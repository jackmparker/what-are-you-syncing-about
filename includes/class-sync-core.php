<?php
/**
 * Core Migration Logic Class
 *
 * Handles database operations: table discovery, export, import, backup, and URL/path replacement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SYNC_Core {

	private static $instance = null;
	private $temp_prefix = '_sync_temp_';
	private $chunk_size = 1000; // Default rows per chunk (can be dynamically adjusted)
	private $max_export_chunk_bytes = 524288; // 512KB max SQL per chunk (avoids timeouts / post limits)
	private $finalize_guard_option = 'sync_finalize_guard';
	private $activity_log = array();
	private $start_time = 0;
	private $debug_enabled = false;

	/**
	 * Get singleton instance
	 *
	 * @return SYNC_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$this->start_time = microtime( true );
	}

	/**
	 * Add activity log entry
	 *
	 * @param string $message Log message
	 * @param string $level Log level (info, warning, error)
	 * @return void
	 */
	public function log_activity( $message, $level = 'info' ) {
		$elapsed = microtime( true ) - $this->start_time;
		$memory = $this->format_bytes( memory_get_usage( true ) );

		$entry = array(
			'timestamp' => current_time( 'mysql' ),
			'elapsed' => round( $elapsed, 2 ),
			'memory' => $memory,
			'level' => $level,
			'message' => $message,
		);

		$this->activity_log[] = $entry;

		// Keep only last 100 entries to prevent memory issues
		if ( count( $this->activity_log ) > 100 ) {
			array_shift( $this->activity_log );
		}

		// Also log to error log if debug is enabled
		if ( $this->debug_enabled ) {
			error_log( sprintf( 'SYNC [%s] %s (%.2fs, %s)', $level, $message, $elapsed, $memory ) );
		}
	}

	/**
	 * Get activity log
	 *
	 * @return array
	 */
	public function get_activity_log() {
		return $this->activity_log;
	}

	/**
	 * Clear activity log
	 *
	 * @return void
	 */
	public function clear_activity_log() {
		$this->activity_log = array();
		$this->start_time = microtime( true );
	}

	/**
	 * Format bytes to human readable
	 *
	 * @param int $bytes Bytes
	 * @return string
	 */
	private function format_bytes( $bytes ) {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$bytes = max( $bytes, 0 );
		$pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow = min( $pow, count( $units ) - 1 );
		$bytes /= pow( 1024, $pow );
		return round( $bytes, 2 ) . ' ' . $units[ $pow ];
	}

	/**
	 * Get dynamic chunk size based on table row count
	 *
	 * @param int $total_rows Total rows in table
	 * @return int Chunk size
	 */
	private function get_dynamic_chunk_size( $total_rows ) {
		// For small tables, use smaller chunks to get more frequent updates
		if ( $total_rows < 100 ) {
			return 50;
		} elseif ( $total_rows < 1000 ) {
			return 250;
		} elseif ( $total_rows < 10000 ) {
			return 1000;
		} elseif ( $total_rows < 100000 ) {
			return 2500;
		} else {
			// For very large tables, use larger chunks to reduce overhead
			return 5000;
		}
	}

	/**
	 * Get all WordPress tables
	 *
	 * @return array
	 */
	public function get_tables() {
		global $wpdb;

		$prefix = $wpdb->prefix;
		$tables = array();

		// Get all tables with the current prefix
		$query = $wpdb->prepare(
			"SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
			DB_NAME,
			$prefix . '%'
		);

		$results = $wpdb->get_col( $query );

		// Filter out temporary tables
		foreach ( $results as $table ) {
			if ( strpos( $table, $this->temp_prefix ) === false ) {
				$tables[] = $table;
			}
		}

		return $tables;
	}

	/**
	 * Get table prefix
	 *
	 * @return string
	 */
	public function get_table_prefix() {
		global $wpdb;
		return $wpdb->prefix;
	}

	/**
	 * Export table to SQL
	 *
	 * @param string $table Table name
	 * @param int $offset Row offset
	 * @param int $limit Row limit
	 * @return string SQL statements
	 */
	public function export_table( $table, $offset = 0, $limit = null ) {
		$result = $this->export_table_rows( $table, $offset, $limit );
		return $result['sql'];
	}

	/**
	 * Export a table chunk with row count metadata.
	 *
	 * @param string   $table  Table name.
	 * @param int      $offset Row offset.
	 * @param int|null $limit  Row limit.
	 * @return array{sql: string, rows_exported: int}
	 */
	public function export_table_rows( $table, $offset = 0, $limit = null ) {
		global $wpdb;

		if ( null === $limit ) {
			$limit = $this->chunk_size;
		}

		$sql           = '';
		$rows_exported = 0;

		// Get table structure
		if ( 0 === $offset ) {
			$this->log_activity( "Exporting table structure for {$table}" );
			$create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( $create_table ) {
				$sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
				$sql .= $create_table[1] . ";\n\n";
			}
		}

		// Get table data
		$this->log_activity( "Exporting rows from {$table} (offset: {$offset}, limit: {$limit})" );
		$query = "SELECT * FROM `{$table}` LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $query, $limit, $offset ), ARRAY_A );

		if ( empty( $rows ) ) {
			return array(
				'sql'           => $sql,
				'rows_exported' => 0,
			);
		}

		// Get column names
		$columns     = array_keys( $rows[0] );
		$column_list = '`' . implode( '`, `', $columns ) . '`';

		// Build INSERT statements with proper escaping
		foreach ( $rows as $row ) {
			$escaped_values = array();

			foreach ( $row as $value ) {
				if ( null === $value ) {
					$escaped_values[] = 'NULL';
				} else {
					$escaped = esc_sql( $value );
					$escaped = str_replace( array( "\n", "\r" ), array( "\\n", "\\r" ), $escaped );
					$escaped_values[] = "'" . $escaped . "'";
				}
			}

			$values_sql = implode( ', ', $escaped_values );
			$sql       .= "INSERT INTO `{$table}` ({$column_list}) VALUES ( {$values_sql} );\n";
			$rows_exported++;
		}

		$this->log_activity( "Exported {$rows_exported} rows from {$table}" );

		return array(
			'sql'           => $sql,
			'rows_exported' => $rows_exported,
		);
	}

	/**
	 * Export rows up to max byte size (shrinks row limit when rows are large, e.g. wp_options).
	 *
	 * @param string $table         Table name.
	 * @param int    $offset        Row offset.
	 * @param int    $initial_limit Starting row limit.
	 * @return array{sql: string, rows_exported: int, chunk_size: int, sql_bytes: int}
	 */
	public function export_table_bounded( $table, $offset, $initial_limit ) {
		$limit        = max( 1, (int) $initial_limit );
		$max_bytes    = $this->max_export_chunk_bytes;
		$max_attempts = 12;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$result    = $this->export_table_rows( $table, $offset, $limit );
			$sql_bytes = strlen( $result['sql'] );

			if ( $sql_bytes <= $max_bytes || $limit <= 1 ) {
				if ( $sql_bytes > $max_bytes ) {
					$this->log_activity(
						"Chunk for {$table} at offset {$offset} is {$sql_bytes} bytes (single row may exceed limit)",
						'warning'
					);
				}

				return array(
					'sql'           => $result['sql'],
					'rows_exported' => $result['rows_exported'],
					'chunk_size'    => $limit,
					'sql_bytes'     => $sql_bytes,
				);
			}

			$limit = max( 1, (int) floor( $limit / 2 ) );
			$this->log_activity( "Reducing {$table} chunk to {$limit} rows (last export was {$sql_bytes} bytes)" );
		}

		$result = $this->export_table_rows( $table, $offset, 1 );

		return array(
			'sql'           => $result['sql'],
			'rows_exported' => $result['rows_exported'],
			'chunk_size'    => 1,
			'sql_bytes'     => strlen( $result['sql'] ),
		);
	}

	/**
	 * Better addslashes for SQL queries.
	 * Taken from phpMyAdmin.
	 *
	 * @param string $string String to escape
	 * @param bool $is_like Whether this is for a LIKE clause
	 * @return string Escaped string
	 */
	private function sql_addslashes( $string = '', $is_like = false ) {
		if ( $is_like ) {
			$string = str_replace( '\\', '\\\\\\\\', $string );
		} else {
			$string = str_replace( '\\', '\\\\', $string );
		}
		return str_replace( "'", "\\'", $string );
	}

	/**
	 * Import SQL chunk
	 *
	 * @param string $sql SQL statements
	 * @param string $temp_table_prefix Temporary table prefix
	 * @param string $source_prefix Source table prefix (from remote site)
	 * @return bool|WP_Error
	 */
	public function import_chunk( $sql, $temp_table_prefix = null, $source_prefix = null ) {
		global $wpdb;

		if ( null === $temp_table_prefix ) {
			$temp_table_prefix = $this->temp_prefix;
		}

		$this->log_activity( "Starting import chunk" );

		$local_prefix = $wpdb->prefix;
		$map_table_name = function( $table_name ) use ( $temp_table_prefix, $local_prefix, $source_prefix ) {
			// Skip if already temp table or system table
			if ( strpos( $table_name, $temp_table_prefix ) !== false ||
				 strpos( $table_name, 'information_schema' ) !== false ||
				 strpos( $table_name, 'mysql' ) !== false ||
				 strpos( $table_name, 'performance_schema' ) !== false ) {
				return $table_name;
			}

			// If source prefix is provided and table starts with it, map to temp table
			if ( $source_prefix && strpos( $table_name, $source_prefix ) === 0 ) {
				$table_suffix = substr( $table_name, strlen( $source_prefix ) );
				return $temp_table_prefix . $table_suffix;
			}

			// If local prefix present, map to temp table
			if ( strpos( $table_name, $local_prefix ) === 0 ) {
				$table_suffix = substr( $table_name, strlen( $local_prefix ) );
				return $temp_table_prefix . $table_suffix;
			}

			// Unknown prefix, still map into temp namespace
			return $temp_table_prefix . $table_name;
		};

		// Split SQL into individual statements
		$statements = $this->split_sql( $sql );
		$this->log_activity( "Processing " . count( $statements ) . " SQL statements" );

		$statement_num = 0;
		foreach ( $statements as $statement ) {
			$statement_num++;
			$statement = trim( $statement );
			if ( empty( $statement ) ) {
				continue;
			}

			// Replace TABLE names with temp prefix (but NEVER rewrite column names / keys).
			$replace_first_table = function( $pattern ) use ( &$statement, $map_table_name ) {
				$statement = preg_replace_callback(
					$pattern,
					function( $m ) use ( $map_table_name ) {
						return $m[1] . '`' . $map_table_name( $m[2] ) . '`';
					},
					$statement,
					1
				);
			};

			$replace_first_table( '/\b(DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?)`([^`]+)`/i' );
			$replace_first_table( '/\b(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`([^`]+)`/i' );
			$replace_first_table( '/\b(INSERT\s+INTO\s+)`([^`]+)`/i' );
			$replace_first_table( '/\b(UPDATE\s+)`([^`]+)`/i' );
			$replace_first_table( '/\b(ALTER\s+TABLE\s+)`([^`]+)`/i' );
			$replace_first_table( '/\b(TRUNCATE\s+TABLE\s+)`([^`]+)`/i' );
			$replace_first_table( '/\b(LOCK\s+TABLES\s+)`([^`]+)`/i' );

			// Additional table references inside DDL (foreign keys, etc.)
			$statement = preg_replace_callback(
				'/\b(REFERENCES\s+)`([^`]+)`/i',
				function( $m ) use ( $map_table_name ) {
					return $m[1] . '`' . $map_table_name( $m[2] ) . '`';
				},
				$statement
			);

			$result = $wpdb->query( $statement );
			if ( $result === false ) {
				$error_msg = "Failed to execute statement #{$statement_num}: {$wpdb->last_error}";
				$this->log_activity( $error_msg, 'error' );

				if ( $this->debug_enabled ) {
					error_log( 'SYNC ERROR import_chunk - Failed statement SQL: ' . $statement );
				}

				return new WP_Error( 'import_failed', $wpdb->last_error, array( 'statement' => $statement ) );
			}
		}

		$this->log_activity( "Successfully imported " . count( $statements ) . " statements" );

		return true;
	}

	/**
	 * Split SQL into individual statements
	 *
	 * @param string $sql SQL string
	 * @return array
	 */
	private function split_sql( $sql ) {
		// IMPORTANT:
		// Do NOT strip SQL comments using regex here.
		// Comment tokens like `--` frequently appear inside *string literals* in real WordPress data
		// (notably Gutenberg `widget_block` content contains HTML comments like `<!-- wp:... -->`).
		// Regex stripping can delete parts of a quoted value, corrupt the SQL, and break semicolon splitting.
		// MySQL can handle comments; our splitting below only splits on semicolons when not inside quotes.

		$statements = array();
		$current    = '';
		$in_single  = false;
		$in_double  = false;
		$len        = strlen( $sql );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $sql[ $i ];
			$next = ( $i + 1 < $len ) ? $sql[ $i + 1 ] : '';

			// Handle escape sequences - only skip backslash+backslash to avoid issues
			if ( $char === '\\' && $next === '\\' && ( $in_single || $in_double ) ) {
				// Escaped backslash - add both and skip ahead
				$current .= $char . $next;
				$i++; // skip second backslash
				continue;
			}

			// Handle quotes - with proper escape handling
			if ( $char === "'" && ! $in_double ) {
				// Check if this quote is escaped (preceded by odd number of backslashes)
				$num_backslashes = 0;
				$check_pos = $i - 1;
				while ( $check_pos >= 0 && $sql[ $check_pos ] === '\\' ) {
					$num_backslashes++;
					$check_pos--;
				}
				// If ODD number of backslashes, the quote is escaped (don't toggle state)
				if ( $num_backslashes % 2 === 0 ) {
					// Even number (including 0) = not escaped, toggle state
					$in_single = ! $in_single;
				}
				$current  .= $char;
				continue;
			}

			if ( $char === '"' && ! $in_single ) {
				// Check if this quote is escaped
				$num_backslashes = 0;
				$check_pos = $i - 1;
				while ( $check_pos >= 0 && $sql[ $check_pos ] === '\\' ) {
					$num_backslashes++;
					$check_pos--;
				}
				// If ODD number of backslashes, the quote is escaped (don't toggle state)
				if ( $num_backslashes % 2 === 0 ) {
					// Even number (including 0) = not escaped, toggle state
					$in_double = ! $in_double;
				}
				$current  .= $char;
				continue;
			}

			if ( $char === ';' && ! $in_single && ! $in_double ) {
				$trimmed = trim( $current );
				if ( $trimmed !== '' ) {
					$statements[] = $trimmed;
				}
				$current = '';
				continue;
			}

			$current .= $char;
		}

		$trimmed = trim( $current );
		if ( $trimmed !== '' ) {
			$statements[] = $trimmed;
		}

		return $statements;
	}

	/**
	 * Create backup of database
	 *
	 * @return string|WP_Error Backup file path or error
	 */
	public function create_backup() {
		global $wpdb;

		$this->log_activity( "Starting database backup" );

		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/what-are-you-syncing-about';

		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		// Check available disk space before writing
		$db_bytes = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s",
			DB_NAME
		) );
		$free_space = function_exists( 'disk_free_space' ) ? disk_free_space( $backup_dir ) : false;
		if ( $free_space !== false && $db_bytes > 0 && $free_space < $db_bytes * 1.2 ) {
			return new WP_Error(
				'insufficient_disk_space',
				sprintf(
					'Insufficient disk space for backup. Database is ~%s, available: %s.',
					$this->format_bytes( $db_bytes ),
					$this->format_bytes( (int) $free_space )
				)
			);
		}

		$timestamp = date( 'Ymd-His' );
		$filename = "backup-{$timestamp}.sql";
		$filepath = $backup_dir . '/' . $filename;

		$tables = $this->get_tables();
		$this->log_activity( "Backing up " . count( $tables ) . " tables" );

		$sql = "-- WordPress Database Backup\n";
		$sql .= "-- Created: " . date( 'Y-m-d H:i:s' ) . "\n\n";

		foreach ( $tables as $table ) {
			$this->log_activity( "Backing up table: {$table}" );
			$table_sql = $this->export_table( $table, 0, null ); // Export entire table
			$sql .= $table_sql . "\n";
		}

		$result = file_put_contents( $filepath, $sql );
		if ( $result === false ) {
			$this->log_activity( "Backup failed to write file", 'error' );
			return new WP_Error( 'backup_failed', 'Failed to create backup file.' );
		}

		$size = $this->format_bytes( filesize( $filepath ) );
		$this->log_activity( "Backup complete: {$filename} ({$size})" );

		return $filepath;
	}

	/**
	 * Replace URLs and paths in data (optimized for SQL chunks)
	 *
	 * @param string $data Data to process
	 * @param string $old_url Old URL
	 * @param string $new_url New URL
	 * @param string $old_path Old file path
	 * @param string $new_path New file path
	 * @return string
	 */
	public function replace_urls_paths( $data, $old_url, $new_url, $old_path, $new_path ) {
		$this->log_activity( "Starting URL/path replacement" );

		// Optimization: Only process INSERT statements, skip CREATE/DROP/ALTER
		// This significantly reduces processing time for large schemas
		if ( stripos( $data, 'INSERT INTO' ) === false ) {
			// No INSERT statements, likely just CREATE TABLE - skip replacement
			$this->log_activity( "Skipping URL replacement (no INSERT statements)" );
			return $data;
		}

		// Prepare URL replacements (with and without protocol)
		$old_url_no_protocol = preg_replace( '#^https?://#', '', $old_url );
		$new_url_no_protocol = preg_replace( '#^https?://#', '', $new_url );

		// Simple string replacement (fast for most cases)
		$data = str_replace( $old_url, $new_url, $data );
		// When the new host contains the old host (e.g. dev.example.com vs example.com),
		// a naive no-protocol replace would corrupt already-replaced URLs (example.com
		// matches inside dev.example.com). Protect the new host, then replace, then restore.
		if ( $old_url_no_protocol !== $new_url_no_protocol && strlen( $new_url_no_protocol ) > 0 ) {
			$host_protect = '~SYNC~' . md5( $old_url_no_protocol . '|' . $new_url_no_protocol ) . '~SYNC~';
			$data         = str_replace( $new_url_no_protocol, $host_protect, $data );
			$data         = str_replace( $old_url_no_protocol, $new_url_no_protocol, $data );
			$data         = str_replace( $host_protect, $new_url_no_protocol, $data );
		}
		$data = str_replace( $old_path, $new_path, $data );

		// Handle protocol-relative URLs (e.g. //old.com/path → //new.com/path)
		$old_proto_relative = '//' . $old_url_no_protocol;
		$new_proto_relative = '//' . $new_url_no_protocol;
		if ( $old_proto_relative !== $new_proto_relative ) {
			$data = str_replace( $old_proto_relative, $new_proto_relative, $data );
		}

		// Handle opposite-protocol variant of source URL (e.g. http:// copies of https:// source)
		if ( strpos( $old_url, 'https://' ) === 0 ) {
			$old_http_url = 'http://' . $old_url_no_protocol;
			if ( $old_http_url !== $new_url ) {
				$data = str_replace( $old_http_url, $new_url, $data );
			}
		}

		// Fix s:N:"..." byte counts that became stale after str_replace changed URL lengths.
		// Only needed when old and new have different lengths.
		if ( strlen( $old_url ) !== strlen( $new_url ) || strlen( $old_path ) !== strlen( $new_path ) ) {
			$data = $this->fix_serialized_string_lengths( $data );
		}

		$this->log_activity( "URL/path replacement complete" );

		return $data;
	}

	/**
	 * Recalculate s:N:"..." byte counts after text replacement may have changed string lengths.
	 * Works on raw SQL export text. Handles most WordPress serialized data; edge cases involving
	 * serialized strings that themselves contain unescaped double-quotes are not addressed.
	 */
	private function fix_serialized_string_lengths( $data ) {
		if ( strpos( $data, 's:' ) === false ) {
			return $data;
		}
		return preg_replace_callback(
			'/s:(\d+):"((?:[^"\\\\]|\\\\.)*)";/',
			function( $matches ) {
				$new_len = strlen( $matches[2] );
				if ( (int) $matches[1] !== $new_len ) {
					return 's:' . $new_len . ':"' . $matches[2] . '";';
				}
				return $matches[0];
			},
			$data
		);
	}

	/**
	 * Finalize migration by replacing temp tables
	 *
	 * @param string $temp_prefix Temporary table prefix
	 * @return bool|WP_Error
	 */
	public function finalize_migration( $temp_prefix = null ) {
		global $wpdb;

		if ( null === $temp_prefix ) {
			$temp_prefix = $this->temp_prefix;
		}

		$this->log_activity( "Starting migration finalization" );

		$prefix = $wpdb->prefix;
		$suffix = gmdate( 'ymdHis' ) . '_' . substr( wp_hash( (string) microtime( true ) ), 0, 6 );

		// Get all temp tables
		$temp_tables = $wpdb->get_col( $wpdb->prepare(
			"SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
			DB_NAME,
			$temp_prefix . '%'
		) );

		if ( empty( $temp_tables ) ) {
			$this->log_activity( "No temp tables found", 'error' );
			return new WP_Error( 'finalize_failed', 'No temporary tables found to finalize.' );
		}

		$this->log_activity( "Finalizing " . count( $temp_tables ) . " tables" );

		$guard = array(
			'created_at'   => time(),
			'temp_prefix'  => $temp_prefix,
			'backup_suffix'=> $suffix,
			'prefix'       => $prefix,
			'mappings'     => array(),
		);
		update_option( $this->finalize_guard_option, $guard, false );

		foreach ( $temp_tables as $temp_table ) {
			// Extract original table name (remove temp prefix)
			$original_table = str_replace( $temp_prefix, $prefix, $temp_table );
			$backup_table   = $this->make_backup_table_name( $original_table, $suffix );

			$this->log_activity( "Swapping table: {$original_table}" );

			// Build atomic rename if original exists; otherwise just rename temp -> original.
			$original_exists = $this->table_exists( $original_table );
			if ( $original_exists ) {
				$sql = "RENAME TABLE `{$original_table}` TO `{$backup_table}`, `{$temp_table}` TO `{$original_table}`";
			} else {
				$sql = "RENAME TABLE `{$temp_table}` TO `{$original_table}`";
				$backup_table = '';
			}

			$result = $wpdb->query( $sql );
			if ( $result === false ) {
				$error_msg = "Table rename failed: {$wpdb->last_error}";
				$this->log_activity( $error_msg, 'error' );

				if ( $this->debug_enabled ) {
					error_log( 'SYNC ERROR finalize_migration - SQL: ' . $sql );
				}

				$this->rollback_from_guard( get_option( $this->finalize_guard_option, array() ) );
				return new WP_Error( 'finalize_failed', $wpdb->last_error );
			}

			$guard['mappings'][] = array(
				'temp'     => $temp_table,
				'original' => $original_table,
				'backup'   => $backup_table,
			);
			update_option( $this->finalize_guard_option, $guard, false );
		}

		$this->log_activity( "Running health check" );
		$health = $this->health_check_core_tables( $prefix );
		if ( is_wp_error( $health ) ) {
			$error_msg = "Health check failed, rolling back: {$health->get_error_message()}";
			$this->log_activity( $error_msg, 'error' );
			$this->rollback_from_guard( $guard );
			return new WP_Error( 'finalize_rolled_back', 'Finalize failed health check and was rolled back: ' . $health->get_error_message() );
		}

		// Finalize succeeded; clear guard.
		delete_option( $this->finalize_guard_option );

		$this->log_activity( "Flushing rewrite rules" );
		flush_rewrite_rules( true );

		$this->log_activity( "Migration finalized successfully" );

		return true;
	}

	/**
	 * If a finalize step died mid-flight or produced an invalid schema, rollback automatically.
	 * Safe to call on every request: it only runs if a guard option exists.
	 *
	 * @return bool True if healthy (or no guard), false if rollback attempted and failed
	 */
	public function maybe_auto_rollback() {
		$guard = get_option( $this->finalize_guard_option, array() );
		if ( empty( $guard ) || empty( $guard['mappings'] ) ) {
			return true;
		}

		$prefix = isset( $guard['prefix'] ) ? $guard['prefix'] : '';
		if ( $prefix === '' ) {
			global $wpdb;
			$prefix = $wpdb->prefix;
		}

		$health = $this->health_check_core_tables( $prefix );
		if ( ! is_wp_error( $health ) ) {
			// Looks good; clear stale guard.
			delete_option( $this->finalize_guard_option );
			return true;
		}

		error_log( 'SYNC ERROR maybe_auto_rollback - Detected unhealthy core tables; attempting rollback: ' . $health->get_error_message() );
		$ok = $this->rollback_from_guard( $guard );
		if ( $ok ) {
			error_log( 'SYNC INFO maybe_auto_rollback - Rollback completed.' );
			delete_option( $this->finalize_guard_option );
			return true;
		}

		error_log( 'SYNC ERROR maybe_auto_rollback - Rollback failed; manual restore may be required.' );
		return false;
	}

	private function rollback_from_guard( $guard ) {
		global $wpdb;

		if ( empty( $guard ) || empty( $guard['mappings'] ) || ! is_array( $guard['mappings'] ) ) {
			return false;
		}

		// Roll back in reverse order.
		$mappings = array_reverse( $guard['mappings'] );
		$all_ok = true;

		foreach ( $mappings as $map ) {
			$original = isset( $map['original'] ) ? $map['original'] : '';
			$backup   = isset( $map['backup'] ) ? $map['backup'] : '';
			$temp     = isset( $map['temp'] ) ? $map['temp'] : '';

			if ( $original === '' || $temp === '' ) {
				continue;
			}

			// If we have a backup, restore it atomically and move the current "bad" table back to temp name.
			if ( $backup !== '' && $this->table_exists( $backup ) && $this->table_exists( $original ) ) {
				$sql = "RENAME TABLE `{$original}` TO `{$temp}`, `{$backup}` TO `{$original}`";
				$r = $wpdb->query( $sql );
				if ( $r === false ) {
					$all_ok = false;
					error_log( 'SYNC ERROR rollback_from_guard - Failed rollback rename: ' . $wpdb->last_error );
					error_log( 'SYNC ERROR rollback_from_guard - SQL: ' . $sql );
				}
				continue;
			}

			// If no backup exists, just move the table back to temp (best effort).
			if ( $this->table_exists( $original ) && ! $this->table_exists( $temp ) ) {
				$sql = "RENAME TABLE `{$original}` TO `{$temp}`";
				$r = $wpdb->query( $sql );
				if ( $r === false ) {
					$all_ok = false;
					error_log( 'SYNC ERROR rollback_from_guard - Failed rollback rename (no backup): ' . $wpdb->last_error );
					error_log( 'SYNC ERROR rollback_from_guard - SQL: ' . $sql );
				}
			}
		}

		return $all_ok;
	}

	private function health_check_core_tables( $prefix ) {
		global $wpdb;

		// Minimal checks that catch the exact class of failure you hit: columns renamed/broken schemas.
		$checks = array(
			$prefix . 'options' => array( 'option_id', 'option_name', 'option_value', 'autoload' ),
			$prefix . 'users'   => array( 'ID', 'user_login', 'user_pass' ),
			$prefix . 'posts'   => array( 'ID', 'post_title', 'post_type' ),
		);

		foreach ( $checks as $table => $required_columns ) {
			if ( ! $this->table_exists( $table ) ) {
				return new WP_Error( 'health_missing_table', "Missing table: {$table}" );
			}

			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
			if ( empty( $cols ) ) {
				return new WP_Error( 'health_bad_table', "Unable to read columns for: {$table}" );
			}

			foreach ( $required_columns as $col ) {
				if ( ! in_array( $col, $cols, true ) ) {
					return new WP_Error( 'health_missing_column', "Missing column {$col} on {$table}" );
				}
			}
		}

		return true;
	}

	private function table_exists( $table ) {
		global $wpdb;
		$like = $wpdb->esc_like( $table );
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
		return ! empty( $found );
	}

	private function make_backup_table_name( $original_table, $suffix ) {
		// MySQL table name limit is 64 chars.
		$max = 64;
		$addon = '_wpsdb_old_' . $suffix;
		$base = $original_table;

		// Reserve room for addon.
		$allowed = $max - strlen( $addon );
		if ( $allowed < 1 ) {
			$allowed = 1;
		}
		if ( strlen( $base ) > $allowed ) {
			$base = substr( $base, 0, $allowed );
		}

		return $base . $addon;
	}

	/**
	 * Get site information
	 *
	 * @return array
	 */
	public function get_site_info() {
		global $wpdb;

		$upload_dir   = wp_upload_dir();
		$table_prefix = $this->get_table_prefix();

		// Fetch per-table byte sizes in a single query
		$size_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT table_name, COALESCE(data_length + index_length, 0) AS total_bytes
			 FROM information_schema.tables
			 WHERE table_schema = %s AND table_name LIKE %s",
			DB_NAME,
			$wpdb->esc_like( $table_prefix ) . '%'
		), ARRAY_A );

		$table_sizes = array();
		if ( $size_rows ) {
			foreach ( $size_rows as $row ) {
				$table_sizes[ $row['table_name'] ] = (int) $row['total_bytes'];
			}
		}

		return array(
			'url'         => home_url(),
			'path'        => ABSPATH,
			'upload_url'  => $upload_dir['url'],
			'upload_path' => $upload_dir['basedir'],
			'tables'      => $this->get_tables(),
			'prefix'      => $table_prefix,
			'version'     => get_bloginfo( 'version' ),
			'table_sizes' => $table_sizes,
			'db_charset'  => $wpdb->charset,
			'db_collate'  => $wpdb->collate,
		);
	}

	/**
	 * Get table row count
	 *
	 * @param string $table Table name
	 * @return int
	 */
	public function get_table_row_count( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Get table info with row count and suggested chunk size
	 *
	 * @param string $table Table name
	 * @return array
	 */
	public function get_table_info( $table ) {
		$row_count = $this->get_table_row_count( $table );
		$chunk_size = $this->get_dynamic_chunk_size( $row_count );

		return array(
			'name' => $table,
			'row_count' => $row_count,
			'chunk_size' => $chunk_size,
		);
	}
}

