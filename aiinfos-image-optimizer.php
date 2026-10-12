<?php
/**
 * Plugin Name:       AI Infos Image Optimizer
 * Plugin URI:        https://iqbalmahmud.com
 * Description:       Automatically compresses every image anyone uploads, without losing visible detail. Resizes oversized photos, makes WebP versions, strips private GPS data, keeps backups, and fills in missing alt text, titles, captions and descriptions from the posts that use each image. Optional AI alt text.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Md. Iqbal Mahmud
 * Author URI:        https://iqbalmahmud.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aiinfos-image-optimizer
 *
 * @package AIInfos\ImageOptimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'AIIO_VERSION', '1.0.0' );
define( 'AIIO_FILE', __FILE__ );
define( 'AIIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIIO_URL', plugin_dir_url( __FILE__ ) );

require_once AIIO_DIR . 'includes/settings.php';
require_once AIIO_DIR . 'includes/optimizer.php';
require_once AIIO_DIR . 'includes/metadata.php';
require_once AIIO_DIR . 'includes/ai.php';
require_once AIIO_DIR . 'includes/admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AIIO_DIR . 'includes/cli.php';
}
