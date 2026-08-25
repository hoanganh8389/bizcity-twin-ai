<?php
/**
 * DDV probe for the TwinBrain Chat default foundation.
 *
 * Read-only: no LLM call, no workflow execution, no DB mutation.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Chat_Default', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Chat_Default implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'twinbrain.chat_default'; }
	public function label(): string { return 'TwinBrain Chat Default / Automation Bridge'; }
	public function description(): string {
		return 'Checks the conversational router, workflow catalog, fuzzy trigger suggestion, confirmation event schema, and safe no-LLM fast paths.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 64; }
	public function icon(): string { return 'message-circle'; }
	public function estimate_ms(): int { return 80; }

	public function precondition() {
		foreach ( array( 'BizCity_TwinBrain_Conversation_Router', 'BizCity_TwinBrain_Conversation_Confirmation', 'BizCity_Automation_Workflow_Catalog' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'class_missing', $class . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — read-only DDV for router/catalog foundation.
		$steps = array();
		$ok = true;
		$root = defined( 'BIZCITY_TWINBRAIN_DIR' ) ? (string) BIZCITY_TWINBRAIN_DIR : '';
		$router_file = $root . 'includes/class-twinbrain-conversation-router.php';
		$schema_file = $root . 'includes/event-schemas/conversation_route_decided.json';
		$evidence_fallback_schema_file = $root . 'includes/event-schemas/evidence_fallback_notice.json';
		$confirm_schema_file = $root . 'includes/event-schemas/conversation_confirm_prompt.json';
		$confirm_file = $root . 'includes/class-twinbrain-conversation-confirmation.php';
		$catalog_file = defined( 'BIZCITY_AUTOMATION_DIR' ) ? BIZCITY_AUTOMATION_DIR . '/includes/class-automation-workflow-catalog.php' : '';
		$default_reply_file = defined( 'BIZCITY_AUTOMATION_DIR' ) ? BIZCITY_AUTOMATION_DIR . '/includes/class-automation-default-reply.php' : '';
		$zalo_gateway_file = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'plugins/bizcity-zalo-bot/includes/class-gateway-bridge.php' : '';

		$ok = $this->step( $ctx, $steps, 'Disk: Conversation Router file', is_readable( $router_file ), $router_file ) && $ok;
		$ok = $this->step( $ctx, $steps, 'Disk: route event schema', is_readable( $schema_file ), $schema_file ) && $ok;
		$ok = $this->step( $ctx, $steps, 'Disk: evidence fallback event schema', is_readable( $evidence_fallback_schema_file ), $evidence_fallback_schema_file ) && $ok;
		$ok = $this->step( $ctx, $steps, 'Disk: confirmation helper/schema', is_readable( $confirm_file ) && is_readable( $confirm_schema_file ), $confirm_file ) && $ok;
		$ok = $this->step( $ctx, $steps, 'Disk: Workflow Catalog file', is_readable( $catalog_file ), $catalog_file ) && $ok;
		$default_reply_source = is_readable( $default_reply_file ) ? (string) file_get_contents( $default_reply_file ) : '';
		$rest_file = $root . 'includes/class-twinbrain-rest.php';
		$rest_source = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$shared_confirmation_bridge = strpos( $default_reply_source, 'BizCity_TwinBrain_Conversation_Confirmation::consume' ) !== false
			&& strpos( $default_reply_source, 'BizCity_TwinBrain_Conversation_Confirmation::begin' ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: text-channel confirmation bridge', is_readable( $default_reply_file ) && $shared_confirmation_bridge, $default_reply_file ) && $ok;
		$sticky_skill_guard = strpos( $rest_source, "\$skill = trim( (string) \$req->get_param( 'skill' ) );" ) !== false
			&& strpos( $rest_source, "array( 'confirmed', 'invalid' )" ) !== false
			&& strpos( $rest_source, "\$skill = '';" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: confirmation beats sticky Skill state', is_readable( $rest_file ) && $sticky_skill_guard, $rest_file ) && $ok;
		$zalo_parity = strpos( $default_reply_source, "'session_id' => self::resolve_zalobot_session_id" ) !== false
			&& strpos( $default_reply_source, "'visible_prompt'    => $text" ) !== false
			&& strpos( $default_reply_source, "'source_marker'     => 'zalobot_chat'" ) !== false
			&& strpos( $default_reply_source, 'bizcity_channel_send( $chat_id, $answer, ' ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: Zalo TwinBrain session parity', is_readable( $default_reply_file ) && $zalo_parity, $default_reply_file ) && $ok;
		$zalo_gateway_source = is_readable( $zalo_gateway_file ) ? (string) file_get_contents( $zalo_gateway_file ) : '';
		$group_privacy = strpos( $zalo_gateway_source, "if ( \$chat_kind === 'group' )" ) !== false
			&& strpos( $zalo_gateway_source, '$wp_user_id = 0;' ) !== false
			&& strpos( $default_reply_source, "(string) ( \$run_payload['chat_kind'] ?? 'private' ) === 'group'" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: Zalo group private-memory guard', is_readable( $zalo_gateway_file ) && $group_privacy, $zalo_gateway_file ) && $ok;

		$schema = is_readable( $schema_file ) ? json_decode( (string) file_get_contents( $schema_file ), true ) : null;
		$schema_ok = is_array( $schema ) && ( $schema['title'] ?? '' ) === 'conversation_route_decided';
		$ok = $this->step( $ctx, $steps, 'Loader: route schema JSON', $schema_ok, $schema_ok ? 'Schema title verified.' : 'Invalid or missing schema JSON.' ) && $ok;
		$confirm_schema = is_readable( $confirm_schema_file ) ? json_decode( (string) file_get_contents( $confirm_schema_file ), true ) : null;
		$confirm_schema_ok = is_array( $confirm_schema ) && ( $confirm_schema['title'] ?? '' ) === 'conversation_confirm_prompt';
		$ok = $this->step( $ctx, $steps, 'Loader: confirmation schema JSON', $confirm_schema_ok, $confirm_schema_ok ? 'Schema title verified.' : 'Invalid or missing schema JSON.' ) && $ok;

		$router_methods_ok = method_exists( 'BizCity_TwinBrain_Conversation_Router', 'route' );
		$specialized_routing_pending = defined( 'BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED' )
			&& false === BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED;
		$ok = $this->step( $ctx, $steps, 'Loader: full Notebook search remains authoritative', $specialized_routing_pending, 'Skeleton/vertical pre-routing is pending; Runtime Notebook Selector owns full allowed-scope search.' ) && $ok;
		$catalog_methods_ok = method_exists( 'BizCity_Automation_Workflow_Catalog', 'render_guide_md' )
			&& method_exists( 'BizCity_Automation_Workflow_Catalog', 'suggest_trigger' );
		$confirmation_methods_ok = method_exists( 'BizCity_TwinBrain_Conversation_Confirmation', 'begin' )
			&& method_exists( 'BizCity_TwinBrain_Conversation_Confirmation', 'consume' )
			&& method_exists( 'BizCity_TwinBrain_Conversation_Confirmation', 'clear' );
		$ok = $this->step( $ctx, $steps, 'Loader: router/catalog/confirmation API', $router_methods_ok && $catalog_methods_ok && $confirmation_methods_ok, 'route/render_guide_md/suggest_trigger/begin/consume available.' ) && $ok;
		$default_reply_loaded = class_exists( 'BizCity_Automation_Default_Reply' ) && $shared_confirmation_bridge;
		$ok = $this->step( $ctx, $steps, 'Loader: Default Reply shared confirmation', $default_reply_loaded, 'Text channel delegates pending state to the shared helper.' ) && $ok;
		$event_contract_ok = class_exists( 'BizCity_Twin_Data_Contract' )
			&& defined( 'BizCity_Twin_Data_Contract::EVT_CONVERSATION_ROUTE_DECIDED' )
			&& isset( BizCity_Twin_Data_Contract::event_taxonomy()[ BizCity_Twin_Data_Contract::EVT_CONVERSATION_ROUTE_DECIDED ] );
		$ok = $this->step( $ctx, $steps, 'Loader: route event Data Contract', $event_contract_ok, $event_contract_ok ? 'conversation_route_decided is canonical and state-neutral.' : 'Route event missing from Data Contract taxonomy.' ) && $ok;
		$session_scope_ok = false;
		if ( class_exists( 'BizCity_Automation_Default_Reply' ) ) {
			$session_resolver = new ReflectionMethod( 'BizCity_Automation_Default_Reply', 'resolve_zalobot_session_id' );
			$session_resolver->setAccessible( true );
			$private_session = $session_resolver->invoke( null, array(
				'bot_id' => '42',
				'sender_user_id' => 'z-user-7',
				'chat_kind' => 'private',
			), 'zalobot_42_private_z-user-7' );
			$group_session = $session_resolver->invoke( null, array(
				'bot_id' => '42',
				'sender_user_id' => 'z-user-7',
				'chat_kind' => 'group',
			), 'zalobot_42_group_group-9' );
			$session_scope_ok = $private_session === 'zalobot_42_z-user-7'
				&& $group_session === 'zalobot_42_group_group-9';
		}
		$ok = $this->step( $ctx, $steps, 'Runtime: Zalo private/group session scope', $session_scope_ok, $session_scope_ok ? 'Private memory is bot+provider-user scoped; group remains conversation scoped.' : 'Unexpected Zalo session identity.' ) && $ok;

		$this->confirmation_probe_key = 'probe:twinbrain-chat-default:' . wp_generate_uuid4();
		$started = BizCity_TwinBrain_Conversation_Confirmation::begin( $this->confirmation_probe_key, 'hãy phân tích notebook này', array(
			'route' => 'notebook',
			'needs_confirm' => true,
			'candidate_notebook_ids' => array( 7 ),
		) );
		$restored = $started ? BizCity_TwinBrain_Conversation_Confirmation::consume( $this->confirmation_probe_key, 'Có nhé!' ) : array();
		$confirmation_ok = ( $restored['status'] ?? '' ) === 'confirmed'
			&& ( $restored['prompt'] ?? '' ) === 'hãy phân tích notebook này'
			&& (int) ( $restored['decision']['candidate_notebook_ids'][0] ?? 0 ) === 7
			&& empty( $restored['decision']['needs_confirm'] )
			&& (int) ( $restored['decision']['force_notebooks'][0] ?? 0 ) === 7;
		$ok = $this->step( $ctx, $steps, 'Runtime: confirmation restores and unlocks route', $confirmation_ok, $confirmation_ok ? 'Pending state consumed without LLM/workflow execution.' : wp_json_encode( $restored ) ) && $ok;

		$generic_started = BizCity_TwinBrain_Conversation_Confirmation::begin( $this->confirmation_probe_key, 'hãy trả lời từ nguồn chuyên gia', array(
			'route' => 'vertical',
			'needs_confirm' => true,
			'candidate_vertical' => 'med',
			'web_mode' => 'med',
		) );
		$invalid = $generic_started ? BizCity_TwinBrain_Conversation_Confirmation::consume( $this->confirmation_probe_key, 'chưa biết' ) : array();
		$generic = BizCity_TwinBrain_Conversation_Confirmation::consume( $this->confirmation_probe_key, 'Không, trả lời chung.' );
		$generic_ok = ( $invalid['status'] ?? '' ) === 'invalid'
			&& ( $generic['status'] ?? '' ) === 'confirmed'
			&& ( $generic['prompt'] ?? '' ) === 'hãy trả lời từ nguồn chuyên gia'
			&& ( $generic['decision']['route'] ?? '' ) === 'casual'
			&& ( $generic['decision']['web_mode'] ?? '' ) === 'chat';
		$ok = $this->step( $ctx, $steps, 'Runtime: invalid reply retains pending and "không" falls back', $generic_ok, $generic_ok ? 'Invalid reply retained state; generic confirmation consumed it.' : wp_json_encode( array( 'invalid' => $invalid, 'generic' => $generic ) ) ) && $ok;
		$deep_accept_key = $this->confirmation_probe_key . ':deep-accept';
		$deep_decline_key = $this->confirmation_probe_key . ':deep-decline';
		$deep_accept_started = BizCity_TwinBrain_Conversation_Confirmation::begin( $deep_accept_key, 'tư vấn dòng sữa cho bé trên 12 tháng', array(
			'offer_type' => 'deep_research',
			'route' => 'vertical',
			'web_mode' => 'deep',
			'needs_confirm' => true,
		) );
		$deep_accept = $deep_accept_started ? BizCity_TwinBrain_Conversation_Confirmation::consume( $deep_accept_key, 'Có' ) : array();
		$deep_decline_started = BizCity_TwinBrain_Conversation_Confirmation::begin( $deep_decline_key, 'tư vấn dòng sữa cho bé trên 12 tháng', array(
			'offer_type' => 'deep_research',
			'route' => 'vertical',
			'web_mode' => 'deep',
			'needs_confirm' => true,
		) );
		$deep_decline = $deep_decline_started ? BizCity_TwinBrain_Conversation_Confirmation::consume( $deep_decline_key, 'Không' ) : array();
		$deep_confirmation_ok = $deep_accept_started
			&& ( $deep_accept['decision']['web_mode'] ?? '' ) === 'deep'
			&& $deep_decline_started
			&& ( $deep_decline['decision']['reason'] ?? '' ) === 'deep_research_declined';
		$ok = $this->step( $ctx, $steps, 'Runtime: Deep Research accept/decline contract', $deep_confirmation_ok, $deep_confirmation_ok ? 'Accept unlocks web_mode=deep; decline is terminal and marked for no-rerun handling.' : wp_json_encode( array( 'accept' => $deep_accept, 'decline' => $deep_decline ) ) ) && $ok;

		$casual = BizCity_TwinBrain_Conversation_Router::route( 'alo', 0 );
		$casual_ok = ( $casual['route'] ?? '' ) === 'casual'
			&& ( $casual['reason'] ?? '' ) === 'casual_fast_path'
			&& (float) ( $casual['confidence'] ?? 0 ) === 1.0;
		$ok = $this->step( $ctx, $steps, 'Runtime: "alo" fast path', $casual_ok, wp_json_encode( $casual ) ) && $ok;
		$full_search_route = BizCity_TwinBrain_Conversation_Router::route( 'Dựa trên notebook hãy tư vấn bước tiếp theo', 0 );
		$full_search_ok = ( $full_search_route['route'] ?? '' ) === 'casual'
			&& abs( (float) ( $full_search_route['confidence'] ?? 0 ) - 0.5 ) < 0.001
			&& empty( $full_search_route['force_notebooks'] )
			&& ( $full_search_route['web_mode'] ?? 'off' ) === 'off';
		$ok = $this->step( $ctx, $steps, 'Runtime: full Notebook search is not preempted', $full_search_ok, $full_search_ok ? 'Specialized pre-routing is pending; Notebook Selector remains authoritative.' : wp_json_encode( $full_search_route ) ) && $ok;

		$help = BizCity_TwinBrain_Conversation_Router::route( 'huong dan toi dung cac kich ban tu dong', 0 );
		$help_ok = ( $help['route'] ?? '' ) === 'automation_help' && ! empty( $help['automation_help'] );
		$ok = $this->step( $ctx, $steps, 'Runtime: automation-help route', $help_ok, wp_json_encode( $help ) ) && $ok;

		$guide = BizCity_Automation_Workflow_Catalog::render_guide_md( array(
			array(
				'id' => 1,
				'name' => 'Healthtest workflow',
				'keywords' => array( 'dang bai' ),
				'filters' => array(),
				'slash_commands' => array( '/healthtest' ),
			),
		) );
		$guide_ok = strpos( $guide, 'Healthtest workflow' ) !== false && strpos( $guide, 'dang bai' ) !== false;
		$ok = $this->step( $ctx, $steps, 'Runtime: factual workflow guide', $guide_ok, $guide_ok ? 'Workflow name and trigger rendered.' : $guide ) && $ok;

		$suggestion = BizCity_Automation_Workflow_Catalog::suggest_trigger( '@thoanh', 'admin' );
		$suggestion_api_ok = $suggestion === null || ( is_array( $suggestion ) && isset( $suggestion['term'], $suggestion['workflow_id'] ) );
		$ok = $this->step( $ctx, $steps, 'Runtime: fuzzy suggestion API is safe', $suggestion_api_ok, $suggestion_api_ok ? 'No unsafe execution; suggestion shape is valid.' : wp_json_encode( $suggestion ) ) && $ok;
		$composer_file = $root . 'includes/class-twinbrain-final-composer.php';
		$composer_source = is_readable( $composer_file ) ? (string) file_get_contents( $composer_file ) : '';
		$runtime_file = $root . 'includes/class-twinbrain-runtime.php';
		$runtime_source = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';
		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? (string) BIZCITY_TWIN_AI_DIR : dirname( dirname( $root ) ) . '/';
		$taxonomy_file = $plugin_root . 'core/twin-core/event-stream/class-twin-event-taxonomy.php';
		$taxonomy_source = is_readable( $taxonomy_file ) ? (string) file_get_contents( $taxonomy_file ) : '';
		$fallback_schema_file = $plugin_root . 'core/twin-core/event-stream/schemas/events/evidence_fallback_notice.json';
		$fallback_schema_source = is_readable( $fallback_schema_file ) ? (string) file_get_contents( $fallback_schema_file ) : '';
		$twinweb_file = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'modules/twinweb/includes/class-twinweb-rest.php' : '';
		$twinweb_source = is_readable( $twinweb_file ) ? (string) file_get_contents( $twinweb_file ) : '';
		$two_part_source_ok = strpos( $composer_source, 'resolve_evidence_fallback_state' ) !== false
			&& strpos( $composer_source, 'apply_evidence_fallback_contract' ) !== false
			&& strpos( $composer_source, 'render_evidence_fallback_notice' ) !== false
			&& strpos( $composer_source, 'EVIDENCE FALLBACK CONTRACT' ) !== false
			&& strpos( $composer_source, 'deep_research_offer' ) !== false;
		$event_contract_source_ok = strpos( $taxonomy_source, "EVIDENCE_FALLBACK_NOTICE = 'evidence_fallback_notice'" ) !== false
			&& strpos( $taxonomy_source, "self::EVIDENCE_FALLBACK_NOTICE => [ 'trace_id', 'trigger', 'reason', 'notice' ]" ) !== false
			&& strpos( $fallback_schema_source, '"evidence_fallback_notice"' ) !== false
			&& strpos( $runtime_source, 'dispatch_v2' ) !== false;
		$two_part_runtime_ok = false;
		$two_part_detail = 'Final Composer class is not loaded; deterministic fallback fixture skipped.';
		if ( class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			try {
				$composer = BizCity_TwinBrain_Final_Composer::instance();
				$resolve = new ReflectionMethod( 'BizCity_TwinBrain_Final_Composer', 'resolve_evidence_fallback_state' );
				$resolve->setAccessible( true );
				$apply = new ReflectionMethod( 'BizCity_TwinBrain_Final_Composer', 'apply_evidence_fallback_contract' );
				$apply->setAccessible( true );
				$empty_opts = array(
					'notebook_source_counts' => array( 'passage_count' => 0 ),
					'search_context_total' => 0,
					'final_context_count' => 0,
				);
				$empty_state = (array) $resolve->invoke( $composer, $empty_opts );
				// [2026-08-24 Johnny Chu] TBR-EVIDENCE-FALLBACK — regression fixture keeps successful tool output outside the empty-Notebook fallback.
				$tool_state = (array) $resolve->invoke( $composer, array_merge( $empty_opts, array(
					'tool_dispatch' => array( 'ok' => true, 'tool_slug' => 'search_web' ),
					'tool_results' => array( array( 'skill' => 'search_web', 'result' => 'Kết quả tool thật' ) ),
				) ) );
				$empty_opts['evidence_fallback_state'] = $empty_state;
				$empty_answer = (array) $apply->invoke( $composer, '## Trả lời\nCó thể tham khảo [nb:1/p2].', $empty_opts );
				$product_opts = array(
					'notebook_source_counts' => array( 'passage_count' => 2 ),
					'answer_intent_meta' => array( 'requires_named_evidence' => true ),
					'product_name_entity_count' => 0,
					'evidence_fallback_state' => array( 'fallback' => true, 'trigger' => 'no_product_name' ),
				);
				$product_state = (array) $resolve->invoke( $composer, $product_opts );
				$product_opts['evidence_fallback_state'] = $product_state;
				$product_answer = (array) $apply->invoke( $composer, '## Trả lời\nDữ liệu [nb:7/p9] cần kiểm tra.', $product_opts );
				$two_part_runtime_ok = ( $empty_state['fallback'] ?? false )
					&& ( $empty_state['trigger'] ?? '' ) === 'full_empty'
					&& empty( $tool_state['fallback'] )
					&& ( $tool_state['reason'] ?? '' ) === 'tool_evidence_available'
					&& strpos( (string) $empty_answer['answer_md'], '[nb:' ) === false
					&& strpos( (string) $empty_answer['answer_md'], 'Notebook chưa có dữ liệu' ) !== false
					&& ( $product_state['fallback'] ?? false )
					&& ( $product_state['trigger'] ?? '' ) === 'no_product_name'
					&& strpos( (string) $product_answer['answer_md'], '[nb:' ) === false
					&& strpos( (string) $product_answer['answer_md'], 'chưa có tên sản phẩm cụ thể' ) !== false;
				$two_part_detail = $two_part_runtime_ok ? 'Full-empty and no-product-name fixtures render Part 1 and remove Part 2 notebook citations.' : wp_json_encode( array( 'empty' => $empty_state, 'product' => $product_state, 'empty_answer' => $empty_answer, 'product_answer' => $product_answer ) );
			} catch ( \Throwable $e ) {
				$two_part_detail = 'Two-part fallback fixture exception: ' . get_class( $e ) . ' ' . $e->getMessage();
			}
		}
		$ok = $this->step( $ctx, $steps, 'Disk: Two-Part Answer fallback contract', $two_part_source_ok, $two_part_source_ok ? $composer_file : 'Final Composer fallback markers are missing.' ) && $ok;
		$ok = $this->step( $ctx, $steps, 'Disk: canonical evidence fallback event contract', $event_contract_source_ok, $event_contract_source_ok ? $fallback_schema_file : 'Canonical taxonomy or Event Stream schema is missing.' ) && $ok;
		$event_contract_runtime_ok = class_exists( 'BizCity_Twin_Event_Taxonomy' )
			&& defined( 'BizCity_Twin_Event_Taxonomy::EVIDENCE_FALLBACK_NOTICE' )
			&& in_array( 'evidence_fallback_notice', BizCity_Twin_Event_Taxonomy::all(), true );
		$ok = $this->step( $ctx, $steps, 'Runtime: canonical evidence fallback event registration', $event_contract_runtime_ok, $event_contract_runtime_ok ? 'evidence_fallback_notice is registered in the loaded Event Taxonomy.' : 'Loaded Event Taxonomy does not expose evidence_fallback_notice.' ) && $ok;
		$ok = $this->step( $ctx, $steps, 'Runtime: Two-Part Answer fallback fixtures', $two_part_runtime_ok, $two_part_detail ) && $ok;
		$deep_offer_bridge_ok = strpos( $rest_source, 'evidence_fallback_offer' ) !== false
			&& strpos( $twinweb_source, 'evidence_fallback_offer' ) !== false
			&& strpos( $default_reply_source, 'evidence_fallback_deep_research_offer' ) !== false;
		$ok = $this->step( $ctx, $steps, 'Loader: Deep Research confirmation handoff', $deep_offer_bridge_ok, $deep_offer_bridge_ok ? 'TwinChat, TwinWeb and text-channel callers persist the post-search deep offer after the answer.' : 'Deep Research confirmation handoff markers are missing.' ) && $ok;

		$memory_citation_file = $root . 'includes/class-twinbrain-citation-resolver.php';
		$memory_citation_source = is_readable( $memory_citation_file ) ? (string) file_get_contents( $memory_citation_file ) : '';
		$memory_citation_ok = strpos( $memory_citation_source, "'/citations/resolve'" ) !== false
			&& strpos( $memory_citation_source, "'tokens'" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: memory citation resolver contract', $memory_citation_ok, $memory_citation_ok ? 'Canonical /citations/resolve route is available for [mem:*] chips.' : $memory_citation_file ) && $ok;

		return array(
			'ok' => $ok,
			'status' => $ok ? 'PASS' : 'FAIL',
			'steps' => $steps,
			'failures' => $ok ? array() : array( 'chat_default_contract_failed' ),
		);
	}

	private function step( $ctx, array &$steps, string $label, bool $passed, string $detail ): bool {
		$row = array( 'label' => $label, 'status' => $passed ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $row;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $row );
		}
		return $passed;
	}

	public function cleanup(): void {
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — read-only probe creates no artifacts.
		if ( $this->confirmation_probe_key !== '' && class_exists( 'BizCity_TwinBrain_Conversation_Confirmation' ) ) {
			BizCity_TwinBrain_Conversation_Confirmation::clear( $this->confirmation_probe_key );
		}
	}

	private $confirmation_probe_key = '';
}
