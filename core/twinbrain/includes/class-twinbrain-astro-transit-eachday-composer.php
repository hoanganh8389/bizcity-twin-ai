<?php
/**
 * TwinBrain Astro Transit Each-Day Composer.
 *
 * Shared composer for both Web Astro runtime and Automation Transit action.
 * Produces unified each-day analysis + final recommendation.
 *
 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — unify each-day transit compose.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Astro_Transit_Eachday_Composer {

	const PURPOSE                  = 'twinbrain_astro_eachday_compose';
	// [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — keep full foreach but
	// shorten each day to reduce latency/token pressure.
	const TARGET_TOKENS_PER_DAY    = 260;
	// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A15 — increase narrative breadth for each-day analysis (+50%).
	const LLM_TEMPERATURE          = 1.08;
	const LLM_TIMEOUT_S            = 90;
	const LLM_MAX_TOKENS_CAP       = 8000;
	const LLM_MIN_TOKENS           = 1800;

	private static $instance = null;

	/**
	 * @return BizCity_TwinBrain_Astro_Transit_Eachday_Composer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Compose unified each-day content.
	 *
	 * @param array $rows day rows from runtime/automation.
	 * @param array $opts query/surface/period_label/source_url/target_tokens_per_day.
	 * @return array
	 */
	public function compose( array $rows, array $opts = array() ): array {
		$query      = (string) ( $opts['query'] ?? '' );
		$surface    = (string) ( $opts['surface'] ?? 'unknown' );
		$source_url = (string) ( $opts['source_url'] ?? '' );

		$normalized = $this->normalize_rows( $rows, $query );
		if ( empty( $normalized ) ) {
			return array(
				'success'              => false,
				'source'               => 'empty',
				'transit_foreach_md'   => '',
				'best_day'             => '',
				'best_day_score'       => 0,
				'final_recommendation' => '',
				'day_messages'         => array(),
				'metrics'              => array(),
			);
		}

		$best    = $this->resolve_best_day( $normalized );
		$metrics = $this->collect_metrics( $normalized );

		$llm_result = $this->compose_with_llm( $normalized, $best, $metrics, $opts );
		if ( ! empty( $llm_result['success'] ) ) {
			$day_messages = $this->build_day_messages_from_llm_payload(
				$normalized,
				(array) ( $llm_result['payload']['days'] ?? array() ),
				$metrics
			);

			$md = $this->build_markdown_from_llm_payload(
				$normalized,
				$llm_result['payload'],
				$metrics,
				$best,
				$source_url,
				$surface
			);

			return array(
				'success'              => true,
				'source'               => 'llm',
				'transit_foreach_md'   => $md,
				'best_day'             => (string) ( $llm_result['payload']['best_day'] ?? $best['date'] ),
				'best_day_score'       => (float) ( $best['score'] ?? 0 ),
				'final_recommendation' => (string) ( $llm_result['payload']['strategy'] ?? '' ),
				'day_messages'         => $day_messages,
				'metrics'              => $metrics,
				'model'                => (string) ( $llm_result['model'] ?? '' ),
				'tokens'               => (int) ( $llm_result['tokens'] ?? 0 ),
			);
		}

		$det = $this->compose_deterministic( $normalized, $best, $metrics, $source_url );
		$det['source'] = 'deterministic';
		$det['success'] = true;
		return $det;
	}

	private function compose_with_llm( array $rows, array $best, array $metrics, array $opts ): array {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array( 'success' => false, 'error' => 'llm_client_missing' );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return array( 'success' => false, 'error' => 'llm_not_ready' );
		}

		$target_per_day = (int) ( $opts['target_tokens_per_day'] ?? self::TARGET_TOKENS_PER_DAY );
		if ( $target_per_day <= 0 ) {
			$target_per_day = self::TARGET_TOKENS_PER_DAY;
		}

		$days_count = count( $rows );
		$max_tokens = $days_count * $target_per_day + 700;
		$max_tokens = max( self::LLM_MIN_TOKENS, min( self::LLM_MAX_TOKENS_CAP, $max_tokens ) );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->build_llm_system_prompt( $days_count ),
			),
			array(
				'role'    => 'user',
				'content' => $this->build_llm_user_prompt( $rows, $best, $metrics, $opts ),
			),
		);

		try {
			$resp = $llm->chat( $messages, array(
				'purpose'     => self::PURPOSE,
				'temperature' => self::LLM_TEMPERATURE,
				'max_tokens'  => $max_tokens,
				'timeout'     => self::LLM_TIMEOUT_S,
			) );
		} catch ( Exception $e ) {
			return array( 'success' => false, 'error' => $e->getMessage() );
		}

		if ( empty( $resp['success'] ) ) {
			return array( 'success' => false, 'error' => (string) ( $resp['error'] ?? 'llm_failed' ) );
		}

		$payload = $this->parse_llm_json( (string) ( $resp['message'] ?? '' ) );
		if ( ! is_array( $payload ) || empty( $payload['days'] ) || ! is_array( $payload['days'] ) ) {
			return array( 'success' => false, 'error' => 'llm_json_invalid' );
		}

		return array(
			'success' => true,
			'payload' => $payload,
			'model'   => (string) ( $resp['model'] ?? '' ),
			'tokens'  => (int) ( $resp['usage']['total_tokens'] ?? 0 ),
		);
	}

	private function build_llm_system_prompt( int $days_count ): string {
		return "Bạn là chuyên gia luận giải TRANSIT chi tiết theo từng ngày. Trả về JSON thuần.\n"
			// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — per-day evidence contract:
			// each day must cite concrete transit grounds (favorable/challenging/retro/slow).
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A15 — widen per-day explanation depth (+50%).
			. "Mục tiêu cứng: mỗi ngày viết NGẮN GỌN 300-480 token, súc tích, có căn cứ transit rõ ràng, có rủi ro/cơ hội/hành động.\n"
			. "Không được bỏ sót ngày. Tổng ngày bắt buộc phân tích: {$days_count}.\n"
			. "Bắt buộc mỗi ngày có CĂN CỨ gồm 2-4 transit cụ thể; ưu tiên nêu rõ nhóm thuận lợi, thử thách, nghịch hành và sao chậm nếu có.\n"
			. "Không dùng câu mơ hồ kiểu chung chung; mọi nhận định phải bám evidence đã cho.\n"
			. "JSON schema bắt buộc:\n"
			. "{\n"
			. "  \"days\": [\n"
			. "    {\"date\":\"YYYY-MM-DD\",\"analysis\":\"...\",\"evidence\":[\"...\",\"...\"],\"risk\":\"...\",\"opportunity\":\"...\",\"action\":\"...\"}\n"
			. "  ],\n"
			. "  \"best_day\":\"YYYY-MM-DD\",\n"
			. "  \"strategy\":\"...\"\n"
			. "}\n"
			. "Quy tắc: dùng đúng dữ liệu đầu vào, không bịa aspect. KHÔNG nhắc metric kỹ thuật (good/bad/topic/retro/aspects) trong nội dung cho người dùng.";
	}

	private function build_llm_user_prompt( array $rows, array $best, array $metrics, array $opts ): string {
		$payload = array(
			'surface'      => (string) ( $opts['surface'] ?? 'unknown' ),
			'query'        => (string) ( $opts['query'] ?? '' ),
			'period_label' => (string) ( $opts['period_label'] ?? '' ),
			'best_hint'    => $best,
			'metrics'      => $metrics,
			'days'         => $rows,
		);

		return "Dữ liệu transit theo ngày (JSON):\n"
			. (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE )
			. "\nHãy trả JSON đúng schema ở system prompt.";
	}

	private function parse_llm_json( string $raw ) {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return null;
		}

		$raw = (string) preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = (string) preg_replace( '/\s*```$/', '', trim( $raw ) );

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$_start = strpos( $raw, '{' );
		$_end   = strrpos( $raw, '}' );
		if ( $_start !== false && $_end !== false && $_end > $_start ) {
			$decoded = json_decode( substr( $raw, $_start, $_end - $_start + 1 ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	private function compose_deterministic( array $rows, array $best, array $metrics, string $source_url ): array {
		$lines = array(
			'## TRANSIT EACH DAY MESSAGE',
		);

		$day_messages = array();
		foreach ( $rows as $row ) {
			$date       = (string) ( $row['date'] ?? '' );
			$date_label = (string) ( $row['date_label'] ?? $date );
			$total_aspects = (int) ( $row['total_aspects'] ?? $row['aspects_count'] ?? 0 );
			$favorable_count = (int) ( $row['favorable_count'] ?? $row['good_hits'] ?? 0 );
			$challenging_count = (int) ( $row['challenging_count'] ?? $row['bad_hits'] ?? 0 );
			$retro_count = (int) ( $row['retrograde_count'] ?? $row['retro_count'] ?? 0 );
			$slow_count = (int) ( $row['slow_planet_count'] ?? 0 );
			$top = isset( $row['top_aspects'] ) && is_array( $row['top_aspects'] )
				? array_values( $row['top_aspects'] )
				: array();
			$favorable = isset( $row['favorable_aspects'] ) && is_array( $row['favorable_aspects'] )
				? array_values( $row['favorable_aspects'] )
				: array();
			$challenging = isset( $row['challenging_aspects'] ) && is_array( $row['challenging_aspects'] )
				? array_values( $row['challenging_aspects'] )
				: array();
			$slow = isset( $row['slow_planet_aspects'] ) && is_array( $row['slow_planet_aspects'] )
				? array_values( $row['slow_planet_aspects'] )
				: array();
			if ( empty( $favorable ) && ! empty( $top ) ) {
				$favorable = array_slice( $top, 0, 2 );
			}

			$day_lines = array(
				'### ' . $date_label,
				'- Căn cứ tổng quan: ' . $total_aspects
					. ' góc chiếu; thuận lợi=' . $favorable_count
					. '; thử thách=' . $challenging_count
					. '; nghịch hành=' . $retro_count
					. '; sao chậm=' . $slow_count . '.',
			);
			if ( ! empty( $favorable ) ) {
				$day_lines[] = '- Căn cứ thuận lợi: ' . implode( '; ', array_slice( $favorable, 0, 3 ) );
			}
			if ( ! empty( $challenging ) ) {
				$day_lines[] = '- Căn cứ thử thách: ' . implode( '; ', array_slice( $challenging, 0, 3 ) );
			}
			if ( ! empty( $slow ) ) {
				$day_lines[] = '- Căn cứ sao chậm: ' . implode( '; ', array_slice( $slow, 0, 3 ) );
			}
			if ( empty( $favorable ) && empty( $challenging ) && ! empty( $top ) ) {
				$day_lines[] = '- Căn cứ transit: ' . implode( '; ', array_slice( $top, 0, 3 ) );
			}
			$day_lines[] = '- Rủi ro: ' . ( $challenging_count > 0
				? 'khả năng phát sinh ma sát ở các điểm có aspect thử thách; tránh quyết định nóng vội.'
				: 'mức rủi ro trung tính, ưu tiên giữ nhịp ổn định và kiểm tra điều kiện trước khi chốt.'
			);
			$day_lines[] = '- Cơ hội: ' . ( $favorable_count > 0
				? 'có cửa mở để đẩy các việc quan trọng nhờ cụm transit thuận lợi.'
				: 'cơ hội đến từ sự chuẩn bị và tối ưu quy trình thay vì bứt tốc mạnh.'
			);
			$day_lines[] = '- Hành động: chốt 1-2 ưu tiên, bám evidence transit trong ngày và cập nhật theo phản hồi thực tế.';

			if ( ! empty( $row['day_url'] ) ) {
				$day_lines[] = '- Link ngày: ' . (string) $row['day_url'];
			}

			$lines = array_merge( $lines, $day_lines );
			$day_messages[ $date ] = implode( "\n", $day_lines );
		}

		$best_day   = (string) ( $best['date'] ?? '' );
		$best_score = (float) ( $best['score'] ?? 0 );

		$final_recommendation = $best_day !== ''
			? 'Ngày nên ưu tiên cho việc quan trọng là ' . $best_day . ' (score=' . round( $best_score, 2 ) . '). Chiến lược: ưu tiên quyết định lớn vào ngày này, các ngày còn lại dùng để chuẩn bị dữ liệu và giảm rủi ro.'
			: 'Chưa đủ dữ liệu để chốt best_day chắc chắn.';

		$lines[] = '## KẾT LUẬN CUỐI';
		$lines[] = '- ' . $final_recommendation;

		return array(
			'transit_foreach_md'   => implode( "\n", $lines ),
			'best_day'             => $best_day,
			'best_day_score'       => $best_score,
			'final_recommendation' => $final_recommendation,
			'day_messages'         => $day_messages,
			'metrics'              => $metrics,
		);
	}

	private function build_markdown_from_llm_payload( array $rows, array $payload, array $metrics, array $best, string $source_url, string $surface ): string {
		$days_payload = isset( $payload['days'] ) && is_array( $payload['days'] ) ? $payload['days'] : array();
		$map = array();
		foreach ( $days_payload as $d ) {
			if ( ! is_array( $d ) ) { continue; }
			$date = (string) ( $d['date'] ?? '' );
			if ( $date === '' ) { continue; }
			$map[ $date ] = $d;
		}

		$lines = array( '## TRANSIT EACH DAY MESSAGE' );

		foreach ( $rows as $r ) {
			$date = (string) ( $r['date'] ?? '' );
			if ( $date === '' ) { continue; }
			$row_payload = isset( $map[ $date ] ) && is_array( $map[ $date ] ) ? $map[ $date ] : array();
			$date_label  = (string) ( $r['date_label'] ?? $date );

			$lines[] = '### ' . $date_label;
			$lines[] = $this->shorten_text(
				(string) ( $row_payload['analysis'] ?? 'Không có phân tích chi tiết từ LLM cho ngày này.' ),
				1100
			);

			$evidence = isset( $row_payload['evidence'] ) && is_array( $row_payload['evidence'] )
				? array_values( array_filter( array_map( 'strval', $row_payload['evidence'] ) ) )
				: array();
			if ( ! empty( $evidence ) ) {
				$lines[] = '- Căn cứ trọng tâm: ' . implode( '; ', array_slice( $evidence, 0, 4 ) );
			}
			if ( ! empty( $row_payload['risk'] ) ) {
				$lines[] = '- Rủi ro: ' . (string) $row_payload['risk'];
			}
			if ( ! empty( $row_payload['opportunity'] ) ) {
				$lines[] = '- Cơ hội: ' . (string) $row_payload['opportunity'];
			}
			if ( ! empty( $row_payload['action'] ) ) {
				$lines[] = '- Hành động: ' . (string) $row_payload['action'];
			}

			if ( ! empty( $r['day_url'] ) ) {
				$lines[] = '- Link ngày: ' . (string) $r['day_url'];
			}
		}

		$best_day = (string) ( $payload['best_day'] ?? $best['date'] ?? '' );
		$strategy = (string) ( $payload['strategy'] ?? '' );
		$lines[] = '## KẾT LUẬN CUỐI';
		if ( $best_day !== '' ) {
			$lines[] = '- best_day: ' . $best_day;
		}
		if ( $strategy !== '' ) {
			$lines[] = '- chiến lược: ' . $strategy;
		}

		return implode( "\n", $lines );
	}

	private function build_day_messages_from_llm_payload( array $rows, array $days_payload, array $metrics ): array {
		$by_date = array();
		foreach ( $days_payload as $d ) {
			if ( ! is_array( $d ) ) { continue; }
			$date = (string) ( $d['date'] ?? '' );
			if ( $date === '' ) { continue; }
			$by_date[ $date ] = $d;
		}

		$out = array();
		foreach ( $rows as $r ) {
			$date = (string) ( $r['date'] ?? '' );
			if ( $date === '' ) { continue; }
			$d = isset( $by_date[ $date ] ) ? $by_date[ $date ] : array();

			$msg = array();
			$msg[] = '🔮 Luận giải chi tiết ngày ' . (string) ( $r['date_label'] ?? $date );
			$msg[] = $this->shorten_text( (string) ( $d['analysis'] ?? '' ), 700 );
			if ( ! empty( $d['risk'] ) ) {
				$msg[] = '⚠️ Rủi ro: ' . $this->shorten_text( (string) $d['risk'], 220 );
			}
			if ( ! empty( $d['opportunity'] ) ) {
				$msg[] = '✅ Cơ hội: ' . $this->shorten_text( (string) $d['opportunity'], 220 );
			}
			if ( ! empty( $d['action'] ) ) {
				$msg[] = '🧭 Hành động: ' . $this->shorten_text( (string) $d['action'], 220 );
			}

			$evidence = isset( $d['evidence'] ) && is_array( $d['evidence'] )
				? array_values( array_filter( array_map( 'strval', $d['evidence'] ) ) )
				: array();
			if ( ! empty( $evidence ) ) {
				$msg[] = '🧾 Căn cứ: ' . implode( '; ', array_slice( $evidence, 0, 4 ) );
			}

			if ( ! empty( $r['day_url'] ) ) {
				$msg[] = '🔗 Link ngày: ' . (string) $r['day_url'];
			}

			$out[ $date ] = trim( implode( "\n", array_filter( $msg ) ) );
		}

		return $out;
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — cap verbose fragments to
	 * keep each day concise while preserving full day loop.
	 */
	private function shorten_text( string $text, int $max_chars ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( $text === '' || $max_chars <= 0 ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) <= $max_chars ) {
				return $text;
			}
			return rtrim( mb_substr( $text, 0, max( 0, $max_chars - 1 ) ) ) . '…';
		}
		if ( strlen( $text ) <= $max_chars ) {
			return $text;
		}
		return rtrim( substr( $text, 0, max( 0, $max_chars - 1 ) ) ) . '...';
	}

	private function build_metrics_explain_lines( array $rows, array $metrics, string $source_url ): array {
		$lines = array(
			'## GIẢI THÍCH METRICS (VÌ SAO CÓ THỂ GIỐNG NHAU)',
		);
		if ( $source_url !== '' ) {
			$lines[] = '- Nguồn transit tổng quan: ' . $source_url;
		}

		if ( ! empty( $metrics['same_counts_across_days'] ) ) {
			$lines[] = '- good/bad/topic/retro/aspects đang trùng giữa các ngày vì các chỉ số này là thống kê theo LOẠI tín hiệu (số lượng), không phản ánh toàn bộ khác biệt về orb và tổ hợp aspect.';
			$lines[] = '- Khác biệt thực tế cần xem thêm: top aspects theo orb từng ngày, signature aspect từng ngày, và diễn giải rủi ro/cơ hội theo ngữ cảnh.';
		}

		foreach ( $rows as $r ) {
			$lines[] = '- ' . (string) ( $r['date'] ?? '' )
				. ': good=' . (int) $r['good_hits']
				. ', bad=' . (int) $r['bad_hits']
				. ', topic=' . (int) $r['topic_hits']
				. ', retro=' . (int) $r['retro_count']
				. ', aspects=' . (int) $r['aspects_count']
				. ', signature=' . (string) ( $r['metrics_signature'] ?? '' );
		}

		return $lines;
	}

	private function resolve_best_day( array $rows ): array {
		$best = array( 'date' => '', 'score' => -9999.0 );
		foreach ( $rows as $r ) {
			$score = (float) ( $r['score'] ?? 0 );
			$date  = (string) ( $r['date'] ?? '' );
			if ( $date === '' ) { continue; }
			if ( $score > (float) $best['score'] ) {
				$best['score'] = $score;
				$best['date']  = $date;
			}
		}
		if ( $best['score'] < -9000 ) {
			$best['score'] = 0;
		}
		return $best;
	}

	private function collect_metrics( array $rows ): array {
		$keys = array( 'good_hits', 'bad_hits', 'topic_hits', 'retro_count', 'aspects_count' );
		$same = true;
		if ( count( $rows ) <= 1 ) {
			$same = false;
		}
		if ( $same ) {
			$first = $rows[0];
			foreach ( $rows as $r ) {
				foreach ( $keys as $k ) {
					if ( (int) ( $r[ $k ] ?? 0 ) !== (int) ( $first[ $k ] ?? 0 ) ) {
						$same = false;
						break 2;
					}
				}
			}
		}

		$agg = array();
		foreach ( $keys as $k ) {
			$agg[ $k ] = array();
			foreach ( $rows as $r ) {
				$agg[ $k ][] = (int) ( $r[ $k ] ?? 0 );
			}
		}

		return array(
			'same_counts_across_days' => $same,
			'values'                  => $agg,
		);
	}

	private function normalize_rows( array $rows, string $query ): array {
		$topics = $this->infer_topics_from_query( $query );
		$out    = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) { continue; }
			$date = (string) ( $r['date'] ?? '' );
			if ( $date === '' ) { continue; }

			$aspects = $this->extract_aspects_from_row( $r );
			$sorted_aspects = array_values( $aspects );
			usort( $sorted_aspects, function ( $a, $b ) {
				$orb_a = $this->extract_orb_from_line( (string) $a );
				$orb_b = $this->extract_orb_from_line( (string) $b );
				if ( $orb_a === $orb_b ) {
					return strcmp( (string) $a, (string) $b );
				}
				return ( $orb_a < $orb_b ) ? -1 : 1;
			} );
			$retro_count = isset( $r['retro_count'] )
				? (int) $r['retro_count']
				: $this->count_retro_from_text( (string) ( $r['retrograde'] ?? '' ) );

			$good_hits = isset( $r['good_hits'] ) ? (int) $r['good_hits'] : 0;
			$bad_hits  = isset( $r['bad_hits'] ) ? (int) $r['bad_hits'] : 0;
			$topic_hits= isset( $r['topic_hits'] ) ? (int) $r['topic_hits'] : 0;
			if ( ! isset( $r['good_hits'] ) || ! isset( $r['bad_hits'] ) ) {
				$good_hits = 0;
				$bad_hits  = 0;
				$topic_hits= 0;
				foreach ( $aspects as $line ) {
					$delta = $this->score_aspect_line( $line );
					if ( $delta >= 0 ) {
						$good_hits++;
					} else {
						$bad_hits++;
					}
					if ( $this->line_matches_topics( $line, $topics ) ) {
						$topic_hits++;
					}
				}
			}

			$favorable_aspects = isset( $r['favorable_aspects'] ) && is_array( $r['favorable_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['favorable_aspects'] ) ) )
				: array();
			$challenging_aspects = isset( $r['challenging_aspects'] ) && is_array( $r['challenging_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['challenging_aspects'] ) ) )
				: array();
			$slow_planet_aspects = isset( $r['slow_planet_aspects'] ) && is_array( $r['slow_planet_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['slow_planet_aspects'] ) ) )
				: array();
			$slow_planets = isset( $r['slow_planets'] ) && is_array( $r['slow_planets'] )
				? array_values( array_filter( array_map( 'strval', $r['slow_planets'] ) ) )
				: array();
			if ( empty( $favorable_aspects ) || empty( $challenging_aspects ) || empty( $slow_planet_aspects ) ) {
				foreach ( $sorted_aspects as $line ) {
					$delta = $this->score_aspect_line( (string) $line );
					if ( $delta >= 0 ) {
						$favorable_aspects[] = (string) $line;
					} else {
						$challenging_aspects[] = (string) $line;
					}
					if ( $this->is_slow_planet_line( (string) $line ) ) {
						$slow_planet_aspects[] = (string) $line;
						$p = $this->extract_transit_planet_from_line( (string) $line );
						if ( $p !== '' ) {
							$slow_planets[] = $p;
						}
					}
				}
			}
			$favorable_aspects = array_values( array_unique( $favorable_aspects ) );
			$challenging_aspects = array_values( array_unique( $challenging_aspects ) );
			$slow_planet_aspects = array_values( array_unique( $slow_planet_aspects ) );
			$slow_planets = array_values( array_unique( $slow_planets ) );

			$total_aspects = isset( $r['total_aspects'] ) ? (int) $r['total_aspects'] : count( $aspects );
			$favorable_count = isset( $r['favorable_count'] ) ? (int) $r['favorable_count'] : $good_hits;
			$challenging_count = isset( $r['challenging_count'] ) ? (int) $r['challenging_count'] : $bad_hits;
			$retrograde_count = isset( $r['retrograde_count'] ) ? (int) $r['retrograde_count'] : $retro_count;
			$slow_planet_count = isset( $r['slow_planet_count'] ) ? (int) $r['slow_planet_count'] : count( $slow_planet_aspects );

			$score = isset( $r['score'] ) ? (float) $r['score'] : 50.0;
			if ( ! isset( $r['score'] ) ) {
				foreach ( $aspects as $line ) {
					$score += $this->score_aspect_line( $line );
				}
				$score -= (float) ( $retro_count * 2 );
			}

			$out[] = array(
				'date'            => $date,
				'date_label'      => (string) ( $r['date_label'] ?? $date ),
				'day_url'         => (string) ( $r['day_url'] ?? '' ),
				'aspects'         => $aspects,
				'top_aspects'     => array_slice( $sorted_aspects, 0, 3 ),
				'total_aspects'   => $total_aspects,
				'favorable_count' => $favorable_count,
				'challenging_count' => $challenging_count,
				'retrograde_count' => $retrograde_count,
				'slow_planet_count' => $slow_planet_count,
				'favorable_aspects' => array_slice( $favorable_aspects, 0, 3 ),
				'challenging_aspects' => array_slice( $challenging_aspects, 0, 3 ),
				'slow_planet_aspects' => array_slice( $slow_planet_aspects, 0, 3 ),
				'slow_planets'    => $slow_planets,
				'good_hits'       => $good_hits,
				'bad_hits'        => $bad_hits,
				'topic_hits'      => $topic_hits,
				'retro_count'     => $retrograde_count,
				'aspects_count'   => isset( $r['aspects_count'] ) ? (int) $r['aspects_count'] : count( $aspects ),
				'score'           => round( $score, 2 ),
				'metrics_signature'=> substr( md5( implode( '|', $aspects ) ), 0, 10 ),
			);
		}

		usort( $out, function ( $a, $b ) {
			$da = (string) ( $a['date'] ?? '' );
			$db = (string) ( $b['date'] ?? '' );
			return strcmp( $da, $db );
		} );

		return $out;
	}

	private function infer_topics_from_query( string $query ): array {
		$q = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$map = array(
			'su nghiep' => array( 'sự nghiệp', 'cong viec', 'công việc', 'thăng tiến', 'mục tiêu' ),
			'tai chinh' => array( 'tài chính', 'tai chinh', 'tiền', 'đầu tư' ),
			'tinh cam'  => array( 'tình cảm', 'yeu', 'yêu', 'quan hệ', 'hôn nhân' ),
			'suc khoe'  => array( 'sức khỏe', 'suc khoe', 'năng lượng', 'stress' ),
		);

		$out = array();
		foreach ( $map as $k => $needles ) {
			foreach ( $needles as $needle ) {
				if ( strpos( $q, $needle ) !== false ) {
					$out[] = $k;
					break;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function extract_aspects_from_row( array $row ): array {
		$aspects = array();
		if ( isset( $row['aspects'] ) && is_array( $row['aspects'] ) ) {
			foreach ( $row['aspects'] as $line ) {
				$line = trim( (string) $line );
				if ( $line !== '' ) {
					$aspects[] = $line;
				}
			}
		}
		if ( empty( $aspects ) && isset( $row['top_aspects'] ) && is_array( $row['top_aspects'] ) ) {
			foreach ( $row['top_aspects'] as $line ) {
				$line = trim( (string) $line );
				if ( $line !== '' ) {
					$aspects[] = $line;
				}
			}
		}
		return array_values( array_unique( $aspects ) );
	}

	private function count_retro_from_text( string $retro ): int {
		$retro = trim( $retro );
		if ( $retro === '' ) {
			return 0;
		}
		return count( array_filter( array_map( 'trim', explode( ',', $retro ) ) ) );
	}

	private function line_matches_topics( string $line, array $topics ): bool {
		if ( empty( $topics ) ) {
			return false;
		}
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
		$topic_map = array(
			'su nghiep' => array( 'saturn', 'jupiter', 'true node', 'mean node', 'pluto' ),
			'tai chinh' => array( 'venus', 'jupiter', 'saturn' ),
			'tinh cam'  => array( 'venus', 'moon', 'mars' ),
			'suc khoe'  => array( 'mars', 'saturn', 'chiron', 'neptune' ),
		);

		foreach ( $topics as $t ) {
			$needles = isset( $topic_map[ $t ] ) ? $topic_map[ $t ] : array();
			foreach ( $needles as $n ) {
				if ( strpos( $lc, $n ) !== false ) {
					return true;
				}
			}
		}
		return false;
	}

	private function score_aspect_line( string $line ): float {
		$weights = array(
			'tam hợp'      => 6,
			'trine'        => 6,
			'lục hợp'      => 4,
			'sextile'      => 4,
			'hợp'          => 2,
			'conjunction'  => 2,
			'đối'          => -5,
			'opposition'   => -5,
			'vuông'        => -6,
			'square'       => -6,
			'quincunx'     => -3,
			'bất điều hòa' => -3,
		);

		$line_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
		$base    = 0.0;
		foreach ( $weights as $k => $w ) {
			if ( strpos( $line_lc, $k ) !== false ) {
				$base = (float) $w;
				break;
			}
		}

		$orb = 2.0;
		if ( preg_match( '/\((\d+(?:\.\d+)?)°\)/u', $line, $m ) ) {
			$orb = (float) $m[1];
		}
		$orb_factor = max( 0.2, ( 4.0 - min( 4.0, $orb ) ) / 4.0 );
		return $base * $orb_factor;
	}

	/**
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — orb parser for aspect sort.
	 */
	private function extract_orb_from_line( string $line ): float {
		if ( preg_match( '/\((\d+(?:\.\d+)?)°\)/u', $line, $m ) ) {
			return (float) $m[1];
		}
		return 99.0;
	}

	/**
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — extract transit planet from line.
	 */
	private function extract_transit_planet_from_line( string $line ): string {
		$line_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
		if ( preg_match( '/transit\s+([^\s]+(?:\s+[^\s]+)?)\s+(tam hợp|trine|lục hợp|sextile|hợp|conjunction|đối|opposition|vuông|square|quincunx|semi(?:-|\s)?sextile|bất điều hòa)/u', $line_lc, $m ) ) {
			$planet = trim( (string) $m[1] );
			$planet = preg_replace( '/\s+/u', ' ', $planet );
			return is_string( $planet ) ? $planet : '';
		}

		$planet_alias = array(
			'jupiter',
			'saturn',
			'uranus',
			'neptune',
			'pluto',
			'sao mộc',
			'sao thổ',
			'sao thiên vương',
			'sao hải vương',
			'sao diêm vương',
		);
		foreach ( $planet_alias as $alias ) {
			if ( strpos( $line_lc, $alias ) !== false ) {
				return $alias;
			}
		}

		return '';
	}

	/**
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — slow-planet line classifier.
	 */
	private function is_slow_planet_line( string $line ): bool {
		$planet = $this->extract_transit_planet_from_line( $line );
		if ( $planet === '' ) {
			return false;
		}

		return in_array( $planet, array(
			'jupiter',
			'saturn',
			'uranus',
			'neptune',
			'pluto',
			'sao mộc',
			'sao thổ',
			'sao thiên vương',
			'sao hải vương',
			'sao diêm vương',
		), true );
	}
}
