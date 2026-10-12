<?php
/**
 * Uninstall: remove settings. Backups of original images are left in place
 * (uploads/aiio-backups-*) so deleting the plugin can never lose a photo;
 * delete that folder by hand if you no longer need it.
 *
 * @package AIInfos\ImageOptimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'aiio_settings' );
