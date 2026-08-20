<?php
/**
 * Profile channel entrypoint manager.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_Entrypoint_Manager' ) ) { return; }

final class BizCity_Personal_Profile_Entrypoint_Manager {

	public static function normalize( $entries ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — normalize user-facing channel settings before Page Builder save.
		if ( ! is_array( $entries ) ) {
			return new WP_Error( 'invalid_param', 'Danh sách kết nối Profile không hợp lệ.', array( 'status' => 400 ) );
		}
		$normalized = array();
		$seen = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) { continue; }
			$channel = sanitize_key( (string) ( $entry['channelCode'] ?? $entry['channel_code'] ?? '' ) );
			if ( ! in_array( $channel, array( 'messenger', 'zalo_oa', 'webchat', 'twin_gpt' ), true ) ) {
				return new WP_Error( 'invalid_param', 'Kênh kết nối Profile không được hỗ trợ.', array( 'status' => 400 ) );
			}
			$presentation = sanitize_key( (string) ( $entry['presentation'] ?? ( 'webchat' === $channel ? 'profile_float' : 'external' ) ) );
			$allowed_presentations = 'webchat' === $channel
				? array( 'profile_float', 'profile_embed' )
				: array( 'external' );
			if ( ! in_array( $presentation, $allowed_presentations, true ) ) {
				return new WP_Error( 'invalid_param', 'Kiểu hiển thị không phù hợp với kênh Profile.', array( 'status' => 400 ) );
			}
			$entry_key = $channel . ':' . $presentation;
			if ( isset( $seen[ $entry_key ] ) ) {
				return new WP_Error( 'invalid_param', 'Kênh Profile bị lặp cấu hình.', array( 'status' => 400 ) );
			}
			$seen[ $entry_key ] = true;
			$fallback = isset( $entry['fallbackUrl'] ) ? esc_url_raw( (string) $entry['fallbackUrl'] ) : '';
			if ( '' !== $fallback && ! wp_http_validate_url( $fallback ) ) {
				return new WP_Error( 'invalid_param', 'Fallback URL của Profile không hợp lệ.', array( 'status' => 400 ) );
			}
			$normalized[] = array(
				'channelCode'  => $channel,
				'enabled'     => ! empty( $entry['enabled'] ),
				'presentation' => $presentation,
				'trackingTag' => sanitize_key( substr( (string) ( $entry['trackingTag'] ?? $entry['tracking_tag'] ?? '' ), 0, 64 ) ),
				'fallbackUrl' => $fallback,
			);
		}
		return $normalized;
	}

	public static function read_from_config( array $config ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — read the single Profile block source of truth.
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) {
			if ( 'profile-card' === (string) ( $block['type'] ?? '' ) ) {
				return is_array( $block['props']['chatEntrypoints'] ?? null ) ? $block['props']['chatEntrypoints'] : array();
			}
		}
		return array();
	}

	public static function write_to_config( array $config, array $entries ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — update only Profile block props; preserve all other Page Builder blocks.
		if ( ! is_array( $config['blocks'] ?? null ) ) {
			return new WP_Error( 'invalid_config', 'SiteConfig chưa có danh sách block.', array( 'status' => 400 ) );
		}
		$found = false;
		foreach ( $config['blocks'] as $index => $block ) {
			if ( 'profile-card' !== (string) ( $block['type'] ?? '' ) ) { continue; }
			$config['blocks'][ $index ]['props']['chatEntrypoints'] = $entries;
			$found = true;
			break;
		}
		if ( ! $found ) {
			return new WP_Error( 'not_found', 'Profile block chưa tồn tại trong Page Builder project.', array( 'status' => 404 ) );
		}
		return $config;
	}
}
