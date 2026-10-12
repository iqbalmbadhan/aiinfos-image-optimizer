<?php
/**
 * Admin: Media › Image Optimizer (stats, bulk tools, settings), Media Library
 * column and per-image actions.
 *
 * @package AIInfos\ImageOptimizer
 */

namespace AIInfos\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

function human_bytes( int $b ): string {
	return size_format( max( 0, $b ), $b >= MB_IN_BYTES ? 1 : 0 ) ?: '0 B';
}

/**
 * Images still missing alt text (or with only filename-based alt).
 *
 * @return int[]
 */
function ids_missing_alt( int $limit = 20, bool $weak_too = false, int $after = 0 ): array {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt'
		 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND p.ID > %d
		 AND ( a.meta_value IS NULL OR a.meta_value = '' " . ( $weak_too ? "OR p.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . AUTO . "' AND meta_value LIKE '%%\"alt\";a:2:{s:1:\"v\";%%s:3:\"src\";s:8:\"filename\"%%' )" : '' ) . " )
		 ORDER BY p.ID ASC LIMIT %d",
		$after,
		$limit
	) );
	return array_map( 'intval', $ids );
}

/**
 * Fill metadata for an existing image, using every post that features or embeds it.
 */
function fill_existing( int $id ): array {
	global $wpdb;
	$did   = autofill_attachment( $id );
	$posts = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 3", $id ) );
	$like  = '%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%';
	$posts = array_merge( $posts, $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('attachment','revision') AND post_content LIKE %s LIMIT 3", $like ) ) );
	foreach ( array_unique( array_map( 'intval', $posts ) ) as $pid ) {
		$did = array_merge( $did, apply_post_context( $id, $pid ) );
	}
	return array_values( array_unique( $did ) );
}

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */
add_action( 'admin_menu', static function () {
	$hook = add_media_page( __( 'Image Optimizer', 'aiinfos-image-optimizer' ), __( 'Image Optimizer', 'aiinfos-image-optimizer' ), 'manage_options', 'aiio', __NAMESPACE__ . '\\render_page' );
	add_action( 'load-' . $hook, static function () {
		wp_enqueue_style( 'aiio-admin', AIIO_URL . 'assets/admin.css', [], AIIO_VERSION );
		wp_enqueue_script( 'aiio-admin', AIIO_URL . 'assets/admin.js', [], AIIO_VERSION, true );
		wp_localize_script( 'aiio-admin', 'aiio', [ 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'aiio_bulk' ) ] );
	} );
} );

add_filter( 'plugin_action_links_' . plugin_basename( AIIO_FILE ), static function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'upload.php?page=aiio' ) ) . '">' . esc_html__( 'Settings', 'aiinfos-image-optimizer' ) . '</a>' );
	return $links;
} );

