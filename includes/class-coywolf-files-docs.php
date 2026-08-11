<?php
/**
 * Documentation screen for Coywolf Files.
 *
 * A static, self-contained help page — no remote calls — with per-provider,
 * step-by-step instructions for getting storage credentials and configuring the
 * one-time bucket CORS rule that browser-direct uploads need, plus how the block,
 * the All Files library, and download links work.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Documentation page.
 */
class Coywolf_Files_Docs {

	/**
	 * The site origin (scheme://host[:port]) to allow in a CORS rule.
	 *
	 * @return string
	 */
	public static function site_origin() {
		$parts  = wp_parse_url( home_url() );
		$scheme = ( is_array( $parts ) && ! empty( $parts['scheme'] ) ) ? $parts['scheme'] : 'https';
		$host   = ( is_array( $parts ) && ! empty( $parts['host'] ) ) ? $parts['host'] : '';
		$origin = $scheme . '://' . $host;
		if ( is_array( $parts ) && ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * The S3 / R2 CORS policy (JSON), with the site origin filled in.
	 *
	 * @return string
	 */
	public static function cors_json_s3() {
		$origin = self::site_origin();
		return "[\n"
			. "  {\n"
			. '    "AllowedOrigins": ["' . $origin . "\"],\n"
			. "    \"AllowedMethods\": [\"PUT\", \"GET\", \"HEAD\"],\n"
			. "    \"AllowedHeaders\": [\"*\"],\n"
			. "    \"ExposeHeaders\": [\"ETag\"],\n"
			. "    \"MaxAgeSeconds\": 3600\n"
			. "  }\n"
			. ']';
	}

	/**
	 * The Backblaze B2 native CORS rule (JSON), with the site origin filled in.
	 *
	 * @return string
	 */
	public static function cors_json_b2() {
		$origin = self::site_origin();
		return "[\n"
			. "  {\n"
			. "    \"corsRuleName\": \"coywolf-files\",\n"
			. '    "allowedOrigins": ["' . $origin . "\"],\n"
			. "    \"allowedOperations\": [\"s3_put\", \"s3_get\", \"s3_head\"],\n"
			. "    \"allowedHeaders\": [\"*\"],\n"
			. "    \"exposeHeaders\": [\"etag\"],\n"
			. "    \"maxAgeSeconds\": 3600\n"
			. "  }\n"
			. ']';
	}

	/**
	 * Render a preformatted code block.
	 *
	 * @param string $code Code.
	 */
	private static function code_block( $code ) {
		echo '<pre class="coywolf-files-code" style="white-space:pre-wrap;background:#f6f7f7;padding:1em;border:1px solid #dcdcde;border-radius:4px;max-width:640px;overflow:auto;"><code>' . esc_html( $code ) . '</code></pre>';
	}

	/**
	 * Render an ordered list of already-escaped step strings.
	 *
	 * @param string[] $steps Steps (each will be run through esc_html).
	 */
	private static function steps( $steps ) {
		echo '<ol style="margin-left:1.5em;">';
		foreach ( $steps as $step ) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Render the Documentation page.
	 */
	public static function render_page() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap coywolf-files-docs">';
		echo '<h1>' . esc_html__( 'Coywolf Files — Documentation', 'coywolf-files' ) . '</h1>';
		echo '<p>' . esc_html__( 'Coywolf Files stores your files in your own object storage — Backblaze B2, Cloudflare R2, or Amazon S3 — and lets you add them to posts and pages with the Files block. Setup is two one-time steps per bucket: get your keys, and add a CORS rule. Both are covered below for each provider.', 'coywolf-files' ) . '</p>';

		/* ---- 1. Credentials ------------------------------------------------ */
		echo '<h2>' . esc_html__( '1. Get your storage credentials', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'You need an access key ID, a secret access key, a bucket name, and either a region (B2 / S3) or your Cloudflare account ID (R2). Everything is done in your provider’s console — nothing here.', 'coywolf-files' ) . '</p>';

		echo '<h3>' . esc_html__( 'Backblaze B2', 'coywolf-files' ) . '</h3>';
		self::steps(
			array(
				__( 'Sign in at backblaze.com and open B2 Cloud Storage → Buckets. Create a bucket (keep it Private) and note its name.', 'coywolf-files' ),
				__( 'On the bucket, find its Endpoint, e.g. s3.us-west-004.backblazeb2.com — the middle part (us-west-004) is your Region.', 'coywolf-files' ),
				__( 'Open App Keys → Add a New Application Key, scope it to that bucket with Read and Write access, and create it.', 'coywolf-files' ),
				__( 'Copy the keyID (this is your Access key ID) and the applicationKey (your Secret access key) — the secret is shown only once.', 'coywolf-files' ),
			)
		);

		echo '<h3>' . esc_html__( 'Cloudflare R2', 'coywolf-files' ) . '</h3>';
		self::steps(
			array(
				__( 'In the Cloudflare dashboard, open R2 and create a bucket; note its name.', 'coywolf-files' ),
				__( 'Open R2 → Manage R2 API Tokens → Create API token, give it Object Read & Write (scoped to your bucket), and create it.', 'coywolf-files' ),
				__( 'Copy the Access Key ID and Secret Access Key it shows.', 'coywolf-files' ),
				__( 'Copy your Cloudflare Account ID (32 hex characters) from the R2 overview page or the dashboard URL.', 'coywolf-files' ),
			)
		);

		echo '<h3>' . esc_html__( 'Amazon S3', 'coywolf-files' ) . '</h3>';
		self::steps(
			array(
				__( 'In the AWS Console, open S3 and create a bucket; note its name and Region (e.g. us-east-1).', 'coywolf-files' ),
				__( 'In IAM, create (or reuse) a user and attach a policy allowing s3:PutObject, s3:GetObject, s3:DeleteObject, and s3:ListBucket on that bucket.', 'coywolf-files' ),
				__( 'Create an access key for that user and copy the Access key ID and Secret access key.', 'coywolf-files' ),
			)
		);

		echo '<h2>' . esc_html__( '2. Connect the bucket', 'coywolf-files' ) . '</h2>';
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: settings page URL. */
				__( 'In <a href="%s">Files → Settings</a>, pick your provider, paste the access key ID, secret key, bucket, and region or account ID, then click Test connection. For better security, define the keys in wp-config.php instead of the database — the Settings screen shows the exact constants. Once connected, use Check CORS (next step) to confirm uploads will work.', 'coywolf-files' ),
				esc_url( admin_url( 'admin.php?page=' . Coywolf_Files_Settings::PAGE ) )
			)
		) . '</p>';

		/* ---- 3. CORS ------------------------------------------------------- */
		echo '<h2>' . esc_html__( '3. Add a bucket CORS rule (required for uploads)', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'Files upload straight from the browser to your bucket, so the bucket has to allow cross-origin uploads from this site. It’s a one-time, copy-paste setting. After you add it, click Check CORS on the Settings screen to confirm it’s right.', 'coywolf-files' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Important:', 'coywolf-files' ) . '</strong> ' . esc_html__( 'the rule must expose the ETag header. Without it, small files upload but large (multipart) files fail — that missing line is almost always the cause of “small files work, big files don’t”.', 'coywolf-files' ) . '</p>';

		echo '<h3>' . esc_html__( 'Amazon S3', 'coywolf-files' ) . '</h3>';
		self::steps(
			array(
				__( 'S3 → your bucket → Permissions tab.', 'coywolf-files' ),
				__( 'Scroll to Cross-origin resource sharing (CORS) → Edit.', 'coywolf-files' ),
				__( 'Paste the JSON below and Save changes.', 'coywolf-files' ),
			)
		);

		echo '<h3>' . esc_html__( 'Cloudflare R2', 'coywolf-files' ) . '</h3>';
		self::steps(
			array(
				__( 'R2 → your bucket → Settings tab.', 'coywolf-files' ),
				__( 'Find CORS Policy → Add CORS policy (or Edit).', 'coywolf-files' ),
				__( 'Paste the same JSON below and Save.', 'coywolf-files' ),
			)
		);

		echo '<p>' . esc_html__( 'S3 and Cloudflare R2 use this policy (your site’s address is already filled in):', 'coywolf-files' ) . '</p>';
		self::code_block( self::cors_json_s3() );

		echo '<h3>' . esc_html__( 'Backblaze B2', 'coywolf-files' ) . '</h3>';
		echo '<p>' . esc_html__( 'On the bucket, open CORS Rules. The quickest option is the preset “Share everything in this bucket with every origin” — that’s safe here, because the signed upload URL is the real access control, not CORS. For a rule scoped to just this site, use B2’s own format (set via the B2 CLI/API):', 'coywolf-files' ) . '</p>';
		self::code_block( self::cors_json_b2() );

		/* ---- 4–6. Usage ---------------------------------------------------- */
		echo '<h2>' . esc_html__( '4. Add a file to a post or page', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'In the editor, add the Files block. Upload a new file or browse to one you’ve already uploaded. Then use the block’s settings to rename it and add a description for this placement — like any other block, the name and description are per-block. Visitors see a download card they can download from or copy a link to.', 'coywolf-files' ) . '</p>';

		echo '<h2>' . esc_html__( '5. The All Files library', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'Files → All Files lists the files currently added to posts and pages, with per-file counts of how many posts and pages use each one (each count links to a filtered list). Deleting a file here removes it from storage and from every post or page that used it. Switch the filter to “All uploaded files” or “Unused” to manage files that aren’t placed anywhere.', 'coywolf-files' ) . '</p>';

		echo '<h2>' . esc_html__( '6. Download links', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each file has a stable download link on your own site. If your bucket is private (the default), the link redirects to a short-lived signed URL each time it’s used, so the link keeps working while the underlying signed URL rotates, and every download is forced as an attachment. If you serve files from a public bucket or CDN, enter its base URL under Settings → Advanced and links will point there instead.', 'coywolf-files' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Security note:', 'coywolf-files' ) . '</strong> ' . esc_html__( 'Files served from a public URL are opened by the browser using their stored content type, so an uploaded HTML or SVG file could run in the origin that serves it. Keep the private (signed-link) default, or host the public bucket on a separate domain from your WordPress site so a file can never execute in your site’s origin.', 'coywolf-files' ) . '</p>';

		echo '</div>';
	}
}
