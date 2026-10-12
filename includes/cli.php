<?php
/**
 * WP-CLI: wp image-optimizer <bulk|fill-alt|describe|restore|stats>
 *
 * @package AIInfos\ImageOptimizer
 */

namespace AIInfos\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Compress images and fill missing image details.
 */
class CLI {

	/**
	 * Optimize images that haven't been processed yet (safe: no URL changes).
	 *
	 * [--limit=<n>]
	 * : Stop after this many images.
	 *
	 * @param array $args Args.
	 * @param array $a    Flags.
	 */
	public function bulk( $args, $a ) {
		$limit = (int) ( $a['limit'] ?? 0 );
		$n     = 0;
		$saved = 0;
		while ( $ids = pending_ids( 10 ) ) {
			foreach ( $ids as $id ) {
				optimize_attachment( $id, [], true );
				if ( opt( 'fill_meta' ) ) {
					fill_existing( $id );
				}
				$r      = get_post_meta( $id, META, true );
				$diff   = max( 0, (int) ( $r['before'] ?? 0 ) - (int) ( $r['after'] ?? 0 ) );
				$saved += $diff;
				\WP_CLI::log( sprintf( '#%d %s %s', $id, $r['status'] ?? '?', $diff ? '-' . human_bytes( $diff ) : '' ) );
				if ( $limit && ++$n >= $limit ) {
					break 2;
				}
			}
		}
		\WP_CLI::success( 'Saved ' . human_bytes( $saved ) . '.' );
	}

	/**
	 * Fill missing alt, title, caption and description for the whole library.
	 *
	 * @subcommand fill-alt
	 */
	public function fill_alt( $args, $a ) {
		$after = 0;
		$n     = 0;
		while ( $ids = ids_missing_alt( 50, false, $after ) ) {
			foreach ( $ids as $id ) {
				$did = fill_existing( $id );
				if ( $did ) {
					++$n;
					\WP_CLI::log( "#{$id}: " . implode( ', ', $did ) . ' → ' . field_value( $id, 'alt' ) );
				}
			}
			$after = max( $ids );
		}
		\WP_CLI::success( "Filled details on {$n} images." );
	}

	/**
	 * Describe images with AI (missing or filename-only alt text).
	 *
	 * [<id>...]
	 * : Specific attachment IDs.
	 */
	public function describe( $args, $a ) {
		if ( ! ai_ready() ) {
			\WP_CLI::error( 'Enable AI and add an API key in Media › Image Optimizer first.' );
		}
		$ids = $args ? array_map( 'intval', $args ) : ids_missing_alt( 500, true );
		foreach ( $ids as $id ) {
			$r = ai_describe( $id );
			is_wp_error( $r ) ? \WP_CLI::warning( "#{$id}: " . $r->get_error_message() ) : \WP_CLI::log( "#{$id}: " . field_value( $id, 'alt' ) );
		}
	}

	/**
	 * Restore originals from backup.
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : Every image that has a backup.
	 */
	public function restore( $args, $a ) {
		global $wpdb;
		$ids = ! empty( $a['all'] ) ? array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", META, '%backup%' ) ) ) : array_map( 'intval', $args );
		$n   = 0;
		foreach ( $ids as $id ) {
			$n += restore_attachment( $id ) ? 1 : 0;
		}
		\WP_CLI::success( "Restored {$n} originals." );
	}

	/**
	 * Library totals.
	 */
	public function stats( $args, $a ) {
		$s = stats();
		\WP_CLI::log( sprintf( 'Images: %d · Optimized: %d · Before: %s · After: %s · Saved: %s · Missing alt: %d',
			$s['images'], $s['optimized'], human_bytes( $s['before'] ), human_bytes( $s['after'] ), human_bytes( $s['before'] - $s['after'] ), count( ids_missing_alt( 100000 ) ) ) );
	}
}

\WP_CLI::add_command( 'image-optimizer', __NAMESPACE__ . '\\CLI' );