function render_page(): void {
	$s       = stats();
	$saved   = max( 0, $s['before'] - $s['after'] );
	$pct     = $s['before'] ? round( $saved / $s['before'] * 100 ) : 0;
	$pending = count( pending_ids( 100000 ) );
	$noalt   = count( ids_missing_alt( 100000 ) );
	$webp    = wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
	$engine  = extension_loaded( 'imagick' ) ? 'Imagick' : 'GD';
	?>
	<div class="wrap aiio">
		<h1><?php esc_html_e( 'Image Optimizer', 'aiinfos-image-optimizer' ); ?></h1>
		<p class="aiio-lede"><?php esc_html_e( 'Every new upload is compressed automatically, whoever uploads it. Use the tools below for images that were already in your library.', 'aiinfos-image-optimizer' ); ?></p>

		<div class="aiio-cards">
			<div class="aiio-card"><span><?php esc_html_e( 'Images in library', 'aiinfos-image-optimizer' ); ?></span><b><?php echo esc_html( number_format_i18n( $s['images'] ) ); ?></b></div>
			<div class="aiio-card"><span><?php esc_html_e( 'Optimized', 'aiinfos-image-optimizer' ); ?></span><b><?php echo esc_html( number_format_i18n( $s['optimized'] ) ); ?></b></div>
			<div class="aiio-card"><span><?php esc_html_e( 'Space saved', 'aiinfos-image-optimizer' ); ?></span><b><?php echo esc_html( human_bytes( $saved ) ); ?></b><small><?php echo esc_html( $pct ? sprintf( __( '%d%% smaller', 'aiinfos-image-optimizer' ), $pct ) : '' ); ?></small></div>
			<div class="aiio-card"><span><?php esc_html_e( 'Missing alt text', 'aiinfos-image-optimizer' ); ?></span><b data-aiio-noalt><?php echo esc_html( number_format_i18n( $noalt ) ); ?></b></div>
		</div>

		<div class="aiio-tools">
			<div class="aiio-tool">
				<h2><?php esc_html_e( 'Optimize existing images', 'aiinfos-image-optimizer' ); ?></h2>
				<p><?php echo esc_html( sprintf( _n( '%s image has not been processed yet.', '%s images have not been processed yet.', $pending, 'aiinfos-image-optimizer' ), number_format_i18n( $pending ) ) ); ?> <?php esc_html_e( 'Files are recompressed in place, so no image URL changes and nothing in your posts breaks. Originals are backed up first.', 'aiinfos-image-optimizer' ); ?></p>
				<button type="button" class="button button-primary" data-aiio-run="optimize" data-aiio-total="<?php echo esc_attr( $pending ); ?>" <?php disabled( 0, $pending ); ?>><?php esc_html_e( 'Optimize library', 'aiinfos-image-optimizer' ); ?></button>
				<div class="aiio-progress" hidden><div class="aiio-bar"><i></i></div><p class="aiio-status" aria-live="polite"></p></div>
			</div>
			<div class="aiio-tool">
				<h2><?php esc_html_e( 'Fill missing image details', 'aiinfos-image-optimizer' ); ?></h2>
				<p><?php esc_html_e( 'Adds alt text, titles and descriptions where they are empty, using the photo’s own details, the posts that use each image, and clean filenames. Text you typed is never changed.', 'aiinfos-image-optimizer' ); ?></p>
				<button type="button" class="button" data-aiio-run="alt" data-aiio-total="<?php echo esc_attr( $noalt ); ?>" <?php disabled( 0, $noalt ); ?>><?php esc_html_e( 'Fill missing details', 'aiinfos-image-optimizer' ); ?></button>
				<?php if ( ai_ready() ) : ?>
					<button type="button" class="button" data-aiio-run="ai" data-aiio-total="<?php echo esc_attr( count( ids_missing_alt( 100000, true ) ) ); ?>"><?php esc_html_e( 'Describe with AI', 'aiinfos-image-optimizer' ); ?></button>
				<?php endif; ?>
				<div class="aiio-progress" hidden><div class="aiio-bar"><i></i></div><p class="aiio-status" aria-live="polite"></p></div>
			</div>
		</div>

		<form method="post" action="options.php" class="aiio-settings">
			<?php settings_fields( 'aiio' ); ?>
			<h2><?php esc_html_e( 'Settings', 'aiinfos-image-optimizer' ); ?></h2>
			<p class="description"><?php echo esc_html( sprintf( __( 'Image engine: %1$s · WebP: %2$s', 'aiinfos-image-optimizer' ), $engine, $webp ? __( 'supported', 'aiinfos-image-optimizer' ) : __( 'not supported by this server', 'aiinfos-image-optimizer' ) ) ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Compression', 'aiinfos-image-optimizer' ); ?></th><td>
					<?php foreach ( [ 'lossless' => __( 'Maximum detail (JPEG 90). Larger files', 'aiinfos-image-optimizer' ), 'balanced' => __( 'Balanced (JPEG 82). Recommended: looks identical, much smaller', 'aiinfos-image-optimizer' ), 'strong' => __( 'Smallest files (JPEG 72). Slight softening on close inspection', 'aiinfos-image-optimizer' ) ] as $k => $label ) : ?>
						<label class="aiio-radio"><input type="radio" name="<?php echo esc_attr( OPTION ); ?>[level]" value="<?php echo esc_attr( $k ); ?>" <?php checked( opt( 'level' ), $k ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<tr><th scope="row"><label for="aiio-max"><?php esc_html_e( 'Maximum size', 'aiinfos-image-optimizer' ); ?></label></th><td>
					<input id="aiio-max" type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr( OPTION ); ?>[max_dim]" value="<?php echo esc_attr( opt( 'max_dim' ) ); ?>"> px
					<p class="description"><?php esc_html_e( 'Larger uploads are scaled down to this width/height; the untouched original is kept. 2560 suits full-width layouts. 0 turns it off.', 'aiinfos-image-optimizer' ); ?></p>
				</td></tr>
				<?php
				$toggles = [
					'webp'         => [ __( 'Create WebP versions', 'aiinfos-image-optimizer' ), __( 'Thumbnails and in-post sizes are saved as WebP (about 25–35% smaller than JPEG). WordPress serves them automatically.', 'aiinfos-image-optimizer' ) ],
					'strip_gps'    => [ __( 'Remove location data', 'aiinfos-image-optimizer' ), __( 'Strips GPS and camera details from public files. Title, caption, credit and copyright are saved in the Media Library first. Colour profile is kept.', 'aiinfos-image-optimizer' ) ],
					'backup'       => [ __( 'Keep backups', 'aiinfos-image-optimizer' ), __( 'Saves each original before recompressing it, in a private folder, so you can restore it from the Media Library.', 'aiinfos-image-optimizer' ) ],
					'rename'       => [ __( 'SEO-friendly filenames', 'aiinfos-image-optimizer' ), __( '“IMG_4821 (1).JPG” becomes “your-post-title.jpg” when uploaded into a post, or a clean lowercase name otherwise.', 'aiinfos-image-optimizer' ) ],
					'fill_meta'    => [ __( 'Fill missing alt, title, caption and description', 'aiinfos-image-optimizer' ), __( 'Uses the photo’s own details, the post the image is uploaded to, featured in or embedded in, and its filename. Never overwrites what someone typed.', 'aiinfos-image-optimizer' ) ],
					'alt_fallback' => [ __( 'No empty alt on the site', 'aiinfos-image-optimizer' ), __( 'If an image still has no alt text when a page is shown, use its title or the page title instead.', 'aiinfos-image-optimizer' ) ],
				];
				foreach ( $toggles as $k => [ $label, $help ] ) :
					?>
					<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( OPTION ); ?>[<?php echo esc_attr( $k ); ?>]" value="1" <?php checked( 1, (int) opt( $k ) ); ?>> <?php echo esc_html( $help ); ?></label></td></tr>
				<?php endforeach; ?>
				<tr><th scope="row"><label for="aiio-min"><?php esc_html_e( 'Minimum saving', 'aiinfos-image-optimizer' ); ?></label></th><td>
					<input id="aiio-min" type="number" min="0" max="50" class="small-text" name="<?php echo esc_attr( OPTION ); ?>[min_saving]" value="<?php echo esc_attr( opt( 'min_saving' ) ); ?>"> %
					<p class="description"><?php esc_html_e( 'A recompressed file is kept only if it is at least this much smaller. Avoids re-encoding images that are already efficient.', 'aiinfos-image-optimizer' ); ?></p>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'AI descriptions (optional)', 'aiinfos-image-optimizer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Uses Anthropic’s Claude to look at each new image (a copy no larger than 1600px) and write alt text, a title, a caption and a description. Runs in the background after upload. Billed to your Anthropic API account.', 'aiinfos-image-optimizer' ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Enable', 'aiinfos-image-optimizer' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( OPTION ); ?>[ai_enabled]" value="1" <?php checked( 1, (int) opt( 'ai_enabled' ) ); ?>> <?php esc_html_e( 'Describe new uploads with AI', 'aiinfos-image-optimizer' ); ?></label></td></tr>
				<tr><th scope="row"><label for="aiio-key"><?php esc_html_e( 'Anthropic API key', 'aiinfos-image-optimizer' ); ?></label></th><td>
					<?php if ( defined( 'AIIO_ANTHROPIC_API_KEY' ) ) : ?>
						<p><?php esc_html_e( 'Set in wp-config.php (AIIO_ANTHROPIC_API_KEY).', 'aiinfos-image-optimizer' ); ?></p>
					<?php else : ?>
						<input id="aiio-key" type="password" autocomplete="off" class="regular-text" name="<?php echo esc_attr( OPTION ); ?>[ai_key]" value="" placeholder="<?php echo esc_attr( opt( 'ai_key' ) ? __( 'Saved. Type a new key to replace, or “clear”', 'aiinfos-image-optimizer' ) : 'sk-ant-…' ); ?>">
						<p class="description"><?php esc_html_e( 'More secure: add define( \'AIIO_ANTHROPIC_API_KEY\', \'…\' ); to wp-config.php instead.', 'aiinfos-image-optimizer' ); ?></p>
					<?php endif; ?>
				</td></tr>
				<tr><th scope="row"><label for="aiio-model"><?php esc_html_e( 'Model', 'aiinfos-image-optimizer' ); ?></label></th><td><input id="aiio-model" type="text" class="regular-text" name="<?php echo esc_attr( OPTION ); ?>[ai_model]" value="<?php echo esc_attr( opt( 'ai_model' ) ); ?>"></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* ------------------------------------------------------------------ *
 * Bulk AJAX: small batches so it works on any host's time limit.
 * ------------------------------------------------------------------ */
add_action( 'wp_ajax_aiio_bulk', static function () {
	check_ajax_referer( 'aiio_bulk' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}
	$type  = sanitize_key( $_POST['type'] ?? '' );
	$after = absint( $_POST['after'] ?? 0 );
	$done  = [];
	$saved = 0;
	$errors = 0;
	if ( 'optimize' === $type ) {
		foreach ( pending_ids( 4 ) as $id ) {
			optimize_attachment( $id, [], true );
			if ( opt( 'fill_meta' ) ) {
				fill_existing( $id );
			}
			$r      = get_post_meta( $id, META, true );
			$saved += max( 0, (int) ( $r['before'] ?? 0 ) - (int) ( $r['after'] ?? 0 ) );
			$done[] = $id;
		}
		$left = count( pending_ids( 100000 ) );
	} elseif ( 'alt' === $type ) {
		$ids = ids_missing_alt( 20, false, $after );
		foreach ( $ids as $id ) {
			fill_existing( $id );
			$done[] = $id;
		}
		$after = $ids ? max( $ids ) : $after;
		$left  = $ids ? count( ids_missing_alt( 100000, false, $after ) ) : 0;
	} elseif ( 'ai' === $type && ai_ready() ) {
		$ids = ids_missing_alt( 2, true, $after );
		foreach ( $ids as $id ) {
			if ( is_wp_error( ai_describe( $id ) ) ) {
				++$errors;
			}
			$done[] = $id;
		}
		$after = $ids ? max( $ids ) : $after;
		$left  = $ids ? count( ids_missing_alt( 100000, true, $after ) ) : 0;
	} else {
		wp_send_json_error( 'bad type', 400 );
	}
	wp_send_json_success( [ 'done' => count( $done ), 'left' => $left, 'saved' => $saved, 'after' => $after, 'errors' => $errors, 'noalt' => count( ids_missing_alt( 100000 ) ) ] );
} );

/* ------------------------------------------------------------------ *
 * Media Library: column, row actions, attachment screen info.
 * ------------------------------------------------------------------ */
function status_html( int $id ): string {
	$r = get_post_meta( $id, META, true );
	if ( ! wp_attachment_is_image( $id ) ) {
		return '—';
	}
	if ( ! is_array( $r ) ) {
		return '<span class="aiio-pill">' . esc_html__( 'Not optimized yet', 'aiinfos-image-optimizer' ) . '</span>';
	}
	$out = '';
	switch ( $r['status'] ?? '' ) {
		case 'optimized':
		case 'already':
			$b = (int) ( $r['before'] ?? 0 );
			$a = (int) ( $r['after'] ?? 0 );
			if ( $b > $a ) {
				$out .= '<strong class="aiio-good">' . esc_html( sprintf( __( '%d%% smaller', 'aiinfos-image-optimizer' ), round( ( $b - $a ) / $b * 100 ) ) ) . '</strong> <span class="aiio-muted">' . esc_html( human_bytes( $b ) . ' → ' . human_bytes( $a ) ) . '</span>';
			} else {
				$out .= '<strong class="aiio-good">' . esc_html__( 'Already efficient', 'aiinfos-image-optimizer' ) . '</strong>';
			}
			if ( ! empty( $r['webp'] ) ) {
				$out .= ' <span class="aiio-pill">WebP</span>';
			}
			if ( ! empty( $r['gps'] ) ) {
				$out .= ' <span class="aiio-pill">' . esc_html__( 'GPS removed', 'aiinfos-image-optimizer' ) . '</span>';
			}
			break;
		case 'restored':
			$out .= esc_html__( 'Original restored', 'aiinfos-image-optimizer' );
			break;
		default:
			$out .= '<span class="aiio-muted">' . esc_html__( 'Skipped', 'aiinfos-image-optimizer' ) . '</span>';
	}
	$alt  = field_value( $id, 'alt' );
	$out .= '<br><span class="aiio-muted">' . ( $alt ? esc_html__( 'Alt:', 'aiinfos-image-optimizer' ) . ' ' . esc_html( wp_trim_words( $alt, 8, '…' ) ) : '<em>' . esc_html__( 'No alt text', 'aiinfos-image-optimizer' ) . '</em>' ) . '</span>';
	return $out;
}

add_filter( 'manage_media_columns', static function ( $cols ) {
	$cols['aiio'] = __( 'Optimization', 'aiinfos-image-optimizer' );
	return $cols;
} );
add_action( 'manage_media_custom_column', static function ( $col, $id ) {
	if ( 'aiio' === $col ) {
		echo wp_kses_post( status_html( (int) $id ) );
	}
}, 10, 2 );

add_filter( 'media_row_actions', static function ( $actions, $post ) {
	if ( ! wp_attachment_is_image( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}
	$url                   = wp_nonce_url( admin_url( 'admin-post.php?action=aiio_one&do=optimize&id=' . $post->ID ), 'aiio_one_' . $post->ID );
	$actions['aiio_opt']   = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Optimize', 'aiinfos-image-optimizer' ) . '</a>';
	$r                     = get_post_meta( $post->ID, META, true );
	if ( is_array( $r ) && ! empty( $r['backup'] ) ) {
		$url                 = wp_nonce_url( admin_url( 'admin-post.php?action=aiio_one&do=restore&id=' . $post->ID ), 'aiio_one_' . $post->ID );
		$actions['aiio_res'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Restore original', 'aiinfos-image-optimizer' ) . '</a>';
	}
	return $actions;
}, 10, 2 );

add_action( 'admin_post_aiio_one', static function () {
	$id = absint( $_GET['id'] ?? 0 );
	check_admin_referer( 'aiio_one_' . $id );
	if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( esc_html__( 'Not allowed.', 'aiinfos-image-optimizer' ) );
	}
	if ( 'restore' === ( $_GET['do'] ?? '' ) ) {
		restore_attachment( $id );
	} else {
		optimize_attachment( $id, [], true );
		fill_existing( $id );
	}
	wp_safe_redirect( wp_get_referer() ?: admin_url( 'upload.php?mode=list' ) );
	exit;
} );

add_filter( 'attachment_fields_to_edit', static function ( $fields, $post ) {
	if ( wp_attachment_is_image( $post->ID ) ) {
		$fields['aiio'] = [ 'label' => __( 'Optimization', 'aiinfos-image-optimizer' ), 'input' => 'html', 'html' => '<div class="aiio-field">' . wp_kses_post( status_html( (int) $post->ID ) ) . '</div>' ];
	}
	return $fields;
}, 10, 2 );

add_action( 'admin_head-upload.php', static function () {
	echo '<style>.column-aiio{width:18%}.aiio-good{color:#0a7a3d}.aiio-muted{color:#646970;font-size:12px}.aiio-pill{display:inline-block;font-size:11px;padding:1px 7px;border-radius:99px;background:#f0e6f6;color:#5b1a6e}</style>';
} );
