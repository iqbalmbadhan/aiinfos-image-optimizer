# AI Infos Image Optimizer

A WordPress plugin that compresses every image anyone uploads, without visible quality loss, and fills in missing **alt text, titles, captions and descriptions** from the posts that use each image. It needs no external service, though Anthropic Claude can optionally write image descriptions.

## What it does

| | |
|---|---|
| **Automatic** | Every upload is processed: Media Library, block editor, REST API, apps, WP-CLI, by any user allowed to upload |
| **Compression** | Visually lossless by default (JPEG 82, WebP 80). Three levels |
| **Resize** | Photos larger than 2560px are scaled down; the untouched original is kept |
| **WebP** | Thumbnails and in-post sizes are saved as WebP, and WordPress serves them automatically |
| **Privacy** | GPS and camera data are removed from public files (colour profile kept). IPTC title, caption, credit and copyright are saved to the Media Library first |
| **Safe** | A file is only replaced if it's really smaller. Originals are backed up to a private folder, with one-click restore. Bulk mode recompresses in place, so **no image URL changes** |
| **SEO filenames** | `IMG_4821 (1).JPG` becomes `your-post-title.jpg` when uploaded into a post |
| **Image details** | Missing alt, title, caption and description are filled from the photo's own data, the post it's uploaded to, set as featured image in, or embedded in, and the filename. **Never overwrites text a person typed** |
| **Front end** | No `<img>` ships with an empty alt |
| **AI (optional)** | Claude describes new uploads in the background (your Anthropic API key) |

### Where image details come from

When a field is empty, the plugin fills it from the best source available. A stronger source can later replace a weaker automatic value, but text a person typed is never replaced. From weakest to strongest:

filename → the post using the image → AI description → the photographer's IPTC data → a person

## Install

1. Upload `aiinfos-image-optimizer.zip` under Plugins › Add New › Upload, then activate it.
2. Go to **Media › Image Optimizer**. The defaults are ready to use.
3. For images already in your library, click **Optimize library**, then **Fill missing details**.

For optional AI descriptions, add this to `wp-config.php`:
```php
define( 'AIIO_ANTHROPIC_API_KEY', 'sk-ant-...' );
```
Then tick **Describe new uploads with AI**.

## WP-CLI

```bash
wp image-optimizer bulk [--limit=<n>]      # optimize unprocessed images
wp image-optimizer fill-alt                # fill missing alt/title/description
wp image-optimizer describe [<id>...]      # AI descriptions (needs API key)
wp image-optimizer restore <id>... | --all # put originals back
wp image-optimizer stats
```

## Tested

Tested on WordPress 7.1.3 with PHP 8.3 and GD:

| Upload | Before | After |
|---|---|---|
| 4000px camera JPEG with GPS (uploaded by an author, into a post) | 2.0 MB | 199 KB (WebP, 2560px) |
| 1600px photo with GPS | 439 KB | 122 KB |
| PNG screenshot | 159 KB | 11 KB |

In each case GPS was removed and the details were filled from the post. A bulk run over existing images saved 2.8 MB across 23 images without changing any URL. AI descriptions were tested against a mocked API reply, not a live key.

## Notes

- Contributors can't upload files in WordPress by default. That's a core permission, not this plugin.
- Backups live in `uploads/aiio-backups-<random>/`. The random name keeps them private on Nginx, where `.htaccess` rules don't apply. Uninstalling keeps the backups.
- Hooks: `aiio_quality` (filter quality per format) and `aiio_optimized` (action after each image).

---
Built by Md. Iqbal Mahmud · [iqbalmahmud.com](https://iqbalmahmud.com)
