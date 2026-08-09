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
		$confirm_schema_file = $root . 'includes/event-schemas/conversation_confirm_prompt.json';
		$confirm_file = $root . 'includes/class-twinbrain-conversation-confirmation.php';
		$catalog_file = defined( 'BIZCITY_AUTOMATION_DIR' ) ? BIZCITY_AUTOMATION_DIR . '/includes/class-automation-workflow-catalog.php' : '';
		$default_reply_file = defined( 'BIZCITY_AUTOMATION_DIR' ) ? BIZCITY_AUTOMATION_DIR . '/includes/class-automation-default-reply.php' : '';
		$zalo_gateway_file = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'plugins/bizcity-zalo-bot/includes/class-gateway-bridge.php' : '';

		$ok = $this->step( $ctx, $steps, 'Disk: Conversation Router file', is_readable( $router_file ), $router_file ) && $ok;
		$ok = $this->step( $ctx, $steps, 'Disk: route event schema', is_readable( $schema_file ), $schema_file ) && $ok;
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
