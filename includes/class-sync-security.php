<?php
/**
 * Security and Authentication Class
 *
 * Handles key generation, HMAC signing, and signature verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SYNC_Security {

	/**
	 * Generate a random 32-character key
	 *
	 * @return string
	 */
	public static function generate_key() {
		if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
			$key = bin2hex( openssl_random_pseudo_bytes( 16 ) );
		} else {
			$key = wp_generate_password( 32, false );
		}
		return $key;
	}

	/**
	 * Create HMAC signature for request
	 *
	 * @param array $data Data to sign
	 * @param string $key Secret key
	 * @return string
	 */
	public static function create_signature( $data, $key ) {
		// Remove signature from data if present
		unset( $data['sig'] );

		// Sort data by key
		ksort( $data );

		// Create query string
		$query_string = http_build_query( $data, '', '&' );

		// Create signature
		$signature = hash_hmac( 'sha256', $query_string, $key );

		return $signature;
	}

	/**
	 * Verify HMAC signature
	 *
	 * @param array $data Data to verify
	 * @param string $key Secret key
	 * @param string $signature Signature to verify against
	 * @return bool
	 */
	public static function verify_signature( $data, $key, $signature ) {
		$expected_signature = self::create_signature( $data, $key );
		return hash_equals( $expected_signature, $signature );
	}

	/**
	 * Sanitize input data
	 *
	 * @param mixed $input Input to sanitize
	 * @return mixed
	 */
	public static function sanitize_input( $input ) {
		if ( is_array( $input ) ) {
			return array_map( array( __CLASS__, 'sanitize_input' ), $input );
		}

		if ( is_string( $input ) ) {
			return sanitize_text_field( $input );
		}

		return $input;
	}

	/**
	 * Verify nonce for AJAX requests
	 *
	 * @param string $action Action name
	 * @param string $nonce Nonce value
	 * @return bool
	 */
	public static function verify_nonce( $action, $nonce ) {
		return wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Check if URL is localhost
	 *
	 * @param string $url URL to check
	 * @return bool
	 */
	public static function is_localhost( $url ) {
		$host = parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$localhost_patterns = array(
			'localhost',
			'127.0.0.1',
			'::1',
			'0.0.0.0',
		);

		foreach ( $localhost_patterns as $pattern ) {
			if ( $host === $pattern || strpos( $host, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize URL for localhost connections
	 *
	 * @param string $url URL to normalize
	 * @return string
	 */
	public static function normalize_url( $url ) {
		// Remove trailing slash
		$url = rtrim( $url, '/' );

		// If localhost and HTTPS, try HTTP first
		if ( self::is_localhost( $url ) && strpos( $url, 'https://' ) === 0 ) {
			// Keep HTTPS but allow fallback
		}

		return $url;
	}
}

