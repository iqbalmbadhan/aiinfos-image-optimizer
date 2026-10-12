<?php
/**
 * Image metadata autofill: SEO-friendly filenames, plus alt text, title,
 * caption and description filled from the best available source.
 *
 * Source strength (a stronger source may replace a weaker auto value, but
 * nothing ever overwrites what a person typed):
 *   filename (1) < post that uses the image (2) < AI description (3) < photographer's IPTC data (4) < human (5)
 *
 * @package AIInfos\ImageOptimizer
 */

namespace AIInfos\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

const AUTO = '_aiio_auto';
const RANK = [ 'filename' => 1, 'post' => 2, 'ai' => 3, 'iptc' => 4 ];

/* ------------------------------------------------------------------ *
 * 1. Filenames: "IMG_4821 (1).JPG" → "chicago-lab-week-one.jpg"
 * ------------------------------------------------------------------ */

/**
 * Camera, phone, screenshot and random-hash names that carry no meaning.
 */
function is_generic_name( string $base ): bool {
	$b = strtolower( trim( $base ) );
	$tail = '(?:[\d_\-\s().]|\bat\b|\bam\b|\bpm\b)*';
	return (bool) preg_match( '/^(img|image|dsc[nf]?|dcim|pxl|photo|pic|picture|screenshot|screen[-_ ]?shot|whatsapp[-_ ]?image|unnamed|untitled|download|downloaded|picsart|canva|fb[-_]?img|signal|mmexport|wp|gopr|mvimg|snapchat|edited|copy[-_ ]?of|scan|file|attachment|demo[-_ ]?image)[-_ ]?' . $tail . '$/', $b )
		|| (bool) preg_match( '/^[\d_\-\s().]+$/', $b )
		|| (bool) preg_match( '/^(?=[0-9a-f]*\d)[0-9a-f]{8,}$/', $b )
		|| (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-/', $b )
		|| strlen( preg_replace( '/[^a-z]/', '', $b ) ) < 3;
}

/**
 * Turn any filename into a clean, lowercase, hyphenated slug.
 */
function slugify( string $text, int $max = 60 ): string {
	$s = remove_accents( wp_strip_all_tags( $text ) );
	$s = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $s ) );
	$s = trim( preg_replace( '/-+/', '-', $s ), '-' );
	if ( strlen( $s ) > $max ) {
		$s = rtrim( substr( $s, 0, $max ), '-' );
		$s = substr( $s, 0, strrpos( $s, '-' ) ?: $max );
	}
	return $s;
}

/**
 * The post an upload is going into (Media modal, block editor, REST).
 */
function upload_parent_id(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context hint; core checks the upload nonce.
	$id = absint( $_REQUEST['post_id'] ?? $_REQUEST['post'] ?? 0 );
	return $id && get_post( $id ) && current_user_can( 'edit_post', $id ) ? $id : 0;
}

$rename = static function ( array $file ): array {
	if ( ! opt( 'rename' ) || ! empty( $file['error'] ) || empty( $file['name'] ) ) {
		return $file;
	}
	$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
	$base = pathinfo( $file['name'], PATHINFO_FILENAME );
	if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ], true ) ) {
		return $file;
	}
	$slug = '';
	if ( is_generic_name( $base ) && ( $parent = upload_parent_id() ) ) {
		$slug = slugify( get_the_title( $parent ) );
	}
	if ( '' === $slug ) {
		$slug = slugify( preg_replace( '/(-|_)?(\d{2,4}x\d{2,4}|scaled|copy|final|edited)$/i', '', $base ) );
	}
	if ( '' === $slug || is_generic_name( $slug ) ) {
		$slug = slugify( get_bloginfo( 'name' ) ) . '-image';
	}
	$file['name'] = $slug . '.' . ( 'jpeg' === $ext ? 'jpg' : $ext );
	return $file;
};
add_filter( 'wp_handle_upload_prefilter', $rename );
add_filter( 'wp_handle_sideload_prefilter', $rename );

/* ------------------------------------------------------------------ *
 * 2. Field helpers
 * ------------------------------------------------------------------ */

/**
 * Current value of a field: alt | title | caption | description.
 */
function field_value( int $id, string $field ): string {
	$p = get_post( $id );
	switch ( $field ) {
		case 'alt':
			return trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		case 'title':
			return trim( (string) $p->post_title );
		case 'caption':
			return trim( (string) $p->post_excerpt );
		default:
			return trim( (string) $p->post_content );
	}
}

/**
 * Set a field if it is empty, or if it holds a weaker auto value we wrote.
 * Values a person typed are never touched.
 */
