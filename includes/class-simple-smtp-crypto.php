<?php
/**
 * At-rest encryption for stored secrets (SMTP password, SES secret key).
 *
 * Secrets are AES-256-CBC encrypted with a key derived from the site's
 * `secure_auth` salt, so a database dump alone does not expose them. The
 * ciphertext carries a version prefix; anything without it (a legacy
 * plaintext value, or a value from a site with openssl unavailable) is
 * passed through unchanged so upgrades never lose an existing password.
 *
 * @package simple-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Simple_SMTP_Crypto {

	const PREFIX = 'ssmtp:v1:';
	const CIPHER = 'aes-256-cbc';

	/**
	 * 32-byte encryption key derived from the site salt. Stable for the life
	 * of the salt, which is what lets a stored secret decrypt on later loads.
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'secure_auth' ), true );
	}

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Encrypt a plaintext secret. Returns a prefixed, base64 blob. If openssl
	 * is missing or encryption fails, the plaintext is returned unchanged
	 * (better a working mailer than a hard failure on an unusual host).
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! self::available() ) {
			return $plain;
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plain;
		}

		return self::PREFIX . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a stored secret. A value without our prefix is assumed to be
	 * plaintext (legacy / openssl-less) and returned as-is.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}

		if ( ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a stored value is one of our ciphertext blobs.
	 */
	public static function is_encrypted( string $stored ): bool {
		return 0 === strpos( $stored, self::PREFIX );
	}
}
