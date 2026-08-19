<?php
/**
 * Centralized HIL augmentation and upgrade policy for Automation templates/workflows.
 *
 * - Augment seeded template blueprints with HIL prompt/spec defaults.
 * - Upgrade existing workflows safely (interactive triggers only, idempotent).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_HIL_Upgrader' ) ) {
	return;
}

final class BizCity_Automation_HIL_Upgrader {

	const UPGRADE_VERSION_OPTION = 'bizcity_automation_hil_upgrade_seed_version';
	const UPGRADE_SUMMARY_OPTION = 'bizcity_automation_hil_upgrade_summary';

	/** @var string[] */
	const MVP_SCOPE_SLUGS = array(
		// Content/Image/Commerce MVP scope.
		'tpl_daily_fb_post_8h_v1',
		'tpl_daily_fb_post_9h_v1',
		'tpl_daily_fb_post_10h_v1',
		'tpl_daily_notebook_fb_post_v1',
		'tpl_daily_notebook_wp_post_v1',
		'tpl_fb_post_with_auto_image_v1',
		'tpl_wp_post_with_auto_image_v1',
		'tpl_global_fb_post_image_first_v1',
		'tpl_generate_image_v1',
		'tpl_zalobot_seedream_photo_capture_v1',
		'tpl_zalobot_seedream_photo_edit_v1',
		'tpl_daily_fb_content_image_v1',
		'tpl_daily_wp_content_image_v1',
		'tpl_woo_order_created_v1',
		'tpl_woo_order_shipped_v1',
		'tpl_woo_abandoned_cart_v1',
		'tpl_woo_refund_notify_v1',
		// [2026-08-16 Johnny Chu] PHASE-2-HIL-TEMPLATE-AUTO-UPGRADE-MVP — include interactive order-create templates (Zalo Bot/Messenger).
		'tpl_zalobot_order_create_v1',
		'tpl_messenger_order_create_v1',

		// [2026-08-16 Johnny Chu] PHASE-2-HIL-TEMPLATE-AUTO-UPGRADE-MVP — extend scope to CRM templates under same safe policy.
		'tpl_fb_lead_capture_v1',
		'tpl_webhook_crm_v1',
		'tpl_schedule_event_v1',
		'tpl_remember_this_v1',
		'tpl_zalo_classify_route_v1',
		'tpl_zalo_tag_assign_v1',
		'tpl_deplao_lead_collect_v1',
		'tpl_deplao_fb_comment_lead_v1',
		'tpl_crm_new_contact_welcome_v1',
		'tpl_crm_label_notify_v1',
		'tpl_crm_sla_escalate_v1',
		'tpl_crm_csat_v1',
		'tpl_crm_stale_lead_v1',
		'tpl_zalo_image_classify_v1',
		'tpl_zalo_voice_v1',
		'tpl_loyalty_earned_v1',
		'tpl_loyalty_tier_up_v1',
		'tpl_campaign_started_v1',
		'tpl_appointment_reminder_v1',
		'tpl_invoice_overdue_v1',
		'tpl_http_form_to_crm_v1',
	);

	/** @var string[] */
	const INTERACTIVE_TRIGGER_TYPES = array(
		'zalo_inbound',
		'zalo_oa_inbound',
		'fb_message',
		'telegram_inbound',
	);

	/**
	 * [2026-08-16 Johnny Chu] PHASE-2-HIL-TEMPLATE-AUTO-UPGRADE-MVP — inject HIL defaults into targeted template blueprints during seed loading.
	 *
	 * @param array<string,mixed> $blueprint
	 * @return array<string,mixed>
	 */
	public static function augment_template_blueprint( array $blueprint ): array {
		$slug = sanitize_title_with_dashes( (string) ( $blueprint['slug'] ?? '' ) );
		if ( $slug === '' || ! in_array( $slug, self::MVP_SCOPE_SLUGS, true ) ) {
			return $blueprint;
		}

		$bundle = self::bundle_for_slug( $slug );
		$config = self::extract_trigger_config( $blueprint );
		if ( empty( $config['hil_prompt'] ) ) {
			$config['hil_prompt'] = $bundle['prompt'];
		}
		$config['hil_rollout'] = 'mvp';

		$trigger_type = sanitize_key( (string) ( $blueprint['trigger_type'] ?? '' ) );
		$is_interactive = in_array( $trigger_type, self::INTERACTIVE_TRIGGER_TYPES, true );

		if ( $is_interactive && empty( $config['hil_spec'] ) ) {
			$trigger_id = self::detect_trigger_id( $blueprint, $trigger_type );
			$spec = self::build_default_spec( $trigger_id, (string) $bundle['intent_id'], (string) $bundle['purpose'], $slug );
			$validation = class_exists( 'BizCity_TwinBrain_HIL_Spec' ) ? BizCity_TwinBrain_HIL_Spec::validate( $spec ) : array( 'valid' => false );
			if ( ! empty( $validation['valid'] ) ) {
				$config['hil_spec'] = (array) ( $validation['spec'] ?? $spec );
				$config['hil_spec_version'] = (string) ( $config['hil_spec']['spec_version'] ?? 'twin_hil.v1' );
				$config['hil_compiled_prompt'] = (string) $config['hil_prompt'];
			}
		}

		self::write_trigger_config( $blueprint, $config );
		return $blueprint;
	}

	public static function runtime_spec_for_workflow( array $workflow, array $spec ): array {
		// [2026-08-16 Johnny Chu] PHASE-2-HIL-ORDER-SCHEMA — repair legacy generic HIL specs in memory before an order side effect runs.
		$slug = sanitize_title_with_dashes( (string) ( $workflow['slug'] ?? '' ) );
		$is_order = self::is_order_workflow( $workflow, $slug );
		if ( ! $is_order || ! self::is_generic_default_spec( $spec ) ) {
			return $spec;
		}
		$trigger_type = sanitize_key( (string) ( $workflow['trigger_type'] ?? '' ) );
		$trigger_id = self::detect_trigger_id_from_graph( (array) ( $workflow['graph'] ?? array() ), $trigger_type );
		$bundle = self::bundle_for_slug( 'tpl_zalobot_order_create_v1' );
		$rebuilt = self::build_default_spec( $trigger_id, (string) $bundle['intent_id'], (string) $bundle['purpose'], 'tpl_zalobot_order_create_v1' );
		$validation = class_exists( 'BizCity_TwinBrain_HIL_Spec' ) ? BizCity_TwinBrain_HIL_Spec::validate( $rebuilt ) : array( 'valid' => false );
		return ! empty( $validation['valid'] ) ? (array) ( $validation['spec'] ?? $rebuilt ) : $spec;
	}

	private static function is_order_workflow( array $workflow, string $slug ): bool {
		if ( strpos( $slug, 'order' ) !== false || strpos( $slug, 'woo' ) !== false ) {
			return true;
		}
		$graph = is_array( $workflow['graph'] ?? null ) ? $workflow['graph'] : array();
		foreach ( (array) ( $graph['nodes'] ?? array() ) as $node ) {
			if ( is_array( $node ) && (string) ( $node['data']['blockId'] ?? '' ) === 'action.create_woo_order' ) {
				return true;
			}
		}
		return false;
	}

	private static function is_generic_default_spec( array $spec ): bool {
		$ids = array();
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			if ( is_array( $slot ) && ! empty( $slot['id'] ) ) {
				$ids[] = (string) $slot['id'];
			}
		}
		sort( $ids );
		return $ids === array( 'final_confirm', 'task_brief' )
			|| (string) ( $spec['intent_id'] ?? '' ) === 'commerce.order_execute' && $ids === array( 'final_confirm', 'task_brief' );
	}

	/**
	 * [2026-08-16 Johnny Chu] PHASE-2-HIL-TEMPLATE-AUTO-UPGRADE-MVP — run idempotent workflow upgrades once per seed version by default.
	 *
	 * @param string $seed_version
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public static function maybe_upgrade_workflows( string $seed_version, array $args = array() ): array {
		$seed_version = trim( $seed_version );
		$force = ! empty( $args['force'] );
		if ( $seed_version === '' ) {
			$seed_version = 'seed_unknown';
		}

		$stamped = (string) get_option( self::UPGRADE_VERSION_OPTION, '' );
		if ( ! $force && $stamped === $seed_version ) {
			return array(
				'ok' => true,
				'skipped' => true,
				'reason' => 'already_upgraded_for_seed_version',
				'seed_version' => $seed_version,
				'summary' => self::read_last_summary(),
			);
		}

		$summary = self::upgrade_workflows_internal( $seed_version, $args );
		$can_stamp = ! empty( $summary['ok'] )
			&& (int) ( $summary['failed_update'] ?? 0 ) === 0
			&& (int) ( $summary['failed_spec_invalid'] ?? 0 ) === 0;
		if ( $can_stamp ) {
			// [2026-08-16 Johnny Chu] PHASE-2-HIL-ORDER-SCHEMA — stamp only a complete pass; failures remain retryable.
			update_option( self::UPGRADE_VERSION_OPTION, $seed_version, false );
		}
		update_option( self::UPGRADE_SUMMARY_OPTION, wp_json_encode( $summary ), false );
		return $summary;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function upgrade_workflows_internal( string $seed_version, array $args = array() ): array {
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return array(
				'ok' => false,
				'seed_version' => $seed_version,
				'error' => 'workflow_repo_missing',
			);
		}

		$max = max( 50, min( 2000, (int) ( $args['max'] ?? 800 ) ) );
		$limit = 200;
		$offset = 0;
		$processed = 0;
		$upgraded = 0;
		$already_hil = 0;
		$skipped_scope_miss = 0;
		$skipped_non_interactive = 0;
		$failed_spec_invalid = 0;
		$failed_update = 0;
		$upgraded_ids = array();

		while ( $processed < $max ) {
			$query = BizCity_Automation_Repo_Workflows::query( array(
				'limit' => $limit,
				'offset' => $offset,
			) );
			$rows = is_array( $query['rows'] ?? null ) ? $query['rows'] : array();
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( $processed >= $max ) {
					break;
				}
				$processed++;

				$workflow_slug = sanitize_title_with_dashes( (string) ( $row['slug'] ?? '' ) );
				$template_slug = self::guess_template_slug_from_workflow_slug( $workflow_slug );
				if ( $template_slug === '' || ! in_array( $template_slug, self::MVP_SCOPE_SLUGS, true ) ) {
					$skipped_scope_miss++;
					continue;
				}

				$trigger_type = sanitize_key( (string) ( $row['trigger_type'] ?? '' ) );
				if ( ! in_array( $trigger_type, self::INTERACTIVE_TRIGGER_TYPES, true ) ) {
					$skipped_non_interactive++;
					continue;
				}

				$config = is_array( $row['trigger_config'] ?? null ) ? (array) $row['trigger_config'] : array();
				if ( ! empty( $config['hil_spec'] ) && ! ( self::is_order_workflow( $row, $template_slug ) && self::is_generic_default_spec( (array) $config['hil_spec'] ) ) ) {
					$already_hil++;
					continue;
				}

				// [2026-08-16 Johnny Chu] PHASE-2-HIL-ORDER-SCHEMA — detect order graph before selecting the repair bundle.
				$is_order_workflow = self::is_order_workflow( $row, $template_slug );
				$bundle = self::bundle_for_slug( $is_order_workflow ? 'tpl_zalobot_order_create_v1' : $template_slug );
				if ( empty( $config['hil_prompt'] ) ) {
					$config['hil_prompt'] = $bundle['prompt'];
				}
				$trigger_id = self::detect_trigger_id_from_graph( (array) ( $row['graph'] ?? array() ), $trigger_type );
				$spec = self::build_default_spec( $trigger_id, (string) $bundle['intent_id'], (string) $bundle['purpose'], $is_order_workflow ? 'tpl_zalobot_order_create_v1' : $template_slug );
				$validation = class_exists( 'BizCity_TwinBrain_HIL_Spec' ) ? BizCity_TwinBrain_HIL_Spec::validate( $spec ) : array( 'valid' => false );
				if ( empty( $validation['valid'] ) ) {
					$failed_spec_invalid++;
					continue;
				}

				$config['hil_spec'] = (array) ( $validation['spec'] ?? $spec );
				$config['hil_spec_version'] = (string) ( $config['hil_spec']['spec_version'] ?? 'twin_hil.v1' );
				$config['hil_rollout'] = 'mvp';
				$config['hil_compiled_prompt'] = (string) $config['hil_prompt'];
				$config['hil_upgrade'] = array(
					'policy' => 'safe_only',
					'seed_version' => $seed_version,
					'template_slug' => $template_slug,
					'upgraded_at' => gmdate( 'c' ),
				);
				$graph = self::sync_hil_to_graph( (array) ( $row['graph'] ?? array() ), $config );

				$updated = BizCity_Automation_Repo_Workflows::update( (int) $row['id'], array(
					'trigger_config' => $config,
					'graph' => $graph,
				) );
				if ( is_wp_error( $updated ) ) {
					$failed_update++;
					continue;
				}

				$upgraded++;
				$upgraded_ids[] = (int) $row['id'];
			}

			$offset += $limit;
		}

		return array(
			'ok' => true,
			'seed_version' => $seed_version,
			'processed' => $processed,
			'upgraded' => $upgraded,
			'already_hil' => $already_hil,
			'skipped_scope_miss' => $skipped_scope_miss,
			'skipped_non_interactive_trigger' => $skipped_non_interactive,
			'failed_spec_invalid' => $failed_spec_invalid,
			'failed_update' => $failed_update,
			'upgraded_ids' => $upgraded_ids,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function read_last_summary(): array {
		$raw = (string) get_option( self::UPGRADE_SUMMARY_OPTION, '' );
		if ( $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @return array<string,mixed>
	 */
	private static function extract_trigger_config( array $blueprint ): array {
		if ( isset( $blueprint['trigger_config'] ) && is_array( $blueprint['trigger_config'] ) ) {
			return (array) $blueprint['trigger_config'];
		}
		if ( isset( $blueprint['trigger_config_json'] ) && is_string( $blueprint['trigger_config_json'] ) ) {
			$decoded = json_decode( $blueprint['trigger_config_json'], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @param array<string,mixed> $config
	 */
	private static function write_trigger_config( array &$blueprint, array $config ): void {
		if ( array_key_exists( 'trigger_config_json', $blueprint ) ) {
			$blueprint['trigger_config_json'] = wp_json_encode( $config );
			return;
		}
		$blueprint['trigger_config'] = $config;
	}

	private static function sync_hil_to_graph( array $graph, array $config ): array {
		// [2026-08-16 Johnny Chu] PHASE-2-HIL-ORDER-SCHEMA — keep persisted trigger node and trigger_config as one HIL source of truth.
		foreach ( (array) ( $graph['nodes'] ?? array() ) as $index => $node ) {
			if ( ! is_array( $node ) || (string) ( $node['type'] ?? '' ) !== 'trigger' ) { continue; }
			$node['data'] = is_array( $node['data'] ?? null ) ? $node['data'] : array();
			foreach ( array( 'hil_prompt', 'hil_spec', 'hil_spec_version', 'hil_rollout', 'hil_compiled_prompt' ) as $key ) {
				if ( array_key_exists( $key, $config ) ) { $node['data'][ $key ] = $config[ $key ]; }
			}
			$graph['nodes'][ $index ] = $node;
		}
		return $graph;
	}

	private static function guess_template_slug_from_workflow_slug( string $workflow_slug ): string {
		if ( preg_match( '/^wf_from_(tpl_[a-z0-9_]+)_/i', $workflow_slug, $m ) ) {
			return sanitize_title_with_dashes( (string) $m[1] );
		}
		if ( preg_match( '/^(tpl_[a-z0-9_]+_v\d+)$/i', $workflow_slug, $m2 ) ) {
			return sanitize_title_with_dashes( (string) $m2[1] );
		}
		return '';
	}

	/**
	 * @return array{prompt:string,purpose:string,intent_id:string}
	 */
	private static function bundle_for_slug( string $slug ): array {
		if ( strpos( $slug, 'crm' ) !== false || strpos( $slug, 'lead' ) !== false || strpos( $slug, 'cskh' ) !== false || strpos( $slug, 'loyalty' ) !== false || strpos( $slug, 'appointment' ) !== false || strpos( $slug, 'invoice' ) !== false || strpos( $slug, 'contact' ) !== false ) {
			return array(
				'prompt' => 'Thu thap thong tin khach hang/su kien CRM va yeu cau xac nhan ro rang truoc khi tao cap nhat event hoac gui thong bao ra kenh.',
				'purpose' => 'CRM operations require explicit confirmation before creating events or sending external notifications.',
				'intent_id' => 'crm.event_execute',
			);
		}
		if ( strpos( $slug, 'woo_' ) !== false || strpos( $slug, 'order_' ) !== false ) {
			return array(
				// [2026-08-16 Johnny Chu] PHASE-2-HIL-DIALOG-BUTTON — order flows must request complete checkout schema before side effect.
				'prompt' => 'Thu thap day du schema dat don truoc khi tao/cap nhat don: ten san pham, gia/thanh toan, so dien thoai nguoi nhan, dia chi giao hang, phuong thuc thanh toan; bat buoc xac nhan cuoi truoc side effect.',
				'purpose' => 'Order operation requires operator confirmation before side effect execution.',
				'intent_id' => 'commerce.order_execute',
			);
		}
		if ( strpos( $slug, 'image' ) !== false || strpos( $slug, 'photo' ) !== false || strpos( $slug, 'seedream' ) !== false ) {
			if ( strpos( $slug, 'edit' ) !== false ) {
				return array(
					// [2026-08-16 Johnny Chu] PHASE-2-HIL-DIALOG-BUTTON — edit-image flows require source image + edit brief before execution.
					'prompt' => 'Thu thong tin sua anh truoc side effect: anh goc dau vao (source image) + mo ta dieu chinh mong muon; neu thieu anh goc thi tiep tuc hoi; bat buoc xac nhan cuoi truoc khi chay.',
					'purpose' => 'Image edit requires source image and explicit final approval before side effects.',
					'intent_id' => 'media.image_execute',
				);
			}
			return array(
				// [2026-08-16 Johnny Chu] PHASE-2-HIL-DIALOG-BUTTON — generate-image flows require topic (and optional style) before generation.
				'prompt' => 'Thu thap topic/chu de anh (bat buoc), muc dich su dung va rang buoc output; neu thieu topic thi tiep tuc hoi; xac nhan brief cuoi truoc khi gui/publish ket qua.',
				'purpose' => 'Image generation/edit should confirm target output and final approval before side effects.',
				'intent_id' => 'media.image_execute',
			);
		}
		return array(
			// [2026-08-16 Johnny Chu] PHASE-2-HIL-DIALOG-BUTTON — publish flows should ask for topic + media before post.
			'prompt' => 'Thu thap chu de bai dang, anh dinh kem (hoac xac nhan text-only) va buoc xac nhan cuoi truoc khi dang bai hoac gui noi dung ra kenh cong khai.',
			'purpose' => 'Content publish requires user confirmation before side effect actions run.',
			'intent_id' => 'content.publish_execute',
		);
	}

	/**
	 * @param array<string,mixed> $blueprint
	 */
	private static function detect_trigger_id( array $blueprint, string $trigger_type ): string {
		$graph = isset( $blueprint['graph'] ) && is_array( $blueprint['graph'] ) ? $blueprint['graph'] : array();
		$detected = self::detect_trigger_id_from_graph( $graph, $trigger_type );
		if ( $detected !== '' ) {
			return $detected;
		}
		$fallback_type = $trigger_type !== '' ? $trigger_type : 'manual';
		return 'trigger.' . $fallback_type;
	}

	/**
	 * @param array<string,mixed> $graph
	 */
	private static function detect_trigger_id_from_graph( array $graph, string $trigger_type ): string {
		$nodes = isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? $graph['nodes'] : array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( (string) ( $node['type'] ?? '' ) !== 'trigger' ) {
				continue;
			}
			$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
			$block = trim( (string) ( $data['blockId'] ?? '' ) );
			if ( $block !== '' ) {
				return $block;
			}
		}
		if ( $trigger_type !== '' ) {
			return 'trigger.' . $trigger_type;
		}
		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_default_spec( string $trigger_id, string $intent_id, string $purpose, string $template_slug = '' ): array {
		$trigger_id = $trigger_id !== '' ? $trigger_id : 'trigger.manual';
		$intent_id = $intent_id !== '' ? $intent_id : 'automation.task_execute';
		$spec_id = 'spec_' . substr( md5( $trigger_id . '|' . $intent_id ), 0, 12 );
		if ( strpos( $template_slug, 'order' ) !== false || strpos( $template_slug, 'woo' ) !== false ) {
			return array(
				'spec_version' => 'twin_hil.v1',
				'spec_id' => 'spec_order_' . substr( md5( $trigger_id . '|' . $intent_id . '|order_schema_v2' ), 0, 12 ),
				'trigger_id' => $trigger_id,
				'intent_id' => 'commerce.order_execute',
				'goal_scope' => 'goal_case',
				'purpose' => $purpose,
				'slots' => array(
					array( 'id' => 'product_name', 'label' => 'Tên sản phẩm', 'type' => 'entity', 'required' => true, 'ask' => 'Bạn muốn đặt sản phẩm nào?', 'sources' => array( 'message_text', 'confirmed_turns' ), 'choices' => array(), 'validation' => array( 'min_length' => 2, 'max_length' => 160 ), 'confirmation' => 'required_if_inferred', 'redact_in_trace' => false ),
					array( 'id' => 'quantity', 'label' => 'Số lượng', 'type' => 'integer', 'required' => true, 'ask' => 'Bạn muốn đặt số lượng bao nhiêu?', 'sources' => array( 'message_text', 'confirmed_turns' ), 'choices' => array(), 'validation' => array( 'min' => 1, 'max' => 999 ), 'confirmation' => 'required_if_inferred', 'redact_in_trace' => false ),
					array( 'id' => 'price', 'label' => 'Giá sản phẩm', 'type' => 'number', 'required' => true, 'ask' => 'Giá sản phẩm hoặc mức thanh toán đã xác nhận là bao nhiêu?', 'sources' => array( 'message_text', 'confirmed_turns' ), 'choices' => array(), 'validation' => array( 'min' => 0 ), 'confirmation' => 'required_if_inferred', 'redact_in_trace' => false ),
					array( 'id' => 'recipient_name', 'label' => 'Tên người nhận', 'type' => 'text', 'required' => true, 'ask' => 'Tên người nhận là gì?', 'sources' => array( 'message_text', 'confirmed_turns' ), 'choices' => array(), 'validation' => array( 'min_length' => 2, 'max_length' => 100 ), 'confirmation' => 'never', 'redact_in_trace' => false ),
					array( 'id' => 'receiver_phone', 'label' => 'Số điện thoại người nhận', 'type' => 'phone', 'required' => true, 'ask' => 'Bạn cho mình số điện thoại người nhận.', 'sources' => array( 'message_text', 'confirmed_turns' ), 'choices' => array(), 'validation' => array( 'country' => 'VN' ), 'confirmation' => 'never', 'redact_in_trace' => true ),
					array( 'id' => 'shipping_address', 'label' => 'Địa chỉ giao hàng', 'type' => 'address', 'required' => true, 'ask' => 'Đơn hàng giao về địa chỉ nào?', 'sources' => array( 'message_text', 'confirmed_turns' ), 'choices' => array(), 'validation' => array( 'min_length' => 8 ), 'confirmation' => 'never', 'redact_in_trace' => true ),
					array( 'id' => 'payment_method', 'label' => 'Phương thức thanh toán', 'type' => 'choice', 'required' => true, 'ask' => 'Bạn muốn thanh toán COD hay chuyển khoản?', 'sources' => array( 'message_text', 'confirmed_turns' ), 'choices' => array( 'cod' => 'COD', 'bank_transfer' => 'Chuyển khoản' ), 'validation' => array(), 'confirmation' => 'never', 'redact_in_trace' => false ),
				),
				'completion' => array( 'condition' => 'all_required_valid_and_confirmed', 'final_confirmation' => true, 'side_effect_gate' => 'block_until_ready' ),
				'limits' => array( 'max_turns' => 12, 'ttl_seconds' => 3600, 'on_timeout' => 'pause' ),
				'notice_policy' => array( 'slot_progress' => true, 'waiting_user' => true, 'ready' => true, 'failed' => true ),
			);
		}

		return array(
			'spec_version' => 'twin_hil.v1',
			'spec_id' => $spec_id,
			'trigger_id' => $trigger_id,
			'intent_id' => $intent_id,
			'goal_scope' => 'goal_case',
			'purpose' => $purpose,
			'slots' => array(
				array(
					'id' => 'task_brief',
					'label' => 'Task brief',
					'type' => 'text',
					'required' => true,
					'ask' => 'Vui long mo ta ngan gon muc tieu va ket qua ban muon he thong thuc hien.',
					'sources' => array( 'message_text' ),
					'choices' => array(),
					'validation' => array( 'min_length' => 4, 'max_length' => 300 ),
					'confirmation' => 'never',
					'redact_in_trace' => false,
				),
				array(
					'id' => 'final_confirm',
					'label' => 'Final confirm',
					'type' => 'choice',
					'required' => true,
					'ask' => 'Xac nhan thuc thi side effect? Tra loi: xac_nhan hoac huy.',
					'sources' => array( 'message_text' ),
					'choices' => array(
						'xac_nhan' => 'Xac nhan',
						'huy' => 'Huy',
					),
					'validation' => array(),
					'confirmation' => 'required',
					'redact_in_trace' => false,
				),
			),
			'completion' => array(
				'condition' => 'all_required_valid_and_confirmed',
				'final_confirmation' => true,
				'side_effect_gate' => 'block_until_ready',
			),
			'limits' => array(
				'max_turns' => 8,
				'ttl_seconds' => 3600,
				'on_timeout' => 'pause',
			),
			'notice_policy' => array(
				'slot_progress' => true,
				'waiting_user' => true,
				'ready' => true,
				'failed' => true,
			),
		);
	}
}
