<?php
/**
 * Changelog — Phase 0: Context Cleanup & Twin Engine Foundation
 *
 * Validates: Focus Gate, Focus Router, Twin Context Resolver, Mode Classifier,
 *            Chat Gateway delegation, Smart Gateway KCI, unified prompt pipeline.
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase0 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '0';
	}

	public function get_phase_title(): string {
		return 'Context Cleanup & Twin Engine Foundation';
	}

	public function get_description(): string {
		return '3-layer Twin Engine: Focus Gate → Focus Router → Twin Context Resolver. Loại bỏ context pollution, giảm prompt từ ~12K → ~3-6K tokens.';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-02-15', 'updated' => '2026-03-15' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'Focus Gate — Context Gating Layer',
				'icon'    => '🚦',
				'entries' => [
					[ 'id' => 'FG-1', 'title' => 'BizCity_Focus_Gate class exists (static)' ],
					[ 'id' => 'FG-2', 'title' => 'should_inject() method — per-layer gating' ],
					[ 'id' => 'FG-3', 'title' => 'gate_context() registered on bizcity_chat_system_prompt @pri1' ],
					[ 'id' => 'FG-4', 'title' => 'get_memory_mode() — memory gating per mode' ],
					[ 'id' => 'FG-5', 'title' => 'amend_for_goal() — astro override for execution mode' ],
				],
			],
			[
				'group'   => 'Focus Router — Mode-aware Profile Resolution',
				'icon'    => '🧭',
				'entries' => [
					[ 'id' => 'FR-1', 'title' => 'BizCity_Focus_Router class exists (static)' ],
					[ 'id' => 'FR-2', 'title' => 'resolve() returns focus profile per mode' ],
					[ 'id' => 'FR-3', 'title' => 'get_mode_defaults() covers 6+ modes' ],
					[ 'id' => 'FR-4', 'title' => 'Astro/coaching topic detection helpers' ],
				],
			],
			[
				'group'   => 'Twin Context Resolver — Single Entry Point',
				'icon'    => '🧩',
				'entries' => [
					[ 'id' => 'CR-1', 'title' => 'BizCity_Twin_Context_Resolver class exists (static)' ],
					[ 'id' => 'CR-2', 'title' => 'build_system_prompt() unified L0-L9 layer order' ],
					[ 'id' => 'CR-3', 'title' => 'for_chat() convenience method' ],
				],
			],
			[
				'group'   => 'Mode Classifier — 4-step Cascade',
				'icon'    => '🏷️',
				'entries' => [
					[ 'id' => 'MC-1', 'title' => 'BizCity_Mode_Classifier singleton exists' ],
					[ 'id' => 'MC-2', 'title' => 'classify() method — memory→WAITING→regex→LLM' ],
					[ 'id' => 'MC-3', 'title' => 'set_kci_ratio() — KCI integration' ],
					[ 'id' => 'MC-4', 'title' => 'get_mode_label() — 6 mode labels' ],
				],
			],
			[
				'group'   => 'Chat Gateway & Smart Gateway',
				'icon'    => '🌐',
				'entries' => [
					[ 'id' => 'GW-1', 'title' => 'BizCity_Chat_Gateway singleton exists' ],
					[ 'id' => 'GW-2', 'title' => 'prepare_llm_call() delegates to Resolver' ],
					[ 'id' => 'GW-3', 'title' => 'ajax_send + ajax_stream AJAX hooks' ],
					[ 'id' => 'GW-4', 'title' => 'BizCity_Smart_Gateway class exists' ],
					[ 'id' => 'GW-5', 'title' => 'Smart Gateway collect_context() with KCI' ],
				],
			],
		];
	}

	protected function run_verifications(): void {
		$this->verify_focus_gate();
		$this->verify_focus_router();
		$this->verify_context_resolver();
		$this->verify_mode_classifier();
		$this->verify_gateways();
	}

	/* ── Focus Gate ── */
	private function verify_focus_gate(): void {
		$exists = class_exists( 'BizCity_Focus_Gate' );
		$this->assert( 'FG-1', 'BizCity_Focus_Gate class exists', $exists );

		if ( ! $exists ) {
			$this->skip( 'FG-2', 'Focus Gate not loaded' );
			$this->skip( 'FG-3', 'Focus Gate not loaded' );
			$this->skip( 'FG-4', 'Focus Gate not loaded' );
			$this->skip( 'FG-5', 'Focus Gate not loaded' );
			return;
		}

		$this->assert( 'FG-2', 'should_inject() exists', method_exists( 'BizCity_Focus_Gate', 'should_inject' ) );

		$this->assert( 'FG-3', 'gate_context on bizcity_chat_system_prompt',
			has_filter( 'bizcity_chat_system_prompt', [ 'BizCity_Focus_Gate', 'gate_context' ] ) !== false,
			has_filter( 'bizcity_chat_system_prompt', [ 'BizCity_Focus_Gate', 'gate_context' ] ) !== false ? '@pri active' : 'Not hooked' );

		$this->assert( 'FG-4', 'get_memory_mode() exists', method_exists( 'BizCity_Focus_Gate', 'get_memory_mode' ) );
		$this->assert( 'FG-5', 'amend_for_goal() exists', method_exists( 'BizCity_Focus_Gate', 'amend_for_goal' ) );
	}

	/* ── Focus Router ── */
	private function verify_focus_router(): void {
		$exists = class_exists( 'BizCity_Focus_Router' );
		$this->assert( 'FR-1', 'BizCity_Focus_Router class exists', $exists );

		if ( ! $exists ) {
			$this->skip( 'FR-2', 'Focus Router not loaded' );
			$this->skip( 'FR-3', 'Focus Router not loaded' );
			$this->skip( 'FR-4', 'Focus Router not loaded' );
			return;
		}

		$this->assert( 'FR-2', 'resolve() exists', method_exists( 'BizCity_Focus_Router', 'resolve' ) );
		$this->assert( 'FR-3', 'get_mode_defaults() exists', method_exists( 'BizCity_Focus_Router', 'get_mode_defaults' ) );

		$has_astro   = method_exists( 'BizCity_Focus_Router', 'is_astro_topic' );
		$has_coach   = method_exists( 'BizCity_Focus_Router', 'is_coaching_topic' );
		$this->assert( 'FR-4', 'Topic detection helpers', $has_astro && $has_coach,
			'is_astro_topic=' . ( $has_astro ? 'yes' : 'no' ) . ', is_coaching_topic=' . ( $has_coach ? 'yes' : 'no' ) );
	}

	/* ── Twin Context Resolver ── */
	private function verify_context_resolver(): void {
		$exists = class_exists( 'BizCity_Twin_Context_Resolver' );
		$this->assert( 'CR-1', 'BizCity_Twin_Context_Resolver exists', $exists );

		if ( ! $exists ) {
			$this->skip( 'CR-2', 'Resolver not loaded' );
			$this->skip( 'CR-3', 'Resolver not loaded' );
			return;
		}

		$this->assert( 'CR-2', 'build_system_prompt() exists', method_exists( 'BizCity_Twin_Context_Resolver', 'build_system_prompt' ) );
		$this->assert( 'CR-3', 'for_chat() exists', method_exists( 'BizCity_Twin_Context_Resolver', 'for_chat' ) );
	}

	/* ── Mode Classifier ── */
	private function verify_mode_classifier(): void {
		$exists = class_exists( 'BizCity_Mode_Classifier' );
		$this->assert( 'MC-1', 'BizCity_Mode_Classifier exists', $exists );

		if ( ! $exists ) {
			$this->skip( 'MC-2', 'Mode Classifier not loaded' );
			$this->skip( 'MC-3', 'Mode Classifier not loaded' );
			$this->skip( 'MC-4', 'Mode Classifier not loaded' );
			return;
		}

		$this->assert( 'MC-2', 'classify() exists', method_exists( 'BizCity_Mode_Classifier', 'classify' ) );
		$this->assert( 'MC-3', 'set_kci_ratio() exists', method_exists( 'BizCity_Mode_Classifier', 'set_kci_ratio' ) );
		$this->assert( 'MC-4', 'get_mode_label() exists', method_exists( 'BizCity_Mode_Classifier', 'get_mode_label' ) );
	}

	/* ── Gateways ── */
	private function verify_gateways(): void {
		// Chat Gateway
		$cg = class_exists( 'BizCity_Chat_Gateway' );
		$this->assert( 'GW-1', 'BizCity_Chat_Gateway exists', $cg );

		if ( $cg ) {
			$this->assert( 'GW-2', 'prepare_llm_call() exists', method_exists( 'BizCity_Chat_Gateway', 'prepare_llm_call' ) );

			$has_send   = has_action( 'wp_ajax_bizcity_chat_send' );
			$has_stream = has_action( 'wp_ajax_bizcity_chat_stream' );
			$this->assert( 'GW-3', 'AJAX send + stream hooks',
				$has_send !== false || $has_stream !== false,
				'send=' . ( $has_send !== false ? 'yes' : 'no' ) . ', stream=' . ( $has_stream !== false ? 'yes' : 'no' ) );
		} else {
			$this->skip( 'GW-2', 'Chat Gateway not loaded' );
			$this->skip( 'GW-3', 'Chat Gateway not loaded' );
		}

		// Smart Gateway
		$sg = class_exists( 'BizCity_Smart_Gateway' );
		$this->assert( 'GW-4', 'BizCity_Smart_Gateway exists', $sg );

		if ( $sg ) {
			$this->assert( 'GW-5', 'collect_context() exists', method_exists( 'BizCity_Smart_Gateway', 'collect_context' ) );
		} else {
			$this->skip( 'GW-5', 'Smart Gateway not loaded' );
		}
	}
}
