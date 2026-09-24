<?php
/**
 * PHASE-0.60A/B/C/D — Bot Studio readiness probe (Disk / Loader / Runtime).
 *
 * Scope: file presence, hook registration at the right priority, office-hours
 * polarity, the tool registry's two-layer intersection, the provider decision
 * contract, the Vietnamese date parser, the enrichment context renderer, the
 * live schema (bindings.office_hours_json, contacts.birthday/birthday_md) and —
 * the highest-risk item in the whole feature (doc §10, "Nghiêm trọng") — that an
 * UNCLAIMED turn leaves the built-in Default_Reply safety net untouched, checked
 * live against the real WP hook system. The CLAIMED-turn / fallback / park paths
 * run under tests/unit/BotTurnRunnerTest.php with faked collaborators, so this
 * probe never creates Character/Binding rows, never calls a provider and never
 * sends Zalo (B12.2).
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since PHASE-0.60A (2026-09-23)
 */

// [2026-09-23 04:55 PM Claude Fable 5.1] PHASE-0.60A W8 — extended for W5/0.60B/0.60C/0.60D surfaces.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Bot_Studio', false ) ) {
	return;
}

final class BizCity_Probe_Bot_Studio implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.channel.bot_studio'; }
	public function label(): string { return 'Bot Studio — sẵn sàng động cơ lượt trả lời'; }
	public function description(): string { return 'Kiểm tra file, loader, ưu tiên hook (tắt lưới đỡ Default_Reply đúng lúc, đúng chỗ), registry công cụ, quyết định nguồn AI, parser ngày VN, khối ngữ cảnh liên hệ và schema thật — không gọi LLM hay gửi Zalo.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 47; }
	public function icon(): string { return 'bot'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return new WP_Error( 'channel_binding_missing', 'BizCity_Channel_Binding chưa nạp — Bot Studio phụ thuộc bảng bindings.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$emit  = function ( $label, $ok, $detail ) use ( $ctx, &$steps ) {
			$step    = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};

		$root    = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __FILE__, 5 ) . '/';
		$bot_dir = $root . 'core/channel-gateway/includes/bot/';
		$classes = array(
			'class-bot-config-repo.php'     => 'BizCity_Bot_Config_Repo',
			'class-bot-rest.php'            => 'BizCity_Bot_REST',
			'class-bot-office-hours.php'    => 'BizCity_Bot_Office_Hours',
			'class-bot-provider.php'        => 'BizCity_Bot_Provider',
			'class-bot-vn-date.php'         => 'BizCity_Bot_VN_Date',
			'class-bot-tool-registry.php'   => 'BizCity_Bot_Tool_Registry',
			'class-bot-vertical-tools.php'  => 'BizCity_Bot_Vertical_Tools',
			'class-bot-astro-tool.php'      => 'BizCity_Bot_Astro_Tool',
			'class-bot-tools.php'           => 'BizCity_Bot_Tools',
			'class-bot-context-builder.php' => 'BizCity_Bot_Context_Builder',
			'class-bot-turn-claim.php'      => 'BizCity_Bot_Turn_Claim',
			'class-bot-turn-runner.php'     => 'BizCity_Bot_Turn_Runner',
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-1/OW-4 — unified read projection + the
			// first genuine tool executor added after this probe's original 12.
			'class-bot-studio-rest.php'     => 'BizCity_Bot_Studio_REST',
			'class-bot-apify-client.php'    => 'BizCity_Bot_Apify_Client',
		);
		$disk_missing = array();
		foreach ( $classes as $file => $class ) {
			if ( ! is_readable( $bot_dir . $file ) ) {
				$disk_missing[] = $file;
			}
		}
		$turn_claim_src  = is_readable( $bot_dir . 'class-bot-turn-claim.php' ) ? (string) file_get_contents( $bot_dir . 'class-bot-turn-claim.php' ) : '';
		$turn_runner_src = is_readable( $bot_dir . 'class-bot-turn-runner.php' ) ? (string) file_get_contents( $bot_dir . 'class-bot-turn-runner.php' ) : '';
		if ( strpos( $turn_claim_src, 'bizcity_automation_default_reply_enabled' ) === false ) { $disk_missing[] = 'turn_claim:default_reply_filter'; }
		if ( ! preg_match( '/bizcity_channel_normalized[\'"]\s*,\s*array\(\s*__CLASS__.*?,\s*0\s*,/s', $turn_claim_src ) ) { $disk_missing[] = 'turn_claim:priority_zero'; }
		if ( strpos( $turn_runner_src, 'bizcity_crm_message_persisted' ) === false ) { $disk_missing[] = 'turn_runner:persisted_hook'; }
		if ( strpos( $turn_runner_src, 'BizCity_CRM_Outbound_Dispatcher::dispatch' ) === false ) { $disk_missing[] = 'turn_runner:dispatcher_send'; }
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 — generate_image's dispatch() call must stay
		// content_type=image, not silently regress to text-only; the two new tool executors must exist.
		if ( strpos( $turn_runner_src, "'content_type'    => 'image'" ) === false ) { $disk_missing[] = 'turn_runner:image_attachment_send'; }
		$bot_tools_src = is_readable( $bot_dir . 'class-bot-tools.php' ) ? (string) file_get_contents( $bot_dir . 'class-bot-tools.php' ) : '';
		if ( strpos( $bot_tools_src, "case 'generate_image'" ) === false ) { $disk_missing[] = 'bot_tools:generate_image_case'; }
		if ( strpos( $bot_tools_src, "case 'scrape_social_data'" ) === false ) { $disk_missing[] = 'bot_tools:scrape_social_data_case'; }
		$crm_enrich = $root . 'plugins/bizcity-twin-crm/includes/class-contact-enrichment.php';
		if ( ! is_readable( $crm_enrich ) ) { $disk_missing[] = 'crm:class-contact-enrichment.php'; }
		$disk_ok = empty( $disk_missing );
		$emit( 'Disk - ' . count( $classes ) . ' file bot + enrichment CRM + đúng hook/priority/dispatcher/tool-executor trong source', $disk_ok,
			$disk_ok ? 'Đủ file; turn-claim khai filter + priority 0; turn-runner khai hook persisted + gửi qua BizCity_CRM_Outbound_Dispatcher (kể cả nhánh ảnh); generate_image/scrape_social_data có executor thật.' : 'Thiếu: ' . implode( ', ', $disk_missing ) . '.' );

		$loader_missing = array();
		foreach ( $classes as $file => $class ) {
			if ( ! class_exists( $class, false ) ) {
				$loader_missing[] = $class;
			}
		}
		$loader_ok = empty( $loader_missing );
		$emit( 'Loader - ' . count( $classes ) . ' class Bot Studio đã nạp', $loader_ok, $loader_ok ? 'Tất cả class đã có trong runtime.' : 'Chưa nạp: ' . implode( ', ', $loader_missing ) . '.' );

		$claim_priority   = $loader_ok ? has_action( 'bizcity_channel_normalized', array( 'BizCity_Bot_Turn_Claim', 'on_normalized' ) ) : false;
		$runner_hooked    = $loader_ok ? has_action( 'bizcity_crm_message_persisted', array( 'BizCity_Bot_Turn_Runner', 'on_persisted' ) ) : false;
		$inserted_hooked  = $loader_ok ? has_action( 'bizcity_crm_message_inserted', array( 'BizCity_Bot_Turn_Runner', 'on_message_inserted' ) ) : false;
		$matcher_priority = class_exists( 'BizCity_Automation_Trigger_Matcher', false ) ? has_action( 'bizcity_channel_normalized', array( BizCity_Automation_Trigger_Matcher::instance(), 'on_channel_normalized' ) ) : 30;
		$hook_priority_ok = 0 === $claim_priority && false !== $runner_hooked && false !== $inserted_hooked && ( false === $matcher_priority || 0 < (int) $matcher_priority );
		$emit( 'Loader - Turn Claim @0 chạy trước Trigger_Matcher (@' . var_export( $matcher_priority, true ) . '); runner + inserted hook đã đăng ký', $hook_priority_ok,
			$hook_priority_ok ? 'bizcity_channel_normalized priority=0 < matcher; bizcity_crm_message_persisted + bizcity_crm_message_inserted đã đăng ký.' : 'claim=' . var_export( $claim_priority, true ) . ' runner=' . var_export( $runner_hooked, true ) . ' inserted=' . var_export( $inserted_hooked, true ) . '.' );

		// Runtime — office hours polarity (pure, no DB/network).
		$office_hours_ok = false;
		if ( class_exists( 'BizCity_Bot_Office_Hours', false ) ) {
			$mon_10am = strtotime( 'next monday 10:00 UTC' );
			$mon_8pm  = strtotime( 'next monday 20:00 UTC' );
			$oh = array( 'enabled' => true, 'timezone' => 'UTC', 'days' => array( 'mon' => array( array( 'start' => '08:00', 'end' => '17:30' ) ) ) );
			$office_hours_ok = BizCity_Bot_Office_Hours::is_staff_on_duty( $oh, $mon_10am ) === true
				&& BizCity_Bot_Office_Hours::is_staff_on_duty( $oh, $mon_8pm ) === false
				&& BizCity_Bot_Office_Hours::is_staff_on_duty( array( 'enabled' => false ), $mon_10am ) === false;
		}
		$emit( 'Runtime - Cực tính giờ trực đúng (trong giờ = im lặng, ngoài giờ = trả lời)', $office_hours_ok, $office_hours_ok ? 'is_staff_on_duty() khớp E10 trên cả 3 nhánh.' : 'is_staff_on_duty() sai cực tính hoặc lỗi trên một nhánh.' );

		// Runtime — negative wiring check against the REAL WP hook system.
		$negative_ok = false;
		if ( $loader_ok ) {
			$probe_tag = 'bizcity_automation_default_reply_enabled';
			$before    = apply_filters( $probe_tag, true, array() );
			BizCity_Bot_Turn_Claim::on_normalized( array( 'platform' => 'ZALO_PERSONAL', 'account_id' => '__healthtest_bot_studio_' . wp_generate_password( 8, false ), 'chat_id' => '__healthtest_chat', 'contact_id' => 0 ), 'probe' );
			$after       = apply_filters( $probe_tag, true, array() );
			$negative_ok = $before === true && $after === true && null === BizCity_Bot_Turn_Claim::consume_claim();
		}
		$emit( 'Runtime - Không có binding thật ⇒ không claim, lưới đỡ giữ nguyên', $negative_ok, $negative_ok ? 'Turn Claim từ chối tài khoản không tồn tại và không đụng vào filter an toàn.' : 'Turn Claim claim nhầm hoặc đã tắt filter dù không có binding thật — RỦI RO khách nhận 2 câu trả lời.' );

		// Runtime — tool registry two-layer intersection + never-offered rows (B7.1–B7.3, S2.5).
		$tools_ok = false;
		$tools_detail = '';
		if ( class_exists( 'BizCity_Bot_Tool_Registry', false ) ) {
			$fake_character = (object) array( 'id' => 0, 'system_prompt' => '', 'allowed_verticals' => wp_json_encode( array( 'woo_bizops', 'quick' ) ) );
			$rows = BizCity_Bot_Tool_Registry::rows( $fake_character );
			$by   = array_column( $rows, null, 'id' );
			$eff  = array_column( BizCity_Bot_Tool_Registry::effective( $fake_character, array( 'current_datetime' ), array() ), 'id' );
			$valid_statuses = array( 'available', 'unconfigured', 'needs_bridge' );
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 — the two tools that gained a real turn
			// executor this phase must stay catalogued with a real status/hint, not silently drop
			// out of rows() if a future edit breaks their `check` callback wiring.
			$new_tools_ok = isset( $by['generate_image'] ) && in_array( $by['generate_image']['status'], $valid_statuses, true )
				&& isset( $by['scrape_social_data'] ) && in_array( $by['scrape_social_data']['status'], $valid_statuses, true )
				&& '' !== $by['scrape_social_data']['hint'];
			$tools_ok = isset( $by['react_message'] ) && 'needs_bridge' === $by['react_message']['status']
				&& ( ! isset( $by['vertical_woo_bizops'] ) || 'available' !== $by['vertical_woo_bizops']['status'] )
				&& ! in_array( 'react_message', $eff, true )
				&& ! in_array( 'current_datetime', $eff, true )
				&& ! in_array( 'vertical_woo_bizops', $eff, true )
				&& $new_tools_ok;
			$tools_detail = 'catalog=' . count( $rows ) . ' available_after_policy=' . count( $eff ) . ' generate_image=' . ( $by['generate_image']['status'] ?? 'missing' ) . ' scrape_social_data=' . ( $by['scrape_social_data']['status'] ?? 'missing' );
		}
		$emit( 'Runtime - Registry công cụ: needs_bridge/woo_bizops không bao giờ được đưa cho model; tắt ở character có hiệu lực; generate_image/scrape_social_data còn catalogued', $tools_ok, $tools_ok ? $tools_detail : 'Registry lệch: một công cụ chưa sẵn sàng hoặc bị tắt vẫn lọt vào danh sách hiệu lực, hoặc generate_image/scrape_social_data biến mất khỏi catalog.' );

		// Runtime — provider decision contract (0.60C §4) + end-anchored host match (D1.6).
		$provider_ok = false;
		if ( class_exists( 'BizCity_Bot_Provider', false ) ) {
			$b2 = BizCity_Bot_Provider::effective( array( 'provider_mode' => 'direct', 'has_own_key' => false ), 'gateway' );
			$provider_ok = 2 === $b2['branch'] && 'gateway' === $b2['mode'] && ! empty( $b2['override_ignored'] )
				&& 4 === BizCity_Bot_Provider::effective( array() )['branch']
				&& ! BizCity_Bot_Provider::host_matches( 'https://api.googleapis.com.evil.example/', 'googleapis.com' )
				&& BizCity_Bot_Provider::host_matches( 'https://x.googleapis.com/', 'googleapis.com' );
		}
		$emit( 'Runtime - Nguồn AI: direct thiếu khóa ⇒ bỏ qua override (khóa ① không đi sang ②); host match neo cuối chuỗi', $provider_ok, $provider_ok ? 'Bốn nhánh 0.60C §4 và D1.6 đúng; site hiện ở chế độ ' . BizCity_Bot_Provider::effective()['mode'] . '.' : 'Quyết định nguồn AI sai nhánh hoặc host match kiểu "có chứa".' );

		// Runtime — VN date + enrichment renderer (pure).
		$pure_ok = false;
		if ( class_exists( 'BizCity_Bot_VN_Date', false ) ) {
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60A — fixed a wrong fixture: per the parser's
			// own documented rule (class-bot-vn-date.php:13,76 — "both parts ≤ 12 → ambiguous"),
			// '03/12/1990' (day=3, month=12, both ≤12) is legitimately ambiguous, not 'ok'. '25/12'
			// (day=25 > 12) is the correct unambiguous fixture; the code was right, this check wasn't.
			$p1 = BizCity_Bot_VN_Date::parse( '25/12/1990' );
			$p2 = BizCity_Bot_VN_Date::parse( '05/06/1990' );
			$pure_ok = 'ok' === $p1['status'] && '1990-12-25' === $p1['date'] && 'ambiguous' === $p2['status'];
			if ( class_exists( 'BizCity_CRM_Contact_Enrichment' ) ) {
				$block = BizCity_CRM_Contact_Enrichment::render_context_block( array( 'name' => 'Probe', 'birthday_md' => '03-12', 'additional_attributes' => wp_json_encode( array( 'birthday_meta' => array( 'source' => 'customer_stated' ) ) ) ) );
				$pure_ok = $pure_ok && strpos( $block, 'chưa có năm sinh' ) !== false && strpos( $block, 'khách nói trong chat' ) !== false;
			}
		}
		$emit( 'Runtime - Parser ngày VN (03/12 = 3 tháng 12; mơ hồ thì hỏi) + khối ngữ cảnh có nhãn nguồn', $pure_ok, $pure_ok ? 'd/m/Y đúng, ca mơ hồ trả ambiguous, khối ngữ cảnh ghi nguồn và ô trống.' : 'Parser hoặc renderer lệch với 0.60B §6 / 0.60D §2.6.' );

		// Runtime — schema reality check (no SHOW COLUMNS in a runtime path: use the cached helper when present).
		global $wpdb;
		$table = BizCity_Channel_Binding::table();
		$cols  = $wpdb->get_col( "DESCRIBE {$table}", 0 );
		$schema_ok = is_array( $cols ) && in_array( 'office_hours_json', $cols, true );
		$contacts_ok = null;
		if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) && function_exists( 'bizcity_column_exists' ) ) {
			$ct = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$contacts_ok = bizcity_column_exists( $ct, 'birthday' ) && bizcity_column_exists( $ct, 'birthday_md' );
		}
		$emit( 'Runtime - Cột office_hours_json (bindings) + birthday/birthday_md (contacts) tồn tại thật', $schema_ok && false !== $contacts_ok,
			( $schema_ok ? 'bindings.office_hours_json OK. ' : 'bindings.office_hours_json THIẾU — chạy maybe_install(). ' ) . ( null === $contacts_ok ? 'contacts: CRM chưa nạp (bỏ qua).' : ( $contacts_ok ? 'contacts.birthday + birthday_md OK.' : 'contacts.birthday/birthday_md THIẾU — chạy migrate_phase_060b().' ) ) );

		// [2026-09-23 Claude Sonnet 5] PHASE-0.60G §8.3 — the unified read projection routes must really be
		// registered on this site (read-only: inspects the route table, never calls a route).
		$routes_ok     = null;
		$routes_detail = 'rest_get_server() không khả dụng ở ngữ cảnh này (bỏ qua).';
		if ( function_exists( 'rest_get_server' ) ) {
			$route_table = rest_get_server()->get_routes();
			$missing_rt  = array();
			foreach ( array( 'accounts', 'sessions', 'identity' ) as $rt ) {
				if ( ! isset( $route_table[ '/bizcity-channel/v1/bot-studio/' . $rt ] ) ) { $missing_rt[] = $rt; }
			}
			$routes_ok     = empty( $missing_rt );
			$routes_detail = $routes_ok ? 'bizcity-channel/v1/bot-studio/{accounts,sessions,identity} đã đăng ký.' : 'Chưa đăng ký: ' . implode( ', ', $missing_rt ) . '.';
		}
		$emit( 'Loader - Route đọc hợp nhất bot-studio/accounts + sessions + identity đã đăng ký', false !== $routes_ok, $routes_detail );

		// [2026-09-23 Claude Sonnet 5] PHASE-0.60G G2 — the bot-replied automation trigger must be reported
		// explicitly (never silent): source fires it, allowlist accepts it, block is catalogued, matcher listens.
		$g2_ok     = true;
		$g2_detail = '';
		if ( ! class_exists( 'BizCity_Automation_Trigger_Matcher', false ) ) {
			$g2_detail = 'SKIP — core/automation chưa nạp trên site này; không đánh giá được trigger.bot_turn_completed (không phải lỗi Bot Studio).';
		} else {
			$g2_missing = array();
			if ( strpos( $turn_runner_src, "do_action( 'bizcity_bot_turn_completed'" ) === false ) { $g2_missing[] = 'turn_runner:không còn bắn bizcity_bot_turn_completed'; }
			if ( ! class_exists( 'BizCity_Automation_Repo_Workflows', false ) || ! in_array( 'bot_turn_completed', BizCity_Automation_Repo_Workflows::TRIGGER_TYPES, true ) ) { $g2_missing[] = 'repo:TRIGGER_TYPES thiếu bot_turn_completed (lưu workflow sẽ bị từ chối)'; }
			if ( ! class_exists( 'BizCity_Automation_Block_Registry', false ) || ! BizCity_Automation_Block_Registry::instance()->has( 'trigger.bot_turn_completed' ) ) { $g2_missing[] = 'registry:chưa có block trigger.bot_turn_completed'; }
			if ( false === has_action( 'bizcity_bot_turn_completed', array( BizCity_Automation_Trigger_Matcher::instance(), 'on_bot_turn_completed' ) ) ) { $g2_missing[] = 'matcher:chưa nghe bizcity_bot_turn_completed'; }
			$g2_ok     = empty( $g2_missing );
			$g2_detail = $g2_ok ? 'PASS — turn-runner bắn hook, TRIGGER_TYPES chấp nhận, block đã catalogued, matcher đang nghe (chưa tính là có workflow nào đã chạy thật).' : 'Thiếu: ' . implode( '; ', $g2_missing ) . '.';
		}
		$emit( 'Loader - G2 Automation trigger cho bizcity_bot_turn_completed (PASS/SKIP nêu rõ, không im lặng)', $g2_ok, $g2_detail );

		// [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 — Bot Studio is the only auto-replier for Zalo Cá nhân and every
		// turn rides WP-Cron: a turn stuck past due means customers get silence. Read-only look at the cron array.
		$cron_ok     = true;
		$cron_detail = 'SKIP — BizCity_Bot_Turn_Runner::overdue_turns() chưa có trên bản này.';
		if ( class_exists( 'BizCity_Bot_Turn_Runner', false ) && method_exists( 'BizCity_Bot_Turn_Runner', 'overdue_turns' ) ) {
			$overdue     = BizCity_Bot_Turn_Runner::overdue_turns();
			$cron_ok     = 0 === (int) $overdue['count'];
			$cron_detail = $cron_ok
				? 'PASS — không có lượt bizcity_bot_run_turn nào quá hạn.' . ( $overdue['wp_cron_disabled'] ? ' (DISABLE_WP_CRON bật — cần cron hệ thống gọi wp-cron.php mỗi phút.)' : '' )
				: sprintf( 'FAIL — %d lượt bot quá hạn, trễ nhất %ds: WP-Cron không chạy nên khách không được trả lời.%s', (int) $overdue['count'], (int) $overdue['max_late'], $overdue['wp_cron_disabled'] ? ' DISABLE_WP_CRON đang bật mà không có cron hệ thống.' : ' Kiểm tra loopback/wp-cron.php hoặc thêm cron hệ thống.' );
		}
		$emit( 'Runtime - Lượt Bot Studio không kẹt trong WP-Cron (D-H7: bot là bộ trả lời duy nhất của Zalo Cá nhân)', $cron_ok, $cron_detail );

		$pass = $disk_ok && $loader_ok && $hook_priority_ok && $office_hours_ok && $negative_ok && $tools_ok && $provider_ok && $pure_ok && $schema_ok && false !== $contacts_ok && false !== $routes_ok && $g2_ok && $cron_ok;
		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Bot Studio W1–W8: file, loader, hook priority, giờ trực, lưới đỡ, registry công cụ, nguồn AI, parser/ngữ cảnh và schema đều PASS.' : 'Bot Studio chưa sẵn sàng — xem các bước fail ở trên.',
			'fix_hint' => $pass ? '' : 'Xem lại core/channel-gateway/bootstrap.php (thứ tự require + init()), BizCity_Channel_Binding::maybe_install() và BizCity_CRM_DB_Installer_V2::migrate_phase_060b().',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Bot_Studio';
	return $list;
} );
