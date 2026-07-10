<?php
/**
 * Action: Web Research (quick / deep / vertical domains).
 *
 * Bọc BizCity_TwinBrain_Web_Quick (mode=quick), Web_Deep (mode=deep),
 * hoặc các vertical engines (Tax / Law / Gov / Med / Nutri / Scholar / Social).
 *
 * Output vars:
 *   {{n_X.answer_md}}      — tổng hợp markdown có citation
 *   {{n_X.citation_count}} — số citation
 *   {{n_X.citations}}      — JSON array {url,title,host}
 *   {{n_X.sources_text}}   — danh sách nguồn plain-text (để gửi Zalo, step-by-step)
 *   {{n_X.mode}}           — quick | deep | tax | law | gov | med | nutri | scholar | social
 *   {{n_X.ms}}             — wall-time ms
 *   {{n_X.ok}}             — bool
 *
 * [2026-06-16 Johnny Chu] PHASE-ATH W8 — new block action.web_research.
 * [2026-06-18 Johnny Chu] PHASE-ZALOBOT — add vertical mode routing + sources_text output.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-ATH W8 (2026-06-16)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Web_Research extends BizCity_Automation_Block_Base {

	// [2026-06-18 Johnny Chu] PHASE-ZALOBOT — vertical engines mapping.
	const VERTICAL_CLASS_MAP = array(
		'tax'     => 'BizCity_TwinBrain_Web_Tax',
		'law'     => 'BizCity_TwinBrain_Web_Law',
		'gov'     => 'BizCity_TwinBrain_Web_Gov',
		'med'     => 'BizCity_TwinBrain_Web_Med',
		'nutri'   => 'BizCity_TwinBrain_Web_Nutri',
		'scholar' => 'BizCity_TwinBrain_Web_Scholar',
		'social'  => 'BizCity_TwinBrain_Web_Social',
	);

	const MODE_OPTIONS     = array( 'quick', 'deep' );
	const VERTICAL_OPTIONS = array( 'auto', 'tax', 'law', 'gov', 'med', 'nutri', 'scholar', 'social' );

	public function id(): string   { return 'action.web_research'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Tìm kiếm Web',
			'short'    => 'web_research',
			'category' => 'ai',
			'color'    => '#0284c7',
			'icon'     => 'globe',
			'defaults' => array(
				'label'       => 'web_research',
				'query'       => '{{trigger.text}}',
				'mode'        => 'quick',
				'vertical'    => 'auto',
				'max_results' => 7,
			),
			'fields' => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',      'type' => 'text' ),
				array( 'name' => 'query',       'label' => 'Câu truy vấn',      'type' => 'textarea', 'hint' => 'Hỗ trợ {{trigger.text}}, {{vars.topic}}' ),
				array( 'name' => 'vertical',    'label' => 'Lĩnh vực',          'type' => 'select', 'options' => self::VERTICAL_OPTIONS, 'hint' => 'auto = tổng hợp · tax/law/gov/med/nutri/scholar/social = chuyên sâu' ),
				array( 'name' => 'mode',        'label' => 'Chế độ (khi auto)', 'type' => 'select', 'options' => self::MODE_OPTIONS, 'hint' => 'quick ≤4s · deep ReAct ≤60s — chỉ áp dụng khi vertical=auto' ),
				array( 'name' => 'max_results', 'label' => 'Số kết quả tối đa', 'type' => 'number', 'hint' => '1–15, mặc định 7' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-06-16 Johnny Chu] PHASE-ATH W8 — execute web research.
		// [2026-06-18 Johnny Chu] PHASE-ZALOBOT — route to vertical engine when set.
		$query = trim( (string) $this->resolve( $data['query'] ?? '{{trigger.text}}', $ctx ) );
		if ( $query === '' ) {
			$this->note_event( 'web_research_skipped', array( 'reason' => 'invalid_param', 'detail' => 'query empty' ) );
			return $this->_empty_result( 'quick', 'invalid_param' );
		}

		$vertical    = (string) ( $data['vertical'] ?? 'auto' );
		if ( ! in_array( $vertical, self::VERTICAL_OPTIONS, true ) ) { $vertical = 'auto'; }
		$mode        = (string) ( $data['mode'] ?? 'quick' );
		if ( ! in_array( $mode, self::MODE_OPTIONS, true ) ) { $mode = 'quick'; }
		$max_results = max( 1, min( 15, (int) ( $data['max_results'] ?? 7 ) ) );

		$trace_id = 'auto_' . uniqid( '', true );
		$started  = microtime( true );
		$row      = null;
		$eff_mode = $vertical !== 'auto' ? $vertical : $mode;

		if ( $vertical !== 'auto' ) {
			// [2026-06-18 Johnny Chu] PHASE-ZALOBOT — vertical domain engine.
			$class = self::VERTICAL_CLASS_MAP[ $vertical ] ?? '';
			if ( $class === '' || ! class_exists( $class ) ) {
				return $this->_degraded( $query, $eff_mode, 'gateway_missing', $class . ' not loaded' );
			}
			try {
				$row = $class::instance()->run( $trace_id, $query, array( 'max' => $max_results ) );
			} catch ( \Throwable $e ) {
				return $this->_degraded( $query, $eff_mode, 'exception', $e->getMessage() );
			}
		} elseif ( $mode === 'deep' ) {
			if ( ! class_exists( 'BizCity_TwinBrain_Web_Deep' ) ) {
				return $this->_degraded( $query, $eff_mode, 'gateway_missing', 'BizCity_TwinBrain_Web_Deep not loaded' );
			}
			try {
				$row = BizCity_TwinBrain_Web_Deep::instance()->run( $trace_id, $query, array( 'max' => $max_results ) );
			} catch ( \Throwable $e ) {
				return $this->_degraded( $query, $eff_mode, 'exception', $e->getMessage() );
			}
		} else {
			if ( ! class_exists( 'BizCity_TwinBrain_Web_Quick' ) ) {
				return $this->_degraded( $query, $eff_mode, 'gateway_missing', 'BizCity_TwinBrain_Web_Quick not loaded' );
			}
			try {
				$row = BizCity_TwinBrain_Web_Quick::instance()->run( $trace_id, $query, array( 'max' => $max_results ) );
			} catch ( \Throwable $e ) {
				return $this->_degraded( $query, $eff_mode, 'exception', $e->getMessage() );
			}
		}

		$ms = (int) ( ( microtime( true ) - $started ) * 1000 );

		if ( ! is_array( $row ) ) {
			return $this->_degraded( $query, $eff_mode, 'invalid_response', 'engine returned non-array' );
		}

		$error = (string) ( $row['error'] ?? '' );
		if ( $error !== '' && ( $row['answer_md'] ?? '' ) === '' ) {
			$this->note_event( 'web_research_failed', array(
				'reason' => $error,
				'mode'   => $eff_mode,
				'query'  => mb_substr( $query, 0, 120 ),
				'ms'     => $ms,
			) );
			return $this->_empty_result( $eff_mode, $error );
		}

		$this->note_event( 'web_research_ok', array(
			'mode'           => $eff_mode,
			'citation_count' => (int) ( $row['citation_count'] ?? 0 ),
			'tokens'         => (int) ( $row['tokens'] ?? 0 ),
			'ms'             => $ms,
		) );

		// Serialize citations for ctx interpolation.
		$citations_raw  = $row['citations'] ?? array();
		$citations_json = is_array( $citations_raw ) ? wp_json_encode( $citations_raw ) : '[]';

		// [2026-06-18 Johnny Chu] PHASE-ZALOBOT — sources_text: plain-text list for Zalo step-reply.
		$sources_text = $this->format_sources_text( $citations_json, (int) ( $row['citation_count'] ?? 0 ) );

		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — top_url: plain-text URL of first citation for Zalo.
		$top_url = '';
		if ( ! empty( $citations_raw ) && is_array( $citations_raw ) ) {
			$first   = reset( $citations_raw );
			$top_url = (string) ( $first['url'] ?? $first['web_url'] ?? '' );
		}

		return array(
			'ok'            => true,
			'answer_md'     => (string) ( $row['answer_md'] ?? '' ),
			'citation_count'=> (int) ( $row['citation_count'] ?? 0 ),
			'citations'     => $citations_json,
			'sources_text'  => $sources_text,
			'top_url'       => $top_url,
			'mode'          => $eff_mode,
			'query'         => $query,
			'tokens'        => (int) ( $row['tokens'] ?? 0 ),
			'ms'            => $ms,
		);
	}

	/**
	 * Format citations as plain-text numbered list for Zalo messaging.
	 * [2026-06-18 Johnny Chu] PHASE-ZALOBOT — new helper.
	 *
	 * @param string $citations_json JSON string from engine.
	 * @param int    $count
	 * @return string
	 */
	private function format_sources_text( string $citations_json, int $count ): string {
		if ( $count === 0 ) { return ''; }
		$arr = json_decode( $citations_json, true );
		if ( ! is_array( $arr ) || empty( $arr ) ) { return ''; }
		$lines = array( '📰 ' . $count . ' nguồn tham khảo:' );
		$i     = 0;
		foreach ( $arr as $c ) {
			$i++;
			$host  = (string) ( $c['host'] ?? $c['web_host'] ?? '' );
			$title = (string) ( $c['title'] ?? $c['web_title'] ?? '' );
			$title = $title !== '' ? mb_substr( $title, 0, 80 ) : '';
			$line  = $i . '. ';
			if ( $host !== '' ) { $line .= $host; }
			if ( $title !== '' ) { $line .= ' — ' . $title; }
			$lines[] = $line;
			if ( $i >= 7 ) { break; }
		}
		return implode( "\n", $lines );
	}

	/**
	 * Fail-OPEN degraded return (R-GW-8).
	 */
	private function _degraded( string $query, string $mode, string $reason, string $detail = '' ) {
		$this->note_event( 'web_research_skipped', array(
			'reason' => $reason,
			'detail' => $detail,
			'mode'   => $mode,
			'query'  => mb_substr( $query, 0, 120 ),
		) );
		return $this->_empty_result( $mode, $reason );
	}

	private function _empty_result( string $mode, string $reason ) {
		return array(
			'ok'            => false,
			'_degraded'     => true,
			'answer_md'     => '',
			'citation_count'=> 0,
			'citations'     => '[]',
			'sources_text'  => '',
			'mode'          => $mode,
			'ms'            => 0,
			'reason'        => $reason,
		);
	}
}
