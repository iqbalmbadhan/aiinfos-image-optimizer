<?php
/**
 * Compression engine.
 *
 * Flow for a new upload (any user, any upload screen, REST or CLI):
 *  1. WordPress scales anything wider/taller than "max size" to a "-scaled" copy
 *     and keeps the untouched original (that original is the backup).
 *  2. Sub-sizes are generated at our quality, as WebP when enabled.
 *  3. After WordPress has read the photo's IPTC/EXIF details into the database,
 *     we recompress the main file in place (backup first), keep the colour
 *     profile, drop private camera data such as GPS, and keep the result only
 *     if it is genuinely smaller.
 *
 * @package AIInfos\ImageOptimizer
 */

namespace AIInfos\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

const META = '_aiio';

/* ------------------------------------------------------------------ *
 * Core hooks: quality, max size, WebP sub-sizes, progressive JPEG.
 * ------------------------------------------------------------------ */
add_filter( 'wp_editor_set_quality', static fn( $q, $mime = '' ) => $mime ? quality( (string) $mime ) : $q, 20, 2 );
add_filter( 'jpeg_quality', static fn() => quality( 'image/jpeg' ), 20 );
add_filter( 'image_save_progressive', '__return_true' );
add_filter( 'big_image_size_threshold', static function ( $t ) {
	$max = (int) opt( 'max_dim' );
	return $max > 0 ? $max : false;
}, 20 );

/**
 * Sub-sizes as WebP (smaller files, same look). Every current browser supports it.
 * Disabled while we recompress an existing file in its own format.
 */
add_filter( 'image_editor_output_format', static function ( $formats ) {
	if ( ! opt( 'webp' ) || ! empty( $GLOBALS['aiio_keep_format'] ) ) {
		return $formats;
	}
	if ( ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) {
		return $formats;
	}
	$formats['image/jpeg'] = 'image/webp';
	$formats['image/png']  = 'image/webp';
	return $formats;
}, 20 );

/**
 * After WordPress builds the metadata for a new upload, optimise the files.
 */
add_filter( 'wp_generate_attachment_metadata', static function ( $meta, $id, $context = 'create' ) {
	if ( 'create' !== $context || ! empty( $GLOBALS['aiio_running'] ) ) {
		return $meta;
	}
	return optimize_attachment( (int) $id, is_array( $meta ) ? $meta : [], false );
}, 50, 3 );

/**
 * Optimise one attachment.
 *
 * @param bool $bulk Existing library item: also recompress existing sub-size files in place.
 * @return array Updated attachment metadata.
 */
function optimize_attachment( int $id, array $meta = [], bool $bulk = true ): array {
	$file = get_attached_file( $id );
	$mime = (string) get_post_mime_type( $id );
	if ( ! $file || ! file_exists( $file ) || ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
		update_post_meta( $id, META, [ 'status' => 'skipped', 'reason' => 'type', 'time' => time() ] );
		return $meta;
	}
	$meta = $meta ?: (array) wp_get_attachment_metadata( $id );
	$GLOBALS['aiio_running'] = true;

	$report = [ 'status' => 'optimized', 'before' => 0, 'after' => 0, 'files' => 0, 'gps' => false, 'time' => time(), 'webp' => false ];

	// 1) The untouched upload WordPress keeps next to the "-scaled"/WebP main file.
	//    It is public, so remove GPS from it; otherwise leave it as the master copy.
	$orig_bytes = 0;
	if ( ! empty( $meta['original_image'] ) ) {
		$orig = path_join( dirname( $file ), $meta['original_image'] );
		$orig_bytes = file_exists( $orig ) ? (int) filesize( $orig ) : 0;
		if ( file_exists( $orig ) && opt( 'strip_gps' ) && has_gps( $orig ) ) {
			$r = recompress( $id, $orig, $mime, true, 92 );
			$report['gps'] = $report['gps'] || $r['gps'];
		}
	}

	// 2) The main file. A "-scaled" file was just made by WordPress at our
	//    quality, so only recompress it on bulk runs.
	$scaled = ! empty( $meta['original_image'] );
	if ( $bulk || ! $scaled ) {
		$r = recompress( $id, $file, $mime, ! $scaled );
		$report['before'] += $r['before'];
		$report['after']  += $r['after'];
		$report['files']  += $r['changed'] ? 1 : 0;
		$report['gps']     = $report['gps'] || $r['gps'];
		if ( $r['backup'] ) {
			$report['backup'] = $r['backup'];
		}
	}

	// 3) Bulk only: existing sub-size files, same names, so no URL ever changes.
	if ( $bulk && ! empty( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size ) {
			$sub = path_join( dirname( $file ), $size['file'] );
			if ( file_exists( $sub ) ) {
				$r = recompress( $id, $sub, $size['mime-type'] ?? $mime, false );
				$report['before'] += $r['before'];
				$report['after']  += $r['after'];
				$report['files']  += $r['changed'] ? 1 : 0;
			}
		}
	}

	foreach ( $meta['sizes'] ?? [] as $size ) {
		if ( 'image/webp' === ( $size['mime-type'] ?? '' ) ) {
			$report['webp'] = true;
			break;
		}
	}
	if ( file_exists( $file ) ) {
		clearstatcache( true, $file );
		$meta['filesize'] = filesize( $file );
	}
	// Savings visitors actually get: the uploaded original vs the file now served.
	if ( $orig_bytes && ! empty( $meta['filesize'] ) && $orig_bytes > (int) $meta['filesize'] ) {
		$report['before'] = $orig_bytes;
		$report['after']  = (int) $meta['filesize'];
		$report['files']  = max( 1, $report['files'] );
	}
	if ( 0 === $report['files'] && ! $report['gps'] ) {
		$report['status'] = 'already';
	}
	$prev = get_post_meta( $id, META, true );
	if ( is_array( $prev ) && ! empty( $prev['backup'] ) && empty( $report['backup'] ) ) {
		$report['backup'] = $prev['backup']; // Keep the first (true original) backup.
	}
	if ( is_array( $prev ) && ! empty( $prev['before'] ) && 'already' === $report['status'] ) {
		$report = array_merge( $prev, [ 'time' => time() ] ); // Re-run found nothing new: keep the real numbers.
	}
	update_post_meta( $id, META, $report );
	unset( $GLOBALS['aiio_running'] );

	do_action( 'aiio_optimized', $id, $report );
	return $meta;
}

