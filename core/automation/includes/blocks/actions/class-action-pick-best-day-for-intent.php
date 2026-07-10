<?php
/**
 * Action: Pick Best Day For Intent (deterministic scoring).
 *
 * Chấm điểm từng ngày dựa trên aspects + orb + retrograde + topic ưu tiên,
 * sau đó trả ranking và ngày tốt nhất để dùng cho kết luận cuối.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-FAA2-TWINBRAIN (2026-07-05)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Pick_Best_Day_For_Intent extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.pick_best_day_for_intent'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Chọn Ngày Tốt Nhất',
			'short'    => 'pick_best_day_for_intent',
			'category' => 'astro',
			'color'    => '#4c1d95',
			'icon'     => 'trophy',
			// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — deterministic best-day selector defaults.
			'defaults' => array(
				'label'              => 'pick_best_day_for_intent',
				'days_json'          => '{{n4.days_json}}',
				'topics'             => '{{n1.topics}}',
				'question'           => '{{trigger.text}}',
				'top_k'              => 3,
				'retrograde_penalty' => 2,
			),
			'fields'   => array(
				array( 'name' => 'label',              'label' => 'Tên hiển thị',         'type' => 'text' ),
				array( 'name' => 'days_json',          'label' => 'Danh sách ngày JSON',   'type' => 'textarea', 'hint' => '{{n4.days_json}}' ),
				array( 'name' => 'topics',             'label' => 'Topics ưu tiên',        'type' => 'text',     'hint' => '{{n1.topics}} hoặc career,finance,love' ),
				array( 'name' => 'question',           'label' => 'Câu hỏi gốc',           'type' => 'textarea', 'hint' => '{{trigger.text}}' ),
				array( 'name' => 'top_k',              'label' => 'Số ngày top',           'type' => 'number' ),
				array( 'name' => 'retrograde_penalty', 'label' => 'Trừ điểm mỗi sao ℞',    'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$days_raw   = $this->resolve( $data['days_json'] ?? '[]', $ctx );
		$days       = $this->normalize_days( $days_raw );
		$question   = (string) $this->resolve( $data['question'] ?? '', $ctx );
		$topics_raw = $this->resolve( $data['topics'] ?? '', $ctx );
		$topics     = $this->normalize_topics( $topics_raw, $question );
		$top_k      = max( 1, (int) $this->resolve( $data['top_k'] ?? 3, $ctx ) );
		$retro_pen  = max( 0, (int) $this->resolve( $data['retrograde_penalty'] ?? 2, $ctx ) );

		if ( empty( $days ) ) {
			return array(
				'ok'              => false,
				'topics'          => $topics,
				'best_day'        => '',
				'best_day_label'  => '',
				'best_score'      => 0,
				'ranking_json'    => '[]',
				'ranking_md'      => '- Chưa có dữ liệu ngày để chấm điểm.',
				'explanation'     => 'Chưa có dữ liệu ngày.',
			);
		}

		$rows = array();
		foreach ( $days as $day ) {
			$rows[] = $this->score_day( $day, $topics, $retro_pen );
		}

		usort( $rows, array( $this, 'sort_rows' ) );
		$best = $rows[0];
		$top  = array_slice( $rows, 0, $top_k );

		$ranking_md = $this->build_ranking_md( $top, $topics );
		$explain    = 'Ngày phù hợp nhất theo điểm deterministic cho chủ đề ' . implode( ',', $topics ) . ': ' . (string) $best['date_label'];

		$this->note_event( 'pick_best_day_done', array(
			'topics'      => $topics,
			'best_day'    => (string) $best['date'],
			'best_score'  => (float) $best['score'],
			'rows_count'  => count( $rows ),
		) );

		return array(
			'ok'              => true,
			'topics'          => $topics,
			'best_day'        => (string) $best['date'],
			'best_day_label'  => (string) $best['date_label'],
			'best_score'      => (float) $best['score'],
			'best_reason'     => (string) $best['reason'],
			'ranking_json'    => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ),
			'ranking_md'      => $ranking_md,
			'explanation'     => $explain,
		);
	}

	private function normalize_days( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function normalize_topics( $raw, string $question ): array {
		$topics = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $t ) {
				$t = trim( strtolower( (string) $t ) );
				if ( $t !== '' ) { $topics[] = $t; }
			}
		} elseif ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $t ) {
					$t = trim( strtolower( (string) $t ) );
					if ( $t !== '' ) { $topics[] = $t; }
				}
			} else {
				$parts = preg_split( '/[,|]/', $raw );
				foreach ( (array) $parts as $t ) {
					$t = trim( strtolower( (string) $t ) );
					if ( $t !== '' ) { $topics[] = $t; }
				}
			}
		}

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — infer topic from question when upstream is empty.
		if ( empty( $topics ) ) {
			$q = mb_strtolower( $question );
			if ( strpos( $q, 'sự nghiệp' ) !== false || strpos( $q, 'công việc' ) !== false ) { $topics[] = 'career'; }
			if ( strpos( $q, 'tài chính' ) !== false || strpos( $q, 'tiền' ) !== false || strpos( $q, 'hợp đồng' ) !== false ) { $topics[] = 'finance'; }
			if ( strpos( $q, 'tình' ) !== false || strpos( $q, 'yêu' ) !== false ) { $topics[] = 'love'; }
			if ( empty( $topics ) ) { $topics[] = 'career'; }
		}

		return array_values( array_unique( $topics ) );
	}

	private function score_day( array $day, array $topics, int $retro_pen ): array {
		$aspect_weights = array(
			'Hợp'            => 2,
			'Conjunction'    => 2,
			'Lục hợp'        => 4,
			'Sextile'        => 4,
			'Tam hợp'        => 6,
			'Trine'          => 6,
			'Đối'            => -5,
			'Opposition'     => -5,
			'Vuông'          => -6,
			'Square'         => -6,
			'Bất điều hòa'   => -3,
			'Quincunx'       => -3,
			'Bán lục hợp'    => 1,
			'Semi-Sextile'   => 1,
			'Sesquiquadrate' => -2,
		);

		$topic_planets = array(
			'career'  => array( 'Sao Thổ', 'Sao Mộc', 'Mặt Trời', 'Sun', 'Saturn', 'Jupiter' ),
			'finance' => array( 'Sao Kim', 'Sao Mộc', 'Sao Thổ', 'Venus', 'Jupiter', 'Saturn' ),
			'love'    => array( 'Sao Kim', 'Sao Hỏa', 'Mặt Trăng', 'Venus', 'Mars', 'Moon' ),
			'health'  => array( 'Mặt Trời', 'Sao Hỏa', 'Sao Thổ', 'Sun', 'Mars', 'Saturn', 'Chiron' ),
			'family'  => array( 'Mặt Trăng', 'Sao Kim', 'Moon', 'Venus' ),
			'study'   => array( 'Sao Thủy', 'Sao Mộc', 'Mercury', 'Jupiter' ),
		);

		$date       = (string) ( $day['date'] ?? '' );
		$date_label = (string) ( $day['date_label'] ?? $date );
		$retro      = trim( (string) ( $day['retrograde'] ?? '' ) );
		$aspects    = is_array( $day['aspects'] ?? null ) ? (array) $day['aspects'] : array();

		$score       = 50.0;
		$good_hits   = 0;
		$bad_hits    = 0;
		$topic_bonus = 0.0;
		$detail      = array();

		foreach ( $aspects as $line ) {
			$line = (string) $line;
			$base = 0.0;
			foreach ( $aspect_weights as $name => $w ) {
				if ( strpos( $line, $name ) !== false ) {
					$base = (float) $w;
					break;
				}
			}

			$orb = 2.0;
			if ( preg_match( '/\((\d+(?:\.\d+)?)°\)/u', $line, $m ) ) {
				$orb = (float) $m[1];
			}
			$orb_factor = max( 0.2, ( 4.0 - min( 4.0, $orb ) ) / 4.0 );
			$delta      = $base * $orb_factor;
			$score     += $delta;

			if ( $delta >= 0 ) {
				$good_hits++;
			} else {
				$bad_hits++;
			}

			if ( $this->line_matches_topic_planet( $line, $topics, $topic_planets ) ) {
				$topic_bonus += 1.5;
			}
		}

		$score += $topic_bonus;

		$retro_count = 0;
		if ( $retro !== '' ) {
			$retro_count = count( array_filter( array_map( 'trim', explode( ',', $retro ) ) ) );
		}
		$score -= (float) ( $retro_count * $retro_pen );

		$detail[] = 'good=' . $good_hits;
		$detail[] = 'bad=' . $bad_hits;
		$detail[] = 'topic_bonus=' . round( $topic_bonus, 1 );
		$detail[] = 'retro=' . $retro_count;

		$reason = 'Ưu: ' . $good_hits . ', Cản: ' . $bad_hits . ', ℞: ' . $retro_count;

		return array(
			'date'       => $date,
			'date_label' => $date_label,
			'score'      => round( $score, 2 ),
			'reason'     => $reason,
			'detail'     => implode( ';', $detail ),
		);
	}

	private function line_matches_topic_planet( string $line, array $topics, array $topic_planets ): bool {
		foreach ( $topics as $topic ) {
			$planets = $topic_planets[ $topic ] ?? array();
			foreach ( $planets as $planet ) {
				if ( strpos( $line, (string) $planet ) !== false ) {
					return true;
				}
			}
		}
		return false;
	}

	private function sort_rows( array $a, array $b ): int {
		$sa = (float) ( $a['score'] ?? 0 );
		$sb = (float) ( $b['score'] ?? 0 );
		if ( $sa === $sb ) {
			$da = (string) ( $a['date'] ?? '' );
			$db = (string) ( $b['date'] ?? '' );
			return strcmp( $da, $db );
		}
		return ( $sa > $sb ) ? -1 : 1;
	}

	private function build_ranking_md( array $rows, array $topics ): string {
		$lines   = array();
		$lines[] = 'Chủ đề ưu tiên: ' . implode( ', ', $topics );
		$rank    = 1;
		foreach ( $rows as $row ) {
			$lines[] = $rank . '. ' . (string) ( $row['date_label'] ?? '' )
				. ' — ' . (string) ( $row['score'] ?? 0 ) . ' điểm'
				. ' (' . (string) ( $row['reason'] ?? '' ) . ')';
			$rank++;
		}
		return implode( "\n", $lines );
	}
}