function fill( int $id, string $field, string $value, string $source ): bool {
	$value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
	if ( '' === $value ) {
		return false;
	}
	if ( 'alt' === $field ) {
		$value = rtrim( mb_substr( $value, 0, 125 ), ' .,;:' ); // Screen-reader friendly length.
	}
	$auto    = (array) get_post_meta( $id, AUTO, true );
	$current = field_value( $id, $field );
	$mine    = isset( $auto[ $field ] ) && $auto[ $field ]['v'] === $current;
	if ( '' !== $current ) {
		if ( ! $mine ) {
			return false; // A person (or WordPress from the photo) set it.
		}
		if ( ( RANK[ $source ] ?? 0 ) <= ( RANK[ $auto[ $field ]['src'] ] ?? 0 ) ) {
			return false;
		}
	}
	switch ( $field ) {
		case 'alt':
			update_post_meta( $id, '_wp_attachment_image_alt', $value );
			break;
		case 'title':
			wp_update_post( [ 'ID' => $id, 'post_title' => $value ] );
			break;
		case 'caption':
			wp_update_post( [ 'ID' => $id, 'post_excerpt' => $value ] );
			break;
		default:
			wp_update_post( [ 'ID' => $id, 'post_content' => $value ] );
	}
	$auto[ $field ] = [ 'v' => $value, 'src' => $source ];
	update_post_meta( $id, AUTO, $auto );
	return true;
}

/**
 * "chicago-lab_week-ONE" → "Chicago lab week one".
 */
function humanize( string $name ): string {
	$t = trim( preg_replace( '/[-_]+/', ' ', pathinfo( $name, PATHINFO_FILENAME ) ) );
	$t = preg_replace( '/\s+(\d{2,4}x\d{2,4}|scaled|rotated|e\d{10,})$/i', '', $t );
	$t = strtolower( $t );
	// Our own fallback name ("site-name-image-2") says nothing about the picture.
	if ( preg_match( '/\bimage( \d+)?$/', $t ) && 0 === strpos( slugify( $t ), slugify( get_bloginfo( 'name' ) ) ) ) {
		return '';
	}
	return '' === $t || is_generic_name( $t ) ? '' : ucfirst( $t );
}

/**
 * Short description from a post: dek (Newsroom), excerpt, or first sentence.
 */
function post_summary( int $post_id ): string {
	$d = (string) get_post_meta( $post_id, 'nr_dek', true );
	if ( '' === $d ) {
		$p = get_post( $post_id );
		$d = has_excerpt( $p ) ? $p->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 30, '…' );
	}
	return trim( $d );
}

/* ------------------------------------------------------------------ *
 * 3. On upload: fill from IPTC, the parent post and the filename.
 *    Runs after WordPress has read the photo's embedded details.
 * ------------------------------------------------------------------ */
add_filter( 'wp_generate_attachment_metadata', static function ( $meta, $id, $context = 'create' ) {
	if ( 'create' !== $context || ! opt( 'fill_meta' ) || ! wp_attachment_is_image( $id ) ) {
		return $meta;
	}
	autofill_attachment( (int) $id, is_array( $meta ) ? $meta : [] );
	return $meta;
}, 60, 3 );

/**
 * Fill whatever is missing on one attachment.
 */
function autofill_attachment( int $id, array $meta = [] ): array {
	$meta   = $meta ?: (array) wp_get_attachment_metadata( $id );
	$im     = $meta['image_meta'] ?? [];
	$file   = basename( (string) get_attached_file( $id ) );
	$parent = (int) get_post_field( 'post_parent', $id ) ?: upload_parent_id();
	$did    = [];

	// WordPress titles uploads with the raw filename; make that readable or replace it.
	$title = field_value( $id, 'title' );
	if ( $title === pathinfo( $file, PATHINFO_FILENAME ) || $title === pathinfo( $file, PATHINFO_FILENAME ) . '-scaled' || is_generic_name( $title ) || preg_match( '/^[a-z0-9]+(-[a-z0-9]+)+$/', $title ) ) {
		wp_update_post( [ 'ID' => $id, 'post_title' => '' ] );
	}

	$iptc_title   = trim( (string) ( $im['title'] ?? '' ) );
	$iptc_caption = trim( (string) ( $im['caption'] ?? '' ) );
	$credit       = trim( implode( ' · ', array_filter( [ $im['credit'] ?? '', $im['copyright'] ?? '' ] ) ) );

	foreach ( [
		[ 'title', $iptc_title, 'iptc' ],
		[ 'alt', $iptc_caption ?: $iptc_title, 'iptc' ],
		[ 'caption', $iptc_caption, 'iptc' ],
	] as [ $f, $v, $src ] ) {
		if ( $v && ! is_generic_name( $v ) && fill( $id, $f, $v, $src ) ) {
			$did[] = $f;
		}
	}
	if ( $parent ) {
		$did = array_merge( $did, apply_post_context( $id, $parent ) );
	}
	$human = humanize( $file );
	if ( $human ) {
		fill( $id, 'title', $human, 'filename' ) && ( $did[] = 'title' );
		fill( $id, 'alt', $human, 'filename' ) && ( $did[] = 'alt' );
	}
	if ( '' === field_value( $id, 'title' ) ) {
		fill( $id, 'title', get_bloginfo( 'name' ) . ' image', 'filename' );
	}
	// Keep the photo's credit/copyright visible in the description.
	if ( $credit && false === stripos( field_value( $id, 'description' ), $credit ) ) {
		$desc = field_value( $id, 'description' );
		fill( $id, 'description', trim( $desc . ' Credit: ' . $credit ), 'iptc' ) && ( $did[] = 'description' );
	}
	return array_values( array_unique( $did ) );
}

