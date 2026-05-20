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
	const CHUNK_SIZE = 2097152; // 2 MB per chunk (base64 = ~2.7 MB on wire, safe for nginx defaults)

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

	/**
	 * Recursively scan wp-content/uploads and return a file inventory.
	 *
	 * @return array [ 'relative/path' => [ 'hash' => string, 'size' => bytes ] ]
	 */
	public function get_file_inventory() {
		$base_dir  = $this->get_uploads_dir();
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

				$size = $file->getSize();
				$hash = ( $size <= 1048576 )
					? md5_file( $path )
					: $file->getMTime() . ':' . $size;

				$inventory[ $relative ] = array(
					'hash' => $hash,
					'size' => $size,
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

		$compressed = false;
		if ( function_exists( 'gzencode' ) && strlen( $data ) > 1024 ) {
			$gz = gzencode( $data, 1 );
			if ( false !== $gz && strlen( $gz ) < strlen( $data ) ) {
				$data       = $gz;
				$compressed = true;
			}
		}

		return array(
			'data'        => base64_encode( $data ),
			'compressed'  => $compressed,
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
	public function import_upload_chunk( $relative_path, $data_b64, $is_last, $compressed = false, $chunk_offset = -1 ) {
		$uploads_dir = $this->get_uploads_dir();

		if ( ! $this->validate_relative_path( $relative_path, $uploads_dir ) ) {
			return new WP_Error( 'invalid_path', 'Invalid file path.' );
		}

		if ( preg_match( '/\.php\d?$/i', $relative_path ) ) {
			return new WP_Error( 'blocked', 'PHP files cannot be written to the uploads directory.' );
		}

		$temp_base = $this->get_temp_dir() . '/uploads';
		$temp_path = $temp_base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
		$temp_dir  = dirname( $temp_path );

		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		// Idempotency: if temp file already contains this chunk (retry after timeout), skip the write.
		if ( $chunk_offset >= 0 && file_exists( $temp_path ) && filesize( $temp_path ) > $chunk_offset ) {
			if ( $is_last ) {
				return $this->finalize_upload_file( $relative_path );
			}
			return true;
		}

		$data = base64_decode( $data_b64, true );
		if ( false === $data ) {
			return new WP_Error( 'invalid_data', 'Invalid base64 chunk.' );
		}

		if ( $compressed ) {
			$dec = @gzdecode( $data );
			if ( false !== $dec ) {
				$data = $dec;
			}
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
