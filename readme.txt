=== Coywolf Files ===
Contributors: jonhenshaw
Tags: file, download, amazon s3, backblaze b2, cloudflare r2
Requires at least: 6.3
Tested up to: 7.0
Stable tag: 1.0.3
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload any-sized file to Backblaze B2, Cloudflare R2, or Amazon S3 and embed it with a Files block visitors can download or copy a link to.

== Description ==

Coywolf Files stores your downloadable files in your own object storage — Backblaze B2, Cloudflare R2, or Amazon S3 — instead of the WordPress media library, and gives you a Files block to add them to posts and pages. Files upload straight from the browser to your bucket, so there is no upload-size limit from PHP.

Features:

* Files block — upload a new file or browse to one you have already uploaded, then rename it and add a description for that placement, just like any other block. Per-block toggles control the file-type icon, description, meta line, and the Download / Copy-link buttons.
* Any-sized uploads — files upload directly from the browser to your bucket using presigned URLs, with large files sent in resumable chunks. Nothing passes through PHP, so the maximum size is your bucket's, not your host's.
* Download card — a clean card with a colored file-type badge, the file name, a "type · size · uploaded date" line, and Download and Copy-link controls, with light and dark themes.
* Three providers, one connection — Backblaze B2, Cloudflare R2, and Amazon S3 all speak the S3 API, so you connect one bucket with an access key, secret key, bucket name, and region (or Cloudflare account ID).
* Private or public buckets — keep your bucket private (the default) and files are served through short-lived signed links behind a stable URL on your own site, or point downloads at a public bucket / CDN URL.
* All Files library — a table of the files currently added to posts and pages, with per-file counts of how many posts and pages use each one (each count links to a filtered list) and a download counter. Deleting a file here removes it from storage and from every post or page that used it.
* Secure credentials — the secret key is stored encrypted, or kept entirely out of the database in wp-config.php.

<!-- wporg-strip:start -->
* GitHub self-updater — updates delivered straight from the project's GitHub releases.
<!-- wporg-strip:end -->

== Installation ==

1. Upload the plugin to wp-content/plugins/coywolf-files or install the zip from Plugins > Add New > Upload Plugin.
2. Activate it.
3. Go to Files > Settings and connect your Backblaze B2, Cloudflare R2, or Amazon S3 bucket (access key, secret key, bucket, and region or Cloudflare account ID), then click Test connection.
4. Add a CORS rule to your bucket so the browser can upload to it — the exact policy is on the Files > Documentation screen.
5. Add the Files block to a post or page and upload or select a file.

== Frequently Asked Questions ==

= Where are my files stored? =

In your own object-storage bucket — Backblaze B2, Cloudflare R2, or Amazon S3. WordPress stores only a small record of each file (name, size, type, and which posts and pages use it) and your settings. Files are not copied into the WordPress media library.

= Is there an upload size limit? =

The plugin itself imposes no size limit. Files upload directly from the browser to your bucket, so PHP's upload_max_filesize and memory limits do not apply — the limit is whatever your storage provider allows.

= Why do I need to set a CORS rule on my bucket? =

Because the browser uploads directly to your bucket, the bucket has to allow cross-origin uploads from your site. It is a one-time setting; the Documentation screen gives you the exact policy to paste in.

= Can visitors see my storage credentials? =

No. The secret key never reaches the browser. Uploads use short-lived presigned URLs, and downloads go through a link on your own site that redirects to a short-lived signed URL (or your public bucket URL, if you configure one).

= What happens when I delete a file? =

Deleting a file from the All Files screen removes the object from your bucket and strips its block from every post or page that used it. Deleting the plugin does not delete your files from the bucket.

== External services ==

This plugin connects to the object-storage provider you configure — one of Backblaze B2, Cloudflare R2, or Amazon S3 — to store and serve your files. Nothing is sent anywhere until you enter credentials and connect a bucket.

What is sent and when:

* When you connect a bucket or click Test connection, your WordPress server calls the provider's S3 API (for example s3.amazonaws.com, <account>.r2.cloudflarestorage.com, or s3.<region>.backblazeb2.com) using your access key and secret key to verify the bucket, to start and finish uploads, to confirm and delete objects, and to sign download links.
* When you upload a file, your browser uploads the file's bytes directly to your bucket's storage host using a short-lived signed URL that your server generated.
* When a visitor downloads a file, their browser is redirected to a short-lived signed URL (or your configured public URL) on the storage host to fetch the file.

The provider is the one you choose; consult its terms and privacy policy:

* Amazon S3 — Service Terms: https://aws.amazon.com/service-terms/ · Privacy Notice: https://aws.amazon.com/privacy/
* Cloudflare R2 — Terms: https://www.cloudflare.com/website-terms/ · Privacy Policy: https://www.cloudflare.com/privacypolicy/
* Backblaze B2 — Terms: https://www.backblaze.com/company/terms.html · Privacy Policy: https://www.backblaze.com/company/privacy.html

== Screenshots ==

1. The All Files library, listing files added to posts and pages with per-file post and page counts and a download count.
2. Connecting a Backblaze B2, Cloudflare R2, or Amazon S3 bucket on the Settings screen.
3. Uploading a file directly to the bucket from the Upload File screen.
4. The Files block in the editor, with the file details and display options.
5. A published download card with the file-type badge, meta line, and Download / Copy-link buttons, in light and dark themes.

== Changelog ==

= 1.0.3 =
* Settings: hide the access key, secret key, bucket, storage location, advanced, and connection fields until a provider is selected, so the form reveals itself step by step.

= 1.0.2 =
* Settings: show only the storage-location field relevant to the selected provider — Region for Backblaze B2 and Amazon S3, Cloudflare account ID for Cloudflare R2.

= 1.0.1 =
* Add a Check CORS button to Settings that runs a real test upload from the browser and reports exactly what to fix (including a missing ExposeHeaders: ETag).
* Expand Documentation with per-provider, step-by-step instructions for getting credentials and configuring the bucket CORS rule (Backblaze B2, Cloudflare R2, Amazon S3).

= 1.0.0 =
* Initial release: the Files block, direct-to-storage uploads for Backblaze B2 / Cloudflare R2 / Amazon S3, the download card with light and dark themes, and the All Files library.
