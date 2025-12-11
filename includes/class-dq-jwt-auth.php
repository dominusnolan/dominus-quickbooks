<?php
/**
 * JWT Authentication Class
 *
 * Provides JWT token-based authentication for the Spark web app.
 * Uses a simple JWT implementation without external libraries.
 *
 * @package Dominus_QuickBooks
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DQ_JWT_Auth
 *
 * Handles JWT token generation, validation, and user authentication.
 */
class DQ_JWT_Auth {

	/**
	 * JWT secret key.
	 * Uses WordPress AUTH_KEY constant as the secret.
	 *
	 * @return string
	 */
	private static function get_secret_key() {
		// Check if AUTH_KEY is defined and strong enough (min 32 characters)
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY && strlen( AUTH_KEY ) >= 32 && AUTH_KEY !== 'put your unique phrase here' ) {
			return AUTH_KEY;
		}

		// Check for stored JWT secret in options
		$stored_secret = get_option( 'dq_jwt_secret_key' );
		if ( $stored_secret && strlen( $stored_secret ) >= 32 ) {
			return $stored_secret;
		}

		// Generate and store a new random secret (only runs once)
		$new_secret = wp_generate_password( 64, true, true );
		update_option( 'dq_jwt_secret_key', $new_secret, false ); // Don't autoload
		
		return $new_secret;
	}

	/**
	 * Token expiration time (7 days).
	 *
	 * @return int
	 */
	public static function get_token_expiration() {
		return apply_filters( 'dq_jwt_token_expiration', 7 * DAY_IN_SECONDS );
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $data Data to encode.
	 * @return string
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Data to decode.
	 * @return string|false
	 */
	private static function base64url_decode( $data ) {
		$remainder = strlen( $data ) % 4;
		if ( $remainder ) {
			$padlen = 4 - $remainder;
			$data .= str_repeat( '=', $padlen );
		}
		return base64_decode( strtr( $data, '-_', '+/' ) );
	}

	/**
	 * Generate a JWT token for a user.
	 *
	 * @param int $user_id The user ID.
	 * @return string|WP_Error The JWT token or WP_Error on failure.
	 */
	public static function generate_token( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'dq_jwt_invalid_user',
				__( 'Invalid user ID.', 'dominus-quickbooks' ),
				array( 'status' => 400 )
			);
		}

		$issued_at = time();
		$expiration = $issued_at + self::get_token_expiration();

		// JWT Header
		$header = array(
			'typ' => 'JWT',
			'alg' => 'HS256',
		);

		// JWT Payload
		$payload = array(
			'iss'        => get_bloginfo( 'url' ),
			'iat'        => $issued_at,
			'exp'        => $expiration,
			'user_id'    => $user_id,
			'user_login' => $user->user_login,
		);

		// Encode header and payload
		$header_encoded = self::base64url_encode( wp_json_encode( $header ) );
		$payload_encoded = self::base64url_encode( wp_json_encode( $payload ) );

		// Create signature
		$signature = hash_hmac(
			'sha256',
			$header_encoded . '.' . $payload_encoded,
			self::get_secret_key(),
			true
		);
		$signature_encoded = self::base64url_encode( $signature );

		// Return JWT token
		return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
	}

	/**
	 * Validate and decode a JWT token.
	 *
	 * @param string $token The JWT token.
	 * @return array|WP_Error The decoded payload or WP_Error on failure.
	 */
	public static function validate_token( $token ) {
		if ( empty( $token ) ) {
			return new WP_Error(
				'dq_jwt_missing_token',
				__( 'JWT token is required.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		// Split token into parts
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return new WP_Error(
				'dq_jwt_invalid_format',
				__( 'Invalid JWT token format.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		list( $header_encoded, $payload_encoded, $signature_encoded ) = $parts;

		// Verify signature
		$signature_check = hash_hmac(
			'sha256',
			$header_encoded . '.' . $payload_encoded,
			self::get_secret_key(),
			true
		);
		$signature_check_encoded = self::base64url_encode( $signature_check );

		if ( ! hash_equals( $signature_encoded, $signature_check_encoded ) ) {
			return new WP_Error(
				'dq_jwt_invalid_signature',
				__( 'Invalid JWT token signature.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		// Decode payload
		$payload_json = self::base64url_decode( $payload_encoded );
		if ( ! $payload_json ) {
			return new WP_Error(
				'dq_jwt_decode_error',
				__( 'Failed to decode JWT token.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		$payload = json_decode( $payload_json, true );
		if ( ! $payload ) {
			return new WP_Error(
				'dq_jwt_invalid_payload',
				__( 'Invalid JWT token payload.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		// Check expiration
		if ( ! isset( $payload['exp'] ) || $payload['exp'] < time() ) {
			return new WP_Error(
				'dq_jwt_expired',
				__( 'JWT token has expired.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		// Validate issuer
		if ( ! isset( $payload['iss'] ) || $payload['iss'] !== get_bloginfo( 'url' ) ) {
			return new WP_Error(
				'dq_jwt_invalid_issuer',
				__( 'Invalid JWT token issuer.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		return $payload;
	}

	/**
	 * Get WP_User from a valid JWT token.
	 *
	 * @param string $token The JWT token.
	 * @return WP_User|WP_Error The user object or WP_Error on failure.
	 */
	public static function get_user_from_token( $token ) {
		$payload = self::validate_token( $token );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( ! isset( $payload['user_id'] ) ) {
			return new WP_Error(
				'dq_jwt_missing_user_id',
				__( 'JWT token missing user_id.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		$user = get_user_by( 'id', $payload['user_id'] );
		if ( ! $user ) {
			return new WP_Error(
				'dq_jwt_user_not_found',
				__( 'User not found.', 'dominus-quickbooks' ),
				array( 'status' => 401 )
			);
		}

		return $user;
	}

	/**
	 * Extract JWT token from Authorization header.
	 *
	 * @return string|null The JWT token or null if not found.
	 */
	public static function get_token_from_request() {
		// Check Authorization header
		$auth_header = null;

		// Try to get from $_SERVER
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( isset( $headers['Authorization'] ) ) {
				$auth_header = $headers['Authorization'];
			} elseif ( isset( $headers['authorization'] ) ) {
				$auth_header = $headers['authorization'];
			}
		}

		if ( ! $auth_header ) {
			return null;
		}

		// Extract Bearer token
		if ( preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}
}
