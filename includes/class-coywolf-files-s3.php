<?php
/**
 * Minimal S3-compatible client with AWS Signature V4 signing.
 *
 * Backblaze B2, Cloudflare R2, and Amazon S3 all speak the S3 API, so a single
 * client — with a per-provider endpoint host and signing region — covers all
 * three. Requests go through the WordPress HTTP API; there is no bundled SDK.
 *
 * Two signing modes:
 *   • header-signed requests for server-side operations (create/complete/abort
 *     multipart upload, delete, head-bucket, list), and
 *   • query-signed (presigned) URLs the browser uses directly to upload parts
 *     and to download files.
 *
 * Only the headers this client sets are signed; it never emits the flexible
 * checksum headers (x-amz-checksum-*) that Cloudflare R2 and Backblaze B2 reject.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * S3-compatible request signer / client.
 */
class Coywolf_Files_S3 {

	/**
	 * SHA-256 of the empty string (payload hash for body-less requests).
	 */
	const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Access key ID.
	 *
	 * @var string
	 */
	private $access_key;

	/**
	 * Secret access key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * SigV4 region (S3: real region; R2: "auto"; B2: real region).
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Base endpoint host, without the bucket (e.g. s3.us-east-1.amazonaws.com).
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Bucket name.
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * Use path-style addressing (host/bucket/key) instead of virtual-hosted
	 * (bucket.host/key).
	 *
	 * @var bool
	 */
	private $path_style;

	/**
	 * Constructor.
	 *
	 * @param array $config { access_key, secret_key, region, host, bucket, path_style }.
	 */
	public function __construct( array $config ) {
		$this->access_key = isset( $config['access_key'] ) ? (string) $config['access_key'] : '';
		$this->secret_key = isset( $config['secret_key'] ) ? (string) $config['secret_key'] : '';
		$this->region     = isset( $config['region'] ) ? (string) $config['region'] : 'auto';
		$this->host       = isset( $config['host'] ) ? (string) $config['host'] : '';
		$this->bucket     = isset( $config['bucket'] ) ? (string) $config['bucket'] : '';
		$this->path_style = ! empty( $config['path_style'] );
	}

	/*
	 * Public operations.
	 */

