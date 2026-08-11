<?php
/**
 * Object-storage module for Coywolf Files.
 *
 * Reads the connection settings, derives the per-provider endpoint host and
 * signing region (Cloudflare R2 or Amazon S3), and builds an
 * S3-compatible client. Higher-level operations sit here: planning a presigned
 * upload, completing a multipart upload, deleting an object, and presigning a
 * download URL.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage operations across R2 / S3.
 */
class Coywolf_Files_Storage {

	/**
	 * Object-key prefix inside the bucket.
	 */
	const KEY_PREFIX = 'coywolf-files';

	/**
	 * Files at or below this size upload in a single presigned PUT; larger files
	 * use a resumable multipart upload.
	 */
	const SINGLE_PUT_MAX = 33554432; // 32 MB.

	/**
	 * Base multipart part size (bytes). Scaled up for very large files so the
	 * part count stays within the S3 10,000-part limit.
	 */
	const PART_SIZE = 33554432; // 32 MB.

	/**
	 * SSRF guard: a Cloudflare R2 account id is 32 hex characters.
	 */
	const R2_ACCOUNT_RE = '/^[0-9a-f]{32}$/i';

	/**
	 * SSRF guard: a region slug (us-east-1, auto, …).
	 */
	const REGION_RE = '/^[a-z0-9-]{2,40}$/';

	/**
	 * Settings module.
	 *
	 * @var Coywolf_Files_Settings
	 */
	private $settings;

