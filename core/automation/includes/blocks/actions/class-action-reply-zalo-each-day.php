<?php
/**
 * Action: Reply Zalo Each Day (loop per-day blocks).
 *
 * Nhận danh sách ngày từ {{nX.days_json}} (JSON array), gửi mỗi ngày 1 tin độc lập.
 * Dùng cho Astro workflow khi cần tách phân tích day-by-day thành nhiều message.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-FAA2-TWINBRAIN (2026-07-05)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Reply_Zalo_Each_Day extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.reply_zalo_each_day'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Zalo Mỗi Ngày 1 Tin',
			'short'    => 'reply_zalo_each_day',
			'category' => 'output',
			'color'    => '#0f766e',
			'icon'     => 'calendar',
			// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — default fields for day-by-day loop messaging.
			'defaults' => array(
				'label'            => 'reply_zalo_each_day',
				'days_json'        => '{{n4.days_json}}',
				'header_text'      => '',
				'footer_text'      => '',
				'instance_id'      => '',
				'override_chat_id' => '',
				'max_days'         => 0,
				'stop_on_error'    => false,
			),
			'fields' => array(
				array( 'name' => 'label',            'label' => 'Tên hiển thị',      'type' => 'text' ),
				array( 'name' => 'days_json',        'label' => 'Danh sách ngày JSON', 'type' => 'textarea', 'hint' => '{{n4.days_json}}' ),
				array( 'name' => 'header_text',      'label' => 'Mở đầu (optional)',   'type' => 'textarea', 'hint' => 'Gửi 1 tin trước vòng lặp' ),
				array( 'name' => 'footer_text',      'label' => 'Kết (optional)',      'type' => 'textarea', 'hint' => 'Gửi 1 tin sau vòng lặp' ),
				array( 'name' => 'instance_id',      'label' => 'Zalo Bot',            'type' => 'channel_instance_picker', 'platform' => 'ZALO_BOT' ),
				array( 'name' => 'override_chat_id', 'label' => 'Gửi đến người dùng',  'type' => 'zalo_user_picker' ),
				array( 'name' => 'max_days',         'label' => 'Giới hạn số ngày',    'type' => 'number', 'hint' => '0 = không giới hạn' ),
				array( 'name' => 'stop_on_error',    'label' => 'Dừng nếu lỗi gửi',     'type' => 'toggle' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$chat_id = $this->resolve_chat_id( $ctx, $data );
		if ( $chat_id === '' ) {
			return new WP_Error( 'no_chat_id', 'reply_zalo_each_day: chưa xác định được chat_id.' );
		}
		if ( ! function_exists( 'bizcity_channel_send' ) ) {
			return new WP_Error( 'gateway_missing', 'Channel Gateway sender chưa load.' );
		}

		$days_raw = $this->resolve( $data['days_json'] ?? '[]', $ctx );
		$days     = $this->normalize_days( $days_raw );
		if ( empty( $days ) ) {
			$this->note_event( 'reply_zalo_each_day_skipped', array( 'reason' => 'empty_days' ) );
			return array(
				'sent'       => true,
				'chat_id'    => $chat_id,
				'sent_count' => 0,
				'total_days' => 0,
			);
		}

		$max_days      = max( 0, (int) $this->resolve( $data['max_days'] ?? 0, $ctx ) );
		$stop_on_error = ! empty( $data['stop_on_error'] );
		$sent_count    = 0;
		$errors        = array();

		$header = trim( (string) $this->resolve( $data['header_text'] ?? '', $ctx ) );
		if ( $header !== '' ) {
			$send_header = bizcity_channel_send( $chat_id, $header );
			if ( is_array( $send_header ) && ! empty( $send_header['sent'] ) ) {
				$sent_count++;
			} elseif ( $stop_on_error ) {
				return new WP_Error( 'send_failed', 'reply_zalo_each_day: gửi header thất bại.' );
			}
		}

		$idx = 0;
		foreach ( $days as $day ) {
			if ( $max_days > 0 && $idx >= $max_days ) {
				break;
			}
			$msg  = $this->build_day_message( $day );
			$send = bizcity_channel_send( $chat_id, $msg );
			if ( is_array( $send ) && ! empty( $send['sent'] ) ) {
				$sent_count++;
			} else {
				$errors[] = is_array( $send ) ? (string) ( $send['error'] ?? 'send_failed' ) : 'send_failed';
				if ( $stop_on_error ) {
					return new WP_Error( 'send_failed', 'reply_zalo_each_day: gửi message theo ngày thất bại.' );
				}
			}
			$idx++;
		}

		$footer = trim( (string) $this->resolve( $data['footer_text'] ?? '', $ctx ) );
		if ( $footer !== '' ) {
			$send_footer = bizcity_channel_send( $chat_id, $footer );
			if ( is_array( $send_footer ) && ! empty( $send_footer['sent'] ) ) {
				$sent_count++;
			} elseif ( $stop_on_error ) {
				return new WP_Error( 'send_failed', 'reply_zalo_each_day: gửi footer thất bại.' );
			}
		}

		$this->note_event( 'reply_zalo_each_day_done', array(
			'chat_id'    => mb_substr( $chat_id, 0, 80 ),
			'total_days' => count( $days ),
			'sent_count' => $sent_count,
			'errors'     => count( $errors ),
		) );

		return array(
			'sent'       => true,
			'chat_id'    => $chat_id,
			'sent_count' => $sent_count,
			'total_days' => count( $days ),
			'errors'     => $errors,
		);
	}

	private function resolve_chat_id( array $ctx, array $data ): string {
		$trigger = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
		$chat_id = (string) ( $trigger['chat_id'] ?? $ctx['chat_id'] ?? '' );

		$override_chat_id = trim( (string) $this->resolve( $data['override_chat_id'] ?? '', $ctx ) );
		if ( $override_chat_id !== '' ) {
			$chat_id = $override_chat_id;
		}

		if ( $chat_id === '' ) {
			$chat_id = trim( (string) $this->resolve( $data['chat_id'] ?? '', $ctx ) );
		}
		if ( $chat_id === '' ) {
			$chat_id = trim( (string) ( $ctx['n4']['chat_id'] ?? $ctx['n1']['chat_id'] ?? '' ) );
		}

		if ( $chat_id === '' ) {
			$bot_id  = (string) ( $trigger['account_id'] ?? $trigger['bot_id'] ?? '' );
			$user_id = (string) ( $trigger['user_id'] ?? $trigger['sender_id'] ?? '' );
			if ( $bot_id !== '' && $user_id !== '' ) {
				$chat_id = 'zalobot_' . $bot_id . '_' . $user_id;
			}
		}

		if ( $chat_id === '' ) {
			$instance_id = trim( (string) ( $data['instance_id'] ?? '' ) );
			if ( $instance_id !== '' && $override_chat_id !== '' ) {
				$chat_id = 'zalobot_' . $instance_id . '_' . $override_chat_id;
			}
		}

		return trim( $chat_id );
	}

	private function normalize_days( $days_raw ): array {
		if ( is_array( $days_raw ) ) {
			return $days_raw;
		}
		if ( ! is_string( $days_raw ) || $days_raw === '' ) {
			return array();
		}
		$decoded = json_decode( $days_raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return $decoded;
	}

	private function build_day_message( array $day ): string {
		$date_label = (string) ( $day['date_label'] ?? $day['date'] ?? '' );
		$day_url    = trim( (string) ( $day['day_url'] ?? '' ) );
		$retro      = trim( (string) ( $day['retrograde'] ?? '' ) );
		$analysis   = trim( (string) ( $day['analysis'] ?? '' ) );
		$score      = isset( $day['score'] ) ? (float) $day['score'] : 0.0;
		$day_index  = max( 1, (int) ( $day['day_index'] ?? 1 ) );
		$day_total  = max( $day_index, (int) ( $day['day_total'] ?? $day_index ) );
		$aspects    = array();
		foreach ( (array) ( $day['aspects'] ?? array() ) as $line ) {
			$line = trim( (string) $line );
			if ( $line !== '' ) {
				$aspects[] = $line;
			}
		}

		$lines   = array();
		$lines[] = '📅 Ngày ' . $day_index . '/' . $day_total . ' — ' . ( $date_label !== '' ? $date_label : 'Trong ngày' );
		if ( $score > 0 ) {
			$lines[] = '📈 Độ thuận lợi: ' . round( $score, 2 );
		}
		if ( $retro !== '' ) {
			$lines[] = '℞ Nghịch hành: ' . $retro;
		}
		if ( ! empty( $aspects ) ) {
			$lines[] = 'Aspects chính:';
			foreach ( array_slice( $aspects, 0, 4 ) as $asp ) {
				$lines[] = '- ' . $asp;
			}
		} else {
			$lines[] = '- Không có aspect nổi bật.';
		}
		if ( $analysis !== '' ) {
			$lines[] = '🔮 Luận giải:';
			$lines[] = $analysis;
		}
		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — plain URL for Zalo (no HTML anchor support).
		if ( $day_url !== '' ) {
			$lines[] = '🔗 Link ngày: ' . $day_url;
		}

		return implode( "\n", $lines );
	}
}