	/**
	 * HEAD the bucket to confirm the credentials and bucket resolve.
	 *
	 * @param int $timeout Request timeout in seconds.
	 * @return true|WP_Error
	 */
	public function head_bucket( $timeout = 30 ) {
		$res = $this->request( 'HEAD', '', array( 'timeout' => (int) $timeout ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return true;
	}

	/**
	 * HEAD an object; returns its size and content type, or an error.
	 *
	 * @param string $key Object key.
	 * @return array|WP_Error { size, content_type }.
	 */
	public function head_object( $key ) {
		$res = $this->request( 'HEAD', $key, array() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$headers = $res['headers'];
		$length  = is_object( $headers ) ? $headers->offsetGet( 'content-length' ) : ( isset( $headers['content-length'] ) ? $headers['content-length'] : '' );
		$type    = is_object( $headers ) ? $headers->offsetGet( 'content-type' ) : ( isset( $headers['content-type'] ) ? $headers['content-type'] : '' );
		return array(
			'size'         => (int) $length,
			'content_type' => (string) $type,
		);
	}

	/**
	 * Delete an object.
	 *
	 * @param string $key Object key.
	 * @return true|WP_Error
	 */
	public function delete_object( $key ) {
		$res = $this->request( 'DELETE', $key, array() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return true;
	}

	/**
	 * Start a multipart upload.
	 *
	 * @param string $key          Object key.
	 * @param string $content_type MIME type.
	 * @return string|WP_Error Upload id.
	 */
	public function create_multipart_upload( $key, $content_type ) {
		$headers = array();
		if ( '' !== (string) $content_type ) {
			$headers['content-type'] = (string) $content_type;
		}
		$res = $this->request(
			'POST',
			$key,
			array(
				'query'   => array( 'uploads' => '' ),
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( preg_match( '#<UploadId>([^<]+)</UploadId>#', $res['body'], $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}
		return new WP_Error( 'coywolf_files_multipart', __( 'The storage provider did not return an upload id.', 'coywolf-files' ) );
	}

	/**
	 * Complete a multipart upload.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload id.
	 * @param array  $parts     [ [ 'PartNumber' => int, 'ETag' => string ], … ] in order.
	 * @return true|WP_Error
	 */
	public function complete_multipart_upload( $key, $upload_id, $parts ) {
		$xml = '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		foreach ( $parts as $part ) {
			$num  = isset( $part['PartNumber'] ) ? (int) $part['PartNumber'] : 0;
			$etag = isset( $part['ETag'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $part['ETag'] ) : '';
			if ( $num < 1 || '' === $etag ) {
				continue;
			}
			$xml .= '<Part><PartNumber>' . $num . '</PartNumber><ETag>&quot;' . $etag . '&quot;</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$res = $this->request(
			'POST',
			$key,
			array(
				'query'   => array( 'uploadId' => (string) $upload_id ),
				'headers' => array( 'content-type' => 'application/xml' ),
				'body'    => $xml,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// S3 can return HTTP 200 with an <Error> body on a late failure.
		if ( false !== strpos( $res['body'], '<Error>' ) ) {
			return $this->error_from_body( $res['body'], 200 );
		}
		return true;
	}

	/**
	 * Abort a multipart upload (best effort cleanup).
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload id.
	 * @return true|WP_Error
	 */
	public function abort_multipart_upload( $key, $upload_id ) {
		$res = $this->request( 'DELETE', $key, array( 'query' => array( 'uploadId' => (string) $upload_id ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return true;
	}

	/**
	 * A presigned PUT URL for a single-request upload.
	 *
	 * @param string $key     Object key.
	 * @param int    $expires Seconds.
	 * @return string
	 */
	public function presign_put( $key, $expires = 3600 ) {
		return $this->presign( 'PUT', $key, array(), $expires );
	}

	/**
	 * A presigned URL for uploading one part of a multipart upload.
	 *
	 * @param string $key         Object key.
	 * @param string $upload_id   Upload id.
	 * @param int    $part_number Part number (1-based).
	 * @param int    $expires     Seconds.
	 * @return string
	 */
	public function presign_upload_part( $key, $upload_id, $part_number, $expires = 3600 ) {
		return $this->presign(
			'PUT',
			$key,
			array(
				'partNumber' => (string) (int) $part_number,
				'uploadId'   => (string) $upload_id,
			),
			$expires
		);
	}

	/**
	 * A presigned GET URL for downloading an object, with response headers baked
	 * in so the storage host returns the right filename and disposition.
	 *
	 * @param string $key          Object key.
	 * @param int    $expires      Seconds.
	 * @param string $disposition  Content-Disposition value (e.g. attachment; filename="…").
	 * @param string $content_type Content-Type to force.
	 * @return string
	 */
	public function presign_get( $key, $expires = 300, $disposition = '', $content_type = '' ) {
		$query = array();
		if ( '' !== (string) $disposition ) {
			$query['response-content-disposition'] = (string) $disposition;
		}
		if ( '' !== (string) $content_type ) {
			$query['response-content-type'] = (string) $content_type;
		}
		return $this->presign( 'GET', $key, $query, $expires );
	}

	/*
	 * Signing core.
	 */

	/**
	 * Perform a header-signed request.
	 *
	 * @param string $method HTTP method.
	 * @param string $key    Object key ('' for bucket-level).
	 * @param array  $args   { query, headers, body, timeout }.
	 * @return array|WP_Error { code, headers, body } or error.
	 */
	private function request( $method, $key, $args ) {
		if ( '' === $this->access_key || '' === $this->secret_key || '' === $this->host || '' === $this->bucket ) {
			return new WP_Error( 'coywolf_files_unconfigured', __( 'Object storage is not fully configured.', 'coywolf-files' ) );
		}

		$query   = isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array();
		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $this->lower_keys( $args['headers'] ) : array();
		$body    = isset( $args['body'] ) ? (string) $args['body'] : '';
		$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 30;

		$host          = $this->request_host();
		$canonical_uri = $this->canonical_uri( $key );
		$amzdate       = gmdate( 'Ymd\THis\Z' );
		$datestamp     = gmdate( 'Ymd' );
		$payload_hash  = '' === $body ? self::EMPTY_HASH : hash( 'sha256', $body );

		$headers['host']                 = $host;
		$headers['x-amz-content-sha256'] = $payload_hash;
		$headers['x-amz-date']           = $amzdate;

		ksort( $headers );
		$canonical_headers = '';
		$signed_headers    = array();
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( preg_replace( '/\s+/', ' ', (string) $value ) ) . "\n";
			$signed_headers[]   = $name;
		}
		$signed_headers_str = implode( ';', $signed_headers );

		$canonical_query = $this->canonical_query( $query );

		$canonical_request = $method . "\n" . $canonical_uri . "\n" . $canonical_query . "\n" . $canonical_headers . "\n" . $signed_headers_str . "\n" . $payload_hash;

		$scope          = $datestamp . '/' . $this->region . '/s3/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
		$signature      = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $datestamp ) );

		$authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $scope
			. ', SignedHeaders=' . $signed_headers_str
			. ', Signature=' . $signature;

		$request_headers = array();
		foreach ( $headers as $name => $value ) {
			if ( 'host' === $name ) {
				continue; // WP sets Host from the URL.
			}
			$request_headers[ $name ] = $value;
		}
		$request_headers['Authorization'] = $authorization;

		$url = 'https://' . $host . $canonical_uri;
		if ( '' !== $canonical_query ) {
			$url .= '?' . $canonical_query;
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $request_headers,
				'body'        => '' === $body ? null : $body,
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$out  = array(
			'code'    => $code,
			'headers' => wp_remote_retrieve_headers( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
		if ( $code >= 400 ) {
			return $this->error_from_body( $out['body'], $code );
		}
		return $out;
	}

	/**
	 * Build a presigned URL (query-signed).
	 *
	 * @param string $method HTTP method.
	 * @param string $key    Object key.
	 * @param array  $query  Extra query params (e.g. partNumber, response-*).
	 * @param int    $expires Seconds until expiry (clamped 1..604800).
	 * @return string
	 */
	private function presign( $method, $key, $query, $expires ) {
		if ( '' === $this->access_key || '' === $this->secret_key || '' === $this->host || '' === $this->bucket ) {
			return '';
		}
		$expires = max( 1, min( 604800, (int) $expires ) );

		$host          = $this->request_host();
		$canonical_uri = $this->canonical_uri( $key );
		$amzdate       = gmdate( 'Ymd\THis\Z' );
		$datestamp     = gmdate( 'Ymd' );
		$scope         = $datestamp . '/' . $this->region . '/s3/aws4_request';

		$params = array_merge(
			$query,
			array(
				'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
				'X-Amz-Credential'    => $this->access_key . '/' . $scope,
				'X-Amz-Date'          => $amzdate,
				'X-Amz-Expires'       => (string) $expires,
				'X-Amz-SignedHeaders' => 'host',
			)
		);

		$canonical_query = $this->canonical_query( $params );
		// Only the host header is signed for presigned URLs, and the payload is
		// unsigned (the browser supplies the body).
		$canonical_request = $method . "\n" . $canonical_uri . "\n" . $canonical_query . "\nhost:" . $host . "\n\nhost\nUNSIGNED-PAYLOAD";

		$string_to_sign = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
		$signature      = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $datestamp ) );

		return 'https://' . $host . $canonical_uri . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * Derive the SigV4 signing key.
	 *
	 * @param string $datestamp YYYYMMDD.
	 * @return string Raw binary key.
	 */
	private function signing_key( $datestamp ) {
		$k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/*
	 * Helpers.
	 */

	/**
	 * The host the request is sent to (virtual-hosted or path-style).
	 *
	 * @return string
	 */
	private function request_host() {
		return $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
	}

	/**
	 * The canonical (percent-encoded) request path.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	private function canonical_uri( $key ) {
		$key = ltrim( (string) $key, '/' );
		if ( '' === $key ) {
			$path = $this->path_style ? '/' . $this->bucket : '/';
		} else {
			$path = $this->path_style ? '/' . $this->bucket . '/' . $key : '/' . $key;
		}
		$segments = explode( '/', $path );
		$encoded  = array_map( array( $this, 'uri_encode' ), $segments );
		$uri      = implode( '/', $encoded );
		return '' === $uri ? '/' : $uri;
	}

	/**
	 * Canonical query string: params sorted by key, key and value percent-encoded.
	 *
	 * @param array $query Params.
	 * @return string
	 */
	private function canonical_query( $query ) {
		if ( empty( $query ) ) {
			return '';
		}
		$pairs = array();
		foreach ( $query as $key => $value ) {
			$pairs[ $this->uri_encode( (string) $key ) ] = $this->uri_encode( (string) $value );
		}
		ksort( $pairs );
		$parts = array();
		foreach ( $pairs as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}
		return implode( '&', $parts );
	}

	/**
	 * RFC 3986 percent-encoding (unreserved chars left intact). Matches AWS's
	 * expected encoding; the '/' in a path is preserved by the caller splitting
	 * on it first.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function uri_encode( $value ) {
		return str_replace( array( '+', '%7E' ), array( '%20', '~' ), rawurlencode( (string) $value ) );
	}

	/**
	 * Lowercase all array keys.
	 *
	 * @param array $arr Array.
	 * @return array
	 */
	private function lower_keys( $arr ) {
		$out = array();
		foreach ( $arr as $key => $value ) {
			$out[ strtolower( (string) $key ) ] = $value;
		}
		return $out;
	}

	/**
	 * Turn an S3 error response (or transport failure) into a WP_Error.
	 *
	 * @param string $body XML body.
	 * @param int    $code HTTP status.
	 * @return WP_Error
	 */
	private function error_from_body( $body, $code ) {
		$s3code = '';
		$msg    = '';
		if ( preg_match( '#<Code>([^<]+)</Code>#', (string) $body, $m ) ) {
			$s3code = $m[1];
		}
		if ( preg_match( '#<Message>([^<]+)</Message>#', (string) $body, $m ) ) {
			$msg = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}
		if ( '' === $msg ) {
			$msg = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The storage provider returned HTTP %d.', 'coywolf-files' ),
				(int) $code
			);
		}
		if ( '' !== $s3code ) {
			$msg .= ' (' . $s3code . ')';
		}
		return new WP_Error( 'coywolf_files_s3_error', $msg, array( 'status' => (int) $code ) );
	}
}
