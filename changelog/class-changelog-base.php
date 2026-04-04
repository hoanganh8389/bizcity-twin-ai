<?php
/**
 * Changelog Base — Abstract class cho Phase Changelog Validators.
 *
 * Mỗi phase kế thừa class này để tạo changelog-as-validator:
 *   - Liệt kê những gì đã build (changelog)
 *   - Giả lập verify từng component (validator)
 *   - Hiển thị trạng thái trên Dashboard
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

abstract class BizCity_Changelog_Base {

	/** @var array Accumulated results */
	protected $results = [];

	/** @var int */
	protected $pass = 0;

	/** @var int */
	protected $fail = 0;

	/** @var int */
	protected $skip = 0;

	/* ════════════════════════════════════════════════════════════════════
	 * ABSTRACT — Mỗi phase phải implement
	 * ════════════════════════════════════════════════════════════════════ */

	/** Phase ID dùng cho URL slug, ví dụ: '1.4', '1.5', '1.6' */
	abstract public function get_phase_id(): string;

	/** Phase title hiển thị, ví dụ: 'Content Tool Core v2' */
	abstract public function get_phase_title(): string;

	/** Mô tả ngắn gọn */
	abstract public function get_description(): string;

	/** Ngày bắt đầu và cập nhật cuối */
	abstract public function get_dates(): array; // ['started' => 'Y-m-d', 'updated' => 'Y-m-d']

	/** Danh sách component groups + changelog entries.
	 *  Return format:
	 *  [
	 *    ['group' => 'Foundation', 'icon' => '🏗️', 'entries' => [
	 *      ['id' => 'R1', 'title' => 'Skill SQL Storage', 'verify' => 'verify_R1'],
	 *    ]],
	 *  ]
	 */
	abstract public function get_changelog(): array;

	/** Chạy tất cả verify methods */
	abstract protected function run_verifications(): void;

	/* ════════════════════════════════════════════════════════════════════
	 * PUBLIC API
	 * ════════════════════════════════════════════════════════════════════ */

	/**
	 * Run all verifications and return results.
	 */
	public function run(): array {
		$this->results = [];
		$this->pass    = 0;
		$this->fail    = 0;
		$this->skip    = 0;

		$this->run_verifications();

		return [
			'phase_id'    => $this->get_phase_id(),
			'phase_title' => $this->get_phase_title(),
			'description' => $this->get_description(),
			'dates'       => $this->get_dates(),
			'pass'        => $this->pass,
			'fail'        => $this->fail,
			'skip'        => $this->skip,
			'total'       => $this->pass + $this->fail + $this->skip,
			'results'     => $this->results,
			'changelog'   => $this->get_changelog(),
		];
	}

	/**
	 * Get summary without running verifications (for dashboard listing).
	 */
	public function get_summary(): array {
		$data = $this->run();
		return [
			'phase_id'    => $data['phase_id'],
			'phase_title' => $data['phase_title'],
			'description' => $data['description'],
			'dates'       => $data['dates'],
			'pass'        => $data['pass'],
			'fail'        => $data['fail'],
			'skip'        => $data['skip'],
			'total'       => $data['total'],
			'score'       => $data['total'] > 0 ? round( $data['pass'] / $data['total'] * 100 ) : 0,
			'changelog'   => $data['changelog'],
		];
	}

	/* ════════════════════════════════════════════════════════════════════
	 * ASSERT HELPERS
	 * ════════════════════════════════════════════════════════════════════ */

	protected function assert( string $id, string $name, bool $condition, string $detail = '' ): void {
		if ( $condition ) {
			$this->pass++;
			$this->results[] = [
				'id'     => $id,
				'name'   => $name,
				'status' => 'pass',
				'icon'   => '✅',
				'detail' => $detail,
			];
		} else {
			$this->fail++;
			$this->results[] = [
				'id'     => $id,
				'name'   => $name,
				'status' => 'fail',
				'icon'   => '❌',
				'detail' => $detail,
			];
		}
	}

	protected function skip( string $id, string $name, string $reason = '' ): void {
		$this->skip++;
		$this->results[] = [
			'id'     => $id,
			'name'   => $name,
			'status' => 'skip',
			'icon'   => '⏭️',
			'detail' => $reason,
		];
	}

	/* ════════════════════════════════════════════════════════════════════
	 * REFLECTION HELPERS — Avoid file_get_contents / open_basedir issues
	 * ════════════════════════════════════════════════════════════════════ */

	/**
	 * Read source code of a method via Reflection (safe on shared hosting).
	 */
	protected function read_method_source( string $file, int $start, int $end ): string {
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}
		$lines = file( $file );
		if ( ! $lines ) {
			return '';
		}
		return implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );
	}

	/**
	 * Get source of a class method by name.
	 */
	protected function get_method_source( string $class, string $method ): string {
		if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
			return '';
		}
		$ref = new ReflectionMethod( $class, $method );
		return $this->read_method_source( $ref->getFileName(), $ref->getStartLine(), $ref->getEndLine() );
	}

	/**
	 * Check if class exists and has specific method(s).
	 */
	protected function class_has_methods( string $class, array $methods ): array {
		if ( ! class_exists( $class ) ) {
			return [ 'exists' => false, 'methods' => [] ];
		}
		$found = [];
		foreach ( $methods as $m ) {
			$found[ $m ] = method_exists( $class, $m );
		}
		return [ 'exists' => true, 'methods' => $found ];
	}

	/**
	 * Check if a non-comment line in source contains a string.
	 */
	protected function source_has_code( string $source, string $needle ): bool {
		foreach ( explode( "\n", $source ) as $line ) {
			$trimmed = ltrim( $line );
			if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) {
				continue;
			}
			if ( strpos( $trimmed, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/* ════════════════════════════════════════════════════════════════════
	 * HTML RENDER
	 * ════════════════════════════════════════════════════════════════════ */

	public function render_html(): string {
		$data = $this->run();

		$html = '<div class="bizcity-changelog" style="font-family:system-ui,-apple-system,sans-serif;max-width:960px;margin:20px auto;padding:20px">';

		// Header
		$html .= '<div style="border-bottom:2px solid #e2e8f0;padding-bottom:12px;margin-bottom:20px">';
		$html .= '<h2 style="margin:0 0 4px">📋 Phase ' . esc_html( $data['phase_id'] ) . ' — ' . esc_html( $data['phase_title'] ) . '</h2>';
		$html .= '<p style="color:#64748b;margin:0 0 8px">' . esc_html( $data['description'] ) . '</p>';
		$html .= '<p style="color:#94a3b8;font-size:13px;margin:0">';
		$html .= 'Started: ' . esc_html( $data['dates']['started'] ?? '—' );
		$html .= ' · Updated: ' . esc_html( $data['dates']['updated'] ?? '—' );
		$html .= '</p>';
		$html .= '</div>';

		// Score bar
		$score = $data['total'] > 0 ? round( $data['pass'] / $data['total'] * 100 ) : 0;
		$bar_color = $score === 100 ? '#059669' : ( $score >= 80 ? '#d97706' : '#ef4444' );
		$html .= '<div style="background:#f1f5f9;border-radius:8px;padding:12px 16px;margin-bottom:20px">';
		$html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">';
		$html .= '<span><strong>' . $data['pass'] . '</strong>/' . $data['total'] . ' verified';
		if ( $data['skip'] > 0 ) {
			$html .= ' · <span style="color:#94a3b8">' . $data['skip'] . ' skipped</span>';
		}
		if ( $data['fail'] > 0 ) {
			$html .= ' · <span style="color:#ef4444"><strong>' . $data['fail'] . ' failed</strong></span>';
		}
		$html .= '</span>';
		$html .= '<strong style="color:' . $bar_color . '">' . $score . '%</strong>';
		$html .= '</div>';
		$html .= '<div style="background:#e2e8f0;border-radius:4px;height:8px;overflow:hidden">';
		$html .= '<div style="background:' . $bar_color . ';height:100%;width:' . $score . '%;transition:width .3s"></div>';
		$html .= '</div>';
		$html .= '</div>';

		// Group results by changelog groups
		$changelog = $data['changelog'];
		$results_by_id = [];
		foreach ( $data['results'] as $r ) {
			$results_by_id[ $r['id'] ] = $r;
		}

		foreach ( $changelog as $group ) {
			$html .= '<div style="margin-bottom:24px">';
			$html .= '<h3 style="margin:0 0 8px;border-bottom:1px solid #e2e8f0;padding-bottom:4px">';
			$html .= esc_html( $group['icon'] ?? '📦' ) . ' ' . esc_html( $group['group'] );
			$html .= '</h3>';

			foreach ( $group['entries'] as $entry ) {
				$entry_id = $entry['id'];
				$r = $results_by_id[ $entry_id ] ?? null;

				if ( $r ) {
					$is_pass = $r['status'] === 'pass';
					$is_skip = $r['status'] === 'skip';
					$color = $is_pass ? '#059669' : ( $is_skip ? '#94a3b8' : '#ef4444' );
					$bg    = $is_pass ? '#f0fdf4' : ( $is_skip ? '#f8fafc' : '#fef2f2' );
					$icon  = $r['icon'];
				} else {
					$color = '#94a3b8';
					$bg    = '#f8fafc';
					$icon  = '⚪';
				}

				$html .= '<div style="margin:3px 0;padding:6px 12px;border-left:3px solid ' . $color . ';background:' . $bg . ';border-radius:0 4px 4px 0">';
				$html .= '<strong>' . $icon . '</strong> ';
				$html .= '<code style="font-size:12px;background:#e2e8f0;padding:1px 4px;border-radius:2px">' . esc_html( $entry_id ) . '</code> ';
				$html .= esc_html( $entry['title'] );
				if ( $r && $r['detail'] ) {
					$html .= '<br><span style="color:#64748b;font-size:12px;margin-left:24px">' . esc_html( $r['detail'] ) . '</span>';
				}
				$html .= '</div>';
			}

			$html .= '</div>';
		}

		// Back to dashboard link
		$html .= '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #e2e8f0">';
		$html .= '<a href="' . esc_url( admin_url( 'admin.php?page=bizcity-changelog' ) ) . '" style="color:#3b82f6;text-decoration:none">← Quay về Dashboard Changelog</a>';
		$html .= '</div>';

		$html .= '</div>';
		return $html;
	}
}
