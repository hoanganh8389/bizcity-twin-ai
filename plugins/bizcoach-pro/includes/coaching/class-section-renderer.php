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

	// =====================================================================
	// [2026-07-17 Johnny Chu] M-BCM.J7 — BizCoach / TiktokCoach renderers
	// =====================================================================

	/**
	 * biz_overview (biz_coach + tiktok_coach gen_overview):
	 * { summary, biz_score, biz_archetype, growth_stage, key_strengths[],
	 *   key_risks[], market_fit, biz_timing{current_phase,favorable_period,caution_period} }
	 */
	public static function render_biz_overview( $d ) {
		$summary    = (string) ( $d['summary'] ?? '' );
		$score      = (int) ( $d['biz_score'] ?? 0 );
		$archetype  = (string) ( $d['biz_archetype'] ?? '' );
		$stage      = (string) ( $d['growth_stage'] ?? '' );
		$market_fit = (string) ( $d['market_fit'] ?? '' );
		$timing     = self::arr( $d['biz_timing'] ?? array() );

		$html  = '<div class="bcpro-rich-hero">';
		$html .= '  <div class="bcpro-rich-hero-left">';
		if ( $score > 0 ) {
			$html .= '<div class="bcpro-rich-score" style="--cc-pct:' . self::pct( $score ) . '%"><div class="bcpro-rich-score-num">' . self::e( $score ) . '</div><div class="bcpro-rich-score-cap">Biz Score</div></div>';
		}
		$html .= '  </div>';
		$html .= '  <div class="bcpro-rich-hero-right">';
		if ( $archetype !== '' ) {
			$html .= '<div class="bcpro-rich-archetype">🏢 Archetype: <strong>' . self::e( $archetype ) . '</strong></div>';
		}
		if ( $stage !== '' ) {
			$html .= '<div class="bcpro-rich-meter"><div class="bcpro-rich-meter-lbl">Growth Stage</div><div class="bcpro-rich-tile-val is-stage">' . self::e( $stage ) . '</div></div>';
		}
		$html .= '  </div></div>';

		if ( $summary !== '' ) {
			$html .= '<p class="bcpro-rich-lead">' . self::e( $summary ) . '</p>';
		}

		$grid = array();
		if ( ! empty( $d['key_strengths'] ) ) {
			$grid[] = array( 'icon' => '💪', 'title' => 'Điểm mạnh chính', 'body' => self::pills( $d['key_strengths'], 'is-emerald' ) );
		}
		if ( ! empty( $d['key_risks'] ) ) {
			$grid[] = array( 'icon' => '⚠️', 'title' => 'Rủi ro cần chú ý', 'body' => self::pills( $d['key_risks'], 'is-rose' ) );
		}
		if ( $grid ) {
			$html .= '<div class="bcpro-rich-grid grid-2">';
			foreach ( $grid as $g ) {
				$html .= '<div class="bcpro-rich-card"><h4>' . $g['icon'] . ' ' . self::e( $g['title'] ) . '</h4>' . $g['body'] . '</div>';
			}
			$html .= '</div>';
		}

		if ( $market_fit !== '' ) {
			$html .= '<div class="bcpro-rich-card is-cyan"><h4>🎯 Market Fit</h4><p>' . self::e( $market_fit ) . '</p></div>';
		}

		if ( $timing ) {
			$html .= '<div class="bcpro-rich-timing"><h4 class="bcpro-rich-h4">⏳ Giai đoạn kinh doanh</h4><div class="bcpro-rich-grid grid-3">';
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
	 * biz_iqmap:
	 * { iq_scores{visionary_iq..8 keys 1-10}, dominant_iq, blind_spots[], iq_strategy }
	 */
	public static function render_biz_iqmap( $d ) {
		$html   = '';
		$scores = self::arr( $d['iq_scores'] ?? array() );
		$labels = array(
			'visionary_iq'   => '🔮 Visionary IQ',
			'strategic_iq'   => '🧠 Strategic IQ',
			'execution_iq'   => '⚙️ Execution IQ',
			'people_iq'      => '👥 People IQ',
			'financial_iq'   => '💰 Financial IQ',
			'sales_iq'       => '🎯 Sales IQ',
			'innovation_iq'  => '💡 Innovation IQ',
			'resilience_iq'  => '🛡️ Resilience IQ',
		);
		if ( ! empty( $d['dominant_iq'] ) ) {
			$html .= '<div class="bcpro-rich-archetype">⚡ Dominant IQ: <strong>' . self::e( $d['dominant_iq'] ) . '</strong></div>';
		}
		if ( $scores ) {
			$html .= '<div class="bcpro-rich-card"><h4>📊 8 chiều IQ lãnh đạo</h4>';
			foreach ( $labels as $k => $lbl ) {
				$score = (int) ( $scores[ $k ] ?? 0 );
				$color = self::score_color( $score, 10 );
				$html .= '<div class="bcpro-rich-bar"><div class="bcpro-rich-bar-row"><span class="bcpro-rich-bar-name">' . self::e( $lbl ) . '</span><span class="bcpro-rich-bar-num" style="color:' . esc_attr( $color ) . '">' . self::e( $score ) . '/10</span></div><div class="bcpro-rich-bar-track"><div class="bcpro-rich-bar-fill" style="width:' . self::pct( $score, 10 ) . '%;background:' . esc_attr( $color ) . '"></div></div></div>';
			}
			$html .= '</div>';
		}
		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['blind_spots'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>🔍 Điểm mù cần cải thiện</h4>' . self::bullets( $d['blind_spots'] ) . '</div>';
		}
		if ( ! empty( $d['iq_strategy'] ) ) {
			$html .= '<div class="bcpro-rich-card is-violet"><h4>🚀 Chiến lược ưu tiên</h4><p>' . self::e( $d['iq_strategy'] ) . '</p></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * biz_vision:
	 * { mission_statement, vision_statement, core_values[{name,description}],
	 *   strategic_goals[{year_label,goal}], brand_promise }
	 */
	public static function render_biz_vision( $d ) {
		$html = '';
		if ( ! empty( $d['mission_statement'] ) ) {
			$html .= '<div class="bcpro-rich-card is-cyan"><h4>🎯 Mission — Sứ mệnh</h4><blockquote class="bcpro-rich-quote">' . self::e( $d['mission_statement'] ) . '</blockquote></div>';
		}
		if ( ! empty( $d['vision_statement'] ) ) {
			$html .= '<div class="bcpro-rich-card is-violet"><h4>🔮 Vision — Tầm nhìn</h4><blockquote class="bcpro-rich-quote">' . self::e( $d['vision_statement'] ) . '</blockquote></div>';
		}
		$cv = self::arr( $d['core_values'] ?? array() );
		if ( $cv ) {
			$html .= '<div class="bcpro-rich-card"><h4>💎 Giá trị cốt lõi</h4><div class="bcpro-rich-grid grid-2">';
			foreach ( $cv as $v ) {
				$html .= '<div class="bcpro-rich-tile is-milestone"><div class="bcpro-rich-tile-lbl">' . self::e( $v['name'] ?? '' ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $v['description'] ?? '' ) . '</div></div>';
			}
			$html .= '</div></div>';
		}
		$goals = self::arr( $d['strategic_goals'] ?? array() );
		if ( $goals ) {
			$html .= '<h4 class="bcpro-rich-h4">📍 Mục tiêu chiến lược</h4><div class="bcpro-rich-grid grid-3">';
			foreach ( $goals as $g ) {
				$html .= '<div class="bcpro-rich-tile is-milestone"><div class="bcpro-rich-tile-lbl">' . self::e( $g['year_label'] ?? '' ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $g['goal'] ?? '' ) . '</div></div>';
			}
			$html .= '</div>';
		}
		if ( ! empty( $d['brand_promise'] ) ) {
			$html .= '<div class="bcpro-rich-banner">🏛️ <strong>Brand Promise:</strong> ' . self::e( $d['brand_promise'] ) . '</div>';
		}
		return $html;
	}

	/**
	 * biz_customer (biz_coach + tiktok_coach audience insights):
	 * { ideal_customer{description,demographics[],psychographics[]},
	 *   pain_points[], desires[], buying_triggers[], customer_journey_stages[], retention_insight }
	 */
	public static function render_biz_customer( $d ) {
		$html = '';
		$ic   = self::arr( $d['ideal_customer'] ?? array() );
		if ( $ic ) {
			$html .= '<div class="bcpro-rich-card is-cyan"><h4>👤 Khách hàng lý tưởng</h4>';
			if ( ! empty( $ic['description'] ) ) {
				$html .= '<p>' . self::e( $ic['description'] ) . '</p>';
			}
			$html .= '<div class="bcpro-rich-grid grid-2">';
			if ( ! empty( $ic['demographics'] ) ) {
				$html .= '<div><strong>Demographics</strong>' . self::pills( $ic['demographics'] ) . '</div>';
			}
			if ( ! empty( $ic['psychographics'] ) ) {
				$html .= '<div><strong>Psychographics</strong>' . self::pills( $ic['psychographics'], 'is-violet' ) . '</div>';
			}
			$html .= '</div></div>';
		}
		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['pain_points'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>😟 Pain Points</h4>' . self::bullets( $d['pain_points'] ) . '</div>';
		}
		if ( ! empty( $d['desires'] ) ) {
			$html .= '<div class="bcpro-rich-card is-emerald"><h4>✨ Desires</h4>' . self::bullets( $d['desires'] ) . '</div>';
		}
		$html .= '</div>';
		if ( ! empty( $d['buying_triggers'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🔑 Buying Triggers</h4>' . self::pills( $d['buying_triggers'], 'is-cyan' ) . '</div>';
		}
		$journey = self::arr( $d['customer_journey_stages'] ?? array() );
		if ( $journey ) {
			$html .= '<div class="bcpro-rich-card"><h4>🗺️ Hành trình khách hàng</h4><div class="bcpro-rich-pills is-journey">';
			foreach ( $journey as $i => $s ) {
				$html .= '<span class="bcpro-rich-pill is-step">' . ( $i + 1 ) . '. ' . self::e( $s ) . '</span>';
			}
			$html .= '</div></div>';
		}
		if ( ! empty( $d['retention_insight'] ) ) {
			$html .= '<div class="bcpro-rich-banner">🔄 <strong>Retention:</strong> ' . self::e( $d['retention_insight'] ) . '</div>';
		}
		return $html;
	}

	/**
	 * biz_value_chain:
	 * { value_proposition, primary_activities[{name,description,maturity}],
	 *   support_activities[{name,description}], competitive_differentiation[], value_gaps[] }
	 */
	public static function render_biz_value_chain( $d ) {
		$html = '';
		if ( ! empty( $d['value_proposition'] ) ) {
			$html .= '<div class="bcpro-rich-card is-violet"><h4>💎 Value Proposition</h4><blockquote class="bcpro-rich-quote">' . self::e( $d['value_proposition'] ) . '</blockquote></div>';
		}
		$primary = self::arr( $d['primary_activities'] ?? array() );
		if ( $primary ) {
			$maturity_cls = array( 'Mạnh' => 'is-emerald', 'Trung bình' => 'is-amber', 'Yếu' => 'is-rose' );
			$html .= '<div class="bcpro-rich-card"><h4>⚙️ Hoạt động chính</h4>';
			foreach ( $primary as $a ) {
				$mat = (string) ( $a['maturity'] ?? '' );
				$cls = isset( $maturity_cls[ $mat ] ) ? $maturity_cls[ $mat ] : 'is-cyan';
				$html .= '<div class="bcpro-rich-bar"><div class="bcpro-rich-bar-row"><span class="bcpro-rich-bar-name">' . self::e( $a['name'] ?? '' ) . '</span><span class="bcpro-rich-pill ' . esc_attr( $cls ) . '" style="font-size:0.7rem">' . self::e( $mat ) . '</span></div>';
				if ( ! empty( $a['description'] ) ) {
					$html .= '<div class="bcpro-rich-bar-desc">' . self::e( $a['description'] ) . '</div>';
				}
				$html .= '</div>';
			}
			$html .= '</div>';
		}
		$support = self::arr( $d['support_activities'] ?? array() );
		if ( $support ) {
			$html .= '<div class="bcpro-rich-card"><h4>🛠️ Hoạt động hỗ trợ</h4><div class="bcpro-rich-grid grid-2">';
			foreach ( $support as $a ) {
				$html .= '<div class="bcpro-rich-tile"><div class="bcpro-rich-tile-lbl">' . self::e( $a['name'] ?? '' ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $a['description'] ?? '' ) . '</div></div>';
			}
			$html .= '</div></div>';
		}
		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['competitive_differentiation'] ) ) {
			$html .= '<div class="bcpro-rich-card is-emerald"><h4>🏆 Khác biệt cạnh tranh</h4>' . self::bullets( $d['competitive_differentiation'] ) . '</div>';
		}
		if ( ! empty( $d['value_gaps'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>🔍 Khoảng trống cần lấp</h4>' . self::bullets( $d['value_gaps'] ) . '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	// =====================================================================
	// [2026-07-17 Johnny Chu] M-BCM.J7 — AstroCoach renderers
	// =====================================================================

	/**
	 * astro_overview:
	 * { summary, sun_sign, moon_sign, rising_sign, dominant_element,
	 *   life_path_number, core_gifts[], life_lessons[], energy_forecast{...} }
	 */
	public static function render_astro_overview( $d ) {
		$summary  = (string) ( $d['summary'] ?? '' );
		$forecast = self::arr( $d['energy_forecast'] ?? array() );

		$html  = '<div class="bcpro-rich-hero">';
		$html .= '  <div class="bcpro-rich-hero-left">';
		if ( $d['life_path_number'] ?? 0 ) {
			$html .= '<div class="bcpro-rich-score" style="--cc-pct:100%"><div class="bcpro-rich-score-num">' . self::e( $d['life_path_number'] ) . '</div><div class="bcpro-rich-score-cap">Life Path</div></div>';
		}
		$html .= '  </div>';
		$html .= '  <div class="bcpro-rich-hero-right">';
		$signs = array(
			array( 'icon' => '☀️', 'label' => 'Sun Sign',     'val' => $d['sun_sign']         ?? '' ),
			array( 'icon' => '🌙', 'label' => 'Moon Sign',    'val' => $d['moon_sign']        ?? '' ),
			array( 'icon' => '⬆️', 'label' => 'Rising Sign',  'val' => $d['rising_sign']      ?? '' ),
			array( 'icon' => '🌊', 'label' => 'Element',      'val' => $d['dominant_element'] ?? '' ),
		);
		foreach ( $signs as $s ) {
			if ( (string) $s['val'] !== '' ) {
				$html .= '<div class="bcpro-rich-archetype">' . $s['icon'] . ' ' . $s['label'] . ': <strong>' . self::e( $s['val'] ) . '</strong></div>';
			}
		}
		$html .= '  </div></div>';

		if ( $summary !== '' ) {
			$html .= '<p class="bcpro-rich-lead">' . self::e( $summary ) . '</p>';
		}

		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['core_gifts'] ) ) {
			$html .= '<div class="bcpro-rich-card is-violet"><h4>✨ Thiên phú cốt lõi</h4>' . self::pills( $d['core_gifts'], 'is-violet' ) . '</div>';
		}
		if ( ! empty( $d['life_lessons'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>📚 Bài học cuộc đời</h4>' . self::bullets( $d['life_lessons'] ) . '</div>';
		}
		$html .= '</div>';

		if ( $forecast ) {
			$html .= '<div class="bcpro-rich-timing"><h4 class="bcpro-rich-h4">🔮 Dự báo năng lượng</h4><div class="bcpro-rich-grid grid-3">';
			$cells = array(
				array( 'lbl' => 'Hiện tại',          'val' => $forecast['current_phase']    ?? '', 'cls' => 'is-now' ),
				array( 'lbl' => 'Thuận lợi sắp tới', 'val' => $forecast['favorable_period'] ?? '', 'cls' => 'is-fav' ),
				array( 'lbl' => 'Cần cẩn trọng',     'val' => $forecast['caution_period']   ?? '', 'cls' => 'is-cau' ),
			);
			foreach ( $cells as $c ) {
				$html .= '<div class="bcpro-rich-tile ' . $c['cls'] . '"><div class="bcpro-rich-tile-lbl">' . self::e( $c['lbl'] ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $c['val'] ) . '</div></div>';
			}
			$html .= '</div></div>';
		}
		return $html;
	}

	/**
	 * astro_lifemap (astro_coach + health_coach lifemap):
	 * { life_theme, life_purpose, soul_mission, karmic_lessons[],
	 *   peak_years[{age_range,theme,opportunity}], life_chapters[{chapter,theme,lesson}] }
	 */
	public static function render_astro_lifemap( $d ) {
		$html = '';
		if ( ! empty( $d['life_theme'] ) ) {
			$html .= '<div class="bcpro-rich-banner">🌟 <strong>Life Theme:</strong> ' . self::e( $d['life_theme'] ) . '</div>';
		}
		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['life_purpose'] ) ) {
			$html .= '<div class="bcpro-rich-card is-violet"><h4>🎯 Mục đích sống</h4><p>' . self::e( $d['life_purpose'] ) . '</p></div>';
		}
		if ( ! empty( $d['soul_mission'] ) ) {
			$html .= '<div class="bcpro-rich-card is-cyan"><h4>💫 Soul Mission</h4><p>' . self::e( $d['soul_mission'] ) . '</p></div>';
		}
		$html .= '</div>';
		if ( ! empty( $d['karmic_lessons'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>📚 Bài học karma</h4>' . self::bullets( $d['karmic_lessons'] ) . '</div>';
		}
		$peaks = self::arr( $d['peak_years'] ?? array() );
		if ( $peaks ) {
			$html .= '<h4 class="bcpro-rich-h4">⭐ Giai đoạn đỉnh cao</h4><div class="bcpro-rich-grid grid-3">';
			foreach ( $peaks as $p ) {
				$html .= '<div class="bcpro-rich-tile is-milestone"><div class="bcpro-rich-tile-lbl">' . self::e( $p['age_range'] ?? '' ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $p['theme'] ?? '' ) . '</div>';
				if ( ! empty( $p['opportunity'] ) ) {
					$html .= '<div class="bcpro-rich-bar-desc">' . self::e( $p['opportunity'] ) . '</div>';
				}
				$html .= '</div>';
			}
			$html .= '</div>';
		}
		$chapters = self::arr( $d['life_chapters'] ?? array() );
		if ( $chapters ) {
			$html .= '<div class="bcpro-rich-card"><h4>📖 Các chương cuộc đời</h4>';
			foreach ( $chapters as $c ) {
				$html .= '<div class="bcpro-rich-pattern"><strong>' . self::e( $c['chapter'] ?? '' ) . ' — ' . self::e( $c['theme'] ?? '' ) . '</strong>';
				if ( ! empty( $c['lesson'] ) ) {
					$html .= '<em> · ' . self::e( $c['lesson'] ) . '</em>';
				}
				$html .= '</div>';
			}
			$html .= '</div>';
		}
		return $html;
	}

	/**
	 * astro_health_relation:
	 * { health_strengths[], health_vulnerabilities[], wellness_practices[],
	 *   relationship_style, compatible_signs[], growth_relationships[], love_language_astro }
	 */
	public static function render_astro_health_relation( $d ) {
		$html  = '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['health_strengths'] ) ) {
			$html .= '<div class="bcpro-rich-card is-emerald"><h4>💪 Sức mạnh sức khỏe</h4>' . self::bullets( $d['health_strengths'] ) . '</div>';
		}
		if ( ! empty( $d['health_vulnerabilities'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>⚠️ Điểm dễ tổn thương</h4>' . self::bullets( $d['health_vulnerabilities'] ) . '</div>';
		}
		$html .= '</div>';
		if ( ! empty( $d['wellness_practices'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🧘 Thực hành wellness phù hợp</h4>' . self::pills( $d['wellness_practices'], 'is-cyan' ) . '</div>';
		}
		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['relationship_style'] ) ) {
			$html .= '<div class="bcpro-rich-card is-violet"><h4>💕 Phong cách quan hệ</h4><p>' . self::e( $d['relationship_style'] ) . '</p></div>';
		}
		if ( ! empty( $d['love_language_astro'] ) ) {
			$html .= '<div class="bcpro-rich-card is-rose"><h4>💖 Love Language (Astro)</h4><p>' . self::e( $d['love_language_astro'] ) . '</p></div>';
		}
		$html .= '</div>';
		if ( ! empty( $d['compatible_signs'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🌟 Cung tương hợp</h4>' . self::pills( $d['compatible_signs'], 'is-emerald' ) . '</div>';
		}
		if ( ! empty( $d['growth_relationships'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>🌱 Quan hệ thúc đẩy phát triển</h4>' . self::bullets( $d['growth_relationships'] ) . '</div>';
		}
		return $html;
	}

	// =====================================================================
	// [2026-07-17 Johnny Chu] M-BCM.J7 — HealthCoach renderers
	// =====================================================================

	/**
	 * health_overview:
	 * { summary, bmi, bmi_category, health_score, wellness_archetype,
	 *   strengths[], risk_areas[], priority_actions[], health_forecast{...} }
	 */
	public static function render_health_overview( $d ) {
		$summary    = (string) ( $d['summary'] ?? '' );
		$bmi        = (float) ( $d['bmi'] ?? 0 );
		$bmi_cat    = (string) ( $d['bmi_category'] ?? '' );
		$score      = (int) ( $d['health_score'] ?? 0 );
		$archetype  = (string) ( $d['wellness_archetype'] ?? '' );
		$forecast   = self::arr( $d['health_forecast'] ?? array() );

		$html  = '<div class="bcpro-rich-hero">';
		$html .= '  <div class="bcpro-rich-hero-left">';
		if ( $score > 0 ) {
			$html .= '<div class="bcpro-rich-score" style="--cc-pct:' . self::pct( $score ) . '%"><div class="bcpro-rich-score-num">' . self::e( $score ) . '</div><div class="bcpro-rich-score-cap">Health Score</div></div>';
		}
		$html .= '  </div>';
		$html .= '  <div class="bcpro-rich-hero-right">';
		if ( $archetype !== '' ) {
			$html .= '<div class="bcpro-rich-archetype">🏃 Archetype: <strong>' . self::e( $archetype ) . '</strong></div>';
		}
		if ( $bmi > 0 ) {
			$bmi_color = ( $bmi_cat === 'Bình thường' ) ? '#10b981' : '#f59e0b';
			$html .= '<div class="bcpro-rich-meter"><div class="bcpro-rich-meter-lbl">BMI</div><div class="bcpro-rich-tile-val" style="color:' . esc_attr( $bmi_color ) . '">' . self::e( number_format( $bmi, 1 ) ) . ' (' . self::e( $bmi_cat ) . ')</div></div>';
		}
		$html .= '  </div></div>';

		if ( $summary !== '' ) {
			$html .= '<p class="bcpro-rich-lead">' . self::e( $summary ) . '</p>';
		}
		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['strengths'] ) ) {
			$html .= '<div class="bcpro-rich-card is-emerald"><h4>💪 Điểm mạnh sức khỏe</h4>' . self::pills( $d['strengths'], 'is-emerald' ) . '</div>';
		}
		if ( ! empty( $d['risk_areas'] ) ) {
			$html .= '<div class="bcpro-rich-card is-rose"><h4>⚠️ Vùng cần chú ý</h4>' . self::pills( $d['risk_areas'], 'is-rose' ) . '</div>';
		}
		$html .= '</div>';
		if ( ! empty( $d['priority_actions'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🎯 Hành động ưu tiên</h4>' . self::bullets( $d['priority_actions'] ) . '</div>';
		}
		if ( $forecast ) {
			$html .= '<div class="bcpro-rich-timing"><h4 class="bcpro-rich-h4">📅 Dự báo sức khỏe</h4><div class="bcpro-rich-grid grid-3">';
			foreach ( array(
				array( 'lbl' => 'Giai đoạn hiện tại', 'val' => $forecast['current_phase']    ?? '', 'cls' => 'is-now' ),
				array( 'lbl' => 'Thời kỳ thuận lợi',  'val' => $forecast['favorable_period'] ?? '', 'cls' => 'is-fav' ),
				array( 'lbl' => 'Cần cẩn thận',       'val' => $forecast['caution_period']   ?? '', 'cls' => 'is-cau' ),
			) as $c ) {
				$html .= '<div class="bcpro-rich-tile ' . $c['cls'] . '"><div class="bcpro-rich-tile-lbl">' . self::e( $c['lbl'] ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $c['val'] ) . '</div></div>';
			}
			$html .= '</div></div>';
		}
		return $html;
	}

	/**
	 * health_detail_map:
	 * { physical_health{score,issues[],recommendations[]}, mental_health{score,emotional_patterns[],recommendations[]},
	 *   nutrition_plan{daily_calories_target,macros{protein_pct,carb_pct,fat_pct},foods_to_eat[],foods_to_avoid[]},
	 *   exercise_plan{type,frequency_per_week,duration_min,activities[]},
	 *   sleep_optimization[], stress_management[] }
	 */
	public static function render_health_detail_map( $d ) {
		$html = '';
		$ph   = self::arr( $d['physical_health'] ?? array() );
		$mh   = self::arr( $d['mental_health']   ?? array() );
		$np   = self::arr( $d['nutrition_plan']  ?? array() );
		$ep   = self::arr( $d['exercise_plan']   ?? array() );

		if ( $ph || $mh ) {
			$html .= '<div class="bcpro-rich-grid grid-2">';
			if ( $ph ) {
				$score = (int) ( $ph['score'] ?? 0 );
				$color = self::score_color( $score, 10 );
				$html .= '<div class="bcpro-rich-card"><h4>🏋️ Sức khỏe thể chất <span class="bcpro-rich-bar-num" style="color:' . esc_attr( $color ) . '">' . self::e( $score ) . '/10</span></h4>';
				if ( ! empty( $ph['issues'] ) ) { $html .= '<div class="bcpro-rich-bar-desc"><strong>Vấn đề:</strong></div>' . self::pills( $ph['issues'], 'is-amber' ); }
				if ( ! empty( $ph['recommendations'] ) ) { $html .= '<div class="bcpro-rich-bar-desc"><strong>Khuyến nghị:</strong></div>' . self::bullets( $ph['recommendations'] ); }
				$html .= '</div>';
			}
			if ( $mh ) {
				$score = (int) ( $mh['score'] ?? 0 );
				$color = self::score_color( $score, 10 );
				$html .= '<div class="bcpro-rich-card"><h4>🧠 Sức khỏe tinh thần <span class="bcpro-rich-bar-num" style="color:' . esc_attr( $color ) . '">' . self::e( $score ) . '/10</span></h4>';
				if ( ! empty( $mh['emotional_patterns'] ) ) { $html .= '<div class="bcpro-rich-bar-desc"><strong>Patterns cảm xúc:</strong></div>' . self::pills( $mh['emotional_patterns'], 'is-violet' ); }
				if ( ! empty( $mh['recommendations'] ) ) { $html .= '<div class="bcpro-rich-bar-desc"><strong>Khuyến nghị:</strong></div>' . self::bullets( $mh['recommendations'] ); }
				$html .= '</div>';
			}
			$html .= '</div>';
		}

		if ( $np ) {
			$html .= '<div class="bcpro-rich-card"><h4>🥗 Kế hoạch dinh dưỡng</h4>';
			if ( ! empty( $np['daily_calories_target'] ) ) {
				$html .= '<div class="bcpro-rich-tile"><div class="bcpro-rich-tile-lbl">Calo mục tiêu / ngày</div><div class="bcpro-rich-tile-val">' . self::e( $np['daily_calories_target'] ) . ' kcal</div></div>';
			}
			$macros = self::arr( $np['macros'] ?? array() );
			if ( $macros ) {
				$html .= '<div class="bcpro-rich-grid grid-3">';
				foreach ( array( 'protein_pct' => '🥩 Protein', 'carb_pct' => '🌾 Carb', 'fat_pct' => '🫒 Fat' ) as $k => $lbl ) {
					$html .= '<div class="bcpro-rich-tile is-milestone"><div class="bcpro-rich-tile-lbl">' . $lbl . '</div><div class="bcpro-rich-tile-val">' . self::e( $macros[ $k ] ?? 0 ) . '%</div></div>';
				}
				$html .= '</div>';
			}
			if ( ! empty( $np['foods_to_eat'] ) ) { $html .= '<div class="bcpro-rich-bar-desc"><strong>✅ Nên ăn:</strong></div>' . self::pills( $np['foods_to_eat'], 'is-emerald' ); }
			if ( ! empty( $np['foods_to_avoid'] ) ) { $html .= '<div class="bcpro-rich-bar-desc"><strong>❌ Hạn chế:</strong></div>' . self::pills( $np['foods_to_avoid'], 'is-rose' ); }
			$html .= '</div>';
		}

		if ( $ep ) {
			$html .= '<div class="bcpro-rich-card is-cyan"><h4>🏃 Kế hoạch vận động</h4>';
			$html .= '<div class="bcpro-rich-grid grid-3">';
			foreach ( array(
				array( 'lbl' => 'Loại hình',         'val' => $ep['type']               ?? '' ),
				array( 'lbl' => 'Buổi/tuần',          'val' => ( $ep['frequency_per_week'] ?? '' ) . ' buổi' ),
				array( 'lbl' => 'Thời lượng',         'val' => ( $ep['duration_min'] ?? '' ) . ' phút' ),
			) as $ti ) {
				$html .= '<div class="bcpro-rich-tile is-milestone"><div class="bcpro-rich-tile-lbl">' . self::e( $ti['lbl'] ) . '</div><div class="bcpro-rich-tile-val">' . self::e( $ti['val'] ) . '</div></div>';
			}
			$html .= '</div>';
			if ( ! empty( $ep['activities'] ) ) { $html .= self::pills( $ep['activities'], 'is-cyan' ); }
			$html .= '</div>';
		}

		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['sleep_optimization'] ) ) {
			$html .= '<div class="bcpro-rich-card is-violet"><h4>😴 Tối ưu giấc ngủ</h4>' . self::bullets( $d['sleep_optimization'] ) . '</div>';
		}
		if ( ! empty( $d['stress_management'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>🧘 Quản lý stress</h4>' . self::bullets( $d['stress_management'] ) . '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	// =====================================================================
	// [2026-07-17 Johnny Chu] M-BCM.J7 — BabyCoach renderers
	// =====================================================================

	/**
	 * baby_overview:
	 * { summary, age_months, age_corrected_months, growth_status{weight_percentile,height_percentile,overall_status},
	 *   development_score, milestones_reached[], milestones_upcoming[], parenting_tips[], health_watch[] }
	 */
	public static function render_baby_overview( $d ) {
		$summary  = (string) ( $d['summary'] ?? '' );
		$age_m    = (float) ( $d['age_months'] ?? 0 );
		$age_c    = (float) ( $d['age_corrected_months'] ?? 0 );
		$score    = (int) ( $d['development_score'] ?? 0 );
		$gs       = self::arr( $d['growth_status'] ?? array() );
		$status   = (string) ( $gs['overall_status'] ?? '' );
		$stat_cls = ( $status === 'Tốt' ) ? '#10b981' : ( ( $status === 'Cần theo dõi' ) ? '#f59e0b' : '#ef4444' );

		$html  = '<div class="bcpro-rich-hero">';
		$html .= '  <div class="bcpro-rich-hero-left">';
		if ( $score > 0 ) {
			$html .= '<div class="bcpro-rich-score" style="--cc-pct:' . self::pct( $score ) . '%"><div class="bcpro-rich-score-num">' . self::e( $score ) . '</div><div class="bcpro-rich-score-cap">Dev Score</div></div>';
		}
		$html .= '  </div>';
		$html .= '  <div class="bcpro-rich-hero-right">';
		if ( $age_m > 0 ) {
			$html .= '<div class="bcpro-rich-archetype">👶 Tuổi: <strong>' . self::e( $age_m ) . ' tháng</strong>';
			if ( $age_c > 0 && abs( $age_c - $age_m ) > 0.5 ) {
				$html .= ' (hiệu chỉnh: ' . self::e( $age_c ) . ' tháng)';
			}
			$html .= '</div>';
		}
		if ( $status !== '' ) {
			$html .= '<div class="bcpro-rich-archetype">📊 Tình trạng: <strong style="color:' . esc_attr( $stat_cls ) . '">' . self::e( $status ) . '</strong></div>';
		}
		if ( $gs ) {
			$html .= '<div class="bcpro-rich-meter"><div class="bcpro-rich-meter-lbl">Cân nặng · Chiều cao (percentile)</div><div class="bcpro-rich-tile-val">' . self::e( $gs['weight_percentile'] ?? '' ) . ' · ' . self::e( $gs['height_percentile'] ?? '' ) . '</div></div>';
		}
		$html .= '  </div></div>';

		if ( $summary !== '' ) {
			$html .= '<p class="bcpro-rich-lead">' . self::e( $summary ) . '</p>';
		}
		$html .= '<div class="bcpro-rich-grid grid-2">';
		if ( ! empty( $d['milestones_reached'] ) ) {
			$html .= '<div class="bcpro-rich-card is-emerald"><h4>✅ Mốc phát triển đạt được</h4>' . self::bullets( $d['milestones_reached'] ) . '</div>';
		}
		if ( ! empty( $d['milestones_upcoming'] ) ) {
			$html .= '<div class="bcpro-rich-card is-cyan"><h4>🔜 Mốc sắp đạt</h4>' . self::bullets( $d['milestones_upcoming'] ) . '</div>';
		}
		$html .= '</div>';
		if ( ! empty( $d['parenting_tips'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>💡 Gợi ý cho bố mẹ</h4>' . self::bullets( $d['parenting_tips'] ) . '</div>';
		}
		if ( ! empty( $d['health_watch'] ) ) {
			$html .= '<div class="bcpro-rich-card is-amber"><h4>👁️ Theo dõi sức khỏe</h4>' . self::bullets( $d['health_watch'] ) . '</div>';
		}
		return $html;
	}

	/**
	 * baby_growth_map:
	 * { physical_development{gross_motor[],fine_motor[],status,tips[]},
	 *   cognitive_development{milestones[],activities[],tips[]},
	 *   social_emotional{behaviors[],bonding_tips[]},
	 *   language_development{current_stage,sounds_words[],activities[]},
	 *   stimulation_activities[], nutrition_guide{feeding_type,foods_appropriate[],foods_avoid[],schedule} }
	 */
	public static function render_baby_growth_map( $d ) {
		$html = '';
		$phys = self::arr( $d['physical_development']  ?? array() );
		$cog  = self::arr( $d['cognitive_development'] ?? array() );
		$soc  = self::arr( $d['social_emotional']      ?? array() );
		$lang = self::arr( $d['language_development']  ?? array() );
		$nutr = self::arr( $d['nutrition_guide']       ?? array() );

		$sections = array(
			array( 'data' => $phys, 'icon' => '🏃', 'title' => 'Vận động thể chất', 'cls' => 'is-cyan' ),
			array( 'data' => $cog,  'icon' => '🧠', 'title' => 'Nhận thức',          'cls' => 'is-violet' ),
			array( 'data' => $soc,  'icon' => '👥', 'title' => 'Xã hội & Cảm xúc',  'cls' => 'is-rose' ),
			array( 'data' => $lang, 'icon' => '💬', 'title' => 'Ngôn ngữ',           'cls' => 'is-emerald' ),
		);
		$html .= '<div class="bcpro-rich-grid grid-2">';
		foreach ( $sections as $sec ) {
			if ( empty( $sec['data'] ) ) { continue; }
			$html .= '<div class="bcpro-rich-card ' . $sec['cls'] . '"><h4>' . $sec['icon'] . ' ' . self::e( $sec['title'] ) . '</h4>';
			foreach ( $sec['data'] as $k => $v ) {
				if ( is_array( $v ) && ! empty( $v ) ) {
					$html .= '<div class="bcpro-rich-bar-desc"><strong>' . self::e( $k ) . ':</strong></div>' . self::pills( $v );
				} elseif ( is_string( $v ) && $v !== '' ) {
					$html .= '<div class="bcpro-rich-bar-desc"><strong>' . self::e( $k ) . ':</strong> ' . self::e( $v ) . '</div>';
				}
			}
			$html .= '</div>';
		}
		$html .= '</div>';

		if ( ! empty( $d['stimulation_activities'] ) ) {
			$html .= '<div class="bcpro-rich-card"><h4>🎮 Hoạt động kích thích</h4>' . self::pills( $d['stimulation_activities'], 'is-cyan' ) . '</div>';
		}

		if ( $nutr ) {
			$html .= '<div class="bcpro-rich-card"><h4>🍼 Hướng dẫn dinh dưỡng</h4>';
			if ( ! empty( $nutr['feeding_type'] ) ) {
				$html .= '<div class="bcpro-rich-archetype">Hình thức: <strong>' . self::e( $nutr['feeding_type'] ) . '</strong></div>';
			}
			if ( ! empty( $nutr['schedule'] ) ) {
				$html .= '<div class="bcpro-rich-bar-desc"><strong>Lịch ăn:</strong> ' . self::e( $nutr['schedule'] ) . '</div>';
			}
			if ( ! empty( $nutr['foods_appropriate'] ) ) { $html .= '<div class="bcpro-rich-bar-desc"><strong>✅ Phù hợp:</strong></div>' . self::pills( $nutr['foods_appropriate'], 'is-emerald' ); }
			if ( ! empty( $nutr['foods_avoid'] ) ) { $html .= '<div class="bcpro-rich-bar-desc"><strong>❌ Tránh:</strong></div>' . self::pills( $nutr['foods_avoid'], 'is-rose' ); }
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
