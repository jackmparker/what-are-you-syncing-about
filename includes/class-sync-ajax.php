<?php
/**
 * AJAX Handlers Class
 *
 * Handles all AJAX requests for database migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SYNC_Ajax {

	private static $instance = null;
	private $core;
	private $files;

	/**
	 * Get singleton instance
	 *
	 * @return SYNC_Ajax
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
		$this->core  = SYNC_Core::get_instance();
		$this->files = SYNC_Files::get_instance();

		// Send CORS headers immediately if this is an AJAX request for our plugin
		$this->maybe_send_cors_now();

		// Modify headers before WordPress sends them
		add_filter( 'wp_headers', array( $this, 'add_cors_headers' ), 10, 2 );

		// Send CORS headers before WordPress sends headers (fallback)
		add_action( 'send_headers', array( $this, 'send_cors_headers_early' ) );

		// Handle CORS preflight requests
		add_action( 'init', array( $this, 'handle_cors_preflight' ), 1 );

		// Hook into template_redirect for early header modification
		add_action( 'template_redirect', array( $this, 'send_cors_headers_early' ), 1 );

		// Internal AJAX handlers (require authentication)
		add_action( 'wp_ajax_sync_verify_connection', array( $this, 'ajax_verify_connection' ) );
		add_action( 'wp_ajax_sync_get_connection_info', array( $this, 'ajax_get_connection_info' ) );
		add_action( 'wp_ajax_sync_initiate_migration', array( $this, 'ajax_initiate_migration' ) );
		add_action( 'wp_ajax_sync_export_chunk', array( $this, 'ajax_export_chunk' ) );
		add_action( 'wp_ajax_sync_import_chunk', array( $this, 'ajax_import_chunk' ) );
		add_action( 'wp_ajax_sync_finalize_migration', array( $this, 'ajax_finalize_migration' ) );

		// External AJAX handlers (no authentication required, but signature verified)
		add_action( 'wp_ajax_nopriv_sync_verify_connection', array( $this, 'ajax_verify_connection' ) );
		add_action( 'wp_ajax_nopriv_sync_get_connection_info', array( $this, 'ajax_get_connection_info' ) );
		add_action( 'wp_ajax_nopriv_sync_initiate_migration', array( $this, 'ajax_initiate_migration' ) );
		add_action( 'wp_ajax_nopriv_sync_export_chunk', array( $this, 'ajax_export_chunk' ) );
		add_action( 'wp_ajax_nopriv_sync_import_chunk', array( $this, 'ajax_import_chunk' ) );
		add_action( 'wp_ajax_nopriv_sync_finalize_migration', array( $this, 'ajax_finalize_migration' ) );

		// Helper endpoint for creating signatures (requires authentication)
		add_action( 'wp_ajax_sync_create_signature', array( $this, 'ajax_create_signature' ) );

		// Server-side proxy for push imports (avoids browser CORS / WAF on raw SQL)
		add_action( 'wp_ajax_sync_remote_import_chunk', array( $this, 'ajax_remote_import_chunk' ) );

		// Save connection settings (Basic Auth, SSL skip)
		add_action( 'wp_ajax_sync_save_settings', array( $this, 'ajax_save_settings' ) );

		// Export a batch of small tables in one request
		add_action( 'wp_ajax_sync_export_batch', array( $this, 'ajax_export_batch' ) );
		add_action( 'wp_ajax_nopriv_sync_export_batch', array( $this, 'ajax_export_batch' ) );

		// File sync: inventory, plugins ZIP, upload chunks
		add_action( 'wp_ajax_sync_get_file_inventory', array( $this, 'ajax_get_file_inventory' ) );
		add_action( 'wp_ajax_nopriv_sync_get_file_inventory', array( $this, 'ajax_get_file_inventory' ) );
		add_action( 'wp_ajax_sync_export_zip_chunk', array( $this, 'ajax_export_zip_chunk' ) );
		add_action( 'wp_ajax_nopriv_sync_export_zip_chunk', array( $this, 'ajax_export_zip_chunk' ) );
		add_action( 'wp_ajax_sync_import_zip_chunk', array( $this, 'ajax_import_zip_chunk' ) );
		add_action( 'wp_ajax_nopriv_sync_import_zip_chunk', array( $this, 'ajax_import_zip_chunk' ) );
		add_action( 'wp_ajax_sync_finalize_plugins', array( $this, 'ajax_finalize_plugins' ) );
		add_action( 'wp_ajax_nopriv_sync_finalize_plugins', array( $this, 'ajax_finalize_plugins' ) );
		add_action( 'wp_ajax_sync_export_upload_chunk', array( $this, 'ajax_export_upload_chunk' ) );
		add_action( 'wp_ajax_nopriv_sync_export_upload_chunk', array( $this, 'ajax_export_upload_chunk' ) );
		add_action( 'wp_ajax_sync_import_upload_chunk', array( $this, 'ajax_import_upload_chunk' ) );
		add_action( 'wp_ajax_nopriv_sync_import_upload_chunk', array( $this, 'ajax_import_upload_chunk' ) );
	}

	/**
	 * Send CORS headers immediately if this is our AJAX request
	 */
	private function maybe_send_cors_now() {
		// Check if this is an AJAX request
		if ( ! defined( 'DOING_AJAX' ) ) {
			// Try to detect AJAX by checking the request
			if ( ! isset( $_REQUEST['action'] ) || strpos( $_SERVER['REQUEST_URI'], 'admin-ajax.php' ) === false ) {
				return;
			}
		}

		// Check if this is our AJAX action
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		if ( strpos( $action, 'sync_' ) !== 0 ) {
			return;
		}

		// Send CORS headers immediately - before WordPress does anything
		$this->send_cors_headers();
	}

	/**
	 * Add CORS headers via wp_headers filter
	 *
	 * @param array $headers Current headers
	 * @param WP $wp WordPress environment instance
	 * @return array Modified headers
	 */
	public function add_cors_headers( $headers, $wp ) {
		// Only for AJAX requests
		if ( ! wp_doing_ajax() ) {
			return $headers;
		}

		// Check if this is our AJAX action
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		if ( strpos( $action, 'sync_' ) !== 0 ) {
			return $headers;
		}

		// Get origin
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = $_SERVER['HTTP_ORIGIN'];
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer_parts = parse_url( $_SERVER['HTTP_REFERER'] );
			$scheme = isset( $referer_parts['scheme'] ) ? $referer_parts['scheme'] : 'http';
			$host = isset( $referer_parts['host'] ) ? $referer_parts['host'] : '';
			$port = isset( $referer_parts['port'] ) ? ':' . $referer_parts['port'] : '';
			$origin = $scheme . '://' . $host . $port;
		} else {
			$origin = '*';
		}

		// Add CORS headers
		if ( empty( $origin ) || $origin === '*' ||
			 SYNC_Security::is_localhost( $origin ) ||
			 SYNC_Security::is_localhost( home_url() ) ||
			 strpos( $origin, 'localhost' ) !== false ||
			 strpos( $origin, '127.0.0.1' ) !== false ) {

			if ( ! empty( $origin ) && $origin !== '*' ) {
				$headers['Access-Control-Allow-Origin'] = $origin;
			} else {
				$headers['Access-Control-Allow-Origin'] = '*';
			}
			$headers['Access-Control-Allow-Methods'] = 'POST, GET, OPTIONS';
			$headers['Access-Control-Allow-Headers'] = 'Content-Type, X-Requested-With';
			$headers['Access-Control-Allow-Credentials'] = 'true';
			$headers['Access-Control-Max-Age'] = '86400';
		} else {
			$headers['Access-Control-Allow-Origin'] = $origin;
			$headers['Access-Control-Allow-Methods'] = 'POST, GET, OPTIONS';
			$headers['Access-Control-Allow-Headers'] = 'Content-Type, X-Requested-With';
			$headers['Access-Control-Max-Age'] = '86400';
		}

		return $headers;
	}

	/**
	 * Send CORS headers early for AJAX requests
	 */
	public function send_cors_headers_early() {
		// Only for AJAX requests
		if ( ! wp_doing_ajax() ) {
			return;
		}

		// Check if this is our AJAX action
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		if ( strpos( $action, 'sync_' ) !== 0 ) {
			return;
		}

		// Send CORS headers (fallback if wp_headers filter didn't work)
		$this->send_cors_headers();
	}

	/**
	 * Handle CORS preflight requests
	 */
	public function handle_cors_preflight() {
		// Check if this is an AJAX request for our plugin
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';

		if ( strpos( $action, 'sync_' ) !== 0 ) {
			return;
		}

		// Handle preflight OPTIONS request
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
			$this->send_cors_headers();
			status_header( 200 );
			exit;
		}
	}

	/**
	 * Send CORS headers
	 */
	private function send_cors_headers() {
		// Try to send headers even if headers_sent() returns true
		// Sometimes WordPress has sent some headers but not all
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = $_SERVER['HTTP_ORIGIN'];
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer_parts = parse_url( $_SERVER['HTTP_REFERER'] );
			$scheme = isset( $referer_parts['scheme'] ) ? $referer_parts['scheme'] : 'http';
			$host = isset( $referer_parts['host'] ) ? $referer_parts['host'] : '';
			$port = isset( $referer_parts['port'] ) ? ':' . $referer_parts['port'] : '';
			$origin = $scheme . '://' . $host . $port;
		} else {
			$origin = '*';
		}

		// For localhost or any origin during development
		if ( empty( $origin ) || $origin === '*' ||
			 SYNC_Security::is_localhost( $origin ) ||
			 SYNC_Security::is_localhost( home_url() ) ||
			 strpos( $origin, 'localhost' ) !== false ||
			 strpos( $origin, '127.0.0.1' ) !== false ) {

			// Use the actual origin if provided, otherwise allow all
			if ( ! empty( $origin ) && $origin !== '*' ) {
				@header( 'Access-Control-Allow-Origin: ' . $origin );
			} else {
				@header( 'Access-Control-Allow-Origin: *' );
			}
			@header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
			@header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With' );
			@header( 'Access-Control-Allow-Credentials: true' );
			@header( 'Access-Control-Max-Age: 86400' );
		} else {
			// For remote sites, allow the requesting origin
			@header( 'Access-Control-Allow-Origin: ' . $origin );
			@header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
			@header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With' );
			@header( 'Access-Control-Max-Age: 86400' );
		}
	}

	/**
	 * Send CORS headers before response
	 */
	private function maybe_send_cors_headers() {
		// Always try to send headers, even if headers_sent() returns true
		// WordPress AJAX might have sent some headers but not all
		$this->send_cors_headers();
	}

	/**
	 * Create signature for client-side requests
	 */
	public function ajax_create_signature() {
		check_ajax_referer( 'sync_nonce', 'nonce' );

		$this->maybe_send_cors_headers();

		// Parse URL-encoded data string back into array (matching how $_POST works)
		$data_string = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';

		if ( empty( $key ) || empty( $data_string ) ) {
			wp_send_json_error( array( 'message' => 'Missing parameters' ) );
			return;
		}

		// Parse the URL-encoded string into an array (same format as $_POST)
		parse_str( $data_string, $data );

		$signature = SYNC_Security::create_signature( $data, $key );

		wp_send_json_success( array( 'signature' => $signature ) );
	}

	/**
	 * Verify connection signature
	 *
	 * @param string $key Remote site key
	 * @return bool|WP_Error
	 */
	private function verify_request( $key ) {
		if ( empty( $_POST['sig'] ) ) {
			return new WP_Error( 'missing_signature', 'Missing signature' );
		}

		$signature = sanitize_text_field( $_POST['sig'] );

		// Build data for verification from raw POST first (avoids sanitization changes)
		$raw_post = file_get_contents( 'php://input' );
		parse_str( $raw_post, $raw_data );
		unset( $raw_data['sig'] );
		unset( $raw_data['nonce'] ); // Nonce is verified separately, not included in signature
		ksort( $raw_data );

		// Fallback to sanitized $_POST if raw parse failed
		if ( empty( $raw_data ) ) {
			$raw_data = $_POST;
			unset( $raw_data['nonce'], $raw_data['sig'] );
			ksort( $raw_data );
		}

		$settings = get_option( 'sync_settings', array() );
		$local_key = isset( $settings['key'] ) ? $settings['key'] : '';

		// Try keys in order: local key (if set), then provided $key
		$candidate_keys = array_values( array_unique( array_filter( array( $local_key, $key ) ) ) );
		$valid = false;
		foreach ( $candidate_keys as $candidate_key ) {
			if ( SYNC_Security::verify_signature( $raw_data, $candidate_key, $signature ) ) {
				$valid = true;
				break;
			}
		}

		if ( ! $valid ) {
			return new WP_Error( 'invalid_signature', 'Invalid signature' );
		}

		return true;
	}

	/**
	 * Send JSON response
	 *
	 * @param mixed $data Response data
	 * @param int $status HTTP status code
	 */
	private function send_response( $data, $status = 200 ) {
		$this->maybe_send_cors_headers();

		// Use nocache headers and send JSON
		nocache_headers();
		wp_send_json( $data, $status );
	}

	/**
	 * Authenticate request via WP nonce (local admin) or HMAC signature (remote).
	 *
	 * @param string $key_field POST field containing the connection key.
	 * @return bool True when authenticated; sends error response and returns false otherwise.
	 */
	private function authenticate_request( $key_field = 'key' ) {
		$this->maybe_send_cors_headers();

		$key = isset( $_POST[ $key_field ] ) ? sanitize_text_field( $_POST[ $key_field ] ) : '';

		if ( ! empty( $_POST['nonce'] ) && check_ajax_referer( 'sync_nonce', 'nonce', false ) ) {
			return true;
		}

		$verify = $this->verify_request( $key );
		if ( is_wp_error( $verify ) ) {
			$this->send_error( $verify->get_error_message(), $verify->get_error_code(), 403 );
			return false;
		}

		return true;
	}

	/**
	 * Send error response
	 *
	 * @param string $message Error message
	 * @param string $code Error code
	 * @param int $status HTTP status code
	 */
	private function send_error( $message, $code = 'error', $status = 400 ) {
		$this->send_response( array(
			'success' => false,
			'error' => $message,
			'code' => $code,
		), $status );
	}

	/**
	 * Verify connection to remote site
	 */
	public function ajax_verify_connection() {
		check_ajax_referer( 'sync_nonce', 'nonce' );
		$this->maybe_send_cors_headers();

		$remote_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';
		$remote_url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';

		if ( empty( $remote_key ) || empty( $remote_url ) ) {
			$this->send_error( 'Missing connection parameters', 'missing_params' );
			return;
		}

		// Verify signature using local key (this is a local AJAX request)
		$settings = get_option( 'sync_settings', array() );
		$local_key = isset( $settings['key'] ) ? $settings['key'] : '';

		if ( empty( $_POST['sig'] ) ) {
			$this->send_error( 'Missing signature', 'missing_signature' );
			return;
		}

		$signature = sanitize_text_field( $_POST['sig'] );
		$data = $_POST;
		unset( $data['nonce'] );
		unset( $data['sig'] );

		if ( ! SYNC_Security::verify_signature( $data, $local_key, $signature ) ) {
			$this->send_error( 'Invalid signature', 'invalid_signature' );
			return;
		}

		// Return success
		$this->send_response( array(
			'success' => true,
			'message' => 'Connection verified',
		) );
	}

	/**
	 * Get connection info (tables, URLs, paths, prefix)
	 */
	public function ajax_get_connection_info() {
		$this->maybe_send_cors_headers();

		$remote_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';

		if ( empty( $remote_key ) ) {
			$this->send_error( 'Missing key', 'missing_key' );
			return;
		}

		// Verify signature
		$verify = $this->verify_request( $remote_key );
		if ( is_wp_error( $verify ) ) {
			$this->send_error( $verify->get_error_message(), $verify->get_error_code() );
			return;
		}

		// Get site info
		$info = $this->core->get_site_info();
		$info['plugin_version'] = SYNC_VERSION;

		$this->send_response( array(
			'success' => true,
			'data' => $info,
		) );
	}

	/**
	 * Initiate migration
	 */
	public function ajax_initiate_migration() {
		if ( ! $this->authenticate_request() ) {
			return;
		}

		$remote_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';
		$action = isset( $_POST['action_type'] ) ? sanitize_text_field( $_POST['action_type'] ) : ''; // 'push' or 'pull'

		if ( empty( $remote_key ) || empty( $action ) ) {
			$this->send_error( 'Missing parameters', 'missing_params' );
			return;
		}

		// Create backup
		$backup_path = $this->core->create_backup();
		if ( is_wp_error( $backup_path ) ) {
			$this->send_error( 'Failed to create backup: ' . $backup_path->get_error_message(), 'backup_failed' );
			return;
		}

		// Get tables
		$tables = $this->core->get_tables();

		$this->send_response( array(
			'success' => true,
			'tables' => $tables,
			'backup_path' => $backup_path,
		) );
	}

	/**
	 * Export database chunk
	 */
	public function ajax_export_chunk() {
		$this->maybe_send_cors_headers();

		$remote_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';
		$table = isset( $_POST['table'] ) ? sanitize_text_field( $_POST['table'] ) : '';
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

		if ( empty( $remote_key ) || empty( $table ) ) {
			$this->send_error( 'Missing parameters', 'missing_params' );
			return;
		}

		// Verify signature
		$verify = $this->verify_request( $remote_key );
		if ( is_wp_error( $verify ) ) {
			$this->send_error( $verify->get_error_message(), $verify->get_error_code() );
			return;
		}

		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 300 );

		// Get table info for dynamic chunk sizing
		$table_info = $this->core->get_table_info( $table );
		$total_rows = $table_info['row_count'];
		$chunk_size = $table_info['chunk_size'];

		// Export table chunk (byte-limited for wide rows like wp_options)
		$export     = $this->core->export_table_bounded( $table, $offset, $chunk_size );
		$sql        = $export['sql'];
		$chunk_size = $export['chunk_size'];
		$rows_exported = $export['rows_exported'];
		$has_more   = ( $offset + $rows_exported ) < $total_rows;

		$response_data = array(
			'success'       => true,
			'offset'        => $offset,
			'rows_exported' => $rows_exported,
			'total_rows'    => $total_rows,
			'has_more'      => $has_more,
			'chunk_size'    => $chunk_size,
			'sql_bytes'     => $export['sql_bytes'],
		);

		if ( function_exists( 'gzencode' ) ) {
			$response_data['sql_gz_b64'] = base64_encode( gzencode( $sql, 6 ) );
		} else {
			$response_data['sql'] = $sql;
		}

		$this->send_response( $response_data );
	}

	/**
	 * Verify HMAC signature from raw POST body (matches browser URLSearchParams signing).
	 *
	 * @param string $key Secret key.
	 * @return true|WP_Error
	 */
	private function verify_raw_body_signature( $key ) {
		if ( empty( $_POST['sig'] ) ) {
			return new WP_Error( 'missing_signature', 'Missing signature' );
		}

		$signature = sanitize_text_field( wp_unslash( $_POST['sig'] ) );
		$raw_post  = file_get_contents( 'php://input' );

		if ( '' !== $raw_post ) {
			$query_parts    = explode( '&', $raw_post );
			$filtered_parts = array();
			foreach ( $query_parts as $part ) {
				if ( strpos( $part, 'sig=' ) !== 0 && strpos( $part, 'nonce=' ) !== 0 ) {
					$filtered_parts[] = $part;
				}
			}
			sort( $filtered_parts );
			$verify_query_string = implode( '&', $filtered_parts );
		} else {
			$sig_data = wp_unslash( $_POST );
			unset( $sig_data['sig'], $sig_data['nonce'] );
			ksort( $sig_data );
			$verify_query_string = http_build_query( $sig_data, '', '&', PHP_QUERY_RFC3986 );
		}

		$expected_sig = hash_hmac( 'sha256', $verify_query_string, $key );
		if ( hash_equals( $expected_sig, $signature ) ) {
			return true;
		}

		return new WP_Error( 'invalid_signature', 'Invalid signature' );
	}

	/**
	 * Extract SQL from import payload (plain or base64).
	 *
	 * @param array $data Parsed POST data.
	 * @return string|WP_Error
	 */
	private function extract_import_sql( $data ) {
		if ( ! empty( $data['sql_gz_b64'] ) ) {
			$compressed = base64_decode( $data['sql_gz_b64'], true );
			if ( false === $compressed ) {
				return new WP_Error( 'invalid_sql', 'Invalid base64 in gzip SQL payload' );
			}
			$sql = gzdecode( $compressed );
			if ( false === $sql ) {
				return new WP_Error( 'invalid_sql', 'Failed to decompress gzip SQL payload' );
			}
			return $sql;
		}

		if ( ! empty( $data['sql_b64'] ) ) {
			$sql = base64_decode( $data['sql_b64'], true );
			if ( false === $sql ) {
				return new WP_Error( 'invalid_sql', 'Invalid base64 SQL payload' );
			}
			return $sql;
		}

		if ( isset( $data['sql'] ) ) {
			return $data['sql'];
		}

		return new WP_Error( 'missing_sql', 'Missing SQL payload' );
	}

	/**
	 * Proxy push import to remote site (server-to-server).
	 */
	public function ajax_remote_import_chunk() {
		if ( ! check_ajax_referer( 'sync_nonce', 'nonce', false ) ) {
			$this->send_error( 'Invalid nonce', 'invalid_nonce', 403 );
			return;
		}

		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 600 );

		$this->maybe_send_cors_headers();

		$raw_post = file_get_contents( 'php://input' );
		parse_str( $raw_post, $raw_data );
		if ( empty( $raw_data ) ) {
			$raw_data = wp_unslash( $_POST );
		}

		$settings  = get_option( 'sync_settings', array() );
		$local_key = isset( $settings['key'] ) ? $settings['key'] : '';

		$verify = $this->verify_raw_body_signature( $local_key );
		if ( is_wp_error( $verify ) ) {
			$this->send_error( $verify->get_error_message(), $verify->get_error_code(), 403 );
			return;
		}

		$remote_url  = isset( $raw_data['remote_url'] ) ? esc_url_raw( $raw_data['remote_url'] ) : '';
		$remote_key  = isset( $raw_data['key'] ) ? sanitize_text_field( $raw_data['key'] ) : '';
		$sql_gz_b64  = isset( $raw_data['sql_gz_b64'] ) ? $raw_data['sql_gz_b64'] : '';
		$sql         = isset( $raw_data['sql'] ) ? $raw_data['sql'] : '';

		if ( empty( $remote_url ) || empty( $remote_key ) || ( '' === $sql_gz_b64 && '' === $sql ) ) {
			$this->send_error( 'Missing parameters', 'missing_params' );
			return;
		}

		$remote_url = rtrim( $remote_url, '/' );
		$ajax_url   = $remote_url . '/wp-admin/admin-ajax.php';

		$payload = array(
			'action'        => 'sync_import_chunk',
			'key'           => $remote_key,
			'old_url'       => isset( $raw_data['old_url'] ) ? esc_url_raw( $raw_data['old_url'] ) : '',
			'new_url'       => isset( $raw_data['new_url'] ) ? esc_url_raw( $raw_data['new_url'] ) : '',
			'old_path'      => isset( $raw_data['old_path'] ) ? sanitize_text_field( $raw_data['old_path'] ) : '',
			'new_path'      => isset( $raw_data['new_path'] ) ? sanitize_text_field( $raw_data['new_path'] ) : '',
			'source_prefix' => isset( $raw_data['source_prefix'] ) ? sanitize_text_field( $raw_data['source_prefix'] ) : '',
		);

		// If the browser already gzip-encoded the SQL, pass it through without re-processing.
		// Otherwise compress now (or fall back to plain base64).
		if ( $sql_gz_b64 !== '' ) {
			$payload['sql_gz_b64'] = $sql_gz_b64;
		} elseif ( function_exists( 'gzencode' ) ) {
			$payload['sql_gz_b64'] = base64_encode( gzencode( $sql, 6 ) );
		} else {
			$payload['sql_b64'] = base64_encode( $sql );
		}

		$result = $this->remote_post( $ajax_url, $payload, $remote_key );

		if ( is_wp_error( $result ) ) {
			$this->send_error( $result->get_error_message(), $result->get_error_code() );
			return;
		}

		if ( empty( $result['success'] ) ) {
			$message = isset( $result['error'] ) ? $result['error'] : 'Remote import failed';
			$code    = isset( $result['code'] ) ? $result['code'] : 'import_failed';
			$this->send_error( $message, $code );
			return;
		}

		$this->send_response( $result );
	}

	/**
	 * Import database chunk
	 */
	public function ajax_import_chunk() {
		$this->maybe_send_cors_headers();

		if ( empty( $_POST['sig'] ) ) {
			$this->send_error( 'Missing signature', 'missing_signature', 403 );
			return;
		}

		$signature = sanitize_text_field( $_POST['sig'] );

		// Verify signature using raw POST body (avoid re-encoding issues)
		$raw_post = file_get_contents( 'php://input' );
		$raw_data = array();
		parse_str( $raw_post, $raw_data );

		$remote_key = isset( $raw_data['key'] ) ? sanitize_text_field( $raw_data['key'] ) : '';

		$signature_valid = false;
		if ( ! empty( $_POST['nonce'] ) && check_ajax_referer( 'sync_nonce', 'nonce', false ) ) {
			$signature_valid = true;
		} else {
			$verify = $this->verify_request( $remote_key );
			if ( ! is_wp_error( $verify ) ) {
				$signature_valid = true;
			} else {
				// Fallback: raw query-string HMAC (browser URLSearchParams)
				$query_parts = explode( '&', $raw_post );
				$filtered_parts = array();
				foreach ( $query_parts as $part ) {
					if ( strpos( $part, 'sig=' ) !== 0 && strpos( $part, 'nonce=' ) !== 0 ) {
						$filtered_parts[] = $part;
					}
				}
				sort( $filtered_parts );
				$verify_query_string = implode( '&', $filtered_parts );

				$settings       = get_option( 'sync_settings', array() );
				$local_key      = isset( $settings['key'] ) ? $settings['key'] : '';
				$candidate_keys = array_values( array_unique( array_filter( array( $local_key, $remote_key ) ) ) );

				foreach ( $candidate_keys as $candidate_key ) {
					$expected_sig = hash_hmac( 'sha256', $verify_query_string, $candidate_key );
					if ( hash_equals( $expected_sig, $signature ) ) {
						$signature_valid = true;
						break;
					}
				}
			}
		}

		if ( ! $signature_valid ) {
			$this->send_error( 'Invalid signature', 'invalid_signature', 403 );
			return;
		}

		// Now process the data after verification
		$remote_key = isset( $raw_data['key'] ) ? sanitize_text_field( $raw_data['key'] ) : '';
		$sql        = $this->extract_import_sql( $raw_data );
		if ( is_wp_error( $sql ) ) {
			$this->send_error( $sql->get_error_message(), $sql->get_error_code() );
			return;
		}

		$old_url = isset( $raw_data['old_url'] ) ? esc_url_raw( $raw_data['old_url'] ) : '';
		$new_url = isset( $raw_data['new_url'] ) ? esc_url_raw( $raw_data['new_url'] ) : '';
		$old_path = isset( $raw_data['old_path'] ) ? sanitize_text_field( $raw_data['old_path'] ) : '';
		$new_path = isset( $raw_data['new_path'] ) ? sanitize_text_field( $raw_data['new_path'] ) : '';
		$source_prefix = isset( $raw_data['source_prefix'] ) ? sanitize_text_field( $raw_data['source_prefix'] ) : null;

		if ( empty( $remote_key ) || '' === $sql ) {
			$this->send_error( 'Missing parameters', 'missing_params' );
			return;
		}

		@set_time_limit( 300 );

		// Replace URLs and paths if provided
		if ( ! empty( $old_url ) && ! empty( $new_url ) ) {
			$sql = $this->core->replace_urls_paths( $sql, $old_url, $new_url, $old_path, $new_path );
		}

		// IMPORTANT: Fix any malformed SQL from remote site that didn't escape newlines properly
		// This is a safety measure for backwards compatibility with older plugin versions
		if ( strpos( $sql, "\n" ) !== false || strpos( $sql, "\r" ) !== false ) {
			$sql = preg_replace_callback(
				'/VALUES\s*\([^)]+\)/is',
				function( $matches ) {
					$values_clause = $matches[0];
					$values_clause = str_replace( "\r\n", "\\r\\n", $values_clause );
					$values_clause = str_replace( "\n", "\\n", $values_clause );
					$values_clause = str_replace( "\r", "\\r", $values_clause );
					return $values_clause;
				},
				$sql
			);
		}

		// Import chunk
		$result = $this->core->import_chunk( $sql, null, $source_prefix );
		if ( is_wp_error( $result ) ) {
			$this->send_error( 'Import failed: ' . $result->get_error_message(), 'import_failed' );
			return;
		}

		$this->send_response( array(
			'success' => true,
			'message' => 'Chunk imported successfully',
		) );
	}

	/**
	 * Finalize migration
	 */
	public function ajax_finalize_migration() {
		if ( ! $this->authenticate_request() ) {
			return;
		}

		$remote_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';

		if ( empty( $remote_key ) ) {
			$this->send_error( 'Missing key', 'missing_key' );
			return;
		}

		// Finalize migration
		$result = $this->core->finalize_migration();
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code() ? $result->get_error_code() : 'finalize_failed';
			$this->send_error( 'Finalization failed: ' . $result->get_error_message(), $code );
			return;
		}

		$this->send_response( array(
			'success' => true,
			'message' => 'Migration finalized successfully',
		) );
	}

	/**
	 * Make remote POST request
	 *
	 * @param string $url Remote URL
	 * @param array $data Data to send
	 * @param string $key Secret key for signing
	 * @return array|WP_Error
	 */
	public function remote_post( $url, $data, $key ) {
		// Add signature (remove existing sig first)
		unset( $data['sig'] );
		$data['sig'] = SYNC_Security::create_signature( $data, $key );

		$settings  = get_option( 'sync_settings', array() );
		$skip_ssl  = ! empty( $settings['skip_ssl'] );
		$basic_user = isset( $settings['basic_auth_user'] ) ? $settings['basic_auth_user'] : '';
		$basic_pass = isset( $settings['basic_auth_pass'] ) ? $settings['basic_auth_pass'] : '';

		$args = array(
			'timeout'   => 600,
			'body'      => $data,
			'sslverify' => ( $skip_ssl || SYNC_Security::is_localhost( $url ) ) ? false : true,
		);

		if ( $basic_user !== '' ) {
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $basic_user . ':' . $basic_pass );
		}

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status === 403 || ( is_string( $body ) && stripos( $body, 'Access Denied' ) !== false ) ) {
			return new WP_Error(
				'waf_blocked',
				"HTTP {$status}: request blocked by WAF or firewall. Try adding the remote site's admin-ajax.php to your WAF allowlist, or check if the remote site requires HTTP Basic Auth credentials."
			);
		}

		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'invalid_response',
				"HTTP {$status}: non-JSON response from remote site: " . substr( $body, 0, 500 )
			);
		}

		return $decoded;
	}

	/**
	 * Save connection settings (Basic Auth credentials, SSL skip).
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_error( 'Insufficient permissions', 'forbidden', 403 );
			return;
		}

		$settings = get_option( 'sync_settings', array() );

		$settings['basic_auth_user'] = sanitize_text_field( isset( $_POST['basic_auth_user'] ) ? $_POST['basic_auth_user'] : '' );
		$settings['basic_auth_pass'] = sanitize_text_field( isset( $_POST['basic_auth_pass'] ) ? $_POST['basic_auth_pass'] : '' );
		$settings['skip_ssl']        = ! empty( $_POST['skip_ssl'] );
		$concurrency                 = isset( $_POST['concurrency'] ) ? (int) $_POST['concurrency'] : 3;
		$settings['concurrency']     = max( 1, min( 10, $concurrency ) );

		update_option( 'sync_settings', $settings );

		$this->send_response( array( 'success' => true ) );
	}

	/**
	 * Export a batch of small tables as a single combined SQL payload.
	 */
	public function ajax_export_batch() {
		$this->maybe_send_cors_headers();

		$remote_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';
		$tables_raw = isset( $_POST['tables'] ) ? wp_unslash( $_POST['tables'] ) : '';

		if ( empty( $remote_key ) || empty( $tables_raw ) ) {
			$this->send_error( 'Missing parameters', 'missing_params' );
			return;
		}

		$verify = $this->verify_request( $remote_key );
		if ( is_wp_error( $verify ) ) {
			$this->send_error( $verify->get_error_message(), $verify->get_error_code() );
			return;
		}

		$tables = json_decode( $tables_raw, true );
		if ( ! is_array( $tables ) ) {
			$this->send_error( 'Invalid tables parameter', 'invalid_params' );
			return;
		}

		// Validate: only export tables that actually exist
		$known_tables = $this->core->get_tables();
		$safe_tables  = array_values( array_intersect( $tables, $known_tables ) );

		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 300 );

		$combined_sql = '';
		foreach ( $safe_tables as $table ) {
			// PHP_INT_MAX: export all rows (small tables, so no memory concern)
			$combined_sql .= $this->core->export_table( $table, 0, PHP_INT_MAX );
		}

		$response_data = array(
			'success'         => true,
			'tables_exported' => $safe_tables,
		);

		if ( function_exists( 'gzencode' ) ) {
			$response_data['sql_gz_b64'] = base64_encode( gzencode( $combined_sql, 6 ) );
		} else {
			$response_data['sql'] = $combined_sql;
		}

		$this->send_response( $response_data );
	}

	// ── File sync handlers ────────────────────────────────────────────────────────

	/**
	 * Return a file inventory (path → hash + size) for uploads or plugins dir.
	 * Called on both local (nonce) and remote (signature) sides.
	 */
	public function ajax_get_file_inventory() {
		if ( ! $this->authenticate_request() ) {
			return;
		}

		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 300 );

		$dir_type = isset( $_POST['dir_type'] ) ? sanitize_text_field( $_POST['dir_type'] ) : '';
		if ( ! in_array( $dir_type, array( 'uploads', 'plugins' ), true ) ) {
			$this->send_error( 'Invalid dir_type', 'invalid_params' );
			return;
		}

		$inventory = $this->files->get_file_inventory( $dir_type );

		$this->send_response( array(
			'success'   => true,
			'inventory' => $inventory,
		) );
	}

	/**
	 * Create the plugins ZIP (on offset=0 / no zip_id) and return the next chunk.
	 * Called on the source site (remote for pull, local for push).
	 */
	public function ajax_export_zip_chunk() {
		$this->maybe_send_cors_headers();

		if ( ! $this->authenticate_request() ) {
			return;
		}

		$zip_id = isset( $_POST['zip_id'] ) ? sanitize_text_field( $_POST['zip_id'] ) : '';
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 600 );

		// Create ZIP on first call
		if ( 0 === $offset && empty( $zip_id ) ) {
			$zip_id = $this->files->create_plugins_zip();
			if ( is_wp_error( $zip_id ) ) {
				$this->send_error( $zip_id->get_error_message(), $zip_id->get_error_code() );
				return;
			}
		}

		$result = $this->files->export_zip_chunk( $zip_id, $offset );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result->get_error_message(), $result->get_error_code() );
			return;
		}

		$this->send_response( array_merge( array( 'success' => true ), $result ) );
	}

	/**
	 * Receive a chunk of the incoming plugins ZIP.
	 * Called on the destination site (local for pull, remote for push).
	 */
	public function ajax_import_zip_chunk() {
		if ( ! $this->authenticate_request() ) {
			return;
		}

		@ini_set( 'memory_limit', '256M' );
		@set_time_limit( 300 );

		$zip_id   = isset( $_POST['zip_id'] ) ? sanitize_text_field( $_POST['zip_id'] ) : '';
		$data_b64 = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';

		if ( empty( $zip_id ) || '' === $data_b64 ) {
			$this->send_error( 'Missing parameters', 'missing_params' );
			return;
		}

		$result = $this->files->import_zip_chunk( $zip_id, $data_b64 );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result->get_error_message(), $result->get_error_code() );
			return;
		}

		$this->send_response( array( 'success' => true ) );
	}

	/**
	 * Extract the assembled plugins ZIP into wp-content/plugins.
	 * Called on the destination site after all chunks received.
	 */
	public function ajax_finalize_plugins() {
		if ( ! $this->authenticate_request() ) {
			return;
		}

		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 600 );

		$zip_id = isset( $_POST['zip_id'] ) ? sanitize_text_field( $_POST['zip_id'] ) : '';

		if ( empty( $zip_id ) ) {
			$this->send_error( 'Missing zip_id', 'missing_params' );
			return;
		}

		$result = $this->files->finalize_plugins_zip( $zip_id );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result->get_error_message(), $result->get_error_code() );
			return;
		}

		$this->send_response( array( 'success' => true ) );
	}

	/**
	 * Return a binary chunk of a specific uploads file.
	 * Called on the source site.
	 */
	public function ajax_export_upload_chunk() {
		$this->maybe_send_cors_headers();

		if ( ! $this->authenticate_request() ) {
			return;
		}

		$relative_path = isset( $_POST['relative_path'] ) ? wp_unslash( $_POST['relative_path'] ) : '';
		$offset        = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

		if ( '' === $relative_path ) {
			$this->send_error( 'Missing relative_path', 'missing_params' );
			return;
		}

		@ini_set( 'memory_limit', '256M' );
		@set_time_limit( 300 );

		$result = $this->files->export_upload_chunk( $relative_path, $offset );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result->get_error_message(), $result->get_error_code() );
			return;
		}

		$this->send_response( array_merge( array( 'success' => true ), $result ) );
	}

	/**
	 * Receive a chunk of an uploads file; finalize (move to final path) on last chunk.
	 * Called on the destination site.
	 */
	public function ajax_import_upload_chunk() {
		if ( ! $this->authenticate_request() ) {
			return;
		}

		@ini_set( 'memory_limit', '256M' );
		@set_time_limit( 300 );

		$relative_path = isset( $_POST['relative_path'] ) ? wp_unslash( $_POST['relative_path'] ) : '';
		$data_b64      = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$is_last       = ! empty( $_POST['is_last'] );

		if ( '' === $relative_path || '' === $data_b64 ) {
			$this->send_error( 'Missing parameters', 'missing_params' );
			return;
		}

		$result = $this->files->import_upload_chunk( $relative_path, $data_b64, $is_last );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result->get_error_message(), $result->get_error_code() );
			return;
		}

		$this->send_response( array( 'success' => true ) );
	}
}