	/**
	 * Per-request memo of the resolved config (array or WP_Error), so a single
	 * request doesn't re-read options and re-decrypt the secret repeatedly.
	 *
	 * @var array|WP_Error|null
	 */
	private $config_cache = null;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_Files_Settings $settings Settings.
	 */
	public function __construct( Coywolf_Files_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Drop the per-request config memo (used after a settings save in the same
	 * request would be unusual, but keeps the memo honest if called).
	 */
	public function flush_config_cache() {
		$this->config_cache = null;
	}

	/**
	 * Whether storage is fully configured (provider chosen, credentials + bucket
	 * present, and the host-determining fields validate).
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! is_wp_error( $this->config() );
	}

	/**
	 * Resolve the active connection into a normalized config array.
	 *
	 * @return array|WP_Error { provider, host, region, bucket, access_key, secret_key, path_style, public_base }.
	 */
	public function config() {
		if ( null !== $this->config_cache ) {
			return $this->config_cache;
		}
		$this->config_cache = $this->resolve_config();
		return $this->config_cache;
	}

	/**
	 * Resolve the active connection into a normalized config array (uncached).
	 *
	 * @return array|WP_Error
	 */
	private function resolve_config() {
		$provider   = $this->settings->provider();
		$access_key = $this->settings->access_key();
		$secret_key = $this->settings->secret_key();
		$conn       = $this->settings->connection();
		$bucket     = isset( $conn['bucket'] ) ? (string) $conn['bucket'] : '';

		if ( ! in_array( $provider, array( 'r2', 's3' ), true ) ) {
			return new WP_Error( 'coywolf_files_no_provider', __( 'Choose a storage provider.', 'coywolf-files' ) );
		}
		if ( '' === $access_key || '' === $secret_key || '' === $bucket ) {
			return new WP_Error( 'coywolf_files_no_creds', __( 'Enter your access key, secret key, and bucket.', 'coywolf-files' ) );
		}

		$region = isset( $conn['region'] ) ? strtolower( trim( (string) $conn['region'] ) ) : '';
		$host   = '';

		if ( 'r2' === $provider ) {
			$account = isset( $conn['account_id'] ) ? strtolower( trim( (string) $conn['account_id'] ) ) : '';
			if ( ! preg_match( self::R2_ACCOUNT_RE, $account ) ) {
				return new WP_Error( 'coywolf_files_bad_account', __( 'Enter a valid Cloudflare account ID (32 hexadecimal characters).', 'coywolf-files' ) );
			}
			$host   = $account . '.r2.cloudflarestorage.com';
			$region = 'auto';
		} else { // s3.
			if ( ! preg_match( self::REGION_RE, $region ) ) {
				return new WP_Error( 'coywolf_files_bad_region', __( 'Enter a valid AWS region (for example, us-east-1).', 'coywolf-files' ) );
			}
			$host = 's3.' . $region . '.amazonaws.com';
		}

		// Optional custom endpoint override (for S3-compatible hosts / testing).
		// Only accepted when it is a real, public hostname — never an IP literal,
		// localhost, or a single-label/internal name — so it can't be used to
		// steer server-side requests at internal services or a cloud metadata IP.
		$endpoint = isset( $conn['endpoint'] ) ? trim( (string) $conn['endpoint'] ) : '';
		if ( '' !== $endpoint ) {
			$parsed = wp_parse_url( $endpoint );
			$eh     = is_array( $parsed ) && ! empty( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
			if ( '' === $eh || ! self::is_safe_endpoint_host( $eh ) ) {
				return new WP_Error( 'coywolf_files_bad_endpoint', __( 'The custom endpoint must be a public hostname (not an IP address, localhost, or an internal name).', 'coywolf-files' ) );
			}
			$host = $eh;
		}

		return array(
			'provider'    => $provider,
			'host'        => $host,
			'region'      => $region,
			'bucket'      => $bucket,
			'access_key'  => $access_key,
			'secret_key'  => $secret_key,
			'path_style'  => ! empty( $conn['path_style'] ),
			'public_base' => isset( $conn['public_base'] ) ? esc_url_raw( (string) $conn['public_base'] ) : '',
		);
	}

	/**
	 * Whether a custom-endpoint host is safe to send server-side requests to: a
	 * public, multi-label hostname. Rejects IP literals (which covers the cloud
	 * metadata IP 169.254.169.254 and private ranges), localhost, single-label
	 * names, and the .local / .internal / .localhost internal suffixes.
	 *
	 * @param string $host Lowercased host.
	 * @return bool
	 */
	private static function is_safe_endpoint_host( $host ) {
		if ( ! preg_match( '/^[a-z0-9.-]{1,253}$/', $host ) ) {
			return false;
		}
		// No IP literals (blocks 169.254.169.254, 127.0.0.1, 10/8, 192.168/16, …).
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( 'localhost' === $host ) {
			return false;
		}
		// Require at least one dot (reject single-label internal names).
		if ( false === strpos( $host, '.' ) ) {
			return false;
		}
		foreach ( array( '.local', '.internal', '.localhost', '.localdomain' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build an S3 client for the active connection, optionally overriding the
	 * bucket (so a stored file can be reached even if the default bucket changed).
	 *
	 * @param string $bucket Optional bucket override.
	 * @return Coywolf_Files_S3|WP_Error
	 */
	public function client( $bucket = '' ) {
		$config = $this->config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		if ( '' !== $bucket ) {
			$config['bucket'] = $bucket;
		}
		return new Coywolf_Files_S3( $config );
	}

	/**
	 * Public base URL for a provider (empty when files are served via presigned
	 * URLs from a private bucket).
	 *
	 * @return string
	 */
	public function public_base() {
		$config = $this->config();
		return is_wp_error( $config ) ? '' : (string) $config['public_base'];
	}

	/**
	 * Test the connection by heading the bucket.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		// Short timeout: this can run during the Settings page render, so a hung
		// or firewalled host must not freeze the page for 30 seconds.
		return $client->head_bucket( 5 );
	}

	/*
	 * Uploads.
	 */

	/**
	 * Build the storage object key for a new upload.
	 *
	 * @param string $file_id  File id.
	 * @param string $filename Original filename.
	 * @return string
	 */
	public static function build_object_key( $file_id, $filename ) {
		$name = self::sanitize_filename( $filename );
		return self::KEY_PREFIX . '/' . gmdate( 'Y/m' ) . '/' . $file_id . '-' . $name;
	}

	/**
	 * Sanitize a filename for use in a storage key: keep word chars, dot, dash;
	 * collapse the rest; cap length while preserving the extension.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	public static function sanitize_filename( $filename ) {
		$filename = wp_basename( (string) $filename );
		$filename = preg_replace( '/[^\w.\-]+/', '-', $filename );
		$filename = trim( (string) $filename, '-.' );
		if ( '' === $filename ) {
			$filename = 'file';
		}
		if ( strlen( $filename ) > 180 ) {
			$ext      = pathinfo( $filename, PATHINFO_EXTENSION );
			$base     = substr( $filename, 0, 160 );
			$filename = '' !== $ext ? $base . '.' . $ext : $base;
		}
		return $filename;
	}

	/**
	 * Plan a multipart upload for a given size: [ part_size, part_count ].
	 *
	 * @param int $size Total bytes.
	 * @return array
	 */
	public static function plan_parts( $size ) {
		$size      = max( 1, (int) $size );
		$part_size = self::PART_SIZE;
		// Keep within the 10,000-part ceiling, rounding the part size up to 8 MB.
		if ( (int) ceil( $size / $part_size ) > 9500 ) {
			$needed    = (int) ceil( $size / 9500 );
			$eight_mb  = 8388608;
			$part_size = (int) ceil( $needed / $eight_mb ) * $eight_mb;
		}
		$count = (int) ceil( $size / $part_size );
		return array( $part_size, max( 1, $count ) );
	}

	/**
	 * Presign a single-request PUT upload.
	 *
	 * @param string $key Object key.
	 * @return string|WP_Error
	 */
	public function presign_put( $key ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->presign_put( $key, HOUR_IN_SECONDS );
	}

	/**
	 * Start a multipart upload.
	 *
	 * @param string $key  Object key.
	 * @param string $mime MIME type.
	 * @return string|WP_Error Upload id.
	 */
	public function create_multipart( $key, $mime ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->create_multipart_upload( $key, $mime );
	}

	/**
	 * Presign one multipart part URL.
	 *
	 * @param string $key         Object key.
	 * @param string $upload_id   Upload id.
	 * @param int    $part_number Part number.
	 * @return string|WP_Error
	 */
	public function presign_part( $key, $upload_id, $part_number ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->presign_upload_part( $key, $upload_id, $part_number, HOUR_IN_SECONDS );
	}

	/**
	 * Complete a multipart upload.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload id.
	 * @param array  $parts     Ordered [ { PartNumber, ETag }, … ].
	 * @return true|WP_Error
	 */
	public function complete_multipart( $key, $upload_id, $parts ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->complete_multipart_upload( $key, $upload_id, $parts );
	}

	/**
	 * Abort a multipart upload (best effort).
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload id.
	 * @return true|WP_Error
	 */
	public function abort_multipart( $key, $upload_id ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->abort_multipart_upload( $key, $upload_id );
	}

	/**
	 * HEAD an object (used to confirm a completed upload and read its true size).
	 *
	 * @param string $key Object key.
	 * @return array|WP_Error
	 */
	public function head_object( $key ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->head_object( $key );
	}

	/*
	 * Delete + download.
	 */

	/**
	 * Delete an object (using the bucket it was stored in).
	 *
	 * @param string $key      Object key.
	 * @param string $provider Provider it was stored under (informational).
	 * @param string $bucket   Bucket it was stored in.
	 * @return true|WP_Error
	 */
	public function delete_object( $key, $provider = '', $bucket = '' ) {
		unset( $provider );
		$client = $this->client( $bucket );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->delete_object( $key );
	}

	/**
	 * Resolve a download URL for a stored file: a presigned GET (private bucket)
	 * or a public URL when a public base is configured.
	 *
	 * @param array $record      Registry record.
	 * @param bool  $attachment  Force a download (attachment) disposition.
	 * @return string|WP_Error
	 */
	public function download_url( $record, $attachment = true ) {
		$key    = isset( $record['object_key'] ) ? (string) $record['object_key'] : '';
		$bucket = isset( $record['bucket'] ) ? (string) $record['bucket'] : '';
		$name   = isset( $record['filename'] ) ? (string) $record['filename'] : 'download';
		$mime   = isset( $record['mime'] ) ? (string) $record['mime'] : '';
		if ( '' === $key ) {
			return new WP_Error( 'coywolf_files_no_key', __( 'This file has no storage key.', 'coywolf-files' ) );
		}

		$public = $this->public_base();
		if ( '' !== $public ) {
			return trailingslashit( $public ) . str_replace( '%2F', '/', rawurlencode( $key ) );
		}

		$client = $this->client( $bucket );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$disposition = ( $attachment ? 'attachment' : 'inline' ) . '; filename="' . self::sanitize_filename( $name ) . '"';
		return $client->presign_get( $key, 5 * MINUTE_IN_SECONDS, $disposition, $mime );
	}
}
