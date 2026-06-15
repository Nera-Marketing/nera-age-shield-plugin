<?php
/**
 * Persistence for the verified date of birth (encrypted at rest) and verification flags.
 *
 * @package Nera_DCMS_Age_Gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_DCMS_Storage
 */
class Nera_DCMS_Storage {

	const META_DOB         = '_nera_dob_encrypted';
	const META_VERIFIED    = '_nera_age_verified';
	const META_VERIFIED_AT = '_nera_dob_verified_at';

	const CIPHER = 'aes-256-cbc';

	/**
	 * Init.
	 */
	public static function init() {
		// GDPR: remove the stored DOB when an account is deleted.
		add_action( 'delete_user', array( __CLASS__, 'delete_dob' ) );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'delete_dob' ) );
	}

	/**
	 * Persist a verified date of birth for a user (encrypted) and mark them verified.
	 *
	 * @param int    $user_id User ID.
	 * @param string $dob     Normalised "Y-m-d" date of birth.
	 * @return bool
	 */
	public static function save_dob( $user_id, $dob ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}

		$encrypted = self::encrypt( $dob );
		if ( null === $encrypted ) {
			return false;
		}

		update_user_meta( $user_id, self::META_DOB, $encrypted );
		update_user_meta( $user_id, self::META_VERIFIED, '1' );
		update_user_meta( $user_id, self::META_VERIFIED_AT, time() );

		return true;
	}

	/**
	 * Whether a user has a stored date of birth.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_dob( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}
		return '' !== (string) get_user_meta( $user_id, self::META_DOB, true );
	}

	/**
	 * Whether a user is age verified.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_verified( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}
		return '1' === (string) get_user_meta( $user_id, self::META_VERIFIED, true );
	}

	/**
	 * Retrieve and decrypt a user's stored date of birth.
	 *
	 * @param int $user_id User ID.
	 * @return string|null Normalised "Y-m-d" date, or null when unavailable.
	 */
	public static function get_dob( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return null;
		}
		$stored = (string) get_user_meta( $user_id, self::META_DOB, true );
		if ( '' === $stored ) {
			return null;
		}
		return self::decrypt( $stored );
	}

	/**
	 * Remove all age-gate meta for a user (account closure / GDPR).
	 *
	 * @param int $user_id User ID.
	 */
	public static function delete_dob( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return;
		}
		delete_user_meta( $user_id, self::META_DOB );
		delete_user_meta( $user_id, self::META_VERIFIED );
		delete_user_meta( $user_id, self::META_VERIFIED_AT );
	}

	/**
	 * Encrypt a plaintext value for storage.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string|null Base64( iv . ciphertext ), or null on failure.
	 */
	private static function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) || '' === $plaintext || ! function_exists( 'openssl_encrypt' ) ) {
			return null;
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $iv_len ) {
			return null;
		}

		$iv     = random_bytes( $iv_len );
		$cipher = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return null;
		}

		return base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored Base64( iv . ciphertext ).
	 * @return string|null Plaintext, or null on failure.
	 */
	private static function decrypt( $stored ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return null;
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $iv_len || strlen( $raw ) <= $iv_len ) {
			return null;
		}

		$iv     = substr( $raw, 0, $iv_len );
		$cipher = substr( $raw, $iv_len );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? null : $plain;
	}

	/**
	 * Derive the 32-byte encryption key.
	 *
	 * Prefers the dedicated NERA_DCMS_ENCRYPTION_KEY constant; falls back to a WordPress salt
	 * so the plugin still functions out of the box.
	 *
	 * @return string
	 */
	private static function key() {
		$secret = ( defined( 'NERA_DCMS_ENCRYPTION_KEY' ) && '' !== NERA_DCMS_ENCRYPTION_KEY )
			? NERA_DCMS_ENCRYPTION_KEY
			: wp_salt( 'secure_auth' );

		return hash( 'sha256', $secret, true );
	}
}
