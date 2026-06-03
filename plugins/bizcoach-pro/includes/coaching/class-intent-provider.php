<?php
/**
 * BizCoach Pro — Intent Provider (Sprint I).
 *
 * Derives goal patterns + plans from active templates in the registry.
 * Each template surfaces 1 goal `create_coach_map_<slug>` matched by:
 *  - explicit `intent_patterns[]` regex array in the JSON template, OR
 *  - default narrow pattern built from the template label.
 *
 * Tool callback delegates to Persona Provider's tool_create_coach_map().
 *
 * @since 0.1.0 (PHASE-0.36 / R-PROD-HUB)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Intent_Provider' ) ) { return; }

class BizCoach_Pro_Intent_Provider extends BizCity_Intent_Provider {

	public function get_id() { return 'bizcoach_pro'; }

	public function get_name() {
		return __( 'BizCoach Pro — Producer Hub', 'bizcoach-pro' );
	}

	public function get_profile_page_url() { return ''; }

	public function get_profile_context( $user_id ) { return array(); }

	/**
	 * One regex per active template. specificity=narrow → 0.90 confidence cap
	 * (avoid hijacking generic "bản đồ" queries).
	 */
	public function get_goal_patterns() {
		if ( ! class_exists( 'BizCoach_Pro_Template_Registry' ) ) { return array(); }
		$out = array();
		foreach ( BizCoach_Pro_Template_Registry::all( 'active' ) as $tpl ) {
			$slug  = isset( $tpl['slug'] )  ? (string) $tpl['slug']  : '';
			$label = isset( $tpl['label'] ) ? (string) $tpl['label'] : $slug;
			if ( $slug === '' ) { continue; }
			$goal_id = 'create_coach_map_' . $slug;

			$patterns = isset( $tpl['intent_patterns'] ) && is_array( $tpl['intent_patterns'] )
				? $tpl['intent_patterns'] : array();
			if ( ! empty( $patterns ) ) {
				foreach ( $patterns as $regex ) {
					if ( ! is_string( $regex ) || $regex === '' ) { continue; }
					$out[ $regex ] = self::pattern_meta( $goal_id, $label, 'exact' );
				}
				continue;
			}
			$kw_label = preg_quote( self::strip_vi( $label ), '/' );
			$kw_slug  = preg_quote( str_replace( '_', ' ', $slug ), '/' );
			$regex = '/(?:tao|lap|sinh|build|create).{0,20}(?:ban do|bản đồ|map).{0,30}(?:'
				. $kw_label . '|' . $kw_slug . ')/iu';
			$out[ $regex ] = self::pattern_meta( $goal_id, $label, 'narrow' );
		}
		return $out;
	}

	public function get_plans() {
		if ( ! class_exists( 'BizCoach_Pro_Template_Registry' ) ) { return array(); }
		$out = array();
		foreach ( BizCoach_Pro_Template_Registry::all( 'active' ) as $tpl ) {
			$slug = isset( $tpl['slug'] ) ? (string) $tpl['slug'] : '';
			if ( $slug === '' ) { continue; }
			$req = array(); $opt = array();
			foreach ( (array) ( $tpl['questions'] ?? array() ) as $q ) {
				if ( ! is_array( $q ) || empty( $q['key'] ) ) { continue; }
				if ( ! empty( $q['required'] ) ) { $req[] = (string) $q['key']; }
				else { $opt[] = (string) $q['key']; }
			}
			$out[ 'create_coach_map_' . $slug ] = array(
				'required_slots' => $req,
				'optional_slots' => $opt,
				'tool'           => 'create_coach_map_' . $slug,
				'ai_compose'     => false,
				'slot_order'     => array_merge( $req, $opt ),
			);
		}
		return $out;
	}

	public function get_tools() {
		if ( ! class_exists( 'BizCoach_Pro_Template_Registry' )
			|| ! class_exists( 'BizCoach_Pro_Persona_Provider' ) ) {
			return array();
		}
		$persona = new BizCoach_Pro_Persona_Provider();
		$out = array();
		foreach ( BizCoach_Pro_Template_Registry::all( 'active' ) as $tpl ) {
			$slug  = isset( $tpl['slug'] )  ? (string) $tpl['slug']  : '';
			$label = isset( $tpl['label'] ) ? (string) $tpl['label'] : $slug;
			if ( $slug === '' ) { continue; }
			$tool_name = 'create_coach_map_' . $slug;

			$slots = array();
			foreach ( (array) ( $tpl['questions'] ?? array() ) as $q ) {
				if ( ! is_array( $q ) || empty( $q['key'] ) ) { continue; }
				$slots[ (string) $q['key'] ] = array(
					'type'     => self::question_type_to_slot_type( $q['type'] ?? 'text' ),
					'required' => ! empty( $q['required'] ),
					'prompt'   => isset( $q['label'] ) ? (string) $q['label'] : (string) $q['key'],
				);
			}

			$out[ $tool_name ] = array(
				'label'         => sprintf( 'Tạo bản đồ %s', $label ),
				'tool_type'     => 'atomic',
				'trust_tier'    => 2,
				'input_fields'  => $slots,
				'output_fields' => array(
					'coachee_id'    => array( 'type' => 'int' ),
					'public_url'    => array( 'type' => 'url' ),
					'template_slug' => array( 'type' => 'string' ),
				),
				'callback'      => function ( array $slots ) use ( $persona, $slug ) {
					$res = $persona->tool_create_coach_map(
						array( 'template_slug' => $slug, 'payload' => $slots ),
						array( 'user_id' => get_current_user_id() )
					);
					if ( is_wp_error( $res ) ) {
						return array(
							'success'  => false,
							'complete' => false,
							'message'  => $res->get_error_message(),
							'data'     => array( 'code' => $res->get_error_code() ),
						);
					}
					$res['public_url'] = class_exists( 'BizCoach_Pro_Artifact_Service' )
						? BizCoach_Pro_Artifact_Service::get_public_url( (int) $res['coachee_id'] )
						: '';
					return array(
						'success'  => true,
						'complete' => true,
						'message'  => sprintf( 'Đã tạo bản đồ %s (#%d).', $slug, (int) $res['coachee_id'] ),
						'data'     => $res,
					);
				},
				'slots'         => $slots,
			);
		}
		return $out;
	}

	/* ─── helpers ─── */

	private static function pattern_meta( $goal_id, $label, $specificity ) {
		return array(
			'goal'            => $goal_id,
			'label'           => sprintf( 'Tạo bản đồ %s', $label ),
			'description'     => sprintf( 'Tạo coach map theo template %s', $label ),
			'extract'         => array(),
			'specificity'     => $specificity,
			'domain_keywords' => array( 'bản đồ', 'ban do', 'map', 'coach' ),
		);
	}

	private static function strip_vi( $s ) {
		$from = array( 'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
			'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
			'ì','í','ị','ỉ','ĩ',
			'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
			'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
			'ỳ','ý','ỵ','ỷ','ỹ','đ',
		);
		$to = array( 'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
			'e','e','e','e','e','e','e','e','e','e','e',
			'i','i','i','i','i',
			'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
			'u','u','u','u','u','u','u','u','u','u','u',
			'y','y','y','y','y','d',
		);
		return str_replace( $from, $to, mb_strtolower( (string) $s, 'UTF-8' ) );
	}

	private static function question_type_to_slot_type( $type ) {
		$map = array(
			'text' => 'string', 'textarea' => 'string', 'select' => 'string',
			'date' => 'string', 'time' => 'string',
			'number' => 'int', 'email' => 'string', 'tel' => 'string',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : 'string';
	}
}
