<?php
/**
 * Find or replace a bad hostname substring across ALL matching DB columns (not just core tables).
 *
 * Use when the site still redirects after tools/sync-emergency-url-fix.php — leftover rows,
 * plugin tables, or serialized blobs often still contain the bad host.
 *
 * SETUP: Edit $secret, $bad_host, $good_host below (must match your real hostnames).
 *
 * USAGE (WordPress root, same host DNS resolves to, e.g. dev.carterparkdental.com):
 *   Scan only:  .../sync-deep-url-sweep.php?key=SECRET&mode=scan
 *   Apply fix:  .../sync-deep-url-sweep.php?key=SECRET&mode=fix
 *
 * After fix: delete this file. If scan shows 0 hits everywhere, the redirect is NOT
 * from the DB (see non-DB checklist in output).
 *
 * @package SYNC
 */

$secret    = 'dnifsdonifds8hfds8fdshoiufdsnof43nri93niofdniofdsniofdsghgfjhgjhg';
$bad_host  = 'dev.dev.carterparkdental.com';
$good_host = 'dev.carterparkdental.com';

// Optional: same key + &debug=1 to surface PHP errors (remove from URL after debugging).
if ( isset( $_GET['key'], $_GET['debug'] ) && (string) $_GET['debug'] === '1' && hash_equals( $secret, (string) $_GET['key'] ) ) {
	error_reporting( E_ALL );
	ini_set( 'display_errors', '1' );
}

$mode = isset( $_GET['mode'] ) ? strtolower( (string) $_GET['mode'] ) : '';
if ( ! isset( $_GET['key'] ) || ! hash_equals( $secret, (string) $_GET['key'] ) || ! in_array( $mode, array( 'scan', 'fix' ), true ) ) {
	header( 'HTTP/1.1 403 Forbidden' );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "Forbidden. Check: key matches \$secret in this file on the server (copy-paste), and mode=scan or mode=fix.\n";
	echo "Example: ?key=YOUR_SECRET&mode=scan\n";
	exit;
}

header( 'Content-Type: text/plain; charset=utf-8' );
echo "sync-deep-url-sweep: authenticated, loading WordPress (minimal)…\n";
if ( function_exists( 'ob_get_level' ) && ob_get_level() > 0 ) {
	@ob_end_flush();
}
@flush();

$wp_load = dirname( __FILE__ ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	echo 'Upload to WordPress root (next to wp-load.php).';
	exit;
}

// Avoid plugins/themes (common cause of blank WSOD on this URL).
if ( ! defined( 'SHORTINIT' ) ) {
	define( 'SHORTINIT', true );
}

require $wp_load;

if ( ! isset( $GLOBALS['wpdb'] ) || ! ( $GLOBALS['wpdb'] instanceof wpdb ) ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	echo 'No $wpdb (SHORTINIT failed to load DB?). Try removing SHORTINIT line temporarily or fix wp-config DB credentials.';
	exit;
}

/** @var wpdb $wpdb */
global $wpdb;

if ( $bad_host === $good_host || $bad_host === '' ) {
	echo "bad_host and good_host must differ; bad_host must be non-empty.\n";
	exit;
}

$like = '%' . $wpdb->esc_like( $bad_host ) . '%';

// Prefix is trusted (wp-config); underscore in LIKE is wildcard — use literal prefix match.
$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
if ( ! is_array( $tables ) ) {
	echo "DB error listing tables: {$wpdb->last_error}\n";
	exit;
}
if ( empty( $tables ) ) {
	echo "No tables found for prefix {$wpdb->prefix}\n";
	exit;
}

register_shutdown_function(
	static function () {
		$e = error_get_last();
		if ( ! is_array( $e ) ) {
			return;
		}
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( ! in_array( (int) $e['type'], $fatal, true ) ) {
			return;
		}
		echo "\n\n[FATAL] {$e['message']}\n{$e['file']}:{$e['line']}\n";
	}
);

$textish = static function ( $mysql_type ) {
	$t = strtolower( (string) $mysql_type );
	// Skip binary blobs; URLs live in char/text/json.
	if ( preg_match( '/\b(tiny|medium|long)?blob\b/', $t ) ) {
		return false;
	}
	return (bool) preg_match( '/(char|text|json)/', $t );
};

echo $mode === 'scan' ? "SCAN (read-only)\n\n" : "FIX (writing REPLACE)\n\n";

$total_hits = 0;
$total_rows = 0;

foreach ( $tables as $table ) {
	if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
		continue;
	}

	$cols = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
	if ( ! is_array( $cols ) ) {
		continue;
	}

	foreach ( $cols as $col ) {
		$field = isset( $col['Field'] ) ? $col['Field'] : '';
		$type  = isset( $col['Type'] ) ? $col['Type'] : '';
		if ( $field === '' || ! preg_match( '/^[a-zA-Z0-9_]+$/', $field ) || ! $textish( $type ) ) {
			continue;
		}

		$count_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$table}` WHERE `{$field}` LIKE %s",
			$like
		);
		$n = (int) $wpdb->get_var( $count_sql );
		if ( $n < 1 ) {
			continue;
		}

		$total_hits += $n;
		echo "{$table}.{$field}: {$n} row(s)\n";

		if ( $mode === 'fix' ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET `{$field}` = REPLACE(`{$field}`, %s, %s) WHERE `{$field}` LIKE %s",
					$bad_host,
					$good_host,
					$like
				)
			);
			$total_rows += (int) $wpdb->rows_affected;
		}
	}
}

echo "\n---\n";
if ( $mode === 'scan' ) {
	echo "Total matching rows (sum of counts): {$total_hits}\n";
	if ( $total_hits === 0 ) {
		echo "\nNothing in the database contains \"{$bad_host}\". Redirect is likely:\n";
		echo "  - Browser cached 301 (try Incognito or another browser)\n";
		echo "  - WP_HOME / WP_SITEURL still in wp-config.php pointing at the bad URL\n";
		echo "  - Server or CDN redirect (.htaccess, nginx, Cloudflare Page Rules, host panel)\n";
		echo "  - Object cache (Redis/Memcached): flush from host panel\n";
	}
	echo "\nIf hits > 0, run again with &mode=fix then delete this script.\n";
} else {
	echo "REPLACE pass finished. rows_affected (sum, approximate): {$total_rows}\n";
	echo "Flush any object cache, test in Incognito, delete this script.\n";
	echo "Serialized options may need a dedicated search-replace if something still breaks.\n";
}
