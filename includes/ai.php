<?php
/**
 * Optional AI descriptions (off by default).
 *
 * After an upload, a background job sends a reduced copy of the image
 * (max 1600px) plus the title of the post it belongs to, and gets back alt text,
 * a title, a caption and a description. Results only fill empty fields or
 * replace weaker automatic ones; anything a person typed is kept.
 *
 * @package AIInfos\ImageOptimizer
 */

namespace AIInfos\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

const AI_META = '_aiio_ai';

function ai_ready(): bool {
	return (bool) opt( 'ai_enabled' ) && '' !== ai_key();
}

/**
 * Queue a description job for new uploads (doesn't slow the upload down).
 */
add_filter( 'wp_generate_attachment_metadata', static function ( $meta, $id, $context = 'create' ) {
	if ( 'create' === $context && ai_ready() && wp_attachment_is_image( $id ) ) {
		wp_schedule_single_event( time() + 5, 'aiio_ai_describe', [ (int) $id ] );
	}
	return $meta;
}, 70, 3 );

add_action( 'aiio_ai_describe', __NAMESPACE__ . '\\ai_describe' );

/**
 * Describe one image and fill its fields.
 *
 * @return array|\WP_Error Filled fields or error.
 */
function ai_describe( int $id ) {
	if ( ! ai_ready() ) {
		return new \WP_Error( 'aiio_ai_off', 'AI descriptions are turned off or no API key is set.' );
	}
	$path = ai_image_path( $id );
	if ( ! $path ) {
		return new \WP_Error( 'aiio_ai_file', 'No image file found.' );
	}
	$mime = wp_check_filetype( $path )['type'] ?: 'image/jpeg';
	if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true ) ) {
		return new \WP_Error( 'aiio_ai_type', 'Unsupported image type for AI.' );
	}
	$parent  = (int) get_post_field( 'post_parent', $id );
	$context = $parent ? sprintf( "It is used in an article titled \"%s\".", wp_strip_all_tags( get_the_title( $parent ) ) ) : '';
	$site    = wp_strip_all_tags( get_bloginfo( 'name' ) );
	$prompt  = "You write accessible, SEO-friendly image text for the website \"{$site}\". {$context}\n"
		. "Describe only what is visible. Do not guess names of people, brands or places unless they are written in the image. "
		. "Do not start with \"Image of\" or \"Photo of\".\n"
		. "Reply with JSON only, no other text: "
		. '{"alt":"under 120 characters, what a screen-reader user needs","title":"2-6 words","caption":"one short sentence","description":"one or two sentences"}';

	$body = [
		'model'      => opt( 'ai_model' ),
		'max_tokens' => 400,
		'messages'   => [ [
			'role'    => 'user',
			'content' => [
				[ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => $mime, 'data' => base64_encode( (string) file_get_contents( $path ) ) ] ],
				[ 'type' => 'text', 'text' => $prompt ],
			],
		] ],
	];
	$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
		'timeout' => 45,
		'headers' => [
			'x-api-key'         => ai_key(),
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		],
		'body'    => wp_json_encode( $body ),
	] );
	if ( is_wp_error( $res ) ) {
		update_post_meta( $id, AI_META, [ 'status' => 'error', 'error' => $res->get_error_message(), 'time' => time() ] );
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || empty( $data['content'][0]['text'] ) ) {
		$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
		update_post_meta( $id, AI_META, [ 'status' => 'error', 'error' => $msg, 'time' => time() ] );
		return new \WP_Error( 'aiio_ai_http', $msg );
	}
	$text = (string) $data['content'][0]['text'];
	if ( ! preg_match( '/\{.*\}/s', $text, $m ) || ! is_array( $json = json_decode( $m[0], true ) ) ) {
		update_post_meta( $id, AI_META, [ 'status' => 'error', 'error' => 'Unreadable AI reply', 'time' => time() ] );
		return new \WP_Error( 'aiio_ai_parse', 'Unreadable AI reply.' );
	}
	$did = [];
	foreach ( [ 'alt', 'title', 'caption', 'description' ] as $f ) {
		if ( ! empty( $json[ $f ] ) && is_string( $json[ $f ] ) && fill( $id, $f, $json[ $f ], 'ai' ) ) {
			$did[] = $f;
		}
	}
	update_post_meta( $id, AI_META, [ 'status' => 'done', 'fields' => $did, 'model' => opt( 'ai_model' ), 'time' => time() ] );
	return $did;
}

/**
 * A reduced copy for the API (cheaper, faster): medium_large, large, then the file.
 */
function ai_image_path( int $id ): string {
	$file = get_attached_file( $id );
	if ( ! $file ) {
		return '';
	}
	$meta = wp_get_attachment_metadata( $id );
	// Small enough already? Send the real file (best detail for the description).
	if ( file_exists( $file ) && ( $meta['width'] ?? 9999 ) <= 1600 && filesize( $file ) < 4 * MB_IN_BYTES ) {
		return $file;
	}
	foreach ( [ 'medium_large', 'large', 'medium' ] as $size ) {
		if ( ! empty( $meta['sizes'][ $size ]['file'] ) ) {
			$p = path_join( dirname( $file ), $meta['sizes'][ $size ]['file'] );
			if ( file_exists( $p ) ) {
				return $p;
			}
		}
	}
	return file_exists( $file ) && filesize( $file ) < 4 * MB_IN_BYTES ? $file : '';
}
