<?php
/**
 * Class BCPro_Section_Renderer
 *
 * Server-side HTML renderer cho structured JSON sections.
 * Mỗi method `render_<key>($data)` nhận decoded array, trả HTML string
 * dùng dark-cosmic design tokens (.bcpro-rich, .bcpro-rich-* classes).
 *
 * Map key được khai báo ở generator template → field "render".
 *
 * Khi không tìm được renderer phù hợp → fallback render JSON pretty.
 *
 * @package BizCoachPro
 * @since 0.36.0  (Phase 0.2 — rich JSON map)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BCPro_Section_Renderer {

	/**
	 * Public dispatch. $render là tên render function (vd: career_overview, career_swot...).
	 * Trả HTML string. Nếu không có renderer → JSON pretty fallback.
	 *
	 * @param string $render_name
	 * @param array  $data
	 * @return string
	 */
	public static function render( $render_name, $data ) {
		$render_name = preg_replace( '/[^a-z0-9_]/i', '', (string) $render_name );
		$method      = 'render_' . $render_name;
		if ( method_exists( __CLASS__, $method ) ) {
			$html = (string) call_user_func( array( __CLASS__, $method ), is_array( $data ) ? $data : array() );
			return '<div class="bcpro-rich">' . $html . '</div>';
		}
		return self::render_fallback( $data );
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	private static function e( $s ) {
		return esc_html( (string) $s );
	}

	private static function arr( $v ) {
		return is_array( $v ) ? $v : array();
	}

	private static function pct( $score, $max = 100 ) {
		$score = max( 0, min( (int) $score, (int) $max ) );
		return $max > 0 ? round( $score / $max * 100 ) : 0;
	}

	private static function score_color( $score, $max = 10 ) {
		$pct = self::pct( $score, $max );
		if ( $pct >= 80 ) return '#10b981'; // emerald
		if ( $pct >= 60 ) return '#22d3ee'; // cyan
		if ( $pct >= 40 ) return '#f59e0b'; // amber
		return '#ef4444'; // red
	}

	private static function pills( $items, $extra_class = '' ) {
		$items = self::arr( $items );
		if ( empty( $items ) ) return '';
		$html = '<div class="bcpro-rich-pills ' . esc_attr( $extra_class ) . '">';
		foreach ( $items as $it ) {
			$html .= '<span class="bcpro-rich-pill">' . self::e( $it ) . '</span>';
		}
		return $html . '</div>';
	}

	private static function bullets( $items ) {
		$items = self::arr( $items );
		if ( empty( $items ) ) return '';
		$html = '<ul class="bcpro-rich-bullets">';
		foreach ( $items as $it ) {
			$html .= '<li>' . self::e( $it ) . '</li>';
		}
		return $html . '</ul>';
	}

	// =====================================================================
	// Section renderers
	// =====================================================================

	/**
	 * career_overview:
	 * { summary, career_score, career_archetype, leadership_potential,
	 *   natural_talents[], ideal_roles[], career_challenges[], growth_areas[],
	 *   career_timing{ current_phase, favorable_period, caution_period } }
	 */
	public static function render_career_overview( $d ) {
		$summary    = (string) ( $d['summary'] ?? '' );
		$score      = (int) ( $d['career_score'] ?? 0 );
		$archetype  = (string) ( $d['career_archetype'] ?? '' );
		$leadership = (int) ( $d['leadership_potential'] ?? 0 );
		$timing     = self::arr( $d['career_timing'] ?? array() );

		$html  = '<div class="bcpro-rich-hero">';
		$html .= '  <div class="bcpro-rich-hero-left">';
		if ( $score > 0 ) {
			$html .= '    <div class="bcpro-rich-score" style="--cc-pct:' . self::pct( $score ) . '%">';
			$html .= '      <div class="bcpro-rich-score-num">' . self::e( $score ) . '</div>';
			$html .= '      <div class="bcpro-rich-score-cap">Career Score</div>';
			$html .= '    </div>';
		}
		$html .= '  </div>';
		$html .= '  <div class="bcpro-rich-hero-right">';
		if ( $archetype !== '' ) {
			$html .= '<div class="bcpro-rich-archetype">🌟 Archetype: <strong>' . self::e( $archetype ) . '</strong></div>';
		}
		if ( $leadership > 0 ) {
			$html .= '<div class="bcpro-rich-meter"><div class="bcpro-rich-meter-lbl">Tiềm năng lãnh đạo</div><div class="bcpro-rich-meter-bar"><div class="bcpro-rich-meter-fill" style="width:' . self::pct( $leadership ) . '%"></div></div><div class="bcpro-rich-meter-num">' . self::e( $leadership ) . '/100</div></div>';
		}
		$html .= '  </div>';
		$html .= '</div>';

		if ( $summary !== '' ) {
			$html .= '<p class="bcpro-rich-lead">' . self::e( $summary ) . '</p>';
		}

		$grid = array();
		if ( ! empty( $d['natural_talents'] ) ) {
			$grid[] = array( 'icon' => '✨', 'title' => 'Tài năng tự nhiên', 'body' => self::pills( $d['natural_talents'], 'is-emerald' ) );
		}
		if ( ! empty( $d['ideal_roles'] ) ) {
			$grid[] = array( 'icon' => '🎯', 'title' => 'Vai trò lý tưởng', 'body' => self::pills( $d['ideal_roles'], 'is-cyan' ) );
		}
		if ( ! empty( $d['career_challenges'] ) ) {
			$grid[] = array( 'icon' => '⚠️', 'title' => 'Thách thức', 'body' => self::pills( $d['career_challenges'], 'is-amber' ) );
		}
		if ( ! empty( $d['growth_areas'] ) ) {
			$grid[] = array( 'icon' => '🌱', 'title' => 'Vùng phát triển', 'body' => self::pills( $d['growth_areas'], 'is-violet' ) );
		}
		if ( $grid ) {
			$html .= '<div class="bcpro-rich-grid grid-2">';
			foreach ( $grid as $g ) {
				$html .= '<div class="bcpro-rich-card"><h4>' . $g['icon'] . ' ' . self::e( $g['title'] ) . '</h4>' . $g['body'] . '</div>';
			}
			$html .= '</div>';
		}

		if ( $timing ) {
			$html .= '<div class="bcpro-rich-timing">';
			$html .= '<h4 class="bcpro-rich-h4">⏳ Giai đoạn sự nghiệp</h4>';
			$html .= '<div class="bcpro-rich-grid grid-3">';
			$cells = array(
				array( 'lbl' => 'Hiện tại',          'val' => $timing['current_phase']    ?? '', 'cls' => 'is-now' ),
				array( 'lbl' => 'Thuận lợi sắp tới', 'val' => $timing['favorable_period'] ?? '', 'cls' => 'is-fav' ),
				array( 'lbl' => 'Cần cẩn trọng',     'val' => $timing['caution_period']   ?? '', 'cls' => 'is-cau' ),
			);
			foreach ( $cells as $c ) {
				$html .= '<div class="bcpro-rich-tile ' . $c['cls'] . '"><div class="bcpro-rich-tile-lbl">' . self::e( $c['lbl'] ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $c['val'] ) . '</div></div>';
			}
			$html .= '</div></div>';
		}

		return $html;
	}

	/**
	 * career_vision:
	 * { vision_statement, career_purpose, core_values[], milestones[{year_label,goal}],
	 *   impact_goals[], legacy_statement }
	 */
	public static function render_career_vision( $d ) {
		$html = '';
		if ( ! empty( $d['vision_statement'] ) ) {
			$html .= '<blockquote class="bcpro-rich-quote">' . self::e( $d['vision_statement'] ) . '</blockquote>';
		}
		if ( ! empty( $d['career_purpose'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🎯 Mục đích sự nghiệp</h4><p>' . self::e( $d['career_purpose'] ) . '</p></div>';
		}
		if ( ! empty( $d['core_values'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>💎 Giá trị cốt lõi</h4>' . self::pills( $d['core_values'], 'is-violet' ) . '</div>';
		}
		$milestones = self::arr( $d['milestones'] ?? array() );
		if ( $milestones ) {
			$html .= '<h4 class="bcpro-rich-h4">📍 Cột mốc</h4><div class="bcpro-rich-grid grid-3">';
			foreach ( $milestones as $m ) {
				$html .= '<div class="bcpro-rich-tile is-milestone"><div class="bcpro-rich-tile-lbl">' . self::e( $m['year_label'] ?? '' ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $m['goal'] ?? '' ) . '</div></div>';
			}
			$html .= '</div>';
		}
		if ( ! empty( $d['impact_goals'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🌍 Mục tiêu tác động</h4>' . self::bullets( $d['impact_goals'] ) . '</div>';
		}
		if ( ! empty( $d['legacy_statement'] ) ) {
			$html .= '<div class="bcpro-rich-banner">🏛️ ' . self::e( $d['legacy_statement'] ) . '</div>';
		}
		return $html;
	}

	/**
	 * career_swot: { strengths[{title,description,action}], weaknesses, opportunities[{...,timing}], threats[{...,mitigation,severity}], swot_strategy }
	 */
	public static function render_career_swot( $d ) {
		$quadrants = array(
			array( 'key' => 'strengths',     'title' => 'Strengths — Điểm mạnh',      'icon' => '💪', 'cls' => 'is-emerald', 'extra_label' => 'Hành động', 'extra_field' => 'action' ),
			array( 'key' => 'weaknesses',    'title' => 'Weaknesses — Điểm yếu',      'icon' => '⚠️', 'cls' => 'is-amber',   'extra_label' => 'Hành động', 'extra_field' => 'action' ),
			array( 'key' => 'opportunities', 'title' => 'Opportunities — Cơ hội',     'icon' => '🚀', 'cls' => 'is-cyan',    'extra_label' => 'Thời điểm',  'extra_field' => 'timing' ),
			array( 'key' => 'threats',       'title' => 'Threats — Thách thức ngoài', 'icon' => '🛡️', 'cls' => 'is-rose',    'extra_label' => 'Đối phó',    'extra_field' => 'mitigation' ),
		);
		$html = '<div class="bcpro-rich-grid grid-2 swot">';
		foreach ( $quadrants as $q ) {
			$items = self::arr( $d[ $q['key'] ] ?? array() );
			$html .= '<div class="bcpro-rich-swot ' . $q['cls'] . '">';
			$html .= '<h4>' . $q['icon'] . ' ' . self::e( $q['title'] ) . '</h4>';
			if ( $items ) {
				foreach ( $items as $it ) {
					$html .= '<div class="bcpro-rich-swot-item">';
					$html .= '<div class="bcpro-rich-swot-title">' . self::e( $it['title'] ?? '' );
					if ( $q['key'] === 'threats' && ! empty( $it['severity'] ) ) {
						$html .= ' <span class="bcpro-rich-sev sev-' . esc_attr( sanitize_title( $it['severity'] ) ) . '">' . self::e( $it['severity'] ) . '</span>';
					}
					$html .= '</div>';
					if ( ! empty( $it['description'] ) ) {
						$html .= '<div class="bcpro-rich-swot-desc">' . self::e( $it['description'] ) . '</div>';
					}
					$ext = (string) ( $it[ $q['extra_field'] ] ?? '' );
					if ( $ext !== '' ) {
						$html .= '<div class="bcpro-rich-swot-action"><strong>→ ' . self::e( $q['extra_label'] ) . ':</strong> ' . self::e( $ext ) . '</div>';
					}
					$html .= '</div>';
				}
			} else {
				$html .= '<div class="bcpro-rich-empty">—</div>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';
		if ( ! empty( $d['swot_strategy'] ) ) {
			$html .= '<div class="bcpro-rich-banner">🎯 <strong>Chiến lược tổng:</strong> ' . self::e( $d['swot_strategy'] ) . '</div>';
		}
		return $html;
	}

	/**
	 * career_value: { core_values[{name,score,description}], work_values[], non_negotiables[], contribution_model }
	 */
	public static function render_career_value( $d ) {
		$html = '';
		$cv   = self::arr( $d['core_values'] ?? array() );
		if ( $cv ) {
			$html .= '<div class="bcpro-rich-card"><h4>💎 Giá trị cốt lõi (1-10)</h4>';
			foreach ( $cv as $v ) {
				$score = (int) ( $v['score'] ?? 0 );
				$color = self::score_color( $score, 10 );
				$html .= '<div class="bcpro-rich-bar">';
				$html .= '<div class="bcpro-rich-bar-row"><span class="bcpro-rich-bar-name">' . self::e( $v['name'] ?? '' ) . '</span><span class="bcpro-rich-bar-num" style="color:' . esc_attr( $color ) . '">' . self::e( $score ) . '/10</span></div>';
				$html .= '<div class="bcpro-rich-bar-track"><div class="bcpro-rich-bar-fill" style="width:' . self::pct( $score, 10 ) . '%;background:' . esc_attr( $color ) . '"></div></div>';
				if ( ! empty( $v['description'] ) ) {
					$html .= '<div class="bcpro-rich-bar-desc">' . self::e( $v['description'] ) . '</div>';
				}
				$html .= '</div>';
			}
			$html .= '</div>';
		}
		if ( ! empty( $d['work_values'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>💼 Work Values</h4>' . self::pills( $d['work_values'], 'is-cyan' ) . '</div>';
		}
		if ( ! empty( $d['non_negotiables'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🚫 Không thể thỏa hiệp</h4>' . self::bullets( $d['non_negotiables'] ) . '</div>';
		}
		if ( ! empty( $d['contribution_model'] ) ) {
			$html .= '<div class="bcpro-rich-banner">🌍 ' . self::e( $d['contribution_model'] ) . '</div>';
		}
		return $html;
	}

	/**
	 * career_winning: { winning_formula, what, why, how, who, competitive_advantages[], success_patterns[{pattern,action}], execution_principles[] }
	 */
	public static function render_career_winning( $d ) {
		$html = '';
		if ( ! empty( $d['winning_formula'] ) ) {
			$html .= '<div class="bcpro-rich-formula">⚡ <strong>Công thức:</strong> ' . self::e( $d['winning_formula'] ) . '</div>';
		}
		$cards = array(
			array( 'k' => 'what', 'icon' => '🎯', 'title' => 'WHAT — Năng lực cốt lõi', 'cls' => 'is-cyan' ),
			array( 'k' => 'why',  'icon' => '💖', 'title' => 'WHY — Động lực sâu xa',   'cls' => 'is-rose' ),
			array( 'k' => 'how',  'icon' => '🔧', 'title' => 'HOW — Cách thực hiện',    'cls' => 'is-emerald' ),
			array( 'k' => 'who',  'icon' => '👥', 'title' => 'WHO — Phục vụ ai',         'cls' => 'is-amber' ),
		);
		$html .= '<div class="bcpro-rich-grid grid-2">';
		foreach ( $cards as $c ) {
			$val = (string) ( $d[ $c['k'] ] ?? '' );
			$html .= '<div class="bcpro-rich-card ' . $c['cls'] . '"><h4>' . $c['icon'] . ' ' . self::e( $c['title'] ) . '</h4><p>' . self::e( $val ) . '</p></div>';
		}
		$html .= '</div>';

		if ( ! empty( $d['competitive_advantages'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🏆 Lợi thế cạnh tranh</h4>' . self::pills( $d['competitive_advantages'], 'is-violet' ) . '</div>';
		}
		$patterns = self::arr( $d['success_patterns'] ?? array() );
		if ( $patterns ) {
			$html .= '<div class="bcpro-rich-card"><h4>📈 Pattern thành công</h4>';
			foreach ( $patterns as $p ) {
				$html .= '<div class="bcpro-rich-pattern"><strong>' . self::e( $p['pattern'] ?? '' ) . '</strong> — <em>' . self::e( $p['action'] ?? '' ) . '</em></div>';
			}
			$html .= '</div>';
		}
		if ( ! empty( $d['execution_principles'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>📜 Nguyên tắc thực thi</h4>' . self::bullets( $d['execution_principles'] ) . '</div>';
		}
		return $html;
	}

	/**
	 * career_leadership: { leadership_archetype, leadership_archetype_desc,
	 *   leadership_scores{...8 keys 1-10}, leadership_strengths[], leadership_blindspots[],
	 *   team_dynamics{...}, leadership_readiness{current,next,gap_skills[],time_to_next} }
	 */
	public static function render_career_leadership( $d ) {
		$html = '';
		if ( ! empty( $d['leadership_archetype'] ) ) {
			$html .= '<div class="bcpro-rich-archetype is-leader">👑 Archetype lãnh đạo: <strong>' . self::e( $d['leadership_archetype'] ) . '</strong>';
			if ( ! empty( $d['leadership_archetype_desc'] ) ) {
				$html .= '<div class="bcpro-rich-archetype-desc">' . self::e( $d['leadership_archetype_desc'] ) . '</div>';
			}
			$html .= '</div>';
		}
		$scores = self::arr( $d['leadership_scores'] ?? array() );
		if ( $scores ) {
			$labels = array(
				'vision'                 => '🔮 Tầm nhìn',
				'execution'              => '⚙️ Thực thi',
				'people_management'      => '👥 Quản lý người',
				'strategic_thinking'     => '🧠 Tư duy chiến lược',
				'communication'          => '💬 Giao tiếp',
				'decision_making'        => '⚖️ Ra quyết định',
				'emotional_intelligence' => '❤️ EQ',
				'influence'              => '🌟 Ảnh hưởng',
			);
			$html .= '<div class="bcpro-rich-card"><h4>📊 8 năng lực lãnh đạo</h4>';
			foreach ( $labels as $k => $lbl ) {
				$score = (int) ( $scores[ $k ] ?? 0 );
				$color = self::score_color( $score, 10 );
				$html .= '<div class="bcpro-rich-bar">';
				$html .= '<div class="bcpro-rich-bar-row"><span class="bcpro-rich-bar-name">' . self::e( $lbl ) . '</span><span class="bcpro-rich-bar-num" style="color:' . esc_attr( $color ) . '">' . self::e( $score ) . '/10</span></div>';
				$html .= '<div class="bcpro-rich-bar-track"><div class="bcpro-rich-bar-fill" style="width:' . self::pct( $score, 10 ) . '%;background:' . esc_attr( $color ) . '"></div></div>';
				$html .= '</div>';
			}
			$html .= '</div>';
		}
		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['leadership_strengths'] ) ) {
			$html .= '<div class="bcpro-rich-card is-emerald"><h4>💪 Điểm mạnh lãnh đạo</h4>' . self::bullets( $d['leadership_strengths'] ) . '</div>';
		}
		if ( ! empty( $d['leadership_blindspots'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>🔍 Điểm mù</h4>' . self::bullets( $d['leadership_blindspots'] ) . '</div>';
		}
		$html .= '</div>';
		$td = self::arr( $d['team_dynamics'] ?? array() );
		if ( $td ) {
			$html .= '<div class="bcpro-rich-card"><h4>👥 Team Dynamics</h4><div class="bcpro-rich-grid grid-3">';
			$html .= '<div class="bcpro-rich-tile"><div class="bcpro-rich-tile-lbl">Quy mô nhóm lý tưởng</div><div class="bcpro-rich-tile-val">' . self::e( $td['ideal_team_size'] ?? '' ) . '</div></div>';
			$html .= '<div class="bcpro-rich-tile"><div class="bcpro-rich-tile-lbl">Văn hoá đội nhóm</div><div class="bcpro-rich-tile-val">' . self::e( $td['team_culture'] ?? '' ) . '</div></div>';
			$html .= '<div class="bcpro-rich-tile"><div class="bcpro-rich-tile-lbl">Phong cách xử lý xung đột</div><div class="bcpro-rich-tile-val">' . self::e( $td['conflict_style'] ?? '' ) . '</div></div>';
			$html .= '</div></div>';
		}
		$lr = self::arr( $d['leadership_readiness'] ?? array() );
		if ( $lr ) {
			$html .= '<div class="bcpro-rich-card is-violet"><h4>🚀 Mức độ sẵn sàng lãnh đạo</h4>';
			$html .= '<div class="bcpro-rich-readiness"><span class="bcpro-rich-readiness-pill is-current">' . self::e( $lr['current'] ?? '' ) . '</span><span class="bcpro-rich-readiness-arrow">→</span><span class="bcpro-rich-readiness-pill is-next">' . self::e( $lr['next'] ?? '' ) . '</span></div>';
			if ( ! empty( $lr['gap_skills'] ) ) {
				$html .= '<div class="bcpro-rich-bar-desc"><strong>Kỹ năng cần lấp:</strong></div>' . self::pills( $lr['gap_skills'], 'is-amber' );
			}
			if ( ! empty( $lr['time_to_next'] ) ) {
				$html .= '<div class="bcpro-rich-bar-desc"><strong>Thời gian dự kiến:</strong> ' . self::e( $lr['time_to_next'] ) . '</div>';
			}
			$html .= '</div>';
		}
		return $html;
	}

	/**
	 * career_milestone: { objective, phases[{phase_label, theme, weeks[{week,focus,tasks[],deliverable}]}] }
	 */
	public static function render_career_milestone( $d ) {
		$html = '';
		if ( ! empty( $d['objective'] ) ) {
			$html .= '<div class="bcpro-rich-banner">🎯 ' . self::e( $d['objective'] ) . '</div>';
		}
		$phases = self::arr( $d['phases'] ?? array() );
		foreach ( $phases as $i => $p ) {
			$html .= '<div class="bcpro-rich-phase">';
			$html .= '<h4 class="bcpro-rich-phase-h">' . self::e( $p['phase_label'] ?? ( 'Phase ' . ( $i + 1 ) ) ) . '</h4>';
			if ( ! empty( $p['theme'] ) ) {
				$html .= '<div class="bcpro-rich-phase-theme">' . self::e( $p['theme'] ) . '</div>';
			}
			$weeks = self::arr( $p['weeks'] ?? array() );
			if ( $weeks ) {
				$html .= '<div class="bcpro-rich-grid grid-2">';
				foreach ( $weeks as $w ) {
					$html .= '<div class="bcpro-rich-week">';
					$html .= '<div class="bcpro-rich-week-h"><span class="bcpro-rich-week-num">Tuần ' . self::e( $w['week'] ?? '' ) . '</span><span class="bcpro-rich-week-focus">' . self::e( $w['focus'] ?? '' ) . '</span></div>';
					if ( ! empty( $w['tasks'] ) ) {
						$html .= self::bullets( $w['tasks'] );
					}
					if ( ! empty( $w['deliverable'] ) ) {
						$html .= '<div class="bcpro-rich-week-out">📦 <strong>Deliverable:</strong> ' . self::e( $w['deliverable'] ) . '</div>';
					}
					$html .= '</div>';
				}
				$html .= '</div>';
			}
			$html .= '</div>';
		}
		return $html;
	}

	/** Generic JSON pretty-print fallback. */
	public static function render_fallback( $data ) {
		$pretty = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		return '<div class="bcpro-rich"><pre class="bcpro-rich-fallback">' . esc_html( (string) $pretty ) . '</pre></div>';
	}
}