/**
 * Use a post as context for an image (upload target, featured image, or
 * used inside the content).
 *
 * @return string[] Fields that were filled.
 */
function apply_post_context( int $id, int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post || 'attachment' === $post->post_type || ! opt( 'fill_meta' ) ) {
		return [];
	}
	$title = wp_strip_all_tags( get_the_title( $post ) );
	if ( '' === $title || __( 'Auto Draft' ) === $title ) {
		return [];
	}
	$did = [];
	fill( $id, 'alt', $title, 'post' ) && ( $did[] = 'alt' );
	fill( $id, 'title', $title, 'post' ) && ( $did[] = 'title' );
	if ( $sum = post_summary( $post_id ) ) {
		fill( $id, 'description', $sum, 'post' ) && ( $did[] = 'description' );
	}
	if ( ! (int) get_post_field( 'post_parent', $id ) ) {
		wp_update_post( [ 'ID' => $id, 'post_parent' => $post_id ] );
	}
	return $did;
}

/* ------------------------------------------------------------------ *
 * 4. When an image is set as a featured image or used in a post.
 * ------------------------------------------------------------------ */
$featured = static function ( $meta_id, $post_id, $key, $value ) {
	if ( '_thumbnail_id' === $key && (int) $value > 0 ) {
		apply_post_context( (int) $value, (int) $post_id );
	}
};
add_action( 'added_post_meta', $featured, 10, 4 );
add_action( 'updated_post_meta', $featured, 10, 4 );

add_action( 'save_post', static function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status || 'attachment' === $post->post_type || ! opt( 'fill_meta' ) ) {
		return;
	}
	foreach ( images_in_content( (string) $post->post_content ) as $att ) {
		apply_post_context( $att, $post_id );
	}
}, 20, 2 );

/**
 * Attachment IDs used in post content (blocks and classic markup).
 *
 * @return int[]
 */
function images_in_content( string $content ): array {
	$ids = [];
	if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
		$ids = array_merge( $ids, $m[1] );
	}
	if ( preg_match_all( '/<!-- wp:(?:image|cover|media-text)\s+\{[^}]*"(?:id|mediaId)":(\d+)/', $content, $m ) ) {
		$ids = array_merge( $ids, $m[1] );
	}
	return array_values( array_unique( array_filter( array_map( 'intval', $ids ), 'wp_attachment_is_image' ) ) );
}

/* ------------------------------------------------------------------ *
 * 5. Front-end safety net: no image ships with an empty alt attribute.
 * ------------------------------------------------------------------ */
function fallback_alt( int $id ): string {
	$alt = field_value( $id, 'alt' );
	if ( '' === $alt ) {
		$t   = (string) get_post_field( 'post_title', $id );
		$alt = false !== stripos( $t, get_bloginfo( 'name' ) . ' image' ) ? '' : ( humanize( $t ) ?: $t );
	}
	if ( ( '' === $alt || is_generic_name( $alt ) ) && is_singular() ) {
		$alt = wp_strip_all_tags( get_the_title( get_queried_object_id() ) );
	}
	return trim( $alt );
}

add_filter( 'wp_get_attachment_image_attributes', static function ( $attr, $att ) {
	if ( opt( 'alt_fallback' ) && isset( $attr['alt'] ) && '' === trim( $attr['alt'] ) && ! is_admin() ) {
		$attr['alt'] = fallback_alt( (int) $att->ID );
	}
	return $attr;
}, 20, 2 );

add_filter( 'wp_content_img_tag', static function ( $html, $context, $id ) {
	if ( ! opt( 'alt_fallback' ) || ! $id || is_admin() ) {
		return $html;
	}
	$alt = null;
	if ( preg_match( '/\salt=(["\'])\s*\1/', $html ) ) {
		$alt = fallback_alt( (int) $id );
		return $alt ? preg_replace( '/\salt=(["\'])\s*\1/', ' alt="' . esc_attr( $alt ) . '"', $html, 1 ) : $html;
	}
	if ( ! preg_match( '/\salt=/', $html ) ) {
		$alt = fallback_alt( (int) $id );
		return $alt ? preg_replace( '/<img\s/', '<img alt="' . esc_attr( $alt ) . '" ', $html, 1 ) : $html;
	}
	return $html;
}, 20, 3 );
