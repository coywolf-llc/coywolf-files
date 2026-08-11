<?php
/**
 * Documentation screen for Coywolf Files.
 *
 * A static, self-contained help page — no remote calls — covering the storage
 * connection, the one-time bucket CORS rule that browser-direct uploads need,
 * using the block, and how download links work.
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
	 * Render the Documentation page.
	 */
	public static function render_page() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			return;
		}

		$origin = home_url();
		$cors   = "[\n"
			. "  {\n"
			. '    "AllowedOrigins": ["' . esc_js( $origin ) . "\"],\n"
			. "    \"AllowedMethods\": [\"PUT\", \"GET\", \"HEAD\"],\n"
			. "    \"AllowedHeaders\": [\"*\"],\n"
			. "    \"ExposeHeaders\": [\"ETag\"],\n"
			. "    \"MaxAgeSeconds\": 3600\n"
			. "  }\n"
			. ']';

		echo '<div class="wrap coywolf-files-docs">';
		echo '<h1>' . esc_html__( 'Coywolf Files — Documentation', 'coywolf-files' ) . '</h1>';

		echo '<h2>' . esc_html__( '1. Connect a storage bucket', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'In Files → Settings, choose your provider (Backblaze B2, Cloudflare R2, or Amazon S3), then enter an access key ID, a secret access key, the bucket name, and the region (B2 / S3) or Cloudflare account ID (R2). For better security, keep the keys in wp-config.php instead of the database — the Settings screen shows the exact constants. Click Test connection to confirm.', 'coywolf-files' ) . '</p>';

		echo '<h2>' . esc_html__( '2. Add a bucket CORS rule (required for uploads)', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'Files upload directly from the browser to your bucket, so the bucket must allow cross-origin uploads from this site. Add a CORS rule allowing PUT, GET, and HEAD from your site’s address and exposing the ETag header. Set it once, in your provider’s console:', 'coywolf-files' ) . '</p>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		echo '<li>' . esc_html__( 'Amazon S3: the bucket’s Permissions → Cross-origin resource sharing (CORS).', 'coywolf-files' ) . '</li>';
		echo '<li>' . esc_html__( 'Cloudflare R2: the bucket’s Settings → CORS Policy.', 'coywolf-files' ) . '</li>';
		echo '<li>' . esc_html__( 'Backblaze B2: the bucket’s CORS Rules (choose “Share everything in this bucket with every origin” or a custom rule with the same origin, methods, and ETag header).', 'coywolf-files' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__( 'S3 / R2 CORS policy (replace the origin if your site is served elsewhere):', 'coywolf-files' ) . '</p>';
		echo '<pre class="coywolf-files-code" style="white-space:pre-wrap;background:#f6f7f7;padding:1em;border:1px solid #dcdcde;border-radius:4px;"><code>' . esc_html( $cors ) . '</code></pre>';

		echo '<h2>' . esc_html__( '3. Add a file to a post or page', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'In the editor, add the Files block. Upload a new file or browse to one you’ve already uploaded. Then use the block’s settings to rename it and add a description for this placement — like any other block, the name and description are per-block. Visitors see a download card they can download from or copy a link to.', 'coywolf-files' ) . '</p>';

		echo '<h2>' . esc_html__( '4. The All Files library', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'Files → All Files lists the files currently added to posts and pages, with per-file counts of how many posts and pages use each one (each count links to a filtered list). Deleting a file here removes it from storage and from every post or page that used it. Switch the filter to “All uploaded files” or “Unused” to manage files that aren’t placed anywhere.', 'coywolf-files' ) . '</p>';

		echo '<h2>' . esc_html__( '5. Download links', 'coywolf-files' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each file has a stable download link on your own site. If your bucket is private (the default), the link redirects to a short-lived signed URL each time it’s used, so the link keeps working while the underlying signed URL rotates, and every download is forced as an attachment. If you serve files from a public bucket or CDN, enter its base URL under Settings → Advanced and links will point there instead.', 'coywolf-files' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Security note:', 'coywolf-files' ) . '</strong> ' . esc_html__( 'Files served from a public URL are opened by the browser using their stored content type, so an uploaded HTML or SVG file could run in the origin that serves it. Keep the private (signed-link) default, or host the public bucket on a separate domain from your WordPress site so a file can never execute in your site’s origin.', 'coywolf-files' ) . '</p>';

		echo '</div>';
	}
}
