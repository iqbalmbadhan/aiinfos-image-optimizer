<?php
/**
 * Settings: one option, sensible defaults, Media › Image Optimizer screen.
 *
 * @package AIInfos\ImageOptimizer
 */

namespace AIInfos\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

const OPTION = 'aiio_settings';

/**
 * Defaults follow common best practice for the web:
 * JPEG/WebP quality ~82 is visually lossless for photos, 2560px covers 4K-wide
 * layouts, and WebP sub-sizes cut bytes ~25–35% versus JPEG.
 */
function defaults(): array {
	return [
		'level'        => 'balanced', // lossless | balanced | strong
		'max_dim'      => 2560,
		'webp'         => 1,
		'backup'       => 1,
		'strip_gps'    => 1,
		'rename'       => 1,
		'fill_meta'    => 1,
		'alt_fallback' => 1,
		'min_saving'   => 3,
		'ai_enabled'   => 0,
		'ai_key'       => '',
		'ai_model'     => 'claude-haiku-5-5',
	];
}

/**
 * Read a setting.
 *
 * @return mixed
 */
function opt( string $key ) {
	static $cache = null;
	if ( null === $cache || doing_action( 'update_option_' . OPTION ) ) {
		$saved = get_option( OPTION, [] );
		$cache = array_merge( defaults(), is_array( $saved ) ? $saved : [] );
	}
	return $cache[ $key ] ?? null;
}

/**
 * Quality per output format for the chosen level.
 */
function quality( string $mime ): int {
	$map = [
		'lossless' => [ 'image/jpeg' => 90, 'image/webp' => 88, 'image/avif' => 75 ],
		'balanced' => [ 'image/jpeg' => 82, 'image/webp' => 80, 'image/avif' => 60 ],
		'strong'   => [ 'image/jpeg' => 72, 'image/webp' => 70, 'image/avif' => 50 ],
	];
	$level = $map[ opt( 'level' ) ] ?? $map['balanced'];
	return (int) apply_filters( 'aiio_quality', $level[ $mime ] ?? 82, $mime, opt( 'level' ) );
}

/**
 * Anthropic API key: wp-config constant wins over the stored option.
 */
function ai_key(): string {
	if ( defined( 'AIIO_ANTHROPIC_API_KEY' ) && AIIO_ANTHROPIC_API_KEY ) {
		return (string) AIIO_ANTHROPIC_API_KEY;
	}
	return (string) opt( 'ai_key' );
}

add_action( 'admin_init', static function () {
	register_setting( 'aiio', OPTION, [
		'type'              => 'object',
		'sanitize_callback' => static function ( $in ) {
			$in  = is_array( $in ) ? $in : [];
			$old = get_option( OPTION, [] );
			$out = [
				'level'        => in_array( $in['level'] ?? '', [ 'lossless', 'balanced', 'strong' ], true ) ? $in['level'] : 'balanced',
				'max_dim'      => max( 0, min( 10000, absint( $in['max_dim'] ?? 2560 ) ) ),
				'min_saving'   => max( 0, min( 50, absint( $in['min_saving'] ?? 3 ) ) ),
				'ai_model'     => sanitize_text_field( $in['ai_model'] ?? 'claude-haiku-5-5' ) ?: 'claude-haiku-5-5',
			];
			foreach ( [ 'webp', 'backup', 'strip_gps', 'rename', 'fill_meta', 'alt_fallback', 'ai_enabled' ] as $k ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
			// Keep the stored key unless a new one is typed; "clear" removes it.
			$key           = trim( (string) ( $in['ai_key'] ?? '' ) );
			$out['ai_key'] = 'clear' === $key ? '' : ( '' === $key ? (string) ( $old['ai_key'] ?? '' ) : sanitize_text_field( $key ) );
			return $out;
		},
	] );
} );