/**
 * Recompress one file in its own format. Keeps it only when it is smaller by
 * the minimum-saving threshold, or when it removes GPS without growing >5%.
 *
 * @param bool     $backup  Copy the original to the private backup folder first.
 * @param int|null $q       Quality override.
 * @return array{before:int,after:int,changed:bool,gps:bool,backup:string}
 */
function recompress( int $id, string $path, string $mime, bool $backup = true, ?int $q = null ): array {
	$out = [ 'before' => 0, 'after' => 0, 'changed' => false, 'gps' => false, 'backup' => '' ];
	clearstatcache( true, $path );
	$before = (int) filesize( $path );
	if ( $before < 1024 ) {
		return $out;
	}
	$gps  = opt( 'strip_gps' ) && 'image/jpeg' === $mime && has_gps( $path );
	$tmp  = $path . '.aiio-tmp';
	$q    = $q ?? quality( $mime );
	$done = false;

	if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
		$done = imagick_encode( $path, $tmp, $mime, $q );
	}
	if ( ! $done ) {
		$GLOBALS['aiio_keep_format'] = true;
		$editor = wp_get_image_editor( $path );
		if ( ! is_wp_error( $editor ) ) {
			$editor->set_quality( $q );
			$saved = $editor->save( $tmp, $mime );
			if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && $saved['path'] !== $tmp && file_exists( $saved['path'] ) ) {
				rename( $saved['path'], $tmp ); // Editor may add an extension.
			}
			$done = ! is_wp_error( $saved ) && file_exists( $tmp );
		}
		unset( $GLOBALS['aiio_keep_format'] );
	}
	if ( ! $done || ! file_exists( $tmp ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $out;
	}
	$after   = (int) filesize( $tmp );
	$enough  = $after <= $before * ( 1 - (int) opt( 'min_saving' ) / 100 );
	$privacy = $gps && $after <= $before * 1.05;
	if ( ! $enough && ! $privacy ) {
		unlink( $tmp );
		return $out + [];
	}
	if ( $backup && opt( 'backup' ) ) {
		$out['backup'] = backup( $id, $path );
	}
	rename( $tmp, $path );
	return [ 'before' => $before, 'after' => $after, 'changed' => true, 'gps' => $gps, 'backup' => $out['backup'] ];
}

/**
 * Imagick encoder: keeps the ICC colour profile, drops EXIF/XMP/IPTC blocks
 * (WordPress already copied title, caption, credit and copyright into the
 * database), progressive JPEG with standard 4:2:0 chroma.
 */
