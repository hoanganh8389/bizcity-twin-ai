<?php
/**
 * BizCity CRM — REST Controller (read-only M1).
 *
 * Namespace: bizcity-crm/v1
 *
 * Endpoints:
 *   GET /channels                            — adapters registered
 *   GET /inboxes                             — list inboxes
 *   GET /conversations?inbox_id&status&...   — list conversations
 *   GET /conversations/(?P<id>\d+)           — one conversation
 *   GET /conversations/(?P<id>\d+)/messages  — list messages
 *
 * Response shape (R-CRM-5):
 *   { ok:true, data:..., next_cursor:?string, ts: epoch_ms }
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_REST_Controller {

	public static function register_routes(): void {
		$ns = BIZCITY_CRM_REST_NS;

		register_rest_route( $ns, '/channels', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_channels' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		// M7.W1 — wizard: per-channel form schema + verify endpoint.
		register_rest_route( $ns, '/channels/(?P<code>[a-z0-9_]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_channel_detail' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		register_rest_route( $ns, '/channels/(?P<code>[a-z0-9_]+)/verify', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_channel_verify' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'config' => array( 'type' => 'object', 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/inboxes', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_inboxes' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			),
			// M7.W1 — wizard create inbox.
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_inbox_create' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'channel_type' => array( 'type' => 'string', 'required' => true ),
					'config'       => array( 'type' => 'object', 'required' => true ),
				),
			),
		) );

		// M7.W4 — runtime health for nav sidebar dot.
		register_rest_route( $ns, '/inboxes/(?P<id>\d+)/health', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_inbox_health' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		register_rest_route( $ns, '/conversations', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_conversations' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'inbox_id'  => array( 'type' => 'integer' ),
				'status'    => array( 'type' => 'string', 'enum' => array( 'open', 'pending', 'resolved', 'snoozed' ) ),
				'limit'     => array( 'type' => 'integer', 'default' => 50 ),
				'before_id' => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_conversation' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/messages', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_messages' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'after_id' => array( 'type' => 'integer', 'default' => 0 ),
					'limit'    => array( 'type' => 'integer', 'default' => 100 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_message' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/notes', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_note' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/resolve', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_resolve' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// PHASE 0.35 M1.W4 — snooze a conversation until N seconds OR ISO ts.
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/snooze', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_snooze' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'duration_seconds' => array( 'type' => 'integer' ),
				'until'            => array( 'type' => 'string' ),
			),
		) );
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/unsnooze', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_unsnooze' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/ai-reply', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_ai_reply' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'prompt'       => array( 'type' => 'string' ),
				'dispatch'     => array( 'type' => 'boolean', 'default' => true ),
				'notebook_id'  => array( 'type' => 'integer' ),
				'character_id' => array( 'type' => 'integer' ),
			),
		) );

		// PHASE 0.35 M-Bridge.W1 — Convert active conversation → CRM Lead.
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/convert-to-lead', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_convert_conv_to_lead' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'first_name' => array( 'type' => 'string' ),
				'last_name'  => array( 'type' => 'string' ),
				'email'      => array( 'type' => 'string' ),
				'phone'      => array( 'type' => 'string' ),
				'company'    => array( 'type' => 'string' ),
				'source'     => array( 'type' => 'string' ),
				'notes'      => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/contacts/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_contact' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		// PHASE-0.35-GURU-SERVICES — Persona infrastructure endpoints.
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/last-skip', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_last_skip' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		register_rest_route( $ns, '/sandbox/test-persona', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_sandbox_test_persona' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'character_id' => array( 'type' => 'integer', 'required' => true ),
				'message'      => array( 'type' => 'string',  'required' => true ),
				'channel_type' => array( 'type' => 'string',  'default'  => 'facebook' ),
				'notebook_id'  => array( 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/persona/analytics', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_persona_analytics' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'default' => 7 ),
			),
		) );

		/* ── PHASE-0.36 Order Adapter ── */
		register_rest_route( $ns, '/order-adapter/banks', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_order_payment_options' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/order-adapter/products', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_order_products' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'q'     => array( 'type' => 'string',  'default' => '' ),
				'limit' => array( 'type' => 'integer', 'default' => 20 ),
			),
		) );
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/orders', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_conversation_orders' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_conversation_order' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'items'          => array( 'type' => 'array' ),
					'custom_amount'  => array( 'type' => 'number' ),
					'payment_option' => array( 'type' => 'string' ),
					'note'           => array( 'type' => 'string' ),
				),
			),
		) );

		// PHASE-0.36b — single-order preview + send-to-customer (link / qr / recap).
		register_rest_route( $ns, '/orders/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_single_order' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/send-order', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_send_order_to_customer' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'order_id' => array( 'type' => 'integer', 'required' => true ),
				'mode'     => array( 'type' => 'string',  'default'  => 'recap' ),
			),
		) );

		// PHASE-0.36b — CRM-managed bank accounts (option-backed CRUD).
		register_rest_route( $ns, '/order-adapter/saved-banks', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_saved_banks' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_saved_bank' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'bank_id'      => array( 'type' => 'string' ),
					'bank_label'   => array( 'type' => 'string', 'required' => true ),
					'bin'          => array( 'type' => 'string' ),
					'account_no'   => array( 'type' => 'string', 'required' => true ),
					'account_name' => array( 'type' => 'string' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_saved_bank' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'idx' => array( 'type' => 'integer', 'required' => true ),
				),
			),
		) );

		/* ── PHASE 0.35 M2.W5 — Automation Rules CRUD ── */
		register_rest_route( $ns, '/automation-rules', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_automation_rules' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
				'args'                => array(
					'event_name' => array( 'type' => 'string',  'required' => false ),
					'inbox_id'   => array( 'type' => 'integer', 'required' => false ),
					'active'     => array( 'type' => 'integer', 'required' => false ),
					'q'          => array( 'type' => 'string',  'required' => false ),
					'limit'      => array( 'type' => 'integer', 'default'  => 100 ),
					'offset'     => array( 'type' => 'integer', 'default'  => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_automation_rule' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );
		register_rest_route( $ns, '/automation-rules/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_automation_rule' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE, // PUT/PATCH
				'callback'            => array( __CLASS__, 'put_automation_rule' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_automation_rule' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );
		register_rest_route( $ns, '/automation-rules/(?P<id>\d+)/dry-run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_automation_rule_dry_run' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			'args'                => array(
				'event_payload' => array( 'type' => 'object', 'required' => false ),
			),
		) );
		register_rest_route( $ns, '/automation-actions', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_automation_actions' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
		) );

		/* ── PHASE 0.35 M3.W1 — Labels CRUD + assign ── */
		register_rest_route( $ns, '/labels', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_labels' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_label' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );
		register_rest_route( $ns, '/labels/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_label' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_label' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_label' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/labels', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_conversation_labels' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_conversation_labels' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'labels' => array( 'type' => 'array', 'required' => true ),
				),
			),
		) );

		/* ── PHASE 0.35 M3.W3 — Custom Attribute Definitions ── */
		register_rest_route( $ns, '/custom-attributes', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_custom_attribute_defs' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'target' => array( 'type' => 'string', 'required' => false ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_custom_attribute_def' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );
		register_rest_route( $ns, '/custom-attributes/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_custom_attribute_def' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_custom_attribute_def' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_custom_attribute_def' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );

		/* ── PHASE 0.35 M3.W4/W5 — Macros + Template render preview ── */
		register_rest_route( $ns, '/macros', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_macros' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_macro' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/macros/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_macro' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_macro' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_macro' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/macros/(?P<id>\d+)/preview', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_macro_preview' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'conversation_id' => array( 'type' => 'integer', 'required' => false ),
			),
		) );
		register_rest_route( $ns, '/macros/(?P<id>\d+)/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_macro_run' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'conversation_id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );
		register_rest_route( $ns, '/render-template', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_render_template' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'template'        => array( 'type' => 'string',  'required' => true ),
				'conversation_id' => array( 'type' => 'integer', 'required' => false ),
				'mode'            => array( 'type' => 'string',  'default'  => 'text' ),
			),
		) );

		/* ── PHASE 0.35 M4.W1 — Working Hours ── */
		register_rest_route( $ns, '/working-hours', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_working_hours' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'inbox_id' => array( 'type' => 'integer', 'required' => true ),
				),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_working_hours' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );
		register_rest_route( $ns, '/working-hours/check', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_working_hours_check' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'inbox_id' => array( 'type' => 'integer', 'required' => true ),
				'ts'       => array( 'type' => 'integer', 'required' => false ),
			),
		) );

		/* ── PHASE 0.35 M4.W2 — SLA Policies ── */
		register_rest_route( $ns, '/sla-policies', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_sla_policies' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_sla_policy' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );
		register_rest_route( $ns, '/sla-policies/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_sla_policy' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_sla_policy' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_sla_policy' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );

		/* ── PHASE 0.35 M4.W3 — SLA evaluator force-tick + per-conv inspect ── */
		register_rest_route( $ns, '/sla/tick', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_sla_tick' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			'args'                => array(
				'force' => array( 'type' => 'boolean', 'default' => true ),
			),
		) );
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/sla', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_conversation_sla' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		/* ── PHASE 0.35 M5 — Reports + CSAT ── */
		register_rest_route( $ns, '/reports/aggregate', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_aggregate' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => array(
				'metric'   => array( 'type' => 'string',  'required' => true ),
				'group_by' => array( 'type' => 'string',  'default'  => 'none' ),
				'from'     => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'to'       => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'inbox_id' => array( 'type' => 'integer', 'required' => false ),
				'agent_id' => array( 'type' => 'integer', 'required' => false ),
			),
		) );
		register_rest_route( $ns, '/reports/auto-vs-human', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_auto_vs_human' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => array(
				'from'     => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'to'       => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'inbox_id' => array( 'type' => 'integer', 'required' => false ),
			),
		) );
		register_rest_route( $ns, '/reports/rollup/run-now', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_reports_rollup_run' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
		) );

		// [2026-06-07 Johnny Chu] PHASE-0.40 G3.1 — BizCity Parity: 6 report endpoints (message/response/agent/campaign/workflow/ai).
		$date_args = array(
			'from'     => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'to'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'days'     => array( 'type' => 'integer', 'required' => false, 'default' => 30 ),
			'inbox_id' => array( 'type' => 'integer', 'required' => false ),
		);
		register_rest_route( $ns, '/reports/message', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_message' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => $date_args,
		) );
		register_rest_route( $ns, '/reports/response', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_response' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => $date_args,
		) );
		register_rest_route( $ns, '/reports/agent', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_agent' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => $date_args,
		) );
		register_rest_route( $ns, '/reports/campaign', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_campaign' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => $date_args,
		) );
		register_rest_route( $ns, '/reports/workflow', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_workflow' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => $date_args,
		) );
		register_rest_route( $ns, '/reports/ai', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_ai' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => $date_args,
		) );

		/* ── PHASE 0.35 M-CRM.M8.W5 — Woo Reports Bridge ── */
		register_rest_route( $ns, '/reports/woo-summary', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_woo_summary' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => array(
				'from' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'to'   => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( $ns, '/reports/woo-top-customers', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_woo_top_customers' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => array(
				'from'  => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'to'    => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 10 ),
			),
		) );
		register_rest_route( $ns, '/reports/woo-by-campaign', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_woo_by_campaign' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => array(
				'from' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'to'   => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( $ns, '/reports/woo-trend', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_woo_trend' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => array(
				'months' => array( 'type' => 'integer', 'required' => false, 'default' => 6 ),
			),
		) );

		/* ── PHASE-0.46 M1 — Team Performance + Source Analytics reports ── */
		// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — team KPI per agent
		register_rest_route( $ns, '/reports/team-performance', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_team_performance' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => array(
				'from'       => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'to'         => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'wp_user_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
			),
		) );
		// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — channel/source breakdown for all submissions
		register_rest_route( $ns, '/reports/source-analytics', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reports_source_analytics' ),
			'permission_callback' => array( __CLASS__, 'can_view_reports' ),
			'args'                => array(
				'from' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'to'   => array( 'type' => 'string', 'required' => false, 'default' => '' ),
			),
		) );

		/* ── PHASE 0.35 M-CRM.M8.W2.2 — Legacy biz_contacts → contacts migration ── */
		register_rest_route( $ns, '/admin/migrate-biz-contacts', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_migrate_biz_contacts' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			'args'                => array(
				'dry_run'  => array( 'type' => 'boolean', 'default' => true ),
				'batch'    => array( 'type' => 'integer', 'default' => 500 ),
				'max_rows' => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );
		register_rest_route( $ns, '/csat/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_csat' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'score' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 5 ),
			),
		) );

		/* ── PHASE 0.35 M-FE.W17 — CRM Modules (Accounts · Biz-Contacts · Tasks · Events · Documents) ── */

		register_rest_route( $ns, '/crm-accounts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_accounts' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'status'   => array( 'type' => 'string'  ),
					'industry' => array( 'type' => 'string'  ),
					'q'        => array( 'type' => 'string'  ),
					'limit'    => array( 'type' => 'integer', 'default' => 100 ),
					'offset'   => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-accounts/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		register_rest_route( $ns, '/crm-contacts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_contacts' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'account_id' => array( 'type' => 'integer' ),
					'q'          => array( 'type' => 'string'  ),
					'limit'      => array( 'type' => 'integer', 'default' => 100 ),
					'offset'     => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_contact' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-contacts/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_contact' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_contact' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_contact' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		// PHASE 0.35 M-CRM.M8.W6.2 — Woo orders for a CRM contact (uses Order Adapter Registry).
		register_rest_route( $ns, '/crm-contacts/(?P<id>\d+)/woo-orders', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_contact_woo_orders' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'limit' => array( 'type' => 'integer', 'default' => 10 ),
				),
			),
		) );

		register_rest_route( $ns, '/crm-tasks', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_tasks' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'status'      => array( 'type' => 'string'  ),
					'assignee_id' => array( 'type' => 'integer' ),
					'q'           => array( 'type' => 'string'  ),
					'limit'       => array( 'type' => 'integer', 'default' => 100 ),
					'offset'      => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_task' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-tasks/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_task' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_task' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_task' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		register_rest_route( $ns, '/crm-events', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_events' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'from'  => array( 'type' => 'integer' ),
					'to'    => array( 'type' => 'integer' ),
					'limit' => array( 'type' => 'integer', 'default' => 100 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_event' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-events/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_event' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_event' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_event' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		register_rest_route( $ns, '/crm-documents', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_documents' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'related_entity_type' => array( 'type' => 'string'  ),
					'related_entity_id'   => array( 'type' => 'integer' ),
					'limit'               => array( 'type' => 'integer', 'default' => 100 ),
					'offset'              => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_document' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-documents/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_document' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_document' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		// [2026-06-07 Johnny Chu] PHASE-0.40 G6.4 — Notes Doc CRUD (bizcity_crm_notes_doc, BizCity parity)
		register_rest_route( $ns, '/crm-notes-doc', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_notes_doc' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'folder'     => array( 'type' => 'string'  ),
					'pinned'     => array( 'type' => 'boolean' ),
					'limit'      => array( 'type' => 'integer', 'default' => 50 ),
					'offset'     => array( 'type' => 'integer', 'default' => 0 ),
					'q'          => array( 'type' => 'string'  ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_notes_doc' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-notes-doc/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_note_doc' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_note_doc' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_note_doc' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		/* ============ M-CRM.M1 — Sales Pipeline (Leads / Opportunities / Contracts) ============ */

		// v1.16.0 — Customer Source adapter sync (Prospecting / Qualification auto-fill).
		register_rest_route( $ns, '/sales-sync', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_sales_sync' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'sources'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'limit'    => array( 'type' => 'integer', 'default' => 200 ),
					'since_ts' => array( 'type' => 'integer' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_sales_sync_status' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		// Leads.
		register_rest_route( $ns, '/crm-leads', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_leads' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'status'     => array( 'type' => 'string'  ),
					'owner_id'   => array( 'type' => 'integer' ),
					'contact_id' => array( 'type' => 'integer' ),
					'q'          => array( 'type' => 'string'  ),
					'limit'      => array( 'type' => 'integer', 'default' => 50 ),
					'offset'     => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_lead' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-leads/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_lead' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_lead' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_lead' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-leads/(?P<id>\d+)/convert', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_lead_convert' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		// Opportunities.
		register_rest_route( $ns, '/crm-opportunities', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_opportunities' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'stage'      => array( 'type' => 'string'  ),
					'status'     => array( 'type' => 'string'  ),
					'owner_id'   => array( 'type' => 'integer' ),
					'account_id' => array( 'type' => 'integer' ),
					'q'          => array( 'type' => 'string'  ),
					'limit'      => array( 'type' => 'integer', 'default' => 100 ),
					'offset'     => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_opportunity' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-opportunities/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_opportunity' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_opportunity' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_opportunity' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-opportunities/(?P<id>\d+)/lines', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_opportunity_lines' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_opportunity_lines' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		// Contracts.
		register_rest_route( $ns, '/crm-contracts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_contracts' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'status'     => array( 'type' => 'string'  ),
					'account_id' => array( 'type' => 'integer' ),
					'owner_id'   => array( 'type' => 'integer' ),
					'q'          => array( 'type' => 'string'  ),
					'limit'      => array( 'type' => 'integer', 'default' => 100 ),
					'offset'     => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_contract' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-contracts/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_contract' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_contract' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_contract' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-contracts/(?P<id>\d+)/lines', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_contract_lines' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_contract_lines' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		/* ============ M-CRM.M1.W2 — Product catalog routes ============ */
		register_rest_route( $ns, '/crm-product-categories', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_product_categories' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_product_category' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-product-categories/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_product_category' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_product_category' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-products', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_products' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_product' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-products/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_product' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_product' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_product' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		/* ============ M-CRM.M2 — Invoicing routes ============ */
		register_rest_route( $ns, '/crm-invoices', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_invoices' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'status'         => array( 'type' => 'string'  ),
					'account_id'     => array( 'type' => 'integer' ),
					'contact_id'     => array( 'type' => 'integer' ),
					'contract_id'    => array( 'type' => 'integer' ),
					'opportunity_id' => array( 'type' => 'integer' ),
					'q'              => array( 'type' => 'string'  ),
					'limit'          => array( 'type' => 'integer', 'default' => 50 ),
					'offset'         => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_invoice' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-invoices/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_invoice' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_invoice' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_invoice' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-invoices/(?P<id>\d+)/transition', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_crm_invoice_transition' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'status' => array( 'type' => 'string', 'required' => true ),
			),
		) );
		register_rest_route( $ns, '/crm-invoices/(?P<id>\d+)/payments', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_invoice_payments' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_invoice_payment' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
				'args'                => array(
					'amount'  => array( 'type' => 'number', 'required' => true ),
					'method'  => array( 'type' => 'string'  ),
					'paid_at' => array( 'type' => 'string'  ),
				),
			),
		) );
		register_rest_route( $ns, '/crm-invoices/payments/(?P<pid>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_crm_invoice_payment' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/crm-invoices/(?P<id>\d+)/send', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_crm_invoice_send' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'to'      => array( 'type' => 'string', 'required' => true ),
				'subject' => array( 'type' => 'string' ),
			),
		) );
		register_rest_route( $ns, '/crm-invoices/(?P<id>\d+)/pdf', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_invoice_pdf' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		/* ============ M-CRM.M3 — Email Client routes ============ */
		register_rest_route( $ns, '/crm-email-accounts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_email_accounts' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_email_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-email-accounts/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_email_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_email_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_email_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/crm-email-accounts/(?P<id>\d+)/sync', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_crm_email_account_sync' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		// Import IMAP account from the site's core SMTP config (same Gmail App Password).
		register_rest_route( $ns, '/crm-email-accounts/from-smtp', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_crm_email_account_from_smtp' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		// Test IMAP connection for an existing account.
		register_rest_route( $ns, '/crm-email-accounts/(?P<id>\d+)/test-imap', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_crm_email_account_test_imap' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/crm-email-threads', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_email_threads' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'account_id'  => array( 'type' => 'integer', 'required' => true ),
				'unread_only' => array( 'type' => 'boolean' ),
				'search'      => array( 'type' => 'string'  ),
				'limit'       => array( 'type' => 'integer', 'default' => 50 ),
				'offset'      => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );
		register_rest_route( $ns, '/crm-email-threads/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_email_thread' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/crm-email-threads/(?P<id>\d+)/read', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_crm_email_thread_read' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/crm-email-send', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_crm_email_send' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'account_id' => array( 'type' => 'integer', 'required' => true ),
				'to'         => array( 'required' => true ),
				'subject'    => array( 'type' => 'string',  'required' => true ),
				'body_html'  => array( 'type' => 'string'  ),
			),
		) );
		register_rest_route( $ns, '/crm-smtp-status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_smtp_status' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		/* ============ PHASE 0.37.1 — Gmail SMTP + Email Automation ============ */
		register_rest_route( $ns, '/gmail-smtp-accounts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_gmail_smtp_accounts' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_gmail_smtp_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/gmail-smtp-accounts/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_gmail_smtp_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_gmail_smtp_account' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/gmail-smtp-accounts/(?P<id>\d+)/test', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_gmail_smtp_account_test' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/gmail-smtp-accounts/(?P<id>\d+)/promote', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_gmail_smtp_account_promote' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/email-events', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_email_events' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/email-event-rules', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_email_event_rules' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_email_event_rule' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/email-event-rules/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_email_event_rule' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_email_event_rule' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/email-event-rules/(?P<id>\d+)/test', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_email_event_rule_test' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// M7.W2/W3 — public inbound webhooks. Auth happens inside the handler
		// (verify token / signature / widget_key) — must NOT require WP login.
		register_rest_route( $ns, '/webhooks/whatsapp', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'webhook_whatsapp_verify' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'webhook_whatsapp_receive' ),
				'permission_callback' => '__return_true',
			),
		) );
		register_rest_route( $ns, '/webhooks/telegram', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'webhook_telegram_receive' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/webhooks/web-widget', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'webhook_web_widget_receive' ),
			'permission_callback' => '__return_true',
		) );

		// PHASE 0.35 M6.W1 — Campaign CRUD + stats. Admin/editor only.
		register_rest_route( $ns, '/campaigns', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'campaigns_list' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'campaigns_create' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'campaigns_get' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'campaigns_update' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'campaigns_delete' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/stats', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'campaigns_stats' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// PHASE 0.35 M6.W2 — QR + UTM URL builder. URL endpoint is JSON-enveloped
		// (admin/editor only); image endpoints stream raw bytes with proper headers.
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/url', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'campaigns_url' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/qr.svg', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'campaigns_qr_svg' ),
			// PUBLIC — QR image asset, consumed by `<img src>` which cannot send
			// X-WP-Nonce. Destination URL inside the QR is already public.
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/qr.png', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'campaigns_qr_png' ),
			// PUBLIC — see qr.svg note.
			'permission_callback' => '__return_true',
		) );

		// PHASE 0.35 M6.W7 — funnel report (visits / conversations / resolved / points).
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/funnel', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'campaigns_funnel' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// PHASE 0.35 M6.W9 — dropdown helper (characters/templates/notebooks).
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/dropdowns', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'campaigns_dropdowns' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// PHASE 0.35 M6.W11 — Messenger m.me link builder + ref token + QR url.
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/messenger-link', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'campaigns_messenger_link' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'page_id' => array( 'type' => 'string', 'required' => false ),
			),
		) );

		// PHASE 0.35 M6.W11 — auto-generate scenario_prompt from shortcode (port bizgpt_generate_prompt_from_shortcode).
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/preview-prompt', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'campaigns_preview_prompt' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// PHASE 0.35 M6.W18-W22 — Marketing Asset Studio.
		register_rest_route( $ns, '/marketing/brand-kit', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'marketing_brand_kit_get' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => 'PUT,POST',
				'callback'            => array( __CLASS__, 'marketing_brand_kit_update' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/marketing/templates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'marketing_templates_list' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/assets/manifest', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'marketing_asset_manifest' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/assets/(?P<key>[a-z0-9_]+)\.(?P<ext>svg|png|jpg|pdf)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'marketing_asset_render' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'headline'     => array( 'type' => 'string', 'required' => false ),
				'cta_text'     => array( 'type' => 'string', 'required' => false ),
				'voucher_code' => array( 'type' => 'string', 'required' => false ),
				'hotline'      => array( 'type' => 'string', 'required' => false ),
				'force'        => array( 'type' => 'boolean', 'required' => false ),
			),
		) );
		register_rest_route( $ns, '/campaigns/(?P<id>\d+)/assets/(?P<key>[a-z0-9_]+)/regenerate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'marketing_asset_regenerate' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// PHASE 0.35 M6 — Funnel Dashboard (single bundled aggregator for KPI + charts).
		register_rest_route( $ns, '/dashboard/funnel-overview', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'dashboard_funnel_overview' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'required' => false ),
			),
		) );

		// [2026-07-06 Johnny Chu] PHASE-0.46 HOTFIX — register missing dashboard/activity routes used by FE.
		register_rest_route( $ns, '/dashboard/crm-overview', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'dashboard_crm_overview' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
			'args'                => array(
				'from' => array( 'type' => 'string', 'required' => false ),
				'to'   => array( 'type' => 'string', 'required' => false ),
			),
		) );
		register_rest_route( $ns, '/activities/recent', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_recent_activities' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'limit'  => array( 'type' => 'integer', 'required' => false ),
				'offset' => array( 'type' => 'integer', 'required' => false ),
			),
		) );

		// PHASE 0.35 M6.W5 — loyalty award + balance.
		register_rest_route( $ns, '/loyalty/award', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'loyalty_award' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/loyalty/balance/(?P<contact_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'loyalty_balance' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// PHASE 0.35 M6.W6 — BizGPT custom-flows importer (preview + commit).
		register_rest_route( $ns, '/flows/import/preview', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'flows_import_preview' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
		) );
		register_rest_route( $ns, '/flows/import', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'flows_import' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
		) );

		// PHASE 3.5 Wave B — Admin Chat grants (3-axis delegation).
		register_rest_route( $ns, '/admin-chat-grants', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_list' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'status' => array( 'type' => 'string', 'required' => false ),
				'limit'  => array( 'type' => 'integer', 'required' => false ),
			),
		) );
		// Lightweight poll endpoint: returns version + pending count only.
		register_rest_route( $ns, '/admin-chat-grants/version', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_version' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
		register_rest_route( $ns, '/admin-chat-grants/(?P<id>\d+)/approve', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_approve' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
		register_rest_route( $ns, '/admin-chat-grants/(?P<id>\d+)/revoke', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_revoke' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
		register_rest_route( $ns, '/admin-chat-grants/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_update' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		// M-CRM.M1.W3 — Audit log (v1.17.0)
		register_rest_route( $ns, '/audit', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_audit_log' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'entity_type' => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'entity_id'   => array( 'required' => true,  'sanitize_callback' => 'absint' ),
				'limit'       => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 50 ),
				'offset'      => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 0 ),
			),
		) );

		// [2026-06-07 Johnny Chu] PHASE-3.5-WC — Admin-chat audit log viewer (v1.22.0)
		register_rest_route( $ns, '/admin-chat-audit', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_admin_chat_audit' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
			'args'                => array(
				'user_id' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'chat_id' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'guru_id' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'action'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				'status'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'   => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 50 ),
				'offset'  => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 0 ),
			),
		) );

		// M-CRM.M4.Inbox v1.18.0 — Broadcasts + bulk label.
		register_rest_route( $ns, '/broadcasts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'broadcasts_list' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'broadcasts_create' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'broadcasts_get' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'broadcasts_update' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'broadcasts_delete' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/send', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_send' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/recipients', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'broadcasts_recipients' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
		// [2026-07-10 Johnny Chu] PHASE-0.47 — checklist operations: dispatch/retry + per-recipient retry + console.
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/recipients/dispatch', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_recipients_dispatch' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/recipients/retry', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_recipients_retry' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/recipients/(?P<rid>\d+)/retry', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_recipient_retry_one' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/console', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'broadcasts_console' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
		// [2026-06-07 Johnny Chu] PHASE-0.43 M3 — Broadcast Mass-Send: enqueue/progress/pause/cancel
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/enqueue', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_enqueue' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/progress', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'broadcasts_progress' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/pause', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_pause' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/cancel', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_cancel' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		// [2026-07-10 Johnny Chu] PHASE-0.47 — restart route parity for BroadcastTab action.
		register_rest_route( $ns, '/broadcasts/(?P<id>\d+)/restart', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_restart' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		// [2026-07-10 Johnny Chu] PHASE-0.47 — cron console parity for API slice polling endpoint.
		register_rest_route( $ns, '/broadcasts/cron-console', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'broadcasts_cron_console' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );
		// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — contacts picker for broadcast recipients
		register_rest_route( $ns, '/broadcasts/contacts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'broadcasts_get_contacts' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'limit'  => array( 'sanitize_callback' => 'absint', 'default' => 100 ),
				'offset' => array( 'sanitize_callback' => 'absint', 'default' => 0 ),
				'search' => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
			),
		) );
		// [2026-07-10 Johnny Chu] PHASE-0.47 — proxy parse-file for CRM broadcast wizard.
		register_rest_route( $ns, '/broadcasts/parse-file', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_parse_file' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		// [2026-07-10 Johnny Chu] PHASE-0.47 — create ZNS broadcast from CRM wizard payload.
		register_rest_route( $ns, '/broadcasts/create-zns', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'broadcasts_create_zns' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		// [2026-07-10 Johnny Chu] PHASE-0.47 — sample template download for broadcast import wizard (csv/xlsx/xls).
		register_rest_route( $ns, '/broadcasts/template', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'broadcasts_csv_template' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'format' => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'csv' ),
			),
		) );
		// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — ZNS templates proxy (R-CH-NS: CRM SPA must not call -channel directly)
		register_rest_route( $ns, '/zns-templates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_zns_templates_proxy' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'status' => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'active' ),
			),
		) );
		// POST /conversations/bulk-label — assign label_ids to N conversation_ids.
		register_rest_route( $ns, '/conversations/bulk-label', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'conversations_bulk_label' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		// PATCH /contacts/{id}/classify — update lead_score + segment.
		register_rest_route( $ns, '/contacts/(?P<id>\d+)/classify', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'contact_classify' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// [2026-07-02 Johnny Chu] PHASE-0.47 W3 — CF7 Submissions, Gift WC Orders, WC Products, Gift Config
		register_rest_route( $ns, '/cf7-submissions', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_cf7_submissions' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/cf7-submissions/forms', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_cf7_submission_forms' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/cf7-submissions/funnel-stats', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_submission_funnel_stats' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/cf7-submissions/activities/stats', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_submission_activities_stats' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/cf7-submissions/bulk-assign', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_cf7_submissions_bulk_assign' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/cf7-submissions/(?P<id>\d+)/activities', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_submission_activities' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_submission_activity' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );
		register_rest_route( $ns, '/cf7-submissions/(?P<id>\d+)/create-gift-wc-order', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_create_gift_wc_order' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		// [2026-07-03 Johnny Chu] R-CF7-STATUS — inline follow_status auto-save
		register_rest_route( $ns, '/cf7-submissions/(?P<id>\d+)/status', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'patch_submission_status' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/wc-products', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_wc_products' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/gift-config', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_gift_config' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_gift_config' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );
		// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — assignable users for call agent picker + bulk-assign
		register_rest_route( $ns, '/crm-settings/assignable-users', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_assignable_users' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

	} // end register_routes()

	/* ------- permissions ------- */

	public static function can_read(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function can_write(): bool {
		/** @param string $cap default cap for CRM composer write actions. */
		$cap = (string) apply_filters( 'bizcity_crm_write_cap', 'edit_posts' );
		return current_user_can( $cap );
	}

	public static function can_manage_rules(): bool {
		$cap = class_exists( 'BizCity_CRM_Capabilities' )
			? BizCity_CRM_Capabilities::CAP_MANAGE_RULES
			: 'manage_options';
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	public static function can_view_reports(): bool {
		$cap = class_exists( 'BizCity_CRM_Capabilities' ) && defined( 'BizCity_CRM_Capabilities::CAP_VIEW_REPORTS' )
			? BizCity_CRM_Capabilities::CAP_VIEW_REPORTS
			: 'bizcity_crm_view_reports';
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	/* ------- handlers ------- */

	public static function get_channels( WP_REST_Request $req ) {
		return self::wrap( static function () {
			$out = array();
			foreach ( BizCity_CRM_Channel_Registry::all() as $a ) {
				$has_wizard = method_exists( $a, 'setup_form_schema' );
				$out[] = array(
					'code'          => $a->code(),
					'label'         => $a->label(),
					'capabilities'  => $a->capabilities(),
					'wizard_ready'  => $has_wizard,
				);
			}
			return $out;
		} );
	}

	/** M7.W1 — return full setup_form_schema for one channel code. */
	public static function get_channel_detail( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$code = (string) $req['code'];
			$a = BizCity_CRM_Channel_Registry::get( $code );
			if ( ! $a ) {
				throw new \RuntimeException( 'channel_not_found' );
			}
			$schema = method_exists( $a, 'setup_form_schema' ) ? $a->setup_form_schema() : array( 'fields' => array() );
			return array(
				'code'         => $a->code(),
				'label'        => $a->label(),
				'capabilities' => $a->capabilities(),
				'schema'       => $schema,
			);
		} );
	}

	/** M7.W1 — verify wizard form submission against the channel API. */
	public static function post_channel_verify( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$code   = (string) $req['code'];
			$config = $req->get_param( 'config' );
			if ( ! is_array( $config ) ) { $config = array(); }

			$a = BizCity_CRM_Channel_Registry::get( $code );
			if ( ! $a ) {
				throw new \RuntimeException( 'channel_not_found' );
			}
			if ( ! method_exists( $a, 'verify' ) ) {
				return array( 'ok' => true, 'name' => $a->label(), 'hints' => array( 'No verify implemented.' ) );
			}
			return $a->verify( $config );
		} );
	}

	/** M7.W1 — create inbox row from wizard. Re-runs verify for safety. */
	public static function post_inbox_create( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$code   = (string) $req->get_param( 'channel_type' );
			$config = $req->get_param( 'config' );
			if ( ! is_array( $config ) ) { $config = array(); }

			$a = BizCity_CRM_Channel_Registry::get( $code );
			if ( ! $a ) {
				throw new \RuntimeException( 'channel_not_found' );
			}
			$verify = method_exists( $a, 'verify' )
				? $a->verify( $config )
				: array( 'ok' => true, 'channel_ref_id' => '', 'name' => $a->label() );

			if ( empty( $verify['ok'] ) ) {
				throw new \RuntimeException( 'verify_failed: ' . (string) ( $verify['error'] ?? 'unknown' ) );
			}
			$ref = (string) ( $verify['channel_ref_id'] ?? '' );
			if ( $ref === '' ) {
				throw new \RuntimeException( 'verify_returned_no_channel_ref_id' );
			}

			$inbox_id = BizCity_CRM_Repository::upsert_inbox( $code, $ref, array(
				'name'     => (string) ( $verify['name'] ?? $a->label() ),
				'settings' => $config,
			) );
			if ( ! $inbox_id ) {
				throw new \RuntimeException( 'inbox_insert_failed' );
			}
			return array(
				'inbox_id'      => $inbox_id,
				'channel_type'  => $code,
				'channel_ref_id'=> $ref,
				'name'          => (string) ( $verify['name'] ?? $a->label() ),
				'verify_hints'  => isset( $verify['hints'] ) ? $verify['hints'] : array(),
			);
		} );
	}

	/** M7.W4 — health snapshot for one inbox. */
	public static function get_inbox_health( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			$inbox = BizCity_CRM_Repository::get_inbox( $id );
			if ( ! $inbox ) {
				throw new \RuntimeException( 'inbox_not_found' );
			}
			$a = BizCity_CRM_Channel_Registry::get( (string) $inbox['channel_type'] );
			if ( ! $a || ! method_exists( $a, 'health' ) ) {
				return array(
					'status'          => 'unknown',
					'last_inbound_at' => null,
					'last_error'      => null,
					'details'         => array( 'reason' => 'adapter_no_health' ),
				);
			}
			return $a->health( $inbox );
		} );
	}

	/* ============================================================
	 * M7.W2 / W3 — Public inbound webhooks.
	 *
	 * Each handler authenticates the payload (verify token / signature
	 * / widget_key), normalizes via the channel adapter, then pushes
	 * through BizCity_CRM_Facebook_Ingestor::ingest() — the universal
	 * inbound pipeline that creates inbox/contact/conversation/message
	 * rows and emits the standard `crm_message_created` event.
	 * ============================================================ */

	/** GET /webhooks/whatsapp — Meta hub.verify handshake. */
	public static function webhook_whatsapp_verify( WP_REST_Request $req ) {
		$mode      = (string) $req->get_param( 'hub_mode' );
		$challenge = (string) $req->get_param( 'hub_challenge' );
		$token     = (string) $req->get_param( 'hub_verify_token' );
		if ( $mode !== 'subscribe' || $token === '' ) {
			return new WP_Error( 'forbidden', 'invalid handshake', array( 'status' => 403 ) );
		}
		// Accept the token if ANY active WA inbox stored it. Multi-tenant: same
		// callback URL, the first inbox with a matching verify_token wins.
		foreach ( BizCity_CRM_Repository::list_inboxes() as $ib ) {
			if ( ( $ib['channel_type'] ?? '' ) !== 'whatsapp_cloud' ) { continue; }
			$s = $ib['settings_json'] ? json_decode( $ib['settings_json'], true ) : array();
			if ( is_array( $s ) && hash_equals( (string) ( $s['verify_token'] ?? '' ), $token ) ) {
				return new WP_REST_Response( $challenge, 200 );
			}
		}
		return new WP_Error( 'forbidden', 'token mismatch', array( 'status' => 403 ) );
	}

	/** POST /webhooks/whatsapp — inbound message. */
	public static function webhook_whatsapp_receive( WP_REST_Request $req ) {
		$payload = $req->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'bad_request', 'expected JSON body', array( 'status' => 400 ) );
		}
		$adapter = BizCity_CRM_Channel_Registry::get( 'whatsapp_cloud' );
		if ( ! $adapter ) {
			return new WP_Error( 'not_ready', 'adapter not registered', array( 'status' => 503 ) );
		}
		$norm = $adapter->normalize_inbound( $payload );
		if ( ! $norm ) {
			// Meta requires a 200 even for non-message events (status, deletes).
			return new WP_REST_Response( array( 'ok' => true, 'skipped' => 'no_message' ), 200 );
		}
		try {
			$mid = BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $norm );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $e->getMessage() ), 200 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'message_id' => (int) $mid ), 200 );
	}

	/** POST /webhooks/telegram — inbound update. */
	public static function webhook_telegram_receive( WP_REST_Request $req ) {
		$payload = $req->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'bad_request', 'expected JSON body', array( 'status' => 400 ) );
		}
		$adapter = BizCity_CRM_Channel_Registry::get( 'telegram' );
		if ( ! $adapter ) {
			return new WP_Error( 'not_ready', 'adapter not registered', array( 'status' => 503 ) );
		}
		// Auth: optional X-Telegram-Bot-Api-Secret-Token header per inbox.
		$header_secret = (string) $req->get_header( 'x_telegram_bot_api_secret_token' );
		$bot_id        = '';
		foreach ( BizCity_CRM_Repository::list_inboxes() as $ib ) {
			if ( ( $ib['channel_type'] ?? '' ) !== 'telegram' ) { continue; }
			$s = $ib['settings_json'] ? json_decode( $ib['settings_json'], true ) : array();
			$expected = (string) ( $s['webhook_secret'] ?? '' );
			if ( $expected !== '' && ! hash_equals( $expected, $header_secret ) ) { continue; }
			$bot_id = (string) ( $ib['channel_ref_id'] ?? '' );
			break;
		}
		// Inject bot id so the adapter can stamp inbox_ref correctly.
		$payload['_bot_id'] = $bot_id;
		$norm = $adapter->normalize_inbound( $payload );
		if ( ! $norm ) {
			return new WP_REST_Response( array( 'ok' => true, 'skipped' => 'no_message' ), 200 );
		}
		try {
			$mid = BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $norm );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $e->getMessage() ), 200 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'message_id' => (int) $mid ), 200 );
	}

	/** POST /webhooks/web-widget — visitor message from JS snippet. */
	public static function webhook_web_widget_receive( WP_REST_Request $req ) {
		$payload = $req->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $req->get_params();
		}
		$widget_key = (string) ( $payload['widget_key'] ?? '' );
		if ( $widget_key === '' || strlen( $widget_key ) < 12 ) {
			return new WP_Error( 'bad_request', 'missing widget_key', array( 'status' => 400 ) );
		}
		// Locate inbox + enforce CORS allow-list.
		$inbox = null;
		foreach ( BizCity_CRM_Repository::list_inboxes() as $ib ) {
			if ( ( $ib['channel_type'] ?? '' ) !== 'web_widget' ) { continue; }
			if ( ( $ib['channel_ref_id'] ?? '' ) === $widget_key ) { $inbox = $ib; break; }
		}
		if ( ! $inbox ) {
			return new WP_Error( 'forbidden', 'widget_key not registered', array( 'status' => 403 ) );
		}
		$settings = $inbox['settings_json'] ? json_decode( $inbox['settings_json'], true ) : array();
		$origins  = array_filter( array_map( 'trim', preg_split( '/\R+/', (string) ( $settings['allowed_origins'] ?? '' ) ) ) );
		if ( $origins ) {
			$origin = (string) $req->get_header( 'origin' );
			if ( $origin !== '' && ! in_array( $origin, $origins, true ) ) {
				return new WP_Error( 'forbidden', 'origin not allowed', array( 'status' => 403 ) );
			}
		}
		$adapter = BizCity_CRM_Channel_Registry::get( 'web_widget' );
		if ( ! $adapter ) {
			return new WP_Error( 'not_ready', 'adapter not registered', array( 'status' => 503 ) );
		}
		$norm = $adapter->normalize_inbound( $payload );
		if ( ! $norm ) {
			return new WP_Error( 'bad_request', 'invalid payload', array( 'status' => 400 ) );
		}
		try {
			$mid = BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $norm );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $e->getMessage() ), 200 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'message_id' => (int) $mid ), 200 );
	}

	/* ==========================================================
	 * PHASE 0.35 M6.W1 — Campaign CRUD + Stats handlers
	 * ========================================================== */

	public static function campaigns_list( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			return BizCity_CRM_Campaign_Repository::list( array(
				'status' => (string) $req->get_param( 'status' ),
				'q'      => (string) $req->get_param( 'q' ),
				'limit'  => (int) ( $req->get_param( 'limit' )  ?: 50 ),
				'offset' => (int) $req->get_param( 'offset' ),
			) );
		} );
	}

	public static function campaigns_create( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = BizCity_CRM_Campaign_Repository::create( (array) $req->get_json_params() );
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( $id->get_error_message() );
			}
			return BizCity_CRM_Campaign_Repository::get( (int) $id );
		} );
	}

	public static function campaigns_get( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$row = BizCity_CRM_Campaign_Repository::get( (int) $req['id'] );
			if ( ! $row ) { throw new \RuntimeException( 'campaign_not_found' ); }
			return $row;
		} );
	}

	public static function campaigns_update( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$res = BizCity_CRM_Campaign_Repository::update( (int) $req['id'], (array) $req->get_json_params() );
			if ( is_wp_error( $res ) ) {
				throw new \RuntimeException( $res->get_error_message() );
			}
			return $res;
		} );
	}

	public static function campaigns_delete( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$ok = BizCity_CRM_Campaign_Repository::delete( (int) $req['id'] );
			if ( ! $ok ) { throw new \RuntimeException( 'campaign_delete_failed' ); }
			return array( 'deleted' => true, 'id' => (int) $req['id'] );
		} );
	}

	public static function campaigns_stats( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			$row = BizCity_CRM_Campaign_Repository::get( $id );
			if ( ! $row ) { throw new \RuntimeException( 'campaign_not_found' ); }
			$visits      = BizCity_CRM_Campaign_Repository::visits_count( $id );
			$conversions = BizCity_CRM_Campaign_Repository::conversions_count( $id );
			$cvr         = $visits > 0 ? round( ( $conversions / $visits ) * 100, 2 ) : 0.0;
			return array(
				'campaign_id' => $id,
				'code'        => $row['code'],
				'visits'      => $visits,
				'conversions' => $conversions,
				'cvr_pct'     => $cvr,
			);
		} );
	}

	/* M6.W2 — URL builder (JSON envelope; supports per-call utm overrides). */
	public static function campaigns_url( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			$row = BizCity_CRM_Campaign_Repository::get( $id );
			if ( ! $row ) { throw new \RuntimeException( 'campaign_not_found' ); }
			$overrides = array();
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ) as $k ) {
				$v = (string) $req->get_param( $k );
				if ( $v !== '' ) { $overrides['utm'][ substr( $k, 4 ) ] = $v; }
			}
			$lp = (string) $req->get_param( 'landing_url' );
			if ( $lp !== '' ) { $overrides['landing_url'] = $lp; }
			$url = BizCity_CRM_QR_Generator::build_url( $row, $overrides );
			return array(
				'campaign_id' => $id,
				'code'        => $row['code'],
				'url'         => $url,
			);
		} );
	}

	/* M6.W2 — QR SVG (raw image, no JSON envelope). */
	public static function campaigns_qr_svg( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$row = BizCity_CRM_Campaign_Repository::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'campaign_not_found', 'campaign not found', array( 'status' => 404 ) );
		}
		$size   = max( 64, min( 1024, (int) ( $req->get_param( 'size' ) ?: 256 ) ) );
		$margin = max( 0,  min( 16,   (int) ( $req->get_param( 'margin' ) ?: 4 ) ) );
		try {
			$svg = BizCity_CRM_QR_Generator::svg( BizCity_CRM_QR_Generator::build_url( $row ), $size, $margin );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'qr_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		$resp = new WP_REST_Response( $svg, 200 );
		$resp->header( 'Content-Type', 'image/svg+xml; charset=utf-8' );
		$resp->header( 'Cache-Control', 'private, max-age=300' );
		return $resp;
	}

	/* M6.W2 — QR PNG (raw image; requires GD). */
	public static function campaigns_qr_png( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$row = BizCity_CRM_Campaign_Repository::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'campaign_not_found', 'campaign not found', array( 'status' => 404 ) );
		}
		$size   = max( 64, min( 1024, (int) ( $req->get_param( 'size' ) ?: 256 ) ) );
		$margin = max( 0,  min( 16,   (int) ( $req->get_param( 'margin' ) ?: 4 ) ) );
		try {
			$png = BizCity_CRM_QR_Generator::png( BizCity_CRM_QR_Generator::build_url( $row ), $size, $margin );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'qr_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: private, max-age=300' );
		header( 'Content-Length: ' . strlen( $png ) );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/* ============================================================
	 * PHASE 0.35 M6.W11 — Messenger link + preview-prompt
	 * ============================================================ */

	/**
	 * GET /campaigns/{id}/messenger-link?page_id=
	 *
	 * Returns: { m_me_url, ref, qr_url, page_id, landing_url }.
	 * If page_id omitted → first FB inbox is auto-picked.
	 */
	public static function campaigns_messenger_link( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id  = (int) $req['id'];
			$row = BizCity_CRM_Campaign_Repository::get( $id );
			if ( ! $row ) { throw new \RuntimeException( 'campaign_not_found' ); }

			$page_id = trim( (string) $req->get_param( 'page_id' ) );
			if ( $page_id === '' ) {
				$page_id = self::resolve_default_messenger_page_id();
			}

			$ref = class_exists( 'BizCity_CRM_Campaign_Ref_Codec' )
				? BizCity_CRM_Campaign_Ref_Codec::encode( $id )
				: ( 'camp_' . $row['code'] );

			$m_me_url = $page_id !== ''
				? sprintf( 'https://m.me/%s?ref=%s', rawurlencode( $page_id ), rawurlencode( $ref ) )
				: '';

			// QR points to landing URL with full UTM (existing build_url) — Messenger ref is appended manually.
			$landing_url = '';
			$qr_url      = '';
			if ( class_exists( 'BizCity_CRM_QR_Generator' ) ) {
				$landing_url = BizCity_CRM_QR_Generator::build_url( $row );
			}
			// M6.W13.8 (2026-05-25) — Render QR via public api.qrserver.com to avoid
			// REST 401/empty PNG issues on the FE list page. Data = m.me link if FB
			// page is bound, otherwise fall back to landing_url. Local REST endpoint
			// still works (kept for callers that pass auth), but FE preference is the
			// public service so <img> renders even when nonce headers aren't attached.
			$qr_data = $m_me_url !== '' ? $m_me_url : $landing_url;
			if ( $qr_data !== '' ) {
				$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode( $qr_data );
			} elseif ( class_exists( 'BizCity_CRM_QR_Generator' ) ) {
				$qr_url = rest_url( 'bizcity-crm/v1/campaigns/' . $id . '/qr.png?size=240' );
			}

			return array(
				'campaign_id' => $id,
				'code'        => $row['code'],
				'ref'         => $ref,
				'page_id'     => $page_id,
				'm_me_url'    => $m_me_url,
				'landing_url' => $landing_url,
				'qr_url'      => $qr_url,
			);
		} );
	}

	/**
	 * Resolve default Messenger page id from the first active FB inbox. Stored
	 * either in `channel_ref_id` (canonical) or `settings_json.page_id`.
	 */
	private static function resolve_default_messenger_page_id(): string {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$row = $wpdb->get_row(
			"SELECT channel_ref_id, settings_json
			   FROM {$tbl}
			  WHERE channel_type = 'facebook' AND is_active = 1
			  ORDER BY id ASC LIMIT 1",
			ARRAY_A
		);
		if ( ! $row ) { return ''; }
		$ref = trim( (string) ( $row['channel_ref_id'] ?? '' ) );
		if ( $ref !== '' ) { return $ref; }
		$settings = json_decode( (string) ( $row['settings_json'] ?? '' ), true );
		if ( is_array( $settings ) && ! empty( $settings['page_id'] ) ) {
			return (string) $settings['page_id'];
		}
		return '';
	}

	/**
	 * POST /campaigns/{id}/preview-prompt
	 *
	 * Body: { shortcode?, action_type?, attrs?, name? }. If omitted, falls back
	 * to the campaign's stored fields. Returns: { prompt, source }.
	 *
	 * Port of `bizgpt_generate_prompt_from_shortcode($shortcode, $attrs, $name)`
	 * — but kept rule-based (no LLM call) so it's free + deterministic. The
	 * resulting prompt is meant as a *starter* the user edits in textarea.
	 */
	public static function campaigns_preview_prompt( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id  = (int) $req['id'];
			$row = BizCity_CRM_Campaign_Repository::get( $id );
			if ( ! $row ) { throw new \RuntimeException( 'campaign_not_found' ); }

			$body        = (array) $req->get_json_params();
			$shortcode   = trim( (string) ( $body['shortcode']   ?? $row['scenario_shortcode'] ?? '' ) );
			$action_type = trim( (string) ( $body['action_type'] ?? $row['scenario_action_type'] ?? 'send_message' ) );
			$attrs       = $body['attrs'] ?? ( $row['scenario_attrs'] ?? array() );
			$name        = trim( (string) ( $body['name'] ?? $row['name'] ?? '' ) );

			$prompt = self::build_scenario_prompt( $name, $action_type, $shortcode, is_array( $attrs ) ? $attrs : array() );

			return array(
				'campaign_id' => $id,
				'prompt'      => $prompt,
				'source'      => $shortcode !== '' ? 'shortcode' : ( $action_type === 'send_message' ? 'template' : 'fallback' ),
				'attrs_count' => is_array( $attrs ) ? count( $attrs ) : 0,
			);
		} );
	}

	/**
	 * Rule-based prompt builder (port of bizgpt_generate_prompt_from_shortcode).
	 */
	private static function build_scenario_prompt( string $name, string $action_type, string $shortcode, array $attrs ): string {
		$lines = array();
		$lines[] = sprintf( 'Bạn là trợ lý ảo cho chiến dịch "%s".', $name !== '' ? $name : 'CRM Campaign' );

		switch ( $action_type ) {
			case 'run_shortcode':
				$lines[] = 'Khi khách kích hoạt kịch bản này:';
				if ( $shortcode !== '' ) {
					$keys = self::extract_shortcode_attr_keys( $shortcode );
					if ( $keys ) {
						$lines[] = sprintf(
							'- Hỏi khách lần lượt các thông tin sau (nói tự nhiên, không liệt kê dạng form): %s.',
							implode( ', ', $keys )
						);
					} else {
						$lines[] = '- Chào hỏi tự nhiên, hỏi nhu cầu khách một cách ngắn gọn.';
					}
					$lines[] = sprintf( '- Sau khi đủ thông tin, gọi shortcode `%s` để trả kết quả.', $shortcode );
				} else {
					$lines[] = '- Chào hỏi và hỏi khách cần gì để chạy kịch bản phù hợp.';
				}
				break;

			case 'kg_grounded_reply':
				$lines[] = 'Khi khách nhắn đến:';
				$lines[] = '- Truy xuất thông tin từ Notebook đính kèm (KG) để trả lời chính xác, có dẫn nguồn `[src:...]` khi phù hợp.';
				$lines[] = '- KHÔNG bịa thông tin ngoài Notebook. Nếu không tìm thấy, mời khách để lại số điện thoại để nhân viên gọi lại.';
				break;

			case 'delay_only':
				$lines[] = 'Đây là kịch bản chỉ-nhắc-lại (delay-only). KHÔNG trả lời tức thời. Đợi reminder hệ thống tự gửi.';
				break;

			case 'send_message':
			default:
				$lines[] = 'Khi khách kích hoạt kịch bản:';
				$lines[] = '- Chào hỏi tự nhiên, ngắn gọn, có thể dùng emoji nhẹ.';
				$lines[] = '- Trả về thông điệp template đã cấu hình; nếu cần personalize → dùng tên khách `{contact.name}`.';
				break;
		}

		if ( ! empty( $attrs ) ) {
			$lines[] = '';
			$lines[] = 'Các thuộc tính cần thu thập (lưu vào contact attributes):';
			foreach ( $attrs as $a ) {
				if ( ! is_array( $a ) ) { continue; }
				$key    = (string) ( $a['key']    ?? '' );
				$prompt = (string) ( $a['prompt'] ?? '' );
				if ( $key === '' ) { continue; }
				$lines[] = sprintf( '  · `%s`: %s', $key, $prompt !== '' ? $prompt : '(không có gợi ý)' );
			}
		}

		$lines[] = '';
		$lines[] = 'Nguyên tắc chung:';
		$lines[] = '- Tiếng Việt thân thiện, tránh trịnh trọng.';
		$lines[] = '- Mỗi tin nhắn ≤ 3 câu.';
		$lines[] = '- Nếu khách hỏi ngoài luồng → trả lời ngắn rồi quay về kịch bản.';

		return implode( "\n", $lines );
	}

	/**
	 * Extract attribute keys from a shortcode like `[tim_san_pham keyword="x" type="y"]`
	 * → returns ['keyword','type']. Best-effort regex; returns [] on parse failure.
	 *
	 * @return string[]
	 */
	private static function extract_shortcode_attr_keys( string $shortcode ): array {
		if ( $shortcode === '' ) { return array(); }
		$keys = array();
		// Match attr_name="..." or attr_name='...' or attr_name=value (no quotes).
		if ( preg_match_all( '/\b([A-Za-z_][A-Za-z0-9_]*)\s*=/u', $shortcode, $m ) ) {
			foreach ( (array) $m[1] as $k ) {
				$k = strtolower( $k );
				if ( $k === 'id' || $k === 'class' ) { continue; }
				if ( ! in_array( $k, $keys, true ) ) { $keys[] = $k; }
			}
		}
		return $keys;
	}

	/* ============================================================
	 * M6.W7 — funnel report
	 * Stages: visits → conversations (any conv from converted contact) →
	 *         resolved → points_awarded (sum of credits tagged with this campaign).
	 * ============================================================ */
	public static function campaigns_funnel( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id = (int) $req['id'];
			$camp = BizCity_CRM_Campaign_Repository::get( $id );
			if ( ! $camp ) { throw new \RuntimeException( 'campaign_not_found' ); }

			$visits      = BizCity_CRM_Campaign_Repository::visits_count( $id );
			$conversions = BizCity_CRM_Campaign_Repository::conversions_count( $id );

			$visits_tbl = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
			$cv         = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$ci         = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();

			$conversations_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT cv.id)
				   FROM {$visits_tbl} v
				   JOIN {$ci} ci ON ci.contact_id = v.converted_contact_id
				   JOIN {$cv} cv ON cv.contact_inbox_id = ci.id
				  WHERE v.campaign_id = %d AND v.converted_contact_id IS NOT NULL",
				$id
			) );
			$resolved_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT cv.id)
				   FROM {$visits_tbl} v
				   JOIN {$ci} ci ON ci.contact_id = v.converted_contact_id
				   JOIN {$cv} cv ON cv.contact_inbox_id = ci.id
				  WHERE v.campaign_id = %d AND v.converted_contact_id IS NOT NULL AND cv.status = 'resolved'",
				$id
			) );

			$points_awarded = 0;
			$tbl_pts = $wpdb->prefix . 'user_points';
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_pts ) ) === $tbl_pts ) {
				$store = BizCity_CRM_Loyalty_Bridge::STORE_PREFIX . $camp['code'];
				$points_awarded = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(SUM(CAST(user_points AS UNSIGNED)),0) FROM {$tbl_pts} WHERE store_name = %s",
					$store
				) );
			}

			$cvr = $visits > 0 ? round( ( $conversions / $visits ) * 100, 2 ) : 0.0;
			return array(
				'campaign_id'    => $id,
				'code'           => $camp['code'],
				'visits'         => $visits,
				'conversions'    => $conversions,
				'conversations'  => $conversations_count,
				'resolved'       => $resolved_count,
				'points_awarded' => $points_awarded,
				'cvr_pct'        => $cvr,
			);
		} );
	}

	/* ============================================================
	 * M6.W9 — dropdown helper for campaign form
	 * ============================================================ */
	public static function campaigns_dropdowns( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl_macros = BizCity_CRM_DB_Installer_V2::tbl_macros();
			$tbl_chars  = $wpdb->prefix . 'bizcity_characters';
			$tbl_nb     = $wpdb->prefix . 'bizcity_kg_notebooks';

			$templates = (array) $wpdb->get_results(
				"SELECT id, name FROM {$tbl_macros} WHERE active = 1 ORDER BY name ASC LIMIT 200",
				ARRAY_A
			);
			$characters = array();
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_chars ) ) === $tbl_chars ) {
				$characters = (array) $wpdb->get_results(
					"SELECT id, name FROM {$tbl_chars} ORDER BY name ASC LIMIT 200",
					ARRAY_A
				);
			}
			$notebooks = array();
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_nb ) ) === $tbl_nb ) {
				$notebooks = (array) $wpdb->get_results(
					"SELECT id, name FROM {$tbl_nb} ORDER BY name ASC LIMIT 200",
					ARRAY_A
				);
			}
			$norm = static fn( $rows ) => array_map(
				static fn( $r ) => array( 'id' => (int) $r['id'], 'name' => (string) ( $r['name'] ?? '' ) ),
				$rows
			);
			return array(
				'templates'  => $norm( $templates ),
				'characters' => $norm( $characters ),
				'notebooks'  => $norm( $notebooks ),
				/* PHASE 0.35 M6.W15 — scenario form helpers. */
				'shortcodes' => class_exists( 'BizCity_CRM_Campaign_Scenario_Dispatcher' )
					? array_values( BizCity_CRM_Campaign_Scenario_Dispatcher::SHORTCODE_WHITELIST )
					: array(),
				'reminder_units' => array( 'minutes', 'hours', 'days' ),
				'action_types'   => array( 'send_message', 'run_shortcode', 'kg_grounded_reply', 'delay_only' ),
			);
		} );
	}

	/* ============================================================
	 * M6.W5 — loyalty award + balance
	 * ============================================================ */
	public static function loyalty_award( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = (array) $req->get_json_params();
			$points = (int) ( $body['points'] ?? 0 );
			if ( $points <= 0 ) { throw new \RuntimeException( 'points_must_be_positive' ); }
			$subject = array(
				'contact_id' => (int) ( $body['contact_id'] ?? 0 ),
				'phone'      => (string) ( $body['phone'] ?? '' ),
				'client_id'  => (string) ( $body['client_id'] ?? '' ),
				'event_uuid' => (string) ( $body['event_uuid'] ?? '' ),
			);
			$meta = array(
				'source' => (string) ( $body['source'] ?? 'rest' ),
				'code'   => (string) ( $body['code']   ?? '' ),
			);
			$res = BizCity_CRM_Loyalty_Bridge::award( $subject, $points, $meta );
			if ( ! $res['ok'] ) { throw new \RuntimeException( $res['status'] ?? 'award_failed' ); }
			return $res;
		} );
	}

	public static function loyalty_balance( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$cid = (int) $req['contact_id'];
			if ( $cid <= 0 ) { throw new \RuntimeException( 'invalid_contact_id' ); }
			return array(
				'contact_id' => $cid,
				'balance'    => BizCity_CRM_Loyalty_Bridge::balance( array( 'contact_id' => $cid ) ),
				'history'    => BizCity_CRM_Loyalty_Bridge::history( array( 'contact_id' => $cid ), 20 ),
			);
		} );
	}

	/* ============================================================
	 * M6.W6 — flow importer (BizGPT custom flows → macros + rules)
	 * ============================================================ */
	public static function flows_import_preview( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$limit = (int) ( $req->get_param( 'limit' ) ?: 100 );
			return array(
				'available' => BizCity_CRM_Flow_Importer::source_available(),
				'flows'     => BizCity_CRM_Flow_Importer::preview( $limit ),
			);
		} );
	}

	public static function flows_import( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body  = (array) $req->get_json_params();
			$ids   = isset( $body['flow_ids'] ) && is_array( $body['flow_ids'] ) ? $body['flow_ids'] : array();
			$rules = ! isset( $body['with_rule'] ) ? true : (bool) $body['with_rule'];
			if ( empty( $ids ) ) { throw new \RuntimeException( 'flow_ids_required' ); }
			return BizCity_CRM_Flow_Importer::import_bulk( $ids, $rules );
		} );
	}

	/* PHASE 3.5 Wave B — Admin Chat grants endpoints. */

	const GRANTS_VERSION_OPTION = 'bzc_crm_grants_version';
	const GRANTS_CACHE_GROUP    = 'bzc_crm_grants';
	const GRANTS_CACHE_TTL      = 300; // 5 min — but version-keyed, so basically until next mutation.

	private static function grants_version(): int {
		$v = (int) get_option( self::GRANTS_VERSION_OPTION, 0 );
		if ( $v <= 0 ) {
			$v = time();
			update_option( self::GRANTS_VERSION_OPTION, $v, false );
		}
		return $v;
	}

	public static function bump_grants_version(): int {
		$v = time();
		update_option( self::GRANTS_VERSION_OPTION, $v, false );
		return $v;
	}

	public static function admin_chat_grants_version( WP_REST_Request $req ) {
		return self::wrap( static function () {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) ) {
				return array( 'version' => 0, 'pending' => 0 );
			}
			$ver = self::grants_version();
			$ck  = 'pending_count_v' . $ver;
			$pending = wp_cache_get( $ck, self::GRANTS_CACHE_GROUP );
			if ( false === $pending ) {
				global $wpdb;
				$tbl = BizCity_CRM_Admin_Chat_Grants::table();
				$pending = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl . ' WHERE status="pending"' );
				wp_cache_set( $ck, $pending, self::GRANTS_CACHE_GROUP, self::GRANTS_CACHE_TTL );
			}
			return array( 'version' => (int) $ver, 'pending' => (int) $pending );
		} );
	}

	public static function admin_chat_grants_list( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) ) {
				return array( 'rows' => array(), 'counts' => array( 'pending' => 0, 'active' => 0, 'revoked' => 0 ), 'version' => 0 );
			}
			$status = sanitize_key( (string) $req->get_param( 'status' ) );
			$limit  = (int) $req->get_param( 'limit' );
			if ( $limit <= 0 || $limit > 200 ) { $limit = 50; }
			if ( ! in_array( $status, array( 'active', 'pending', 'revoked' ), true ) ) { $status = ''; }

			$ver = self::grants_version();
			$ck  = 'list_v' . $ver . '_s' . $status . '_l' . $limit;
			$cached = wp_cache_get( $ck, self::GRANTS_CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			global $wpdb;
			$tbl   = BizCity_CRM_Admin_Chat_Grants::table();
			$where = '1=1';
			if ( '' !== $status ) {
				$where = $wpdb->prepare( 'status = %s', $status );
			}
			$rows = $wpdb->get_results(
				'SELECT * FROM ' . $tbl . ' WHERE ' . $where
				. ' ORDER BY (status="pending") DESC, created_at DESC LIMIT ' . (int) $limit,
				ARRAY_A
			) ?: array();

			$count_rows = $wpdb->get_results(
				'SELECT status, COUNT(*) AS n FROM ' . $tbl . ' GROUP BY status',
				ARRAY_A
			) ?: array();
			$counts = array( 'pending' => 0, 'active' => 0, 'revoked' => 0 );
			foreach ( $count_rows as $r ) {
				$counts[ $r['status'] ] = (int) $r['n'];
			}

			$shaped = array_map( array( __CLASS__, 'shape_admin_chat_grant' ), $rows );
			$out = array( 'rows' => $shaped, 'counts' => $counts, 'version' => (int) $ver );
			wp_cache_set( $ck, $out, self::GRANTS_CACHE_GROUP, self::GRANTS_CACHE_TTL );
			return $out;
		} );
	}

	public static function admin_chat_grants_approve( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) ) { throw new \RuntimeException( 'grants_unavailable' ); }
			$id = (int) $req->get_param( 'id' );
			if ( $id <= 0 ) { throw new \RuntimeException( 'invalid_id' ); }
			global $wpdb;
			$ok = $wpdb->update(
				BizCity_CRM_Admin_Chat_Grants::table(),
				array(
					'status'             => BizCity_CRM_Admin_Chat_Grants::STATUS_ACTIVE,
					'granted_by_user_id' => get_current_user_id(),
					'granted_at'         => current_time( 'mysql' ),
					'updated_at'         => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			if ( $ok === false ) { throw new \RuntimeException( $wpdb->last_error ?: 'update_failed' ); }
			self::bump_grants_version();
			do_action( 'bizcity_crm_admin_chat_grant_approved', $id, get_current_user_id() );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BizCity_CRM_Admin_Chat_Grants::table() . ' WHERE id=%d', $id ), ARRAY_A );
			return self::shape_admin_chat_grant( (array) $row );
		} );
	}

	public static function admin_chat_grants_revoke( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) ) { throw new \RuntimeException( 'grants_unavailable' ); }
			$id = (int) $req->get_param( 'id' );
			if ( $id <= 0 ) { throw new \RuntimeException( 'invalid_id' ); }
			BizCity_CRM_Admin_Chat_Grants::revoke( $id, get_current_user_id() );
			self::bump_grants_version();
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BizCity_CRM_Admin_Chat_Grants::table() . ' WHERE id=%d', $id ), ARRAY_A );
			return self::shape_admin_chat_grant( (array) $row );
		} );
	}

	public static function admin_chat_grants_update( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) ) { throw new \RuntimeException( 'grants_unavailable' ); }
			$id = (int) $req->get_param( 'id' );
			if ( $id <= 0 ) { throw new \RuntimeException( 'invalid_id' ); }
			$body = (array) $req->get_json_params();

			$update = array( 'updated_at' => current_time( 'mysql' ) );
			$fmt    = array( '%s' );
			foreach ( array( 'allow_producer', 'allow_retriever', 'allow_distributor' ) as $f ) {
				if ( array_key_exists( $f, $body ) ) {
					$update[ $f ] = ! empty( $body[ $f ] ) ? 1 : 0;
					$fmt[]        = '%d';
				}
			}
			if ( array_key_exists( 'quota_per_day', $body ) ) {
				$update['quota_per_day'] = max( 0, (int) $body['quota_per_day'] );
				$fmt[]                    = '%d';
			}
			if ( array_key_exists( 'tool_overrides_json', $body ) ) {
				$ov = $body['tool_overrides_json'];
				if ( is_array( $ov ) ) {
					$clean = array();
					foreach ( $ov as $tool => $verb ) {
						$verb = strtolower( (string) $verb );
						if ( in_array( $verb, array( 'allow', 'confirm', 'deny' ), true ) ) {
							$clean[ sanitize_key( (string) $tool ) ] = $verb;
						}
					}
					$update['tool_overrides_json'] = $clean ? wp_json_encode( $clean ) : null;
				} else {
					$update['tool_overrides_json'] = null;
				}
				$fmt[] = '%s';
			}

			global $wpdb;
			$ok = $wpdb->update(
				BizCity_CRM_Admin_Chat_Grants::table(),
				$update,
				array( 'id' => $id ),
				$fmt,
				array( '%d' )
			);
			if ( $ok === false ) { throw new \RuntimeException( $wpdb->last_error ?: 'update_failed' ); }
			self::bump_grants_version();
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BizCity_CRM_Admin_Chat_Grants::table() . ' WHERE id=%d', $id ), ARRAY_A );
			return self::shape_admin_chat_grant( (array) $row );
		} );
	}

	/* ------- M-CRM.M4.Inbox v1.18.0 — Broadcast handlers ------- */

	public static function broadcasts_list( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl    = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$status = sanitize_text_field( (string) $req->get_param( 'status' ) );
			$limit  = max( 1, min( (int) ( $req->get_param( 'limit' ) ?: 50 ), 200 ) );
			$offset = max( 0, (int) $req->get_param( 'offset' ) );
			$where  = '1=1';
			$params = array();
			if ( $status !== '' ) {
				$where  .= ' AND status = %s';
				$params[] = $status;
			}
			$sql  = "SELECT * FROM `{$tbl}` WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
			$params[] = $limit;
			$params[] = $offset;
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE {$where}", ...array_slice( $params, 0, count( $params ) - 2 ) ) );
			return array( 'items' => array_map( array( __CLASS__, 'shape_broadcast' ), $rows ), 'total' => $total );
		} );
	}

	public static function broadcasts_create( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body  = (array) $req->get_json_params();
			$title = sanitize_text_field( (string) ( isset( $body['title'] ) ? $body['title'] : '' ) );
			if ( $title === '' ) { throw new \RuntimeException( 'title_required' ); }
			// [2026-06-07 Johnny Chu] PHASE-0.43 M3.1 — accept action_flags, delay_sec, campaign_id
			$allowed_delays = array( 0, 5, 15, 30, 60, 120, 180 );
			$delay_sec = isset( $body['delay_sec'] ) ? (int) $body['delay_sec'] : 5;
			if ( ! in_array( $delay_sec, $allowed_delays, true ) ) {
				throw new \RuntimeException( 'invalid_param' ); // R-ERROR-UX bucket
			}
			$action_flags = null;
			if ( isset( $body['action_flags'] ) && is_array( $body['action_flags'] ) ) {
				$af = $body['action_flags'];
				// invite_group requires group_id
				if ( ! empty( $af['invite_group'] ) && empty( $af['group_id'] ) ) {
					throw new \RuntimeException( 'invalid_param' );
				}
				$action_flags = wp_json_encode( array(
					'send_message'        => ! empty( $af['send_message'] ),
					'send_friend_request' => ! empty( $af['send_friend_request'] ),
					'invite_group'        => ! empty( $af['invite_group'] ),
					'group_id'            => sanitize_text_field( (string) ( isset( $af['group_id'] ) ? $af['group_id'] : '' ) ),
				) );
			}
			$campaign_id = isset( $body['campaign_id'] ) ? (int) $body['campaign_id'] : 0;
			$template   = (string) ( isset( $body['message_template'] ) ? $body['message_template'] : '' );
			$inbox_ids  = isset( $body['inbox_ids'] ) && is_array( $body['inbox_ids'] ) ? array_map( 'absint', $body['inbox_ids'] ) : null;
			$filter     = isset( $body['segment_filter'] ) && is_array( $body['segment_filter'] ) ? $body['segment_filter'] : null;
			$now        = current_time( 'mysql' );
			$tbl        = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$id = self::broadcasts_insert_row_compat( $tbl, array(
				'title'               => $title,
				'inbox_ids_json'      => $inbox_ids ? wp_json_encode( $inbox_ids ) : null,
				'segment_filter_json' => $filter ? wp_json_encode( $filter ) : null,
				'message_template'    => $template,
				'status'              => 'draft',
				'scheduled_at'        => isset( $body['scheduled_at'] ) ? sanitize_text_field( (string) $body['scheduled_at'] ) : null,
				'created_by'          => get_current_user_id(),
				'created_at'          => $now,
				'updated_at'          => $now,
				'action_flags_json'   => $action_flags,
				'delay_sec'           => $delay_sec,
				'campaign_id'         => $campaign_id > 0 ? $campaign_id : null,
			) );
			if ( ! $id ) { throw new \RuntimeException( 'insert_failed' ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_broadcast( (array) $row );
		} );
	}

	public static function broadcasts_get( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { throw new \RuntimeException( 'broadcast_not_found' ); }
			return self::shape_broadcast( $row );
		} );
	}

	public static function broadcasts_update( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id   = (int) $req['id'];
			$tbl  = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $row ) { throw new \RuntimeException( 'broadcast_not_found' ); }
			if ( in_array( $row['status'], array( 'sending', 'sent' ), true ) ) { throw new \RuntimeException( 'cannot_edit_after_send' ); }

			$body   = (array) $req->get_json_params();
			$update = array( 'updated_at' => current_time( 'mysql' ) );
			$fmt    = array( '%s' );
			foreach ( array( 'title', 'message_template', 'scheduled_at', 'status' ) as $f ) {
				if ( array_key_exists( $f, $body ) ) { $update[ $f ] = sanitize_text_field( (string) $body[ $f ] ); $fmt[] = '%s'; }
			}
			if ( array_key_exists( 'inbox_ids', $body ) ) {
				$update['inbox_ids_json'] = is_array( $body['inbox_ids'] ) ? wp_json_encode( array_map( 'absint', $body['inbox_ids'] ) ) : null;
				$fmt[] = '%s';
			}
			if ( array_key_exists( 'segment_filter', $body ) ) {
				$update['segment_filter_json'] = is_array( $body['segment_filter'] ) ? wp_json_encode( $body['segment_filter'] ) : null;
				$fmt[] = '%s';
			}
			$wpdb->update( $tbl, $update, array( 'id' => $id ), $fmt, array( '%d' ) );
			$updated = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_broadcast( (array) $updated );
		} );
	}

	public static function broadcasts_delete( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id  = (int) $req['id'];
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $row ) { throw new \RuntimeException( 'broadcast_not_found' ); }
			$wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
			$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients(), array( 'broadcast_id' => $id ), array( '%d' ) );
			return array( 'deleted' => true, 'id' => $id );
		} );
	}

	/**
	 * POST /broadcasts/{id}/send
	 * Resolves recipients from segment_filter_json, enqueues via Dispatcher (delay-aware),
	 * then fires bizcity_crm_broadcast_dispatch.
	 */
	public static function broadcasts_send( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id     = (int) $req['id'];
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $row ) { throw new \RuntimeException( 'broadcast_not_found' ); }

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: delegate start to canonical channel-gateway.
			$link = self::broadcasts_channel_link( $row );
			if ( ! empty( $link['id'] ) ) {
				self::broadcasts_channel_proxy( 'POST', 'broadcasts/' . (int) $link['id'] . '/start' );
				self::broadcasts_sync_local_from_channel( $id, (int) $link['id'] );
				$updated = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
				return array_merge( self::shape_broadcast( (array) $updated ), array(
					'queued'    => 0,
					'skipped'   => 0,
					'_degraded' => true,
				) );
			}

			if ( in_array( $row['status'], array( 'sending', 'sent' ), true ) ) {
				throw new \RuntimeException( 'already_sent' );
			}
			// Resolve contacts via segment filter.
			$filter   = isset( $row['segment_filter_json'] ) && $row['segment_filter_json'] ? json_decode( $row['segment_filter_json'], true ) : array();
			if ( ! is_array( $filter ) ) { $filter = array(); }
			$contacts = self::resolve_broadcast_contacts( $filter );
			if ( empty( $contacts ) ) {
				throw new \RuntimeException( 'no_recipients_matched_filter' );
			}
			// Max 5000 per broadcast (PHASE-0.43 validation)
			$contacts = array_slice( $contacts, 0, 5000 );
			$now = current_time( 'mysql' );
			$wpdb->update( $bc_tbl, array(
				'status'      => 'sending',
				'total_count' => count( $contacts ),
				'sent_at'     => $now,
				'updated_at'  => $now,
			), array( 'id' => $id ), array( '%s', '%d', '%s', '%s' ), array( '%d' ) );
			// [2026-06-07 Johnny Chu] PHASE-0.43 M3.2 — enqueue via Dispatcher (delay-aware scheduled_send_at)
			$result = class_exists( 'BizCity_CRM_Broadcast_Dispatcher' )
				? BizCity_CRM_Broadcast_Dispatcher::enqueue( $id, $contacts )
				: array( 'enqueued' => 0, 'skipped' => count( $contacts ) );
			do_action( 'bizcity_crm_broadcast_dispatch', $id );
			$updated = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			return array_merge( self::shape_broadcast( (array) $updated ), array(
				'queued'  => isset( $result['enqueued'] ) ? (int) $result['enqueued'] : 0,
				'skipped' => isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
			) );
		} );
	}

	/**
	 * Resolve contact IDs from a segment filter array.
	 * Filter keys: label_ids[], segments[], lead_score_min, status_in[].
	 * @return int[]
	 */
	private static function resolve_broadcast_contacts( array $filter ): array {
		global $wpdb;
		// [2026-06-07 Johnny Chu] PHASE-0.43 — explicit contact_ids short-circuit (from BroadcastCreateDialog)
		if ( ! empty( $filter['contact_ids'] ) && is_array( $filter['contact_ids'] ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $filter['contact_ids'] ) ) ) );
			return array_slice( $ids, 0, 5000 );
		}
		$contacts_tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$ci_tbl       = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$cl_tbl       = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$cv_tbl       = BizCity_CRM_DB_Installer_V2::tbl_conversations();

		$where  = array( 'c.deleted_at IS NULL' );
		$params = array();

		// Filter by segment (A/B/C/VIP).
		if ( ! empty( $filter['segments'] ) && is_array( $filter['segments'] ) ) {
			$segs = array_map( 'sanitize_text_field', $filter['segments'] );
			$phs  = implode( ',', array_fill( 0, count( $segs ), '%s' ) );
			$where[]  = "c.segment IN ({$phs})";
			$params   = array_merge( $params, $segs );
		}

		// Filter by lead_score minimum.
		if ( isset( $filter['lead_score_min'] ) && (int) $filter['lead_score_min'] > 0 ) {
			$where[]  = 'c.lead_score >= %d';
			$params[] = (int) $filter['lead_score_min'];
		}

		// Filter by label (any conv for this contact has the label).
		if ( ! empty( $filter['label_ids'] ) && is_array( $filter['label_ids'] ) ) {
			$lids = array_map( 'absint', $filter['label_ids'] );
			$phs  = implode( ',', array_fill( 0, count( $lids ), '%d' ) );
			$where[]  = "c.id IN (SELECT ci.contact_id FROM {$ci_tbl} ci JOIN {$cv_tbl} cv ON cv.contact_inbox_id=ci.id JOIN {$cl_tbl} cl ON cl.conversation_id=cv.id WHERE cl.label_id IN ({$phs}))";
			$params   = array_merge( $params, $lids );
		}

		$sql = "SELECT c.id FROM `{$contacts_tbl}` c WHERE " . implode( ' AND ', $where ) . ' LIMIT 5000';
		$rows = $params
			? $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) )
			: $wpdb->get_col( $sql );
		return array_map( 'intval', (array) $rows );
	}

	public static function broadcasts_recipients( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id      = (int) $req['id'];
			$bc_tbl  = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$tbl     = BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
			$ct_tbl  = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$limit   = max( 1, min( (int) ( $req->get_param( 'limit' ) ?: 50 ), 200 ) );
			$offset  = max( 0, (int) $req->get_param( 'offset' ) );
			$status  = sanitize_key( (string) $req->get_param( 'status' ) );
			$search  = sanitize_text_field( (string) $req->get_param( 'search' ) );
			$activity = (int) $req->get_param( 'activity' ) === 1;

			$allowed_status = array( 'queued', 'sending', 'sent', 'failed', 'skipped' );
			if ( ! in_array( $status, $allowed_status, true ) ) {
				$status = '';
			}

			$bc_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, message_template FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $bc_row ) {
				throw new \RuntimeException( 'broadcast_not_found' );
			}

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: load recipients from channel source of truth.
			$link = self::broadcasts_channel_link( $bc_row );
			if ( ! empty( $link['id'] ) ) {
				$page = (int) floor( $offset / $limit ) + 1;
				$data = self::broadcasts_channel_proxy( 'GET', 'broadcasts/' . (int) $link['id'] . '/recipients', array(
					'q'        => $search,
					'status'   => $status,
					'page'     => $page,
					'per_page' => $limit,
					'activity' => $activity ? 1 : 0,
				) );

				$counts = array(
					'total'   => 0,
					'queued'  => 0,
					'sending' => 0,
					'sent'    => 0,
					'failed'  => 0,
					'skipped' => 0,
				);
				if ( isset( $data['counts'] ) && is_array( $data['counts'] ) ) {
					foreach ( array_keys( $counts ) as $k ) {
						if ( isset( $data['counts'][ $k ] ) ) {
							$counts[ $k ] = (int) $data['counts'][ $k ];
						}
					}
				}

				$total = isset( $data['total'] ) ? (int) $data['total'] : 0;
				if ( $counts['total'] <= 0 ) {
					$counts['total'] = $total;
				}

				$items = array();
				foreach ( (array) ( isset( $data['items'] ) ? $data['items'] : array() ) as $r ) {
					$items[] = array(
						'id'                => isset( $r['id'] ) ? (int) $r['id'] : 0,
						'contact_id'        => 0,
						'name'              => isset( $r['name'] ) ? (string) $r['name'] : '',
						'phone'             => isset( $r['phone'] ) ? (string) $r['phone'] : '',
						'email'             => isset( $r['email'] ) ? (string) $r['email'] : '',
						'conversation_id'   => null,
						'status'            => isset( $r['status'] ) ? (string) $r['status'] : 'queued',
						'sent_at'           => isset( $r['sent_at'] ) ? $r['sent_at'] : null,
						'scheduled_send_at' => isset( $r['scheduled_send_at'] ) ? $r['scheduled_send_at'] : null,
						'error'             => isset( $r['error'] ) ? (string) $r['error'] : '',
					);
				}

				return array(
					'items'   => $items,
					'total'   => $total,
					'counts'  => $counts,
					'limit'   => $limit,
					'offset'  => $offset,
					'_degraded' => true,
				);
			}

			$where  = array( 'r.broadcast_id=%d' );
			$params = array( $id );

			if ( $activity ) {
				$where[] = "r.status IN ('sent','failed')";
			} elseif ( $status !== '' ) {
				$where[] = 'r.status=%s';
				$params[] = $status;
			}

			if ( $search !== '' ) {
				// [2026-07-10 Johnny Chu] PHASE-0.47 — checklist quick search by name/phone/email.
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				$where[] = '(c.name LIKE %s OR c.phone LIKE %s OR c.email LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}

			$order = $activity ? 'r.sent_at DESC, r.id DESC' : 'r.id ASC';
			$sql_base = " FROM `{$tbl}` r LEFT JOIN `{$ct_tbl}` c ON c.id = r.contact_id WHERE " . implode( ' AND ', $where );

			$params_items = $params;
			$params_items[] = $limit;
			$params_items[] = $offset;
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT r.*, c.name AS contact_name, c.phone AS contact_phone, c.email AS contact_email {$sql_base} ORDER BY {$order} LIMIT %d OFFSET %d", ...$params_items ),
				ARRAY_A
			) ?: array();

			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$sql_base}", ...$params ) );

			$count_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT status, COUNT(*) AS cnt FROM `{$tbl}` WHERE broadcast_id=%d GROUP BY status", $id ),
				ARRAY_A
			) ?: array();
			$counts = array(
				'total'   => 0,
				'queued'  => 0,
				'sending' => 0,
				'sent'    => 0,
				'failed'  => 0,
				'skipped' => 0,
			);
			foreach ( $count_rows as $cr ) {
				$st = (string) ( isset( $cr['status'] ) ? $cr['status'] : '' );
				$ct = (int) ( isset( $cr['cnt'] ) ? $cr['cnt'] : 0 );
				$counts['total'] += $ct;
				if ( isset( $counts[ $st ] ) ) {
					$counts[ $st ] = $ct;
				}
			}

			$items  = array();
			foreach ( $rows as $r ) {
				$items[] = array(
					'id'                => (int) $r['id'],
					'contact_id'        => (int) $r['contact_id'],
					'name'              => isset( $r['contact_name'] ) ? (string) $r['contact_name'] : '',
					'phone'             => isset( $r['contact_phone'] ) ? (string) $r['contact_phone'] : '',
					'email'             => isset( $r['contact_email'] ) ? (string) $r['contact_email'] : '',
					'conversation_id'   => isset( $r['conversation_id'] ) ? (int) $r['conversation_id'] : null,
					'status'            => (string) $r['status'],
					'sent_at'           => $r['sent_at'],
					'scheduled_send_at' => isset( $r['scheduled_send_at'] ) ? $r['scheduled_send_at'] : null,
					'error'             => $r['error'],
				);
			}
			return array(
				'items'  => $items,
				'total'  => $total,
				'counts' => $counts,
				'limit'  => $limit,
				'offset' => $offset,
			);
		} );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — POST /broadcasts/{id}/recipients/dispatch
	 * Resume dispatch for queued recipients (all or selected IDs).
	 */
	public static function broadcasts_recipients_dispatch( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id     = (int) $req['id'];
			$body   = (array) $req->get_json_params();
			$rc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$bc_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, message_template FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $bc_row ) {
				throw new \RuntimeException( 'broadcast_not_found' );
			}

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: dispatch selected recipients in channel ledger.
			$link = self::broadcasts_channel_link( $bc_row );
			if ( ! empty( $link['id'] ) ) {
				$payload = array();
				if ( isset( $body['recipient_ids'] ) && is_array( $body['recipient_ids'] ) ) {
					$payload['recipient_ids'] = array_values( array_filter( array_map( 'absint', $body['recipient_ids'] ) ) );
				}
				if ( isset( $body['phones'] ) && is_array( $body['phones'] ) ) {
					$payload['phones'] = array_values( array_filter( array_map( 'sanitize_text_field', $body['phones'] ) ) );
				}
				if ( ! empty( $body['all_failed'] ) ) {
					$payload['all_failed'] = 1;
				}

				try {
					$data = self::broadcasts_channel_proxy( 'POST', 'broadcasts/' . (int) $link['id'] . '/recipients/dispatch', array(), $payload );
				} catch ( \RuntimeException $e ) {
					// [2026-07-10 Johnny Chu] PHASE-0.47 — selected rows may already be queued; still resume channel dispatcher.
					if ( (string) $e->getMessage() !== 'no_rows_updated' ) {
						throw $e;
					}
					$data = self::broadcasts_channel_proxy( 'POST', 'broadcasts/' . (int) $link['id'] . '/start' );
				}
				self::broadcasts_sync_local_from_channel( $id, (int) $link['id'] );
				$pg = isset( $data['progress'] ) && is_array( $data['progress'] ) ? $data['progress'] : array();
				return array(
					'broadcast_id'         => $id,
					'dispatched'           => isset( $data['updated'] ) ? (int) $data['updated'] : 0,
					'queued'               => isset( $pg['queued'] ) ? (int) $pg['queued'] : 0,
					'channel_broadcast_id' => (int) $link['id'],
					'_degraded'            => true,
				);
			}

			$ids = array();
			if ( isset( $body['recipient_ids'] ) && is_array( $body['recipient_ids'] ) ) {
				$ids = array_values( array_unique( array_filter( array_map( 'absint', $body['recipient_ids'] ) ) ) );
				$ids = array_slice( $ids, 0, 1000 );
			}

			$queued = 0;
			if ( ! empty( $ids ) ) {
				$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$params = array_merge( array( $id ), $ids );
				$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$rc_tbl}` WHERE broadcast_id=%d AND status='queued' AND id IN ({$ph})", ...$params ) );
			} else {
				$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$rc_tbl}` WHERE broadcast_id=%d AND status='queued'", $id ) );
			}

			if ( $queued <= 0 ) {
				return array( 'broadcast_id' => $id, 'dispatched' => 0, 'queued' => 0 );
			}

			// [2026-07-10 Johnny Chu] PHASE-0.47 — mark active before triggering worker tick.
			$wpdb->update(
				$bc_tbl,
				array( 'status' => 'sending', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
				update_option( BizCity_CRM_Broadcast_Dispatcher::OPT_PAUSED, '0' );
			}
			do_action( 'bizcity_crm_broadcast_dispatch', $id );

			return array( 'broadcast_id' => $id, 'dispatched' => $queued, 'queued' => $queued );
		} );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — POST /broadcasts/{id}/recipients/retry
	 * Requeue failed recipients (all or selected IDs), then dispatch.
	 */
	public static function broadcasts_recipients_retry( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id     = (int) $req['id'];
			$body   = (array) $req->get_json_params();
			$rc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$bc_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, message_template FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $bc_row ) {
				throw new \RuntimeException( 'broadcast_not_found' );
			}

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: retry through channel recipient ledger.
			$link = self::broadcasts_channel_link( $bc_row );
			if ( ! empty( $link['id'] ) ) {
				$payload = array();
				if ( isset( $body['recipient_ids'] ) && is_array( $body['recipient_ids'] ) ) {
					$payload['recipient_ids'] = array_values( array_filter( array_map( 'absint', $body['recipient_ids'] ) ) );
				}
				if ( isset( $body['phones'] ) && is_array( $body['phones'] ) ) {
					$payload['phones'] = array_values( array_filter( array_map( 'sanitize_text_field', $body['phones'] ) ) );
				}
				if ( ! empty( $body['all_failed'] ) ) {
					$payload['all_failed'] = 1;
				}

				$data = self::broadcasts_channel_proxy( 'POST', 'broadcasts/' . (int) $link['id'] . '/recipients/retry', array(), $payload );
				self::broadcasts_sync_local_from_channel( $id, (int) $link['id'] );
				return array(
					'broadcast_id'         => $id,
					'retried'              => isset( $data['updated'] ) ? (int) $data['updated'] : 0,
					'channel_broadcast_id' => (int) $link['id'],
					'_degraded'            => true,
				);
			}

			$has_scheduled_send = BizCity_CRM_DB_Installer_V2::column_exists( $rc_tbl, 'scheduled_send_at' );
			$set_clause = $has_scheduled_send
				? "status='queued', sent_at=NULL, error=NULL, scheduled_send_at=NULL"
				: "status='queued', sent_at=NULL, error=NULL";

			$ids = array();
			if ( isset( $body['recipient_ids'] ) && is_array( $body['recipient_ids'] ) ) {
				$ids = array_values( array_unique( array_filter( array_map( 'absint', $body['recipient_ids'] ) ) ) );
				$ids = array_slice( $ids, 0, 1000 );
			}

			if ( ! empty( $ids ) ) {
				$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$params = array_merge( array( $id ), $ids );
				$wpdb->query( $wpdb->prepare(
					"UPDATE `{$rc_tbl}` SET {$set_clause} WHERE broadcast_id=%d AND status='failed' AND id IN ({$ph})",
					...$params
				) );
			} else {
				$wpdb->query( $wpdb->prepare(
					"UPDATE `{$rc_tbl}` SET {$set_clause} WHERE broadcast_id=%d AND status='failed'",
					$id
				) );
			}

			$retried = (int) $wpdb->rows_affected;
			if ( $retried > 0 ) {
				$wpdb->update(
					$bc_tbl,
					array( 'status' => 'sending', 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
					update_option( BizCity_CRM_Broadcast_Dispatcher::OPT_PAUSED, '0' );
				}
				do_action( 'bizcity_crm_broadcast_dispatch', $id );
			}

			return array( 'broadcast_id' => $id, 'retried' => $retried );
		} );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — POST /broadcasts/{id}/recipients/{rid}/retry
	 * Requeue a single failed recipient and resume dispatch.
	 */
	public static function broadcasts_recipient_retry_one( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id     = (int) $req['id'];
			$rid    = (int) $req['rid'];
			$rc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$bc_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, message_template FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $bc_row ) {
				throw new \RuntimeException( 'broadcast_not_found' );
			}

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: one-click retry on channel recipient id.
			$link = self::broadcasts_channel_link( $bc_row );
			if ( ! empty( $link['id'] ) ) {
				$data = self::broadcasts_channel_proxy( 'POST', 'broadcasts/' . (int) $link['id'] . '/recipients/' . $rid . '/retry' );
				self::broadcasts_sync_local_from_channel( $id, (int) $link['id'] );
				return array(
					'broadcast_id'         => $id,
					'recipient_id'         => $rid,
					'retried'              => ! empty( $data['updated'] ),
					'channel_broadcast_id' => (int) $link['id'],
					'_degraded'            => true,
				);
			}

			$has_scheduled_send = BizCity_CRM_DB_Installer_V2::column_exists( $rc_tbl, 'scheduled_send_at' );
			$set_clause = $has_scheduled_send
				? "status='queued', sent_at=NULL, error=NULL, scheduled_send_at=NULL"
				: "status='queued', sent_at=NULL, error=NULL";

			$wpdb->query( $wpdb->prepare(
				"UPDATE `{$rc_tbl}` SET {$set_clause} WHERE broadcast_id=%d AND id=%d AND status='failed'",
				$id,
				$rid
			) );
			$retried = (int) $wpdb->rows_affected;

			if ( $retried > 0 ) {
				$wpdb->update(
					$bc_tbl,
					array( 'status' => 'sending', 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
					update_option( BizCity_CRM_Broadcast_Dispatcher::OPT_PAUSED, '0' );
				}
				do_action( 'bizcity_crm_broadcast_dispatch', $id );
			}

			return array( 'broadcast_id' => $id, 'recipient_id' => $rid, 'retried' => $retried > 0 );
		} );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — GET /broadcasts/{id}/console
	 * Checklist console payload: counters + recent sent/failed logs.
	 */
	public static function broadcasts_console( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id      = (int) $req['id'];
			$limit   = max( 1, min( (int) ( $req->get_param( 'limit' ) ?: 30 ), 200 ) );
			$bc_tbl  = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$rc_tbl  = BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
			$ct_tbl  = BizCity_CRM_DB_Installer_V2::tbl_contacts();

			$bc_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, status, total_count, sent_count, failed_count, updated_at, message_template FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $bc_row ) {
				throw new \RuntimeException( 'broadcast_not_found' );
			}

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: console data from channel source of truth.
			$link = self::broadcasts_channel_link( $bc_row );
			if ( ! empty( $link['id'] ) ) {
				$data = self::broadcasts_channel_proxy( 'GET', 'broadcasts/' . (int) $link['id'] . '/console', array( 'limit' => $limit ) );

				$counts = array(
					'total'   => 0,
					'queued'  => 0,
					'sending' => 0,
					'sent'    => 0,
					'failed'  => 0,
					'skipped' => 0,
				);
				if ( isset( $data['counts'] ) && is_array( $data['counts'] ) ) {
					foreach ( array_keys( $counts ) as $k ) {
						if ( isset( $data['counts'][ $k ] ) ) {
							$counts[ $k ] = (int) $data['counts'][ $k ];
						}
					}
				}

				$pg = isset( $data['progress'] ) && is_array( $data['progress'] ) ? $data['progress'] : array();
				$total = isset( $pg['total'] ) ? (int) $pg['total'] : (int) $counts['total'];
				$done  = isset( $pg['sent'] ) ? (int) $pg['sent'] : ( (int) $counts['sent'] + (int) $counts['failed'] + (int) $counts['skipped'] );

				$items = array();
				foreach ( (array) ( isset( $data['activity'] ) ? $data['activity'] : array() ) as $r ) {
					$items[] = array(
						'id'         => isset( $r['id'] ) ? (int) $r['id'] : 0,
						'contact_id' => 0,
						'name'       => isset( $r['name'] ) ? (string) $r['name'] : '',
						'phone'      => isset( $r['phone'] ) ? (string) $r['phone'] : '',
						'email'      => isset( $r['email'] ) ? (string) $r['email'] : '',
						'status'     => isset( $r['status'] ) ? (string) $r['status'] : '',
						'sent_at'    => isset( $r['sent_at'] ) ? (string) $r['sent_at'] : null,
						'error'      => isset( $r['error'] ) ? (string) $r['error'] : '',
					);
				}

				self::broadcasts_sync_local_from_channel( $id, (int) $link['id'] );

				return array(
					'broadcast' => array(
						'id'           => (int) $bc_row['id'],
						'title'        => (string) $bc_row['title'],
						'status'       => isset( $data['broadcast_status'] ) ? (string) $data['broadcast_status'] : (string) $bc_row['status'],
						'total_count'  => $total,
						'sent_count'   => isset( $pg['sent'] ) ? (int) $pg['sent'] : (int) $counts['sent'],
						'failed_count' => isset( $pg['failed'] ) ? (int) $pg['failed'] : (int) $counts['failed'],
						'updated_at'   => (string) $bc_row['updated_at'],
					),
					'counts'    => $counts,
					'progress'  => array(
						'total'   => $total,
						'done'    => $done,
						'percent' => $total > 0 ? round( ( $done / $total ) * 100, 1 ) : 0,
					),
					'logs'      => $items,
					'now'       => current_time( 'mysql' ),
					'_degraded' => true,
				);
			}

			$raw_counts = $wpdb->get_results(
				$wpdb->prepare( "SELECT status, COUNT(*) AS cnt FROM `{$rc_tbl}` WHERE broadcast_id=%d GROUP BY status", $id ),
				ARRAY_A
			) ?: array();

			$counts = array(
				'total'   => 0,
				'queued'  => 0,
				'sending' => 0,
				'sent'    => 0,
				'failed'  => 0,
				'skipped' => 0,
			);
			foreach ( $raw_counts as $c ) {
				$st = (string) ( isset( $c['status'] ) ? $c['status'] : '' );
				$ct = (int) ( isset( $c['cnt'] ) ? $c['cnt'] : 0 );
				$counts['total'] += $ct;
				if ( isset( $counts[ $st ] ) ) {
					$counts[ $st ] = $ct;
				}
			}

			$logs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.id, r.contact_id, r.status, r.sent_at, r.error, c.name AS contact_name, c.phone AS contact_phone, c.email AS contact_email
					 FROM `{$rc_tbl}` r
					 LEFT JOIN `{$ct_tbl}` c ON c.id = r.contact_id
					 WHERE r.broadcast_id=%d AND r.status IN ('sent','failed')
					 ORDER BY r.sent_at DESC, r.id DESC
					 LIMIT %d",
					$id,
					$limit
				),
				ARRAY_A
			) ?: array();

			$items = array();
			foreach ( $logs as $r ) {
				$items[] = array(
					'id'         => (int) $r['id'],
					'contact_id' => (int) $r['contact_id'],
					'name'       => isset( $r['contact_name'] ) ? (string) $r['contact_name'] : '',
					'phone'      => isset( $r['contact_phone'] ) ? (string) $r['contact_phone'] : '',
					'email'      => isset( $r['contact_email'] ) ? (string) $r['contact_email'] : '',
					'status'     => (string) $r['status'],
					'sent_at'    => isset( $r['sent_at'] ) ? (string) $r['sent_at'] : null,
					'error'      => isset( $r['error'] ) ? (string) $r['error'] : '',
				);
			}

			$total = max( (int) $bc_row['total_count'], (int) $counts['total'] );
			$done  = (int) $counts['sent'] + (int) $counts['failed'] + (int) $counts['skipped'];

			return array(
				'broadcast' => array(
					'id'           => (int) $bc_row['id'],
					'title'        => (string) $bc_row['title'],
					'status'       => (string) $bc_row['status'],
					'total_count'  => (int) $bc_row['total_count'],
					'sent_count'   => (int) $bc_row['sent_count'],
					'failed_count' => (int) $bc_row['failed_count'],
					'updated_at'   => (string) $bc_row['updated_at'],
				),
				'counts'    => $counts,
				'progress'  => array(
					'total'   => $total,
					'done'    => $done,
					'percent' => $total > 0 ? round( ( $done / $total ) * 100, 1 ) : 0,
				),
				'logs'      => $items,
				'now'       => current_time( 'mysql' ),
			);
		} );
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-0.43 M3.3 — POST /broadcasts/{id}/enqueue
	 * Enqueue an explicit contact_ids[] list (vs. auto-resolve from segment_filter).
	 */
	public static function broadcasts_enqueue( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id   = (int) $req['id'];
			$body = (array) $req->get_json_params();
			if ( empty( $body['contact_ids'] ) || ! is_array( $body['contact_ids'] ) ) {
				throw new \RuntimeException( 'invalid_param' );
			}
			$contact_ids = array_slice( array_map( 'absint', $body['contact_ids'] ), 0, 5000 );
			if ( ! class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
				return array( 'enqueued' => 0, 'skipped' => count( $contact_ids ), '_degraded' => true );
			}
			$result = BizCity_CRM_Broadcast_Dispatcher::enqueue( $id, $contact_ids );
			return array(
				'broadcast_id' => $id,
				'enqueued'     => isset( $result['enqueued'] ) ? (int) $result['enqueued'] : 0,
				'skipped'      => isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
			);
		} );
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-0.43 M3.4 — GET /broadcasts/{id}/progress
	 */
	public static function broadcasts_progress( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id     = (int) $req['id'];
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$rc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
			$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $row ) { throw new \RuntimeException( 'broadcast_not_found' ); }

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: progress from channel manager contract.
			$link = self::broadcasts_channel_link( $row );
			if ( ! empty( $link['id'] ) ) {
				$data = self::broadcasts_channel_proxy( 'GET', 'broadcasts/' . (int) $link['id'] . '/progress' );
				$pg   = isset( $data['progress'] ) && is_array( $data['progress'] ) ? $data['progress'] : array();
				self::broadcasts_sync_local_from_channel( $id, (int) $link['id'] );
				$total  = isset( $pg['total'] ) ? (int) $pg['total'] : (int) $row['total_count'];
				$sent   = isset( $pg['sent'] ) ? (int) $pg['sent'] : 0;
				$failed = isset( $pg['failed'] ) ? (int) $pg['failed'] : 0;
				$queued = isset( $pg['queued'] ) ? (int) $pg['queued'] : 0;
				return array(
					'id'      => $id,
					'status'  => isset( $data['status'] ) ? (string) $data['status'] : (string) $row['status'],
					'total'   => $total,
					'sent'    => $sent,
					'failed'  => $failed,
					'queued'  => $queued,
					'percent' => $total > 0 ? round( ( $sent + $failed ) / $total * 100, 1 ) : 0,
					'_degraded' => true,
				);
			}

			$total   = (int) $row['total_count'];
			$sent    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$rc_tbl}` WHERE broadcast_id=%d AND status='sent'", $id ) );
			$failed  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$rc_tbl}` WHERE broadcast_id=%d AND status='failed'", $id ) );
			$queued  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$rc_tbl}` WHERE broadcast_id=%d AND status='queued'", $id ) );
			return array(
				'id'      => $id,
				'status'  => (string) $row['status'],
				'total'   => $total,
				'sent'    => $sent,
				'failed'  => $failed,
				'queued'  => $queued,
				'percent' => $total > 0 ? round( ( $sent + $failed ) / $total * 100, 1 ) : 0,
			);
		} );
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-0.43 M3.5 — POST /broadcasts/{id}/pause
	 */
	public static function broadcasts_pause( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id = (int) $req['id'];
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, message_template FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $row ) {
				throw new \RuntimeException( 'broadcast_not_found' );
			}

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: pause canonical channel dispatch.
			$link = self::broadcasts_channel_link( $row );
			if ( ! empty( $link['id'] ) ) {
				self::broadcasts_channel_proxy( 'POST', 'broadcasts/' . (int) $link['id'] . '/pause' );
				self::broadcasts_sync_local_from_channel( $id, (int) $link['id'] );
				return array( 'broadcast_id' => $id, 'paused' => true, '_degraded' => true );
			}

			if ( class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
				update_option( BizCity_CRM_Broadcast_Dispatcher::OPT_PAUSED, '1' );
			}
			return array( 'broadcast_id' => $id, 'paused' => true );
		} );
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-0.43 M3.6 — POST /broadcasts/{id}/cancel
	 * Sets broadcast status=cancelled, marks all queued recipients as skipped.
	 */
	public static function broadcasts_cancel( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id     = (int) $req['id'];
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$rc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
			$row    = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, message_template FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $row ) { throw new \RuntimeException( 'broadcast_not_found' ); }

			// [2026-07-10 Johnny Chu] PHASE-0.47 — linked channel broadcast: cancel canonical channel job.
			$link = self::broadcasts_channel_link( $row );
			if ( ! empty( $link['id'] ) ) {
				self::broadcasts_channel_proxy( 'POST', 'broadcasts/' . (int) $link['id'] . '/cancel' );
				self::broadcasts_sync_local_from_channel( $id, (int) $link['id'] );
				$wpdb->update( $bc_tbl, array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
				return array( 'broadcast_id' => $id, 'cancelled' => true, '_degraded' => true );
			}

			if ( $row['status'] === 'sent' ) { throw new \RuntimeException( 'already_sent' ); }
			$wpdb->update( $bc_tbl, array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
			$wpdb->update( $rc_tbl, array( 'status' => 'skipped' ), array( 'broadcast_id' => $id, 'status' => 'queued' ), array( '%s' ), array( '%d', '%s' ) );
			return array( 'broadcast_id' => $id, 'cancelled' => true );
		} );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — POST /broadcasts/{id}/restart
	 * Reset all recipients to queued and resume sending from start.
	 */
	public static function broadcasts_restart( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id     = (int) $req['id'];
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$rc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
			$has_scheduled_send = BizCity_CRM_DB_Installer_V2::column_exists( $rc_tbl, 'scheduled_send_at' );

			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM `{$bc_tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( ! $row ) {
				throw new \RuntimeException( 'broadcast_not_found' );
			}

			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$rc_tbl}` WHERE broadcast_id=%d", $id ) );
			if ( $total <= 0 ) {
				throw new \RuntimeException( 'no_recipients' );
			}

			// [2026-07-10 Johnny Chu] PHASE-0.47 — full reset so dispatcher can replay the whole broadcast.
			// [2026-07-10 Johnny Chu] HOTFIX — tolerate sites missing scheduled_send_at in legacy schema.
			$reset_sql = $has_scheduled_send
				? "UPDATE `{$rc_tbl}` SET status='queued', sent_at=NULL, error=NULL, scheduled_send_at=NULL WHERE broadcast_id=%d"
				: "UPDATE `{$rc_tbl}` SET status='queued', sent_at=NULL, error=NULL WHERE broadcast_id=%d";
			$wpdb->query( $wpdb->prepare( $reset_sql, $id ) );

			$now = current_time( 'mysql' );
			$wpdb->update(
				$bc_tbl,
				array(
					'status'       => 'sending',
					'total_count'  => $total,
					'sent_count'   => 0,
					'failed_count' => 0,
					'sent_at'      => $now,
					'updated_at'   => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);

			if ( class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
				update_option( BizCity_CRM_Broadcast_Dispatcher::OPT_PAUSED, '0' );
			}

			do_action( 'bizcity_crm_broadcast_dispatch', $id );

			return array(
				'broadcast_id' => $id,
				'restarted'    => true,
				'total_count'  => $total,
				'status'       => 'sending',
			);
		} );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — GET /broadcasts/cron-console
	 * Lightweight polling payload for console widgets.
	 */
	public static function broadcasts_cron_console( WP_REST_Request $req ) {
		return self::wrap( static function () {
			global $wpdb;
			$bc_tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
			$rows   = $wpdb->get_results(
				"SELECT id, title, status, total_count, sent_count, failed_count, updated_at
				 FROM `{$bc_tbl}`
				 ORDER BY updated_at DESC, id DESC
				 LIMIT 50",
				ARRAY_A
			) ?: array();

			$items = array();
			foreach ( $rows as $r ) {
				$items[] = array(
					'id'          => (int) $r['id'],
					'title'       => (string) $r['title'],
					'status'      => (string) $r['status'],
					'total_count' => (int) $r['total_count'],
					'sent_count'  => (int) $r['sent_count'],
					'failed_count'=> (int) $r['failed_count'],
					'updated_at'  => (string) $r['updated_at'],
				);
			}

			$paused = false;
			if ( class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
				$paused = (bool) get_option( BizCity_CRM_Broadcast_Dispatcher::OPT_PAUSED, false );
			}

			return array(
				'items'  => $items,
				'paused' => $paused,
				'now'    => current_time( 'mysql' ),
			);
		} );
	}

	// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — contacts for broadcast recipient picker
	public static function broadcasts_get_contacts( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$limit  = min( 500, max( 1, (int) $req->get_param( 'limit' ) ) );
			$offset = max( 0, (int) $req->get_param( 'offset' ) );
			$search = sanitize_text_field( (string) $req->get_param( 'search' ) );
			$tbl    = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			if ( $search !== '' ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$rows     = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, name, phone, email FROM `{$tbl}` WHERE (name LIKE %s OR phone LIKE %s OR email LIKE %s) AND (phone <> '' OR email <> '') ORDER BY name ASC LIMIT %d OFFSET %d",
						$like, $like, $like, $limit, $offset
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, name, phone, email FROM `{$tbl}` WHERE (phone <> '' OR email <> '') ORDER BY name ASC LIMIT %d OFFSET %d",
						$limit, $offset
					),
					ARRAY_A
				);
			}
			return array( 'ok' => true, 'contacts' => $rows ? $rows : array() );
		} );
	}

	/**
	 * POST /broadcasts/parse-file — proxy parse endpoint to channel-gateway and normalize shape for CRM FE.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public static function broadcasts_parse_file( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			// [2026-07-10 Johnny Chu] PHASE-0.47 — route lives in CRM namespace but parser is canonical in bizcity-channel/v1.
			$inner = new WP_REST_Request( 'POST', '/bizcity-channel/v1/broadcasts/parse-file' );

			$source_kind = (string) $req->get_param( 'source_kind' );
			$source_url  = (string) $req->get_param( 'source_url' );
			if ( $source_kind !== '' ) {
				$inner->set_param( 'source_kind', sanitize_text_field( $source_kind ) );
			}
			if ( $source_url !== '' ) {
				$inner->set_param( 'source_url', esc_url_raw( $source_url ) );
			}

			$files = $req->get_file_params();
			if ( ! empty( $files ) ) {
				$inner->set_file_params( $files );
			}

			$res  = rest_do_request( $inner );
			$data = $res instanceof WP_REST_Response ? $res->get_data() : null;

			if ( ! is_array( $data ) ) {
				throw new \RuntimeException( 'parse_file_unavailable' );
			}

			if ( isset( $data['success'] ) && ! $data['success'] ) {
				throw new \RuntimeException( (string) ( $data['error'] ?? 'parse_failed' ) );
			}

			$rows = array();
			if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
				$rows = $data['rows'];
			} elseif ( isset( $data['recipients'] ) && is_array( $data['recipients'] ) ) {
				$rows = $data['recipients'];
			}

			return array(
				'success' => true,
				'rows'    => array_values( $rows ),
				'count'   => isset( $data['count'] ) ? (int) $data['count'] : count( $rows ),
			);
		} );
	}

	/**
	 * POST /broadcasts/create-zns — create campaign in CRM broadcast tables, then enqueue/dispatch.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public static function broadcasts_create_zns( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			// [2026-07-10 Johnny Chu] PHASE-0.47 — keep create-zns in CRM storage so list/reload reads the same data source.
			global $wpdb;
			$body  = (array) $req->get_json_params();
			$title = sanitize_text_field( (string) ( isset( $body['title'] ) ? $body['title'] : ( isset( $body['name'] ) ? $body['name'] : '' ) ) );
			if ( $title === '' ) {
				throw new \RuntimeException( 'invalid_param: title required' );
			}

			$temp_id = sanitize_text_field( (string) ( isset( $body['temp_id'] ) ? $body['temp_id'] : '' ) );
			if ( $temp_id === '' ) {
				throw new \RuntimeException( 'invalid_param: temp_id required' );
			}

			$recipients_in = isset( $body['recipients'] ) && is_array( $body['recipients'] ) ? $body['recipients'] : array();
			$recipients    = array();
			foreach ( $recipients_in as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$name  = sanitize_text_field( (string) ( isset( $r['name'] ) ? $r['name'] : '' ) );
				$phone = sanitize_text_field( (string) ( isset( $r['phone'] ) ? $r['phone'] : '' ) );
				$email = sanitize_email( (string) ( isset( $r['email'] ) ? $r['email'] : '' ) );
				$cid   = isset( $r['contact_id'] ) ? (int) $r['contact_id'] : ( isset( $r['id'] ) ? (int) $r['id'] : 0 );
				if ( $phone === '' && $email === '' ) {
					continue;
				}
				$recipients[] = array(
					'contact_id' => $cid,
					'name'  => $name,
					'phone' => $phone,
					'email' => $email,
				);
			}
			if ( empty( $recipients ) ) {
				throw new \RuntimeException( 'invalid_param: recipients required' );
			}

			$contact_ids = self::resolve_broadcast_contact_ids( $recipients );

			$temp_data = isset( $body['temp_data'] ) && is_array( $body['temp_data'] ) ? $body['temp_data'] : array();
			$temp_vars = array();
			if ( isset( $body['temp_vars'] ) && is_array( $body['temp_vars'] ) ) {
				foreach ( $body['temp_vars'] as $tv ) {
					if ( ! is_array( $tv ) ) {
						continue;
					}
					$var_name = sanitize_text_field( (string) ( isset( $tv['k'] ) ? $tv['k'] : ( isset( $tv['var_name'] ) ? $tv['var_name'] : '' ) ) );
					if ( $var_name === '' ) {
						continue;
					}
					$val = (string) ( isset( $tv['v'] ) ? $tv['v'] : ( isset( $tv['value'] ) ? $tv['value'] : '' ) );
					$temp_vars[] = array(
						'var_name' => $var_name,
						'value'    => $val,
						'source'   => sanitize_key( (string) ( isset( $tv['source'] ) ? $tv['source'] : 'recipient' ) ),
					);
				}
			}

			$meta = array(
				'temp_id'   => $temp_id,
				'oa_id'     => sanitize_text_field( (string) ( isset( $body['oa_id'] ) ? $body['oa_id'] : '' ) ),
				'temp_data' => $temp_data,
				'temp_vars' => $temp_vars,
			);

			$allowed_delays = array( 0, 5, 15, 30, 60, 120, 180 );
			$delay_sec      = isset( $body['delay_sec'] ) ? (int) $body['delay_sec'] : 5;
			if ( ! in_array( $delay_sec, $allowed_delays, true ) ) {
				$delay_sec = 5;
			}

			if ( empty( $contact_ids ) ) {
				// [2026-07-10 Johnny Chu] PHASE-0.47 — CSV may contain non-CRM contacts; fallback to channel create (phone-based ZNS) and mirror to CRM list.
				$payload = array(
					'name'       => $title,
					'type'       => 'zns',
					'meta'       => $meta,
					'recipients' => $recipients,
					'batch_size' => max( 1, min( 100, (int) ( isset( $body['batch_size'] ) ? $body['batch_size'] : 10 ) ) ),
					'delay_sec'  => $delay_sec,
					'auto_start' => ! array_key_exists( 'auto_start', $body ) || ! empty( $body['auto_start'] ),
				);

				$inner = new WP_REST_Request( 'POST', '/bizcity-channel/v1/broadcasts' );
				$inner->set_body_params( $payload );
				$res  = rest_do_request( $inner );
				$data = $res instanceof WP_REST_Response ? $res->get_data() : null;
				if ( ! is_array( $data ) || empty( $data['success'] ) ) {
					throw new \RuntimeException( (string) ( is_array( $data ) && isset( $data['error'] ) ? $data['error'] : 'create_broadcast_failed' ) );
				}

				$status = sanitize_key( (string) ( isset( $data['status'] ) ? $data['status'] : 'queued' ) );
				if ( ! in_array( $status, array( 'draft', 'queued', 'sending', 'paused', 'sent', 'failed', 'cancelled', 'done' ), true ) ) {
					$status = 'queued';
				}

				$now       = current_time( 'mysql' );
				$bc_tbl    = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
				$total_cnt = isset( $data['imported'] ) ? (int) $data['imported'] : count( $recipients );
				$id = self::broadcasts_insert_row_compat( $bc_tbl, array(
					'title'               => $title,
					'inbox_ids_json'      => null,
					'segment_filter_json' => null,
					'message_template'    => wp_json_encode( array(
						'broadcast_type'       => 'zns',
						'zns'                  => $meta,
						'channel_broadcast_id' => isset( $data['id'] ) ? (int) $data['id'] : 0,
						'channel_source'       => 'bizcity-channel/v1',
					) ),
					'status'              => $status,
					'scheduled_at'        => null,
					'sent_at'             => in_array( $status, array( 'sending', 'sent', 'done' ), true ) ? $now : null,
					'total_count'         => $total_cnt,
					'sent_count'          => 0,
					'failed_count'        => 0,
					'created_by'          => get_current_user_id(),
					'created_at'          => $now,
					'updated_at'          => $now,
					'action_flags_json'   => wp_json_encode( array(
						'send_message'        => false,
						'send_friend_request' => false,
						'invite_group'        => false,
						'group_id'            => '',
					) ),
					'delay_sec'           => $delay_sec,
					'campaign_id'         => null,
				) );
				if ( $id <= 0 ) {
					throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : 'insert_failed' );
				}

				return array(
					'id'          => $id,
					'title'       => $title,
					'status'      => $status,
					'total_count' => $total_cnt,
					'_degraded'   => true,
				);
			}

			$auto_start = ! array_key_exists( 'auto_start', $body ) || ! empty( $body['auto_start'] );
			$status     = $auto_start ? 'sending' : 'queued';
			$now        = current_time( 'mysql' );
			$bc_tbl     = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();

			$id = self::broadcasts_insert_row_compat( $bc_tbl, array(
				'title'               => $title,
				'inbox_ids_json'      => null,
				'segment_filter_json' => wp_json_encode( array( 'contact_ids' => $contact_ids ) ),
				'message_template'    => wp_json_encode( array(
					'broadcast_type' => 'zns',
					'zns'            => $meta,
				) ),
				'status'              => $status,
				'scheduled_at'        => null,
				'sent_at'             => $auto_start ? $now : null,
				'total_count'         => count( $contact_ids ),
				'sent_count'          => 0,
				'failed_count'        => 0,
				'created_by'          => get_current_user_id(),
				'created_at'          => $now,
				'updated_at'          => $now,
				'action_flags_json'   => wp_json_encode( array(
					'send_message'        => true,
					'send_friend_request' => false,
					'invite_group'        => false,
					'group_id'            => '',
				) ),
				'delay_sec'           => $delay_sec,
				'campaign_id'         => null,
			) );
			if ( $id <= 0 ) {
				throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : 'insert_failed' );
			}

			$queued     = 0;
			$skipped    = 0;
			$_degraded  = false;
			if ( $auto_start ) {
				if ( class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
					$result = BizCity_CRM_Broadcast_Dispatcher::enqueue( $id, $contact_ids );
					$queued = isset( $result['enqueued'] ) ? (int) $result['enqueued'] : 0;
					$skipped = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
					do_action( 'bizcity_crm_broadcast_dispatch', $id );
				} else {
					$_degraded = true;
					$wpdb->update( $bc_tbl, array( 'status' => 'queued', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
					$status = 'queued';
				}
			}

			return array(
				'id'          => $id,
				'title'       => $title,
				'status'      => $status,
				'total_count' => count( $contact_ids ),
				'queued'      => $queued,
				'skipped'     => $skipped,
				'_degraded'   => $_degraded,
			);
		} );
	}

	/**
	 * Insert one broadcast row while tolerating schema drift on optional columns.
	 *
	 * @param string $table Broadcasts table name.
	 * @param array  $row   Associative row.
	 * @return int Inserted ID, 0 on failure.
	 */
	private static function broadcasts_insert_row_compat( $table, array $row ) {
		global $wpdb;

		// [2026-07-10 Johnny Chu] HOTFIX — some sites haven't migrated optional cols yet.
		foreach ( array( 'action_flags_json', 'delay_sec', 'campaign_id' ) as $optional_col ) {
			if ( array_key_exists( $optional_col, $row ) && ! BizCity_CRM_DB_Installer_V2::column_exists( $table, $optional_col ) ) {
				unset( $row[ $optional_col ] );
			}
		}

		$format_map = array(
			'title'               => '%s',
			'inbox_ids_json'      => '%s',
			'segment_filter_json' => '%s',
			'message_template'    => '%s',
			'status'              => '%s',
			'scheduled_at'        => '%s',
			'sent_at'             => '%s',
			'total_count'         => '%d',
			'sent_count'          => '%d',
			'failed_count'        => '%d',
			'created_by'          => '%d',
			'created_at'          => '%s',
			'updated_at'          => '%s',
			'action_flags_json'   => '%s',
			'delay_sec'           => '%d',
			'campaign_id'         => '%d',
		);

		$formats = array();
		foreach ( array_keys( $row ) as $col ) {
			$formats[] = isset( $format_map[ $col ] ) ? $format_map[ $col ] : '%s';
		}

		$ok = $wpdb->insert( $table, $row, $formats );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Resolve recipients payload into unique CRM contact IDs.
	 *
	 * @param array $recipients Rows from wizard payload.
	 * @return int[]
	 */
	private static function resolve_broadcast_contact_ids( array $recipients ): array {
		global $wpdb;
		$ct_tbl      = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$contact_ids = array();
		$need        = array();

		foreach ( $recipients as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$cid = isset( $r['contact_id'] ) ? (int) $r['contact_id'] : ( isset( $r['id'] ) ? (int) $r['id'] : 0 );
			if ( $cid > 0 ) {
				$contact_ids[] = $cid;
				continue;
			}
			$need[] = array(
				'phone' => sanitize_text_field( (string) ( isset( $r['phone'] ) ? $r['phone'] : '' ) ),
				'email' => sanitize_email( (string) ( isset( $r['email'] ) ? $r['email'] : '' ) ),
			);
		}

		if ( ! empty( $need ) ) {
			$phones = array();
			$emails = array();
			foreach ( $need as $n ) {
				if ( $n['phone'] !== '' ) {
					$phones[ $n['phone'] ] = true;
				}
				if ( $n['email'] !== '' ) {
					$emails[ strtolower( $n['email'] ) ] = true;
				}
			}

			$phone_map = array();
			if ( ! empty( $phones ) ) {
				$phone_keys = array_keys( $phones );
				$ph         = implode( ',', array_fill( 0, count( $phone_keys ), '%s' ) );
				$rows       = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, phone FROM `{$ct_tbl}` WHERE deleted_at IS NULL AND phone IN ({$ph}) ORDER BY id DESC", ...$phone_keys ),
					ARRAY_A
				) ?: array();
				foreach ( $rows as $row ) {
					$p = (string) ( isset( $row['phone'] ) ? $row['phone'] : '' );
					if ( $p !== '' && ! isset( $phone_map[ $p ] ) ) {
						$phone_map[ $p ] = (int) $row['id'];
					}
				}
			}

			$email_map = array();
			if ( ! empty( $emails ) ) {
				$email_keys = array_keys( $emails );
				$ph         = implode( ',', array_fill( 0, count( $email_keys ), '%s' ) );
				$rows       = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, email FROM `{$ct_tbl}` WHERE deleted_at IS NULL AND email IN ({$ph}) ORDER BY id DESC", ...$email_keys ),
					ARRAY_A
				) ?: array();
				foreach ( $rows as $row ) {
					$e = strtolower( (string) ( isset( $row['email'] ) ? $row['email'] : '' ) );
					if ( $e !== '' && ! isset( $email_map[ $e ] ) ) {
						$email_map[ $e ] = (int) $row['id'];
					}
				}
			}

			foreach ( $need as $n ) {
				$resolved = 0;
				if ( $n['phone'] !== '' && isset( $phone_map[ $n['phone'] ] ) ) {
					$resolved = (int) $phone_map[ $n['phone'] ];
				} elseif ( $n['email'] !== '' ) {
					$key = strtolower( $n['email'] );
					if ( isset( $email_map[ $key ] ) ) {
						$resolved = (int) $email_map[ $key ];
					}
				}
				if ( $resolved > 0 ) {
					$contact_ids[] = $resolved;
				}
			}
		}

		$contact_ids = array_values( array_unique( array_map( 'intval', $contact_ids ) ) );
		return array_values( array_filter( $contact_ids ) );
	}

	/**
	 * Build a minimal XLSX binary content for broadcast recipient template.
	 *
	 * @param array $rows Template rows.
	 * @return string
	 */
	private static function _broadcast_template_xlsx_binary( array $rows ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — native XLSX template generation in canonical CRM REST controller.
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$tmp = wp_tempnam( 'broadcast-template.xlsx' );
		if ( ! $tmp ) {
			return '';
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp );
			return '';
		}

		$sheet_rows = '';
		foreach ( $rows as $row_idx => $row ) {
			$sheet_rows .= self::_broadcast_template_xlsx_row_xml( $row_idx + 1, $row );
		}

		$max_row   = max( 1, count( $rows ) );
		$sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<dimension ref="A1:C' . $max_row . '"/>'
			. '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. '<sheetData>' . $sheet_rows . '</sheetData>'
			. '</worksheet>';

		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Recipients" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>' );
		$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
			. '</styleSheet>' );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet_xml );
		$zip->close();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$binary = file_get_contents( $tmp );
		@unlink( $tmp );
		if ( $binary === false ) {
			return '';
		}
		return $binary;
	}

	/**
	 * Build an XML row for XLSX inline strings.
	 *
	 * @param int   $row_num Row number.
	 * @param array $values  Row values.
	 * @return string
	 */
	private static function _broadcast_template_xlsx_row_xml( $row_num, array $values ) {
		$cells = '';
		foreach ( array_values( $values ) as $idx => $value ) {
			$col = self::_broadcast_template_xlsx_col_name( (int) $idx );
			$ref = $col . (int) $row_num;
			$txt = htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			$cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $txt . '</t></is></c>';
		}
		return '<row r="' . (int) $row_num . '">' . $cells . '</row>';
	}

	/**
	 * Convert zero-based index to XLSX column name.
	 *
	 * @param int $index Column index.
	 * @return string
	 */
	private static function _broadcast_template_xlsx_col_name( $index ) {
		$index = (int) $index;
		$col   = '';
		do {
			$col   = chr( 65 + ( $index % 26 ) ) . $col;
			$index = (int) floor( $index / 26 ) - 1;
		} while ( $index >= 0 );
		return $col;
	}

	/**
	 * GET /broadcasts/template — serve sample recipient file in csv/xlsx/xls format.
	 */
	public static function broadcasts_csv_template( WP_REST_Request $req ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — serve sample files for CRM broadcast import UI links.
		$format = strtolower( trim( (string) $req->get_param( 'format' ) ) );
		if ( ! in_array( $format, array( '', 'csv', 'xlsx', 'xls' ), true ) ) {
			$format = 'csv';
		}
		if ( $format === '' ) {
			$format = 'csv';
		}

		$rows = array(
			array( 'Tên', 'Số điện thoại', 'Email' ),
			array( 'Nguyễn Văn A', '0901234567', 'a@example.com' ),
			array( 'Trần Thị B', '0912345678', 'b@example.com' ),
		);

		if ( $format === 'xlsx' ) {
			$binary = self::_broadcast_template_xlsx_binary( $rows );
			if ( $binary !== '' ) {
				header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
				header( 'Content-Disposition: attachment; filename="broadcast-template.xlsx"' );
				echo $binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
			$format = 'csv';
		}

		if ( $format === 'xls' ) {
			$tsv = "\xEF\xBB\xBF" . implode( "\n", array(
				"Tên\tSố điện thoại\tEmail",
				"Nguyễn Văn A\t0901234567\ta@example.com",
				"Trần Thị B\t0912345678\tb@example.com",
			) );
			header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="broadcast-template.xls"' );
			echo $tsv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		$lines = array(
			"Tên;Số điện thoại;Email",
			"Nguyễn Văn A;0901234567;a@example.com",
			"Trần Thị B;0912345678;b@example.com",
		);
		$csv = "\xEF\xBB\xBF" . implode( "\n", $lines );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="broadcast-template.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — ZNS templates proxy (R-CH-NS)
	public static function get_zns_templates_proxy( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$status = sanitize_text_field( (string) ( $req->get_param( 'status' ) ?: 'active' ) );
			if ( class_exists( 'BizCity_CF7_ZNS_Templates' ) ) {
				$templates = BizCity_CF7_ZNS_Templates::get_all( $status );
				return array( 'ok' => true, 'templates' => $templates, 'count' => count( $templates ) );
			}
			// Fallback: internal REST dispatch to channel-gateway (class not yet loaded)
			$inner_req = new WP_REST_Request( 'GET', '/bizcity-channel/v1/cf7/zns-templates' );
			$inner_req->set_param( 'status', $status );
			$inner_res = rest_do_request( $inner_req );
			$data      = $inner_res->get_data();
			if ( is_array( $data ) && isset( $data['ok'] ) ) {
				return $data;
			}
			return array( 'ok' => true, '_degraded' => true, 'templates' => array(), 'count' => 0 );
		} );
	}

	private static function shape_broadcast( array $r ): array {
		// [2026-06-07 Johnny Chu] PHASE-0.43 M3.7 — include action_flags + delay_sec + campaign_id
		$out = array(
			'id'              => (int) $r['id'],
			'title'           => (string) $r['title'],
			'campaign_id'     => isset( $r['campaign_id'] ) ? (int) $r['campaign_id'] : null,
			'inbox_ids'       => isset( $r['inbox_ids_json'] ) && $r['inbox_ids_json'] ? json_decode( $r['inbox_ids_json'], true ) : null,
			'segment_filter'  => isset( $r['segment_filter_json'] ) && $r['segment_filter_json'] ? json_decode( $r['segment_filter_json'], true ) : null,
			'message_template'=> (string) ( isset( $r['message_template'] ) ? $r['message_template'] : '' ),
			'action_flags'    => isset( $r['action_flags_json'] ) && $r['action_flags_json'] ? json_decode( $r['action_flags_json'], true ) : null,
			'delay_sec'       => isset( $r['delay_sec'] ) ? (int) $r['delay_sec'] : 5,
			'status'          => (string) ( isset( $r['status'] ) ? $r['status'] : 'draft' ),
			'scheduled_at'    => isset( $r['scheduled_at'] ) ? $r['scheduled_at'] : null,
			'sent_at'         => isset( $r['sent_at'] ) ? $r['sent_at'] : null,
			'total_count'     => isset( $r['total_count'] ) ? (int) $r['total_count'] : 0,
			'sent_count'      => isset( $r['sent_count'] ) ? (int) $r['sent_count'] : 0,
			'failed_count'    => isset( $r['failed_count'] ) ? (int) $r['failed_count'] : 0,
			'created_by'      => isset( $r['created_by'] ) ? (int) $r['created_by'] : 0,
			'created_at'      => isset( $r['created_at'] ) ? $r['created_at'] : null,
			'updated_at'      => isset( $r['updated_at'] ) ? $r['updated_at'] : null,
		);

		// [2026-07-10 Johnny Chu] PHASE-0.47 — sync linked channel counters/status so list UI reflects real dispatch progress.
		$link = self::broadcasts_channel_link( $r );
		if ( ! empty( $link['id'] ) ) {
			$out['channel_source']       = isset( $link['source'] ) ? (string) $link['source'] : 'bizcity-channel/v1';
			$out['channel_broadcast_id'] = (int) $link['id'];
			if ( class_exists( 'BizCity_Broadcast_Manager' ) ) {
				$cb = BizCity_Broadcast_Manager::get_one( (int) $link['id'] );
				$pg = BizCity_Broadcast_Manager::get_progress( (int) $link['id'] );
				if ( is_array( $cb ) && isset( $cb['status'] ) ) {
					$out['status'] = (string) $cb['status'];
				}
				if ( is_array( $pg ) ) {
					$out['total_count']  = isset( $pg['total'] ) ? (int) $pg['total'] : $out['total_count'];
					$out['sent_count']   = isset( $pg['sent'] ) ? (int) $pg['sent'] : $out['sent_count'];
					$out['failed_count'] = isset( $pg['failed'] ) ? (int) $pg['failed'] : $out['failed_count'];
				}
			}
		}

		return $out;
	}

	/**
	 * Parse channel-linked metadata from CRM broadcast row.
	 *
	 * @param  array $row
	 * @return array
	 */
	private static function broadcasts_channel_link( array $row ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — detect mirrored channel broadcast rows (phone-list fallback path).
		$raw = isset( $row['message_template'] ) ? (string) $row['message_template'] : '';
		if ( $raw === '' ) {
			return array();
		}
		$mt = json_decode( $raw, true );
		if ( ! is_array( $mt ) ) {
			return array();
		}
		$src = isset( $mt['channel_source'] ) ? (string) $mt['channel_source'] : '';
		$cid = isset( $mt['channel_broadcast_id'] ) ? (int) $mt['channel_broadcast_id'] : 0;
		if ( $cid <= 0 || $src !== 'bizcity-channel/v1' ) {
			return array();
		}
		return array(
			'source' => $src,
			'id'     => $cid,
		);
	}

	/**
	 * Dispatch one internal REST request to channel-gateway and normalize failures.
	 *
	 * @param  string     $method
	 * @param  string     $path
	 * @param  array      $params
	 * @param  array|null $body
	 * @return array
	 */
	private static function broadcasts_channel_proxy( $method, $path, array $params = array(), $body = null ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — canonical channel proxy helper for linked broadcast operations.
		$route = '/bizcity-channel/v1/' . ltrim( (string) $path, '/' );
		$req   = new WP_REST_Request( strtoupper( (string) $method ), $route );

		foreach ( $params as $k => $v ) {
			if ( $v === null || $v === '' ) {
				continue;
			}
			$req->set_param( (string) $k, $v );
		}
		if ( is_array( $body ) ) {
			$req->set_body_params( $body );
		}

		$res  = rest_do_request( $req );
		$data = $res instanceof WP_REST_Response ? $res->get_data() : null;
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'channel_proxy_failed' );
		}
		if ( ! empty( $data['success'] ) ) {
			return $data;
		}

		$err = isset( $data['error'] ) ? (string) $data['error'] : 'channel_proxy_failed';
		throw new \RuntimeException( $err );
	}

	/**
	 * Sync local CRM broadcast counters/status from linked channel broadcast state.
	 *
	 * @param int $broadcast_id         CRM broadcast id.
	 * @param int $channel_broadcast_id Channel broadcast id.
	 */
	private static function broadcasts_sync_local_from_channel( $broadcast_id, $channel_broadcast_id ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — keep CRM list counters aligned with linked channel dispatch progress.
		if ( ! class_exists( 'BizCity_Broadcast_Manager' ) ) {
			return;
		}

		$broadcast_id         = (int) $broadcast_id;
		$channel_broadcast_id = (int) $channel_broadcast_id;
		if ( $broadcast_id <= 0 || $channel_broadcast_id <= 0 ) {
			return;
		}

		$cb = BizCity_Broadcast_Manager::get_one( $channel_broadcast_id );
		$pg = BizCity_Broadcast_Manager::get_progress( $channel_broadcast_id );
		if ( ! is_array( $cb ) && ! is_array( $pg ) ) {
			return;
		}

		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
		$upd = array(
			'updated_at' => current_time( 'mysql' ),
		);
		$fmt = array( '%s' );

		if ( is_array( $cb ) && isset( $cb['status'] ) ) {
			$upd['status'] = sanitize_key( (string) $cb['status'] );
			$fmt[]         = '%s';
		}
		if ( is_array( $pg ) ) {
			if ( isset( $pg['total'] ) ) {
				$upd['total_count'] = (int) $pg['total'];
				$fmt[] = '%d';
			}
			if ( isset( $pg['sent'] ) ) {
				$upd['sent_count'] = (int) $pg['sent'];
				$fmt[] = '%d';
			}
			if ( isset( $pg['failed'] ) ) {
				$upd['failed_count'] = (int) $pg['failed'];
				$fmt[] = '%d';
			}
		}

		if ( count( $upd ) > 1 ) {
			$wpdb->update( $tbl, $upd, array( 'id' => $broadcast_id ), $fmt, array( '%d' ) );
		}
	}

	/** POST /conversations/bulk-label */
	public static function conversations_bulk_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body     = (array) $req->get_json_params();
			$conv_ids = isset( $body['conversation_ids'] ) && is_array( $body['conversation_ids'] )
				? array_map( 'absint', $body['conversation_ids'] ) : array();
			$label_ids = isset( $body['label_ids'] ) && is_array( $body['label_ids'] )
				? array_map( 'absint', $body['label_ids'] ) : array();
			if ( empty( $conv_ids ) || empty( $label_ids ) ) {
				throw new \RuntimeException( 'conversation_ids_and_label_ids_required' );
			}
			$by = get_current_user_id();
			$updated = 0;
			foreach ( $conv_ids as $cid ) {
				BizCity_CRM_Repository::set_conversation_labels( $cid, $label_ids, $by );
				$updated++;
			}
			return array( 'updated' => $updated, 'label_ids' => $label_ids );
		} );
	}

	/** POST /contacts/{id}/classify — set lead_score + segment */
	public static function contact_classify( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id   = (int) $req['id'];
			$body = (array) $req->get_json_params();
			$tbl  = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ), ARRAY_A );
			if ( ! $row ) { throw new \RuntimeException( 'contact_not_found' ); }

			$update = array( 'updated_at' => current_time( 'mysql' ) );
			$fmt    = array( '%s' );
			if ( array_key_exists( 'lead_score', $body ) ) {
				$update['lead_score'] = max( 0, min( 100, (int) $body['lead_score'] ) );
				$fmt[] = '%d';
			}
			if ( array_key_exists( 'segment', $body ) ) {
				$seg = strtoupper( sanitize_text_field( (string) $body['segment'] ) );
				if ( ! in_array( $seg, array( 'A', 'B', 'C', 'VIP', '' ), true ) ) { throw new \RuntimeException( 'invalid_segment: must be A|B|C|VIP|empty' ); }
				$update['segment'] = $seg;
				$fmt[] = '%s';
			}
			$wpdb->update( $tbl, $update, array( 'id' => $id ), $fmt, array( '%d' ) );
			$updated = $wpdb->get_row( $wpdb->prepare( "SELECT id,lead_score,segment,updated_at FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return array(
				'id'         => (int) $updated['id'],
				'lead_score' => (int) $updated['lead_score'],
				'segment'    => (string) $updated['segment'],
				'updated_at' => $updated['updated_at'],
			);
		} );
	}

	/* ------- M-CRM.M1.W3 Audit Log ------- */

	public static function get_audit_log( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$entity_type = (string) $req->get_param( 'entity_type' );
			$entity_id   = (int)    $req->get_param( 'entity_id' );
			$limit       = max( 1, min( (int) $req->get_param( 'limit' ), 200 ) );
			$offset      = max( 0, (int) $req->get_param( 'offset' ) );

			if ( ! $entity_type || $entity_id <= 0 ) {
				throw new \RuntimeException( 'invalid_params' );
			}

			if ( ! class_exists( 'BizCity_CRM_Audit_Repository' ) ) {
				throw new \RuntimeException( 'audit_repository_unavailable' );
			}

			return BizCity_CRM_Audit_Repository::find_by_entity( $entity_type, $entity_id, $limit, $offset );
		} );
	}

	// [2026-06-07 Johnny Chu] PHASE-3.5-WC — Admin-chat audit log viewer REST callback
	public static function get_admin_chat_audit( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_AdminChat_Audit' ) ) {
				return array( 'rows' => array(), 'total' => 0, '_note' => 'audit_class_unavailable' );
			}
			$filters = array(
				'limit'  => max( 1, min( (int) $req->get_param( 'limit' ),  200 ) ),
				'offset' => max( 0, (int) $req->get_param( 'offset' ) ),
			);
			foreach ( array( 'user_id', 'guru_id' ) as $int_key ) {
				$v = $req->get_param( $int_key );
				if ( $v ) { $filters[ $int_key ] = (int) $v; }
			}
			foreach ( array( 'chat_id', 'action', 'status' ) as $str_key ) {
				$v = $req->get_param( $str_key );
				if ( $v ) { $filters[ $str_key ] = (string) $v; }
			}
			$rows = BizCity_CRM_AdminChat_Audit::find( $filters );
			return array( 'rows' => $rows, 'total' => count( $rows ) );
		} );
	}

	private static function shape_admin_chat_grant( array $row ): array {
		$user_id = (int) ( $row['user_id'] ?? 0 );
		$user    = $user_id ? get_user_by( 'id', $user_id ) : null;
		$char_id = (int) ( $row['character_id'] ?? 0 );
		$guru_name = '';
		if ( $char_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
			try {
				$kdb = BizCity_Knowledge_Database::instance();
				if ( method_exists( $kdb, 'get_character' ) ) {
					$c = $kdb->get_character( $char_id );
					if ( is_object( $c ) ) { $guru_name = (string) ( $c->name ?? '' ); }
				}
			} catch ( \Throwable $e ) { /* ignore */ }
		}
		$overrides = null;
		if ( ! empty( $row['tool_overrides_json'] ) ) {
			$decoded = json_decode( (string) $row['tool_overrides_json'], true );
			if ( is_array( $decoded ) ) { $overrides = $decoded; }
		}
		return array(
			'id'                  => (int) $row['id'],
			'user_id'             => $user_id,
			'user_login'          => $user ? $user->user_login : '',
			'user_name'           => $user ? ( $user->display_name ?: $user->user_login ) : '',
			'user_email'          => $user ? $user->user_email : '',
			'character_id'        => $char_id,
			'character_name'      => $guru_name,
			'platform'            => (string) ( $row['platform'] ?? '' ),
			'chat_id'             => (string) ( $row['chat_id'] ?? '' ),
			'channel_binding_id'  => isset( $row['channel_binding_id'] ) ? (int) $row['channel_binding_id'] : null,
			'status'              => (string) ( $row['status'] ?? '' ),
			'allow_producer'      => (int) ( $row['allow_producer'] ?? 0 ),
			'allow_retriever'     => (int) ( $row['allow_retriever'] ?? 0 ),
			'allow_distributor'   => (int) ( $row['allow_distributor'] ?? 0 ),
			'quota_per_day'       => (int) ( $row['quota_per_day'] ?? 0 ),
			'quota_used_today'    => (int) ( $row['quota_used_today'] ?? 0 ),
			'quota_reset_at'      => $row['quota_reset_at'] ?? null,
			'tool_overrides'      => $overrides,
			'granted_at'          => $row['granted_at'] ?? null,
			'granted_by_user_id'  => (int) ( $row['granted_by_user_id'] ?? 0 ),
			'created_at'          => $row['created_at'] ?? null,
			'updated_at'          => $row['updated_at'] ?? null,
		);
	}

	public static function get_inboxes( WP_REST_Request $req ) {
		return self::wrap( static function () {
			$rows = BizCity_CRM_Repository::list_inboxes();
			return array_map( array( __CLASS__, 'shape_inbox' ), $rows );
		} );
	}

	public static function get_conversations( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array(
				'inbox_id'    => (int) $req->get_param( 'inbox_id' ),
				'status'      => (string) $req->get_param( 'status' ),
				'priority'    => $req->get_param( 'priority' ),
				'assignee_id' => (int) $req->get_param( 'assignee_id' ),
				'label_id'    => (int) $req->get_param( 'label_id' ),
				'q'           => (string) $req->get_param( 'q' ),
				'limit'       => (int) ( $req->get_param( 'limit' ) ?: 50 ),
				'before_id'   => (int) $req->get_param( 'before_id' ),
			);
			$snoozed_raw = $req->get_param( 'snoozed' );
			if ( $snoozed_raw !== null && $snoozed_raw !== '' ) {
				$args['snoozed'] = $snoozed_raw;
			}
			$rows = BizCity_CRM_Repository::list_conversations( $args );
			return array_map( array( __CLASS__, 'shape_conversation' ), $rows );
		} );
	}

	public static function get_conversation( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			if ( ! BizCity_CRM_Repository::get_conversation( $id ) ) {
				throw new \RuntimeException( 'conversation_not_found' );
			}
			$rows = BizCity_CRM_Repository::list_conversations( array( 'id' => $id, 'limit' => 1 ) );
			if ( empty( $rows ) ) {
				throw new \RuntimeException( 'conversation_not_found' );
			}
			return self::shape_conversation( $rows[0] );
		} );
	}

	public static function get_messages( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id       = (int) $req['id'];
			$after_id = (int) $req->get_param( 'after_id' );
			$limit    = (int) ( $req->get_param( 'limit' ) ?: 100 );
			$rows     = BizCity_CRM_Repository::list_messages( $id, $limit, $after_id );
			return array_map( array( __CLASS__, 'shape_message' ), $rows );
		} );
	}

	/* ------- shapers ------- */

	public static function shape_inbox( array $r ): array {
		return array(
			'id'                  => (int) $r['id'],
			'name'                => (string) $r['name'],
			'channel_type'        => (string) $r['channel_type'],
			'channel_ref_id'      => (string) $r['channel_ref_id'],
			'is_active'           => (int) $r['is_active'] === 1,
			'default_notebook_id' => isset( $r['default_notebook_id'] ) ? (int) $r['default_notebook_id'] : null,
			'created_at'          => (string) $r['created_at'],
			'settings'            => $r['settings_json'] ? json_decode( $r['settings_json'], true ) : null,
		);
	}

	public static function shape_conversation( array $r ): array {
		$now           = time();
		$snoozed_until = isset( $r['snoozed_until'] ) && $r['snoozed_until'] !== null ? (int) $r['snoozed_until'] : null;
		$labels_raw    = (string) ( $r['cached_label_list'] ?? '' );
		$labels        = $labels_raw !== '' ? array_values( array_filter( array_map( 'trim', explode( ',', $labels_raw ) ) ) ) : array();
		return array(
			'id'                  => (int) $r['id'],
			'inbox_id'            => (int) $r['inbox_id'],
			'contact_inbox_id'    => (int) $r['contact_inbox_id'],
			'status'              => (string) $r['status'],
			'priority'            => (int) ( $r['priority'] ?? 0 ),
			'snoozed_until'       => $snoozed_until,
			'is_snoozed'          => $snoozed_until !== null && $snoozed_until > $now,
			'waiting_since'       => isset( $r['waiting_since'] ) && $r['waiting_since'] !== null ? (int) $r['waiting_since'] : null,
			'first_reply_at'      => isset( $r['first_reply_at'] ) && $r['first_reply_at'] !== null ? (int) $r['first_reply_at'] : null,
			'labels'              => $labels,
			'sla_policy_id'       => isset( $r['sla_policy_id'] ) && $r['sla_policy_id'] !== null ? (int) $r['sla_policy_id'] : null,
			'team_id'             => isset( $r['team_id'] ) && $r['team_id'] !== null ? (int) $r['team_id'] : null,
			'unread_count'        => (int) ( $r['unread_count'] ?? 0 ),
			'notebook_id'         => isset( $r['notebook_id'] ) && $r['notebook_id'] !== null ? (int) $r['notebook_id'] : null,
			'last_activity_at'    => $r['last_activity_at'] ?? null,
			'last_message'        => isset( $r['last_message_content'] ) ? array(
				'content'      => (string) ( $r['last_message_content'] ?? '' ),
				'message_type' => (string) ( $r['last_message_type']    ?? '' ),
				'sender_type'  => (string) ( $r['last_sender_type']     ?? '' ),
				'created_at'   => $r['last_message_at'] ?? null,
			) : null,
			'contact'             => array(
				'id'         => (int) ( $r['contact_id']     ?? 0 ),
				'source_id'  => (string) ( $r['source_id']    ?? '' ),
				'name'       => (string) ( $r['contact_name'] ?? '' ),
				'avatar_url' => $r['contact_avatar'] ?? null,
			),
		);
	}

	public static function shape_message( array $r ): array {
		$ai = null;
		if ( ! empty( $r['ai_metadata_json'] ) ) {
			$decoded = json_decode( (string) $r['ai_metadata_json'], true );
			if ( is_array( $decoded ) ) { $ai = $decoded; }
		}
		return array(
			'id'                 => (int) $r['id'],
			'conversation_id'    => (int) $r['conversation_id'],
			'inbox_id'           => (int) $r['inbox_id'],
			'content'            => (string) $r['content'],
			'content_type'       => (string) $r['content_type'],
			'message_type'       => (string) $r['message_type'],
			'sender_type'        => (string) $r['sender_type'],
			'sender_id'          => $r['sender_id'] !== null ? (int) $r['sender_id'] : null,
			'status'             => (string) $r['status'],
			'external_source_id' => $r['external_source_id'] ?? null,
			'event_uuid'         => $r['event_uuid'] ?? null,
			'responder_kind'     => $r['responder_kind']    ?? null,
			'responder_user_id'  => isset( $r['responder_user_id'] ) ? (int) $r['responder_user_id'] : null,
			'character_id'       => isset( $r['character_id'] )      ? (int) $r['character_id']      : null,
			'character_edit_url' => isset( $r['character_id'] ) && (int) $r['character_id'] > 0
				? admin_url( 'admin.php?page=bizcity-knowledge-character-edit&id=' . (int) $r['character_id'] )
				: null,
			'responder_user_edit_url' => isset( $r['responder_user_id'] ) && (int) $r['responder_user_id'] > 0
				? admin_url( 'user-edit.php?user_id=' . (int) $r['responder_user_id'] )
				: null,
			'ai_metadata'        => $ai,
			'attachments'        => array_map( static function ( $a ) {
				return array(
					'id'        => (int) $a['id'],
					'file_type' => (string) $a['file_type'],
					'data_url'  => (string) $a['data_url'],
					'thumb_url' => $a['thumb_url'] ?? null,
				);
			}, $r['attachments'] ?? array() ),
			'created_at'         => (string) $r['created_at'],
		);
	}

	/* ------- envelope ------- */

	/**
	 * Convert a date string (YYYY-MM-DD) or numeric timestamp string to a
	 * Unix timestamp using the WordPress site timezone. Accepts both formats so
	 * the reports endpoints can accept either ISO date strings from the frontend
	 * or Unix timestamps from legacy callers.
	 */
	private static function date_string_to_ts( string $v ): int {
		try {
			return (int) ( new DateTimeImmutable( $v . ' 00:00:00', wp_timezone() ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return (int) strtotime( $v );
		}
	}

	private static function wrap( callable $fn ) {
		try {
			$data = $fn();
			return new WP_REST_Response( array(
				'ok'   => true,
				'data' => $data,
				'ts'   => (int) round( microtime( true ) * 1000 ),
			), 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array(
				'ok'    => false,
				'error' => array(
					'code'    => 'crm_exception',
					'message' => $e->getMessage(),
				),
				'ts'    => (int) round( microtime( true ) * 1000 ),
			), 500 );
		}
	}

	/* ------- write handlers (PHASE 0.34 FE-M4/M5) ------- */

	/**
	 * POST /conversations/{id}/messages — manual outbound.
	 * Body: { content, content_type?, responder_kind?='manual', character_id? }
	 *
	 * Pipeline:
	 *   1. Resolve chat_id+platform from conversation.
	 *   2. Push Stamper context (kind=manual, user_id=current).
	 *   3. Insert CRM message row immediately (UI feedback).
	 *   4. Dispatch via BizCity_Gateway_Sender::send() → Stamper hook also writes _bizcity_channel_messages.
	 */
	public static function post_message( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$body    = $req->get_json_params() ?: array();
			$content = (string) ( $body['content'] ?? '' );
			$ctype   = (string) ( $body['content_type'] ?? 'text' );
			$kind    = (string) ( $body['responder_kind'] ?? 'manual' );
			$cid     = isset( $body['character_id'] ) ? (int) $body['character_id'] : 0;

			if ( $content === '' ) {
				throw new \RuntimeException( 'content_required' );
			}
			if ( ! in_array( $kind, array( 'manual', 'hybrid', 'auto', 'system' ), true ) ) {
				$kind = 'manual';
			}

			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }

			$resolved = BizCity_CRM_Repository::resolve_chat_id( $conv_id );
			if ( ! $resolved ) { throw new \RuntimeException( 'chat_id_unresolved' ); }

			$user_id = (int) get_current_user_id();
			if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::push( array(
					'kind'         => $kind,
					'character_id' => $cid ?: null,
					'user_id'      => $user_id,
					'source'       => 'crm-rest',
				) );
			}

			$msg_id = BizCity_CRM_Repository::insert_message( array(
				'conversation_id'   => $conv_id,
				'inbox_id'          => (int) $conv['inbox_id'],
				'content'           => $content,
				'content_type'      => $ctype,
				'message_type'      => 'outgoing',
				'sender_type'       => $kind === 'manual' ? 'agent' : ( $kind === 'auto' ? 'bot' : $kind ),
				'sender_id'         => $user_id ?: null,
				'status'            => 'pending',
				'responder_kind'    => $kind,
				'responder_user_id' => $user_id ?: null,
				'character_id'      => $cid ?: null,
			) );

			$result = array( 'sent' => false, 'error' => 'no-sender', 'platform' => $resolved['platform'] );

			// Prefer the CRM channel adapter when one is registered for this inbox's channel
			// (`facebook`, `zalo`, …). The CRM adapter knows per-page/per-OA tokens, branches
			// for comment-replies, and never falls through Channel Gateway's UNKNOWN bucket.
			$inbox_row     = BizCity_CRM_Repository::get_inbox( (int) $conv['inbox_id'] );
			$adapter_code  = $inbox_row ? (string) $inbox_row['channel_type'] : '';
			$crm_adapter   = $adapter_code ? BizCity_CRM_Channel_Registry::get( $adapter_code ) : null;
			if ( $crm_adapter ) {
				// Tap to detect whether the adapter dispatched through a path
				// that already emits `bizcity_channel_outbound_logged` itself
				// (e.g. Zalo Bot via BizCity_Gateway_Sender). When it does, we
				// MUST NOT mirror again \u2014 otherwise the Responder_Stamper hook
				// would write a second row to wp_bizcity_channel_messages.
				$gw_emitted = 0;
				$gw_tap     = static function () use ( &$gw_emitted ) { $gw_emitted++; };
				if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
					BizCity_CRM_Facebook_Ingestor::set_crm_outbound_in_flight( true );
				}
				add_action( 'bizcity_channel_outbound_logged', $gw_tap, 1 );
				try {
					$adapter_res = $crm_adapter->send(
						$conv,
						array(
							'content'      => $content,
							'content_type' => $ctype,
						)
					);
				} finally {
					remove_action( 'bizcity_channel_outbound_logged', $gw_tap, 1 );
					if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
						BizCity_CRM_Facebook_Ingestor::set_crm_outbound_in_flight( false );
					}
				}
				$result = array(
					'sent'     => ! empty( $adapter_res['success'] ),
					'error'    => (string) ( $adapter_res['error'] ?? '' ),
					'platform' => $resolved['platform'],
					'mid'      => (string) ( $adapter_res['external_source_id'] ?? '' ),
				);
				// Mirror to Channel Gateway ledger only when the adapter did
				// not already emit (e.g. Facebook Bridge sends via Graph API
				// without firing the gateway hook).
				if ( $gw_emitted === 0 ) {
					do_action( 'bizcity_channel_outbound_logged', array(
						'chat_id'  => $resolved['chat_id'],
						'platform' => $resolved['platform'],
						'message'  => $content,
						'type'     => $ctype,
						'extra'    => array( 'mid' => $result['mid'], 'source' => 'crm-adapter' ),
						'sent'     => (bool) $result['sent'],
						'error'    => (string) $result['error'],
					) );
				}
			} elseif ( class_exists( 'BizCity_Gateway_Sender' ) ) {
				$result = BizCity_Gateway_Sender::instance()->send( $resolved['chat_id'], $content );
			}

			if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::pop();
			}

			// Update CRM message status from dispatch result.
			if ( $msg_id ) {
				global $wpdb;
				$wpdb->update(
					BizCity_CRM_DB_Installer_V2::tbl_messages(),
					array( 'status' => $result['sent'] ? 'sent' : 'failed' ),
					array( 'id' => $msg_id )
				);
			}

			$row = $msg_id ? BizCity_CRM_Repository::get_message( $msg_id ) : null;
			if ( $row ) { $row['attachments'] = array(); }
			return array(
				'message'  => $row ? self::shape_message( $row ) : null,
				'dispatch' => array(
					'sent'     => (bool) $result['sent'],
					'platform' => (string) $resolved['platform'],
					'chat_id'  => (string) $resolved['chat_id'],
					'error'    => (string) $result['error'],
				),
			);
		} );
	}

	/**
	 * POST /conversations/{id}/notes — internal private note (no outbound dispatch).
	 * Body: { content }
	 */
	public static function post_note( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$body    = $req->get_json_params() ?: array();
			$content = (string) ( $body['content'] ?? '' );
			if ( $content === '' ) {
				throw new \RuntimeException( 'content_required' );
			}
			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }

			$user_id = (int) get_current_user_id();
			$msg_id  = BizCity_CRM_Repository::insert_message( array(
				'conversation_id'   => $conv_id,
				'inbox_id'          => (int) $conv['inbox_id'],
				'content'           => $content,
				'content_type'      => 'text',
				'message_type'      => 'private_note',
				'sender_type'       => 'agent',
				'sender_id'         => $user_id ?: null,
				'status'            => 'note',
				'responder_kind'    => 'manual',
				'responder_user_id' => $user_id ?: null,
			) );
			$row = $msg_id ? BizCity_CRM_Repository::get_message( $msg_id ) : null;
			if ( $row ) { $row['attachments'] = array(); }
			return $row ? self::shape_message( $row ) : null;
		} );
	}

	/**
	 * POST /conversations/{id}/resolve — flip status to resolved.
	 */
	public static function post_resolve( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			$ok = BizCity_CRM_Repository::set_conversation_status( $conv_id, 'resolved', (int) get_current_user_id() );
			return array( 'resolved' => (bool) $ok );
		} );
	}

	/**
	 * POST /conversations/{id}/snooze — set snoozed_until.
	 * Body: { duration_seconds?: int, until?: ISO-8601 string }. duration_seconds wins.
	 */
	public static function post_snooze( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }

			$dur   = (int) $req->get_param( 'duration_seconds' );
			$until = (string) $req->get_param( 'until' );
			$ts    = 0;
			if ( $dur > 0 ) {
				$ts = time() + $dur;
			} elseif ( $until !== '' ) {
				$ts = (int) strtotime( $until );
			}
			if ( $ts <= time() ) {
				throw new \RuntimeException( 'snooze_until_must_be_in_future' );
			}
			$ok = BizCity_CRM_Repository::set_snooze( $conv_id, $ts, (int) get_current_user_id() );
			return array(
				'snoozed'        => (bool) $ok,
				'snoozed_until'  => $ts,
				'snoozed_until_iso' => gmdate( 'c', $ts ),
			);
		} );
	}

	/**
	 * POST /conversations/{id}/unsnooze — clear snoozed_until.
	 */
	public static function post_unsnooze( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			$ok = BizCity_CRM_Repository::set_snooze( $conv_id, 0, (int) get_current_user_id() );
			return array( 'unsnoozed' => (bool) $ok );
		} );
	}

	/**
	 * POST /conversations/{id}/convert-to-lead — materialise a CRM Lead from
	 * the conversation's contact (M-Bridge.W2).
	 *
	 * Prefill order: explicit body fields → contact attributes → fallback ''.
	 * Idempotent: if a lead already exists for this contact_id, returns the
	 * existing lead with `existing=true` instead of erroring (FE then prompts
	 * user whether to view existing or create another).
	 *
	 * Body: { first_name?, last_name?, email?, phone?, company?, source?, notes? }
	 *
	 * @return array|WP_Error  lead row (shape_crm_lead) + `{ existing:bool }`
	 */
	public static function post_convert_conv_to_lead( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { return new WP_Error( 'not_found', 'Conversation not found', array( 'status' => 404 ) ); }

			$ci_tbl     = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$contact_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT contact_id FROM `{$ci_tbl}` WHERE id = %d",
				(int) ( $conv['contact_inbox_id'] ?? 0 )
			) );
			$tbl_leads  = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();

			// Idempotency — surface existing open/working lead for the same contact.
			if ( $contact_id > 0 ) {
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM `{$tbl_leads}` WHERE contact_id = %d AND deleted_at IS NULL AND status NOT IN ('lost','converted') ORDER BY id DESC LIMIT 1",
					$contact_id
				), ARRAY_A );
				if ( $existing ) {
					$out = self::shape_crm_lead( $existing );
					$out['existing'] = true;
					return $out;
				}
			}

			// Pull canonical contact row (for name/email/phone prefill).
			$contact_row = $contact_id > 0
				? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_contacts() . "` WHERE id = %d", $contact_id ), ARRAY_A )
				: null;

			$body   = self::extract_json_body( $req );
			$cname  = (string) ( $contact_row['name'] ?? '' );
			$cparts = preg_split( '/\s+/', trim( $cname ) ) ?: array();
			$cfirst = (string) array_shift( $cparts );
			$clast  = $cparts ? implode( ' ', $cparts ) : '';

			$first = trim( (string) ( $body['first_name'] ?? $cfirst ) );
			$last  = trim( (string) ( $body['last_name']  ?? $clast  ) );
			$email = (string) ( $body['email'] ?? $contact_row['email'] ?? '' );
			$phone = (string) ( $body['phone'] ?? $contact_row['phone'] ?? '' );
			$company = (string) ( $body['company'] ?? '' );
			$source  = (string) ( $body['source']  ?? ( 'inbox:conv#' . $conv_id ) );
			$notes   = (string) ( $body['notes']   ?? '' );

			if ( $first === '' && $last === '' && $company === '' && $email === '' && $phone === '' ) {
				return new WP_Error( 'invalid_lead', 'Cần ít nhất tên / công ty / email / SĐT', array( 'status' => 422 ) );
			}

			$now = current_time( 'mysql' );
			$wpdb->insert( $tbl_leads, array(
				'first_name'  => $first,
				'last_name'   => $last,
				'email'       => $email !== '' ? $email : null,
				'phone'       => $phone !== '' ? $phone : null,
				'company'     => $company !== '' ? $company : null,
				'source'      => $source,
				'status'      => 'new',
				'owner_id'    => get_current_user_id() ?: null,
				'contact_id'  => $contact_id ?: null,
				'notes'       => $notes !== '' ? $notes : null,
				'custom_json' => wp_json_encode( array(
					'origin'          => 'conv_convert',
					'conversation_id' => $conv_id,
					'inbox_id'        => (int) ( $conv['inbox_id'] ?? 0 ),
				) ),
				'created_by'  => get_current_user_id() ?: null,
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
			$lead_id = (int) $wpdb->insert_id;
			if ( ! $lead_id ) { return new WP_Error( 'insert_failed', 'Could not create lead', array( 'status' => 500 ) ); }

			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl_leads}` WHERE id=%d", $lead_id ), ARRAY_A );

			// Fire downstream events — email automation + Intent Monitor trace.
			$display_name = trim( $first . ' ' . $last );
			do_action( 'bizcity_crm_lead_created', $lead_id, array(
				'id'     => $lead_id,
				'name'   => $display_name !== '' ? $display_name : ( $email !== '' ? $email : $phone ),
				'email'  => $email,
				'phone'  => $phone,
				'source' => $source,
			) );
			if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
				BizCity_CRM_Event_Emitter::emit( 'crm_lead_converted_from_conv', array(
					'lead_id'         => $lead_id,
					'conversation_id' => $conv_id,
					'contact_id'      => $contact_id,
					'inbox_id'        => (int) ( $conv['inbox_id'] ?? 0 ),
				) );
			}

			$out = self::shape_crm_lead( $row );
			$out['existing'] = false;
			return $out;
		} );
	}

	/**
	 * POST /conversations/{id}/ai-reply — generate a notebook-grounded AI
	 * reply for the latest inbound message and dispatch via the channel
	 * adapter. Returns the inserted message + full thinking trace.
	 *
	 * Body: { prompt?, dispatch?=true, notebook_id?, character_id? }
	 */
	public static function post_ai_reply( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_AI_Replier' ) ) {
				throw new \RuntimeException( 'ai_replier_unavailable' );
			}
			$conv_id = (int) $req['id'];
			$body    = $req->get_json_params() ?: array();
			$opts    = array();
			if ( isset( $body['prompt'] ) )       { $opts['prompt']       = (string) $body['prompt']; }
			if ( isset( $body['dispatch'] ) )     { $opts['dispatch']     = (bool)   $body['dispatch']; }
			if ( isset( $body['notebook_id'] ) )  { $opts['notebook_id']  = (int)    $body['notebook_id']; }
			if ( isset( $body['character_id'] ) ) { $opts['character_id'] = (int)    $body['character_id']; }

			$result = BizCity_CRM_AI_Replier::reply( $conv_id, $opts );

			$row = $result['message_id']
				? BizCity_CRM_Repository::get_message( (int) $result['message_id'] )
				: null;
			if ( $row ) { $row['attachments'] = array(); }

			return array(
				'message'  => $row ? self::shape_message( $row ) : null,
				'trace'    => array(
					'trace_uuid'   => $result['trace_uuid'],
					'notebook_id'  => $result['notebook_id'],
					'character_id' => $result['character_id'],
					'latency_ms'   => $result['latency_ms'],
					'steps'        => $result['steps'],
					'sources'      => $result['sources'],
				),
				'dispatch' => $result['dispatch'],
			);
		} );
	}

	/* ------- contact drawer (PHASE 0.34 FE-M6) ------- */

	/**
	 * GET /contacts/{id}
	 *
	 * Aggregated payload for the right-side ContactDrawer:
	 *   { contact, inboxes:[...], conversations:[recent×10], gurus:[...] }
	 */
	public static function get_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			$contact = BizCity_CRM_Repository::get_contact( $id );
			if ( ! $contact ) { throw new \RuntimeException( 'contact_not_found' ); }

			$inboxes = BizCity_CRM_Repository::list_inboxes_for_contact( $id );
			$convs   = BizCity_CRM_Repository::list_conversations_for_contact( $id, 10 );
			$gurus   = BizCity_CRM_Repository::list_gurus_for_contact( $id );

			return array(
				'contact'       => self::shape_contact( $contact ),
				'inboxes'       => array_map( array( __CLASS__, 'shape_inbox' ), $inboxes ),
				'conversations' => array_map( array( __CLASS__, 'shape_conversation' ), $convs ),
				'gurus'         => $gurus,
			);
		} );
	}

	public static function shape_contact( array $r ): array {
		$attrs = array();
		if ( ! empty( $r['additional_attributes'] ) ) {
			$decoded = json_decode( (string) $r['additional_attributes'], true );
			if ( is_array( $decoded ) ) { $attrs = $decoded; }
		}
		return array(
			'id'           => (int) $r['id'],
			'name'         => (string) ( $r['name'] ?? '' ),
			'email'        => $r['email'] ?? null,
			'phone'        => $r['phone'] ?? null,
			'avatar_url'   => $r['avatar_url'] ?? null,
			'wp_user_id'   => isset( $r['wp_user_id'] ) ? (int) $r['wp_user_id'] : null,
			'attributes'   => $attrs,
			'created_at'   => (string) ( $r['created_at'] ?? '' ),
			'updated_at'   => (string) ( $r['updated_at'] ?? '' ),
		);
	}

	/* ───── Persona infra (PHASE-0.35-GURU-SERVICES) ───── */

	public static function get_last_skip( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id   = (int) $req['id'];
			$skip = class_exists( 'BizCity_CRM_AI_Autoreply_Listener' )
				? BizCity_CRM_AI_Autoreply_Listener::get_recent_skip( $id )
				: null;
			return array( 'conversation_id' => $id, 'skip' => $skip );
		} );
	}

	/**
	 * Mock-run a persona prompt: build prefix + retrieve passages + ask LLM
	 * but DO NOT insert a CRM message or dispatch.
	 */
	public static function post_sandbox_test_persona( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$character_id = (int) $req->get_param( 'character_id' );
			$message      = trim( (string) $req->get_param( 'message' ) );
			$channel_type = sanitize_key( (string) $req->get_param( 'channel_type' ) ) ?: 'facebook';
			$nb_id        = (int) $req->get_param( 'notebook_id' );

			if ( $character_id <= 0 || $message === '' ) {
				throw new \RuntimeException( 'character_id_and_message_required' );
			}
			if ( ! class_exists( 'BizCity_CRM_Service_Templates' ) ) {
				throw new \RuntimeException( 'service_templates_unavailable' );
			}

			$svc = BizCity_CRM_Service_Templates::resolve_for_character( $character_id, $channel_type );
			$persona_prefix = BizCity_CRM_Service_Templates::build_persona_prefix( (array) $svc['template'] );

			// Resolve a notebook: explicit → guru-attached.
			if ( $nb_id <= 0 && class_exists( 'BizCity_KG_Database' ) ) {
				global $wpdb;
				$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE character_id=%d ORDER BY id DESC LIMIT 1", $character_id ) );
				if ( $row ) { $nb_id = (int) $row->id; }
			}

			$passages_n = 0; $kg_answer = ''; $reply = ''; $provider = ''; $model = ''; $rag_meta = array();
			if ( $nb_id > 0 && class_exists( 'BizCity_KG_Retriever' ) ) {
				$rag = BizCity_KG_Retriever::instance()->ask( $nb_id, $message, array( 'answer' => true ) );
				$passages_n = is_array( $rag['passages'] ?? null ) ? count( $rag['passages'] ) : 0;
				$kg_answer  = (string) ( $rag['answer'] ?? '' );
				$rag_meta   = array(
					'mode'     => $rag['mode']    ?? null,
					'passages' => $passages_n,
				);

				// Build the same augmented prompt AI Replier uses.
				$ctx = array( '【Tài liệu nội bộ ưu tiên (notebook#' . $nb_id . ')】' );
				$cap = 0;
				foreach ( (array) ( $rag['passages'] ?? array() ) as $p ) {
					$snip = trim( (string) ( $p['content'] ?? '' ) );
					if ( $snip === '' ) { continue; }
					if ( mb_strlen( $snip ) > 800 ) { $snip = mb_substr( $snip, 0, 800 ) . '…'; }
					$ctx[] = sprintf( '[src:S%dp%d] %s', (int) ( $p['source_id'] ?? 0 ), (int) ( $p['id'] ?? 0 ), $snip );
					$cap  += mb_strlen( $snip );
					if ( $cap > 4000 ) { break; }
				}
				$ctx[] = '【Hết tài liệu】';
				$ctx[] = '';
				$ctx[] = 'Câu hỏi của khách: ' . $message;
				$prompt_aug = $persona_prefix . implode( "\n", $ctx );
			} else {
				$prompt_aug = $persona_prefix . $message;
			}

			if ( class_exists( 'BizCity_Chat_Gateway' ) ) {
				try {
					$gw = BizCity_Chat_Gateway::instance()->get_ai_response(
						$character_id, $prompt_aug, array(),
						'crm_sandbox_' . wp_generate_uuid4(),
						'[]',
						(int) get_current_user_id(),
						'crm_sandbox'
					);
					if ( is_array( $gw ) && ! empty( $gw['message'] ) ) {
						$reply    = (string) $gw['message'];
						$provider = (string) ( $gw['provider'] ?? '' );
						$model    = (string) ( $gw['model']    ?? '' );
					}
				} catch ( \Throwable $e ) {
					$reply = '[gateway_error] ' . $e->getMessage();
				}
			}
			if ( $reply === '' ) { $reply = $kg_answer; }

			// Apply post-LLM trim same as production.
			$max_chars = (int) ( $svc['template']['max_chars_target'] ?? 0 );
			$trimmed   = false;
			if ( $max_chars > 0 && mb_strlen( $reply ) > $max_chars * 1.4 ) {
				$reply = mb_substr( $reply, 0, (int) ( $max_chars * 1.4 ) ) . '…';
				$trimmed = true;
			}

			return array(
				'character_id'   => $character_id,
				'channel_type'   => $channel_type,
				'notebook_id'    => $nb_id,
				'service'        => $svc,
				'persona_prefix' => $persona_prefix,
				'prompt_chars'   => mb_strlen( $prompt_aug ),
				'rag'            => $rag_meta,
				'reply'          => $reply,
				'reply_chars'    => mb_strlen( $reply ),
				'trimmed'        => $trimmed,
				'provider'       => $provider,
				'model'          => $model,
			);
		} );
	}

	/**
	 * Aggregate AI auto-reply count grouped by service template + day.
	 * Reads ai_metadata_json via JSON_EXTRACT (MySQL 5.7+ / MariaDB 10.2+).
	 */
	public static function get_persona_analytics( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$days = max( 1, min( 90, (int) $req->get_param( 'days' ) ) );
			$tbl  = BizCity_CRM_DB_Installer_V2::tbl_messages();

			$sql = $wpdb->prepare(
				"SELECT
					DATE(created_at) AS day,
					COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ai_metadata_json, '$.steps[0].detail.service_template.slug')), '—') AS template_slug,
					COUNT(*) AS reply_count,
					AVG(JSON_EXTRACT(ai_metadata_json, '$.latency_ms')) AS avg_latency_ms,
					AVG(CHAR_LENGTH(content)) AS avg_reply_chars
				FROM {$tbl}
				WHERE message_type='outgoing'
				  AND responder_kind='auto'
				  AND ai_metadata_json IS NOT NULL
				  AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY day, template_slug
				ORDER BY day DESC, reply_count DESC",
				$days
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			$out  = array();
			foreach ( (array) $rows as $r ) {
				$out[] = array(
					'day'             => (string) $r['day'],
					'template_slug'   => (string) $r['template_slug'],
					'reply_count'     => (int)    $r['reply_count'],
					'avg_latency_ms'  => (int)    round( (float) $r['avg_latency_ms'] ),
					'avg_reply_chars' => (int)    round( (float) $r['avg_reply_chars'] ),
				);
			}

			// Totals by template across the window.
			$by_tpl = array();
			foreach ( $out as $r ) {
				$k = $r['template_slug'];
				if ( ! isset( $by_tpl[ $k ] ) ) {
					$by_tpl[ $k ] = array( 'template_slug' => $k, 'reply_count' => 0, 'avg_latency_ms' => 0, 'samples' => 0 );
				}
				$by_tpl[ $k ]['reply_count']    += $r['reply_count'];
				$by_tpl[ $k ]['avg_latency_ms'] += $r['avg_latency_ms'] * $r['reply_count'];
				$by_tpl[ $k ]['samples']        += $r['reply_count'];
			}
			foreach ( $by_tpl as &$t ) {
				$t['avg_latency_ms'] = $t['samples'] > 0 ? (int) round( $t['avg_latency_ms'] / $t['samples'] ) : 0;
				unset( $t['samples'] );
			}
			unset( $t );

			return array(
				'days'   => $days,
				'by_day' => $out,
				'totals' => array_values( $by_tpl ),
			);
		} );
	}

	/* ───── PHASE-0.36 Order Adapter handlers ───── */

	private static function order_adapter(): BizCity_CRM_Order_Adapter_Interface {
		if ( ! class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ) {
			throw new \RuntimeException( 'order_adapter_registry_missing' );
		}
		$a = BizCity_CRM_Order_Adapter_Registry::default_adapter();
		if ( ! $a ) { throw new \RuntimeException( 'no_order_adapter_available' ); }
		return $a;
	}

	public static function get_order_payment_options( WP_REST_Request $req ) {
		return self::wrap( static function () {
			$a = self::order_adapter();
			return array(
				'adapter' => array( 'slug' => $a->slug(), 'label' => $a->label() ),
				'options' => $a->get_payment_options(),
			);
		} );
	}

	public static function get_order_products( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$a = self::order_adapter();
			$q = (string) ( $req->get_param( 'q' ) ?: '' );
			$l = (int) ( $req->get_param( 'limit' ) ?: 20 );
			return array( 'products' => $a->search_products( $q, $l ) );
		} );
	}

	public static function get_conversation_orders( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$a       = self::order_adapter();
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			$contact = (int) $conv['contact_id'] > 0 ? BizCity_CRM_Repository::get_contact( (int) $conv['contact_id'] ) : null;
			$shaped  = $contact ? self::shape_contact( $contact ) : array();
			// Inject conv_id so adapter can also pull CRM-linked orders
			// (orders born inside this conv even when contact has no email/user).
			$shaped['conversation_id'] = $conv_id;
			if ( $contact && empty( $shaped['id'] ) ) { $shaped['id'] = (int) $conv['contact_id']; }
			return array( 'orders' => $a->list_orders_for_contact( $shaped, 10 ) );
		} );
	}

	public static function post_conversation_order( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$a       = self::order_adapter();
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			$contact_row = (int) $conv['contact_id'] > 0 ? BizCity_CRM_Repository::get_contact( (int) $conv['contact_id'] ) : null;
			$contact     = $contact_row ? self::shape_contact( $contact_row ) : array();

			$payload = array(
				'conversation_id' => $conv_id,
				'contact'         => $contact,
				'items'           => (array) $req->get_param( 'items' ),
				'custom_amount'   => (float) $req->get_param( 'custom_amount' ),
				'payment_option'  => (string) ( $req->get_param( 'payment_option' ) ?: '' ),
				'note'            => (string) ( $req->get_param( 'note' ) ?: '' ),
			);
			$res = $a->create_order( $payload );

			// Audit note in conversation timeline.
			if ( class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'insert_message' ) ) {
				BizCity_CRM_Repository::insert_message( array(
					'conversation_id'  => $conv_id,
					'content'          => sprintf(
						'📦 Đã tạo đơn #%d · %s %s · checkout: %s',
						(int) $res['order_id'],
						number_format( (float) $res['total'], 0, ',', '.' ),
						(string) $res['currency'],
						(string) $res['checkout_url']
					),
					'message_type'     => 'outgoing',
					'sender_type'      => 'system',
					'responder_kind'   => 'system',
					'ai_metadata_json' => wp_json_encode( array(
						'kind'    => 'crm_order_created',
						'adapter' => $a->slug(),
						'order'   => $res,
					) ),
				) );
			}

			return $res;
		} );
	}

	/* ── PHASE-0.36b — order preview, send-to-customer, saved banks ── */

	public static function get_single_order( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$a   = self::order_adapter();
			$id  = (int) $req['id'];
			if ( ! method_exists( $a, 'get_order' ) ) { throw new \RuntimeException( 'adapter_no_get_order' ); }
			$row = $a->get_order( $id );
			if ( ! $row ) { throw new \RuntimeException( 'order_not_found' ); }
			return $row;
		} );
	}

	/**
	 * Send an existing order to the customer through the conversation channel.
	 * Modes:
	 *   - link  : "Link thanh toán: <checkout_url>"
	 *   - qr    : bank info + VietQR image URL (single attachment-like message)
	 *   - recap : full recap (items + total + bank + QR + link) as one message
	 */
	public static function post_send_order_to_customer( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$a       = self::order_adapter();
			$conv_id = (int) $req['id'];
			$body    = $req->get_json_params() ?: array();
			$oid     = (int) ( $body['order_id'] ?? $req->get_param( 'order_id' ) );
			$mode    = (string) ( $body['mode'] ?? $req->get_param( 'mode' ) ?: 'recap' );
			if ( ! in_array( $mode, array( 'link', 'qr', 'recap' ), true ) ) { $mode = 'recap'; }

			$order = method_exists( $a, 'get_order' ) ? $a->get_order( $oid ) : null;
			if ( ! $order ) { throw new \RuntimeException( 'order_not_found' ); }

			// Build the outgoing message text.
			$lines = array();
			$pay   = $order['payment'] ?? null;
			if ( $mode === 'link' ) {
				$lines[] = sprintf( '🔗 Link thanh toán đơn #%d (%s %s):', $oid, number_format( (float) $order['total'], 0, ',', '.' ), $order['currency'] );
				$lines[] = (string) $order['checkout_url'];
			} elseif ( $mode === 'qr' && $pay ) {
				$lines[] = sprintf( '🏦 Quý khách vui lòng chuyển khoản đơn #%d:', $oid );
				$lines[] = sprintf( '• Ngân hàng: %s', $pay['bank_label'] );
				$lines[] = sprintf( '• STK: %s — %s', $pay['account_no'], $pay['account_name'] );
				$lines[] = sprintf( '• Số tiền: %s đ', number_format( (float) $pay['amount'], 0, ',', '.' ) );
				$lines[] = sprintf( '• Nội dung: %s', $pay['content'] );
				if ( ! empty( $pay['qr_img_url'] ) ) {
					$lines[] = '🔳 Mã QR: ' . $pay['qr_img_url'];
				}
			} else { // recap (default)
				$lines[] = sprintf( '📦 Cảm ơn anh/chị đã đặt đơn #%d', $oid );
				if ( ! empty( $order['items'] ) ) {
					foreach ( $order['items'] as $it ) {
						$lines[] = sprintf( '   • %s × %d = %s đ', $it['name'], (int) $it['qty'], number_format( (float) $it['total'], 0, ',', '.' ) );
					}
				}
				$lines[] = sprintf( 'Tổng: %s %s', number_format( (float) $order['total'], 0, ',', '.' ), $order['currency'] );
				if ( $pay ) {
					$lines[] = sprintf( '🏦 %s — STK %s (%s)', $pay['bank_label'], $pay['account_no'], $pay['account_name'] );
					$lines[] = sprintf( 'Nội dung: %s', $pay['content'] );
					if ( ! empty( $pay['qr_img_url'] ) ) {
						$lines[] = 'QR: ' . $pay['qr_img_url'];
					}
				}
				$lines[] = '🔗 Thanh toán online: ' . $order['checkout_url'];
			}
			$content = implode( "\n", $lines );

			// Reuse post_message dispatch path so adapter routing + ledger mirror stay consistent.
			$inner = new WP_REST_Request( 'POST', '' );
			$inner->set_url_params( array( 'id' => $conv_id ) );
			$inner->set_body( wp_json_encode( array(
				'content'        => $content,
				'content_type'   => 'text',
				'responder_kind' => 'manual',
			) ) );
			$inner->set_header( 'content-type', 'application/json' );
			$resp = self::post_message( $inner );
			$data = is_object( $resp ) && method_exists( $resp, 'get_data' ) ? $resp->get_data() : $resp;
			return array(
				'sent'    => ! empty( $data['data']['sent'] ?? $data['sent'] ?? false ),
				'mode'    => $mode,
				'order'   => $order,
				'preview' => $content,
			);
		} );
	}

	public static function get_saved_banks( WP_REST_Request $req ) {
		return self::wrap( static function () {
			return array( 'banks' => BizCity_CRM_Order_Adapter_Woo_Bank_QR::get_saved_bank_accounts() );
		} );
	}

	public static function post_saved_bank( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: $req->get_params();
			$row  = BizCity_CRM_Order_Adapter_Woo_Bank_QR::add_saved_bank_account( array(
				'bank_id'      => (string) ( $body['bank_id'] ?? '' ),
				'bank_label'   => (string) ( $body['bank_label'] ?? '' ),
				'bin'          => (string) ( $body['bin'] ?? '' ),
				'account_no'   => (string) ( $body['account_no'] ?? '' ),
				'account_name' => (string) ( $body['account_name'] ?? '' ),
			) );
			return array(
				'added' => $row,
				'banks' => BizCity_CRM_Order_Adapter_Woo_Bank_QR::get_saved_bank_accounts(),
			);
		} );
	}

	public static function delete_saved_bank( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$idx = (int) ( $req->get_param( 'idx' ) ?? -1 );
			$ok  = BizCity_CRM_Order_Adapter_Woo_Bank_QR::delete_saved_bank_account( $idx );
			return array(
				'deleted' => $ok,
				'banks'   => BizCity_CRM_Order_Adapter_Woo_Bank_QR::get_saved_bank_accounts(),
			);
		} );
	}

	/* ================================================================
	 * PHASE 0.35 M2.W5 — Automation Rules handlers
	 * ================================================================ */

	public static function get_automation_rules( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array(
				'event_name' => (string) ( $req->get_param( 'event_name' ) ?? '' ),
				'inbox_id'   => $req->get_param( 'inbox_id' ),
				'q'          => (string) ( $req->get_param( 'q' ) ?? '' ),
				'limit'      => (int) ( $req->get_param( 'limit' )  ?? 100 ),
				'offset'     => (int) ( $req->get_param( 'offset' ) ?? 0 ),
			);
			$active = $req->get_param( 'active' );
			if ( $active !== null ) { $args['active'] = (int) (bool) $active; }
			if ( $args['event_name'] === '' ) { unset( $args['event_name'] ); }
			if ( $args['q']          === '' ) { unset( $args['q'] ); }
			if ( $args['inbox_id']   === null ) { unset( $args['inbox_id'] ); }

			$rows = BizCity_CRM_Repository::list_automation_rules( $args );
			return array(
				'rules' => array_map( array( __CLASS__, 'shape_automation_rule' ), $rows ),
				'count' => count( $rows ),
			);
		} );
	}

	public static function get_automation_rule( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id  = (int) $req->get_param( 'id' );
			$row = BizCity_CRM_Repository::get_automation_rule( $id );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Automation rule not found', array( 'status' => 404 ) );
			}
			return self::shape_automation_rule( $row );
		} );
	}

	public static function post_automation_rule( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = self::extract_rule_body( $req );
			$err  = self::validate_rule_body( $body, true );
			if ( is_wp_error( $err ) ) { return $err; }
			$id = BizCity_CRM_Repository::upsert_automation_rule( $body );
			if ( ! $id ) {
				return new WP_Error( 'insert_failed', 'Could not create automation rule', array( 'status' => 500 ) );
			}
			return self::shape_automation_rule( BizCity_CRM_Repository::get_automation_rule( $id ) );
		} );
	}

	public static function put_automation_rule( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			if ( ! BizCity_CRM_Repository::get_automation_rule( $id ) ) {
				return new WP_Error( 'not_found', 'Automation rule not found', array( 'status' => 404 ) );
			}
			$body       = self::extract_rule_body( $req );
			$body['id'] = $id;
			$err        = self::validate_rule_body( $body, false );
			if ( is_wp_error( $err ) ) { return $err; }
			BizCity_CRM_Repository::upsert_automation_rule( $body );
			return self::shape_automation_rule( BizCity_CRM_Repository::get_automation_rule( $id ) );
		} );
	}

	public static function delete_automation_rule( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			$ok = BizCity_CRM_Repository::delete_automation_rule( $id );
			return array( 'deleted' => $ok, 'id' => $id );
		} );
	}

	public static function post_automation_rule_dry_run( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id   = (int) $req->get_param( 'id' );
			$rule = BizCity_CRM_Repository::get_automation_rule( $id );
			if ( ! $rule ) {
				return new WP_Error( 'not_found', 'Automation rule not found', array( 'status' => 404 ) );
			}
			$payload = $req->get_param( 'event_payload' );
			if ( ! is_array( $payload ) ) { $payload = array(); }
			$context             = BizCity_CRM_Automation_Engine::build_context( (string) $rule['event_name'], $payload );
			$context['rule_id']  = (int) $rule['id'];
			$context['dry_run']  = true;
			$conditions          = json_decode( (string) $rule['conditions_json'], true );
			$evaluation          = BizCity_CRM_Rule_Evaluator::evaluate( is_array( $conditions ) ? $conditions : array(), $context );
			$action_results      = array();
			if ( ! empty( $evaluation['matched'] ) ) {
				$actions        = json_decode( (string) $rule['actions_json'], true );
				$action_results = BizCity_CRM_Action_Runner::run( is_array( $actions ) ? $actions : array(), $context );
			}
			return array(
				'rule_id'    => (int) $rule['id'],
				'event_name' => (string) $rule['event_name'],
				'matched'    => (bool) ( $evaluation['matched'] ?? false ),
				'trace'      => $evaluation['trace'] ?? array(),
				'actions'    => $action_results,
				'dry_run'    => true,
			);
		} );
	}

	public static function get_automation_actions() {
		return self::wrap( static function () {
			$out = array();
			foreach ( BizCity_CRM_Action_Registry::all() as $type => $def ) {
				$out[] = array(
					'type'         => $type,
					'label'        => (string) ( $def['label']       ?? $type ),
					'description'  => (string) ( $def['description'] ?? '' ),
					'param_schema' => $def['param_schema'] ?? array(),
				);
			}
			return array( 'actions' => $out );
		} );
	}

	private static function extract_rule_body( WP_REST_Request $req ): array {
		$json = $req->get_json_params();
		if ( ! is_array( $json ) ) { $json = array(); }
		// Fall back to body params for form-encoded posts.
		$body = wp_parse_args( $json, $req->get_body_params() ?: array() );
		return $body;
	}

	private static function validate_rule_body( array $body, bool $is_create ) {
		$name = trim( (string) ( $body['name'] ?? '' ) );
		if ( $name === '' ) {
			return new WP_Error( 'invalid_name', 'Field "name" is required', array( 'status' => 422 ) );
		}
		$event = (string) ( $body['event_name'] ?? '' );
		if ( $event === '' ) {
			return new WP_Error( 'invalid_event_name', 'Field "event_name" is required', array( 'status' => 422 ) );
		}
		if ( ! in_array( $event, BizCity_CRM_Automation_Engine::SUBSCRIBED_EVENTS, true ) ) {
			return new WP_Error( 'unsupported_event', 'event_name not subscribed by engine', array(
				'status'    => 422,
				'supported' => BizCity_CRM_Automation_Engine::SUBSCRIBED_EVENTS,
			) );
		}
		$actions = $body['actions'] ?? null;
		if ( ! is_array( $actions ) || empty( $actions ) ) {
			return new WP_Error( 'invalid_actions', 'Field "actions" must be a non-empty array', array( 'status' => 422 ) );
		}
		$known = array_keys( BizCity_CRM_Action_Registry::all() );
		foreach ( $actions as $i => $a ) {
			$type = (string) ( $a['type'] ?? '' );
			if ( ! in_array( $type, $known, true ) ) {
				return new WP_Error( 'unknown_action', sprintf( 'actions[%d].type "%s" not registered', $i, $type ), array(
					'status' => 422,
					'known'  => $known,
				) );
			}
		}
		return true;
	}

	private static function shape_automation_rule( ?array $row ): ?array {
		if ( ! $row ) { return null; }
		$cond = json_decode( (string) ( $row['conditions_json'] ?? '' ), true );
		$act  = json_decode( (string) ( $row['actions_json']    ?? '' ), true );
		return array(
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'description'   => $row['description'] !== null ? (string) $row['description'] : '',
			'event_name'    => (string) $row['event_name'],
			'inbox_id'      => $row['inbox_id'] !== null ? (int) $row['inbox_id'] : null,
			'conditions'    => is_array( $cond ) ? $cond : array(),
			'actions'       => is_array( $act )  ? $act  : array(),
			'active'        => (bool) (int) $row['active'],
			'run_count'     => (int) $row['run_count'],
			'last_run_at'   => $row['last_run_at'],
			'created_by_id' => $row['created_by_id'] !== null ? (int) $row['created_by_id'] : null,
			'created_at'    => $row['created_at'],
			'updated_at'    => $row['updated_at'],
		);
	}

	/* ================================================================
	 * PHASE 0.35 M3.W1 — Labels handlers
	 * ================================================================ */

	public static function get_labels( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array(
				'q'               => (string) ( $req->get_param( 'q' ) ?? '' ),
				'show_on_sidebar' => $req->get_param( 'show_on_sidebar' ),
			);
			if ( $args['q'] === '' )                  { unset( $args['q'] ); }
			if ( $args['show_on_sidebar'] === null )  { unset( $args['show_on_sidebar'] ); }
			$rows = BizCity_CRM_Repository::list_labels( $args );
			return array( 'labels' => array_map( array( __CLASS__, 'shape_label' ), $rows ), 'count' => count( $rows ) );
		} );
	}

	public static function get_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$row = BizCity_CRM_Repository::get_label( (int) $req->get_param( 'id' ) );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Label not found', array( 'status' => 404 ) ); }
			return self::shape_label( $row );
		} );
	}

	public static function post_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body  = self::extract_json_body( $req );
			$title = trim( (string) ( $body['title'] ?? '' ) );
			if ( $title === '' ) {
				return new WP_Error( 'invalid_title', 'Field "title" is required', array( 'status' => 422 ) );
			}
			if ( BizCity_CRM_Repository::get_label_by_title( $title ) ) {
				return new WP_Error( 'duplicate_title', 'A label with this title already exists', array( 'status' => 409 ) );
			}
			$id = BizCity_CRM_Repository::upsert_label( $body );
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create label', array( 'status' => 500 ) ); }
			return self::shape_label( BizCity_CRM_Repository::get_label( $id ) );
		} );
	}

	public static function put_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			if ( ! BizCity_CRM_Repository::get_label( $id ) ) {
				return new WP_Error( 'not_found', 'Label not found', array( 'status' => 404 ) );
			}
			$body       = self::extract_json_body( $req );
			$body['id'] = $id;
			BizCity_CRM_Repository::upsert_label( $body );
			return self::shape_label( BizCity_CRM_Repository::get_label( $id ) );
		} );
	}

	public static function delete_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			$ok = BizCity_CRM_Repository::delete_label( $id );
			return array( 'deleted' => $ok, 'id' => $id );
		} );
	}

	public static function get_conversation_labels( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$cid  = (int) $req->get_param( 'id' );
			$rows = BizCity_CRM_Repository::get_conversation_labels( $cid );
			return array( 'labels' => array_map( array( __CLASS__, 'shape_label' ), $rows ) );
		} );
	}

	public static function post_conversation_labels( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$cid  = (int) $req->get_param( 'id' );
			if ( ! BizCity_CRM_Repository::get_conversation( $cid ) ) {
				return new WP_Error( 'not_found', 'Conversation not found', array( 'status' => 404 ) );
			}
			$body  = self::extract_json_body( $req );
			$input = $body['labels'] ?? array();
			if ( ! is_array( $input ) ) {
				return new WP_Error( 'invalid_labels', 'Field "labels" must be an array of ids or titles', array( 'status' => 422 ) );
			}
			$ids = array();
			foreach ( $input as $entry ) {
				if ( is_numeric( $entry ) ) { $ids[] = (int) $entry; continue; }
				if ( is_string( $entry ) && $entry !== '' ) {
					$lbl = BizCity_CRM_Repository::get_label_by_title( $entry );
					if ( $lbl ) { $ids[] = (int) $lbl['id']; }
				}
			}
			$diff = BizCity_CRM_Repository::set_conversation_labels( $cid, $ids, get_current_user_id() );
			$rows = BizCity_CRM_Repository::get_conversation_labels( $cid );
			return array(
				'labels'  => array_map( array( __CLASS__, 'shape_label' ), $rows ),
				'added'   => $diff['added'],
				'removed' => $diff['removed'],
			);
		} );
	}

	private static function shape_label( ?array $row ): ?array {
		if ( ! $row ) { return null; }
		return array(
			'id'              => (int) $row['id'],
			'title'           => (string) $row['title'],
			'description'     => $row['description'] !== null ? (string) $row['description'] : '',
			'color'           => (string) $row['color'],
			'show_on_sidebar' => (bool) (int) $row['show_on_sidebar'],
			'created_at'      => $row['created_at'] ?? null,
			'updated_at'      => $row['updated_at'] ?? null,
		);
	}

	/* ================================================================
	 * PHASE 0.35 M3.W3 — Custom Attribute Definitions handlers
	 * ================================================================ */

	public static function get_custom_attribute_defs( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array( 'target' => (string) ( $req->get_param( 'target' ) ?? '' ) );
			if ( $args['target'] === '' ) { unset( $args['target'] ); }
			$rows = BizCity_CRM_Repository::list_custom_attribute_defs( $args );
			return array(
				'definitions' => array_map( array( __CLASS__, 'shape_custom_attribute_def' ), $rows ),
				'count'       => count( $rows ),
			);
		} );
	}

	public static function get_custom_attribute_def( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$row = BizCity_CRM_Repository::get_custom_attribute_def( (int) $req->get_param( 'id' ) );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Definition not found', array( 'status' => 404 ) ); }
			return self::shape_custom_attribute_def( $row );
		} );
	}

	public static function post_custom_attribute_def( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = self::extract_json_body( $req );
			$err  = self::validate_custom_attr_def( $body );
			if ( is_wp_error( $err ) ) { return $err; }
			$id = BizCity_CRM_Repository::upsert_custom_attribute_def( $body );
			if ( ! $id ) {
				return new WP_Error( 'insert_failed', 'Could not create custom attribute definition', array( 'status' => 500 ) );
			}
			return self::shape_custom_attribute_def( BizCity_CRM_Repository::get_custom_attribute_def( $id ) );
		} );
	}

	public static function put_custom_attribute_def( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			if ( ! BizCity_CRM_Repository::get_custom_attribute_def( $id ) ) {
				return new WP_Error( 'not_found', 'Definition not found', array( 'status' => 404 ) );
			}
			$body       = self::extract_json_body( $req );
			$body['id'] = $id;
			$err        = self::validate_custom_attr_def( $body, false );
			if ( is_wp_error( $err ) ) { return $err; }
			BizCity_CRM_Repository::upsert_custom_attribute_def( $body );
			return self::shape_custom_attribute_def( BizCity_CRM_Repository::get_custom_attribute_def( $id ) );
		} );
	}

	public static function delete_custom_attribute_def( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			$ok = BizCity_CRM_Repository::delete_custom_attribute_def( $id );
			return array( 'deleted' => $ok, 'id' => $id );
		} );
	}

	private static function validate_custom_attr_def( array $body, bool $is_create = true ) {
		$key = sanitize_key( (string) ( $body['attribute_key'] ?? '' ) );
		if ( $key === '' ) {
			return new WP_Error( 'invalid_attribute_key', '"attribute_key" is required (lowercase + underscores)', array( 'status' => 422 ) );
		}
		if ( trim( (string) ( $body['display_name'] ?? '' ) ) === '' ) {
			return new WP_Error( 'invalid_display_name', '"display_name" is required', array( 'status' => 422 ) );
		}
		$type = (string) ( $body['display_type'] ?? 'text' );
		$allowed_types = array( 'text', 'textarea', 'number', 'checkbox', 'date', 'list', 'link', 'regex' );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_display_type', '"display_type" must be one of: ' . implode( ', ', $allowed_types ), array( 'status' => 422 ) );
		}
		$target = (string) ( $body['target'] ?? 'contact' );
		if ( ! in_array( $target, array( 'contact', 'conversation' ), true ) ) {
			return new WP_Error( 'invalid_target', '"target" must be "contact" or "conversation"', array( 'status' => 422 ) );
		}
		if ( $type === 'list' ) {
			$opts = $body['options'] ?? null;
			if ( ! is_array( $opts ) || empty( $opts ) ) {
				return new WP_Error( 'invalid_options', 'For display_type="list", "options" must be a non-empty array', array( 'status' => 422 ) );
			}
		}
		if ( $type === 'regex' ) {
			$pattern = (string) ( $body['regex_pattern'] ?? '' );
			if ( $pattern === '' ) {
				return new WP_Error( 'invalid_regex_pattern', 'For display_type="regex", "regex_pattern" is required', array( 'status' => 422 ) );
			}
			if ( @preg_match( '/' . str_replace( '/', '\/', $pattern ) . '/u', '' ) === false ) {
				return new WP_Error( 'invalid_regex_pattern', 'regex_pattern is not valid PCRE', array( 'status' => 422 ) );
			}
		}
		return true;
	}

	private static function shape_custom_attribute_def( ?array $row ): ?array {
		if ( ! $row ) { return null; }
		$opts = json_decode( (string) ( $row['options_json'] ?? '' ), true );
		return array(
			'id'             => (int) $row['id'],
			'attribute_key'  => (string) $row['attribute_key'],
			'display_name'   => (string) $row['display_name'],
			'description'    => $row['description'] !== null ? (string) $row['description'] : '',
			'display_type'   => (string) $row['display_type'],
			'target'         => (string) $row['target'],
			'regex_pattern'  => $row['regex_pattern'],
			'options'        => is_array( $opts ) ? $opts : array(),
			'default_value'  => $row['default_value'],
			'created_at'     => $row['created_at'],
			'updated_at'     => $row['updated_at'],
		);
	}

	/* ================================================================
	 * PHASE 0.35 M3.W4/W5 — Macros + Template renderer handlers
	 * ================================================================ */

	public static function get_macros( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array(
				'visibility' => (string) ( $req->get_param( 'visibility' ) ?? '' ),
				'q'          => (string) ( $req->get_param( 'q' )          ?? '' ),
			);
			$active = $req->get_param( 'active' );
			if ( $active !== null ) { $args['active'] = (int) (bool) $active; }
			if ( $args['visibility'] === '' ) { unset( $args['visibility'] ); }
			if ( $args['q']          === '' ) { unset( $args['q'] ); }
			$rows = BizCity_CRM_Repository::list_macros( $args );
			return array( 'macros' => array_map( array( __CLASS__, 'shape_macro' ), $rows ), 'count' => count( $rows ) );
		} );
	}

	public static function get_macro( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$row = BizCity_CRM_Repository::get_macro( (int) $req->get_param( 'id' ) );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Macro not found', array( 'status' => 404 ) ); }
			return self::shape_macro( $row );
		} );
	}

	public static function post_macro( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = self::extract_json_body( $req );
			$err  = self::validate_macro_body( $body );
			if ( is_wp_error( $err ) ) { return $err; }
			$id = BizCity_CRM_Repository::upsert_macro( $body );
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create macro', array( 'status' => 500 ) ); }
			return self::shape_macro( BizCity_CRM_Repository::get_macro( $id ) );
		} );
	}

	public static function put_macro( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			if ( ! BizCity_CRM_Repository::get_macro( $id ) ) {
				return new WP_Error( 'not_found', 'Macro not found', array( 'status' => 404 ) );
			}
			$body       = self::extract_json_body( $req );
			$body['id'] = $id;
			$err        = self::validate_macro_body( $body, false );
			if ( is_wp_error( $err ) ) { return $err; }
			BizCity_CRM_Repository::upsert_macro( $body );
			return self::shape_macro( BizCity_CRM_Repository::get_macro( $id ) );
		} );
	}

	public static function delete_macro( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			$ok = BizCity_CRM_Repository::delete_macro( $id );
			return array( 'deleted' => $ok, 'id' => $id );
		} );
	}

	public static function post_macro_preview( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id    = (int) $req->get_param( 'id' );
			$macro = BizCity_CRM_Repository::get_macro( $id );
			if ( ! $macro ) { return new WP_Error( 'not_found', 'Macro not found', array( 'status' => 404 ) ); }
			$conv_id = (int) ( $req->get_param( 'conversation_id' ) ?? 0 );
			$ctx     = $conv_id > 0 ? BizCity_CRM_Template_Renderer::build_context_from_conversation( $conv_id ) : array();
			$tpl     = (string) ( $macro['template'] ?? '' );
			$mode    = (string) ( $req->get_param( 'mode' ) ?? 'text' );
			$out     = BizCity_CRM_Template_Renderer::render( $tpl, $ctx, $mode );
			return array(
				'macro_id'   => $id,
				'rendered'   => $out,
				'mode'       => $mode,
				'context_present' => array_keys( $ctx ),
				'actions'    => json_decode( (string) $macro['actions_json'], true ) ?: array(),
			);
		} );
	}

	public static function post_macro_run( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id    = (int) $req->get_param( 'id' );
			$macro = BizCity_CRM_Repository::get_macro( $id );
			if ( ! $macro ) { return new WP_Error( 'not_found', 'Macro not found', array( 'status' => 404 ) ); }
			$conv_id = (int) ( $req->get_param( 'conversation_id' ) ?? 0 );
			$conv    = $conv_id > 0 ? BizCity_CRM_Repository::get_conversation( $conv_id ) : null;
			if ( ! $conv ) { return new WP_Error( 'invalid_conversation_id', 'conversation_id required', array( 'status' => 422 ) ); }

			$ctx        = BizCity_CRM_Template_Renderer::build_context_from_conversation( $conv_id );
			$rendered   = BizCity_CRM_Template_Renderer::render( (string) $macro['template'], $ctx );
			$action_set = json_decode( (string) $macro['actions_json'], true ) ?: array();

			// If macro has a template, prepend an implicit send_message action with rendered text.
			if ( $rendered !== '' ) {
				array_unshift( $action_set, array(
					'type'   => 'send_message',
					'params' => array( 'content' => $rendered, 'content_type' => 'text' ),
				) );
			}
			$run_ctx = array(
				'event_name'      => 'crm_macro_invoked',
				'conversation_id' => (int) $conv['id'],
				'inbox_id'        => (int) $conv['inbox_id'],
				'rule_id'         => null,
				'event_uuid'      => null,
				'dry_run'         => (bool) $req->get_param( 'dry_run' ),
			);
			$results = BizCity_CRM_Action_Runner::run( $action_set, $run_ctx );
			BizCity_CRM_Repository::bump_macro_run_count( $id );
			return array(
				'macro_id'   => $id,
				'rendered'   => $rendered,
				'actions'    => $results,
				'dry_run'    => $run_ctx['dry_run'],
			);
		} );
	}

	public static function post_render_template( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$tpl     = (string) $req->get_param( 'template' );
			$conv_id = (int) ( $req->get_param( 'conversation_id' ) ?? 0 );
			$mode    = (string) ( $req->get_param( 'mode' ) ?? 'text' );
			$ctx     = $conv_id > 0 ? BizCity_CRM_Template_Renderer::build_context_from_conversation( $conv_id ) : array();
			return array(
				'rendered'         => BizCity_CRM_Template_Renderer::render( $tpl, $ctx, $mode ),
				'mode'             => $mode,
				'conversation_id'  => $conv_id ?: null,
				'context_present'  => array_keys( $ctx ),
			);
		} );
	}

	private static function validate_macro_body( array $body, bool $is_create = true ) {
		$name = trim( (string) ( $body['name'] ?? '' ) );
		if ( $name === '' ) {
			return new WP_Error( 'invalid_name', '"name" is required', array( 'status' => 422 ) );
		}
		$has_tpl     = isset( $body['template'] ) && trim( (string) $body['template'] ) !== '';
		$has_actions = isset( $body['actions'] ) && is_array( $body['actions'] ) && ! empty( $body['actions'] );
		if ( ! $has_tpl && ! $has_actions ) {
			return new WP_Error( 'empty_macro', 'Macro must have at least a template or actions', array( 'status' => 422 ) );
		}
		if ( $has_actions ) {
			$known = array_keys( BizCity_CRM_Action_Registry::all() );
			foreach ( $body['actions'] as $i => $a ) {
				if ( ! in_array( (string) ( $a['type'] ?? '' ), $known, true ) ) {
					return new WP_Error( 'unknown_action', sprintf( 'actions[%d].type unknown', $i ), array( 'status' => 422, 'known' => $known ) );
				}
			}
		}
		$vis = (string) ( $body['visibility'] ?? 'global' );
		if ( ! in_array( $vis, array( 'global', 'personal' ), true ) ) {
			return new WP_Error( 'invalid_visibility', '"visibility" must be "global" or "personal"', array( 'status' => 422 ) );
		}
		return true;
	}

	private static function shape_macro( ?array $row ): ?array {
		if ( ! $row ) { return null; }
		$act = json_decode( (string) ( $row['actions_json'] ?? '' ), true );
		return array(
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'description'   => $row['description'] !== null ? (string) $row['description'] : '',
			'visibility'    => (string) $row['visibility'],
			'owner_user_id' => $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
			'template'      => $row['template'] !== null ? (string) $row['template'] : '',
			'actions'       => is_array( $act ) ? $act : array(),
			'active'        => (bool) (int) $row['active'],
			'run_count'     => (int) $row['run_count'],
			'last_used_at'  => $row['last_used_at'],
			'created_at'    => $row['created_at'],
			'updated_at'    => $row['updated_at'],
		);
	}

	/**
	 * Shared JSON body extractor (handles JSON + form-encoded fallbacks).
	 */
	private static function extract_json_body( WP_REST_Request $req ): array {
		$json = $req->get_json_params();
		if ( ! is_array( $json ) ) { $json = array(); }
		$body = wp_parse_args( $json, $req->get_body_params() ?: array() );
		return is_array( $body ) ? $body : array();
	}

	/* ================================================================
	 * PHASE 0.35 M4.W1+W2+W3 — Working Hours · SLA Policies · SLA tick
	 * ================================================================ */

	public static function get_working_hours( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$inbox_id = (int) $req->get_param( 'inbox_id' );
			if ( $inbox_id <= 0 ) { return new WP_Error( 'invalid_inbox_id', 'inbox_id required', array( 'status' => 422 ) ); }
			BizCity_CRM_Repository::ensure_working_hours_seeded( $inbox_id );
			$rows = BizCity_CRM_Repository::list_working_hours( $inbox_id );
			return array(
				'inbox_id' => $inbox_id,
				'days'     => array_map( array( __CLASS__, 'shape_working_hour_row' ), $rows ),
			);
		} );
	}

	public static function put_working_hours( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body     = self::extract_json_body( $req );
			$inbox_id = (int) ( $body['inbox_id'] ?? $req->get_param( 'inbox_id' ) ?? 0 );
			if ( $inbox_id <= 0 ) { return new WP_Error( 'invalid_inbox_id', 'inbox_id required', array( 'status' => 422 ) ); }
			$days = $body['days'] ?? array();
			if ( ! is_array( $days ) ) { return new WP_Error( 'invalid_days', '"days" must be an array', array( 'status' => 422 ) ); }
			$saved = 0;
			foreach ( $days as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$row['inbox_id'] = $inbox_id;
				if ( BizCity_CRM_Repository::upsert_working_hour_row( $row ) ) { $saved++; }
			}
			BizCity_CRM_Working_Hours::invalidate_cache( $inbox_id );
			$rows = BizCity_CRM_Repository::list_working_hours( $inbox_id );
			return array(
				'inbox_id' => $inbox_id,
				'saved'    => $saved,
				'days'     => array_map( array( __CLASS__, 'shape_working_hour_row' ), $rows ),
			);
		} );
	}

	public static function get_working_hours_check( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$inbox_id = (int) $req->get_param( 'inbox_id' );
			if ( $inbox_id <= 0 ) { return new WP_Error( 'invalid_inbox_id', 'inbox_id required', array( 'status' => 422 ) ); }
			$ts  = (int) ( $req->get_param( 'ts' ) ?? 0 );
			$ts  = $ts > 0 ? $ts : null;
			$res = BizCity_CRM_Working_Hours::check( $inbox_id, $ts );
			$res['inbox_id'] = $inbox_id;
			$res['ts']       = $ts ?? time();
			return $res;
		} );
	}

	private static function shape_working_hour_row( array $row ): array {
		return array(
			'day_of_week' => (int) $row['day_of_week'],
			'is_open'     => (bool) (int) $row['is_open'],
			'open_time'   => substr( (string) $row['open_time'],  0, 5 ),
			'close_time'  => substr( (string) $row['close_time'], 0, 5 ),
		);
	}

	public static function get_sla_policies( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array( 'q' => (string) ( $req->get_param( 'q' ) ?? '' ) );
			if ( $req->get_param( 'active' ) !== null ) { $args['active'] = (int) (bool) $req->get_param( 'active' ); }
			if ( $args['q'] === '' ) { unset( $args['q'] ); }
			$rows = BizCity_CRM_Repository::list_sla_policies( $args );
			return array( 'policies' => array_map( array( __CLASS__, 'shape_sla_policy' ), $rows ), 'count' => count( $rows ) );
		} );
	}

	public static function get_sla_policy( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$row = BizCity_CRM_Repository::get_sla_policy( (int) $req->get_param( 'id' ) );
			if ( ! $row ) { return new WP_Error( 'not_found', 'SLA policy not found', array( 'status' => 404 ) ); }
			return self::shape_sla_policy( $row );
		} );
	}

	public static function post_sla_policy( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = self::extract_json_body( $req );
			$err  = self::validate_sla_policy_body( $body );
			if ( is_wp_error( $err ) ) { return $err; }
			$id = BizCity_CRM_Repository::upsert_sla_policy( $body );
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create SLA policy', array( 'status' => 500 ) ); }
			return self::shape_sla_policy( BizCity_CRM_Repository::get_sla_policy( $id ) );
		} );
	}

	public static function put_sla_policy( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			if ( ! BizCity_CRM_Repository::get_sla_policy( $id ) ) {
				return new WP_Error( 'not_found', 'SLA policy not found', array( 'status' => 404 ) );
			}
			$body       = self::extract_json_body( $req );
			$body['id'] = $id;
			$err        = self::validate_sla_policy_body( $body, false );
			if ( is_wp_error( $err ) ) { return $err; }
			BizCity_CRM_Repository::upsert_sla_policy( $body );
			return self::shape_sla_policy( BizCity_CRM_Repository::get_sla_policy( $id ) );
		} );
	}

	public static function delete_sla_policy( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			$ok = BizCity_CRM_Repository::delete_sla_policy( $id );
			return array( 'deleted' => $ok, 'id' => $id );
		} );
	}

	private static function validate_sla_policy_body( array $body, bool $is_create = true ) {
		if ( trim( (string) ( $body['name'] ?? '' ) ) === '' ) {
			return new WP_Error( 'invalid_name', '"name" is required', array( 'status' => 422 ) );
		}
		$has_threshold = false;
		foreach ( array( 'frt_threshold_minutes', 'nrt_threshold_minutes', 'rt_threshold_minutes' ) as $f ) {
			if ( isset( $body[ $f ] ) && (int) $body[ $f ] > 0 ) { $has_threshold = true; break; }
		}
		if ( ! $has_threshold ) {
			return new WP_Error( 'no_threshold', 'At least one of frt/nrt/rt_threshold_minutes must be > 0', array( 'status' => 422 ) );
		}
		return true;
	}

	private static function shape_sla_policy( ?array $row ): ?array {
		if ( ! $row ) { return null; }
		return array(
			'id'                          => (int) $row['id'],
			'name'                        => (string) $row['name'],
			'description'                 => $row['description'] !== null ? (string) $row['description'] : '',
			'frt_threshold_minutes'       => $row['frt_threshold_minutes'] !== null ? (int) $row['frt_threshold_minutes'] : null,
			'nrt_threshold_minutes'       => $row['nrt_threshold_minutes'] !== null ? (int) $row['nrt_threshold_minutes'] : null,
			'rt_threshold_minutes'        => $row['rt_threshold_minutes']  !== null ? (int) $row['rt_threshold_minutes']  : null,
			'only_during_business_hours'  => (bool) (int) $row['only_during_business_hours'],
			'active'                      => (bool) (int) $row['active'],
			'created_at'                  => $row['created_at'],
			'updated_at'                  => $row['updated_at'],
		);
	}

	public static function post_sla_tick( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$force  = (bool) ( $req->get_param( 'force' ) ?? true );
			return BizCity_CRM_SLA_Evaluator::tick( $force );
		} );
	}

	public static function get_conversation_sla( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$cid = (int) $req->get_param( 'id' );
			$row = BizCity_CRM_Repository::get_applied_sla_for_conversation( $cid );
			if ( ! $row ) { return array( 'conversation_id' => $cid, 'applied' => null ); }
			$policy = BizCity_CRM_Repository::get_sla_policy( (int) $row['sla_policy_id'] );
			return array(
				'conversation_id' => $cid,
				'applied'         => array(
					'id'                => (int) $row['id'],
					'sla_policy_id'     => (int) $row['sla_policy_id'],
					'state'             => (string) $row['state'],
					'applied_at'        => $row['applied_at'] !== null ? (int) $row['applied_at'] : null,
					'frt_due_at'        => $row['frt_due_at'] !== null ? (int) $row['frt_due_at'] : null,
					'nrt_due_at'        => $row['nrt_due_at'] !== null ? (int) $row['nrt_due_at'] : null,
					'rt_due_at'         => $row['rt_due_at']  !== null ? (int) $row['rt_due_at']  : null,
					'frt_breached_at'   => $row['frt_breached_at'] !== null ? (int) $row['frt_breached_at'] : null,
					'nrt_breached_at'   => $row['nrt_breached_at'] !== null ? (int) $row['nrt_breached_at'] : null,
					'rt_breached_at'    => $row['rt_breached_at']  !== null ? (int) $row['rt_breached_at']  : null,
					'met_at'            => $row['met_at']            !== null ? (int) $row['met_at']            : null,
					'last_evaluated_at' => $row['last_evaluated_at'] !== null ? (int) $row['last_evaluated_at'] : null,
				),
				'policy' => $policy ? self::shape_sla_policy( $policy ) : null,
			);
		} );
	}

	/* ── M5 — Reports + CSAT ── */

	public static function get_reports_aggregate( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array(
				'metric'   => (string) $req->get_param( 'metric' ),
				'group_by' => (string) ( $req->get_param( 'group_by' ) ?: 'none' ),
			);
			foreach ( array( 'inbox_id', 'agent_id' ) as $k ) {
				$v = $req->get_param( $k );
				if ( $v !== null && $v !== '' ) { $args[ $k ] = (int) $v; }
			}
			foreach ( array( 'from', 'to' ) as $k ) {
				$v = $req->get_param( $k );
				if ( $v !== null && $v !== '' ) {
					$args[ $k ] = is_numeric( $v ) ? (int) $v : self::date_string_to_ts( (string) $v );
				}
			}
			return BizCity_CRM_Report_Builder::aggregate( $args );
		} );
	}

	public static function get_reports_auto_vs_human( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array( 'metric' => 'outgoing_messages_count', 'group_by' => 'responder_kind' );
			foreach ( array( 'inbox_id' ) as $k ) {
				$v = $req->get_param( $k );
				if ( $v !== null && $v !== '' ) { $args[ $k ] = (int) $v; }
			}
			foreach ( array( 'from', 'to' ) as $k ) {
				$v = $req->get_param( $k );
				if ( $v !== null && $v !== '' ) {
					$args[ $k ] = is_numeric( $v ) ? (int) $v : self::date_string_to_ts( (string) $v );
				}
			}
			return BizCity_CRM_Report_Builder::aggregate( $args );
		} );
	}

	public static function post_reports_rollup_run( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$day = $req->get_param( 'day_ts' );
			return BizCity_CRM_Daily_Rollup::run( $day !== null && $day !== '' ? (int) $day : null );
		} );
	}

	/* ── PHASE 0.35 M-CRM.M8.W5 — Woo Reports Bridge handlers ── */

	public static function get_reports_woo_summary( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ) {
				return array( 'wc_active' => false, 'summary' => null );
			}
			$from = (string) ( $req->get_param( 'from' ) ?: '-30 days' );
			$to   = (string) ( $req->get_param( 'to' )   ?: 'now' );
			return array(
				'wc_active' => true,
				'summary'   => BizCity_CRM_Woo_Reports_Bridge::get_revenue_summary( $from, $to ),
			);
		} );
	}

	public static function get_reports_woo_top_customers( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ) {
				return array( 'wc_active' => false, 'customers' => array() );
			}
			$from  = (string) ( $req->get_param( 'from' )  ?: '-30 days' );
			$to    = (string) ( $req->get_param( 'to' )    ?: 'now' );
			$limit = (int)    ( $req->get_param( 'limit' ) ?: 10 );
			return array(
				'wc_active' => true,
				'customers' => BizCity_CRM_Woo_Reports_Bridge::get_top_customers( $from, $to, $limit ),
			);
		} );
	}

	public static function get_reports_woo_by_campaign( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ) {
				return array( 'wc_active' => false, 'campaigns' => array() );
			}
			$from = (string) ( $req->get_param( 'from' ) ?: '-30 days' );
			$to   = (string) ( $req->get_param( 'to' )   ?: 'now' );
			return array(
				'wc_active' => true,
				'campaigns' => BizCity_CRM_Woo_Reports_Bridge::get_revenue_by_campaign( $from, $to ),
			);
		} );
	}

	public static function get_reports_woo_trend( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$months = max( 1, min( 24, (int) ( $req->get_param( 'months' ) ?: 6 ) ) );
			if ( ! class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ) {
				return array( 'wc_active' => false, 'months' => array(), 'currency' => '' );
			}
			$trend = BizCity_CRM_Woo_Reports_Bridge::get_revenue_trend( $months );
			return array(
				'wc_active' => ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ),
				'months'    => $trend['months'],
				'currency'  => $trend['currency'],
			);
		} );
	}

	/* ── PHASE-0.46 M1 — Team Performance report ─────────────────────────────── */
	// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — Aggregate KPI per agent from
	// bizcity_crm_submissions + bizcity_crm_opportunities + bizcity_crm_activities.

	public static function get_reports_team_performance( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;

			$is_manager = current_user_can( 'manage_options' ) || current_user_can( 'bizcity_manager' );
			if ( ! $is_manager ) {
				return new WP_Error( 'forbidden', 'Team performance report requires manager role.', array( 'status' => 403 ) );
			}

			// Date range — default: current month
			$now       = current_time( 'mysql' );
			$from_raw  = sanitize_text_field( $req->get_param( 'from' ) ?: '' );
			$to_raw    = sanitize_text_field( $req->get_param( 'to' )   ?: '' );
			$from      = $from_raw ? $from_raw . ' 00:00:00' : date( 'Y-m-01 00:00:00' );
			$to        = $to_raw   ? $to_raw   . ' 23:59:59' : $now;
			$filter_uid = (int) $req->get_param( 'wp_user_id' );

			// Tables
			$t_sub  = $wpdb->prefix . 'bizcity_crm_submissions';
			$t_opp  = $wpdb->prefix . 'bizcity_crm_opportunities';
			$t_act  = $wpdb->prefix . 'bizcity_crm_activities';
			$t_usr  = $wpdb->users;

			// Guard: check tables exist
			if ( ! bizcity_tbl_exists( $t_sub ) ) {
				return array( 'rows' => array(), 'from' => $from, 'to' => $to, '_missing_table' => true );
			}

			$uid_clause = $filter_uid ? $wpdb->prepare( 'AND s.assigned_to_wp_user_id = %d', $filter_uid ) : '';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						u.ID              AS wp_user_id,
						u.display_name    AS name,
						u.user_email      AS email,
						COUNT(DISTINCT s.id)                                                                    AS submissions_assigned,
						COUNT(DISTINCT CASE WHEN s.follow_status = 'contacted'   THEN s.id END)                AS contacted,
						COUNT(DISTINCT CASE WHEN s.follow_status = 'qualified'   THEN s.id END)                AS qualified,
						COUNT(DISTINCT CASE WHEN s.follow_status = 'closed_won'  THEN s.id END)                AS closed_won_subs,
						COUNT(DISTINCT CASE WHEN a.type = 'call' THEN a.id END)                                AS calls_made,
						COUNT(DISTINCT o.id)                                                                    AS opps_created,
						COUNT(DISTINCT CASE WHEN o.stage = 'closed_won' THEN o.id END)                         AS opps_won,
						COALESCE(SUM(CASE WHEN o.stage != 'closed_lost' THEN CAST(o.amount AS DECIMAL(18,2)) END), 0) AS pipeline_value,
						COALESCE(SUM(CASE WHEN o.stage = 'closed_won'   THEN CAST(o.amount AS DECIMAL(18,2)) END), 0) AS won_value
					FROM {$t_usr} u
					LEFT JOIN {$t_sub} s
						ON s.assigned_to_wp_user_id = u.ID
						AND s.submitted_at BETWEEN %s AND %s
						AND s.deleted_at IS NULL
						{$uid_clause}
					LEFT JOIN {$t_act} a
						ON a.entity_type = 'submission'
						AND a.entity_id  = s.id
						AND a.type = 'call'
					LEFT JOIN {$t_opp} o
						ON o.owner_id = u.ID
						AND o.created_at BETWEEN %s AND %s
					WHERE u.ID IN (
						SELECT DISTINCT assigned_to_wp_user_id FROM {$t_sub}
						WHERE assigned_to_wp_user_id IS NOT NULL AND deleted_at IS NULL
					)
					GROUP BY u.ID, u.display_name, u.user_email
					ORDER BY pipeline_value DESC",
					$from, $to, $from, $to
				),
				ARRAY_A
			);
			// phpcs:enable

			// Compute conversion rate for each row
			$out = array();
			foreach ( $rows as $r ) {
				$qualified  = max( 1, (int) $r['qualified'] );
				$opps_won   = (int) $r['opps_won'];
				$cr_pct     = round( ( $opps_won / $qualified ) * 100, 1 );
				$out[] = array(
					'wp_user_id'          => (int) $r['wp_user_id'],
					'name'                => $r['name'],
					'email'               => $r['email'],
					'submissions_assigned' => (int) $r['submissions_assigned'],
					'contacted'           => (int) $r['contacted'],
					'qualified'           => (int) $r['qualified'],
					'closed_won_subs'     => (int) $r['closed_won_subs'],
					'calls_made'          => (int) $r['calls_made'],
					'opps_created'        => (int) $r['opps_created'],
					'opps_won'            => $opps_won,
					'pipeline_value'      => (float) $r['pipeline_value'],
					'won_value'           => (float) $r['won_value'],
					'conversion_rate'     => $cr_pct,
				);
			}

			return array(
				'rows' => $out,
				'from' => $from,
				'to'   => $to,
				'totals' => array(
					'submissions' => array_sum( array_column( $out, 'submissions_assigned' ) ),
					'opps_won'    => array_sum( array_column( $out, 'opps_won' ) ),
					'won_value'   => round( array_sum( array_column( $out, 'won_value' ) ), 0 ),
				),
			);
		} );
	}

	/* ── PHASE-0.46 M1 — Source Analytics report ─────────────────────────────── */
	// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — Breakdown by channel + UTM source
	// from source_meta_json of bizcity_crm_submissions.

	public static function get_reports_source_analytics( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;

			$from_raw = sanitize_text_field( $req->get_param( 'from' ) ?: '' );
			$to_raw   = sanitize_text_field( $req->get_param( 'to' )   ?: '' );
			$from     = $from_raw ? $from_raw . ' 00:00:00' : date( 'Y-m-01 00:00:00' );
			$to       = $to_raw   ? $to_raw   . ' 23:59:59' : current_time( 'mysql' );

			$t_sub = $wpdb->prefix . 'bizcity_crm_submissions';
			if ( ! bizcity_tbl_exists( $t_sub ) ) {
				return array( 'channel' => array(), 'device' => array(), 'os' => array(), 'browser' => array(), 'top_utm_campaign' => array() );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$all = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_meta_json FROM {$t_sub}
					 WHERE submitted_at BETWEEN %s AND %s AND deleted_at IS NULL",
					$from, $to
				),
				ARRAY_A
			);
			// phpcs:enable

			$channel  = array();
			$device   = array();
			$os       = array();
			$browser  = array();
			$utm_src  = array();
			$utm_camp = array();
			$total    = 0;

			foreach ( $all as $row ) {
				$meta = $row['source_meta_json'] ? json_decode( $row['source_meta_json'], true ) : array();
				if ( ! is_array( $meta ) ) {
					$meta = array();
				}
				$total++;

				$ch = sanitize_key( $meta['channel']      ?? 'other' );
				$dv = sanitize_key( $meta['device']        ?? 'unknown' );
				$ov = sanitize_key( $meta['os']            ?? 'unknown' );
				$bv = sanitize_key( $meta['browser']       ?? 'unknown' );
				$us = sanitize_text_field( $meta['utm_source']   ?? '' );
				$uc = sanitize_text_field( $meta['utm_campaign'] ?? '' );

				$channel[ $ch ] = ( $channel[ $ch ] ?? 0 ) + 1;
				$device[  $dv ] = ( $device[  $dv ] ?? 0 ) + 1;
				$os[      $ov ] = ( $os[      $ov ] ?? 0 ) + 1;
				$browser[ $bv ] = ( $browser[ $bv ] ?? 0 ) + 1;
				if ( $us )  { $utm_src[  $us ] = ( $utm_src[  $us ] ?? 0 ) + 1; }
				if ( $uc )  { $utm_camp[ $uc ] = ( $utm_camp[ $uc ] ?? 0 ) + 1; }
			}

			// Sort desc + convert to array of { name, count, pct }
			$to_series = function ( array $map ) use ( $total ) {
				arsort( $map );
				$out = array();
				foreach ( $map as $k => $v ) {
					$out[] = array( 'name' => $k, 'count' => $v, 'pct' => $total > 0 ? round( $v / $total * 100, 1 ) : 0 );
				}
				return $out;
			};

			arsort( $utm_camp );
			$top_campaigns = array_slice( $to_series( $utm_camp ), 0, 10 );

			return array(
				'total'            => $total,
				'from'             => $from,
				'to'               => $to,
				'channel'          => $to_series( $channel ),
				'device'           => $to_series( $device ),
				'os'               => $to_series( $os ),
				'browser'          => $to_series( $browser ),
				'top_utm_source'   => array_slice( $to_series( $utm_src ),  0, 10 ),
				'top_utm_campaign' => $top_campaigns,
			);
		} );
	}

	/* ── PHASE 0.35 M-CRM.M8.W2.2 — biz_contacts → contacts migration handler ── */

	public static function post_migrate_biz_contacts( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Migrate_Biz_Contacts' ) ) {
				return new WP_Error( 'migration_unavailable', 'Migration class not loaded', array( 'status' => 500 ) );
			}
			$opts = array(
				'dry_run'  => (bool) $req->get_param( 'dry_run' ),
				'batch'    => max( 50, (int) ( $req->get_param( 'batch' )    ?: 500 ) ),
				'max_rows' => max( 0,  (int) ( $req->get_param( 'max_rows' ) ?: 0 ) ),
			);
			return BizCity_CRM_Migrate_Biz_Contacts::run( $opts );
		} );
	}

	public static function post_csat( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$cid   = (int) $req->get_param( 'id' );
			$score = (int) $req->get_param( 'score' );
			if ( $cid <= 0 || $score < 1 || $score > 5 ) {
				return new WP_Error( 'invalid_input', 'conversation id and score (1-5) required', array( 'status' => 400 ) );
			}
			global $wpdb;
			$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$inbox_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT inbox_id FROM {$conv_tbl} WHERE id=%d", $cid ) );
			$uuid = BizCity_CRM_CSAT_Survey::record_response( $cid, $score, $inbox_id );
			return array( 'conversation_id' => $cid, 'score' => $score, 'event_uuid' => $uuid );
		} );
	}

	// ── [2026-06-07 Johnny Chu] PHASE-0.40 G3.1 — BizCity parity 6 report endpoints ──

	/**
	 * GET /reports/message — message volume KPIs.
	 * Returns: {total, today, avg_per_day, sent_count, received_count, series[]}.
	 */
	public static function get_reports_message( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$days = max( 1, min( 365, (int) ( $req->get_param( 'days' ) ?: 30 ) ) );
			// [2026-06-07 Johnny Chu] PHASE-0.40 fix OWASP A03 — validate date format before SQL interpolation
			$from = self::safe_date( $req->get_param( 'from' ), date( 'Y-m-d', strtotime( "-{$days} days" ) ) );
			$to   = self::safe_date( $req->get_param( 'to' ),   date( 'Y-m-d' ) );
			$inbox_id = (int) ( $req->get_param( 'inbox_id' ) ?: 0 );
			global $wpdb;
			$msg_tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
			$where_inbox = $inbox_id > 0 ? $wpdb->prepare( ' AND m.inbox_id = %d', $inbox_id ) : '';
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$msg_tbl}` m WHERE DATE(m.created_at) BETWEEN '{$from}' AND '{$to}'{$where_inbox}" );
			$today = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$msg_tbl}` m WHERE DATE(m.created_at) = CURDATE(){$where_inbox}" );
			$days_diff = max( 1, (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1 );
			$series = $wpdb->get_results( "SELECT DATE(m.created_at) AS day, COUNT(*) AS count FROM `{$msg_tbl}` m WHERE DATE(m.created_at) BETWEEN '{$from}' AND '{$to}'{$where_inbox} GROUP BY day ORDER BY day ASC", ARRAY_A );
			return array(
				'total'        => $total,
				'today'        => $today,
				'avg_per_day'  => round( $total / $days_diff, 1 ),
				'from'         => $from,
				'to'           => $to,
				'series'       => $series ?: array(),
			);
		} );
	}

	/**
	 * GET /reports/response — response time distribution.
	 * Tái dùng class-sla-evaluator data. Returns: {avg_min, distribution[], heatmap[]}.
	 */
	public static function get_reports_response( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$days = max( 1, min( 365, (int) ( $req->get_param( 'days' ) ?: 30 ) ) );
			// [2026-06-07 Johnny Chu] PHASE-0.40 fix OWASP A03
			$from = self::safe_date( $req->get_param( 'from' ), date( 'Y-m-d', strtotime( "-{$days} days" ) ) );
			$to   = self::safe_date( $req->get_param( 'to' ),   date( 'Y-m-d' ) );
			if ( ! class_exists( 'BizCity_CRM_Report_Builder' ) ) {
				return array( 'avg_min' => 0, 'distribution' => array(), 'heatmap' => array(), '_degraded' => true );
			}
			$args = array( 'metric' => 'first_response_time', 'from' => $from, 'to' => $to );
			$data = BizCity_CRM_Report_Builder::aggregate( $args );
			return is_array( $data ) ? $data : array( 'avg_min' => 0, 'distribution' => array(), '_degraded' => true );
		} );
	}

	/**
	 * GET /reports/agent — per-agent KPI.
	 * Tái dùng class-responder-stamper. Returns agents[]{id, name, msg_count, first_response_avg_min}.
	 */
	public static function get_reports_agent( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$days = max( 1, min( 365, (int) ( $req->get_param( 'days' ) ?: 30 ) ) );
			// [2026-06-07 Johnny Chu] PHASE-0.40 fix OWASP A03
			$from = self::safe_date( $req->get_param( 'from' ), date( 'Y-m-d', strtotime( "-{$days} days" ) ) );
			$to   = self::safe_date( $req->get_param( 'to' ),   date( 'Y-m-d' ) );
			global $wpdb;
			$msg_tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
			$rows = $wpdb->get_results( "
				SELECT m.responder_id AS agent_id, COUNT(*) AS msg_count
				FROM `{$msg_tbl}` m
				WHERE m.responder_id IS NOT NULL AND m.responder_id > 0
				  AND DATE(m.created_at) BETWEEN '{$from}' AND '{$to}'
				GROUP BY m.responder_id
				ORDER BY msg_count DESC
				LIMIT 50
			", ARRAY_A );
			$agents = array();
			foreach ( ( $rows ?: array() ) as $row ) {
				$uid  = (int) $row['agent_id'];
				$user = get_userdata( $uid );
				$agents[] = array(
					'id'                    => $uid,
					'name'                  => $user ? $user->display_name : "Agent #{$uid}",
					'msg_count'             => (int) $row['msg_count'],
					'first_response_avg_min' => 0, // Calculated by SLA evaluator — placeholder
				);
			}
			return array( 'from' => $from, 'to' => $to, 'agents' => $agents );
		} );
	}

	/**
	 * GET /reports/campaign — campaign visit + conversion summary.
	 */
	public static function get_reports_campaign( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$days = max( 1, min( 365, (int) ( $req->get_param( 'days' ) ?: 30 ) ) );
			// [2026-06-07 Johnny Chu] PHASE-0.40 fix OWASP A03
			$from = self::safe_date( $req->get_param( 'from' ), date( 'Y-m-d', strtotime( "-{$days} days" ) ) );
			$to   = self::safe_date( $req->get_param( 'to' ),   date( 'Y-m-d' ) );
			global $wpdb;
			$v_tbl = BizCity_CRM_DB_Installer_V2::tbl_campaigns() ? $wpdb->prefix . 'bizcity_crm_campaign_visits' : '';
			$c_tbl = $wpdb->prefix . 'bizcity_crm_campaigns';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v_tbl ) ) !== $v_tbl ) {
				return array( 'total_visits' => 0, 'total_conversions' => 0, 'campaigns' => array(), '_degraded' => true );
			}
			$total_visits      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$v_tbl}` WHERE DATE(visited_at) BETWEEN '{$from}' AND '{$to}'" );
			$total_conversions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$v_tbl}` WHERE is_converted=1 AND DATE(visited_at) BETWEEN '{$from}' AND '{$to}'" );
			$campaigns = $wpdb->get_results( "
				SELECT c.id, c.name, COUNT(v.id) AS visits, SUM(v.is_converted) AS conversions
				FROM `{$c_tbl}` c LEFT JOIN `{$v_tbl}` v ON v.campaign_id = c.id AND DATE(v.visited_at) BETWEEN '{$from}' AND '{$to}'
				GROUP BY c.id ORDER BY visits DESC LIMIT 20
			", ARRAY_A );
			return array(
				'total_visits'      => $total_visits,
				'total_conversions' => $total_conversions,
				'conversion_rate'   => $total_visits > 0 ? round( $total_conversions / $total_visits * 100, 1 ) : 0,
				'campaigns'         => $campaigns ?: array(),
			);
		} );
	}

	/**
	 * GET /reports/workflow — automation run success/fail counts.
	 */
	public static function get_reports_workflow( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$days = max( 1, min( 365, (int) ( $req->get_param( 'days' ) ?: 30 ) ) );
			// [2026-06-07 Johnny Chu] PHASE-0.40 fix OWASP A03
			$from = self::safe_date( $req->get_param( 'from' ), date( 'Y-m-d', strtotime( "-{$days} days" ) ) );
			$to   = self::safe_date( $req->get_param( 'to' ),   date( 'Y-m-d' ) );
			global $wpdb;
			$runs_tbl = $wpdb->prefix . 'bizcity_automation_runs';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs_tbl ) ) !== $runs_tbl ) {
				return array( 'total' => 0, 'success' => 0, 'failed' => 0, '_degraded' => true );
			}
			$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$runs_tbl}` WHERE DATE(created_at) BETWEEN '{$from}' AND '{$to}'" );
			$success = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$runs_tbl}` WHERE status='done' AND DATE(created_at) BETWEEN '{$from}' AND '{$to}'" );
			$failed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$runs_tbl}` WHERE status='failed' AND DATE(created_at) BETWEEN '{$from}' AND '{$to}'" );
			$series  = $wpdb->get_results( "SELECT DATE(created_at) AS day, COUNT(*) AS count FROM `{$runs_tbl}` WHERE DATE(created_at) BETWEEN '{$from}' AND '{$to}' GROUP BY day ORDER BY day ASC", ARRAY_A );
			return array(
				'total'   => $total,
				'success' => $success,
				'failed'  => $failed,
				'series'  => $series ?: array(),
			);
		} );
	}

	/**
	 * GET /reports/ai — AI usage summary from bizcity_llm_usage.
	 * Returns: {total_tokens, total_calls, by_service[], by_day[]}.
	 */
	public static function get_reports_ai( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$days = max( 1, min( 365, (int) ( $req->get_param( 'days' ) ?: 30 ) ) );
			// [2026-06-07 Johnny Chu] PHASE-0.40 fix OWASP A03
			$from = self::safe_date( $req->get_param( 'from' ), date( 'Y-m-d', strtotime( "-{$days} days" ) ) );
			$to   = self::safe_date( $req->get_param( 'to' ),   date( 'Y-m-d' ) );
			global $wpdb;
			$usage_tbl = $wpdb->prefix . 'bizcity_llm_usage';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_tbl ) ) !== $usage_tbl ) {
				return array( 'total_tokens' => 0, 'total_calls' => 0, '_degraded' => true );
			}
			$total_calls  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$usage_tbl}` WHERE DATE(created_at) BETWEEN '{$from}' AND '{$to}'" );
			$total_tokens = (int) $wpdb->get_var( "SELECT COALESCE(SUM(tokens_prompt + tokens_completion), 0) FROM `{$usage_tbl}` WHERE DATE(created_at) BETWEEN '{$from}' AND '{$to}'" );
			$by_service   = $wpdb->get_results( "SELECT service, COUNT(*) AS calls, SUM(tokens_prompt+tokens_completion) AS tokens FROM `{$usage_tbl}` WHERE DATE(created_at) BETWEEN '{$from}' AND '{$to}' GROUP BY service ORDER BY calls DESC", ARRAY_A );
			$by_day       = $wpdb->get_results( "SELECT DATE(created_at) AS day, COUNT(*) AS calls FROM `{$usage_tbl}` WHERE DATE(created_at) BETWEEN '{$from}' AND '{$to}' GROUP BY day ORDER BY day ASC", ARRAY_A );
			return array(
				'total_tokens' => $total_tokens,
				'total_calls'  => $total_calls,
				'by_service'   => $by_service ?: array(),
				'by_day'       => $by_day ?: array(),
			);
		} );
	}

	/* ================================================================
	 * PHASE 0.35 M-FE.W17 — CRM Module handlers
	 * (Accounts · Biz-Contacts · Tasks · Calendar Events · Documents)
	 * ================================================================ */

	/* ── Accounts ── */

	public static function get_crm_accounts( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl    = BizCity_CRM_DB_Installer_V2::tbl_accounts();
			$where  = array( 'deleted_at IS NULL' );
			$args   = array();
			$status = (string) ( $req->get_param( 'status' ) ?: '' );
			if ( $status !== '' ) { $where[] = $wpdb->prepare( 'status = %s', $status ); }
			$industry = (string) ( $req->get_param( 'industry' ) ?: '' );
			if ( $industry !== '' ) { $where[] = $wpdb->prepare( 'industry = %s', $industry ); }
			$q = (string) ( $req->get_param( 'q' ) ?: '' );
			if ( $q !== '' ) { $where[] = $wpdb->prepare( 'name LIKE %s', '%' . $wpdb->esc_like( $q ) . '%' ); }
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY name ASC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'accounts' => array_map( array( __CLASS__, 'shape_crm_account' ), (array) $rows ),
				'count'    => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_accounts();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Account not found', array( 'status' => 404 ) ); }
			return self::shape_crm_account( $row );
		} );
	}

	public static function post_crm_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$name = trim( (string) ( $body['name'] ?? '' ) );
			if ( $name === '' ) { return new WP_Error( 'invalid_name', '"name" is required', array( 'status' => 422 ) ); }
			$now = current_time( 'mysql' );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_accounts(), array(
				'name'           => $name,
				'industry'       => (string) ( $body['industry'] ?? '' ) ?: null,
				'size'           => (string) ( $body['size'] ?? '' ) ?: null,
				'website'        => (string) ( $body['website'] ?? '' ) ?: null,
				'country'        => (string) ( $body['country'] ?? '' ) ?: null,
				'annual_revenue' => (float) ( $body['annual_revenue'] ?? 0 ),
				'status'         => (string) ( $body['status'] ?? 'active' ),
				'owner_id'       => ( $body['owner_id'] ?? null ) ? (int) $body['owner_id'] : null,
				'created_at'     => $now,
				'updated_at'     => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create account', array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_accounts() . "` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_account( $row );
		} );
	}

	public static function put_crm_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_accounts();
			$id  = (int) $req['id'];
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ) ) ) {
				return new WP_Error( 'not_found', 'Account not found', array( 'status' => 404 ) );
			}
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'name', 'industry', 'size', 'website', 'country', 'status' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (string) $body[ $f ]; }
			}
			if ( isset( $body['annual_revenue'] ) ) { $fields['annual_revenue'] = (float) $body['annual_revenue']; }
			if ( isset( $body['owner_id'] ) ) { $fields['owner_id'] = $body['owner_id'] ? (int) $body['owner_id'] : null; }
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_account( $row );
		} );
	}

	public static function delete_crm_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_accounts();
			$id  = (int) $req['id'];
			$ok  = $wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			return array( 'deleted' => (bool) $ok, 'id' => $id );
		} );
	}

	private static function shape_crm_account( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		return array(
			'id'                  => (int) $r['id'],
			'name'                => (string) $r['name'],
			'industry'            => $r['industry'],
			'size'                => $r['size'],
			'website'             => $r['website'],
			'country'             => $r['country'],
			'annual_revenue'      => (float) $r['annual_revenue'],
			'status'              => (string) $r['status'],
			'owner_id'            => $r['owner_id'] ? (int) $r['owner_id'] : null,
			'opportunities_count' => (int) ( $r['opportunities_count'] ?? 0 ),
			'created_at'          => $r['created_at'],
			'updated_at'          => $r['updated_at'],
		);
	}

	/* ── Biz Contacts ──
	 * PHASE 0.35 M-CRM.M8.W2 — Unified onto canonical tbl_contacts(). The
	 * old tbl_biz_contacts() table is no longer written; reads dual-source
	 * during the deprecation window so existing UI/links keep working
	 * (legacy ids transparently redirect via tbl_contact_id_map()).
	 */

	/** Resolve an incoming `id` (canonical OR legacy biz_contacts id) to canonical contact_id. */
	private static function resolve_canonical_contact_id( int $id ): int {
		if ( $id <= 0 ) { return 0; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND (deleted_at IS NULL)", $id ) );
		if ( $exists ) { return $exists; }
		// Try mapping table (legacy id → canonical).
		$map = BizCity_CRM_DB_Installer_V2::tbl_contact_id_map();
		$mapped = (int) $wpdb->get_var( $wpdb->prepare( "SELECT new_contact_id FROM `{$map}` WHERE old_biz_id=%d", $id ) );
		return $mapped;
	}

	public static function get_crm_contacts( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$view  = sanitize_key( (string) ( $req->get_param( 'view' ) ?: 'active' ) );
			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — keep contacts list on single-table scan (no wp_users JOIN) for multishard safety.
			$where = array( $view === 'archived' ? '(deleted_at IS NOT NULL)' : '(deleted_at IS NULL)' );
			$include_empty = (int) ( $req->get_param( 'include_empty' ) ?: 0 );
			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — hide ghost contacts (all identity fields empty) by default.
			if ( ! $include_empty ) {
				$where[] = "(TRIM(COALESCE(name,'')) <> '' OR TRIM(COALESCE(first_name,'')) <> '' OR TRIM(COALESCE(last_name,'')) <> '' OR TRIM(COALESCE(email,'')) <> '' OR TRIM(COALESCE(phone,'')) <> '')";
			}
			$aid   = $req->get_param( 'account_id' );
			if ( $aid !== null ) { $where[] = $wpdb->prepare( 'account_id = %d', (int) $aid ); }
			$source = sanitize_text_field( (string) ( $req->get_param( 'source' ) ?: '' ) );
			$cf7_form_id = (int) ( $req->get_param( 'cf7_form_id' ) ?: 0 );
			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — support FE source/form filters without any cross-table JOIN.
			if ( $cf7_form_id > 0 ) {
				$where[] = $wpdb->prepare( 'acquisition_source = %s', 'cf7:' . $cf7_form_id );
			} elseif ( $source !== '' ) {
				if ( $source === 'cf7' ) {
					$where[] = "(acquisition_source = 'cf7' OR acquisition_source LIKE 'cf7:%')";
				} elseif ( $source === 'inbox' ) {
					$where[] = "acquisition_source LIKE 'inbox:%'";
				} else {
					$where[] = $wpdb->prepare( 'acquisition_source = %s', $source );
				}
			}
			$q = (string) ( $req->get_param( 'q' ) ?: '' );
			if ( $q !== '' ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = $wpdb->prepare( '(name LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)', $like, $like, $like, $like, $like );
			}
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — prioritize meaningful/recent contacts; avoid blank-name rows dominating first page.
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'contacts' => array_map( array( __CLASS__, 'shape_crm_contact' ), (array) $rows ),
				'count'    => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$id  = self::resolve_canonical_contact_id( (int) $req['id'] );
			if ( ! $id ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND (deleted_at IS NULL)", $id ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			return self::shape_crm_contact( $row );
		} );
	}

	public static function post_crm_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$now  = current_time( 'mysql' );
			$tags = isset( $body['tags'] ) && is_array( $body['tags'] ) ? wp_json_encode( $body['tags'] ) : null;
			$first = (string) ( $body['first_name'] ?? '' );
			$last  = (string) ( $body['last_name'] ?? '' );
			$name  = trim( $first . ' ' . $last );
			if ( $name === '' ) { $name = (string) ( $body['name'] ?? '' ); }

			// Dedupe: if email or phone matches an existing canonical contact, return it.
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$email = (string) ( $body['email'] ?? '' );
			$phone = (string) ( $body['phone'] ?? '' );
			$existing = 0;
			if ( $email !== '' ) {
				$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE email=%s AND (deleted_at IS NULL) LIMIT 1", $email ) );
			}
			if ( ! $existing && $phone !== '' ) {
				$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE phone=%s AND (deleted_at IS NULL) LIMIT 1", $phone ) );
			}
			if ( $existing ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $existing ), ARRAY_A );
				return self::shape_crm_contact( $row );
			}

			$wpdb->insert( $tbl, array(
				'name'                  => $name,
				'first_name'            => $first ?: null,
				'last_name'             => $last  ?: null,
				'email'                 => $email ?: null,
				'phone'                 => $phone ?: null,
				'title'                 => (string) ( $body['title'] ?? '' ) ?: null,
				'account_id'            => ( $body['account_id'] ?? null ) ? (int) $body['account_id'] : null,
				'owner_id'              => ( $body['owner_id'] ?? null )   ? (int) $body['owner_id']   : null,
				'tags_json'             => $tags,
				'additional_attributes' => isset( $body['additional_attributes'] ) ? wp_json_encode( $body['additional_attributes'] ) : null,
				'acquisition_source'    => (string) ( $body['acquisition_source'] ?? 'crm_manual' ),
				'created_at'            => $now,
				'updated_at'            => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create contact', array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			do_action( 'bizcity_crm_contact_saved', $id, $row );
			return self::shape_crm_contact( $row );
		} );
	}

	public static function put_crm_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$id  = self::resolve_canonical_contact_id( (int) $req['id'] );
			if ( ! $id ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'title' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (string) $body[ $f ]; }
			}
			if ( isset( $body['account_id'] ) ) { $fields['account_id'] = $body['account_id'] ? (int) $body['account_id'] : null; }
			if ( isset( $body['owner_id'] ) )   { $fields['owner_id']   = $body['owner_id']   ? (int) $body['owner_id']   : null; }
			if ( isset( $body['tags'] ) && is_array( $body['tags'] ) ) { $fields['tags_json'] = wp_json_encode( $body['tags'] ); }
			// Keep `name` denormalized for legacy readers / search.
			if ( isset( $fields['first_name'] ) || isset( $fields['last_name'] ) ) {
				$current = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name, name FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
				$fn = $fields['first_name'] ?? (string) ( $current['first_name'] ?? '' );
				$ln = $fields['last_name']  ?? (string) ( $current['last_name']  ?? '' );
				$composed = trim( $fn . ' ' . $ln );
				if ( $composed !== '' ) { $fields['name'] = $composed; }
			} elseif ( isset( $body['name'] ) ) {
				$fields['name'] = (string) $body['name'];
			}
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			do_action( 'bizcity_crm_contact_saved', $id, $row );
			return self::shape_crm_contact( $row );
		} );
	}

	public static function delete_crm_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$id  = self::resolve_canonical_contact_id( (int) $req['id'] );
			if ( ! $id ) { return array( 'deleted' => false, 'id' => (int) $req['id'] ); }
			$ok  = $wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			do_action( 'bizcity_crm_contact_deleted', $id );
			return array( 'deleted' => (bool) $ok, 'id' => $id );
		} );
	}

	private static function shape_crm_contact( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		$tags = json_decode( (string) ( $r['tags_json'] ?? '' ), true );
		$attrs = json_decode( (string) ( $r['additional_attributes'] ?? '' ), true );
		$first = (string) ( $r['first_name'] ?? '' );
		$last  = (string) ( $r['last_name']  ?? '' );
		$name  = trim( $first . ' ' . $last );
		if ( $name === '' ) { $name = (string) ( $r['name'] ?? '' ); }
		// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — fallback for legacy/ingestor data stored in additional_attributes.
		if ( $name === '' && is_array( $attrs ) ) {
			$name = trim( (string) ( $attrs['display_name'] ?? $attrs['name'] ?? $attrs['full_name'] ?? $attrs['from_user_name'] ?? '' ) );
		}
		$email = (string) ( $r['email'] ?? '' );
		$phone = (string) ( $r['phone'] ?? '' );
		// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — avoid empty columns on Contacts UI when old records keep phone/email in attrs.
		if ( $email === '' && is_array( $attrs ) ) {
			$email = trim( (string) ( $attrs['email'] ?? '' ) );
		}
		if ( $phone === '' && is_array( $attrs ) ) {
			$phone = trim( (string) ( $attrs['phone'] ?? $attrs['phone_number'] ?? $attrs['mobile'] ?? '' ) );
		}
		return array(
			'id'         => (int) $r['id'],
			'first_name' => $first,
			'last_name'  => $last,
			'name'       => $name,
			'email'      => $email !== '' ? $email : null,
			'phone'      => $phone !== '' ? $phone : null,
			'title'      => $r['title'] ?? null,
			'account_id' => isset( $r['account_id'] ) && $r['account_id'] ? (int) $r['account_id'] : null,
			'owner_id'   => isset( $r['owner_id']   ) && $r['owner_id']   ? (int) $r['owner_id']   : null,
			'wp_user_id' => isset( $r['wp_user_id'] ) && $r['wp_user_id'] ? (int) $r['wp_user_id'] : null,
			'tags'       => is_array( $tags ) ? $tags : array(),
			'additional_attributes' => is_array( $attrs ) ? $attrs : array(),
			'created_at' => $r['created_at'] ?? null,
			'updated_at' => $r['updated_at'] ?? null,
		);
	}

	/**
	 * GET /crm-contacts/{id}/woo-orders — PHASE 0.35 M-CRM.M8.W6.2.
	 * Delegates to the default Order Adapter (Woo + bank QR concrete impl).
	 */
	public static function get_crm_contact_woo_orders( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id  = self::resolve_canonical_contact_id( (int) $req['id'] );
			if ( ! $id ) { return array( 'orders' => array(), 'wc_active' => false ); }
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ), ARRAY_A );
			if ( ! $row ) { return array( 'orders' => array(), 'wc_active' => false ); }
			$shaped = self::shape_crm_contact( $row );
			if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
				return array( 'orders' => array(), 'wc_active' => false );
			}
			if ( ! class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ) {
				return array( 'orders' => array(), 'wc_active' => true, 'error' => 'order_adapter_registry_missing' );
			}
			$a = BizCity_CRM_Order_Adapter_Registry::default_adapter();
			if ( ! $a ) { return array( 'orders' => array(), 'wc_active' => true, 'error' => 'no_adapter' ); }
			$limit = max( 1, min( 50, (int) ( $req->get_param( 'limit' ) ?: 10 ) ) );
			return array(
				'wc_active' => true,
				'orders'    => $a->list_orders_for_contact( $shaped, $limit ),
			);
		} );
	}

	/* ── Tasks ── */

	public static function get_crm_tasks( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$where = array( 'deleted_at IS NULL' );
			$status = (string) ( $req->get_param( 'status' ) ?: '' );
			if ( $status !== '' ) { $where[] = $wpdb->prepare( 'status = %s', $status ); }
			$aid = $req->get_param( 'assignee_id' );
			if ( $aid !== null ) { $where[] = $wpdb->prepare( 'assignee_id = %d', (int) $aid ); }
			$q = (string) ( $req->get_param( 'q' ) ?: '' );
			if ( $q !== '' ) { $where[] = $wpdb->prepare( 'title LIKE %s', '%' . $wpdb->esc_like( $q ) . '%' ); }
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY due_date ASC, id DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'tasks' => array_map( array( __CLASS__, 'shape_crm_task' ), (array) $rows ),
				'count' => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_task( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Task not found', array( 'status' => 404 ) ); }
			return self::shape_crm_task( $row );
		} );
	}

	public static function post_crm_task( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$title = trim( (string) ( $body['title'] ?? '' ) );
			if ( $title === '' ) { return new WP_Error( 'invalid_title', '"title" is required', array( 'status' => 422 ) ); }
			$now = current_time( 'mysql' );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_tasks(), array(
				'title'               => $title,
				'status'              => (string) ( $body['status'] ?? 'open' ),
				'priority'            => (string) ( $body['priority'] ?? 'medium' ),
				'due_date'            => (string) ( $body['due_date'] ?? '' ) ?: null,
				'assignee_id'         => ( $body['assignee_id'] ?? null ) ? (int) $body['assignee_id'] : null,
				'related_entity_type' => (string) ( $body['related_entity_type'] ?? '' ) ?: null,
				'related_entity_id'   => ( $body['related_entity_id'] ?? null ) ? (int) $body['related_entity_id'] : null,
				'notes'               => (string) ( $body['notes'] ?? '' ) ?: null,
				'completed'           => 0,
				'created_by'          => get_current_user_id() ?: null,
				'created_at'          => $now,
				'updated_at'          => $now,
			) );
			$id  = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create task', array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_crm_tasks() . "` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_task( $row );
		} );
	}

	public static function put_crm_task( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$id  = (int) $req['id'];
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ) ) ) {
				return new WP_Error( 'not_found', 'Task not found', array( 'status' => 404 ) );
			}
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'title', 'status', 'priority', 'due_date', 'notes' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (string) $body[ $f ]; }
			}
			if ( isset( $body['assignee_id'] ) ) { $fields['assignee_id'] = $body['assignee_id'] ? (int) $body['assignee_id'] : null; }
			if ( isset( $body['completed'] ) ) {
				$fields['completed']    = (int) (bool) $body['completed'];
				$fields['completed_at'] = $fields['completed'] ? current_time( 'mysql' ) : null;
				if ( $fields['completed'] && ! isset( $fields['status'] ) ) { $fields['status'] = 'done'; }
			}
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_task( $row );
		} );
	}

	public static function delete_crm_task( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$id  = (int) $req['id'];
			$ok  = $wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			return array( 'deleted' => (bool) $ok, 'id' => $id );
		} );
	}

	private static function shape_crm_task( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		return array(
			'id'                  => (int) $r['id'],
			'title'               => (string) $r['title'],
			'status'              => (string) $r['status'],
			'priority'            => (string) $r['priority'],
			'due_date'            => $r['due_date'],
			'assignee_id'         => $r['assignee_id'] ? (int) $r['assignee_id'] : null,
			'related_entity_type' => $r['related_entity_type'],
			'related_entity_id'   => $r['related_entity_id'] ? (int) $r['related_entity_id'] : null,
			'notes'               => $r['notes'],
			'completed'           => (bool) (int) $r['completed'],
			'completed_at'        => $r['completed_at'],
			'created_at'          => $r['created_at'],
			'updated_at'          => $r['updated_at'],
		);
	}

	/* ── Calendar Events — PROXY to BizCity_Scheduler_Manager (M-CRM.M12 v2 phase 2.5)
	 *
	 * As of 2026-05-13 the underlying table `wp_bizcity_crm_events` is owned by
	 * core/scheduler (renamed from `wp_bizcity_scheduler_events`, schema v3).
	 * These handlers preserve the legacy FE shape (unix timestamps, attendees
	 * array, `type` field) by mapping through `BizCity_Scheduler_Manager`.
	 * Mark with `X-Deprecated` header — FE is being migrated to call
	 * `bizcity-scheduler/v1/events` directly in M-CRM.M12 v2 phase 6.
	 * ── */

	private static function shape_crm_event( $r ): ?array {
		if ( ! $r ) { return null; }
		$r = is_object( $r ) ? (array) $r : (array) $r;

		$meta = isset( $r['metadata'] ) ? json_decode( (string) $r['metadata'], true ) : null;
		if ( ! is_array( $meta ) ) { $meta = array(); }

		$start_unix = ! empty( $r['start_at'] ) ? (int) strtotime( (string) $r['start_at'] ) : 0;
		$end_unix   = ! empty( $r['end_at'] )   ? (int) strtotime( (string) $r['end_at'] )   : 0;

		return array(
			'id'                  => (int) $r['id'],
			'title'               => (string) $r['title'],
			'type'                => (string) ( $r['event_type'] ?? 'meeting' ),
			'start_at'            => $start_unix,
			'end_at'              => $end_unix,
			'attendees'           => isset( $meta['attendees'] ) && is_array( $meta['attendees'] ) ? $meta['attendees'] : array(),
			'related_entity_type' => $meta['related_entity_type'] ?? null,
			'related_entity_id'   => isset( $meta['related_entity_id'] ) ? (int) $meta['related_entity_id'] : null,
			'created_by'          => ! empty( $r['user_id'] ) ? (int) $r['user_id'] : null,
			'created_at'          => $r['created_at'] ?? null,
		);
	}

	public static function get_crm_events( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
				return array( 'events' => array(), 'count' => 0 );
			}
			$from_unix = (int) ( $req->get_param( 'from' ) ?: ( time() - 30 * DAY_IN_SECONDS ) );
			$to_unix   = (int) ( $req->get_param( 'to' )   ?: ( time() + 90 * DAY_IN_SECONDS ) );
			$user_id   = get_current_user_id();
			$rows = BizCity_Scheduler_Manager::instance()->get_events(
				$user_id,
				gmdate( 'Y-m-d H:i:s', $from_unix ),
				gmdate( 'Y-m-d H:i:s', $to_unix )
			);
			return array(
				'events' => array_values( array_filter( array_map( array( __CLASS__, 'shape_crm_event' ), $rows ) ) ),
				'count'  => count( $rows ),
			);
		} );
	}

	public static function get_crm_event( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
				return new WP_Error( 'scheduler_unavailable', 'Scheduler manager not loaded', array( 'status' => 503 ) );
			}
			$row = BizCity_Scheduler_Manager::instance()->get_event( (int) $req['id'] );
			if ( ! $row || is_wp_error( $row ) ) { return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) ); }
			return self::shape_crm_event( $row );
		} );
	}

	public static function post_crm_event( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
				return new WP_Error( 'scheduler_unavailable', 'Scheduler manager not loaded', array( 'status' => 503 ) );
			}
			$body  = self::extract_json_body( $req );
			$title = trim( (string) ( $body['title'] ?? '' ) );
			if ( $title === '' ) { return new WP_Error( 'invalid_title', '"title" is required', array( 'status' => 422 ) ); }
			$start = (int) ( $body['start_at'] ?? 0 );
			$end   = (int) ( $body['end_at']   ?? 0 );
			if ( $start <= 0 || $end <= 0 ) {
				return new WP_Error( 'invalid_times', '"start_at" and "end_at" (Unix timestamps) are required', array( 'status' => 422 ) );
			}

			$metadata = array();
			if ( isset( $body['attendees'] ) && is_array( $body['attendees'] ) ) {
				$metadata['attendees'] = $body['attendees'];
			}
			if ( ! empty( $body['related_entity_type'] ) ) {
				$metadata['related_entity_type'] = (string) $body['related_entity_type'];
			}
			if ( ! empty( $body['related_entity_id'] ) ) {
				$metadata['related_entity_id'] = (int) $body['related_entity_id'];
			}

			$id_or_err = BizCity_Scheduler_Manager::instance()->create_event( array(
				'title'      => $title,
				'start_at'   => gmdate( 'Y-m-d H:i:s', $start ),
				'end_at'     => gmdate( 'Y-m-d H:i:s', $end ),
				'event_type' => (string) ( $body['type'] ?? 'meeting' ),
				'source'     => 'crm_calendar',
				'metadata'   => $metadata,
				'user_id'    => get_current_user_id() ?: null,
			) );
			if ( is_wp_error( $id_or_err ) ) { return $id_or_err; }
			$row = BizCity_Scheduler_Manager::instance()->get_event( (int) $id_or_err );
			return self::shape_crm_event( $row );
		} );
	}

	public static function put_crm_event( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
				return new WP_Error( 'scheduler_unavailable', 'Scheduler manager not loaded', array( 'status' => 503 ) );
			}
			$id   = (int) $req['id'];
			$mgr  = BizCity_Scheduler_Manager::instance();
			$prev = $mgr->get_event( $id );
			if ( ! $prev || is_wp_error( $prev ) ) {
				return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
			}

			$body   = self::extract_json_body( $req );
			$update = array();
			if ( isset( $body['title'] ) ) { $update['title'] = (string) $body['title']; }
			if ( isset( $body['type'] ) )  { $update['event_type'] = (string) $body['type']; }
			if ( isset( $body['start_at'] ) ) { $update['start_at'] = gmdate( 'Y-m-d H:i:s', (int) $body['start_at'] ); }
			if ( isset( $body['end_at'] ) )   { $update['end_at']   = gmdate( 'Y-m-d H:i:s', (int) $body['end_at'] ); }

			$prev_arr = is_object( $prev ) ? (array) $prev : (array) $prev;
			$prev_meta = isset( $prev_arr['metadata'] ) ? json_decode( (string) $prev_arr['metadata'], true ) : array();
			if ( ! is_array( $prev_meta ) ) { $prev_meta = array(); }

			if ( isset( $body['attendees'] ) && is_array( $body['attendees'] ) ) {
				$prev_meta['attendees'] = $body['attendees'];
			}
			if ( array_key_exists( 'related_entity_type', $body ) ) {
				$prev_meta['related_entity_type'] = (string) $body['related_entity_type'];
			}
			if ( array_key_exists( 'related_entity_id', $body ) ) {
				$prev_meta['related_entity_id'] = $body['related_entity_id'] ? (int) $body['related_entity_id'] : null;
			}
			$update['metadata'] = $prev_meta;

			$result = $mgr->update_event( $id, $update );
			if ( is_wp_error( $result ) ) { return $result; }
			return self::shape_crm_event( $mgr->get_event( $id ) );
		} );
	}

	public static function delete_crm_event( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
				return new WP_Error( 'scheduler_unavailable', 'Scheduler manager not loaded', array( 'status' => 503 ) );
			}
			$id  = (int) $req['id'];
			$res = BizCity_Scheduler_Manager::instance()->delete_event( $id );
			return array( 'deleted' => ! is_wp_error( $res ) && $res, 'id' => $id );
		} );
	}

	/* ── Documents ── */

	public static function get_crm_documents( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_documents();
			$where = array( '1=1' );
			$et    = (string) ( $req->get_param( 'related_entity_type' ) ?: '' );
			$eid   = $req->get_param( 'related_entity_id' );
			if ( $et !== '' ) { $where[] = $wpdb->prepare( 'related_entity_type = %s', $et ); }
			if ( $eid !== null ) { $where[] = $wpdb->prepare( 'related_entity_id = %d', (int) $eid ); }
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY uploaded_at DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'documents' => array_map( array( __CLASS__, 'shape_crm_document' ), (array) $rows ),
				'count'     => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_document( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_documents();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Document not found', array( 'status' => 404 ) ); }
			return self::shape_crm_document( $row );
		} );
	}

	public static function post_crm_document( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$name = trim( (string) ( $body['name'] ?? '' ) );
			if ( $name === '' ) { return new WP_Error( 'invalid_name', '"name" is required', array( 'status' => 422 ) ); }
			$now = current_time( 'mysql' );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_documents(), array(
				'name'                => $name,
				'type'                => (string) ( $body['type'] ?? 'file' ),
				'size_bytes'          => (int) ( $body['size_bytes'] ?? 0 ),
				'path'                => (string) ( $body['path'] ?? '' ),
				'uploaded_by'         => get_current_user_id() ?: null,
				'related_entity_type' => (string) ( $body['related_entity_type'] ?? '' ) ?: null,
				'related_entity_id'   => ( $body['related_entity_id'] ?? null ) ? (int) $body['related_entity_id'] : null,
				'uploaded_at'         => $now,
			) );
			$id  = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create document record', array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_crm_documents() . "` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_document( $row );
		} );
	}

	public static function delete_crm_document( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_documents();
			$id  = (int) $req['id'];
			$ok  = $wpdb->delete( $tbl, array( 'id' => $id ) );
			return array( 'deleted' => (bool) $ok, 'id' => $id );
		} );
	}

	private static function shape_crm_document( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		return array(
			'id'                  => (int) $r['id'],
			'name'                => (string) $r['name'],
			'type'                => (string) $r['type'],
			'size_bytes'          => (int) $r['size_bytes'],
			'path'                => (string) $r['path'],
			'uploaded_by'         => $r['uploaded_by'] ? (int) $r['uploaded_by'] : null,
			'related_entity_type' => $r['related_entity_type'],
			'related_entity_id'   => $r['related_entity_id'] ? (int) $r['related_entity_id'] : null,
			'uploaded_at'         => $r['uploaded_at'],
		);
	}

	/* ============ M-CRM.M1 — Sales Pipeline handlers ============ */

	// [2026-06-07 Johnny Chu] PHASE-0.40 G6.4 — Notes Doc CRUD handlers

	public static function get_crm_notes_doc( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl    = BizCity_CRM_DB_Installer_V2::tbl_notes_doc();
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
				return array( 'notes' => array(), '_degraded' => true );
			}
			$where  = array( 'deleted_at IS NULL' );
			$folder = (string) ( $req->get_param( 'folder' ) ?: '' );
			$q      = (string) ( $req->get_param( 'q' ) ?: '' );
			$pinned = $req->get_param( 'pinned' );
			if ( $folder !== '' ) { $where[] = $wpdb->prepare( 'folder = %s', $folder ); }
			if ( $q !== '' )      { $where[] = $wpdb->prepare( '(title LIKE %s OR content LIKE %s)', '%' . $wpdb->esc_like( $q ) . '%', '%' . $wpdb->esc_like( $q ) . '%' ); }
			if ( $pinned !== null ) { $where[] = 'pinned = ' . ( $pinned ? '1' : '0' ); }
			$limit  = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY pinned DESC, updated_at DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array( 'notes' => array_map( array( __CLASS__, 'shape_crm_note_doc' ), $rows ?: array() ) );
		} );
	}

	public static function get_crm_note_doc( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_notes_doc();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'bizcity_crm_note_doc_not_found', 'note not found', array( 'status' => 404 ) ); }
			return self::shape_crm_note_doc( $row );
		} );
	}

	public static function post_crm_notes_doc( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_notes_doc();
			$title = trim( (string) ( $req->get_param( 'title' ) ?: '' ) );
			if ( $title === '' ) { return new WP_Error( 'invalid_param', 'title required', array( 'status' => 400 ) ); }
			$now = current_time( 'mysql' );
			$wpdb->insert( $tbl, array(
				'folder'      => sanitize_text_field( (string) ( $req->get_param( 'folder' ) ?: '' ) ),
				'title'       => $title,
				'content'     => wp_kses_post( (string) ( $req->get_param( 'content' ) ?: '' ) ),
				'tags_json'   => $req->get_param( 'tags' ) ? wp_json_encode( (array) $req->get_param( 'tags' ) ) : null,
				'share_scope' => sanitize_text_field( (string) ( $req->get_param( 'share_scope' ) ?: 'private' ) ),
				'owner_id'    => get_current_user_id() ?: null,
				'pinned'      => ! empty( $req->get_param( 'pinned' ) ) ? 1 : 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'bizcity_crm_note_doc_db', $wpdb->last_error ?: 'insert failed', array( 'status' => 500 ) ); }
			$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_note_doc( $fresh );
		} );
	}

	public static function put_crm_note_doc( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_notes_doc();
			$id  = (int) $req['id'];
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ) );
			if ( ! $row ) { return new WP_Error( 'bizcity_crm_note_doc_not_found', 'note not found', array( 'status' => 404 ) ); }
			$patch = array( 'updated_at' => current_time( 'mysql' ) );
			if ( $req->get_param( 'title' )   !== null ) { $patch['title']   = trim( (string) $req->get_param( 'title' ) ); }
			if ( $req->get_param( 'content' ) !== null ) { $patch['content'] = wp_kses_post( (string) $req->get_param( 'content' ) ); }
			if ( $req->get_param( 'folder' )  !== null ) { $patch['folder']  = sanitize_text_field( (string) $req->get_param( 'folder' ) ); }
			if ( $req->get_param( 'tags' )    !== null ) { $patch['tags_json'] = wp_json_encode( (array) $req->get_param( 'tags' ) ); }
			if ( $req->get_param( 'pinned' )  !== null ) { $patch['pinned']  = ! empty( $req->get_param( 'pinned' ) ) ? 1 : 0; }
			if ( $req->get_param( 'share_scope' ) !== null ) { $patch['share_scope'] = sanitize_text_field( (string) $req->get_param( 'share_scope' ) ); }
			$wpdb->update( $tbl, $patch, array( 'id' => $id ) );
			$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_note_doc( $fresh );
		} );
	}

	public static function delete_crm_note_doc( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_notes_doc();
			$id  = (int) $req['id'];
			$wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			return array( 'deleted' => true, 'id' => $id );
		} );
	}

	/**
	 * @return array
	 */
	/**
	 * [2026-06-07 Johnny Chu] PHASE-0.40 fix — Validate date string to prevent SQL injection (OWASP A03).
	 * Only accepts YYYY-MM-DD format. Returns $fallback if invalid.
	 *
	 * @param mixed  $val      Raw date value (user-supplied).
	 * @param string $fallback Safe fallback date.
	 * @return string
	 */
	private static function safe_date( $val, $fallback ) {
		if ( $val && is_string( $val ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
			return $val;
		}
		return $fallback;
	}

	private static function shape_crm_note_doc( array $r ) {
		return array(
			'id'          => (int) $r['id'],
			'folder'      => (string) $r['folder'],
			'title'       => (string) $r['title'],
			'content'     => (string) $r['content'],
			'tags'        => ( isset( $r['tags_json'] ) && $r['tags_json'] ) ? ( json_decode( (string) $r['tags_json'], true ) ?: array() ) : array(),
			'share_scope' => (string) $r['share_scope'],
			'owner_id'    => $r['owner_id'] ? (int) $r['owner_id'] : null,
			'pinned'      => ! empty( $r['pinned'] ),
			'created_at'  => $r['created_at'],
			'updated_at'  => $r['updated_at'],
		);
	}

	/* ── Leads ── */

	public static function get_crm_leads( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
			$where = array( 'deleted_at IS NULL' );
			$status = (string) ( $req->get_param( 'status' ) ?: '' );
			$owner  = $req->get_param( 'owner_id' );
			$contact = $req->get_param( 'contact_id' );
			$q      = (string) ( $req->get_param( 'q' ) ?: '' );
			if ( $status !== '' ) { $where[] = $wpdb->prepare( 'status = %s', $status ); }
			if ( $owner  !== null ) { $where[] = $wpdb->prepare( 'owner_id = %d', (int) $owner ); }
			if ( $contact !== null ) { $where[] = $wpdb->prepare( 'contact_id = %d', (int) $contact ); }
			if ( $q !== '' ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = $wpdb->prepare( '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s OR company LIKE %s)', $like, $like, $like, $like, $like );
			}
			$limit  = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'leads' => array_map( array( __CLASS__, 'shape_crm_lead' ), (array) $rows ),
				'count' => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_lead( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Lead not found', array( 'status' => 404 ) ); }
			return self::shape_crm_lead( $row );
		} );
	}

	/**
	 * POST /bizcity-crm/v1/sales-sync
	 *
	 * Run the Customer Source adapter sync — pulls fresh prospects from every
	 * registered BizCity_CRM_Customer_Source into the Sales Pipeline.
	 * Idempotent: each (source, source_ref) maps to exactly one opportunity.
	 *
	 * @since 1.16.0
	 */
	public static function post_sales_sync( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Pipeline_Sync' ) ) {
				return new WP_Error( 'pipeline_sync_unavailable', 'BizCity_CRM_Pipeline_Sync class not loaded.', array( 'status' => 500 ) );
			}
			$body = self::extract_json_body( $req );
			$args = array(
				'limit'    => isset( $body['limit'] )    ? (int) $body['limit']    : (int) $req->get_param( 'limit' ),
				'since_ts' => isset( $body['since_ts'] ) ? (int) $body['since_ts'] : (int) $req->get_param( 'since_ts' ),
			);
			if ( ! empty( $body['sources'] ) && is_array( $body['sources'] ) ) {
				$args['sources'] = array_map( 'strval', $body['sources'] );
			} elseif ( $req->get_param( 'sources' ) ) {
				$sp = $req->get_param( 'sources' );
				$args['sources'] = is_array( $sp ) ? array_map( 'strval', $sp ) : array( (string) $sp );
			}
			$args['limit']    = $args['limit']    > 0 ? $args['limit']    : 200;
			$args['since_ts'] = $args['since_ts'] > 0 ? $args['since_ts'] : null;
			return BizCity_CRM_Pipeline_Sync::run( $args );
		} );
	}

	/**
	 * GET /bizcity-crm/v1/sales-sync
	 * Lightweight status: last run timestamp + registered sources.
	 */
	public static function get_sales_sync_status( WP_REST_Request $req ) {
		return self::wrap( static function () {
			$last = (int) get_option( BizCity_CRM_Pipeline_Sync::OPT_LAST_RUN, 0 );
			$srcs = array();
			if ( class_exists( 'BizCity_CRM_Customer_Source_Registry' ) ) {
				foreach ( BizCity_CRM_Customer_Source_Registry::all() as $code => $src ) {
					$srcs[] = array( 'code' => $code, 'label' => $src->label() );
				}
			}
			return array(
				'last_run_ts'  => $last,
				'last_run_iso' => $last ? gmdate( 'c', $last ) : null,
				'sources'      => $srcs,
			);
		} );
	}

	public static function post_crm_lead( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$first = trim( (string) ( $body['first_name'] ?? '' ) );
			$last  = trim( (string) ( $body['last_name']  ?? '' ) );
			if ( $first === '' && $last === '' && empty( $body['company'] ) ) {
				return new WP_Error( 'invalid_lead', 'first_name/last_name or company is required', array( 'status' => 422 ) );
			}
			$now = current_time( 'mysql' );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_leads(), array(
				'first_name'  => $first,
				'last_name'   => $last,
				'email'       => isset( $body['email'] )   ? (string) $body['email']   : null,
				'phone'       => isset( $body['phone'] )   ? (string) $body['phone']   : null,
				'company'     => isset( $body['company'] ) ? (string) $body['company'] : null,
				'title'       => isset( $body['title'] )   ? (string) $body['title']   : null,
				'source'      => isset( $body['source'] )  ? (string) $body['source']  : null,
				'status'      => (string) ( $body['status'] ?? 'new' ),
				'rating'      => isset( $body['rating'] )  ? (string) $body['rating']  : null,
				'owner_id'    => isset( $body['owner_id'] )  ? (int) $body['owner_id'] : ( get_current_user_id() ?: null ),
				'account_id'  => isset( $body['account_id'] ) ? (int) $body['account_id'] : null,
				'contact_id'  => isset( $body['contact_id'] ) ? (int) $body['contact_id'] : null,
				'notes'       => isset( $body['notes'] ) ? (string) $body['notes'] : null,
				'custom_json' => isset( $body['custom'] ) && is_array( $body['custom'] ) ? wp_json_encode( $body['custom'] ) : null,
				'created_by'  => get_current_user_id() ?: null,
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create lead', array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_crm_leads() . "` WHERE id=%d", $id ), ARRAY_A );

			// M-CRM.M1.W3 — audit log 'created'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log_created( 'crm_lead', $id, (array) $row );
			}

			// PHASE 0.37.2 — Fire bizcity_crm_lead_created so Email Automation rules can react.
			$_name = trim( ( (string) ( $row['first_name'] ?? '' ) ) . ' ' . ( (string) ( $row['last_name'] ?? '' ) ) );
			do_action( 'bizcity_crm_lead_created', $id, array(
				'id'     => $id,
				'name'   => $_name ?: ( (string) ( $row['email'] ?? $row['phone'] ?? '' ) ),
				'email'  => (string) ( $row['email'] ?? '' ),
				'phone'  => (string) ( $row['phone'] ?? '' ),
				'source' => (string) ( $row['source'] ?? 'manual' ),
			) );

			return self::shape_crm_lead( $row );
		} );
	}

	public static function put_crm_lead( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
			$id  = (int) $req['id'];
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ) ) ) {
				return new WP_Error( 'not_found', 'Lead not found', array( 'status' => 404 ) );
			}
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'company', 'title', 'source', 'status', 'rating', 'notes' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = is_string( $body[ $f ] ) ? (string) $body[ $f ] : $body[ $f ]; }
			}
			foreach ( array( 'owner_id', 'account_id', 'contact_id' ) as $f ) {
				if ( array_key_exists( $f, $body ) ) { $fields[ $f ] = $body[ $f ] === null ? null : (int) $body[ $f ]; }
			}
			if ( isset( $body['custom'] ) && is_array( $body['custom'] ) ) {
				$fields['custom_json'] = wp_json_encode( $body['custom'] );
			}
			$before_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			// M-CRM.M1.W3 — audit log 'updated'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) && $before_row ) {
				BizCity_CRM_Audit_Log::log_updated( 'crm_lead', $id, (array) $before_row, (array) $row );
			}
			return self::shape_crm_lead( $row );
		} );
	}

	public static function delete_crm_lead( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
			$id  = (int) $req['id'];
			$wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			// M-CRM.M1.W3 — audit log 'deleted'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log_deleted( 'crm_lead', $id );
			}
			return array( 'deleted' => true, 'id' => $id );
		} );
	}

	/**
	 * Convert a lead → Account (optional) + Contact (optional) + Opportunity.
	 *
	 * Body: { create_account?:bool, create_contact?:bool, opportunity:{ name, amount, stage, close_date } }
	 */
	public static function post_crm_lead_convert( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl_leads = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
			$id   = (int) $req['id'];
			$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl_leads}` WHERE id=%d AND deleted_at IS NULL", $id ), ARRAY_A );
			if ( ! $lead ) { return new WP_Error( 'not_found', 'Lead not found', array( 'status' => 404 ) ); }
			$body = self::extract_json_body( $req );
			$now  = current_time( 'mysql' );

			$account_id = $lead['account_id'] ? (int) $lead['account_id'] : null;
			if ( ! $account_id && ! empty( $body['create_account'] ) && ! empty( $lead['company'] ) ) {
				$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_accounts(), array(
					'name'       => (string) $lead['company'],
					'owner_id'   => (int) $lead['owner_id'] ?: null,
					'created_at' => $now,
					'updated_at' => $now,
				) );
				$account_id = (int) $wpdb->insert_id ?: null;
			}

			$contact_id = $lead['contact_id'] ? (int) $lead['contact_id'] : null;
			if ( ! $contact_id && ! empty( $body['create_contact'] ) ) {
				// PHASE 0.35 M-CRM.M8.W2 — write to canonical contacts (not legacy biz_contacts).
				$first  = (string) $lead['first_name'];
				$last   = (string) $lead['last_name'];
				$cname  = trim( $first . ' ' . $last );
				$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_contacts(), array(
					'name'        => $cname,
					'first_name'  => $first ?: null,
					'last_name'   => $last  ?: null,
					'email'       => $lead['email'],
					'phone'       => $lead['phone'],
					'title'       => $lead['title'],
					'account_id'  => $account_id,
					'owner_id'    => (int) $lead['owner_id'] ?: null,
					'acquisition_source' => 'lead_convert',
					'created_at'  => $now,
					'updated_at'  => $now,
				) );
				$contact_id = (int) $wpdb->insert_id ?: null;
			}

			$opp_in = is_array( $body['opportunity'] ?? null ) ? $body['opportunity'] : array();
			$opp_name = trim( (string) ( $opp_in['name'] ?? ( $lead['company'] ?: ( $lead['first_name'] . ' ' . $lead['last_name'] ) ) ) );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities(), array(
				'name'        => $opp_name ?: 'New Opportunity',
				'account_id'  => $account_id,
				'contact_id'  => $contact_id,
				'lead_id'     => $id,
				'owner_id'    => (int) $lead['owner_id'] ?: ( get_current_user_id() ?: null ),
				'stage'       => (string) ( $opp_in['stage'] ?? 'qualification' ),
				'status'      => 'open',
				'amount'      => isset( $opp_in['amount'] ) ? (float) $opp_in['amount'] : 0,
				'currency'    => (string) ( $opp_in['currency'] ?? 'VND' ),
				'probability' => isset( $opp_in['probability'] ) ? max( 0, min( 100, (int) $opp_in['probability'] ) ) : 10,
				'close_date'  => $opp_in['close_date'] ?? null,
				'created_by'  => get_current_user_id() ?: null,
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
			$opp_id = (int) $wpdb->insert_id;

			$wpdb->update( $tbl_leads, array(
				'status'                   => 'converted',
				'converted_at'             => $now,
				'converted_opportunity_id' => $opp_id ?: null,
				'account_id'               => $account_id,
				'contact_id'               => $contact_id,
				'updated_at'               => $now,
			), array( 'id' => $id ) );

			return array(
				'lead_id'        => $id,
				'account_id'     => $account_id,
				'contact_id'     => $contact_id,
				'opportunity_id' => $opp_id ?: null,
			);
		} );
	}

	private static function shape_crm_lead( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		$custom = json_decode( (string) ( $r['custom_json'] ?? '' ), true );
		return array(
			'id'                       => (int) $r['id'],
			'first_name'               => (string) $r['first_name'],
			'last_name'                => (string) $r['last_name'],
			'name'                     => trim( $r['first_name'] . ' ' . $r['last_name'] ),
			'email'                    => $r['email'],
			'phone'                    => $r['phone'],
			'company'                  => $r['company'],
			'title'                    => $r['title'],
			'source'                   => $r['source'],
			'status'                   => (string) $r['status'],
			'rating'                   => $r['rating'],
			'owner_id'                 => $r['owner_id']   ? (int) $r['owner_id']   : null,
			'account_id'               => $r['account_id'] ? (int) $r['account_id'] : null,
			'contact_id'               => $r['contact_id'] ? (int) $r['contact_id'] : null,
			'converted_at'             => $r['converted_at'],
			'converted_opportunity_id' => $r['converted_opportunity_id'] ? (int) $r['converted_opportunity_id'] : null,
			'notes'                    => $r['notes'],
			'custom'                   => is_array( $custom ) ? $custom : array(),
			'created_at'               => $r['created_at'],
			'updated_at'               => $r['updated_at'],
		);
	}

	/* ── Opportunities ── */

	public static function get_crm_opportunities( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
			$where = array( 'deleted_at IS NULL' );
			$stage  = (string) ( $req->get_param( 'stage' )  ?: '' );
			$status = (string) ( $req->get_param( 'status' ) ?: '' );
			$owner  = $req->get_param( 'owner_id' );
			$acct   = $req->get_param( 'account_id' );
			$q      = (string) ( $req->get_param( 'q' ) ?: '' );
			if ( $stage  !== '' ) { $where[] = $wpdb->prepare( 'stage = %s', $stage ); }
			if ( $status !== '' ) { $where[] = $wpdb->prepare( 'status = %s', $status ); }
			if ( $owner  !== null ) { $where[] = $wpdb->prepare( 'owner_id = %d', (int) $owner ); }
			if ( $acct   !== null ) { $where[] = $wpdb->prepare( 'account_id = %d', (int) $acct ); }
			if ( $q !== '' ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = $wpdb->prepare( '(name LIKE %s OR description LIKE %s)', $like, $like );
			}
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'opportunities' => array_map( array( __CLASS__, 'shape_crm_opportunity' ), (array) $rows ),
				'count'         => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_opportunity( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Opportunity not found', array( 'status' => 404 ) ); }
			return self::shape_crm_opportunity( $row );
		} );
	}

	public static function post_crm_opportunity( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$name = trim( (string) ( $body['name'] ?? '' ) );
			if ( $name === '' ) { return new WP_Error( 'invalid_name', '"name" is required', array( 'status' => 422 ) ); }
			$amount = isset( $body['amount'] ) ? (float) $body['amount'] : 0;
			$prob   = isset( $body['probability'] ) ? max( 0, min( 100, (int) $body['probability'] ) ) : 10;
			$now    = current_time( 'mysql' );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities(), array(
				'name'             => $name,
				'account_id'       => isset( $body['account_id'] ) ? (int) $body['account_id'] : null,
				'contact_id'       => isset( $body['contact_id'] ) ? (int) $body['contact_id'] : null,
				'lead_id'          => isset( $body['lead_id'] )    ? (int) $body['lead_id']    : null,
				'owner_id'         => isset( $body['owner_id'] )   ? (int) $body['owner_id']   : ( get_current_user_id() ?: null ),
				'stage'            => (string) ( $body['stage']  ?? 'qualification' ),
				'status'           => (string) ( $body['status'] ?? 'open' ),
				'amount'           => $amount,
				'currency'         => (string) ( $body['currency'] ?? 'VND' ),
				'probability'      => $prob,
				'expected_revenue' => round( $amount * ( $prob / 100 ), 2 ),
				'close_date'       => $body['close_date'] ?? null,
				'lost_reason'      => isset( $body['lost_reason'] ) ? (string) $body['lost_reason'] : null,
				'next_step'        => isset( $body['next_step'] )   ? (string) $body['next_step']   : null,
				'description'      => isset( $body['description'] ) ? (string) $body['description'] : null,
				'custom_json'      => isset( $body['custom'] ) && is_array( $body['custom'] ) ? wp_json_encode( $body['custom'] ) : null,
				'created_by'       => get_current_user_id() ?: null,
				'created_at'       => $now,
				'updated_at'       => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create opportunity', array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities() . "` WHERE id=%d", $id ), ARRAY_A );
			// M-CRM.M1.W3 — audit log 'created'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log_created( 'crm_opportunity', $id, (array) $row );
			}
			return self::shape_crm_opportunity( $row );
		} );
	}

	public static function put_crm_opportunity( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
			$id  = (int) $req['id'];
			$cur = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ), ARRAY_A );
			if ( ! $cur ) { return new WP_Error( 'not_found', 'Opportunity not found', array( 'status' => 404 ) ); }
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'name', 'stage', 'status', 'currency', 'lost_reason', 'next_step', 'description' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (string) $body[ $f ]; }
			}
			foreach ( array( 'account_id', 'contact_id', 'lead_id', 'owner_id' ) as $f ) {
				if ( array_key_exists( $f, $body ) ) { $fields[ $f ] = $body[ $f ] === null ? null : (int) $body[ $f ]; }
			}
			if ( isset( $body['amount'] ) )      { $fields['amount']      = (float) $body['amount']; }
			if ( isset( $body['probability'] ) ) { $fields['probability'] = max( 0, min( 100, (int) $body['probability'] ) ); }
			if ( array_key_exists( 'close_date', $body ) ) { $fields['close_date'] = $body['close_date'] ?: null; }
			if ( isset( $body['custom'] ) && is_array( $body['custom'] ) ) { $fields['custom_json'] = wp_json_encode( $body['custom'] ); }
			// Won/lost lifecycle.
			if ( isset( $fields['status'] ) && in_array( $fields['status'], array( 'won', 'lost' ), true ) ) {
				$fields['actual_close_date'] = current_time( 'Y-m-d' );
			}
			// Recompute expected_revenue if either amount/probability changed.
			$amount = $fields['amount']      ?? (float) $cur['amount'];
			$prob   = $fields['probability'] ?? (int)   $cur['probability'];
			$fields['expected_revenue'] = round( $amount * ( $prob / 100 ), 2 );
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			// M-CRM.M1.W3 — audit log 'updated'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) && $cur ) {
				BizCity_CRM_Audit_Log::log_updated( 'crm_opportunity', $id, $cur, (array) $row );
			}
			return self::shape_crm_opportunity( $row );
		} );
	}

	public static function delete_crm_opportunity( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
			$id  = (int) $req['id'];
			$wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			// M-CRM.M1.W3 — audit log 'deleted'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log_deleted( 'crm_opportunity', $id );
			}
			return array( 'deleted' => true, 'id' => $id );
		} );
	}

	private static function shape_crm_opportunity( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		$custom = json_decode( (string) ( $r['custom_json'] ?? '' ), true );
		return array(
			'id'                => (int) $r['id'],
			'name'              => (string) $r['name'],
			'account_id'        => $r['account_id'] ? (int) $r['account_id'] : null,
			'contact_id'        => $r['contact_id'] ? (int) $r['contact_id'] : null,
			'lead_id'           => $r['lead_id']    ? (int) $r['lead_id']    : null,
			'owner_id'          => $r['owner_id']   ? (int) $r['owner_id']   : null,
			'stage'             => (string) $r['stage'],
			'status'            => (string) $r['status'],
			'amount'            => (float) $r['amount'],
			'currency'          => (string) $r['currency'],
			'probability'       => (int) $r['probability'],
			'expected_revenue'  => (float) $r['expected_revenue'],
			'close_date'        => $r['close_date'],
			'actual_close_date' => $r['actual_close_date'],
			'lost_reason'       => $r['lost_reason'],
			'next_step'         => $r['next_step'],
			'description'       => $r['description'],
			'custom'            => is_array( $custom ) ? $custom : array(),
			'created_at'        => $r['created_at'],
			'updated_at'        => $r['updated_at'],
		);
	}

	/* ── Opportunity lines ── */

	public static function get_crm_opportunity_lines( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunity_lines();
			$opp = (int) $req['id'];
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE opportunity_id=%d ORDER BY position ASC, id ASC", $opp ), ARRAY_A );
			return array(
				'lines' => array_map( array( __CLASS__, 'shape_crm_opp_line' ), (array) $rows ),
			);
		} );
	}

	/**
	 * PUT lines = full replace. Body: { lines: [ { product_code, description, quantity, unit_price, discount_pct, tax_pct }... ] }
	 * Recomputes opportunity.amount = sum(line_total).
	 */
	public static function put_crm_opportunity_lines( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$opp_tbl  = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
			$line_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunity_lines();
			$opp_id   = (int) $req['id'];
			$cur = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$opp_tbl}` WHERE id=%d AND deleted_at IS NULL", $opp_id ), ARRAY_A );
			if ( ! $cur ) { return new WP_Error( 'not_found', 'Opportunity not found', array( 'status' => 404 ) ); }
			$body  = self::extract_json_body( $req );
			$lines = is_array( $body['lines'] ?? null ) ? $body['lines'] : array();
			$wpdb->delete( $line_tbl, array( 'opportunity_id' => $opp_id ) );
			$now = current_time( 'mysql' );
			$total = 0.0;
			foreach ( array_values( $lines ) as $i => $ln ) {
				$ln    = self::resolve_line_from_product( $ln );
				$calc  = self::compute_line_total( $ln );
				$total += $calc['line_total'];
				$wpdb->insert( $line_tbl, array(
					'opportunity_id' => $opp_id,
					'product_id'     => isset( $ln['product_id'] ) && $ln['product_id'] ? (int) $ln['product_id'] : null,
					'product_code'   => isset( $ln['product_code'] ) ? (string) $ln['product_code'] : null,
					'description'    => (string) ( $ln['description'] ?? '' ),
					'quantity'       => $calc['quantity'],
					'unit_price'     => $calc['unit_price'],
					'discount_pct'   => $calc['discount_pct'],
					'discount_type'  => $calc['discount_type'],
					'tax_pct'        => $calc['tax_pct'],
					'line_total'     => $calc['line_total'],
					'position'       => $i,
					'created_at'     => $now,
					'updated_at'     => $now,
				) );
			}
			$prob = (int) $cur['probability'];
			$wpdb->update( $opp_tbl, array(
				'amount'           => $total,
				'expected_revenue' => round( $total * ( $prob / 100 ), 2 ),
				'updated_at'       => $now,
			), array( 'id' => $opp_id ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$line_tbl}` WHERE opportunity_id=%d ORDER BY position ASC", $opp_id ), ARRAY_A );
			return array(
				'lines'  => array_map( array( __CLASS__, 'shape_crm_opp_line' ), (array) $rows ),
				'amount' => $total,
			);
		} );
	}

	private static function shape_crm_opp_line( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		return array(
			'id'             => (int) $r['id'],
			'opportunity_id' => (int) $r['opportunity_id'],
			'product_id'     => isset( $r['product_id'] ) && $r['product_id'] ? (int) $r['product_id'] : null,
			'product_code'   => $r['product_code'],
			'description'    => (string) $r['description'],
			'quantity'       => (float) $r['quantity'],
			'unit_price'     => (float) $r['unit_price'],
			'discount_pct'   => (float) $r['discount_pct'],
			'discount_type'  => isset( $r['discount_type'] ) ? (string) $r['discount_type'] : 'percentage',
			'tax_pct'        => (float) $r['tax_pct'],
			'line_total'     => (float) $r['line_total'],
			'position'       => (int) $r['position'],
		);
	}

	/* ── Contracts ── */

	public static function get_crm_contracts( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_contracts();
			$where = array( 'deleted_at IS NULL' );
			$status = (string) ( $req->get_param( 'status' ) ?: '' );
			$owner  = $req->get_param( 'owner_id' );
			$acct   = $req->get_param( 'account_id' );
			$q      = (string) ( $req->get_param( 'q' ) ?: '' );
			if ( $status !== '' ) { $where[] = $wpdb->prepare( 'status = %s', $status ); }
			if ( $owner  !== null ) { $where[] = $wpdb->prepare( 'owner_id = %d', (int) $owner ); }
			if ( $acct   !== null ) { $where[] = $wpdb->prepare( 'account_id = %d', (int) $acct ); }
			if ( $q !== '' ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = $wpdb->prepare( '(title LIKE %s OR code LIKE %s)', $like, $like );
			}
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'contracts' => array_map( array( __CLASS__, 'shape_crm_contract' ), (array) $rows ),
				'count'     => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_contract( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_contracts();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Contract not found', array( 'status' => 404 ) ); }
			return self::shape_crm_contract( $row );
		} );
	}

	public static function post_crm_contract( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body  = self::extract_json_body( $req );
			$title = trim( (string) ( $body['title'] ?? '' ) );
			if ( $title === '' ) { return new WP_Error( 'invalid_title', '"title" is required', array( 'status' => 422 ) ); }
			$code = (string) ( $body['code'] ?? '' );
			if ( $code === '' ) { $code = 'CT-' . date( 'Ymd' ) . '-' . wp_generate_password( 5, false, false ); }
			$now = current_time( 'mysql' );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_contracts(), array(
				'code'           => $code,
				'title'          => $title,
				'account_id'     => isset( $body['account_id'] )     ? (int) $body['account_id']     : null,
				'contact_id'     => isset( $body['contact_id'] )     ? (int) $body['contact_id']     : null,
				'opportunity_id' => isset( $body['opportunity_id'] ) ? (int) $body['opportunity_id'] : null,
				'owner_id'       => isset( $body['owner_id'] )       ? (int) $body['owner_id']       : ( get_current_user_id() ?: null ),
				'status'         => (string) ( $body['status'] ?? 'draft' ),
				'start_date'     => $body['start_date']  ?? null,
				'end_date'       => $body['end_date']    ?? null,
				'signed_date'    => $body['signed_date'] ?? null,
				'amount'         => isset( $body['amount'] ) ? (float) $body['amount'] : 0,
				'currency'       => (string) ( $body['currency'] ?? 'VND' ),
				'terms'          => isset( $body['terms'] ) ? (string) $body['terms'] : null,
				'custom_json'    => isset( $body['custom'] ) && is_array( $body['custom'] ) ? wp_json_encode( $body['custom'] ) : null,
				'created_by'     => get_current_user_id() ?: null,
				'created_at'     => $now,
				'updated_at'     => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				return new WP_Error( 'insert_failed', 'Could not create contract: ' . $wpdb->last_error, array( 'status' => 500 ) );
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_crm_contracts() . "` WHERE id=%d", $id ), ARRAY_A );
			// M-CRM.M1.W3 — audit log 'created'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log_created( 'crm_contract', $id, (array) $row );
			}
			return self::shape_crm_contract( $row );
		} );
	}

	public static function put_crm_contract( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_contracts();
			$id  = (int) $req['id'];
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ) ) ) {
				return new WP_Error( 'not_found', 'Contract not found', array( 'status' => 404 ) );
			}
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'code', 'title', 'status', 'currency', 'terms' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (string) $body[ $f ]; }
			}
			foreach ( array( 'account_id', 'contact_id', 'opportunity_id', 'owner_id' ) as $f ) {
				if ( array_key_exists( $f, $body ) ) { $fields[ $f ] = $body[ $f ] === null ? null : (int) $body[ $f ]; }
			}
			foreach ( array( 'start_date', 'end_date', 'signed_date' ) as $f ) {
				if ( array_key_exists( $f, $body ) ) { $fields[ $f ] = $body[ $f ] ?: null; }
			}
			if ( isset( $body['amount'] ) ) { $fields['amount'] = (float) $body['amount']; }
			if ( isset( $body['custom'] ) && is_array( $body['custom'] ) ) {
				$fields['custom_json'] = wp_json_encode( $body['custom'] );
			}
			$before_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			// M-CRM.M1.W3 — audit log 'updated'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) && $before_row ) {
				BizCity_CRM_Audit_Log::log_updated( 'crm_contract', $id, (array) $before_row, (array) $row );
			}
			return self::shape_crm_contract( $row );
		} );
	}

	public static function delete_crm_contract( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_contracts();
			$id  = (int) $req['id'];
			$wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			// M-CRM.M1.W3 — audit log 'deleted'.
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log_deleted( 'crm_contract', $id );
			}
			return array( 'deleted' => true, 'id' => $id );
		} );
	}

	private static function shape_crm_contract( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		$custom = json_decode( (string) ( $r['custom_json'] ?? '' ), true );
		return array(
			'id'             => (int) $r['id'],
			'code'           => (string) $r['code'],
			'title'          => (string) $r['title'],
			'account_id'     => $r['account_id']     ? (int) $r['account_id']     : null,
			'contact_id'     => $r['contact_id']     ? (int) $r['contact_id']     : null,
			'opportunity_id' => $r['opportunity_id'] ? (int) $r['opportunity_id'] : null,
			'owner_id'       => $r['owner_id']       ? (int) $r['owner_id']       : null,
			'status'         => (string) $r['status'],
			'start_date'     => $r['start_date'],
			'end_date'       => $r['end_date'],
			'signed_date'    => $r['signed_date'],
			'amount'         => (float) $r['amount'],
			'currency'       => (string) $r['currency'],
			'terms'          => $r['terms'],
			'custom'         => is_array( $custom ) ? $custom : array(),
			'created_at'     => $r['created_at'],
			'updated_at'     => $r['updated_at'],
		);
	}

	/* ── Contract lines ── */

	public static function get_crm_contract_lines( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_contract_lines();
			$cid = (int) $req['id'];
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE contract_id=%d ORDER BY position ASC, id ASC", $cid ), ARRAY_A );
			return array(
				'lines' => array_map( array( __CLASS__, 'shape_crm_contract_line' ), (array) $rows ),
			);
		} );
	}

	public static function put_crm_contract_lines( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$ct_tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_contracts();
			$line_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_contract_lines();
			$cid      = (int) $req['id'];
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$ct_tbl}` WHERE id=%d AND deleted_at IS NULL", $cid ) ) ) {
				return new WP_Error( 'not_found', 'Contract not found', array( 'status' => 404 ) );
			}
			$body  = self::extract_json_body( $req );
			$lines = is_array( $body['lines'] ?? null ) ? $body['lines'] : array();
			$wpdb->delete( $line_tbl, array( 'contract_id' => $cid ) );
			$now = current_time( 'mysql' );
			$total = 0.0;
			foreach ( array_values( $lines ) as $i => $ln ) {
				$ln    = self::resolve_line_from_product( $ln );
				$calc  = self::compute_line_total( $ln );
				$total += $calc['line_total'];
				$wpdb->insert( $line_tbl, array(
					'contract_id'   => $cid,
					'product_id'    => isset( $ln['product_id'] ) && $ln['product_id'] ? (int) $ln['product_id'] : null,
					'product_code'  => isset( $ln['product_code'] ) ? (string) $ln['product_code'] : null,
					'description'   => (string) ( $ln['description'] ?? '' ),
					'quantity'      => $calc['quantity'],
					'unit_price'    => $calc['unit_price'],
					'discount_pct'  => $calc['discount_pct'],
					'discount_type' => $calc['discount_type'],
					'tax_pct'       => $calc['tax_pct'],
					'line_total'    => $calc['line_total'],
					'position'      => $i,
					'created_at'    => $now,
					'updated_at'    => $now,
				) );
			}
			$wpdb->update( $ct_tbl, array( 'amount' => $total, 'updated_at' => $now ), array( 'id' => $cid ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$line_tbl}` WHERE contract_id=%d ORDER BY position ASC", $cid ), ARRAY_A );
			return array(
				'lines'  => array_map( array( __CLASS__, 'shape_crm_contract_line' ), (array) $rows ),
				'amount' => $total,
			);
		} );
	}

	private static function shape_crm_contract_line( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		return array(
			'id'            => (int) $r['id'],
			'contract_id'   => (int) $r['contract_id'],
			'product_id'    => isset( $r['product_id'] ) && $r['product_id'] ? (int) $r['product_id'] : null,
			'product_code'  => $r['product_code'],
			'description'   => (string) $r['description'],
			'quantity'      => (float) $r['quantity'],
			'unit_price'    => (float) $r['unit_price'],
			'discount_pct'  => (float) $r['discount_pct'],
			'discount_type' => isset( $r['discount_type'] ) ? (string) $r['discount_type'] : 'percentage',
			'tax_pct'       => (float) $r['tax_pct'],
			'line_total'    => (float) $r['line_total'],
			'position'      => (int) $r['position'],
		);
	}

	/* ============ M-CRM.M1.W2 — Shared line helpers ============ */

	/**
	 * If the line has product_id, hydrate missing fields (description / unit_price / tax_pct / product_code)
	 * from the products catalog. Caller-supplied values take precedence.
	 */
	private static function resolve_line_from_product( array $ln ): array {
		$pid = isset( $ln['product_id'] ) ? (int) $ln['product_id'] : 0;
		if ( $pid <= 0 ) { return $ln; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_products();
		$p   = $wpdb->get_row( $wpdb->prepare( "SELECT sku, name, unit_price, tax_rate FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $pid ), ARRAY_A );
		if ( ! $p ) { return $ln; }
		if ( ! isset( $ln['product_code'] ) || $ln['product_code'] === '' || $ln['product_code'] === null ) { $ln['product_code'] = (string) $p['sku']; }
		if ( ! isset( $ln['description'] )  || $ln['description'] === '' )  { $ln['description'] = (string) $p['name']; }
		if ( ! isset( $ln['unit_price'] )   || $ln['unit_price'] === null ) { $ln['unit_price']  = (float) $p['unit_price']; }
		if ( ! isset( $ln['tax_pct'] )      || $ln['tax_pct'] === null )    { $ln['tax_pct']     = (float) $p['tax_rate']; }
		return $ln;
	}

	/**
	 * Compute line totals respecting discount_type (percentage vs fixed).
	 *
	 * - percentage: subtotal = qty * price * (1 - disc/100)
	 * - fixed:      subtotal = (qty * price) - disc            (clamped >= 0)
	 *
	 * Then line_total = round( subtotal * (1 + tax_pct/100), 2 )
	 *
	 * @return array{quantity:float,unit_price:float,discount_pct:float,discount_type:string,tax_pct:float,line_total:float}
	 */
	private static function compute_line_total( array $ln ): array {
		$qty   = isset( $ln['quantity'] )      ? (float) $ln['quantity']     : 1;
		$price = isset( $ln['unit_price'] )    ? (float) $ln['unit_price']   : 0;
		$disc  = isset( $ln['discount_pct'] )  ? (float) $ln['discount_pct'] : 0;
		$tax   = isset( $ln['tax_pct'] )       ? (float) $ln['tax_pct']      : 0;
		$type  = isset( $ln['discount_type'] ) ? strtolower( (string) $ln['discount_type'] ) : 'percentage';
		if ( ! in_array( $type, array( 'percentage', 'fixed' ), true ) ) { $type = 'percentage'; }
		$gross = $qty * $price;
		$sub   = ( $type === 'fixed' ) ? max( 0, $gross - $disc ) : $gross * ( 1 - $disc / 100 );
		$lt    = round( $sub * ( 1 + $tax / 100 ), 2 );
		return array(
			'quantity'      => $qty,
			'unit_price'    => $price,
			'discount_pct'  => $disc,
			'discount_type' => $type,
			'tax_pct'       => $tax,
			'line_total'    => $lt,
		);
	}

	/* ============ M-CRM.M1.W2 — Product Categories ============ */

	public static function get_crm_product_categories( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_product_categories();
			$q   = (string) ( $req->get_param( 'q' ) ?: '' );
			$where = array( 'deleted_at IS NULL' );
			if ( $q !== '' ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = $wpdb->prepare( '(name LIKE %s OR slug LIKE %s)', $like, $like );
			}
			$rows = $wpdb->get_results( "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY position ASC, name ASC", ARRAY_A );
			return array(
				'categories' => array_map( array( __CLASS__, 'shape_crm_product_category' ), (array) $rows ),
				'count'      => count( (array) $rows ),
			);
		} );
	}

	public static function post_crm_product_category( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$name = trim( (string) ( $body['name'] ?? '' ) );
			if ( $name === '' ) { return new WP_Error( 'invalid_name', '"name" is required', array( 'status' => 422 ) ); }
			$slug = (string) ( $body['slug'] ?? '' );
			if ( $slug === '' ) { $slug = sanitize_title( $name ); }
			$now  = current_time( 'mysql' );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_product_categories(), array(
				'name'        => $name,
				'slug'        => $slug,
				'parent_id'   => isset( $body['parent_id'] ) ? (int) $body['parent_id'] : null,
				'description' => isset( $body['description'] ) ? (string) $body['description'] : null,
				'position'    => isset( $body['position'] ) ? (int) $body['position'] : 0,
				'created_by'  => get_current_user_id() ?: null,
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create category: ' . $wpdb->last_error, array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_crm_product_categories() . "` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_product_category( $row );
		} );
	}

	public static function put_crm_product_category( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_product_categories();
			$id  = (int) $req['id'];
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ) ) ) {
				return new WP_Error( 'not_found', 'Category not found', array( 'status' => 404 ) );
			}
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'name', 'slug', 'description' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (string) $body[ $f ]; }
			}
			if ( array_key_exists( 'parent_id', $body ) ) { $fields['parent_id'] = $body['parent_id'] === null ? null : (int) $body['parent_id']; }
			if ( isset( $body['position'] ) ) { $fields['position'] = (int) $body['position']; }
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_product_category( $row );
		} );
	}

	public static function delete_crm_product_category( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_product_categories();
			$id  = (int) $req['id'];
			$wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			return array( 'deleted' => true, 'id' => $id );
		} );
	}

	private static function shape_crm_product_category( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		return array(
			'id'          => (int) $r['id'],
			'name'        => (string) $r['name'],
			'slug'        => (string) $r['slug'],
			'parent_id'   => $r['parent_id'] ? (int) $r['parent_id'] : null,
			'description' => $r['description'],
			'position'    => (int) $r['position'],
			'created_at'  => $r['created_at'],
			'updated_at'  => $r['updated_at'],
		);
	}

	/* ============ M-CRM.M1.W2 — Products ============ */

	public static function get_crm_products( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_products();
			$where = array( 'deleted_at IS NULL' );
			$status = (string) ( $req->get_param( 'status' )      ?: '' );
			$type   = (string) ( $req->get_param( 'type' )        ?: '' );
			$cat    = $req->get_param( 'category_id' );
			$q      = (string) ( $req->get_param( 'q' ) ?: '' );
			if ( $status !== '' ) { $where[] = $wpdb->prepare( 'status = %s', $status ); }
			if ( $type   !== '' ) { $where[] = $wpdb->prepare( 'type = %s', $type ); }
			if ( $cat    !== null ) { $where[] = $wpdb->prepare( 'category_id = %d', (int) $cat ); }
			if ( $q !== '' ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = $wpdb->prepare( '(name LIKE %s OR sku LIKE %s OR description LIKE %s)', $like, $like, $like );
			}
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'products' => array_map( array( __CLASS__, 'shape_crm_product' ), (array) $rows ),
				'count'    => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_product( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_products();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) ); }
			return self::shape_crm_product( $row );
		} );
	}

	public static function post_crm_product( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$name = trim( (string) ( $body['name'] ?? '' ) );
			if ( $name === '' ) { return new WP_Error( 'invalid_name', '"name" is required', array( 'status' => 422 ) ); }
			$sku = (string) ( $body['sku'] ?? '' );
			if ( $sku === '' ) { $sku = 'P-' . date( 'Ymd' ) . '-' . wp_generate_password( 5, false, false ); }
			$type = (string) ( $body['type'] ?? 'product' );
			if ( ! in_array( $type, array( 'product', 'service' ), true ) ) { $type = 'product'; }
			$status = (string) ( $body['status'] ?? 'active' );
			if ( ! in_array( $status, array( 'draft', 'active', 'archived' ), true ) ) { $status = 'active'; }
			$now = current_time( 'mysql' );
			$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_products(), array(
				'category_id'    => isset( $body['category_id'] ) ? (int) $body['category_id'] : null,
				'sku'            => $sku,
				'name'           => $name,
				'type'           => $type,
				'status'         => $status,
				'description'    => isset( $body['description'] ) ? (string) $body['description'] : null,
				'unit_price'     => isset( $body['unit_price'] ) ? (float) $body['unit_price'] : 0,
				'unit_cost'      => isset( $body['unit_cost'] )  ? (float) $body['unit_cost']  : 0,
				'currency'       => (string) ( $body['currency'] ?? 'VND' ),
				'tax_rate'       => isset( $body['tax_rate'] ) ? (float) $body['tax_rate'] : 0,
				'is_recurring'   => ! empty( $body['is_recurring'] ) ? 1 : 0,
				'billing_period' => isset( $body['billing_period'] ) ? (string) $body['billing_period'] : null,
				'stock_qty'      => isset( $body['stock_qty'] ) ? (float) $body['stock_qty'] : null,
				'custom_json'    => isset( $body['custom'] ) && is_array( $body['custom'] ) ? wp_json_encode( $body['custom'] ) : null,
				'created_by'     => get_current_user_id() ?: null,
				'created_at'     => $now,
				'updated_at'     => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create product: ' . $wpdb->last_error, array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . BizCity_CRM_DB_Installer_V2::tbl_crm_products() . "` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_product( $row );
		} );
	}

	public static function put_crm_product( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_products();
			$id  = (int) $req['id'];
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND deleted_at IS NULL", $id ) ) ) {
				return new WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
			}
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'sku', 'name', 'description', 'currency', 'billing_period' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (string) $body[ $f ]; }
			}
			if ( isset( $body['type'] ) && in_array( $body['type'], array( 'product', 'service' ), true ) ) { $fields['type'] = (string) $body['type']; }
			if ( isset( $body['status'] ) && in_array( $body['status'], array( 'draft', 'active', 'archived' ), true ) ) { $fields['status'] = (string) $body['status']; }
			if ( array_key_exists( 'category_id', $body ) ) { $fields['category_id'] = $body['category_id'] === null ? null : (int) $body['category_id']; }
			foreach ( array( 'unit_price', 'unit_cost', 'tax_rate', 'stock_qty' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (float) $body[ $f ]; }
			}
			if ( isset( $body['is_recurring'] ) ) { $fields['is_recurring'] = ! empty( $body['is_recurring'] ) ? 1 : 0; }
			if ( isset( $body['custom'] ) && is_array( $body['custom'] ) ) { $fields['custom_json'] = wp_json_encode( $body['custom'] ); }
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			return self::shape_crm_product( $row );
		} );
	}

	public static function delete_crm_product( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_products();
			$id  = (int) $req['id'];
			$wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			return array( 'deleted' => true, 'id' => $id );
		} );
	}

	private static function shape_crm_product( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		$custom = json_decode( (string) ( $r['custom_json'] ?? '' ), true );
		return array(
			'id'             => (int) $r['id'],
			'category_id'    => $r['category_id'] ? (int) $r['category_id'] : null,
			'sku'            => (string) $r['sku'],
			'name'           => (string) $r['name'],
			'type'           => (string) $r['type'],
			'status'         => (string) $r['status'],
			'description'    => $r['description'],
			'unit_price'     => (float) $r['unit_price'],
			'unit_cost'      => (float) $r['unit_cost'],
			'margin'         => (float) ( (float) $r['unit_price'] - (float) $r['unit_cost'] ),
			'currency'       => (string) $r['currency'],
			'tax_rate'       => (float) $r['tax_rate'],
			'is_recurring'   => (bool) $r['is_recurring'],
			'billing_period' => $r['billing_period'],
			'stock_qty'      => $r['stock_qty'] === null ? null : (float) $r['stock_qty'],
			'custom'         => is_array( $custom ) ? $custom : array(),
			'created_at'     => $r['created_at'],
			'updated_at'     => $r['updated_at'],
		);
	}

	/* ============================================================
	 * M-CRM.M2 — Invoicing handlers
	 * ============================================================ */

	public static function get_crm_invoices( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			return BizCity_CRM_Invoice_Repository::list( array(
				'status'         => (string) ( $req['status'] ?? '' ),
				'account_id'     => (int) ( $req['account_id'] ?? 0 ),
				'contact_id'     => (int) ( $req['contact_id'] ?? 0 ),
				'contract_id'    => (int) ( $req['contract_id'] ?? 0 ),
				'opportunity_id' => (int) ( $req['opportunity_id'] ?? 0 ),
				'search'         => (string) ( $req['q'] ?? '' ),
				'limit'          => (int) ( $req['limit'] ?? 50 ),
				'offset'         => (int) ( $req['offset'] ?? 0 ),
			) );
		} );
	}

	public static function post_crm_invoice( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			$id   = BizCity_CRM_Invoice_Repository::create( $body );
			return BizCity_CRM_Invoice_Repository::get_with_relations( $id );
		} );
	}

	public static function get_crm_invoice( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$inv = BizCity_CRM_Invoice_Repository::get_with_relations( (int) $req['id'] );
			if ( ! $inv ) { throw new \RuntimeException( 'invoice_not_found' ); }
			return $inv;
		} );
	}

	public static function put_crm_invoice( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id   = (int) $req['id'];
			$body = $req->get_json_params() ?: array();
			BizCity_CRM_Invoice_Repository::update( $id, $body );
			return BizCity_CRM_Invoice_Repository::get_with_relations( $id );
		} );
	}

	public static function delete_crm_invoice( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			BizCity_CRM_Invoice_Repository::delete( (int) $req['id'] );
			return array( 'deleted' => true );
		} );
	}

	public static function post_crm_invoice_transition( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body   = $req->get_json_params() ?: array();
			$status = (string) ( $body['status'] ?? $req['status'] ?? '' );
			BizCity_CRM_Invoice_Repository::transition( (int) $req['id'], $status );
			return BizCity_CRM_Invoice_Repository::get_with_relations( (int) $req['id'] );
		} );
	}

	public static function get_crm_invoice_payments( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$inv = BizCity_CRM_Invoice_Repository::get_with_relations( (int) $req['id'] );
			if ( ! $inv ) { throw new \RuntimeException( 'invoice_not_found' ); }
			return $inv['payments'] ?? array();
		} );
	}

	public static function post_crm_invoice_payment( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			$pid  = BizCity_CRM_Invoice_Repository::add_payment( (int) $req['id'], $body );
			return array(
				'payment_id' => $pid,
				'invoice'    => BizCity_CRM_Invoice_Repository::get_with_relations( (int) $req['id'] ),
			);
		} );
	}

	public static function delete_crm_invoice_payment( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			BizCity_CRM_Invoice_Repository::delete_payment( (int) $req['pid'] );
			return array( 'deleted' => true );
		} );
	}

	public static function post_crm_invoice_send( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body    = $req->get_json_params() ?: array();
			$to      = sanitize_email( (string) ( $body['to'] ?? '' ) );
			$subject = (string) ( $body['subject'] ?? '' );
			if ( ! $to ) { throw new \RuntimeException( 'recipient_required' ); }
			$ok = BizCity_CRM_Invoice_PDF::send_by_email( (int) $req['id'], $to, $subject );
			if ( $ok ) {
				// Auto-mark draft → sent on first successful delivery (best effort).
				$inv = BizCity_CRM_Invoice_Repository::get( (int) $req['id'] );
				if ( $inv && $inv['status'] === BizCity_CRM_Invoice_Repository::STATUS_DRAFT ) {
					try { BizCity_CRM_Invoice_Repository::transition( (int) $req['id'], BizCity_CRM_Invoice_Repository::STATUS_SENT ); }
					catch ( \Throwable $e ) { /* swallow — sending succeeded regardless */ }
				}
			}
			return array( 'sent' => $ok, 'to' => $to );
		} );
	}

	/**
	 * GET /crm-invoices/{id}/pdf — sends raw HTML directly (no JSON envelope).
	 * Returns a WP_REST_Response with text/html content-type.
	 */
	public static function get_crm_invoice_pdf( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$html = BizCity_CRM_Invoice_PDF::render_html( $id );
		$resp = new WP_REST_Response( $html );
		$resp->header( 'Content-Type', 'text/html; charset=UTF-8' );
		// Suppress REST JSON serialization.
		add_filter( 'rest_pre_serve_request', static function ( $served, $r ) use ( $html ) {
			if ( $served ) { return $served; }
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return true;
		}, 10, 2 );
		return $resp;
	}

	/* ============================================================
	 * M-CRM.M3 — Email Client handlers
	 * ============================================================ */

	public static function get_crm_email_accounts( WP_REST_Request $req ) {
		return self::wrap( static function () {
			return BizCity_CRM_Email_Repository::list_accounts();
		} );
	}

	public static function post_crm_email_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			$id   = BizCity_CRM_Email_Repository::create_account( $body );
			return BizCity_CRM_Email_Repository::get_account( $id );
		} );
	}

	public static function get_crm_email_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$acc = BizCity_CRM_Email_Repository::get_account( (int) $req['id'] );
			if ( ! $acc ) { throw new \RuntimeException( 'account_not_found' ); }
			return $acc;
		} );
	}

	public static function put_crm_email_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			BizCity_CRM_Email_Repository::update_account( (int) $req['id'], $body );
			return BizCity_CRM_Email_Repository::get_account( (int) $req['id'] );
		} );
	}

	public static function delete_crm_email_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			BizCity_CRM_Email_Repository::delete_account( (int) $req['id'] );
			return array( 'deleted' => true );
		} );
	}

	public static function post_crm_email_account_sync( WP_REST_Request $req ) {
		$account_id = (int) $req->get_param( 'id' );
		try {
			if ( ! BizCity_CRM_Email_Poller::imap_available() ) {
				return new WP_REST_Response( array(
					'error' => array(
						'code'    => 'imap_extension_missing',
						'message' => 'PHP ext-imap is not installed on this server. Please contact your host to enable it.',
					),
				), 500 );
			}

			$result = BizCity_CRM_Email_Poller::poll_account( $account_id );

			// Return flat shape so frontend can access result.fetched / result.inserted directly.
			return new WP_REST_Response( array(
				'fetched'  => (int) ( $result['fetched']  ?? 0 ),
				'inserted' => (int) ( $result['inserted'] ?? 0 ),
				'skipped'  => isset( $result['skipped'] ) ? (string) $result['skipped'] : null,
			), 200 );

		} catch ( \Throwable $e ) {
			$raw_msg = $e->getMessage();
			// Sanitize to valid UTF-8 so wp_json_encode never fails
			// (imap_last_error() can return ISO-8859-1 or garbled bytes).
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$msg = mb_convert_encoding( $raw_msg, 'UTF-8', 'UTF-8' );
			} else {
				$msg = htmlspecialchars_decode( htmlspecialchars( $raw_msg, ENT_SUBSTITUTE, 'UTF-8' ) );
			}
			if ( $msg === false || $msg === '' ) {
				$msg = get_class( $e ) . ' (no message)';
			}
			error_log( '[bizcity-crm] email sync error account ' . $account_id . ': ' . $raw_msg );
			return new WP_REST_Response( array(
				'error' => array(
					'code'    => 'sync_failed',
					'message' => $msg,
				),
			), 500 );
		}
	}

	/**
	 * POST /crm-email-accounts/from-smtp
	 *
	 * Auto-provisions (create or upsert) a CRM email account using the site's
	 * core BizCity SMTP credentials. Because Gmail uses the same App Password
	 * for both SMTP (outbound) and IMAP (inbound), no extra input is needed.
	 *
	 * IMAP derivation from SMTP host:
	 *   smtp.gmail.com   → imap.gmail.com   (port 993, ssl)
	 *   smtp.example.com → imap.example.com (port 993, ssl)
	 *   anything else    → imap.<tld-stripped>, or user supplies manually
	 */
	public static function post_crm_email_account_from_smtp( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_SMTP' ) ) {
				throw new \RuntimeException( 'BizCity_SMTP class not found. Make sure the core SMTP module is loaded.' );
			}
			$cfg = BizCity_SMTP::resolve_config();
			if ( ! $cfg ) {
				throw new \RuntimeException( 'BizCity SMTP chưa cấu hình. Điền thông tin tại trang Cài đặt SMTP trước.' );
			}

			// Derive IMAP host: replace "smtp." prefix → "imap." prefix.
			$smtp_host = (string) $cfg['host'];
			if ( stripos( $smtp_host, 'smtp.' ) === 0 ) {
				$imap_host = 'imap.' . substr( $smtp_host, 5 );
			} else {
				// Best-effort: use same host (works for some providers like Office365).
				$imap_host = $smtp_host;
			}

			$email = (string) ( $cfg['from'] ?: $cfg['user'] );
			$label = (string) ( $cfg['from_name'] ?: 'SMTP Account' );
			$user  = (string) $cfg['user'];
			$pass  = (string) $cfg['pass'];

			// Check if account with same email already exists → upsert.
			global $wpdb;
			$tbl      = BizCity_CRM_DB_Installer_V2::tbl_crm_email_accounts();
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT id FROM {$tbl} WHERE email = %s AND deleted_at IS NULL LIMIT 1", $email ),
				ARRAY_A
			);

			$account_data = array(
				'label'           => $label,
				'email'           => $email,
				'imap_host'       => $imap_host,
				'imap_port'       => 993,
				'imap_secure'     => 'ssl',
				'imap_user'       => $user,
				'imap_pass'       => $pass,
				'imap_folder'     => 'INBOX',
				'smtp_use_global' => 1,   // Outbound: reuse SMTP bridge.
				'is_active'       => 1,
			);

			if ( $existing ) {
				$account_id = (int) $existing['id'];
				BizCity_CRM_Email_Repository::update_account( $account_id, $account_data );
				$action = 'updated';
			} else {
				$account_id = BizCity_CRM_Email_Repository::create_account( $account_data );
				$action     = 'created';
			}

			$account = BizCity_CRM_Email_Repository::get_account( $account_id );
			return array(
				'action'  => $action,
				'account' => $account,
			);
		} );
	}

	/**
	 * POST /crm-email-accounts/{id}/test-imap
	 *
	 * Opens (and immediately closes) an IMAP connection to verify credentials.
	 * Safe to call without side effects — does not fetch any messages.
	 */
	public static function post_crm_email_account_test_imap( WP_REST_Request $req ) {
		$account_id = (int) $req->get_param( 'id' );
		try {
			if ( ! BizCity_CRM_Email_Poller::imap_available() ) {
				return new WP_REST_Response( array(
					'ok'      => false,
					'message' => 'PHP ext-imap chưa được bật trên server này. Liên hệ hosting để kích hoạt.',
				), 200 );  // 200 so FE can show the message, not throw.
			}

			$acc = BizCity_CRM_Email_Repository::get_account_with_passwords( $account_id );
			if ( ! $acc ) {
				return new WP_REST_Response( array( 'ok' => false, 'message' => 'Account không tồn tại.' ), 404 );
			}

			// Build mailbox string (reuse poller's private method via reflection or inline).
			$host   = (string) $acc['imap_host'];
			$port   = (int) $acc['imap_port'];
			$secure = (string) $acc['imap_secure'];
			$folder = (string) ( $acc['imap_folder'] ?: 'INBOX' );
			$flags  = '/imap';
			if ( $secure === 'ssl' )     { $flags .= '/ssl'; }
			elseif ( $secure === 'tls' ) { $flags .= '/tls'; }
			else                         { $flags .= '/notls'; }
			$flags = (string) apply_filters( 'bizcity_crm_imap_flags', $flags . '/novalidate-cert', $acc );
			$mbx   = sprintf( '{%s:%d%s}%s', $host, $port, $flags, $folder );

			$conn = @imap_open( $mbx, (string) $acc['imap_user'], (string) ( $acc['imap_pass'] ?? '' ), 0, 1, array( 'DISABLE_AUTHENTICATOR' => 'GSSAPI' ) );

			if ( ! $conn ) {
				$raw_err = imap_last_error() ?: 'imap_open failed';
				if ( function_exists( 'mb_convert_encoding' ) ) {
					$err = mb_convert_encoding( $raw_err, 'UTF-8', 'UTF-8' ) ?: $raw_err;
				} else {
					$err = htmlspecialchars_decode( htmlspecialchars( $raw_err, ENT_SUBSTITUTE, 'UTF-8' ) );
				}
				return new WP_REST_Response( array( 'ok' => false, 'message' => $err ), 200 );
			}

			$count = imap_num_msg( $conn );
			imap_close( $conn );

			return new WP_REST_Response( array(
				'ok'      => true,
				'message' => 'Kết nối thành công! Hộp thư có ' . $count . ' email.',
			), 200 );

		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $e->getMessage() ), 200 );
		}
	}

	public static function get_crm_email_threads( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			return BizCity_CRM_Email_Repository::list_threads( (int) $req['account_id'], array(
				'unread_only' => (bool) ( $req['unread_only'] ?? false ),
				'search'      => (string) ( $req['search'] ?? '' ),
				'limit'       => (int) ( $req['limit'] ?? 50 ),
				'offset'      => (int) ( $req['offset'] ?? 0 ),
			) );
		} );
	}

	public static function get_crm_email_thread( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$t = BizCity_CRM_Email_Repository::get_thread_with_messages( (int) $req['id'] );
			if ( ! $t ) { throw new \RuntimeException( 'thread_not_found' ); }
			return $t;
		} );
	}

	public static function post_crm_email_thread_read( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			BizCity_CRM_Email_Repository::mark_thread_read( (int) $req['id'] );
			return array( 'marked_read' => true );
		} );
	}

	public static function post_crm_email_send( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			$to   = $body['to'] ?? array();
			if ( is_string( $to ) ) {
				$to = array_filter( array_map( 'trim', explode( ',', $to ) ) );
			}
			$body['to'] = $to;
			return BizCity_CRM_Email_Repository::compose_and_send( (int) $body['account_id'], $body );
		} );
	}

	/**
	 * Returns safe (non-credential) preview of the global BizCity SMTP config.
	 * Password is intentionally excluded — only host/port/secure/from/from_name.
	 */
	public static function get_crm_smtp_status( WP_REST_Request $req ) {
		return self::wrap( static function () {
			$configured = false;
			$preview    = array();
			if ( class_exists( 'BizCity_SMTP' ) ) {
				$cfg = BizCity_SMTP::resolve_config();
				if ( $cfg && ! empty( $cfg['host'] ) && ! empty( $cfg['user'] ) ) {
					$configured = true;
					$preview    = array(
						'host'      => $cfg['host'],
						'port'      => $cfg['port'],
						'secure'    => $cfg['secure'],
						'from'      => $cfg['from'],
						'from_name' => $cfg['from_name'],
					);
				}
			}
			return array( 'configured' => $configured, 'preview' => $preview );
		} );
	}

	/* ============================================================
	 * PHASE 0.37.1 — Gmail SMTP + Email Automation handlers
	 * ============================================================ */

	public static function get_gmail_smtp_accounts( WP_REST_Request $req ) {
		return self::wrap( static function () {
			return BizCity_CRM_Gmail_SMTP_Repo::list_accounts();
		} );
	}

	public static function post_gmail_smtp_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			$id   = BizCity_CRM_Gmail_SMTP_Repo::create( $body );
			return BizCity_CRM_Gmail_SMTP_Repo::get( $id );
		} );
	}

	public static function put_gmail_smtp_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			BizCity_CRM_Gmail_SMTP_Repo::update( (int) $req['id'], $body );
			return BizCity_CRM_Gmail_SMTP_Repo::get( (int) $req['id'] );
		} );
	}

	public static function delete_gmail_smtp_account( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			BizCity_CRM_Gmail_SMTP_Repo::delete( (int) $req['id'] );
			return array( 'deleted' => true );
		} );
	}

	public static function post_gmail_smtp_account_test( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$body = $req->get_json_params() ?: array();
		$to   = isset( $body['to'] ) ? sanitize_email( $body['to'] ) : '';
		if ( ! $to ) {
			$cu = wp_get_current_user();
			$to = $cu ? $cu->user_email : '';
		}
		$res = BizCity_CRM_Gmail_SMTP_Repo::send_via( $id, array(
			'to'      => $to,
			'subject' => '[BizCity CRM] Test Gmail SMTP — ' . current_time( 'mysql' ),
			'body'    => '<p>Đây là email kiểm tra từ <strong>BizCity CRM</strong>.</p><p>Nếu bạn nhận được email này → cấu hình Gmail SMTP đã đúng.</p>',
			'is_html' => true,
		) );
		BizCity_CRM_Gmail_SMTP_Repo::record_test( $id, ! empty( $res['ok'] ), (string) ( $res['error'] ?? '' ) );
		return new WP_REST_Response( $res, 200 );
	}

	public static function post_gmail_smtp_account_promote( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$ok = BizCity_CRM_Gmail_SMTP_Repo::promote_to_global( (int) $req['id'] );
			return array( 'promoted' => $ok );
		} );
	}

	public static function get_email_events( WP_REST_Request $req ) {
		return self::wrap( static function () {
			$out = array();
			foreach ( BizCity_CRM_Email_Event_Registry::events() as $key => $ev ) {
				$out[] = array(
					'key'          => $key,
					'label'        => (string) ( $ev['label'] ?? $key ),
					'placeholders' => (array) ( $ev['placeholders'] ?? array() ),
				);
			}
			return $out;
		} );
	}

	public static function get_email_event_rules( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$event_key = (string) ( $req->get_param( 'event_key' ) ?? '' );
			return BizCity_CRM_Email_Rules_Repo::list_rules( $event_key );
		} );
	}

	public static function post_email_event_rule( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			$id   = BizCity_CRM_Email_Rules_Repo::create( $body );
			return BizCity_CRM_Email_Rules_Repo::get( $id );
		} );
	}

	public static function put_email_event_rule( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			BizCity_CRM_Email_Rules_Repo::update( (int) $req['id'], $body );
			return BizCity_CRM_Email_Rules_Repo::get( (int) $req['id'] );
		} );
	}

	public static function delete_email_event_rule( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			BizCity_CRM_Email_Rules_Repo::delete( (int) $req['id'] );
			return array( 'deleted' => true );
		} );
	}

	public static function post_email_event_rule_test( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body = $req->get_json_params() ?: array();
			$ctx  = is_array( $body['ctx'] ?? null ) ? $body['ctx'] : array();
			return BizCity_CRM_Email_Dispatcher::test_rule( (int) $req['id'], $ctx );
		} );
	}

	/* ============================================================
	 * PHASE 0.35 M6.W18-W22 — Marketing Asset Studio handlers
	 * ============================================================ */

	/** GET /marketing/brand-kit — current kit + hash. */
	public static function marketing_brand_kit_get( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_Brand_Kit' ) ) {
			return new WP_Error( 'bizcity_crm_brand_kit_unavailable', 'Brand kit module not loaded.', array( 'status' => 500 ) );
		}
		$kit = BizCity_CRM_Brand_Kit::get();
		return array(
			'kit'  => $kit,
			'hash' => BizCity_CRM_Brand_Kit::hash( $kit ),
		);
	}

	/** PUT/POST /marketing/brand-kit — patch and return new kit. */
	public static function marketing_brand_kit_update( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_Brand_Kit' ) ) {
			return new WP_Error( 'bizcity_crm_brand_kit_unavailable', 'Brand kit module not loaded.', array( 'status' => 500 ) );
		}
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) { $body = $req->get_params(); }
		$next = BizCity_CRM_Brand_Kit::update( is_array( $body ) ? $body : array() );
		return array( 'kit' => $next, 'hash' => BizCity_CRM_Brand_Kit::hash( $next ) );
	}

	/** GET /marketing/templates — registry of available SVG templates. */
	public static function marketing_templates_list( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_Asset_Renderer' ) ) {
			return new WP_Error( 'bizcity_crm_asset_renderer_unavailable', 'Asset renderer not loaded.', array( 'status' => 500 ) );
		}
		return array(
			'templates' => BizCity_CRM_Asset_Renderer::list_templates(),
			'formats'   => array_keys( BizCity_CRM_Asset_Renderer::SUPPORTED_MIME ),
		);
	}

	/** GET /campaigns/{id}/assets/manifest — list of templates with render URLs. */
	public static function marketing_asset_manifest( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_Asset_Renderer' ) ) {
			return new WP_Error( 'bizcity_crm_asset_renderer_unavailable', 'Asset renderer not loaded.', array( 'status' => 500 ) );
		}
		$cid    = (int) $req['id'];
		$rest_url = rest_url( 'bizcity-crm/v1/campaigns/' . $cid . '/assets/' );
		$out    = array();
		$kit_hash = class_exists( 'BizCity_CRM_Brand_Kit' ) ? BizCity_CRM_Brand_Kit::hash() : '';
		foreach ( BizCity_CRM_Asset_Renderer::list_templates() as $tpl ) {
			$out[] = array_merge( $tpl, array(
				'urls' => array(
					'svg' => $rest_url . $tpl['key'] . '.svg',
					'png' => $rest_url . $tpl['key'] . '.png',
					'jpg' => $rest_url . $tpl['key'] . '.jpg',
					'pdf' => $rest_url . $tpl['key'] . '.pdf',
				),
			) );
		}
		return array(
			'campaign_id' => $cid,
			'brand_hash'  => $kit_hash,
			'imagick'     => extension_loaded( 'imagick' ),
			'gd'          => extension_loaded( 'gd' ),
			'templates'   => $out,
		);
	}

	/**
	 * GET /campaigns/{id}/assets/{key}.{ext}
	 * Streams the rendered binary directly with proper Content-Type + ETag.
	 */
	public static function marketing_asset_render( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_Asset_Renderer' ) || ! class_exists( 'BizCity_CRM_Asset_Cache' ) ) {
			return new WP_Error( 'bizcity_crm_asset_renderer_unavailable', 'Asset renderer not loaded.', array( 'status' => 500 ) );
		}
		$cid  = (int) $req['id'];
		$key  = sanitize_key( (string) $req['key'] );
		$ext  = strtolower( (string) $req['ext'] );
		$opts = array(
			'headline'     => $req->get_param( 'headline' ),
			'cta_text'     => $req->get_param( 'cta_text' ),
			'voucher_code' => $req->get_param( 'voucher_code' ),
			'hotline'      => $req->get_param( 'hotline' ),
		);
		$opts = array_filter( $opts, static function ( $v ) { return $v !== null && $v !== ''; } );
		$force = (bool) $req->get_param( 'force' );

		$brand_hash = class_exists( 'BizCity_CRM_Brand_Kit' ) ? BizCity_CRM_Brand_Kit::hash() : '';
		// Cache key includes opts hash so per-render overrides do not collide.
		$opts_hash  = sha1( wp_json_encode( $opts ) );
		$composite_hash = $brand_hash . ':' . $opts_hash;

		$cached = $force ? null : BizCity_CRM_Asset_Cache::get( $cid, $key, $ext, $composite_hash );
		if ( $cached === null ) {
			$res = BizCity_CRM_Asset_Renderer::render( $cid, $key, $ext, $opts );
			if ( is_wp_error( $res ) ) { return $res; }
			BizCity_CRM_Asset_Cache::put( $cid, $key, $ext, $composite_hash, $res );
			$cached = $res;
		}

		// Stream directly so binary blobs aren't JSON-encoded.
		$mime  = (string) $cached['mime'];
		$bytes = (string) $cached['bytes'];
		$etag  = '"' . sha1( $mime . '|' . $composite_hash . '|' . strlen( $bytes ) ) . '"';

		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
		if ( $inm !== '' && $inm === $etag ) {
			status_header( 304 );
			header( 'ETag: ' . $etag );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'ETag: ' . $etag );
		if ( $ext === 'pdf' ) {
			header( 'Content-Disposition: inline; filename="campaign-' . $cid . '-' . $key . '.pdf"' );
		}
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — binary stream
		exit;
	}

	/** POST /campaigns/{id}/assets/{key}/regenerate — flush cached row(s) for that template. */
	public static function marketing_asset_regenerate( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_Asset_Cache' ) ) {
			return new WP_Error( 'bizcity_crm_asset_cache_unavailable', 'Cache module not loaded.', array( 'status' => 500 ) );
		}
		$cid = (int) $req['id'];
		$n   = BizCity_CRM_Asset_Cache::flush_campaign( $cid );
		return array(
			'campaign_id' => $cid,
			'flushed'     => $n,
		);
	}

/* ============================================================
 * Funnel Dashboard � bundled aggregator
 * ============================================================ */

/**
 * GET /dashboard/funnel-overview?days=7
 * Returns one payload powering the entire dashboard.
 */
public static function dashboard_funnel_overview( WP_REST_Request $req ) {
return self::wrap( static function () use ( $req ) {
global $wpdb;
$days = max( 1, min( 90, (int) ( $req->get_param( 'days' ) ?: 7 ) ) );
$now  = current_time( 'timestamp' );
$from = gmdate( 'Y-m-d 00:00:00', $now - ( $days - 1 ) * DAY_IN_SECONDS );
$prev_from = gmdate( 'Y-m-d 00:00:00', $now - ( 2 * $days - 1 ) * DAY_IN_SECONDS );
$prev_to   = gmdate( 'Y-m-d 23:59:59', $now - $days * DAY_IN_SECONDS );

$inb    = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
$conv   = BizCity_CRM_DB_Installer_V2::tbl_conversations();
$msg    = BizCity_CRM_DB_Installer_V2::tbl_messages();
$visits = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
$camps  = BizCity_CRM_DB_Installer_V2::tbl_campaigns();

/* ---------- KPI ---------- */
$reach_total = (int) $wpdb->get_var( $wpdb->prepare(
"SELECT COUNT(*) FROM {$visits} WHERE created_at >= %s",
$from
) );
$reach_prev = (int) $wpdb->get_var( $wpdb->prepare(
"SELECT COUNT(*) FROM {$visits} WHERE created_at BETWEEN %s AND %s",
$prev_from, $prev_to
) );
$reach_delta_pct = $reach_prev > 0 ? round( ( ( $reach_total - $reach_prev ) / $reach_prev ) * 100, 1 ) : null;

$inbox_total = (int) $wpdb->get_var( $wpdb->prepare(
"SELECT COUNT(*) FROM {$msg} WHERE created_at >= %s AND message_type = %s",
$from, 'incoming'
) );

$pipeline_count = (int) $wpdb->get_var(
"SELECT COUNT(*) FROM {$conv} WHERE status IN ('open','pending')"
);
$urgent_threshold = $now - DAY_IN_SECONDS;
$urgent_count = (int) $wpdb->get_var( $wpdb->prepare(
"SELECT COUNT(*) FROM {$conv} WHERE status IN ('open','pending') AND waiting_since IS NOT NULL AND waiting_since < %d",
$urgent_threshold
) );

$avg_response_seconds = (int) $wpdb->get_var( $wpdb->prepare(
"SELECT AVG(reply_lag) FROM (
SELECT TIMESTAMPDIFF(SECOND,
        MIN(CASE WHEN m.message_type = 'incoming' THEN m.created_at END),
        MIN(CASE WHEN m.message_type = 'outgoing' THEN m.created_at END)
) AS reply_lag
  FROM {$msg} m
 WHERE m.created_at >= %s
 GROUP BY m.conversation_id
HAVING reply_lag IS NOT NULL AND reply_lag > 0
) t",
$from
) );

$revenue_pipeline = 0.0;
$opps_tbl = method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_crm_opportunities' ) ? BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities() : '';
if ( $opps_tbl && (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $opps_tbl ) ) === $opps_tbl ) {
$revenue_pipeline = (float) $wpdb->get_var(
"SELECT COALESCE(SUM(amount), 0) FROM {$opps_tbl}
  WHERE COALESCE(stage, '') NOT IN ('won', 'lost', 'closed')
    AND COALESCE(deleted_at, '1970-01-01') = '1970-01-01'"
);
}

/* ---------- Time series ---------- */
$reach_rows = $wpdb->get_results( $wpdb->prepare(
"SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$visits} WHERE created_at >= %s GROUP BY DATE(created_at)",
$from
), ARRAY_A );
$inbox_rows = $wpdb->get_results( $wpdb->prepare(
"SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$msg} WHERE created_at >= %s AND message_type = %s GROUP BY DATE(created_at)",
$from, 'incoming'
), ARRAY_A );
$reach_map = array();
foreach ( (array) $reach_rows as $r ) { $reach_map[ $r['d'] ] = (int) $r['c']; }
$inbox_map = array();
foreach ( (array) $inbox_rows as $r ) { $inbox_map[ $r['d'] ] = (int) $r['c']; }
$timeseries = array();
for ( $i = $days - 1; $i >= 0; $i-- ) {
$day = gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS );
$timeseries[] = array(
'day'   => $day,
'reach' => $reach_map[ $day ] ?? 0,
'inbox' => $inbox_map[ $day ] ?? 0,
);
}

/* ---------- Channel palette ---------- */
$channel_palette = array(
'facebook'       => array( 'label' => 'Fanpage',   'color' => '#0ea5e9' ),
'instagram'      => array( 'label' => 'Instagram', 'color' => '#ec4899' ),
'zalo'           => array( 'label' => 'Zalo OA',   'color' => '#1d4ed8' ),
'whatsapp_cloud' => array( 'label' => 'WhatsApp',  'color' => '#22c55e' ),
'telegram'       => array( 'label' => 'Telegram',  'color' => '#06b6d4' ),
'web_widget'     => array( 'label' => 'Website',   'color' => '#10b981' ),
'email_imap'     => array( 'label' => 'Email',     'color' => '#f59e0b' ),
'tiktok'         => array( 'label' => 'TikTok',    'color' => '#0f172a' ),
'hotline'        => array( 'label' => 'Hotline',   'color' => '#a855f7' ),
);

/* ---------- By channel (donut) ---------- */
$by_channel_rows = $wpdb->get_results( $wpdb->prepare(
"SELECT i.channel_type AS ch, COUNT(c.id) AS n
   FROM {$conv} c
   JOIN {$inb} i ON i.id = c.inbox_id
  WHERE c.created_at >= %s
  GROUP BY i.channel_type
  ORDER BY n DESC",
$from
), ARRAY_A );
$by_channel = array();
foreach ( (array) $by_channel_rows as $r ) {
$ch   = (string) $r['ch'];
$meta = $channel_palette[ $ch ] ?? array( 'label' => ucfirst( $ch ), 'color' => '#64748b' );
$by_channel[] = array(
'channel'       => $ch,
'label'         => $meta['label'],
'color'         => $meta['color'],
'conversations' => (int) $r['n'],
);
}

/* ---------- Source quality (new vs returning) ---------- */
$src_rows = $wpdb->get_results( $wpdb->prepare(
"SELECT i.channel_type AS ch,
        SUM(CASE WHEN x.rn = 1 THEN 1 ELSE 0 END) AS new_n,
        SUM(CASE WHEN x.rn > 1 THEN 1 ELSE 0 END) AS ret_n
   FROM (
        SELECT c.id, c.inbox_id, c.contact_inbox_id,
               ROW_NUMBER() OVER (PARTITION BY c.contact_inbox_id ORDER BY c.created_at) AS rn
          FROM {$conv} c
         WHERE c.created_at >= %s
   ) x
   JOIN {$inb} i ON i.id = x.inbox_id
  GROUP BY i.channel_type
  ORDER BY (new_n + ret_n) DESC",
$from
), ARRAY_A );
$source_quality = array();
foreach ( (array) $src_rows as $r ) {
$ch   = (string) $r['ch'];
$meta = $channel_palette[ $ch ] ?? array( 'label' => ucfirst( $ch ), 'color' => '#64748b' );
$source_quality[] = array(
'channel'   => $ch,
'label'     => $meta['label'],
'new'       => (int) $r['new_n'],
'returning' => (int) $r['ret_n'],
);
}

/* ---------- Top campaigns ---------- */
$top_rows = $wpdb->get_results( $wpdb->prepare(
"SELECT c.id, c.code, c.name,
        COALESCE(v.visits, 0)      AS visits,
        COALESCE(v.conversions, 0) AS conversions
   FROM {$camps} c
   LEFT JOIN (
        SELECT campaign_id,
               COUNT(*) AS visits,
               SUM(CASE WHEN converted_contact_id IS NOT NULL THEN 1 ELSE 0 END) AS conversions
          FROM {$visits}
         WHERE created_at >= %s
         GROUP BY campaign_id
   ) v ON v.campaign_id = c.id
  WHERE COALESCE(c.deleted_at, '1970-01-01') = '1970-01-01'
  ORDER BY visits DESC
  LIMIT 5",
$from
), ARRAY_A );
$top_articles = array();
$rank = 0;
foreach ( (array) $top_rows as $r ) {
$rank++;
$visits_n      = (int) $r['visits'];
$conversions_n = (int) $r['conversions'];
$cvr           = $visits_n > 0 ? round( ( $conversions_n / $visits_n ) * 100, 2 ) : 0.0;
$top_articles[] = array(
'rank'           => $rank,
'campaign_id'    => (int) $r['id'],
'code'           => (string) $r['code'],
'title'          => (string) ( $r['name'] !== '' ? $r['name'] : $r['code'] ),
'reads'          => $visits_n,
'inbox'          => $conversions_n,
'conversion_pct' => $cvr,
);
}

$brand_name = '';
if ( class_exists( 'BizCity_CRM_Brand_Kit' ) ) {
$brand      = BizCity_CRM_Brand_Kit::get();
$brand_name = (string) ( $brand['brand_name'] ?? '' );
}

return array(
'window'        => array( 'days' => $days, 'from' => $from ),
'brand_name'    => $brand_name !== '' ? $brand_name : (string) get_bloginfo( 'name' ),
'kpi'           => array(
'revenue_pipeline'     => $revenue_pipeline,
'pipeline_count'       => $pipeline_count,
'urgent_followups'     => $urgent_count,
'reach_total'          => $reach_total,
'reach_delta_pct'      => $reach_delta_pct,
'inbox_total'          => $inbox_total,
'avg_response_seconds' => $avg_response_seconds,
),
'timeseries'    => $timeseries,
'by_channel'    => $by_channel,
'source_quality'=> $source_quality,
'top_articles'  => $top_articles,
);
} );
}

/**
 * GET /dashboard/crm-overview?from=YYYY-MM-DD&to=YYYY-MM-DD
 * Dashboard payload for CRM submissions overview cards/charts.
 */
public static function dashboard_crm_overview( WP_REST_Request $req ) {
	return self::wrap( static function () use ( $req ) {
		// [2026-07-06 Johnny Chu] PHASE-0.46 HOTFIX — provide FE-compatible overview payload.
		global $wpdb;

		$sub_tbl = $wpdb->prefix . 'bizcity_crm_submissions';
		$con_tbl = $wpdb->prefix . 'bizcity_crm_contacts';

		$from_raw = sanitize_text_field( (string) ( $req->get_param( 'from' ) ?: '' ) );
		$to_raw   = sanitize_text_field( (string) ( $req->get_param( 'to' ) ?: '' ) );
		$from     = $from_raw ? $from_raw . ' 00:00:00' : date( 'Y-m-01 00:00:00' );
		$to       = $to_raw ? $to_raw . ' 23:59:59' : current_time( 'mysql' );

		$result = array(
			'window'   => array( 'from' => $from, 'to' => $to ),
			'leads'    => array(
				'total'           => 0,
				'prev'            => 0,
				'delta_pct'       => 0,
				'assigned'        => 0,
				'unassigned'      => 0,
				'in_progress'     => 0,
				'closed_won'      => 0,
				'closed_lost'     => 0,
				'conversion_rate' => 0,
				'by_status'       => array(),
				'daily_trend'     => array(),
			),
			'agents'   => array(),
			'contacts' => array(
				'total'      => 0,
				'new_period' => 0,
			),
		);

		if ( ! bizcity_tbl_exists( $sub_tbl ) ) {
			return $result;
		}

		$from_ts = strtotime( $from );
		$to_ts   = strtotime( $to );
		$span    = max( DAY_IN_SECONDS, ( (int) $to_ts - (int) $from_ts ) + 1 );
		$prev_to_ts   = (int) $from_ts - 1;
		$prev_from_ts = $prev_to_ts - $span + 1;
		$prev_from    = gmdate( 'Y-m-d H:i:s', $prev_from_ts );
		$prev_to      = gmdate( 'Y-m-d H:i:s', $prev_to_ts );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sub_tbl}` WHERE submitted_at BETWEEN %s AND %s AND deleted_at IS NULL",
			$from,
			$to
		) );
		$prev = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sub_tbl}` WHERE submitted_at BETWEEN %s AND %s AND deleted_at IS NULL",
			$prev_from,
			$prev_to
		) );

		$assigned = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sub_tbl}` WHERE submitted_at BETWEEN %s AND %s AND deleted_at IS NULL AND assigned_to_wp_user_id IS NOT NULL AND assigned_to_wp_user_id > 0",
			$from,
			$to
		) );
		$unassigned = max( 0, $total - $assigned );

		$status_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT follow_status, COUNT(*) AS cnt
			 FROM `{$sub_tbl}`
			 WHERE submitted_at BETWEEN %s AND %s AND deleted_at IS NULL
			 GROUP BY follow_status",
			$from,
			$to
		), ARRAY_A );

		$by_status   = array();
		$in_progress = 0;
		$won         = 0;
		$lost        = 0;
		foreach ( is_array( $status_rows ) ? $status_rows : array() as $r ) {
			$status = (string) ( $r['follow_status'] !== '' ? $r['follow_status'] : 'new' );
			$cnt    = (int) $r['cnt'];
			$by_status[] = array(
				'status' => $status,
				'cnt'    => $cnt,
			);
			if ( in_array( $status, array( 'contacted', 'qualified', 'proposal_sent', 'negotiating' ), true ) ) {
				$in_progress += $cnt;
			}
			if ( $status === 'closed_won' ) {
				$won += $cnt;
			}
			if ( $status === 'closed_lost' ) {
				$lost += $cnt;
			}
		}

		$trend_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(submitted_at) AS day, COUNT(*) AS cnt
			 FROM `{$sub_tbl}`
			 WHERE submitted_at BETWEEN %s AND %s AND deleted_at IS NULL
			 GROUP BY DATE(submitted_at)
			 ORDER BY day ASC",
			$from,
			$to
		), ARRAY_A );
		$daily_trend = array();
		foreach ( is_array( $trend_rows ) ? $trend_rows : array() as $r ) {
			$daily_trend[] = array(
				'day' => (string) $r['day'],
				'cnt' => (int) $r['cnt'],
			);
		}

		$agent_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.ID AS user_id, u.display_name AS name,
			        COUNT(s.id) AS total,
			        SUM( CASE WHEN s.follow_status = 'new' THEN 1 ELSE 0 END ) AS cnt_new,
			        SUM( CASE WHEN s.follow_status IN ('contacted','qualified','proposal_sent','negotiating') THEN 1 ELSE 0 END ) AS in_progress,
			        SUM( CASE WHEN s.follow_status = 'closed_won' THEN 1 ELSE 0 END ) AS cnt_won,
			        SUM( CASE WHEN s.follow_status = 'closed_lost' THEN 1 ELSE 0 END ) AS cnt_lost
			 FROM {$wpdb->users} u
			 JOIN `{$sub_tbl}` s ON s.assigned_to_wp_user_id = u.ID
			 WHERE s.submitted_at BETWEEN %s AND %s AND s.deleted_at IS NULL
			 GROUP BY u.ID, u.display_name
			 ORDER BY cnt_won DESC, total DESC
			 LIMIT 20",
			$from,
			$to
		), ARRAY_A );
		$agents = array();
		foreach ( is_array( $agent_rows ) ? $agent_rows : array() as $r ) {
			$total_agent = (int) $r['total'];
			$won_agent   = (int) $r['cnt_won'];
			$agents[] = array(
				'user_id'     => (int) $r['user_id'],
				'name'        => (string) $r['name'],
				'total'       => $total_agent,
				'cnt_new'     => (int) $r['cnt_new'],
				'in_progress' => (int) $r['in_progress'],
				'cnt_won'     => $won_agent,
				'cnt_lost'    => (int) $r['cnt_lost'],
				'rate'        => $total_agent > 0 ? round( $won_agent / $total_agent * 100, 1 ) : 0,
			);
		}

		$contacts_total = 0;
		$contacts_new_period = 0;
		if ( bizcity_tbl_exists( $con_tbl ) ) {
			$contacts_total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$con_tbl}` WHERE deleted_at IS NULL"
			);
			$contacts_new_period = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$con_tbl}` WHERE created_at BETWEEN %s AND %s AND deleted_at IS NULL",
				$from,
				$to
			) );
		}

		$result['leads'] = array(
			'total'           => $total,
			'prev'            => $prev,
			'delta_pct'       => $prev > 0 ? round( ( ( $total - $prev ) / $prev ) * 100, 1 ) : 0,
			'assigned'        => $assigned,
			'unassigned'      => $unassigned,
			'in_progress'     => $in_progress,
			'closed_won'      => $won,
			'closed_lost'     => $lost,
			'conversion_rate' => $total > 0 ? round( $won / $total * 100, 1 ) : 0,
			'by_status'       => $by_status,
			'daily_trend'     => $daily_trend,
		);
		$result['agents']   = $agents;
		$result['contacts'] = array(
			'total'      => $contacts_total,
			'new_period' => $contacts_new_period,
		);

		return $result;
	} );
}

/**
 * GET /activities/recent?limit=50&offset=0
 * Global activity feed for dashboard card.
 */
public static function get_recent_activities( WP_REST_Request $req ) {
	return self::wrap( static function () use ( $req ) {
		// [2026-07-06 Johnny Chu] PHASE-0.46 HOTFIX — add global recent activity endpoint used by FE.
		global $wpdb;

		$act_tbl = $wpdb->prefix . 'bizcity_crm_activities';
		$sub_tbl = $wpdb->prefix . 'bizcity_crm_submissions';
		$cf7_tbl = $wpdb->prefix . 'bizcity_cf7_submissions';

		$limit  = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
		$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );

		if ( ! bizcity_tbl_exists( $act_tbl ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$act_tbl}` WHERE ( deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' )"
		);

		if ( bizcity_tbl_exists( $sub_tbl ) ) {
			// [2026-07-08 Johnny Chu] HOTFIX — crm_submissions uses contact_phone/contact_email; form_title belongs to cf7 table.
			$cf7_join = bizcity_tbl_exists( $cf7_tbl )
				? "LEFT JOIN `{$cf7_tbl}` cf7
				   ON cf7.id = a.entity_id
				  AND a.entity_type = 'cf7_submission'"
				: '';
			$cf7_cols = bizcity_tbl_exists( $cf7_tbl ) ? ', cf7.form_title AS form_title' : ", '' AS form_title";

			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT a.id, a.type, a.title, a.body, a.user_id, a.user_label, a.entity_type, a.entity_id, a.created_at,
				        s.contact_name, s.contact_phone AS contact_phone, s.contact_email AS contact_email, s.follow_status{$cf7_cols}
				 FROM `{$act_tbl}` a
				 LEFT JOIN `{$sub_tbl}` s
				   ON a.entity_id = s.id
				  AND a.entity_type IN ('cf7_submission','submission')
				 {$cf7_join}
				 WHERE ( a.deleted_at IS NULL OR a.deleted_at = '0000-00-00 00:00:00' )
				 ORDER BY a.created_at DESC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, type, title, body, user_id, user_label, entity_type, entity_id, created_at
				 FROM `{$act_tbl}`
				 WHERE ( deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' )
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			), ARRAY_A );
		}

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$uid          = (int) ( $r['user_id'] ?? 0 );
			$display_name = (string) ( $r['user_label'] ?? '' );
			if ( $uid && $display_name === '' ) {
				$u = get_userdata( $uid );
				$display_name = $u ? $u->display_name : "User#{$uid}";
			}

			$items[] = array(
				'id'            => (int) ( $r['id'] ?? 0 ),
				'type'          => (string) ( $r['type'] ?? 'note' ),
				'title'         => (string) ( $r['title'] ?? '' ),
				'body'          => (string) ( $r['body'] ?? '' ),
				'user'          => $display_name !== '' ? $display_name : 'System',
				'user_id'       => $uid,
				'user_label'    => (string) ( $r['user_label'] ?? '' ),
				'entity_type'   => (string) ( $r['entity_type'] ?? '' ),
				'entity_id'     => (int) ( $r['entity_id'] ?? 0 ),
				'created_at'    => (string) ( $r['created_at'] ?? '' ),
				'contact_name'  => (string) ( $r['contact_name'] ?? '' ),
				'contact_phone' => (string) ( $r['contact_phone'] ?? '' ),
				'contact_email' => (string) ( $r['contact_email'] ?? '' ),
				'form_title'    => (string) ( $r['form_title'] ?? '' ),
				'follow_status' => (string) ( $r['follow_status'] ?? '' ),
			);
		}

		return array(
			'items'  => $items,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		);
	} );
}

	/* =====================================================================
	 * PHASE-0.47 W3 — CF7 Submissions + Gift WC Orders + WC Products
	 * [2026-07-02 Johnny Chu] PHASE-0.47 W3
	 * ===================================================================== */

	/**
	 * GET /cf7-submissions — list CF7 submissions with filters.
	 *
	 * Query params: form_id, status, from, to, page, per_page, q (search)
	 */
	public static function get_cf7_submissions( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;

			$cf7_tbl = $wpdb->prefix . 'bizcity_cf7_submissions';
			if ( ! bizcity_tbl_exists( $cf7_tbl ) ) {
				return array( 'rows' => array(), 'total' => 0, 'pages' => 0, '_missing_table' => true );
			}

			// [2026-07-03 Johnny Chu] R-CF7-SEARCH — extended params: q, q_email, q_phone, q_name, q_assignee; filters: crm_action, follow_status, source_type
			$form_id       = (int) $req->get_param( 'form_id' );
			$crm_action    = sanitize_key( (string) ( $req->get_param( 'crm_action' ) ?: $req->get_param( 'status' ) ?: '' ) );
			$follow_status = sanitize_key( (string) ( $req->get_param( 'follow_status' ) ?: '' ) );
			$source_type   = sanitize_key( (string) ( $req->get_param( 'source_type' )   ?: '' ) );
			$from          = sanitize_text_field( (string) ( $req->get_param( 'from' )       ?: '' ) );
			$to            = sanitize_text_field( (string) ( $req->get_param( 'to' )         ?: '' ) );
			$q             = sanitize_text_field( (string) ( $req->get_param( 'q' )          ?: '' ) );
			$q_email       = sanitize_text_field( (string) ( $req->get_param( 'q_email' )    ?: '' ) );
			$q_phone       = sanitize_text_field( (string) ( $req->get_param( 'q_phone' )    ?: '' ) );
			$q_name        = sanitize_text_field( (string) ( $req->get_param( 'q_name' )     ?: '' ) );
			$q_assignee    = sanitize_text_field( (string) ( $req->get_param( 'q_assignee' ) ?: '' ) );
			$per_page      = max( 1, min( 200, (int) ( $req->get_param( 'per_page' ) ?: 20 ) ) );
			$page          = max( 1, (int) ( $req->get_param( 'page' ) ?: 1 ) );
			$offset        = ( $page - 1 ) * $per_page;

			$sub_tbl        = $wpdb->prefix . 'bizcity_crm_submissions';
			$sub_tbl_exists = bizcity_tbl_exists( $sub_tbl );

			$where  = array( 'deleted_at IS NULL' );
			$params = array();

			if ( $form_id > 0 ) {
				$where[]  = 'form_id = %d';
				$params[] = $form_id;
			}
			if ( $crm_action !== '' ) {
				$where[]  = 'crm_action = %s';
				$params[] = $crm_action;
			}
			if ( $from !== '' ) {
				$where[]  = 'submitted_at >= %s';
				$params[] = $from . ' 00:00:00';
			}
			if ( $to !== '' ) {
				$where[]  = 'submitted_at <= %s';
				$params[] = $to . ' 23:59:59';
			}
			if ( $q !== '' ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				if ( $sub_tbl_exists ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$where[]  = "( email LIKE %s OR phone LIKE %s OR form_title LIKE %s OR id IN ( SELECT source_ref_id FROM `{$sub_tbl}` WHERE source_type = 'cf7' AND contact_name LIKE %s AND deleted_at IS NULL ) )";
					$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
				} else {
					$where[]  = '( email LIKE %s OR phone LIKE %s OR form_title LIKE %s )';
					$params[] = $like; $params[] = $like; $params[] = $like;
				}
			}
			if ( $q_email !== '' ) {
				$where[]  = 'email LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $q_email ) . '%';
			}
			if ( $q_phone !== '' ) {
				$where[]  = 'phone LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $q_phone ) . '%';
			}
			if ( $q_name !== '' && $sub_tbl_exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where[]  = "id IN ( SELECT source_ref_id FROM `{$sub_tbl}` WHERE source_type = 'cf7' AND contact_name LIKE %s AND deleted_at IS NULL )";
				$params[] = '%' . $wpdb->esc_like( $q_name ) . '%';
			}
			if ( $follow_status !== '' && $sub_tbl_exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where[]  = "id IN ( SELECT source_ref_id FROM `{$sub_tbl}` WHERE source_type = 'cf7' AND follow_status = %s AND deleted_at IS NULL )";
				$params[] = $follow_status;
			}
			if ( $source_type !== '' && $sub_tbl_exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where[]  = "id IN ( SELECT source_ref_id FROM `{$sub_tbl}` WHERE source_type = %s AND deleted_at IS NULL )";
				$params[] = $source_type;
			}
			if ( $q_assignee !== '' && $sub_tbl_exists ) {
				$matched_uids = get_users( array( 'search' => '*' . $q_assignee . '*', 'search_columns' => array( 'display_name', 'user_login' ), 'fields' => 'ID', 'number' => 50 ) );
				if ( ! empty( $matched_uids ) ) {
					$uid_in  = implode( ',', array_map( 'intval', $matched_uids ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
					$where[] = "id IN ( SELECT source_ref_id FROM `{$sub_tbl}` WHERE source_type = 'cf7' AND assigned_to_wp_user_id IN ({$uid_in}) AND deleted_at IS NULL )";
				} else {
					$where[] = '1=0';
				}
			}

			$where_sql = implode( ' AND ', $where );

			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — Query 1: paginated CF7 rows only (no JOIN)
			// Rationale: multisite multi-shard setup — avoid LEFT JOIN across global wp_users + 3 tables
			// which causes GROUP BY issues and silent empty result on some shard configs.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			if ( ! empty( $params ) ) {
				$total = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM `{$cf7_tbl}` WHERE {$where_sql}",
					...$params
				) );
				$cf7_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM `{$cf7_tbl}` WHERE {$where_sql} ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
					array_merge( $params, array( $per_page, $offset ) )
				), ARRAY_A );
			} else {
				$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$cf7_tbl}` WHERE {$where_sql}" );
				$cf7_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM `{$cf7_tbl}` WHERE {$where_sql} ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
					$per_page, $offset
				), ARRAY_A );
			}
			// phpcs:enable

			if ( empty( $cf7_rows ) ) {
				return array( 'rows' => array(), 'total' => $total, 'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1 );
			}

			// Collect CF7 IDs for batch lookups
			$cf7_ids = array_map( 'intval', array_column( $cf7_rows, 'id' ) );
			$ids_in  = implode( ',', $cf7_ids );

			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — Query 2: unified submissions batch (same-shard)
			$sub_map = array(); // keyed by source_ref_id (= cf7.id)
			$sub_tbl = $wpdb->prefix . 'bizcity_crm_submissions';
			if ( bizcity_tbl_exists( $sub_tbl ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$sub_rows = $wpdb->get_results(
					"SELECT id, source_ref_id, contact_name, contact_phone, contact_email,
					        follow_status, assigned_to_wp_user_id, source_meta_json
					 FROM `{$sub_tbl}`
					 WHERE source_type = 'cf7' AND source_ref_id IN ({$ids_in}) AND deleted_at IS NULL",
					ARRAY_A
				);
				foreach ( is_array( $sub_rows ) ? $sub_rows : array() as $s ) {
					$sub_map[ (int) $s['source_ref_id'] ] = $s;
				}
			}

			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — Query 3: activity stats batch (same-shard)
			$act_map = array(); // keyed by entity_id (= cf7.id)
			$act_tbl = $wpdb->prefix . 'bizcity_crm_activities';
			if ( bizcity_tbl_exists( $act_tbl ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$act_rows = $wpdb->get_results(
					"SELECT entity_id, COUNT(*) AS act_count, MIN(type) AS act_type,
					        MIN(title) AS act_title, MIN(body) AS act_body, MAX(created_at) AS act_at
					 FROM `{$act_tbl}`
					 WHERE entity_type = 'cf7_submission' AND entity_id IN ({$ids_in})
					   AND ( deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' )
					 GROUP BY entity_id",
					ARRAY_A
				);
				foreach ( is_array( $act_rows ) ? $act_rows : array() as $a ) {
					$act_map[ (int) $a['entity_id'] ] = $a;
				}
			}

			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — Query 4: assignee display_names (WP native cache, avoids raw JOIN)
			$assignee_map = array(); // keyed by wp_user_id
			$assignee_ids = array();
			foreach ( $sub_map as $s ) {
				$uid = (int) ( $s['assigned_to_wp_user_id'] ?? 0 );
				if ( $uid > 0 ) { $assignee_ids[ $uid ] = true; }
			}
			foreach ( array_keys( $assignee_ids ) as $uid ) {
				$u = get_userdata( $uid ); // uses WP object cache — no raw JOIN on wp_users
				if ( $u ) { $assignee_map[ $uid ] = $u->display_name; }
			}

			// Build output — merge 4 data sources in PHP
			$out = array();
			foreach ( $cf7_rows as $r ) {
				$cf7_id      = (int) $r['id'];
				$sub         = $sub_map[ $cf7_id ] ?? null;
				$act         = $act_map[ $cf7_id ] ?? null;
				$raw         = $r['raw_data']  ? json_decode( $r['raw_data'],  true ) : array();
				$mapped      = $r['mapped_data'] ? json_decode( $r['mapped_data'], true ) : array();
				$source_meta = ( $sub && $sub['source_meta_json'] )
					? json_decode( $sub['source_meta_json'], true )
					: array();

				// Build gift_orders with wc_order_url
				$raw_gifts   = ( is_array( $source_meta ) && isset( $source_meta['gifts'] ) && is_array( $source_meta['gifts'] ) )
					? $source_meta['gifts']
					: array();
				$gift_orders = array();
				foreach ( $raw_gifts as $slot_key => $g ) {
					$wc_oid = isset( $g['wc_order_id'] ) ? (int) $g['wc_order_id'] : 0;
					if ( ! $wc_oid ) { continue; }
					$gift_orders[ $slot_key ] = array(
						'wc_order_id'  => $wc_oid,
						'wc_order_url' => admin_url( 'admin.php?page=wc-orders&id=' . $wc_oid ),
						'created_at'   => $g['created_at'] ?? '',
					);
				}

				$assigned_uid  = $sub ? (int) ( $sub['assigned_to_wp_user_id'] ?? 0 ) : 0;
				$assignee_name = $assigned_uid ? ( $assignee_map[ $assigned_uid ] ?? null ) : null;

				$out[] = array(
					'id'             => $cf7_id,
					'form_id'        => (int) $r['form_id'],
					'form_title'     => (string) $r['form_title'],
					'email'          => (string) $r['email'],
					'phone'          => (string) $r['phone'],
					'crm_action'     => (string) $r['crm_action'],
					'crm_error'      => (string) ( $r['crm_error'] ?? '' ),
					'source_url'     => (string) ( $r['source_url'] ?? '' ),
					'submitted_at'   => (string) $r['submitted_at'],
					'raw_data'       => is_array( $raw )    ? $raw    : array(),
					'mapped_data'    => is_array( $mapped ) ? $mapped : array(),
					// unified submission fields (from sub_map)
					'unified_submission_id'  => $sub ? (int) $sub['id'] : null,
					'crm_contact_name'       => $sub ? (string) $sub['contact_name']  : null,
					'crm_contact_phone'      => $sub ? (string) $sub['contact_phone'] : null,
					'crm_contact_email'      => $sub ? (string) $sub['contact_email'] : null,
					'follow_status'          => $sub ? (string) $sub['follow_status'] : null,
					'assigned_to_wp_user_id' => $assigned_uid ?: null,
					'assignee_name'          => $assignee_name,
					'source_meta'            => is_array( $source_meta ) ? $source_meta : array(),
					'gifts'                  => $raw_gifts,
					'gift_orders'            => $gift_orders,
					// activity fields (from act_map)
					'activity_count'  => $act ? (int) $act['act_count']      : 0,
					'activity_status' => $act ? (string) $act['act_type']    : '',
					'activity_title'  => $act ? (string) $act['act_title']   : '',
					'activity_body'   => $act ? (string) $act['act_body']    : '',
					'activity_at'     => $act ? (string) $act['act_at']      : '',
				);
			}

			return array(
				'rows'  => $out,
				'total' => $total,
				'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
			);
		} );
	}

	/**
	 * GET /cf7-submissions/forms — list distinct form IDs + titles.
	 */
	public static function get_cf7_submission_forms( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$cf7_tbl = $wpdb->prefix . 'bizcity_cf7_submissions';
			if ( ! bizcity_tbl_exists( $cf7_tbl ) ) {
				return array( 'forms' => array() );
			}
			$rows = $wpdb->get_results(
				"SELECT form_id, form_title, COUNT(*) AS submission_count
				 FROM `{$cf7_tbl}`
				 WHERE deleted_at IS NULL
				 GROUP BY form_id, form_title
				 ORDER BY submission_count DESC",
				ARRAY_A
			);
			$forms = array();
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				$forms[] = array(
					'form_id'          => (int) $r['form_id'],
					'form_title'       => (string) $r['form_title'],
					'submission_count' => (int) $r['submission_count'],
				);
			}
			return array( 'forms' => $forms );
		} );
	}

	/**
	 * GET /cf7-submissions/funnel-stats — funnel breakdown from bizcity_crm_submissions.
	 *
	 * Query params: days, from (YYYY-MM-DD), to (YYYY-MM-DD)
	 */
	public static function get_submission_funnel_stats( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;

			$sub_tbl = $wpdb->prefix . 'bizcity_crm_submissions';
			$cf7_tbl = $wpdb->prefix . 'bizcity_cf7_submissions';

			// [2026-07-07 Johnny Chu] HOTFIX — respect FE day tabs (7/14/30/90), default days=30.
			$days = (int) $req->get_param( 'days' );
			$days = $days > 0 ? max( 1, min( 365, $days ) ) : 30;

			$from_raw = sanitize_text_field( (string) ( $req->get_param( 'from' ) ?: '' ) );
			$to_raw   = sanitize_text_field( (string) ( $req->get_param( 'to' )   ?: '' ) );
			$from     = $from_raw ? $from_raw . ' 00:00:00' : gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $days - 1 ) . ' days' ) );
			$to       = $to_raw   ? $to_raw   . ' 23:59:59' : current_time( 'mysql' );

			$result = array(
				'period'     => array( 'from' => $from, 'to' => $to, 'days' => $days ),
				'volume'     => array(
					'total'           => 0,
					'prev_total'      => 0,
					'delta_pct'       => 0,
					'pending'         => 0,
					'active'          => 0,
					'converted'       => 0,
					'lost'            => 0,
					'conversion_rate' => 0,
					'by_status'       => array(),
				),
				'by_channel' => array(),
				'timeline'   => array(),
				'daily_report' => array(),
				'agents'     => array(),
				'cf7_sync'   => array(
					'total_all_time' => 0,
					'period_created' => 0,
					'period_updated' => 0,
					'period_skipped' => 0,
					'period_error'   => 0,
				),
			);

			// CF7 all-time total
			if ( bizcity_tbl_exists( $cf7_tbl ) ) {
				$result['cf7_sync']['total_all_time'] = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM `{$cf7_tbl}` WHERE deleted_at IS NULL"
				);
				$sync_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT crm_action, COUNT(*) AS cnt
					 FROM `{$cf7_tbl}`
					 WHERE created_at BETWEEN %s AND %s
					 GROUP BY crm_action",
					$from,
					$to
				), ARRAY_A );
				foreach ( is_array( $sync_rows ) ? $sync_rows : array() as $sr ) {
					$k = 'period_' . (string) $sr['crm_action'];
					if ( isset( $result['cf7_sync'][ $k ] ) ) {
						$result['cf7_sync'][ $k ] = (int) $sr['cnt'];
					}
				}
			}

			if ( ! bizcity_tbl_exists( $sub_tbl ) ) {
				return $result;
			}

			// Total in period + by_status + period delta
			$status_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT follow_status, COUNT(*) AS cnt
				 FROM `{$sub_tbl}`
				 WHERE submitted_at BETWEEN %s AND %s AND deleted_at IS NULL
				 GROUP BY follow_status
				 ORDER BY cnt DESC",
				$from, $to
			), ARRAY_A );

			$total = 0;
			$by_status = array();
			foreach ( is_array( $status_rows ) ? $status_rows : array() as $r ) {
				$total += (int) $r['cnt'];
				$by_status[] = array(
					'status' => (string) $r['follow_status'],
					'cnt'    => (int) $r['cnt'],
				);
			}

			$interval_secs = max( 1, strtotime( $to ) - strtotime( $from ) );
			$prev_from     = gmdate( 'Y-m-d H:i:s', strtotime( $from ) - $interval_secs );
			$prev_to       = gmdate( 'Y-m-d H:i:s', strtotime( $from ) - 1 );
			$prev_total    = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$sub_tbl}`
				 WHERE deleted_at IS NULL AND submitted_at BETWEEN %s AND %s",
				$prev_from,
				$prev_to
			) );
			$delta_pct = $prev_total > 0 ? (int) round( ( ( $total - $prev_total ) / $prev_total ) * 100 ) : 0;

			$pending_statuses   = array( 'new', 'pending' );
			$active_statuses    = array( 'contacted', 'qualified', 'proposal_sent', 'negotiating' );
			$converted_statuses = array( 'closed_won', 'delivered' );
			$lost_statuses      = array( 'closed_lost', 'invalid' );
			$cnt_pending = 0;
			$cnt_active = 0;
			$cnt_converted = 0;
			$cnt_lost = 0;
			foreach ( $by_status as $s ) {
				if ( in_array( $s['status'], $pending_statuses, true ) ) {
					$cnt_pending += $s['cnt'];
				}
				if ( in_array( $s['status'], $active_statuses, true ) ) {
					$cnt_active += $s['cnt'];
				}
				if ( in_array( $s['status'], $converted_statuses, true ) ) {
					$cnt_converted += $s['cnt'];
				}
				if ( in_array( $s['status'], $lost_statuses, true ) ) {
					$cnt_lost += $s['cnt'];
				}
			}
			$conversion_rate = $total > 0 ? round( ( $cnt_converted / $total ) * 100, 1 ) : 0.0;

			$result['volume'] = array(
				'total'           => $total,
				'prev_total'      => $prev_total,
				'delta_pct'       => $delta_pct,
				'pending'         => $cnt_pending,
				'active'          => $cnt_active,
				'converted'       => $cnt_converted,
				'lost'            => $cnt_lost,
				'conversion_rate' => $conversion_rate,
				'by_status'       => $by_status,
			);

			// By channel (source_type)
			$ch_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT source_type AS channel, COUNT(*) AS leads,
				        SUM( CASE WHEN follow_status IN ('contacted','qualified','proposal_sent','negotiating','closed_won') THEN 1 ELSE 0 END ) AS contacted,
				        SUM( CASE WHEN follow_status = 'closed_won' THEN 1 ELSE 0 END ) AS converted
				 FROM `{$sub_tbl}`
				 WHERE submitted_at BETWEEN %s AND %s AND deleted_at IS NULL
				 GROUP BY source_type
				 ORDER BY leads DESC",
				$from, $to
			), ARRAY_A );

			$ch_labels = array(
				'facebook'  => 'Facebook',
				'zns'       => 'ZNS',
				'zalo'      => 'Zalo OA',
				'ladi'      => 'Ladi.vn',
				'telegram'  => 'Telegram',
				'affiliate' => 'Affiliate',
				'organic'   => 'Organic',
				'direct'    => 'Direct',
				'manual'    => 'Thủ công',
				'cf7'       => 'CF7',
			);
			$by_channel = array();
			foreach ( is_array( $ch_rows ) ? $ch_rows : array() as $r ) {
				$channel   = (string) $r['channel'];
				if ( $channel === 'facebook_lead' ) {
					$channel = 'facebook';
				}
				if ( $channel === 'zalo_oa' ) {
					$channel = 'zalo';
				}
				if ( $channel === '' ) {
					$channel = 'direct';
				}
				$leads = (int) $r['leads'];
				$converted = (int) $r['converted'];
				$by_channel[] = array(
					'channel'   => $channel,
					'label'     => isset( $ch_labels[ $channel ] ) ? $ch_labels[ $channel ] : ucfirst( $channel ),
					'leads'     => $leads,
					'contacted' => (int) $r['contacted'],
					'converted' => $converted,
					'rate'      => $leads > 0 ? round( $converted / $leads * 100, 1 ) : 0,
				);
			}
			$result['by_channel'] = $by_channel;

			// [2026-07-07 Johnny Chu] PHASE-0.47 W0 — prefill all days so timeline does not skip empty days.
			$range_from_day = gmdate( 'Y-m-d', strtotime( $from ) );
			$range_to_day   = gmdate( 'Y-m-d', strtotime( $to ) );
			$days_map       = array();
			$cursor_ts      = strtotime( $range_from_day );
			$end_ts         = strtotime( $range_to_day );
			while ( $cursor_ts <= $end_ts ) {
				$day = gmdate( 'Y-m-d', $cursor_ts );
				$days_map[ $day ] = array(
					'day'      => $day,
					'facebook' => 0,
					'zns'      => 0,
					'zalo'     => 0,
					'ladi'     => 0,
					'cf7'      => 0,
					'other'    => 0,
				);
				$cursor_ts = strtotime( '+1 day', $cursor_ts );
			}
			$t_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(submitted_at) AS day, source_type, COUNT(*) AS cnt
				 FROM `{$sub_tbl}`
				 WHERE deleted_at IS NULL AND submitted_at BETWEEN %s AND %s
				 GROUP BY DATE(submitted_at), source_type
				 ORDER BY day ASC",
				$from,
				$to
			), ARRAY_A );
			foreach ( is_array( $t_rows ) ? $t_rows : array() as $r ) {
				$day = (string) $r['day'];
				$ch  = (string) $r['source_type'];
				if ( $ch === 'facebook_lead' ) {
					$ch = 'facebook';
				}
				if ( $ch === 'zalo_oa' ) {
					$ch = 'zalo';
				}
				if ( ! isset( $days_map[ $day ] ) ) {
					$days_map[ $day ] = array(
						'day'      => $day,
						'facebook' => 0,
						'zns'      => 0,
						'zalo'     => 0,
						'ladi'     => 0,
						'cf7'      => 0,
						'other'    => 0,
					);
				}
				$known = array( 'facebook', 'zns', 'zalo', 'ladi', 'cf7' );
				if ( in_array( $ch, $known, true ) ) {
					$days_map[ $day ][ $ch ] += (int) $r['cnt'];
				} else {
					$days_map[ $day ]['other'] += (int) $r['cnt'];
				}
			}
			ksort( $days_map );
			$result['timeline'] = array_values( $days_map );

			// [2026-07-07 Johnny Chu] PHASE-0.47 W0 — daily report rows for table view.
			$daily_map = array();
			$cursor_ts = strtotime( $range_from_day );
			while ( $cursor_ts <= $end_ts ) {
				$day = gmdate( 'Y-m-d', $cursor_ts );
				$daily_map[ $day ] = array(
					'day'             => $day,
					'leads'           => 0,
					'contacted'       => 0,
					'converted'       => 0,
					'lost'            => 0,
					'conversion_rate' => 0.0,
				);
				$cursor_ts = strtotime( '+1 day', $cursor_ts );
			}
			$d_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(submitted_at) AS day,
				        COUNT(*) AS leads,
				        SUM(CASE WHEN follow_status IN ('contacted','qualified','proposal_sent','negotiating') THEN 1 ELSE 0 END) AS contacted,
				        SUM(CASE WHEN follow_status IN ('closed_won','delivered') THEN 1 ELSE 0 END) AS converted,
				        SUM(CASE WHEN follow_status IN ('closed_lost','invalid') THEN 1 ELSE 0 END) AS lost
				 FROM `{$sub_tbl}`
				 WHERE deleted_at IS NULL AND submitted_at BETWEEN %s AND %s
				 GROUP BY DATE(submitted_at)
				 ORDER BY day ASC",
				$from,
				$to
			), ARRAY_A );
			foreach ( is_array( $d_rows ) ? $d_rows : array() as $dr ) {
				$day = (string) $dr['day'];
				if ( ! isset( $daily_map[ $day ] ) ) {
					$daily_map[ $day ] = array(
						'day'             => $day,
						'leads'           => 0,
						'contacted'       => 0,
						'converted'       => 0,
						'lost'            => 0,
						'conversion_rate' => 0.0,
					);
				}
				$daily_map[ $day ]['leads']     = (int) $dr['leads'];
				$daily_map[ $day ]['contacted'] = (int) $dr['contacted'];
				$daily_map[ $day ]['converted'] = (int) $dr['converted'];
				$daily_map[ $day ]['lost']      = (int) $dr['lost'];
				$daily_map[ $day ]['conversion_rate'] = ( (int) $dr['leads'] > 0 )
					? round( ( (int) $dr['converted'] / (int) $dr['leads'] ) * 100, 1 )
					: 0.0;
			}
			ksort( $daily_map );
			$result['daily_report'] = array_values( $daily_map );

			// Agent leaderboard
			$agent_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT u.ID AS user_id, u.display_name AS name,
				        COUNT(s.id)                                                         AS assigned,
				        SUM( CASE WHEN s.follow_status IN ('contacted','qualified','proposal_sent','negotiating','closed_won') THEN 1 ELSE 0 END ) AS contacted,
				        SUM( CASE WHEN s.follow_status = 'closed_won' THEN 1 ELSE 0 END )  AS converted
				 FROM {$wpdb->users} u
				 JOIN `{$sub_tbl}` s ON s.assigned_to_wp_user_id = u.ID
				 WHERE s.submitted_at BETWEEN %s AND %s AND s.deleted_at IS NULL
				 GROUP BY u.ID, u.display_name
				 ORDER BY converted DESC
				 LIMIT 20",
				$from, $to
			), ARRAY_A );

			$agents = array();
			foreach ( is_array( $agent_rows ) ? $agent_rows : array() as $r ) {
				$assigned  = (int) $r['assigned'];
				$converted = (int) $r['converted'];
				$agents[] = array(
					'user_id'   => (int) $r['user_id'],
					'name'      => (string) $r['name'],
					'assigned'  => $assigned,
					'contacted' => (int) $r['contacted'],
					'converted' => $converted,
					'rate'      => $assigned > 0 ? round( $converted / $assigned * 100, 1 ) : 0,
				);
			}
			$result['agents'] = $agents;

			return $result;
		} );
	}

	/**
	 * GET /cf7-submissions/activities/stats — activity counts per submission.
	 */
	public static function get_submission_activities_stats( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$act_tbl = $wpdb->prefix . 'bizcity_crm_activities';
			if ( ! bizcity_tbl_exists( $act_tbl ) ) {
				return array( 'stats' => array() );
			}
			$rows = $wpdb->get_results(
				"SELECT entity_id AS submission_id, COUNT(*) AS activity_count, MAX(created_at) AS last_activity_at
				 FROM `{$act_tbl}`
				 WHERE entity_type = 'cf7_submission' AND deleted_at IS NULL
				 GROUP BY entity_id",
				ARRAY_A
			);
			$stats = array();
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				$stats[] = array(
					'submission_id'    => (int) $r['submission_id'],
					'activity_count'   => (int) $r['activity_count'],
					'last_activity_at' => (string) $r['last_activity_at'],
				);
			}
			return array( 'stats' => $stats );
		} );
	}

	/**
	 * POST /cf7-submissions/bulk-assign — bulk assign submissions to WP user.
	 * Body: { ids: int[], wp_user_id: int }
	 */
	public static function post_cf7_submissions_bulk_assign( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body       = (array) $req->get_json_params();
			$ids        = array_filter( array_map( 'intval', (array) ( $body['ids'] ?? array() ) ) );
			$wp_user_id = (int) ( $body['wp_user_id'] ?? 0 );

			if ( empty( $ids ) ) {
				throw new RuntimeException( 'Thiếu danh sách submission IDs.' );
			}
			if ( ! $wp_user_id ) {
				throw new RuntimeException( 'Thiếu wp_user_id.' );
			}

			if ( class_exists( 'BizCity_CRM_Submissions_Repo' ) ) {
				$updated = BizCity_CRM_Submissions_Repo::bulk_assign( $ids, $wp_user_id, get_current_user_id() );
				return array( 'updated' => $updated );
			}

			// Fallback: direct query on unified submissions table
			global $wpdb;
			$sub_tbl = $wpdb->prefix . 'bizcity_crm_submissions';
			if ( ! bizcity_tbl_exists( $sub_tbl ) ) {
				return array( 'updated' => 0, '_missing_table' => true );
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$now          = current_time( 'mysql' );
			$assigner     = get_current_user_id();
			$updated      = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"UPDATE `{$sub_tbl}` SET assigned_to_wp_user_id=%d, assigned_at=%s, assigned_by_wp_user_id=%d, updated_at=%s WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
					array_merge( array( $wp_user_id, $now, $assigner, $now ), $ids )
				)
			);
			return array( 'updated' => (int) $updated );
		} );
	}

	/**
	 * GET /cf7-submissions/{id}/activities — list activities for a submission.
	 */
	public static function get_submission_activities( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id      = (int) $req['id'];
			$act_tbl = $wpdb->prefix . 'bizcity_crm_activities';

			if ( ! bizcity_tbl_exists( $act_tbl ) ) {
				return array( 'activities' => array() );
			}

			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — no JOIN wp_users (multisite multishard)
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$act_tbl}`
					 WHERE entity_type = 'cf7_submission' AND entity_id = %d
					   AND ( deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' )
					 ORDER BY created_at DESC",
					$id
				),
				ARRAY_A
			);

			$out = array();
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				$uid          = (int) ( $r['user_id'] ?? 0 );
				$display_name = (string) ( $r['user_label'] ?? '' );
				if ( $uid && ! $display_name ) {
					$u            = get_userdata( $uid ); // WP object cache — safe on multishard
					$display_name = $u ? $u->display_name : "User#{$uid}";
				}
				$out[] = array(
					'id'                    => (int) $r['id'],
					'type'                  => (string) $r['type'],
					'title'                 => (string) $r['title'],
					'body'                  => (string) $r['body'],
					'user_id'               => $uid,
					'user_label'            => (string) $r['user_label'],
					'user_display_name'     => $display_name,
					'call_date'             => $r['call_date'] ?? null,
					'call_agent_wp_user_id' => isset( $r['call_agent_wp_user_id'] ) ? (int) $r['call_agent_wp_user_id'] : null,
					'call_agent_label'      => $r['call_agent_label'] ?? null,
					'created_at'            => (string) $r['created_at'],
				);
			}

			return array( 'activities' => $out );
		} );
	}

	/**
	 * POST /cf7-submissions/{id}/activities — add a new activity for a submission.
	 * Body: { type, title, body, call_date?, call_agent_id? }
	 */
	public static function post_submission_activity( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id   = (int) $req['id'];
			$body = (array) $req->get_json_params();

			$type  = sanitize_key( (string) ( $body['type']  ?? 'note' ) );
			$title = sanitize_text_field( (string) ( $body['title'] ?? '' ) );
			$text  = sanitize_textarea_field( (string) ( $body['body'] ?? '' ) );
			$uid   = get_current_user_id();
			$uname = wp_get_current_user()->display_name ?: "User#{$uid}";
			$now   = current_time( 'mysql' );

			// [2026-07-01 Johnny Chu] PHASE-0.47 W1 — accept call_date + call_agent_id
			$call_date_raw = (string) ( $body['call_date'] ?? '' );
			$call_agent_id = (int) ( $body['call_agent_id'] ?? 0 );
			$call_date     = null;
			if ( $call_date_raw ) {
				$ts = strtotime( $call_date_raw );
				if ( $ts ) {
					$call_date = gmdate( 'Y-m-d H:i:s', $ts );
				}
			}
			$call_agent_label = '';
			if ( $call_agent_id > 0 ) {
				$agent_user = get_userdata( $call_agent_id );
				$call_agent_label = $agent_user ? $agent_user->display_name : "User#{$call_agent_id}";
			}

			$act_tbl = $wpdb->prefix . 'bizcity_crm_activities';
			if ( ! bizcity_tbl_exists( $act_tbl ) ) {
				throw new RuntimeException( 'Bảng activities chưa tồn tại. Vui lòng chạy migration.' );
			}

			$row = array(
				'entity_type'  => 'cf7_submission',
				'entity_id'    => $id,
				'type'         => $type,
				'title'        => $title,
				'body'         => $text,
				'user_id'      => $uid ?: null,
				'user_label'   => $uname,
				'created_at'   => $now,
			);

			// Add call columns if they exist in the table
			if ( $call_date !== null ) {
				$row['call_date'] = $call_date;
			}
			if ( $call_agent_id > 0 ) {
				$row['call_agent_wp_user_id'] = $call_agent_id;
				$row['call_agent_label']      = $call_agent_label;
			}

			$wpdb->insert( $act_tbl, $row );
			$new_id = (int) $wpdb->insert_id;

			if ( ! $new_id ) {
				throw new RuntimeException( 'Ghi activity thất bại.' );
			}

			return array( 'id' => $new_id );
		} );
	}

	/**
	 * PATCH /cf7-submissions/{id}/status
	 * Body: { follow_status: string }
	 *
	 * [2026-07-03 Johnny Chu] R-CF7-STATUS — inline follow_status auto-save
	 */
	public static function patch_submission_status( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$id      = (int) $req['id'];
			$body    = $req->get_json_params();
			$allowed = array( 'new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'closed_won', 'closed_lost', 'invalid' );
			$status  = isset( $body['follow_status'] ) ? (string) $body['follow_status'] : '';
			if ( ! in_array( $status, $allowed, true ) ) {
				throw new InvalidArgumentException( 'follow_status không hợp lệ.' );
			}

			$sub_tbl = $wpdb->prefix . 'bizcity_crm_submissions';
			if ( bizcity_tbl_exists( $sub_tbl ) ) {
				$exists = $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM `{$sub_tbl}` WHERE source_type = 'cf7' AND source_ref_id = %d LIMIT 1", $id )
				);
				if ( $exists ) {
					$wpdb->update( $sub_tbl, array( 'follow_status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'source_ref_id' => $id, 'source_type' => 'cf7' ), array( '%s', '%s' ), array( '%d', '%s' ) );
				} else {
					$wpdb->insert( $sub_tbl, array( 'source_type' => 'cf7', 'source_ref_id' => $id, 'follow_status' => $status, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
				}
			}

			return array( 'id' => $id, 'follow_status' => $status );
		} );
	}

	/**
	 * POST /cf7-submissions/{id}/create-gift-wc-order
	 *
	 * Body: {
	 *   items: [{ wc_product_id, qty, unit_price, name }]  ← multi-product array (required)
	 *   coupon_code:       string (optional)
	 *   order_discount:    float  (optional, flat discount in VND)
	 *   recipient_name:    string (required)
	 *   recipient_phone:   string (required)
	 *   recipient_address: string (required)
	 *   recipient_note:    string (optional)
	 *   --- legacy single-product compat ---
	 *   wc_product_id:     int    (used if items absent)
	 *   gift_product_name: string
	 *   gift_slot:         string
	 * }
	 *
	 * [2026-07-02 Johnny Chu] PHASE-0.47 W3 — tạo WC order gift, link wc_order_id vào source_meta_json
	 * [2026-07-03 Johnny Chu] PHASE-0.47 FIX — support multi-product items[], qty, unit_price, coupon, discount
	 */
	public static function post_create_gift_wc_order( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! function_exists( 'wc_create_order' ) ) {
				throw new RuntimeException( 'WooCommerce chưa kích hoạt trên site này.' );
			}

			$cf7_id = (int) $req['id'];
			$body   = (array) $req->get_json_params();

			// Validate recipient info
			$rname  = sanitize_text_field( (string) ( $body['recipient_name']    ?? '' ) );
			$rphone = sanitize_text_field( (string) ( $body['recipient_phone']   ?? '' ) );
			$raddr  = sanitize_text_field( (string) ( $body['recipient_address'] ?? '' ) );
			$rnote  = sanitize_text_field( (string) ( $body['recipient_note']    ?? '' ) );

			if ( ! $rname || ! $rphone || ! $raddr ) {
				throw new RuntimeException( 'Thiếu thông tin người nhận: tên, SĐT, địa chỉ.' );
			}

			// Normalize items — support both new multi-product array and legacy single-product fields
			$raw_items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();
			if ( empty( $raw_items ) ) {
				// Legacy single-product compat
				$single_id = (int) ( $body['wc_product_id'] ?? 0 );
				if ( $single_id > 0 ) {
					$raw_items = array( array(
						'wc_product_id' => $single_id,
						'qty'           => 1,
						'unit_price'    => null, // use WC list price
						'name'          => sanitize_text_field( (string) ( $body['gift_product_name'] ?? '' ) ),
					) );
				}
			}
			if ( empty( $raw_items ) ) {
				throw new RuntimeException( 'Thiếu danh sách sản phẩm (items).' );
			}

			// Create WC order
			$order = wc_create_order( array( 'status' => 'processing', 'customer_id' => 0 ) );
			if ( is_wp_error( $order ) ) {
				throw new RuntimeException( 'Tạo WC order thất bại: ' . $order->get_error_message() );
			}

			// Add each item
			$gift_label_parts = array();
			$slot_keys        = array();
			foreach ( $raw_items as $item ) {
				$pid   = (int) ( $item['wc_product_id'] ?? 0 );
				$qty   = max( 1, (int) ( $item['qty'] ?? 1 ) );
				$price = isset( $item['unit_price'] ) && $item['unit_price'] !== null && $item['unit_price'] !== ''
					? (float) $item['unit_price']
					: null; // null = use WC list price

				if ( ! $pid ) { continue; }
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					throw new RuntimeException( 'WC product #' . $pid . ' không tìm thấy.' );
				}

				$item_price    = ( $price !== null ) ? $price : (float) $product->get_price();
				$item_subtotal = $item_price * $qty;

				$order->add_product( $product, $qty, array(
					'subtotal' => $item_subtotal,
					'total'    => $item_subtotal,
				) );

				$label = (string) ( $item['name'] ?? $product->get_name() );
				$gift_label_parts[] = $label . ( $qty > 1 ? " x{$qty}" : '' );
				$slot_keys[] = 'product_' . $pid;
			}

			$gift_label = implode( ', ', $gift_label_parts );
			$slot_key   = implode( '+', $slot_keys ) ?: ( 'order_' . time() );

			// Optional: apply coupon code
			$coupon_code = sanitize_text_field( (string) ( $body['coupon_code'] ?? '' ) );
			if ( $coupon_code !== '' ) {
				$order->apply_coupon( $coupon_code );
			}

			// Optional: flat order-level discount
			$order_discount = (float) ( $body['order_discount'] ?? 0 );

			// Shipping address
			$order->set_shipping_first_name( $rname );
			$order->set_shipping_phone( $rphone );
			$order->set_shipping_address_1( $raddr );
			$order->set_shipping_country( 'VN' );

			// Billing (mirror shipping for guest order)
			$order->set_billing_first_name( $rname );
			$order->set_billing_phone( $rphone );
			$order->set_billing_address_1( $raddr );
			$order->set_billing_country( 'VN' );

			if ( $rnote ) {
				$order->set_customer_note( $rnote );
			}

			// Order note — link CRM submission
			$order->add_order_note( sprintf(
				'[BizCity CRM] Quà tặng mẫu — %s | CF7 Submission #%d',
				esc_html( $gift_label ),
				$cf7_id
			) );

			// Meta — link back to CRM submission
			$order->update_meta_data( '_bizcity_crm_cf7_submission_id', $cf7_id );
			$order->update_meta_data( '_bizcity_gift_slot', $slot_key );
			$order->update_meta_data( '_bizcity_gift_label', $gift_label );

			// Apply flat discount if set (after items, before calculate_totals)
			if ( $order_discount > 0 ) {
				$order->set_discount_total( $order_discount );
			}

			$order->calculate_totals();
			$order->save();
			$wc_order_id = (int) $order->get_id();

			// Write wc_order_id to source_meta_json.gifts of unified submission (if synced)
			// [2026-07-01 Johnny Chu] PHASE-0.47 W3 — patch source_meta_json, no new DB column
			global $wpdb;
			$sub_tbl = $wpdb->prefix . 'bizcity_crm_submissions';
			if ( bizcity_tbl_exists( $sub_tbl ) ) {
				$sub_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, source_meta_json FROM `{$sub_tbl}`
					 WHERE source_type = 'cf7' AND source_ref_id = %d AND deleted_at IS NULL
					 LIMIT 1",
					$cf7_id
				), ARRAY_A );

				if ( $sub_row ) {
					$meta = $sub_row['source_meta_json']
						? ( (array) json_decode( $sub_row['source_meta_json'], true ) )
						: array();
					if ( ! isset( $meta['gifts'] ) || ! is_array( $meta['gifts'] ) ) {
						$meta['gifts'] = array();
					}
					$meta['gifts'][ $slot_key ] = array(
						'wc_order_id' => $wc_order_id,
						'created_at'  => current_time( 'mysql' ),
						'created_by'  => get_current_user_id(),
					);
					$wpdb->update(
						$sub_tbl,
						array(
							'source_meta_json' => wp_json_encode( $meta ),
							'updated_at'       => current_time( 'mysql' ),
						),
						array( 'id' => (int) $sub_row['id'] )
					);
					if ( class_exists( 'BizCity_Cache' ) ) {
						BizCity_Cache::flush_group( 'bzcsub' );
					}
				}
				// If no unified submission found — still proceed (WC order was created)
			}

			return array(
				'wc_order_id'   => $wc_order_id,
				'wc_order_url'  => admin_url( 'admin.php?page=wc-orders&id=' . $wc_order_id ),
				'wc_order_edit' => admin_url( 'post.php?post=' . $wc_order_id . '&action=edit' ),
				'wc_status'     => 'processing',
				'gift_slot'     => $slot_key,
				'gift_label'    => $gift_label,
			);
		} );
	}

	/**
	 * [2026-07-03 Johnny Chu] PHASE-0.46 FIX
	 * GET /crm-settings/assignable-users — list WP users that can be assigned to submissions.
	 * Returns users with manage_options or a lighter CRM role.
	 * No JOIN on global wp_users table is needed — uses get_users() which handles multisite.
	 */
	public static function get_crm_assignable_users( WP_REST_Request $req ) {
		return self::wrap( static function () {
			$wp_users = get_users( array(
				'role__in' => array( 'administrator', 'editor', 'author' ),
				'number'   => 200,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => array( 'ID', 'display_name', 'user_email' ),
			) );
			$out = array();
			foreach ( $wp_users as $u ) {
				$out[] = array(
					'id'           => (int) $u->ID,
					'display_name' => (string) $u->display_name,
					'email'        => (string) $u->user_email,
				);
			}
			return $out;
		} );
	}

	/**
	 * GET /wc-products — search WooCommerce products.
	 *
	 * Query params: q (search), limit, status
	 */
	public static function get_wc_products( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! function_exists( 'wc_get_products' ) ) {
				throw new RuntimeException( 'WooCommerce chưa kích hoạt.' );
			}

			// [2026-07-03 Johnny Chu] PHASE-0.47 FIX — support per_page/page params from WcProductPickerDialog
			$q        = sanitize_text_field( (string) ( $req->get_param( 'q' ) ?: '' ) );
			$per_page = max( 1, min( 100, (int) ( $req->get_param( 'per_page' ) ?: $req->get_param( 'limit' ) ?: 20 ) ) );
			$page     = max( 1, (int) ( $req->get_param( 'page' ) ?: 1 ) );
			$offset   = ( $page - 1 ) * $per_page;
			$status   = sanitize_key( (string) ( $req->get_param( 'status' ) ?: 'publish' ) );

			// Count total (for pagination)
			$count_args = array(
				'status' => $status,
				'limit'  => -1,
				'return' => 'ids',
			);
			if ( $q !== '' ) { $count_args['s'] = $q; }
			$total_ids = wc_get_products( $count_args );
			$total     = count( is_array( $total_ids ) ? $total_ids : array() );

			$args = array(
				'status'  => $status,
				'limit'   => $per_page,
				'offset'  => $offset,
				'return'  => 'objects',
				'orderby' => 'title',
				'order'   => 'ASC',
			);
			if ( $q !== '' ) { $args['s'] = $q; }

			$products = wc_get_products( $args );
			$out      = array();
			foreach ( is_array( $products ) ? $products : array() as $p ) {
				$img_id  = $p->get_image_id();
				$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
				$out[]   = array(
					'id'           => $p->get_id(),
					'name'         => $p->get_name(),
					'sku'          => $p->get_sku(),
					'price'        => $p->get_price(),
					'stock_status' => $p->get_stock_status(),
					'image_url'    => $img_url ?: '',
				);
			}

			return array(
				'products' => $out,
				'total'    => $total,
				'pages'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
			);
		} );
	}

	/**
	 * GET /gift-config — get gift slot configuration.
	 */
	public static function get_gift_config( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$slots = get_option( 'bizcity_crm_gift_slots_config', array() );
			return array( 'slots' => is_array( $slots ) ? $slots : array() );
		} );
	}

	/**
	 * POST /gift-config — update gift slot configuration.
	 * Body: { slots: { mamil_1: { label, wc_product_id, product_expiry, enabled }, ... } }
	 * Requires can_manage_rules permission.
	 */
	public static function post_gift_config( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body  = (array) $req->get_json_params();
			$slots = is_array( $body['slots'] ?? null ) ? $body['slots'] : array();

			// Sanitize each slot
			$clean = array();
			foreach ( $slots as $key => $cfg ) {
				$key = sanitize_key( (string) $key );
				if ( ! $key ) {
					continue;
				}
				$clean[ $key ] = array(
					'label'          => sanitize_text_field( (string) ( $cfg['label'] ?? '' ) ),
					'wc_product_id'  => (int) ( $cfg['wc_product_id'] ?? 0 ),
					'product_expiry' => sanitize_text_field( (string) ( $cfg['product_expiry'] ?? '' ) ),
					'enabled'        => ! empty( $cfg['enabled'] ),
				);
			}

			update_option( 'bizcity_crm_gift_slots_config', $clean, false );
			return array( 'ok' => true, 'slots' => $clean );
		} );
	}
}