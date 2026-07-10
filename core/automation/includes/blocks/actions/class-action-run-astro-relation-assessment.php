<?php
/**
 * Action: Run Astro Relation Assessment.
 *
 * Shared relation flow wrapper for Automation:
 * - resolve subject/partner pair
 * - sync transit evidence for pair
 * - compose relation answer with 4 mandatory lenses
 *
 * Output vars:
 *   {{nX.ok}}
 *   {{nX.subject_coachee_id}}, {{nX.partner_coachee_id}}
 *   {{nX.subject_name}}, {{nX.partner_name}}
 *   {{nX.subject_block_md}}, {{nX.relation_block_md}}, {{nX.final_answer_md}}
 *   {{nX.subject_natal_url}}, {{nX.partner_natal_url}}
 *   {{nX.subject_transit_url}}, {{nX.partner_transit_url}}
 *   {{nX.relation_lenses}}, {{nX.citations_json}}, {{nX.source_marker}}, {{nX.sync_status}}
 *   {{nX.error_code}}, {{nX.error_message}}
 *
 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — new action.run_astro_relation_assessment.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Run_Astro_Relation_Assessment extends BizCity_Automation_Block_Base {

	public function id(): string { return 'action.run_astro_relation_assessment'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Astro Relation Assessment',
			'short'    => 'run_astro_relation_assessment',
			'category' => 'astro',
			'color'    => '#7c3aed',
			'icon'     => 'users',
			'defaults' => array(
				'label' => 'Relation assessment',
				'subject_coachee_id' => '{{n1.coachee_id}}',
				'partner_coachee_id' => '',
				'partner_name' => '',
				'query' => '{{trigger.text}}',
				'relation_lenses' => 'work,love,business,hr',
				'source_marker' => 'zalobot_chat',
				'start_offset' => 1,
				'sync_days' => 7,
			),
			'fields' => array(
				array( 'name' => 'label', 'label' => 'Ten hien thi', 'type' => 'text' ),
				array( 'name' => 'subject_coachee_id', 'label' => 'Subject coachee_id', 'type' => 'text', 'hint' => '{{n1.coachee_id}}' ),
				array( 'name' => 'partner_coachee_id', 'label' => 'Partner coachee_id', 'type' => 'text' ),
				array( 'name' => 'partner_name', 'label' => 'Ten doi tac (fallback)', 'type' => 'text' ),
				array( 'name' => 'query', 'label' => 'Cau hoi relation', 'type' => 'textarea', 'hint' => '{{trigger.text}}' ),
				array( 'name' => 'relation_lenses', 'label' => 'Lenses CSV', 'type' => 'text', 'hint' => 'work,love,business,hr' ),
				array( 'name' => 'source_marker', 'label' => 'Source marker', 'type' => 'select',
					'options' => array(
						array( 'value' => 'zalobot_chat', 'label' => 'zalobot_chat' ),
						array( 'value' => 'twinbrain_chat', 'label' => 'twinbrain_chat' ),
						array( 'value' => 'automation', 'label' => 'automation' ),
					),
				),
				array( 'name' => 'start_offset', 'label' => 'Start offset (days)', 'type' => 'number' ),
				array( 'name' => 'sync_days', 'label' => 'Sync window days', 'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — execute relation block.
		$query = trim( (string) $this->resolve( $data['query'] ?? '{{trigger.text}}', $ctx ) );
		if ( $query === '' ) {
			$query = 'Danh gia do hop profile nay';
		}

		$subject_coachee_id = (int) $this->resolve( $data['subject_coachee_id'] ?? 0, $ctx );
		$partner_coachee_id = (int) $this->resolve( $data['partner_coachee_id'] ?? 0, $ctx );
		$partner_name = trim( (string) $this->resolve( $data['partner_name'] ?? '', $ctx ) );
		$chat_id = trim( (string) ( $ctx['trigger']['chat_id'] ?? '' ) );
		$user_id = $this->resolve_user_id( $ctx, $chat_id );

		$source_marker = sanitize_key( (string) ( $data['source_marker'] ?? 'zalobot_chat' ) );
		if ( ! in_array( $source_marker, array( 'zalobot_chat', 'twinbrain_chat', 'automation' ), true ) ) {
			$source_marker = 'automation';
		}

		$relation_lenses = $this->normalize_lenses_csv(
			(string) $this->resolve( $data['relation_lenses'] ?? 'work,love,business,hr', $ctx )
		);
		$sync_days = max( 1, min( 31, (int) ( $data['sync_days'] ?? 7 ) ) );
		$start_offset = max( 0, (int) ( $data['start_offset'] ?? 1 ) );
		$trace_id = 'auto_rel_' . uniqid( '', true );

		if ( ! class_exists( 'BizCity_TwinBrain_Astro_Relation_Assessment_Service' ) ) {
			return $this->fail_result( 'relation_service_missing', 'Relation service chua duoc load.' );
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Astro_Relation_Composer' ) ) {
			return $this->fail_result( 'relation_composer_missing', 'Relation composer chua duoc load.' );
		}

		$svc = BizCity_TwinBrain_Astro_Relation_Assessment_Service::instance();
		$res = $svc->assess_by_query( $query, array(
			'user_id' => $user_id,
			'chat_id' => $chat_id,
			'trace_id' => $trace_id,
			'subject_coachee_id' => $subject_coachee_id,
			'partner_coachee_id' => $partner_coachee_id,
			'partner_name_hint' => $partner_name,
			'relation_lenses' => $relation_lenses,
			'source_marker' => $source_marker,
			'sync_days' => $sync_days,
			'start_offset' => $start_offset,
			'surface' => 'automation_zalobot',
		) );

		if ( empty( $res['success'] ) ) {
			$reason = (string) ( $res['_degraded'] ?? 'relation_assess_failed' );
			$msg = (string) ( $res['message'] ?? 'Khong danh gia duoc relation vi thieu du lieu.' );
			$this->note_event( 'run_astro_relation_failed', array(
				'reason' => $reason,
				'message' => $msg,
				'subject_coachee_id' => $subject_coachee_id,
				'partner_coachee_id' => $partner_coachee_id,
			) );
			return $this->fail_result( $reason, $msg );
		}

		$composer = BizCity_TwinBrain_Astro_Relation_Composer::instance();
		$composed = $composer->compose( $res, array(
			'query' => $query,
			'trace_id' => $trace_id,
			'surface' => 'automation_zalobot',
		) );

		$subject = isset( $res['subject'] ) && is_array( $res['subject'] ) ? $res['subject'] : array();
		$partner = isset( $res['partner'] ) && is_array( $res['partner'] ) ? $res['partner'] : array();
		$citations = isset( $res['citations'] ) && is_array( $res['citations'] ) ? $res['citations'] : array();
		$final_answer_md = (string) ( $composed['final_answer_md'] ?? '' );
		if ( $final_answer_md === '' ) {
			$final_answer_md = trim( (string) ( $composed['subject_block_md'] ?? '' ) . "\n\n" . (string) ( $composed['relation_block_md'] ?? '' ) );
		}

		$this->note_event( 'run_astro_relation_done', array(
			'subject_coachee_id' => (int) ( $subject['coachee_id'] ?? 0 ),
			'partner_coachee_id' => (int) ( $partner['coachee_id'] ?? 0 ),
			'sync_status' => (string) ( $res['sync_status'] ?? '' ),
			'source_marker' => (string) ( $res['source_marker'] ?? $source_marker ),
			'fallback' => (string) ( $composed['fallback'] ?? '' ),
		) );

		return array(
			'ok' => 1,
			'analysis_mode' => 'relation_profile',
			'subject_coachee_id' => (int) ( $subject['coachee_id'] ?? 0 ),
			'partner_coachee_id' => (int) ( $partner['coachee_id'] ?? 0 ),
			'subject_name' => (string) ( $subject['name'] ?? '' ),
			'partner_name' => (string) ( $partner['name'] ?? '' ),
			'subject_block_md' => (string) ( $composed['subject_block_md'] ?? '' ),
			'relation_block_md' => (string) ( $composed['relation_block_md'] ?? '' ),
			'final_answer_md' => $final_answer_md,
			'relation_lenses' => implode( ',', (array) ( $res['relation_lenses'] ?? $relation_lenses ) ),
			'subject_natal_url' => (string) ( $subject['natal_url'] ?? '' ),
			'partner_natal_url' => (string) ( $partner['natal_url'] ?? '' ),
			'subject_transit_url' => (string) ( $subject['transit_url'] ?? '' ),
			'partner_transit_url' => (string) ( $partner['transit_url'] ?? '' ),
			'citations_json' => wp_json_encode( $citations, JSON_UNESCAPED_UNICODE ),
			'source_marker' => (string) ( $res['source_marker'] ?? $source_marker ),
			'sync_status' => (string) ( $res['sync_status'] ?? 'failed' ),
			'error_code' => '',
			'error_message' => '',
		);
	}

	/**
	 * @param array  $ctx
	 * @param string $chat_id
	 * @return int
	 */
	private function resolve_user_id( array $ctx, $chat_id ) {
		$user_id = (int) ( $ctx['trigger']['wp_user_id'] ?? $ctx['_owner_user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			return $user_id;
		}
		if ( $chat_id !== '' && class_exists( 'BizCity_User_Resolver' ) ) {
			return (int) BizCity_User_Resolver::instance()->resolve( $chat_id );
		}
		return 0;
	}

	/**
	 * @param string $csv
	 * @return array
	 */
	private function normalize_lenses_csv( $csv ) {
		$parts = preg_split( '/[,\s]+/', (string) $csv );
		$parts = is_array( $parts ) ? $parts : array();
		$out = array();
		foreach ( $parts as $p ) {
			$l = sanitize_key( (string) $p );
			if ( $l === 'career' ) { $l = 'work'; }
			if ( in_array( $l, array( 'work', 'love', 'business', 'hr' ), true ) ) {
				$out[] = $l;
			}
		}
		if ( empty( $out ) ) {
			$out = array( 'work', 'love', 'business', 'hr' );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string $code
	 * @param string $message
	 * @return array
	 */
	private function fail_result( $code, $message ) {
		return array(
			'ok' => 0,
			'analysis_mode' => 'relation_profile',
			'subject_coachee_id' => 0,
			'partner_coachee_id' => 0,
			'subject_name' => '',
			'partner_name' => '',
			'subject_block_md' => '',
			'relation_block_md' => '',
			'final_answer_md' => '',
			'relation_lenses' => 'work,love,business,hr',
			'subject_natal_url' => '',
			'partner_natal_url' => '',
			'subject_transit_url' => '',
			'partner_transit_url' => '',
			'citations_json' => '[]',
			'source_marker' => 'automation',
			'sync_status' => 'failed',
			'error_code' => (string) $code,
			'error_message' => (string) $message,
		);
	}
}
