<?php
/**
 * One-time emergency fix: double subdomain / bad URL in the database after migration.
 *
 * SETUP (edit below):
 *   1. $secret — long random string; open this script with ?key=YOUR_SECRET
 *   2. $wrong_url — the broken URL (e.g. https://dev.dev.carterparkdental.com)
 *   3. $correct_url — the intended URL (e.g. https://dev.carterparkdental.com)
 *
 * INSTALL:
 *   Upload to the WordPress root (same folder as wp-load.php), then visit the script using
 *   the hostname that **DNS actually resolves** (the real server), e.g.:
 *   https://dev.carterparkdental.com/sync-emergency-url-fix.php?key=YOUR_SECRET
 *
 *   Do NOT use the broken URL in the browser (e.g. dev.dev....) — that host often has no DNS
 *   record (NXDOMAIN), so the request never reaches your server.
 *
 *   Use the same $secret as in this file, with no spaces; if the key is wrong you get a blank 403.
 *
 * If the bad URL makes the site unusable in the browser, add these to wp-config.php
 * (above "That's all, stop editing!"), upload, then load this script using the same host
 * you use for wp-admin (or the server IP if vhosts allow it):
 *
 *   define( 'WP_HOME', 'https://dev.carterparkdental.com' );
 *   define( 'WP_SITEURL', 'https://dev.carterparkdental.com' );
 *
 * Remove those two lines after this script succeeds.
 *
 * SECURITY: Delete this file from the server immediately after success.
 *
 * @package SYNC
 */

$secret      = 'dnifsdonifds8hfds8fdshoiufdsnof43nri93niofdniofdsniofdsghgfjhgjhg';
$wrong_url   = 'https://dev.dev.carterparkdental.com';
$correct_url = 'https://dev.carterparkdental.com';

if ( ! isset( $_GET['key'] ) || ! hash_equals( $secret, (string) $_GET['key'] ) ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

$wp_load = dirname( __FILE__ ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	echo 'Could not read wp-load.php. Upload this script to the WordPress root (next to wp-load.php).';
	exit;
}

require $wp_load;

// If this request ever reaches template_redirect, avoid redirecting to a broken siteurl in DB.
remove_action( 'template_redirect', 'redirect_canonical' );

if ( ! isset( $GLOBALS['wpdb'] ) || ! ( $GLOBALS['wpdb'] instanceof wpdb ) ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	echo 'WordPress database object not available.';
	exit;
}

/** @var wpdb $wpdb */
global $wpdb;

header( 'Content-Type: text/plain; charset=utf-8' );

$wrong_noproto   = preg_replace( '#^https?://#i', '', $wrong_url );
$correct_noproto = preg_replace( '#^https?://#i', '', $correct_url );

/**
 * REPLACE in one column using prepared values.
 *
 * @param wpdb   $wpdb   Database.
 * @param string $table  Table name (from $wpdb->*).
 * @param string $column Column name.
 * @param string $from   Search string.
 * @param string $to     Replacement.
 * @return int Rows affected (best effort; see wpdb docs).
 */
$run_replace = static function ( wpdb $wpdb, $table, $column, $from, $to ) {
	if ( $from === $to || $from === '' ) {
		return 0;
	}
	$like = '%' . $wpdb->esc_like( $from ) . '%';
	$sql  = $wpdb->prepare(
		"UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, %s, %s) WHERE `{$column}` LIKE %s",
		$from,
		$to,
		$like
	);
	if ( ! $sql ) {
		return 0;
	}
	$wpdb->query( $sql );

	return (int) $wpdb->rows_affected;
};

$counts = array();

$wpdb->update(
	$wpdb->options,
	array( 'option_value' => $correct_url ),
	array( 'option_name' => 'siteurl' ),
	array( '%s' ),
	array( '%s' )
);
$counts['options siteurl'] = (int) $wpdb->rows_affected;

$wpdb->update(
	$wpdb->options,
	array( 'option_value' => $correct_url ),
	array( 'option_name' => 'home' ),
	array( '%s' ),
	array( '%s' )
);
$counts['options home'] = (int) $wpdb->rows_affected;

$counts['options option_value (URL)']       = $run_replace( $wpdb, $wpdb->options, 'option_value', $wrong_url, $correct_url );
$counts['options option_value (no proto)'] = $run_replace( $wpdb, $wpdb->options, 'option_value', $wrong_noproto, $correct_noproto );

$counts['posts post_content (URL)']       = $run_replace( $wpdb, $wpdb->posts, 'post_content', $wrong_url, $correct_url );
$counts['posts post_content (no proto)']  = $run_replace( $wpdb, $wpdb->posts, 'post_content', $wrong_noproto, $correct_noproto );
$counts['posts post_excerpt (URL)']       = $run_replace( $wpdb, $wpdb->posts, 'post_excerpt', $wrong_url, $correct_url );
$counts['posts post_excerpt (no proto)']  = $run_replace( $wpdb, $wpdb->posts, 'post_excerpt', $wrong_noproto, $correct_noproto );
$counts['posts guid (URL)']               = $run_replace( $wpdb, $wpdb->posts, 'guid', $wrong_url, $correct_url );
$counts['posts guid (no proto)']          = $run_replace( $wpdb, $wpdb->posts, 'guid', $wrong_noproto, $correct_noproto );

$counts['postmeta meta_value (URL)']       = $run_replace( $wpdb, $wpdb->postmeta, 'meta_value', $wrong_url, $correct_url );
$counts['postmeta meta_value (no proto)']  = $run_replace( $wpdb, $wpdb->postmeta, 'meta_value', $wrong_noproto, $correct_noproto );

$counts['comments comment_content (URL)']      = $run_replace( $wpdb, $wpdb->comments, 'comment_content', $wrong_url, $correct_url );
$counts['comments comment_content (np)']     = $run_replace( $wpdb, $wpdb->comments, 'comment_content', $wrong_noproto, $correct_noproto );
$counts['comments comment_author_url (URL)'] = $run_replace( $wpdb, $wpdb->comments, 'comment_author_url', $wrong_url, $correct_url );

$counts['commentmeta meta_value (URL)']      = $run_replace( $wpdb, $wpdb->commentmeta, 'meta_value', $wrong_url, $correct_url );
$counts['commentmeta meta_value (no proto)'] = $run_replace( $wpdb, $wpdb->commentmeta, 'meta_value', $wrong_noproto, $correct_noproto );

$counts['usermeta meta_value (URL)']       = $run_replace( $wpdb, $wpdb->usermeta, 'meta_value', $wrong_url, $correct_url );
$counts['usermeta meta_value (no proto)']  = $run_replace( $wpdb, $wpdb->usermeta, 'meta_value', $wrong_noproto, $correct_noproto );

$termmeta_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->termmeta ) );
if ( $termmeta_exists === $wpdb->termmeta ) {
	$counts['termmeta meta_value (URL)']      = $run_replace( $wpdb, $wpdb->termmeta, 'meta_value', $wrong_url, $correct_url );
	$counts['termmeta meta_value (no proto)'] = $run_replace( $wpdb, $wpdb->termmeta, 'meta_value', $wrong_noproto, $correct_noproto );
}

echo "Done. Rows affected (per step):\n\n";
foreach ( $counts as $label => $n ) {
	echo $label . ': ' . $n . "\n";
}

echo "\nVerify Settings → General, then delete this PHP file from the server.\n";
echo "If you added WP_HOME / WP_SITEURL to wp-config.php for recovery, remove them now.\n";
