<?php
/**
 * One-time migration from vayz / vat-are-you-zinking-about naming.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SYNC_Upgrade {

	const MIGRATED_OPTION = 'sync_migrated_from_vayz';

	const LEGACY_SETTINGS_OPTION = 'vayz_settings';
	const LEGACY_GUARD_OPTION    = 'vayz_finalize_guard';
	const LEGACY_TEMP_PREFIX     = '_vayz_temp_';

	const NEW_SETTINGS_OPTION = 'sync_settings';
	const NEW_GUARD_OPTION    = 'sync_finalize_guard';
	const NEW_TEMP_PREFIX     = '_sync_temp_';

	const LEGACY_BACKUP_SUBDIR = 'vat-are-you-zinking-about';
	const NEW_BACKUP_SUBDIR    = 'what-are-you-syncing-about';

	/**
	 * Run legacy migration once per site.
	 */
	public static function maybe_run() {
		if ( get_option( self::MIGRATED_OPTION, false ) ) {
			return;
		}

		self::legacy_rollback_if_guard_exists();
		self::migrate_settings();
		self::migrate_finalize_guard();
		self::rename_legacy_temp_tables();
		self::migrate_backup_directory();

		update_option( self::MIGRATED_OPTION, '1.1.0', false );
	}

	private static function legacy_rollback_if_guard_exists() {
		$guard = get_option( self::LEGACY_GUARD_OPTION, array() );
		if ( empty( $guard ) || empty( $guard['mappings'] ) ) {
			return;
		}

		$ok = self::rollback_from_guard( $guard );
		if ( $ok ) {
			delete_option( self::LEGACY_GUARD_OPTION );
			error_log( 'SYNC INFO upgrade - Legacy vayz finalize guard rolled back before rename.' );
		} else {
			error_log( 'SYNC ERROR upgrade - Legacy vayz rollback failed; leaving vayz_finalize_guard for manual recovery.' );
		}
	}

	private static function rollback_from_guard( $guard ) {
		global $wpdb;

		if ( empty( $guard['mappings'] ) || ! is_array( $guard['mappings'] ) ) {
			return false;
		}

		$mappings = array_reverse( $guard['mappings'] );
		$all_ok   = true;

		foreach ( $mappings as $map ) {
			$original = isset( $map['original'] ) ? $map['original'] : '';
			$backup   = isset( $map['backup'] ) ? $map['backup'] : '';
			$temp     = isset( $map['temp'] ) ? $map['temp'] : '';

			if ( $original === '' || $temp === '' ) {
				continue;
			}

			if ( $backup !== '' && self::table_exists( $backup ) && self::table_exists( $original ) ) {
				$sql = "RENAME TABLE `{$original}` TO `{$temp}`, `{$backup}` TO `{$original}`";
				if ( $wpdb->query( $sql ) === false ) {
					$all_ok = false;
					error_log( 'SYNC ERROR upgrade rollback - ' . $wpdb->last_error );
				}
				continue;
			}

			if ( self::table_exists( $original ) && ! self::table_exists( $temp ) ) {
				$sql = "RENAME TABLE `{$original}` TO `{$temp}`";
				if ( $wpdb->query( $sql ) === false ) {
					$all_ok = false;
					error_log( 'SYNC ERROR upgrade rollback - ' . $wpdb->last_error );
				}
			}
		}

		return $all_ok;
	}

	private static function migrate_settings() {
		$legacy = get_option( self::LEGACY_SETTINGS_OPTION, null );
		if ( $legacy === null ) {
			return;
		}

		if ( get_option( self::NEW_SETTINGS_OPTION, null ) === null ) {
			update_option( self::NEW_SETTINGS_OPTION, $legacy, false );
		}

		delete_option( self::LEGACY_SETTINGS_OPTION );
	}

	private static function migrate_finalize_guard() {
		$guard = get_option( self::LEGACY_GUARD_OPTION, array() );
		if ( empty( $guard ) ) {
			return;
		}

		if ( ! empty( $guard['temp_prefix'] ) ) {
			$guard['temp_prefix'] = str_replace( self::LEGACY_TEMP_PREFIX, self::NEW_TEMP_PREFIX, $guard['temp_prefix'] );
		}

		if ( ! empty( $guard['mappings'] ) && is_array( $guard['mappings'] ) ) {
			foreach ( $guard['mappings'] as $i => $map ) {
				foreach ( array( 'temp', 'original', 'backup' ) as $key ) {
					if ( ! empty( $map[ $key ] ) ) {
						$guard['mappings'][ $i ][ $key ] = str_replace(
							self::LEGACY_TEMP_PREFIX,
							self::NEW_TEMP_PREFIX,
							$map[ $key ]
						);
					}
				}
			}
		}

		update_option( self::NEW_GUARD_OPTION, $guard, false );
		delete_option( self::LEGACY_GUARD_OPTION );
	}

	private static function rename_legacy_temp_tables() {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix . self::LEGACY_TEMP_PREFIX ) . '%';
		$tables = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s',
				DB_NAME,
				$like
			)
		);

		if ( empty( $tables ) ) {
			return;
		}

		foreach ( $tables as $old_name ) {
			$new_name = str_replace( self::LEGACY_TEMP_PREFIX, self::NEW_TEMP_PREFIX, $old_name );
			if ( $new_name === $old_name || self::table_exists( $new_name ) ) {
				continue;
			}

			$sql = "RENAME TABLE `{$old_name}` TO `{$new_name}`";
			if ( $wpdb->query( $sql ) === false ) {
				error_log( 'SYNC ERROR upgrade - Failed to rename temp table ' . $old_name . ': ' . $wpdb->last_error );
			}
		}
	}

	private static function migrate_backup_directory() {
		$upload_dir = wp_upload_dir();
		$old_dir    = $upload_dir['basedir'] . '/' . self::LEGACY_BACKUP_SUBDIR;
		$new_dir    = $upload_dir['basedir'] . '/' . self::NEW_BACKUP_SUBDIR;

		if ( ! is_dir( $old_dir ) ) {
			return;
		}

		if ( is_dir( $new_dir ) ) {
			return;
		}

		if ( ! rename( $old_dir, $new_dir ) ) {
			error_log( 'SYNC ERROR upgrade - Could not rename backup directory to ' . self::NEW_BACKUP_SUBDIR );
		}
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$like  = $wpdb->esc_like( $table );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return ! empty( $found );
	}
}
