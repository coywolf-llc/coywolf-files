<?php
/**
 * Secret encryption for Coywolf Files.
 *
 * The object-storage secret access key can be stored in the database (when it
 * is not supplied via a wp-config constant or environment variable). Storing it
 * in plaintext would expose it in a database leak, so it is encrypted at rest
 * with AES-256-GCM. The key is derived from a dedicated wp-config constant when
 * defined, else from the site's authentication salts — so the ciphertext is
 * useless without the site's wp-config.php.
 *
 * Stored form is a self-describing string: "v1:<iv b64>:<tag b64>:<ct b64>".
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-GCM helper for at-rest secret storage.
 */
class Coywolf_Files_Crypto {

	/**
	 * Whether the runtime can encrypt (OpenSSL with GCM support). Cached per
	 * request — scanning the cipher list on every decrypt is wasteful.
	 *
	 * @var bool|null
	 */
	private static $available_cache = null;

	/**
	 * Whether the runtime can encrypt (OpenSSL with GCM support).
	 *
	 * @return bool
	 */
	public static function available() {
		if ( null === self::$available_cache ) {
			self::$available_cache = function_exists( 'openssl_encrypt' )
				&& in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
		}
		return self::$available_cache;
	}

	/**
	 * Derive the 32-byte encryption key.
	 *
	 * @return string
	 */
	private static function key() {
		if ( defined( 'COYWOLF_FILES_ENCRYPTION_KEY' ) && '' !== (string) COYWOLF_FILES_ENCRYPTION_KEY ) {
			$material = (string) COYWOLF_FILES_ENCRYPTION_KEY;
		} else {
			$material = '';
			foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT' ) as $const ) {
				if ( defined( $const ) ) {
					$material .= (string) constant( $const );
				}
			}
			if ( '' === $material ) {
				// Last resort so encryption never silently no-ops on a mis-salted
				// install; still site-specific.
				$material = wp_salt( 'auth' );
			}
		}
		return hash( 'sha256', 'coywolf-files|' . $material, true );
	}

	/**
	 * Encrypt a secret. Returns the plaintext unchanged (marked) when encryption
	 * is unavailable, so callers always get a storable string.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext || ! self::available() ) {
			return $plaintext;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ct ) {
			return $plaintext;
		}
		return 'v1:' . base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary envelope, not obfuscation.
	}

	/**
	 * Decrypt a stored secret. A value that is not in the "v1:" envelope is
	 * returned as-is (it was stored before encryption was available, or supplied
	 * verbatim), so upgrades don't strand credentials.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, 'v1:' ) ) {
			return $stored;
		}
		$parts = explode( ':', $stored, 4 );
		if ( 4 !== count( $parts ) || ! self::available() ) {
			return '';
		}
		$iv  = base64_decode( $parts[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary envelope.
		$tag = base64_decode( $parts[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary envelope.
		$ct  = base64_decode( $parts[3], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary envelope.
		if ( false === $iv || false === $tag || false === $ct ) {
			return '';
		}
		$plain = openssl_decrypt( $ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}
}
