<?php
/**
 * Profile public channel-context resolver.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_Channel_Resolver' ) ) { return; }

final class BizCity_Personal_Profile_Channel_Resolver {

	public static function verify_context( $token, $card_id, $channel_code, $presentation ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — verify signed public chat context before entering TwinBrain.
		$parts = explode( '.', (string) $token, 2 );
		if ( count( $parts ) !== 2 ) { return new WP_Error( 'invalid_context', 'Context chat Profile không hợp lệ.', array( 'status' => 403 ) ); }
		$payload = $parts[0];
		$signature = $parts[1];
		$claims_json = base64_decode( strtr( $payload, '-_', '+/' ) . str_repeat( '=', ( 4 - ( strlen( $payload ) % 4 ) ) % 4 ), true );
		$claims = is_string( $claims_json ) ? json_decode( $claims_json, true ) : null;
		if ( ! is_array( $claims ) || ! hash_equals( hash_hmac( 'sha256', $payload . '.' . (int) ( $claims['expires_at'] ?? 0 ), wp_salt( 'auth' ) ), $signature ) ) {
			return new WP_Error( 'invalid_context', 'Context chat Profile không hợp lệ.', array( 'status' => 403 ) );
		}
		$expected_platform = 'twin_gpt' === sanitize_key( (string) $channel_code ) ? 'TWINWEB' : 'WEBCHAT';
		if ( (int) ( $claims['expires_at'] ?? 0 ) < time() || (int) ( $claims['card_id'] ?? 0 ) !== (int) $card_id || (string) $claims['channel_code'] !== sanitize_key( (string) $channel_code ) || (string) $claims['presentation'] !== sanitize_key( (string) $presentation ) || $expected_platform !== (string) ( $claims['platform'] ?? '' ) || ( 'WEBCHAT' === $expected_platform && 'guest_channel' !== (string) ( $claims['subject_policy'] ?? '' ) ) ) {
			return new WP_Error( 'invalid_context', 'Context chat Profile đã hết hạn hoặc không khớp.', array( 'status' => 403 ) );
		}
		$current_binding = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( $expected_platform, (string) get_current_blog_id() ) : null;
		if ( ! is_array( $current_binding ) || (int) ( $claims['binding_id'] ?? 0 ) !== (int) ( $current_binding['id'] ?? 0 ) || empty( $current_binding['status'] ) ) {
			return new WP_Error( 'invalid_context', 'Binding WebChat đã thay đổi hoặc không còn hoạt động.', array( 'status' => 403 ) );
		}
		return $claims;
	}

	public static function resolve( $card_id, $channel_code, $presentation ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — resolve only enabled published entrypoints through Channel Binding.
		$card = BizCity_Personal_Profile_Card_Manager::get_published( $card_id );
		if ( ! is_array( $card ) ) {
			return new WP_Error( 'not_found', 'Không tìm thấy cổng Profile công khai.', array( 'status' => 404 ) );
		}
		$channel_code = sanitize_key( (string) $channel_code );
		$presentation = sanitize_key( (string) $presentation );
		if ( ! in_array( $channel_code, array( 'webchat', 'twin_gpt' ), true ) ) {
			return new WP_Error( 'invalid_param', 'Kênh Profile không được hỗ trợ cho chat context.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $presentation, array( 'profile_float', 'profile_embed' ), true ) ) {
			return new WP_Error( 'invalid_param', 'Kiểu hiển thị chat không hợp lệ.', array( 'status' => 400 ) );
		}

		$entrypoint = self::find_entrypoint( (int) $card['bzpb_project_id'], $channel_code, $presentation );
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — resolve the legacy empty config to the default FloatChat without mutating SiteConfig.
		if ( ! is_array( $entrypoint ) && 'webchat' === $channel_code && 'profile_float' === $presentation ) {
			$entrypoint = array( 'enabled' => true, 'trackingTag' => '' );
		}
		if ( ! is_array( $entrypoint ) || empty( $entrypoint['enabled'] ) ) {
			return new WP_Error( 'not_found', 'Entrypoint chat chưa được bật cho Profile này.', array( 'status' => 404 ) );
		}
		$platform = 'webchat' === $channel_code ? 'WEBCHAT' : 'TWINWEB';
		$account_id = (string) get_current_blog_id();
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return new WP_Error( 'module_not_loaded', 'Channel Gateway chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		$binding = BizCity_Channel_Binding::resolve( $platform, $account_id );
		if ( ! is_array( $binding ) || empty( $binding['id'] ) || empty( $binding['status'] ) ) {
			return new WP_Error( 'gateway_degraded', 'Kênh chat Profile chưa được cấu hình.', array( 'status' => 503 ) );
		}

		$expires = time() + 300;
		$subject_policy = 'WEBCHAT' === $platform ? 'guest_channel' : 'twinweb_policy';
		$claims = array(
			'card_id'       => (int) $card_id,
			'channel_code'  => $channel_code,
			'platform'      => $platform,
			'account_id'    => $account_id,
			'binding_id'    => (int) $binding['id'],
			'presentation'  => $presentation,
			'expires_at'    => $expires,
			'subject_policy'=> $subject_policy,
		);
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $claims ) : json_encode( $claims );
		$payload = rtrim( strtr( base64_encode( $encoded ), '+/', '-_' ), '=' );
		$signature_input = $payload . '.' . $expires;
		$token_signature = hash_hmac( 'sha256', $signature_input, wp_salt( 'auth' ) );
		$token = $payload . '.' . $token_signature;
		return array(
			'channel_code'   => $channel_code,
			'platform'       => $platform,
			'account_id'     => $account_id,
			'binding_id'     => (int) $binding['id'],
			'presentation'   => $presentation,
			'subject_policy' => $subject_policy,
			'expires_at'     => $expires,
			'context_token'  => $token,
			'tracking_tag'   => sanitize_key( (string) ( $entrypoint['trackingTag'] ?? '' ) ),
		);
	}

	private static function find_entrypoint( $project_id, $channel_code, $presentation ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT site_config FROM `' . $wpdb->prefix . 'bzpb_projects` WHERE id = %d LIMIT 1', (int) $project_id ) );
		if ( ! $row ) { return null; }
		$config = json_decode( (string) $row->site_config, true );
		if ( ! is_array( $config ) || ! is_array( $config['blocks'] ?? null ) ) { return null; }
		foreach ( $config['blocks'] as $block ) {
			if ( 'profile-card' !== (string) ( $block['type'] ?? '' ) ) { continue; }
			$entries = is_array( $block['props']['chatEntrypoints'] ?? null ) ? $block['props']['chatEntrypoints'] : array();
			foreach ( $entries as $entry ) {
				if ( sanitize_key( (string) ( $entry['channelCode'] ?? '' ) ) === $channel_code
					&& sanitize_key( (string) ( $entry['presentation'] ?? '' ) ) === $presentation ) {
					return $entry;
				}
			}
		}
		return null;
	}
}
