<?php
/**
 * File Sync Class
 *
 * Handles uploads and plugins directory sync: inventory, ZIP transfer (plugins),
 * and chunked file transfer (uploads).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SYNC_Files {

	private static $instance = null;
	const CHUNK_SIZE = 2097152; // 2 MB per chunk

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function get_temp_dir() {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/.sync_temp';
	}

	private function get_uploads_dir() {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'];
	}

	private function get_plugins_dir() {
		return WP_PLUGIN_DIR;
	}

	/**
	 * Recursively scan a directory and return a file inventory.
	 *
	 * @param string $dir_type 'uploads' or 'plugins'
	 * @return array [ 'relative/path' => [ 'hash' => md5, 'size' => bytes ] ]
	 */
	public function get_file_inventory( $dir_type ) {
		$base_dir = ( 'plugins' === $dir_type ) ? $this->get_plugins_dir() : $this->get_uploads_dir();
		$inventory = array();

		if ( ! is_dir( $base_dir ) ) {
			return $inventory;
		}

		$real_base  = realpath( $base_dir );
		$skip_names = array( '.DS_Store', 'Thumbs.db', 'desktop.ini' );

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$path     = $file->getRealPath();
				$relative = ltrim( str_replace( $real_base, '', $path ), DIRECTORY_SEPARATOR . '/' );
				$relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

				if ( '' === $relative || strpos( $relative, '.sync_temp' ) === 0 ) {
					continue;
				}

				if ( in_array( basename( $path ), $skip_names, true ) ) {
					continue;
				}

				$inventory[ $relative ] = array(
					'hash' => md5_file( $path ),
					'size' => $file->getSize(),
				);
			}
		} catch ( UnexpectedValueException $e ) {
			// Permission errors on some dirs — return what we have
		}

		return $inventory;
	}

	/**
	 * Return relative paths present in $source but absent or hash-different in $dest.
	 *
	 * @param array $source
	 * @param array $dest
	 * @return array
	 */
	public function diff_inventories( $source, $dest ) {
		$to_transfer = array();
		foreach ( $source as $path => $info ) {
			$src_hash = is_array( $info ) ? $info['hash'] : $info;
			if ( ! isset( $dest[ $path ] ) ) {
				$to_transfer[] = $path;
			} else {
				$dst_hash = is_array( $dest[ $path ] ) ? $dest[ $path ]['hash'] : $dest[ $path ];
				if ( $src_hash !== $dst_hash ) {
					$to_transfer[] = $path;
				}
			}
		}
		return $to_transfer;
	}

	// ── Plugins ZIP ──────────────────────────────────────────────────────────────

	/**
	 * Create a ZIP of wp-content/plugins. Returns a zip_id string or WP_Error.
	 *
	 * @return string|WP_Error
	 */
	public function create_plugins_zip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', 'ZipArchive PHP extension is not available.' );
		}

		$plugins_dir = $this->get_plugins_dir();
		$temp_dir    = $this->get_temp_dir();

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$zip_id   = 'pl-' . uniqid( '', true );
		$zip_path = $temp_dir . '/' . $zip_id . '.zip';

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_create_failed', 'Failed to create plugins ZIP.' );
		}

		$real_plugins = realpath( $plugins_dir );
		$base_len     = strlen( $real_plugins ) + 1;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $plugins_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$local_name = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getRealPath(), $base_len ) );
					$zip->addFile( $file->getRealPath(), $local_name );
				}
			}
		} catch ( UnexpectedValueException $e ) {
			$zip->close();
			@unlink( $zip_path );
			return new WP_Error( 'zip_scan_failed', 'Error scanning plugins directory: ' . $e->getMessage() );
		}

		$zip->close();
		return $zip_id;
	}

	/**
	 * Read a chunk of an export ZIP.
	 *
	 * @param string $zip_id
	 * @param int    $offset
	 * @return array|WP_Error
	 */
	public function export_zip_chunk( $zip_id, $offset ) {
		if ( ! $this->is_valid_id( $zip_id ) ) {
			return new WP_Error( 'invalid_id', 'Invalid ZIP ID.' );
		}

		$zip_path = $this->get_temp_dir() . '/' . $zip_id . '.zip';

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'zip_not_found', 'ZIP file not found.' );
		}

		$total_size = filesize( $zip_path );
		$fh         = fopen( $zip_path, 'rb' );
		if ( ! $fh ) {
			return new WP_Error( 'read_error', 'Cannot open ZIP for reading.' );
		}

		fseek( $fh, (int) $offset );
		$data      = fread( $fh, self::CHUNK_SIZE );
		fclose( $fh );

		$next_offset = (int) $offset + strlen( $data );
		$done        = ( $next_offset >= $total_size );

		if ( $done ) {
			@unlink( $zip_path );
		}

		return array(
			'zip_id'      => $zip_id,
			'data'        => base64_encode( $data ),
			'offset'      => (int) $offset,
			'next_offset' => $next_offset,
			'total_size'  => $total_size,
			'done'        => $done,
		);
	}

	/**
	 * Append a base64-decoded chunk to the incoming (import) ZIP.
	 *
	 * @param string $zip_id
	 * @param string $data_b64 Base64-encoded binary chunk
	 * @return true|WP_Error
	 */
	public function import_zip_chunk( $zip_id, $data_b64 ) {
		if ( ! $this->is_valid_id( $zip_id ) ) {
			return new WP_Error( 'invalid_id', 'Invalid ZIP ID.' );
		}

		$temp_dir = $this->get_temp_dir();
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$data = base64_decode( $data_b64, true );
		if ( false === $data ) {
			return new WP_Error( 'invalid_data', 'Invalid base64 chunk.' );
		}

		$zip_path = $temp_dir . '/import-' . $zip_id . '.zip';
		if ( false === file_put_contents( $zip_path, $data, FILE_APPEND ) ) {
			return new WP_Error( 'write_failed', 'Failed to write ZIP chunk.' );
		}

		return true;
	}

	/**
	 * Extract the assembled import ZIP into wp-content/plugins (non-destructive).
	 * Never deletes plugins that exist on the target but not in the ZIP.
	 *
	 * @param string $zip_id
	 * @return true|WP_Error
	 */
	public function finalize_plugins_zip( $zip_id ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', 'ZipArchive PHP extension is not available.' );
		}

		if ( ! $this->is_valid_id( $zip_id ) ) {
			return new WP_Error( 'invalid_id', 'Invalid ZIP ID.' );
		}

		$temp_dir    = $this->get_temp_dir();
		$zip_path    = $temp_dir . '/import-' . $zip_id . '.zip';
		$plugins_dir = $this->get_plugins_dir();

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'zip_not_found', 'Assembled ZIP not found.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open_failed', 'Failed to open ZIP for extraction.' );
		}

		$real_plugins = realpath( $plugins_dir );
		$count        = $zip->numFiles;

		for ( $i = 0; $i < $count; $i++ ) {
			$entry_name = $zip->getNameIndex( $i );
			if ( false === $entry_name || '/' === substr( $entry_name, -1 ) ) {
				continue;
			}

			$target     = $plugins_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $entry_name );
			$target_dir = dirname( $target );

			if ( ! is_dir( $target_dir ) ) {
				wp_mkdir_p( $target_dir );
			}

			$real_target_dir = realpath( $target_dir );
			if ( false === $real_target_dir || 0 !== strpos( $real_target_dir, $real_plugins ) ) {
				$zip->close();
				@unlink( $zip_path );
				return new WP_Error( 'path_traversal', 'ZIP contains unsafe path: ' . esc_html( $entry_name ) );
			}

			$content = $zip->getFromIndex( $i );
			if ( false !== $content ) {
				file_put_contents( $target, $content );
			}
		}

		$zip->close();
		@unlink( $zip_path );

		return true;
	}

	// ── Upload file transfer ─────────────────────────────────────────────────────

	/**
	 * Read a chunk of an uploads file for export.
	 *
	 * @param string $relative_path Relative path within uploads dir
	 * @param int    $offset        Byte offset
	 * @return array|WP_Error
	 */
	public function export_upload_chunk( $relative_path, $offset ) {
		$uploads_dir = $this->get_uploads_dir();

		if ( ! $this->validate_relative_path( $relative_path, $uploads_dir ) ) {
			return new WP_Error( 'invalid_path', 'Invalid file path.' );
		}

		$full_path = $uploads_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );

		if ( ! is_file( $full_path ) ) {
			return new WP_Error( 'not_found', 'File not found.' );
		}

		$file_size = filesize( $full_path );
		$fh        = fopen( $full_path, 'rb' );
		if ( ! $fh ) {
			return new WP_Error( 'read_error', 'Cannot open file for reading.' );
		}

		fseek( $fh, (int) $offset );
		$data      = fread( $fh, self::CHUNK_SIZE );
		fclose( $fh );

		$next_offset = (int) $offset + strlen( $data );

		return array(
			'data'        => base64_encode( $data ),
			'offset'      => (int) $offset,
			'next_offset' => $next_offset,
			'file_size'   => $file_size,
			'done'        => ( $next_offset >= $file_size ),
		);
	}

	/**
	 * Receive a chunk of an incoming uploads file, write to temp, finalize on last chunk.
	 *
	 * @param string $relative_path
	 * @param string $data_b64      Base64-encoded chunk
	 * @param bool   $is_last
	 * @return true|WP_Error
	 */
	public function import_upload_chunk( $relative_path, $data_b64, $is_last ) {
		$uploads_dir = $this->get_uploads_dir();

		if ( ! $this->validate_relative_path( $relative_path, $uploads_dir ) ) {
			return new WP_Error( 'invalid_path', 'Invalid file path.' );
		}

		if ( preg_match( '/\.php\d?$/i', $relative_path ) ) {
			return new WP_Error( 'blocked', 'PHP files cannot be written to the uploads directory.' );
		}

		$data = base64_decode( $data_b64, true );
		if ( false === $data ) {
			return new WP_Error( 'invalid_data', 'Invalid base64 chunk.' );
		}

		$temp_base = $this->get_temp_dir() . '/uploads';
		$temp_path = $temp_base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
		$temp_dir  = dirname( $temp_path );

		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		if ( false === file_put_contents( $temp_path, $data, FILE_APPEND ) ) {
			return new WP_Error( 'write_failed', 'Failed to write chunk.' );
		}

		if ( $is_last ) {
			return $this->finalize_upload_file( $relative_path );
		}

		return true;
	}

	/**
	 * Move an assembled upload file from temp to its final location.
	 *
	 * @param string $relative_path
	 * @return true|WP_Error
	 */
	private function finalize_upload_file( $relative_path ) {
		$uploads_dir = $this->get_uploads_dir();
		$temp_base   = $this->get_temp_dir() . '/uploads';

		$temp_path  = $temp_base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
		$final_path = $uploads_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );

		if ( ! file_exists( $temp_path ) ) {
			return new WP_Error( 'temp_missing', 'Assembled file not found in temp.' );
		}

		$final_dir = dirname( $final_path );
		if ( ! is_dir( $final_dir ) ) {
			wp_mkdir_p( $final_dir );
		}

		if ( ! rename( $temp_path, $final_path ) ) {
			if ( copy( $temp_path, $final_path ) ) {
				@unlink( $temp_path );
			} else {
				return new WP_Error( 'move_failed', 'Failed to move file to final location.' );
			}
		}

		return true;
	}

	// ── Security ─────────────────────────────────────────────────────────────────

	/**
	 * Validate a relative path: no null bytes, no .., no absolute path prefixes.
	 *
	 * @param string $relative_path
	 * @param string $base_dir      Unused (reserved for realpath check if dir exists)
	 * @return bool
	 */
	public function validate_relative_path( $relative_path, $base_dir ) {
		if ( '' === $relative_path ) {
			return false;
		}
		if ( strpos( $relative_path, "\0" ) !== false ) {
			return false;
		}
		if ( strpos( $relative_path, '..' ) !== false ) {
			return false;
		}
		if ( '/' === $relative_path[0] || '\\' === $relative_path[0] ) {
			return false;
		}
		// Windows absolute path (e.g. C:/)
		if ( strlen( $relative_path ) > 1 && ':' === $relative_path[1] ) {
			return false;
		}
		return true;
	}

	/**
	 * Validate a zip_id / import ID token (alphanumeric, dots, hyphens only).
	 *
	 * @param string $id
	 * @return bool
	 */
	private function is_valid_id( $id ) {
		return (bool) preg_match( '/^[a-zA-Z0-9.\-]+$/', $id );
	}

	// ── Cleanup ──────────────────────────────────────────────────────────────────

	/**
	 * Remove the temp directory and all its contents.
	 *
	 * @return void
	 */
	public function cleanup_temp_files() {
		$temp_dir = $this->get_temp_dir();
		if ( is_dir( $temp_dir ) ) {
			$this->rmdir_recursive( $temp_dir );
		}
	}

	/**
	 * @param string $dir
	 * @return void
	 */
	private function rmdir_recursive( $dir ) {
		$items = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $items as $item ) {
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
