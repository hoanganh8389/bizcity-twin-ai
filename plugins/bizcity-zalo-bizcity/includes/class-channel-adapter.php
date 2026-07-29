<?php
/**
 * BizCity Zalo Hotline — Channel Adapter (PHASE 0.31 T-S2.2 — LIVE)
 *
 * Zalo Hotline = Zalo Notification Service (ZNS) — outbound-only template
 * messages tied to a verified phone number/OA, distinct from Zalo Bot.
 * The webhook side is owned by bizcity-admin-hook-zalo's existing
 * `gateway-functions.php`; this adapter exposes the contract so the
 * channel-gateway can route notifications via prefix `hotline_`.
 *
 * LIVE WIRING (Sprint 4):
 *   send_outbound() now calls https://business.openapi.zalo.me/message/template
 *   using the OA access_token + template_id. Free-text $message is ignored
 *   (ZNS is template-only); pass template_id + template_data via $options.
 *
 *   ZNS access tokens rotate ~every 25 days via refresh_token. This adapter
 *   reads the current token from the WaicChannelIntegration_zalo_hotline
 *   integration settings; refresh automation is NOT included here (operator
 *   responsibility until cron lands).
 *
 * @package BizCity\AdminHookZalo
 * @since   PHASE 0.31 Sprint 2 (skeleton) / Sprint 4 (live)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Channel_Adapter' ) ) {
	return;
}

class BizCity_Zalo_Hotline_Channel_Adapter implements BizCity_Channel_Adapter {

	const ZNS_ENDPOINT_SEND = 'https://business.openapi.zalo.me/message/template';
	const ZNS_ENDPOINT_LIST = 'https://business.openapi.zalo.me/template/all';

	public function get_platform(): string {
		return 'ZALO_HOTLINE';
	}

	public function get_prefix(): string {
		return 'hotline_';
	}

	public function get_endpoints(): array {
		return array( '?bizhook=1' );
	}

	public function verify_webhook( array $request ): bool {
		// gateway-functions.php already guards via shared secret.
		return true;
	}

	public function normalize_inbound( array $raw_data ): array {
		$user = isset( $raw_data['user_id'] ) ? (string) $raw_data['user_id'] : '';
		$text = isset( $raw_data['message'] ) ? (string) $raw_data['message'] : '';

		return array(
			'platform'    => 'ZALO_HOTLINE',
			'chat_id'     => 'hotline_' . $user,
			'user_id'     => $user,
			'client_name' => isset( $raw_data['display_name'] ) ? (string) $raw_data['display_name'] : '',
			'message'     => $text,
			'message_id'  => isset( $raw_data['message_id'] ) ? (string) $raw_data['message_id'] : '',
			'attachments' => array(),
			'event_type'  => 'message',
			'raw'         => $raw_data,
		);
	}

	/**
	 * Outbound ZNS template send — LIVE.
	 *
	 * @param string $chat_id  Phone number (E.164-ish, with or without `hotline_` prefix and `84` country code).
	 * @param string $message  Free-text fallback. Used as `template_data.content` if no
	 *                         explicit template_data supplied. (ZNS templates expect named
	 *                         params; for many templates a single `content` field works.)
	 * @param array  $options  {
	 *     @type int|string $account_idx     WaicChannelIntegration_zalo_hotline account index. Default 0.
	 *     @type string     $template_id     ZNS template_id override (else integration default).
	 *     @type array      $template_data   Map of template fields (overrides $message synthesis).
	 *     @type string     $tracking_id     Optional tracking id.
	 *     @type string     $access_token    Override integration access_token.
	 * }
	 * @return bool true if ZNS returned error=0, false otherwise (error logged).
	 */
	public function send_outbound( string $chat_id, string $message, array $options = array() ): bool {
		$account_idx = isset( $options['account_idx'] ) ? (int) $options['account_idx'] : 0;
		$creds       = $this->resolve_credentials( $account_idx );

		$access_token = isset( $options['access_token'] ) && $options['access_token'] !== ''
			? (string) $options['access_token']
			: $creds['access_token'];

		$template_id = isset( $options['template_id'] ) && $options['template_id'] !== ''
			? (string) $options['template_id']
			: $creds['default_template_id'];

		if ( $access_token === '' || $template_id === '' ) {
			error_log( '[Zalo Hotline] send_outbound aborted — missing access_token or template_id (account_idx=' . $account_idx . ')' );
			return false;
		}

		$phone = $this->normalize_phone( $chat_id, $creds['phone_default'] );
		if ( $phone === '' ) {
			error_log( '[Zalo Hotline] send_outbound aborted — invalid phone (chat_id=' . $chat_id . ')' );
			return false;
		}

		$template_data = ( isset( $options['template_data'] ) && is_array( $options['template_data'] ) )
			? $options['template_data']
			: array( 'content' => $message );

		$payload = array(
			'phone'         => $phone,
			'template_id'   => $template_id,
			'template_data' => $template_data,
		);
		if ( ! empty( $options['tracking_id'] ) ) {
			$payload['tracking_id'] = (string) $options['tracking_id'];
		}

		$response = wp_remote_post( self::ZNS_ENDPOINT_SEND, array(
			'timeout' => 15,
			'headers' => array(
				'access_token' => $access_token,
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[Zalo Hotline] HTTP error: ' . $response->get_error_message() );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		$err  = is_array( $json ) ? (int) ( $json['error'] ?? -1 ) : -1;

		if ( $code !== 200 || $err !== 0 ) {
			error_log( sprintf(
				'[Zalo Hotline] ZNS send failed http=%d zns_error=%s body=%s',
				$code,
				is_array( $json ) ? ( $json['error'] ?? 'n/a' ) : 'n/a',
				substr( $body, 0, 500 )
			) );
			return false;
		}

		do_action( 'bizcity_zalo_hotline_sent', array(
			'phone'       => $phone,
			'template_id' => $template_id,
			'msg_id'      => $json['data']['msg_id'] ?? '',
			'account_idx' => $account_idx,
		) );
		return true;
	}

	/**
	 * Verify access_token by hitting the cheapest authenticated endpoint
	 * (template list, limit 1). Returns array{ok, status, error?, sample?}.
	 * Used by WaicChannelIntegration_zalo_hotline::doTest().
	 */
	public static function verify_credentials( string $access_token ): array {
		if ( $access_token === '' ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'access_token empty' );
		}
		$url = add_query_arg( array( 'offset' => 0, 'limit' => 1 ), self::ZNS_ENDPOINT_LIST );
		$response = wp_remote_get( $url, array(
			'timeout' => 10,
			'headers' => array( 'access_token' => $access_token ),
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		$err  = is_array( $json ) ? (int) ( $json['error'] ?? -1 ) : -1;
		$ok   = ( $code === 200 && $err === 0 );
		return array(
			'ok'     => $ok,
			'status' => $code,
			'error'  => $ok ? '' : ( is_array( $json ) ? (string) ( $json['message'] ?? 'unknown' ) : 'invalid response' ),
			'sample' => $json,
		);
	}

	/**
	 * Resolve credentials from WaicChannelIntegration_zalo_hotline at $account_idx.
	 * Falls back to legacy options if integration not configured.
	 *
	 * @return array{access_token:string, default_template_id:string, phone_default:string, app_id:string}
	 */
	private function resolve_credentials( int $account_idx ): array {
		$out = array(
			'access_token'        => '',
			'default_template_id' => '',
			'phone_default'       => '',
			'app_id'              => '',
		);
		if ( class_exists( 'WaicFrame' ) ) {
			try {
				$model = WaicFrame::_()->getModule( 'workflow' )->getModel( 'integrations' );
				if ( $model && method_exists( $model, 'getIntegration' ) ) {
					$integration = $model->getIntegration( 'zalo_hotline', $account_idx );
					if ( $integration && method_exists( $integration, 'getParam' ) ) {
						$out['access_token']        = (string) $integration->getParam( 'access_token' );
						$out['default_template_id'] = (string) $integration->getParam( 'default_template_id' );
						$out['phone_default']       = (string) $integration->getParam( 'phone_default' );
						$out['app_id']              = (string) $integration->getParam( 'app_id' );
					}
				}
			} catch ( \Throwable $e ) {
				// fall through to legacy
			}
		}
		// Legacy fallbacks (if any prior plugin stored these).
		if ( $out['access_token'] === '' ) {
			$out['access_token'] = (string) get_option( 'bizcity_zns_access_token', '' );
		}
		if ( $out['default_template_id'] === '' ) {
			$out['default_template_id'] = (string) get_option( 'bizcity_zns_default_template_id', '' );
		}
		return $out;
	}

	/**
	 * Normalize a phone string for ZNS:
	 *  - strip `hotline_` prefix
	 *  - strip non-digits
	 *  - if starts with `0`, replace with `84`
	 */
	private function normalize_phone( string $chat_id, string $fallback ): string {
		$raw = $chat_id;
		if ( strpos( $raw, 'hotline_' ) === 0 ) {
			$raw = substr( $raw, strlen( 'hotline_' ) );
		}
		$raw = preg_replace( '/\D+/', '', $raw );
		if ( $raw === '' ) {
			$raw = preg_replace( '/\D+/', '', $fallback );
		}
		if ( $raw === '' ) { return ''; }
		if ( strpos( $raw, '0' ) === 0 ) {
			$raw = '84' . substr( $raw, 1 );
		}
		return $raw;
	}
}

/**
 * Register adapter with the gateway bridge (mirror Sprint 1 T-S1.4 pattern).
 */
add_action( 'bizcity_register_channel', function ( $bridge ) {
	if ( $bridge instanceof BizCity_Gateway_Bridge ) {
		$bridge->register_adapter( new BizCity_Zalo_Hotline_Channel_Adapter() );
	}
}, 5 );

if ( did_action( 'bizcity_register_channel' ) && class_exists( 'BizCity_Gateway_Bridge' ) ) {
	BizCity_Gateway_Bridge::instance()->register_adapter(
		new BizCity_Zalo_Hotline_Channel_Adapter()
	);
}