function imagick_encode( string $src, string $dest, string $mime, int $q ): bool {
	try {
		$im       = new \Imagick( $src );
		$profiles = $im->getImageProfiles( 'icc', true );
		$im->stripImage();
		if ( ! empty( $profiles['icc'] ) ) {
			$im->profileImage( 'icc', $profiles['icc'] );
		}
		$im->setImageCompressionQuality( $q );
		if ( 'image/jpeg' === $mime ) {
			$im->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
			$im->setSamplingFactors( [ '2x2', '1x1', '1x1' ] );
		} elseif ( 'image/png' === $mime ) {
			$im->setOption( 'png:compression-level', '9' );
		} elseif ( 'image/webp' === $mime ) {
			$im->setOption( 'webp:method', '6' );
		}
		$ok = $im->writeImage( $dest );
		$im->clear();
		return (bool) $ok;
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Does a JPEG carry GPS coordinates?
 */
function has_gps( string $path ): bool {
	if ( ! function_exists( 'exif_read_data' ) ) {
		return false;
	}
	$exif = @exif_read_data( $path, 'GPS' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return is_array( $exif ) && ( isset( $exif['GPSLatitude'] ) || isset( $exif['GPSLongitude'] ) );
}

/* ------------------------------------------------------------------ *
 * Backups: a private, unguessable folder inside uploads (works on Nginx,
 * where .htaccess rules are ignored).
 * ------------------------------------------------------------------ */
function backup_dir(): string {
	$name = get_option( 'aiio_backup_dir' );
	if ( ! $name ) {
		$name = 'aiio-backups-' . strtolower( wp_generate_password( 16, false ) );
		update_option( 'aiio_backup_dir', $name, false );
	}
	$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . $name;
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
	}
	return $dir;
}

function backup( int $id, string $path ): string {
	$base = trailingslashit( wp_get_upload_dir()['basedir'] );
	$rel  = ltrim( str_replace( $base, '', $path ), '/' );
	$dest = trailingslashit( backup_dir() ) . $rel;
	if ( file_exists( $dest ) ) {
		return $rel; // Never overwrite the first, true original.
	}
	wp_mkdir_p( dirname( $dest ) );
	return copy( $path, $dest ) ? $rel : '';
}

/**
 * Put the original main file back.
 */
function restore_attachment( int $id ): bool {
	$r = get_post_meta( $id, META, true );
	if ( ! is_array( $r ) || empty( $r['backup'] ) ) {
		return false;
	}
	$src  = trailingslashit( backup_dir() ) . $r['backup'];
	$dest = trailingslashit( wp_get_upload_dir()['basedir'] ) . $r['backup'];
	if ( ! file_exists( $src ) || ! copy( $src, $dest ) ) {
		return false;
	}
	unlink( $src );
	$meta = wp_get_attachment_metadata( $id );
	if ( is_array( $meta ) && get_attached_file( $id ) === $dest ) {
		clearstatcache( true, $dest );
		$meta['filesize'] = filesize( $dest );
		wp_update_attachment_metadata( $id, $meta );
	}
	update_post_meta( $id, META, [ 'status' => 'restored', 'time' => time() ] );
	return true;
}

/**
 * Remove a deleted attachment's backup too.
 */
add_action( 'delete_attachment', static function ( $id ) {
	$r = get_post_meta( $id, META, true );
	if ( is_array( $r ) && ! empty( $r['backup'] ) ) {
		$f = trailingslashit( backup_dir() ) . $r['backup'];
		if ( file_exists( $f ) ) {
			unlink( $f );
		}
	}
} );

/**
 * Library-wide totals.
 *
 * @return array{images:int,optimized:int,before:int,after:int}
 */
function stats(): array {
	global $wpdb;
	$images = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp')" );
	$rows   = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s", META ) );
	$out    = [ 'images' => $images, 'optimized' => 0, 'before' => 0, 'after' => 0 ];
	foreach ( $rows as $raw ) {
		$r = maybe_unserialize( $raw );
		if ( is_array( $r ) && in_array( $r['status'] ?? '', [ 'optimized', 'already' ], true ) ) {
			++$out['optimized'];
			$out['before'] += (int) ( $r['before'] ?? 0 );
			$out['after']  += (int) ( $r['after'] ?? 0 );
		}
	}
	return $out;
}

/**
 * IDs of images not yet processed (for bulk runs).
 *
 * @return int[]
 */
function pending_ids( int $limit = 20 ): array {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		 WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg','image/png','image/webp') AND m.meta_id IS NULL
		 ORDER BY p.ID DESC LIMIT %d",
		META,
		$limit
	) ) );
}
