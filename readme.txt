=== AI Infos Image Optimizer ===
Contributors: iqbalmahmud
Tags: image optimization, compress images, webp, alt text, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Compresses every upload automatically, makes WebP versions, removes GPS data, and fills missing alt text, titles, captions and descriptions from the posts that use each image.

== Description ==

* Works for every upload: Media Library, block editor, REST API, mobile apps and WP-CLI, for any user who can upload.
* Visually lossless by default (JPEG 82 / WebP 80), with Maximum-detail and Smallest-file levels.
* Scales oversized photos (default 2560px) and keeps the untouched original.
* WebP thumbnails and in-post sizes, served by WordPress automatically.
* Removes GPS and camera data from public files, keeps the colour profile. Title, caption, credit and copyright are saved to the Media Library first.
* Keeps a file only if it's really smaller. Backs up originals to a private folder, with one-click restore.
* SEO filenames: "IMG_4821 (1).JPG" becomes "your-post-title.jpg".
* Fills missing alt, title, caption and description from the photo's IPTC data, the post it's uploaded to, featured in or embedded in, and the filename. Never overwrites text a person typed.
* No empty alt on the front end.
* Optional: AI descriptions with Anthropic Claude (your API key), in the background.
* Bulk tools with live progress, Media Library column, and WP-CLI: `wp image-optimizer bulk|fill-alt|describe|restore|stats`.
* No external service required. Uses the server's Imagick or GD.

== Changelog ==

= 1.0.0 =
* First release.
