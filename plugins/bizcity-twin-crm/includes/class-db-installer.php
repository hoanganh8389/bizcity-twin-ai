<?php
/**
 * BizCity CRM — DB Installer (6 state-snapshot tables).
 *
 * Tables (state snapshots — R-CRM-1, R-EVT-2 exception):
 *   {prefix}bizcity_crm_inboxes
 *   {prefix}bizcity_crm_contacts
 *   {prefix}bizcity_crm_contact_inboxes
 *   {prefix}bizcity_crm_conversations
 *   {prefix}bizcity_crm_messages
 *   {prefix}bizcity_crm_attachments
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) :

class BizCity_CRM_DB_Installer_V2 {

	const DB_VERSION_OPTION = 'bizcity_crm_db_ver';

	/* ----- Table-name helpers ----- */
	public static function tbl_inboxes(): string         { global $wpdb; return $wpdb->prefix . 'bizcity_crm_inboxes'; }
	public static function tbl_contacts(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contacts'; }
	public static function tbl_contact_inboxes(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contact_inboxes'; }
	public static function tbl_conversations(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_conversations'; }
	public static function tbl_messages(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_messages'; }
	public static function tbl_attachments(): string     { global $wpdb; return $wpdb->prefix . 'bizcity_crm_attachments'; }
	public static function tbl_automation_rules(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_automation_rules'; }
	public static function tbl_labels(): string           { global $wpdb; return $wpdb->prefix . 'bizcity_crm_labels'; }
	public static function tbl_conversation_labels(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_conversation_labels'; }
	public static function tbl_custom_attribute_definitions(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_custom_attribute_definitions'; }
	public static function tbl_macros(): string           { global $wpdb; return $wpdb->prefix . 'bizcity_crm_macros'; }
	public static function tbl_working_hours(): string    { global $wpdb; return $wpdb->prefix . 'bizcity_crm_working_hours'; }
	public static function tbl_sla_policies(): string     { global $wpdb; return $wpdb->prefix . 'bizcity_crm_sla_policies'; }
	public static function tbl_applied_slas(): string     { global $wpdb; return $wpdb->prefix . 'bizcity_crm_applied_slas'; }

	/* ── PHASE 0.35 M-FE.W17 — CRM module tables ── */
	public static function tbl_accounts(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_accounts'; }
	public static function tbl_biz_contacts(): string    { global $wpdb; return $wpdb->prefix . 'bizcity_crm_biz_contacts'; }
	public static function tbl_crm_tasks(): string       { global $wpdb; return $wpdb->prefix . 'bizcity_crm_tasks'; }
	public static function tbl_crm_events(): string      { global $wpdb; return $wpdb->prefix . 'bizcity_crm_events'; }
	public static function tbl_crm_documents(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_documents'; }

	/* ── PHASE 0.35 M-CRM.M1 — Sales Pipeline tables ── */
	public static function tbl_crm_leads(): string            { global $wpdb; return $wpdb->prefix . 'bizcity_crm_leads'; }
	public static function tbl_crm_opportunities(): string    { global $wpdb; return $wpdb->prefix . 'bizcity_crm_opportunities'; }
	public static function tbl_crm_opportunity_lines(): string{ global $wpdb; return $wpdb->prefix . 'bizcity_crm_opportunity_lines'; }
	public static function tbl_crm_contracts(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contracts'; }
	public static function tbl_crm_contract_lines(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contract_lines'; }

	/* ── PHASE 0.35 M-CRM.M1.W2 — Product catalog tables ── */
	public static function tbl_crm_product_categories(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_product_categories'; }
	public static function tbl_crm_products(): string           { global $wpdb; return $wpdb->prefix . 'bizcity_crm_products'; }

	/* ── PHASE 0.35 M6.W1 — Campaigns + Visits ── */
	public static function tbl_campaigns(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_campaigns'; }
	public static function tbl_campaign_visits(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_campaign_visits'; }

	/* ── PHASE 0.35 M-CRM.M2 — Invoicing tables ── */
	public static function tbl_crm_invoices(): string         { global $wpdb; return $wpdb->prefix . 'bizcity_crm_invoices'; }
	public static function tbl_crm_invoice_lines(): string    { global $wpdb; return $wpdb->prefix . 'bizcity_crm_invoice_lines'; }
	public static function tbl_crm_invoice_payments(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_invoice_payments'; }

	/* ── PHASE 0.35 M-CRM.M3 — Email Client tables ── */
	public static function tbl_crm_email_accounts(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_email_accounts'; }
	public static function tbl_crm_email_threads(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_email_threads'; }
	public static function tbl_crm_email_messages(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_email_messages'; }

	/* ── PHASE 0.37.1 — Gmail SMTP + Email automation rules ── */
	public static function tbl_gmail_smtp_accounts(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_gmail_smtp_accounts'; }
	public static function tbl_email_event_rules(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_email_event_rules'; }

	/* ── PHASE 0.35 M-CRM.M8.W2 — Contact unification (legacy biz_contacts → contacts) ── */
	public static function tbl_contact_id_map(): string     { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contact_id_map'; }

	/* ── PHASE 3.5 Wave A — Admin Chat magic links ── */
	public static function tbl_chat_magic_links(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_chat_magic_links'; }

	/* ── PHASE 3.5 Wave B — Admin Chat grants (3-axis delegation) ── */
	public static function tbl_admin_chat_grants(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_admin_chat_grants'; }

	/* ── PHASE 0.35 M-CRM.M1.W3 — Audit log ── */
	public static function tbl_crm_audit_log(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_audit_log'; }

	/* ── M-CRM.M4.Inbox v1.18.0 — Broadcasts ── */
	public static function tbl_broadcasts(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_broadcasts'; }
	public static function tbl_broadcast_recipients(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_broadcast_recipients'; }

	/* ── PHASE 0.38 v1.20.0 — Order Fulfillment Hub tables ── */
	// [2026-06-07 Johnny Chu] PHASE-0.38.W1.3 — declare 3 new recap/csat/shipment tables
	public static function tbl_order_recap_log(): string      { global $wpdb; return $wpdb->prefix . 'bizcity_crm_order_recap_log'; }
	public static function tbl_order_csat(): string           { global $wpdb; return $wpdb->prefix . 'bizcity_crm_order_csat'; }
	public static function tbl_shipment_status_log(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_shipment_status_log'; }

	/* ── PHASE 0.40 v1.21.0 — Deplao Parity: notes_doc ── */
	// [2026-06-07 Johnny Chu] PHASE-0.40.G1 — internal doc notes table
	public static function tbl_notes_doc(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_notes_doc'; }

	/* ── PHASE 3.5 v1.22.0 — Wave C: admin_chat_audit ── */
	// [2026-06-07 Johnny Chu] PHASE-3.5-WC — admin chat audit log table
	public static function tbl_admin_chat_audit(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_admin_chat_audit'; }

	/* ── PHASE-0.46 M1 — unified submissions pipeline ── */
	// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — unified submissions table helper
	public static function tbl_crm_submissions(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_submissions'; }
	// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — activities table helper (entity_type + entity_id based)
	public static function tbl_crm_activities(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_activities'; }

	public static function all_tables(): array {
		return array(
			'inboxes'                       => self::tbl_inboxes(),
			'contacts'                      => self::tbl_contacts(),
			'contact_inboxes'               => self::tbl_contact_inboxes(),
			'conversations'                 => self::tbl_conversations(),
			'messages'                      => self::tbl_messages(),
			'attachments'                   => self::tbl_attachments(),
			'automation_rules'              => self::tbl_automation_rules(),
			'labels'                        => self::tbl_labels(),
			'conversation_labels'           => self::tbl_conversation_labels(),
			'custom_attribute_definitions'  => self::tbl_custom_attribute_definitions(),
			'macros'                        => self::tbl_macros(),
			'working_hours'                 => self::tbl_working_hours(),
			'sla_policies'                  => self::tbl_sla_policies(),
			'applied_slas'                  => self::tbl_applied_slas(),
			'accounts'                      => self::tbl_accounts(),
			'crm_tasks'                     => self::tbl_crm_tasks(),
			'crm_events'                    => self::tbl_crm_events(),
			'crm_documents'                 => self::tbl_crm_documents(),
			'crm_leads'                     => self::tbl_crm_leads(),
			'crm_opportunities'             => self::tbl_crm_opportunities(),
			'crm_opportunity_lines'         => self::tbl_crm_opportunity_lines(),
			'crm_contracts'                 => self::tbl_crm_contracts(),
			'crm_contract_lines'            => self::tbl_crm_contract_lines(),
			'crm_product_categories'        => self::tbl_crm_product_categories(),
			'crm_products'                  => self::tbl_crm_products(),
			'crm_invoices'                  => self::tbl_crm_invoices(),
			'crm_invoice_lines'             => self::tbl_crm_invoice_lines(),
			'crm_invoice_payments'          => self::tbl_crm_invoice_payments(),
			'crm_email_accounts'            => self::tbl_crm_email_accounts(),
			'crm_email_threads'             => self::tbl_crm_email_threads(),
			'crm_email_messages'            => self::tbl_crm_email_messages(),
			'gmail_smtp_accounts'           => self::tbl_gmail_smtp_accounts(),
			'email_event_rules'             => self::tbl_email_event_rules(),
			'contact_id_map'                => self::tbl_contact_id_map(),
			'chat_magic_links'              => self::tbl_chat_magic_links(),
			'admin_chat_grants'             => self::tbl_admin_chat_grants(),
			'crm_audit_log'                 => self::tbl_crm_audit_log(),
			'broadcasts'                    => self::tbl_broadcasts(),
			'broadcast_recipients'          => self::tbl_broadcast_recipients(),
			// [2026-06-07 Johnny Chu] PHASE-0.40.G1 — notes_doc new table
			'crm_notes_doc'                 => self::tbl_notes_doc(),
			'crm_admin_chat_audit'          => self::tbl_admin_chat_audit(), // [2026-06-07 Johnny Chu] PHASE-3.5-WC
		);
	}

	/* ----- Lifecycle ----- */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === BIZCITY_CRM_DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Lightweight existence check used to guard cron/REST against subsites
	 * where CRM schema hasn't been installed yet (R-CRM safety).
	 *
	 * [2026-07-05 Johnny Chu] R-SHOW-TABLES — replaced SHOW TABLES LIKE with
	 * information_schema SELECT + dual-cache (static + wp_cache 1h) per rule.
	 */
	public static function table_exists( string $table ): bool {
		static $s = array();
		if ( isset( $s[ $table ] ) ) {
			return $s[ $table ]; // free: static hit
		}
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table ] = (bool) $present;
		return $s[ $table ];
	}

	/**
	 * Flush wp_cache entries for all known CRM tables.
	 * Call after dbDelta installs/creates a table.
	 *
	 * [2026-07-05 Johnny Chu] R-SHOW-TABLES — cache invalidation helper.
	 */
	public static function invalidate_tables_cache(): void {
		$blog_id = (int) get_current_blog_id();
		foreach ( self::all_tables() as $tbl ) {
			wp_cache_delete( 'bz_tbl_' . $blog_id . '_' . crc32( $tbl ), 'bizcity_tbl' );
		}
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$inboxes         = self::tbl_inboxes();
		$contacts        = self::tbl_contacts();
		$contact_inboxes = self::tbl_contact_inboxes();
		$conversations   = self::tbl_conversations();
		$messages        = self::tbl_messages();
		$attachments     = self::tbl_attachments();

		$sql = array();

		/* Inboxes — 1 row per channel-account */
		$sql[] = "CREATE TABLE `{$inboxes}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			channel_type VARCHAR(32) NOT NULL,
			channel_ref_id VARCHAR(190) NOT NULL,
			default_notebook_id BIGINT UNSIGNED NULL,
			default_assignee_id BIGINT UNSIGNED NULL,
			settings_json LONGTEXT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_channel_ref (channel_type, channel_ref_id),
			KEY idx_active (is_active)
		) {$charset};";

		/* Contacts — canonical Source-of-Truth for people. PHASE 0.35 M-CRM.M8.W2
		 * absorbs legacy biz_contacts (first_name/last_name/title/account_id) so the
		 * old table becomes a soft-deprecated mirror. */
		$sql[] = "CREATE TABLE `{$contacts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			first_name VARCHAR(95) NULL,
			last_name VARCHAR(95) NULL,
			title VARCHAR(120) NULL,
			account_id BIGINT UNSIGNED NULL,
			email VARCHAR(190) NULL,
			phone VARCHAR(32) NULL,
			avatar_url TEXT NULL,
			additional_attributes LONGTEXT NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NULL,
			tags_json LONGTEXT NULL,
			acquisition_source VARCHAR(64) NULL,
			acquisition_meta_json LONGTEXT NULL,
			points_balance_cache INT NOT NULL DEFAULT 0,
			platform VARCHAR(32) NULL,
			platform_uid VARCHAR(190) NULL,
			source VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_email (email),
			KEY idx_phone (phone),
			KEY idx_wp_user (wp_user_id),
			KEY idx_account (account_id),
			KEY idx_owner (owner_id),
			KEY idx_acquisition (acquisition_source),
			KEY idx_deleted (deleted_at),
			KEY idx_platform_uid (platform, platform_uid)
		) {$charset};";

		/* M-CRM.M8.W2 — biz_contacts → contacts ID mapping. One row per legacy
		 * biz_contact merged or migrated. UNIQUE on old_biz_id keeps reads idempotent
		 * across rerun of the migration job. */
		$contact_id_map = self::tbl_contact_id_map();
		$sql[] = "CREATE TABLE `{$contact_id_map}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			old_biz_id BIGINT UNSIGNED NOT NULL,
			new_contact_id BIGINT UNSIGNED NOT NULL,
			match_method VARCHAR(32) NOT NULL DEFAULT 'inserted',
			migrated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_old (old_biz_id),
			KEY idx_new (new_contact_id)
		) {$charset};";

		/* Contact-inboxes — N:N with source_id (PSID/Zalo user_id/visitor_id) */
		$sql[] = "CREATE TABLE `{$contact_inboxes}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			inbox_id BIGINT UNSIGNED NOT NULL,
			source_id VARCHAR(190) NOT NULL,
			last_seen_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_inbox_source (inbox_id, source_id),
			KEY idx_contact (contact_id)
		) {$charset};";

		/* Conversations — 1 thread (PHASE 0.35 added: snoozed_until, waiting_since, first_reply_at, cached_label_list, sla_policy_id, team_id) */
		$sql[] = "CREATE TABLE `{$conversations}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			inbox_id BIGINT UNSIGNED NOT NULL,
			contact_inbox_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			assignee_id BIGINT UNSIGNED NULL,
			notebook_id BIGINT UNSIGNED NULL,
			character_id BIGINT UNSIGNED NULL,
			priority TINYINT NOT NULL DEFAULT 0,
			snoozed_until BIGINT NULL,
			waiting_since BIGINT NULL,
			first_reply_at BIGINT NULL,
			cached_label_list TEXT NULL,
			sla_policy_id BIGINT UNSIGNED NULL,
			team_id BIGINT UNSIGNED NULL,
			last_message_id BIGINT UNSIGNED NULL,
			last_activity_at DATETIME NULL,
			unread_count INT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(32) NULL,
			channel_thread_id VARCHAR(190) NULL,
			chat_id VARCHAR(190) NULL,
			contact_id BIGINT UNSIGNED NULL,
			account_id VARCHAR(190) NULL,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_inbox_status_act (inbox_id, status, last_activity_at),
			KEY idx_assignee_status (assignee_id, status),
			KEY idx_contact_inbox (contact_inbox_id),
			KEY idx_priority_status (priority, status),
			KEY idx_waiting (waiting_since),
			KEY idx_snoozed (snoozed_until),
			KEY idx_platform_thread (platform, channel_thread_id),
			KEY idx_conv_contact (contact_id),
			KEY idx_blog (blog_id)
		) {$charset};";

		/* Messages — every message (PHASE 0.35 added: macro_id, automation_rule_id) */
		$sql[] = "CREATE TABLE `{$messages}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			inbox_id BIGINT UNSIGNED NOT NULL,
			external_source_id VARCHAR(190) NULL,
			content LONGTEXT NULL,
			content_type VARCHAR(16) NOT NULL DEFAULT 'text',
			message_type VARCHAR(16) NOT NULL DEFAULT 'incoming',
			sender_type VARCHAR(16) NOT NULL DEFAULT 'contact',
			sender_id BIGINT UNSIGNED NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'sent',
			ai_metadata_json LONGTEXT NULL,
			event_uuid CHAR(36) NULL,
			responder_kind VARCHAR(10) NULL,
			responder_user_id BIGINT UNSIGNED NULL,
			character_id BIGINT UNSIGNED NULL,
			macro_id BIGINT UNSIGNED NULL,
			automation_rule_id BIGINT UNSIGNED NULL,
			platform VARCHAR(32) NULL,
			platform_msg_id VARCHAR(190) NULL,
			body LONGTEXT NULL,
			payload_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_inbox_external (inbox_id, external_source_id),
			KEY idx_conv_created (conversation_id, created_at),
			KEY idx_sender (sender_type, message_type),
			KEY idx_event_uuid (event_uuid),
			KEY idx_responder_user (responder_user_id),
			KEY idx_responder_kind (responder_kind),
			KEY idx_rule (automation_rule_id),
			KEY idx_macro (macro_id)
		) {$charset};";

		/* Attachments */
		$sql[] = "CREATE TABLE `{$attachments}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			message_id BIGINT UNSIGNED NOT NULL,
			file_type VARCHAR(16) NOT NULL DEFAULT 'file',
			data_url TEXT NULL,
			thumb_url TEXT NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_message (message_id)
		) {$charset};";

		/* Automation rules — PHASE 0.35 M2.W1 */
		$rules = self::tbl_automation_rules();
		$sql[] = "CREATE TABLE `{$rules}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			description TEXT NULL,
			event_name VARCHAR(64) NOT NULL,
			inbox_id BIGINT UNSIGNED NULL,
			conditions_json LONGTEXT NULL,
			actions_json LONGTEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			run_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_run_at DATETIME NULL,
			created_by_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_event_active (event_name, active),
			KEY idx_inbox (inbox_id)
		) {$charset};";

		/* Labels — PHASE 0.35 M3.W1 */
		$labels = self::tbl_labels();
		$sql[] = "CREATE TABLE `{$labels}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL,
			description TEXT NULL,
			color VARCHAR(16) NOT NULL DEFAULT '#1f93ff',
			show_on_sidebar TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_title (title)
		) {$charset};";

		/* Conversation ↔ Label join — PHASE 0.35 M3.W1 */
		$cl = self::tbl_conversation_labels();
		$sql[] = "CREATE TABLE `{$cl}` (
			conversation_id BIGINT UNSIGNED NOT NULL,
			label_id BIGINT UNSIGNED NOT NULL,
			assigned_by BIGINT UNSIGNED NULL,
			assigned_at DATETIME NOT NULL,
			PRIMARY KEY  (conversation_id, label_id),
			KEY idx_label (label_id)
		) {$charset};";

		/* Custom Attribute Definitions — PHASE 0.35 M3.W3 */
		$cad = self::tbl_custom_attribute_definitions();
		$sql[] = "CREATE TABLE `{$cad}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attribute_key VARCHAR(64) NOT NULL,
			display_name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			display_type VARCHAR(32) NOT NULL DEFAULT 'text',
			target VARCHAR(16) NOT NULL DEFAULT 'contact',
			regex_pattern VARCHAR(255) NULL,
			options_json LONGTEXT NULL,
			default_value TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_target_key (target, attribute_key)
		) {$charset};";

		/* Macros — PHASE 0.35 M3.W5 */
		$macros = self::tbl_macros();
		$sql[] = "CREATE TABLE `{$macros}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			visibility VARCHAR(16) NOT NULL DEFAULT 'global',
			owner_user_id BIGINT UNSIGNED NULL,
			template LONGTEXT NULL,
			actions_json LONGTEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			run_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_used_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_owner (owner_user_id),
			KEY idx_visibility_active (visibility, active)
		) {$charset};";

		/* Working Hours — PHASE 0.35 M4.W1 (per-inbox 7-day grid) */
		$wh = self::tbl_working_hours();
		$sql[] = "CREATE TABLE `{$wh}` (
			inbox_id BIGINT UNSIGNED NOT NULL,
			day_of_week TINYINT NOT NULL,
			is_open TINYINT(1) NOT NULL DEFAULT 1,
			open_time TIME NOT NULL DEFAULT '09:00:00',
			close_time TIME NOT NULL DEFAULT '18:00:00',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (inbox_id, day_of_week)
		) {$charset};";

		/* SLA Policies — PHASE 0.35 M4.W2 */
		$slap = self::tbl_sla_policies();
		$sql[] = "CREATE TABLE `{$slap}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			frt_threshold_minutes INT UNSIGNED NULL,
			nrt_threshold_minutes INT UNSIGNED NULL,
			rt_threshold_minutes  INT UNSIGNED NULL,
			only_during_business_hours TINYINT(1) NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_active (active)
		) {$charset};";

		/* Applied SLAs — PHASE 0.35 M4.W2 (one row per conversation under SLA) */
		$appl = self::tbl_applied_slas();
		$sql[] = "CREATE TABLE `{$appl}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			sla_policy_id BIGINT UNSIGNED NOT NULL,
			applied_at BIGINT NOT NULL,
			frt_due_at BIGINT NULL,
			nrt_due_at BIGINT NULL,
			rt_due_at  BIGINT NULL,
			frt_breached_at BIGINT NULL,
			nrt_breached_at BIGINT NULL,
			rt_breached_at  BIGINT NULL,
			met_at BIGINT NULL,
			last_evaluated_at BIGINT NULL,
			state VARCHAR(16) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_conv (conversation_id),
			KEY idx_state_due (state, frt_due_at),
			KEY idx_policy (sla_policy_id)
		) {$charset};";

		/* ── PHASE 0.35 M-FE.W17 — CRM module tables ── */

		/* Accounts — B2B companies / deal accounts */
		$accounts = self::tbl_accounts();
		$sql[] = "CREATE TABLE `{$accounts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			industry VARCHAR(64) NULL,
			size VARCHAR(32) NULL,
			website VARCHAR(255) NULL,
			country VARCHAR(64) NULL,
			annual_revenue DECIMAL(18,2) NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT 'active',
			owner_id BIGINT UNSIGNED NULL,
			opportunities_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_owner (owner_id),
			KEY idx_status (status),
			KEY idx_industry (industry)
		) {$charset};";

		/* Biz Contacts — DEPRECATED in PHASE 0.35 M-CRM.M8.W2.5.
		 * Schema unified into canonical `bizcity_crm_contacts`. Legacy rows are
		 * migrated by `BizCity_CRM_Migrate_Biz_Contacts::run()` and the table is
		 * dropped via the Sprint Diagnostic admin action. The table is no longer
		 * created by `install()` and is excluded from `all_tables()`. */

		/* CRM Tasks — internal tasks with priority/status/due_date */
		$crm_tasks = self::tbl_crm_tasks();
		$sql[] = "CREATE TABLE `{$crm_tasks}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT 'open',
			priority VARCHAR(16) NOT NULL DEFAULT 'medium',
			due_date DATE NULL,
			assignee_id BIGINT UNSIGNED NULL,
			related_entity_type VARCHAR(32) NULL,
			related_entity_id BIGINT UNSIGNED NULL,
			notes LONGTEXT NULL,
			completed TINYINT(1) NOT NULL DEFAULT 0,
			completed_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_assignee_status (assignee_id, status),
			KEY idx_due_date (due_date),
			KEY idx_related (related_entity_type, related_entity_id)
		) {$charset};";

		/* CRM Events — DEPRECATED schema as of 2026-05-13 (M-CRM.M12 v2 phase 2).
		 * The `wp_bizcity_crm_events` table is now owned by
		 * `BizCity_Scheduler_Manager` (core/scheduler) which renames the legacy
		 * `wp_bizcity_scheduler_events` table into this slot during its v3
		 * schema migration and adds event_type/metadata/google_account_id.
		 * The CRM installer no longer CREATEs this table — see
		 * PHASE-0.35-WAVES.md §A M-CRM.M12 v2 for the unification plan.
		 * The `tbl_crm_events()` helper is kept as a back-compat alias only. */

		/* CRM Documents — file attachments for CRM records */
		$crm_docs = self::tbl_crm_documents();
		$sql[] = "CREATE TABLE `{$crm_docs}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			type VARCHAR(64) NOT NULL DEFAULT 'file',
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			path VARCHAR(512) NOT NULL DEFAULT '',
			uploaded_by BIGINT UNSIGNED NULL,
			related_entity_type VARCHAR(32) NULL,
			related_entity_id BIGINT UNSIGNED NULL,
			uploaded_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_related (related_entity_type, related_entity_id),
			KEY idx_uploader (uploaded_by)
		) {$charset};";

		/* ===== M-CRM.M1 — Sales Pipeline ===== */

		/* Leads — raw inbound prospect (pre-qualified) */
		$crm_leads = self::tbl_crm_leads();
		$sql[] = "CREATE TABLE `{$crm_leads}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(120) NOT NULL DEFAULT '',
			last_name  VARCHAR(120) NOT NULL DEFAULT '',
			email VARCHAR(190) NULL,
			phone VARCHAR(64) NULL,
			company VARCHAR(190) NULL,
			title VARCHAR(120) NULL,
			source VARCHAR(64) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'new',
			rating VARCHAR(16) NULL,
			owner_id BIGINT UNSIGNED NULL,
			account_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			converted_at DATETIME NULL,
			converted_opportunity_id BIGINT UNSIGNED NULL,
			notes LONGTEXT NULL,
			custom_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_owner (owner_id),
			KEY idx_email (email),
			KEY idx_phone (phone),
			KEY idx_account (account_id)
		) {$charset};";

		/* Opportunities — deal in pipeline */
		$crm_opps = self::tbl_crm_opportunities();
		$sql[] = "CREATE TABLE `{$crm_opps}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			account_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			lead_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NULL,
			stage VARCHAR(32) NOT NULL DEFAULT 'qualification',
			status VARCHAR(32) NOT NULL DEFAULT 'open',
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			probability TINYINT UNSIGNED NOT NULL DEFAULT 10,
			expected_revenue DECIMAL(18,2) NOT NULL DEFAULT 0,
			close_date DATE NULL,
			actual_close_date DATE NULL,
			lost_reason VARCHAR(190) NULL,
			next_step VARCHAR(255) NULL,
			description LONGTEXT NULL,
			custom_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_stage (stage),
			KEY idx_status (status),
			KEY idx_owner (owner_id),
			KEY idx_account (account_id),
			KEY idx_close (close_date)
		) {$charset};";

		/* Opportunity lines — product/service rows */
		$crm_opp_lines = self::tbl_crm_opportunity_lines();
		$sql[] = "CREATE TABLE `{$crm_opp_lines}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			opportunity_id BIGINT UNSIGNED NOT NULL,
			product_code VARCHAR(64) NULL,
			description VARCHAR(255) NOT NULL DEFAULT '',
			quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_opp (opportunity_id, position)
		) {$charset};";

		/* Contracts — signed agreement, lifecycle distinct from invoice */
		$crm_contracts = self::tbl_crm_contracts();
		$sql[] = "CREATE TABLE `{$crm_contracts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(64) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			account_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			opportunity_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'draft',
			start_date DATE NULL,
			end_date DATE NULL,
			signed_date DATE NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			terms LONGTEXT NULL,
			custom_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_code (code),
			KEY idx_status (status),
			KEY idx_account (account_id),
			KEY idx_opp (opportunity_id),
			KEY idx_dates (start_date, end_date)
		) {$charset};";

		/* Contract lines */
		$crm_contract_lines = self::tbl_crm_contract_lines();
		$sql[] = "CREATE TABLE `{$crm_contract_lines}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NOT NULL,
			product_code VARCHAR(64) NULL,
			description VARCHAR(255) NOT NULL DEFAULT '',
			quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_contract (contract_id, position)
		) {$charset};";

		/* PHASE 0.35 M-CRM.M1.W2 — Product categories (taxonomy) */
		$crm_product_categories = self::tbl_crm_product_categories();
		$sql[] = "CREATE TABLE `{$crm_product_categories}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			slug VARCHAR(190) NOT NULL DEFAULT '',
			parent_id BIGINT UNSIGNED NULL,
			description TEXT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_slug (slug),
			KEY idx_parent (parent_id)
		) {$charset};";

		/* PHASE 0.35 M-CRM.M1.W2 — Products catalog (PRODUCT/SERVICE, recurring billing) */
		$crm_products = self::tbl_crm_products();
		$sql[] = "CREATE TABLE `{$crm_products}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			category_id BIGINT UNSIGNED NULL,
			sku VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			type VARCHAR(16) NOT NULL DEFAULT 'product',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			description TEXT NULL,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
			is_recurring TINYINT(1) NOT NULL DEFAULT 0,
			billing_period VARCHAR(16) NULL,
			stock_qty DECIMAL(14,3) NULL,
			custom_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_sku (sku),
			KEY idx_category (category_id),
			KEY idx_status (status),
			KEY idx_type (type),
			KEY idx_name (name)
		) {$charset};";

		/* ===== M-CRM.M2 — Invoicing (lifecycle: draft → sent → paid|overdue|voided|refunded) ===== */
		$crm_invoices = self::tbl_crm_invoices();
		$sql[] = "CREATE TABLE `{$crm_invoices}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			number VARCHAR(64) NOT NULL DEFAULT '',
			account_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			opportunity_id BIGINT UNSIGNED NULL,
			contract_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			fx_rate DECIMAL(14,6) NOT NULL DEFAULT 1,
			subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			tax_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			total DECIMAL(18,2) NOT NULL DEFAULT 0,
			amount_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
			amount_due DECIMAL(18,2) NOT NULL DEFAULT 0,
			issue_date DATE NULL,
			due_date DATE NULL,
			sent_at DATETIME NULL,
			paid_at DATETIME NULL,
			voided_at DATETIME NULL,
			notes LONGTEXT NULL,
			billing_address LONGTEXT NULL,
			custom_json LONGTEXT NULL,
			wc_order_id BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_number (number),
			KEY idx_status_due (status, due_date),
			KEY idx_account (account_id),
			KEY idx_contact (contact_id),
			KEY idx_contract (contract_id),
			KEY idx_owner (owner_id),
			KEY idx_wc_order (wc_order_id)
		) {$charset};";

		$crm_invoice_lines = self::tbl_crm_invoice_lines();
		$sql[] = "CREATE TABLE `{$crm_invoice_lines}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NULL,
			product_code VARCHAR(64) NULL,
			description VARCHAR(255) NOT NULL DEFAULT '',
			quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			discount_type VARCHAR(16) NOT NULL DEFAULT 'percentage',
			tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_invoice (invoice_id, position),
			KEY idx_product (product_id)
		) {$charset};";

		$crm_invoice_payments = self::tbl_crm_invoice_payments();
		$sql[] = "CREATE TABLE `{$crm_invoice_payments}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			method VARCHAR(32) NOT NULL DEFAULT 'manual',
			reference VARCHAR(190) NULL,
			paid_at DATETIME NOT NULL,
			notes TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_invoice (invoice_id),
			KEY idx_paid_at (paid_at)
		) {$charset};";

		/* ===== M-CRM.M3 — Email Client (IMAP fetch + SMTP send via core/smtp) ===== */
		$crm_email_accounts = self::tbl_crm_email_accounts();
		$sql[] = "CREATE TABLE `{$crm_email_accounts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			label VARCHAR(190) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			imap_host VARCHAR(190) NOT NULL DEFAULT '',
			imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
			imap_secure VARCHAR(8) NOT NULL DEFAULT 'ssl',
			imap_user VARCHAR(190) NOT NULL DEFAULT '',
			imap_pass_enc TEXT NULL,
			imap_folder VARCHAR(64) NOT NULL DEFAULT 'INBOX',
			smtp_use_global TINYINT(1) NOT NULL DEFAULT 1,
			smtp_host VARCHAR(190) NULL,
			smtp_port SMALLINT UNSIGNED NULL,
			smtp_secure VARCHAR(8) NULL,
			smtp_user VARCHAR(190) NULL,
			smtp_pass_enc TEXT NULL,
			last_uid_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_sync_at DATETIME NULL,
			last_sync_status VARCHAR(16) NULL,
			last_sync_error TEXT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_email (user_id, email),
			KEY idx_active (is_active)
		) {$charset};";

		$crm_email_threads = self::tbl_crm_email_threads();
		$sql[] = "CREATE TABLE `{$crm_email_threads}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			normalized_subject VARCHAR(255) NOT NULL DEFAULT '',
			participants_json LONGTEXT NULL,
			message_count INT UNSIGNED NOT NULL DEFAULT 0,
			unread_count INT UNSIGNED NOT NULL DEFAULT 0,
			has_attachment TINYINT(1) NOT NULL DEFAULT 0,
			labels_json LONGTEXT NULL,
			last_message_at DATETIME NULL,
			related_entity_type VARCHAR(32) NULL,
			related_entity_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_account_last (account_id, last_message_at),
			KEY idx_subject (normalized_subject),
			KEY idx_related (related_entity_type, related_entity_id)
		) {$charset};";

		$crm_email_messages = self::tbl_crm_email_messages();
		$sql[] = "CREATE TABLE `{$crm_email_messages}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			thread_id BIGINT UNSIGNED NULL,
			direction VARCHAR(8) NOT NULL DEFAULT 'in',
			imap_uid BIGINT UNSIGNED NULL,
			message_id_header VARCHAR(190) NULL,
			in_reply_to VARCHAR(190) NULL,
			from_email VARCHAR(190) NULL,
			from_name VARCHAR(190) NULL,
			to_json LONGTEXT NULL,
			cc_json LONGTEXT NULL,
			bcc_json LONGTEXT NULL,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			body_html LONGTEXT NULL,
			body_text LONGTEXT NULL,
			attachments_json LONGTEXT NULL,
			is_seen TINYINT(1) NOT NULL DEFAULT 0,
			is_starred TINYINT(1) NOT NULL DEFAULT 0,
			received_at DATETIME NULL,
			sent_at DATETIME NULL,
			send_status VARCHAR(16) NULL,
			send_error TEXT NULL,
			raw_size INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_account_uid (account_id, imap_uid),
			KEY idx_thread (thread_id),
			KEY idx_message_id (message_id_header),
			KEY idx_received (received_at),
			KEY idx_direction (direction)
		) {$charset};";

		/* PHASE 0.35 M6.W1 — Campaigns (UNIQUE BIZCITY: QR · UTM · loyalty) */
		$campaigns       = self::tbl_campaigns();
		$campaign_visits = self::tbl_campaign_visits();

		$sql[] = "CREATE TABLE `{$campaigns}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(64) NOT NULL,
			name VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			landing_url TEXT NULL,
			utm_source VARCHAR(120) NULL,
			utm_medium VARCHAR(120) NULL,
			utm_campaign VARCHAR(120) NULL,
			utm_content VARCHAR(120) NULL,
			utm_term VARCHAR(120) NULL,
			loyalty_points_award INT NOT NULL DEFAULT 0,
			notebook_id BIGINT UNSIGNED NULL,
			welcome_template_id BIGINT UNSIGNED NULL,
			bound_character_id BIGINT UNSIGNED NULL,
			bound_notebook_id BIGINT UNSIGNED NULL,
			scenario_action_type VARCHAR(20) NOT NULL DEFAULT 'send_message',
			scenario_shortcode TEXT NULL,
			scenario_template TEXT NULL,
			scenario_attrs_json LONGTEXT NULL,
			scenario_prompt TEXT NULL,
			reminder_delay INT NOT NULL DEFAULT 0,
			reminder_unit VARCHAR(10) NOT NULL DEFAULT 'minutes',
			reminder_text TEXT NULL,
			reminder_only TINYINT(1) NOT NULL DEFAULT 0,
			imported_from_bizgpt_flow_id BIGINT UNSIGNED NULL,
			notes_json LONGTEXT NULL,
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			deleted_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_code (code),
			KEY idx_status (status),
			KEY idx_window (starts_at, ends_at),
			KEY idx_deleted (deleted_at),
			KEY idx_imported_flow (imported_from_bizgpt_flow_id),
			KEY idx_action_type (scenario_action_type)
		) {$charset};";

		/* M6.W1 — visit ledger (1 row per scan/click; converted_contact_id filled by W4) */
		$sql[] = "CREATE TABLE `{$campaign_visits}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			client_id VARCHAR(190) NOT NULL,
			contact_id BIGINT UNSIGNED NULL,
			converted_contact_id BIGINT UNSIGNED NULL,
			referer TEXT NULL,
			user_agent VARCHAR(255) NULL,
			ip_hash CHAR(64) NULL,
			utm_source VARCHAR(120) NULL,
			utm_medium VARCHAR(120) NULL,
			utm_campaign VARCHAR(120) NULL,
			utm_content VARCHAR(120) NULL,
			utm_term VARCHAR(120) NULL,
			meta_json LONGTEXT NULL,
			converted_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_campaign_created (campaign_id, created_at),
			KEY idx_client (client_id),
			KEY idx_converted (converted_contact_id),
			KEY idx_created (created_at)
		) {$charset};";

		/* PHASE 3.5 Wave A — Admin Chat magic links (single-use, TTL-bound). */
		$chat_magic_links = self::tbl_chat_magic_links();
		$sql[] = "CREATE TABLE `{$chat_magic_links}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash CHAR(64) NOT NULL,
			platform VARCHAR(32) NOT NULL,
			chat_id VARCHAR(190) NOT NULL,
			bot_id VARCHAR(64) NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			intent VARCHAR(32) NOT NULL DEFAULT 'login',
			character_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			issued_ip VARCHAR(64) NULL,
			consumed_at DATETIME NULL,
			consumed_ip VARCHAR(64) NULL,
			consumed_ua VARCHAR(255) NULL,
			expires_at DATETIME NOT NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_token (token_hash),
			KEY idx_chat_lookup (platform, chat_id, expires_at),
			KEY idx_blog_consumed (blog_id, consumed_at)
		) {$charset};";

		/* PHASE 3.5 Wave B — Admin Chat grants (3-axis delegation: WHO×WHAT×WHERE). */
		$admin_chat_grants = self::tbl_admin_chat_grants();
		$sql[] = "CREATE TABLE `{$admin_chat_grants}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			character_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel_binding_id BIGINT UNSIGNED NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			platform VARCHAR(32) NOT NULL DEFAULT '',
			chat_id VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			allow_producer TINYINT(1) NOT NULL DEFAULT 1,
			allow_retriever TINYINT(1) NOT NULL DEFAULT 0,
			allow_distributor TINYINT(1) NOT NULL DEFAULT 0,
			tool_overrides_json LONGTEXT NULL,
			inbox_notebook_id BIGINT UNSIGNED NULL,
			quota_per_day INT NOT NULL DEFAULT 50,
			quota_used_today INT NOT NULL DEFAULT 0,
			quota_reset_at DATETIME NULL,
			granted_by_user_id BIGINT UNSIGNED NULL,
			granted_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			revoked_by BIGINT UNSIGNED NULL,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_guru_binding (user_id, character_id, channel_binding_id),
			KEY idx_chat (platform, chat_id, status),
			KEY idx_status (status),
			KEY idx_blog (blog_id, status)
		) {$charset};";

		/* ===== PHASE 0.37.1 — Gmail SMTP accounts (single-tenant Gmail App Password store) ===== */
		$gmail_smtp = self::tbl_gmail_smtp_accounts();
		$sql[] = "CREATE TABLE `{$gmail_smtp}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(190) NOT NULL DEFAULT '',
			from_email VARCHAR(190) NOT NULL DEFAULT '',
			from_name VARCHAR(190) NOT NULL DEFAULT '',
			smtp_host VARCHAR(190) NOT NULL DEFAULT 'smtp.gmail.com',
			smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
			smtp_secure VARCHAR(8) NOT NULL DEFAULT 'tls',
			smtp_user VARCHAR(190) NOT NULL DEFAULT '',
			smtp_pass_enc TEXT NULL,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			last_test_at DATETIME NULL,
			last_test_ok TINYINT(1) NULL,
			last_test_msg TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_default (is_default, is_active),
			KEY idx_user (smtp_user)
		) {$charset};";

		/* ===== PHASE 0.37.1 — Email automation rules (event→template wire) ===== */
		$email_rules = self::tbl_email_event_rules();
		$sql[] = "CREATE TABLE `{$email_rules}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			event_key VARCHAR(120) NOT NULL,
			account_id BIGINT UNSIGNED NULL,
			is_enabled TINYINT(1) NOT NULL DEFAULT 1,
			to_template TEXT NOT NULL,
			cc_template TEXT NULL,
			bcc_template TEXT NULL,
			subject_template VARCHAR(500) NOT NULL DEFAULT '',
			body_template LONGTEXT NULL,
			conditions_json LONGTEXT NULL,
			last_fired_at DATETIME NULL,
			last_fire_status VARCHAR(16) NULL,
			last_fire_error TEXT NULL,
			fire_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_event_enabled (event_key, is_enabled),
			KEY idx_account (account_id)
		) {$charset};";

		/* PHASE 0.35 M-CRM.M1.W3 — Audit log (immutable trail for CRM entity mutations). */
		$audit_log = self::tbl_crm_audit_log();
		$sql[] = "CREATE TABLE `{$audit_log}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(64) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(32) NOT NULL DEFAULT 'updated',
			before_json LONGTEXT NULL,
			after_json LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			user_label VARCHAR(190) NULL,
			event_uuid VARCHAR(36) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_user (user_id),
			KEY idx_created_at (created_at),
			KEY idx_event_uuid (event_uuid)
		) {$charset};";

		foreach ( $sql as $stmt ) {
			dbDelta( $stmt );
		}

		// PHASE 0.35 M1.W1 — idempotent ALTER fallback for installs created before v1.2.0
		// dbDelta is good at adding columns but flaky on KEY changes; we run explicit
		// guarded ALTERs to be safe across MySQL 5.7 / 8.0 / MariaDB 10.x.
		self::migrate_phase_035();
		// PHASE 0.35 M-FE.W17 — add CRM module columns + tables.
		self::migrate_phase_036();
		// PHASE 0.35 M-CRM.M1 — sales pipeline tables.
		self::migrate_phase_037();
		// PHASE 0.35 M-CRM.M1.W2 — product catalog + line-item normalization.
		self::migrate_phase_038();
		// PHASE 0.35 M6.W1 — campaigns + campaign visits (dbDelta handles tables; stub kept for parity).
		self::migrate_phase_039();
		// PHASE 0.35 M-CRM.M8.W2 — contact unification cols + contact_id_map + invoices.wc_order_id.
		self::migrate_phase_040();
		// PHASE 0.35 M6.W10 — Campaign scenario builder columns (10 new) + 2 indexes.
		self::migrate_phase_041();
		// 2026-05-25 v1.16.0 — Customer Source adapter columns on opportunities + leads.
		// See core/diagnostics/changelog/modules.twin-crm.json + class-pipeline-sync.php.
		self::migrate_phase_042();
		// 2026-05-28 v1.17.0 — Audit log table (M-CRM.M1.W3).
		self::migrate_phase_043();
		// 2026-05-28 v1.18.0 — Broadcasts + lead_score/segment (M-CRM.M4.Inbox).
		self::migrate_phase_044();
		// [2026-06-07 Johnny Chu] PHASE-0.38.W1.3 — Order Fulfillment Hub tables (v1.20.0).
		self::migrate_phase_045();
		// [2026-06-07 Johnny Chu] PHASE-0.40.G1 — Deplao Parity schema: variants_json + checklist_json + notes_doc (v1.21.0).
		self::migrate_phase_046();
		// [2026-06-07 Johnny Chu] PHASE-3.5-WC — v1.22.0 admin_chat_audit table.
		self::migrate_phase_047();
		// [2026-06-07 Johnny Chu] PHASE-0.43 — v1.23.0 Broadcast Mass-Send: action_flags_json + delay_sec + scheduled_send_at.
		self::migrate_phase_048();
		// [2026-07-03 Johnny Chu] PHASE-0.46 M1 — v1.24.0 bizcity_crm_activities table + bizcity_crm_submissions table.
		self::migrate_phase_049();

		update_option( self::DB_VERSION_OPTION, BIZCITY_CRM_DB_VERSION );
	}

	/**
	 * Idempotent column + index migration for PHASE 0.35 M1.W1.
	 *
	 * Safe to run repeatedly. Each ALTER is guarded by SHOW COLUMNS / SHOW INDEX
	 * so re-running on a fully migrated DB is a no-op.
	 *
	 * @return array<string,string> Map of operation => result ('skip'|'ok'|'fail:<msg>').
	 */
	public static function migrate_phase_035(): array {
		global $wpdb;
		$results = array();

		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb, &$results ): void {
			$key = "col:{$table}.{$column}";
			if ( self::column_exists( $table, $column ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb, &$results ): void {
			$key = "idx:{$table}.{$index}";
			if ( self::index_exists( $table, $index ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$conv = self::tbl_conversations();
		$add_column( $conv, 'snoozed_until',     'BIGINT NULL AFTER priority' );
		$add_column( $conv, 'waiting_since',     'BIGINT NULL AFTER snoozed_until' );
		$add_column( $conv, 'first_reply_at',    'BIGINT NULL AFTER waiting_since' );
		$add_column( $conv, 'cached_label_list', 'TEXT NULL AFTER first_reply_at' );
		$add_column( $conv, 'sla_policy_id',     'BIGINT UNSIGNED NULL AFTER cached_label_list' );
		$add_column( $conv, 'team_id',           'BIGINT UNSIGNED NULL AFTER sla_policy_id' );
		$add_index(  $conv, 'idx_priority_status', '(priority, status)' );
		$add_index(  $conv, 'idx_waiting',         '(waiting_since)' );
		$add_index(  $conv, 'idx_snoozed',         '(snoozed_until)' );

		$msg = self::tbl_messages();
		$add_column( $msg, 'macro_id',           'BIGINT UNSIGNED NULL AFTER character_id' );
		$add_column( $msg, 'automation_rule_id', 'BIGINT UNSIGNED NULL AFTER macro_id' );
		$add_index(  $msg, 'idx_rule',  '(automation_rule_id)' );
		$add_index(  $msg, 'idx_macro', '(macro_id)' );

		$ct = self::tbl_contacts();
		$add_column( $ct, 'acquisition_source',     "VARCHAR(64) NULL AFTER wp_user_id" );
		$add_column( $ct, 'acquisition_meta_json',  'LONGTEXT NULL AFTER acquisition_source' );
		$add_column( $ct, 'points_balance_cache',   'INT NOT NULL DEFAULT 0 AFTER acquisition_meta_json' );
		$add_index(  $ct, 'idx_acquisition', '(acquisition_source)' );

		return $results;
	}

	/**
	 * Idempotent migration for PHASE 0.35 M-FE.W17.
	 *
	 * New tables (accounts, biz_contacts, crm_tasks, crm_events, crm_documents)
	 * are fully handled by dbDelta() in install(). No column migrations needed.
	 *
	 * @return array<string,string>
	 */
	public static function migrate_phase_036(): array {
		return array();
	}

	/**
	 * Idempotent migration for PHASE 0.35 M-CRM.M1 (Sales Pipeline).
	 *
	 * New tables (crm_leads, crm_opportunities, crm_opportunity_lines,
	 * crm_contracts, crm_contract_lines) are fully handled by dbDelta().
	 */
	public static function migrate_phase_037(): array {
		return array();
	}

	/**
	 * Idempotent migration for PHASE 0.35 M-CRM.M1.W2 (Product Catalog).
	 *
	 * - Adds `product_id` + `discount_type` columns to opportunity_lines and contract_lines.
	 * - New tables (crm_products, crm_product_categories) handled by dbDelta() in install().
	 *
	 * @return array<string,string>
	 */
	public static function migrate_phase_038(): array {
		global $wpdb;
		$results = array();

		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb, &$results ): void {
			$key = "col:{$table}.{$column}";
			if ( self::column_exists( $table, $column ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb, &$results ): void {
			$key = "idx:{$table}.{$index}";
			if ( self::index_exists( $table, $index ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$opp_lines = self::tbl_crm_opportunity_lines();
		$add_column( $opp_lines, 'product_id',    'BIGINT UNSIGNED NULL AFTER opportunity_id' );
		$add_column( $opp_lines, 'discount_type', "VARCHAR(16) NOT NULL DEFAULT 'percentage' AFTER discount_pct" );
		$add_index(  $opp_lines, 'idx_product',   '(product_id)' );

		$ct_lines = self::tbl_crm_contract_lines();
		$add_column( $ct_lines, 'product_id',    'BIGINT UNSIGNED NULL AFTER contract_id' );
		$add_column( $ct_lines, 'discount_type', "VARCHAR(16) NOT NULL DEFAULT 'percentage' AFTER discount_pct" );
		$add_index(  $ct_lines, 'idx_product',   '(product_id)' );

		return $results;
	}

	/**
	 * Idempotent migration for PHASE 0.35 M6 (Campaigns).
	 *
	 * dbDelta() in install() handles the W1 base tables. This hook adds
	 * incremental columns introduced after W1 without rewriting install():
	 *   - M6.W9 (1.10.3): welcome_template_id, bound_character_id,
	 *                     bound_notebook_id on tbl_campaigns().
	 */
	public static function migrate_phase_039(): array {
		global $wpdb;
		$results = array();
		$tbl     = self::tbl_campaigns();

		$add_column = function ( $table, $col, $ddl ) use ( $wpdb, &$results ) {
			if ( ! self::column_exists( $table, $col ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$ddl}" );
				$results[] = sprintf( '+col %s.%s', $table, $col );
			}
		};

		// M6.W9 — Campaign ↔ Scenario binding columns.
		$add_column( $tbl, 'welcome_template_id', 'BIGINT UNSIGNED NULL AFTER notebook_id' );
		$add_column( $tbl, 'bound_character_id',  'BIGINT UNSIGNED NULL AFTER welcome_template_id' );
		$add_column( $tbl, 'bound_notebook_id',   'BIGINT UNSIGNED NULL AFTER bound_character_id' );

		// M6.W9 — conversations.character_id (so bridge can switch active character).
		$conv = self::tbl_conversations();
		$add_column( $conv, 'character_id', 'BIGINT UNSIGNED NULL AFTER notebook_id' );

		return $results;
	}

	/**
	 * Idempotent migration for PHASE 0.35 M-CRM.M8.W2 (Contact Unification + Woo link).
	 *
	 * Adds:
	 *   - contacts.{first_name,last_name,title,account_id,owner_id,tags_json,deleted_at}
	 *     + idx_account / idx_owner / idx_deleted
	 *   - crm_invoices.wc_order_id + idx_wc_order
	 *
	 * The new contact_id_map table is created by dbDelta() in install() —
	 * nothing to migrate here for it on existing installs (just ensure the
	 * file is present; install() runs dbDelta on every version bump).
	 */
	public static function migrate_phase_040(): array {
		global $wpdb;
		$results = array();

		$add_column = function ( $table, $col, $ddl ) use ( $wpdb, &$results ) {
			if ( ! self::column_exists( $table, $col ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$ddl}" );
				$results[] = sprintf( '+col %s.%s', $table, $col );
			}
		};
		$add_index = function ( $table, $idx, $ddl ) use ( $wpdb, &$results ) {
			if ( ! self::index_exists( $table, $idx ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD KEY {$idx} {$ddl}" );
				$results[] = sprintf( '+idx %s.%s', $table, $idx );
			}
		};

		// --- contacts (canonical SoT) ---
		$contacts = self::tbl_contacts();
		$add_column( $contacts, 'first_name', 'VARCHAR(95) NULL AFTER name' );
		$add_column( $contacts, 'last_name',  'VARCHAR(95) NULL AFTER first_name' );
		$add_column( $contacts, 'title',      'VARCHAR(120) NULL AFTER last_name' );
		$add_column( $contacts, 'account_id', 'BIGINT UNSIGNED NULL AFTER title' );
		$add_column( $contacts, 'owner_id',   'BIGINT UNSIGNED NULL AFTER wp_user_id' );
		$add_column( $contacts, 'tags_json',  'LONGTEXT NULL AFTER owner_id' );
		$add_column( $contacts, 'deleted_at', 'DATETIME NULL AFTER updated_at' );
		$add_index(  $contacts, 'idx_account', '(account_id)' );
		$add_index(  $contacts, 'idx_owner',   '(owner_id)' );
		$add_index(  $contacts, 'idx_deleted', '(deleted_at)' );

		// --- crm_invoices.wc_order_id (Woo link) ---
		$invoices = self::tbl_crm_invoices();
		$add_column( $invoices, 'wc_order_id', 'BIGINT UNSIGNED NULL AFTER custom_json' );
		$add_index(  $invoices, 'idx_wc_order', '(wc_order_id)' );

		return $results;
	}

	/**
	 * Idempotent migration for PHASE 0.35 M6.W10 — Campaign Scenario Builder.
	 *
	 * Adds 10 columns + 2 indexes to `bizcity_crm_campaigns` for the
	 * scenario-builder UX ported from `wp_bizgpt_custom_flows`. All ALTERs are
	 * guarded by SHOW COLUMNS / SHOW INDEX so re-runs are no-ops. Defaults are
	 * picked so existing rows behave as plain "send_message + no reminder" —
	 * no eager backfill needed.
	 *
	 * Spec: PHASE-0.35-CRM-CAMPAIGN.md §2 + §13.
	 *
	 * @return array<string,string> Map of operation => result.
	 */
	public static function migrate_phase_041(): array {
		global $wpdb;
		$results = array();
		$tbl     = self::tbl_campaigns();

		// Sanity — base table must exist (M6.W1 ran). Otherwise dbDelta in install()
		// will pick up the new cols on first run; this method is upgrade-only.
		if ( ! self::table_exists( $tbl ) ) {
			$results['precheck'] = 'skip:base_table_missing';
			return $results;
		}

		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb, &$results ): void {
			$key = "col:{$table}.{$column}";
			if ( self::column_exists( $table, $column ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb, &$results ): void {
			$key = "idx:{$table}.{$index}";
			if ( self::index_exists( $table, $index ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		// 10 scenario / reminder / import columns. Place AFTER bound_notebook_id so
		// SHOW CREATE TABLE reads naturally; MariaDB ignores AFTER if col exists.
		$add_column( $tbl, 'scenario_action_type',         "VARCHAR(20) NOT NULL DEFAULT 'send_message' AFTER bound_notebook_id" );
		$add_column( $tbl, 'scenario_shortcode',           'TEXT NULL AFTER scenario_action_type' );
		$add_column( $tbl, 'scenario_template',            'TEXT NULL AFTER scenario_shortcode' );
		$add_column( $tbl, 'scenario_attrs_json',          'LONGTEXT NULL AFTER scenario_template' );
		$add_column( $tbl, 'scenario_prompt',              'TEXT NULL AFTER scenario_attrs_json' );
		$add_column( $tbl, 'reminder_delay',               'INT NOT NULL DEFAULT 0 AFTER scenario_prompt' );
		$add_column( $tbl, 'reminder_unit',                "VARCHAR(10) NOT NULL DEFAULT 'minutes' AFTER reminder_delay" );
		$add_column( $tbl, 'reminder_text',                'TEXT NULL AFTER reminder_unit' );
		$add_column( $tbl, 'reminder_only',                'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_text' );
		$add_column( $tbl, 'imported_from_bizgpt_flow_id', 'BIGINT UNSIGNED NULL AFTER reminder_only' );

		$add_index( $tbl, 'idx_imported_flow', '(imported_from_bizgpt_flow_id)' );
		$add_index( $tbl, 'idx_action_type',   '(scenario_action_type)' );

		// M6.W13.5 — seed default automation rule that wires
		// crm_campaign_visit_recorded → dispatch_campaign_scenario. Idempotent.
		if ( class_exists( 'BizCity_CRM_Campaign_Scenario_Dispatcher' ) ) {
			$seeded_id = BizCity_CRM_Campaign_Scenario_Dispatcher::seed_default_rule();
			$results['seed:campaign_dispatch_rule'] = $seeded_id > 0 ? ( 'ok:#' . $seeded_id ) : 'fail';
		} else {
			$results['seed:campaign_dispatch_rule'] = 'skip:dispatcher_class_missing';
		}

		return $results;
	}

	/**
	 * Idempotent migration for v1.16.0 — Customer Source adapter columns.
	 *
	 * Adds idempotent upsert keys so BizCity_CRM_Pipeline_Sync can write
	 * once-per-(source, source_ref) without race conditions:
	 *   - opportunities.{source, source_ref, source_synced_at} + UNIQUE(source, source_ref)
	 *   - leads.{source_ref} + UNIQUE(source, source_ref)
	 *
	 * ADD-only; safe to run on every install() call. UNIQUE index creation
	 * uses ALTER TABLE ADD UNIQUE so existing duplicate NULLs survive
	 * (MySQL/MariaDB treat multiple NULLs as distinct under UNIQUE).
	 */
	public static function migrate_phase_042(): array {
		global $wpdb;
		$results = array();

		$add_column = static function ( string $table, string $column, string $ddl ) use ( $wpdb, &$results ): void {
			$key = "col:{$table}.{$column}";
			if ( self::column_exists( $table, $column ) ) { $results[ $key ] = 'skip'; return; }
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$ddl}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};
		$add_unique = static function ( string $table, string $index, string $cols ) use ( $wpdb, &$results ): void {
			$key = "idx:{$table}.{$index}";
			if ( self::index_exists( $table, $index ) ) { $results[ $key ] = 'skip'; return; }
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY {$index} {$cols}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$opps = self::tbl_crm_opportunities();
		if ( self::table_exists( $opps ) ) {
			$add_column( $opps, 'source',           'VARCHAR(64) NULL AFTER custom_json' );
			$add_column( $opps, 'source_ref',       'VARCHAR(190) NULL AFTER source' );
			$add_column( $opps, 'source_synced_at', 'DATETIME NULL AFTER source_ref' );
			$add_unique( $opps, 'uniq_source_ref', '(source, source_ref)' );
		}

		$leads = self::tbl_crm_leads();
		if ( self::table_exists( $leads ) ) {
			$add_column( $leads, 'source_ref', 'VARCHAR(190) NULL AFTER source' );
			$add_unique( $leads, 'uniq_source_ref', '(source, source_ref)' );
		}

		return $results;
	}

	/**
	 * 2026-05-28 v1.17.0 — Audit log table for M-CRM.M1.W3.
	 * Uses dbDelta — safe to run on existing installs (table already exists = no-op).
	 */
	public static function migrate_phase_043(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset   = $wpdb->get_charset_collate();
		$audit_log = self::tbl_crm_audit_log();
		dbDelta( "CREATE TABLE `{$audit_log}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(64) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(32) NOT NULL DEFAULT 'updated',
			before_json LONGTEXT NULL,
			after_json LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			user_label VARCHAR(190) NULL,
			event_uuid VARCHAR(36) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_user (user_id),
			KEY idx_created_at (created_at),
			KEY idx_event_uuid (event_uuid)
		) {$charset};" );
	}

	/**
	 * 2026-05-28 v1.18.0 — Broadcasts + per-recipient ledger + lead classification.
	 * Uses dbDelta for new tables; guarded ADD COLUMN for contacts classification.
	 */
	public static function migrate_phase_044(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// 1) Broadcast job header table.
		$broadcasts = self::tbl_broadcasts();
		dbDelta( "CREATE TABLE `{$broadcasts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			inbox_ids_json LONGTEXT NULL,
			segment_filter_json LONGTEXT NULL,
			message_template LONGTEXT NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'draft',
			scheduled_at DATETIME NULL,
			sent_at DATETIME NULL,
			total_count INT UNSIGNED NOT NULL DEFAULT 0,
			sent_count INT UNSIGNED NOT NULL DEFAULT 0,
			failed_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_scheduled (scheduled_at),
			KEY idx_created_by (created_by)
		) {$charset};" );

		// 2) Broadcast recipients ledger.
		$recipients = self::tbl_broadcast_recipients();
		dbDelta( "CREATE TABLE `{$recipients}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			broadcast_id BIGINT UNSIGNED NOT NULL,
			contact_id BIGINT UNSIGNED NOT NULL,
			conversation_id BIGINT UNSIGNED NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'queued',
			sent_at DATETIME NULL,
			error VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_bc (broadcast_id, contact_id),
			KEY idx_broadcast (broadcast_id, status),
			KEY idx_contact (contact_id)
		) {$charset};" );

		// 3) Add lead_score + segment to contacts table (idempotent ADD COLUMN).
		$contacts = self::tbl_contacts();
		if ( self::table_exists( $contacts ) ) {
			if ( ! self::column_exists( $contacts, 'lead_score' ) ) {
				$wpdb->query( "ALTER TABLE `{$contacts}` ADD COLUMN lead_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER points_balance_cache" );
			}
			if ( ! self::column_exists( $contacts, 'segment' ) ) {
				$wpdb->query( "ALTER TABLE `{$contacts}` ADD COLUMN segment VARCHAR(32) NOT NULL DEFAULT '' AFTER lead_score" );
			}
		}
	}

	/**
	 * Helper — check table existence.
	 */
	

	/**
	 * Helper — check column existence (case-insensitive).
	 */
	public static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
		return (bool) $row;
	}

	/**
	 * Helper — check index existence on a table.
	 */
	public static function index_exists( string $table, string $index ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ) );
		return (bool) $row;
	}

	/**
	 * 2026-06-07 v1.20.0 — Phase 0.38 Order Fulfillment Hub: 3 new tables.
	 * ADD-only via dbDelta. No DROP/MODIFY. R-DCL compliant (modules.twin-crm.json v1.20.0).
	 * [2026-06-07 Johnny Chu] PHASE-0.38.W1.3 — create recap_log + order_csat + shipment_status_log
	 */
	public static function migrate_phase_045(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// 1) Order recap send audit log.
		$recap_log = self::tbl_order_recap_log();
		dbDelta( "CREATE TABLE `{$recap_log}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			recap_type VARCHAR(32) NOT NULL DEFAULT 'new_order',
			platform VARCHAR(32) NOT NULL DEFAULT '',
			chat_id VARCHAR(190) NOT NULL DEFAULT '',
			gateway_msg_id VARCHAR(190) NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'sent',
			error VARCHAR(512) NULL,
			sent_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_order_type (order_id, recap_type),
			KEY idx_sent (sent_at),
			KEY idx_status (status)
		) {$charset};" );

		// 2) Customer satisfaction feedback from public tracking page.
		$order_csat = self::tbl_order_csat();
		dbDelta( "CREATE TABLE `{$order_csat}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			contact_id BIGINT UNSIGNED NULL,
			rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
			comment VARCHAR(512) NULL,
			source VARCHAR(32) NOT NULL DEFAULT 'public_tracking',
			ip VARCHAR(45) NULL,
			submitted_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_order (order_id),
			KEY idx_rating (rating),
			KEY idx_submitted (submitted_at)
		) {$charset};" );

		// 3) Shipping status change log (detected by cron tracker, feed timeline).
		$shipment_log = self::tbl_shipment_status_log();
		dbDelta( "CREATE TABLE `{$shipment_log}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			tracking_number VARCHAR(100) NULL,
			provider VARCHAR(32) NULL,
			old_status VARCHAR(64) NULL,
			new_status VARCHAR(64) NOT NULL DEFAULT '',
			raw_payload LONGTEXT NULL,
			changed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_order (order_id, changed_at),
			KEY idx_tracking (tracking_number)
		) {$charset};" );
	}

	// [2026-06-07 Johnny Chu] PHASE-0.40.G1 — Deplao Parity: variants_json + checklist_json + crm_notes_doc (v1.21.0).
	public static function migrate_phase_046(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// 1) campaigns.variants_json — ADD-only guard.
		$tbl_campaigns = self::tbl_campaigns();
		if ( self::table_exists( $tbl_campaigns ) && ! self::column_exists( $tbl_campaigns, 'variants_json' ) ) {
			$wpdb->query( "ALTER TABLE `{$tbl_campaigns}` ADD COLUMN variants_json LONGTEXT NULL" );
		}

		// 2) crm_tasks.checklist_json — ADD-only guard.
		$tbl_tasks = self::tbl_crm_tasks();
		if ( self::table_exists( $tbl_tasks ) && ! self::column_exists( $tbl_tasks, 'checklist_json' ) ) {
			$wpdb->query( "ALTER TABLE `{$tbl_tasks}` ADD COLUMN checklist_json LONGTEXT NULL" );
		}

		// 3) crm_notes_doc — new table (Deplao ERP Note parity).
		$tbl_notes = self::tbl_notes_doc();
		dbDelta( "CREATE TABLE `{$tbl_notes}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			folder VARCHAR(120) NOT NULL DEFAULT 'General',
			title VARCHAR(255) NOT NULL DEFAULT '',
			content LONGTEXT NULL,
			tags_json LONGTEXT NULL,
			share_scope VARCHAR(16) NOT NULL DEFAULT 'private',
			owner_id BIGINT UNSIGNED NULL,
			pinned TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_owner_folder (owner_id, folder),
			KEY idx_share (share_scope)
		) {$charset};" );
	}

	// [2026-06-07 Johnny Chu] PHASE-3.5-WC — Wave C: NEW bizcity_crm_admin_chat_audit (v1.22.0).
	public static function migrate_phase_047(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'bizcity_crm_admin_chat_audit';
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			chat_id VARCHAR(255) NOT NULL DEFAULT '',
			guru_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			grant_id BIGINT UNSIGNED NULL,
			action VARCHAR(80) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'attempted',
			input_json LONGTEXT NULL,
			result_json LONGTEXT NULL,
			ip VARCHAR(45) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_created (user_id, created_at),
			KEY idx_chat_created (chat_id(191), created_at),
			KEY idx_action_status (action, status),
			KEY idx_guru (guru_id)
		) {$charset};" );
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-0.43 M0 — ADD action_flags_json + delay_sec to broadcasts;
	 * ADD scheduled_send_at + idx_scheduled_send to broadcast_recipients. ADD-only, idempotent.
	 */
	public static function migrate_phase_048(): void {
		global $wpdb;
		// bizcity_crm_broadcasts — add action_flags_json
		$bc = self::tbl_broadcasts();
		$existing = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM `%1s`', $bc ), 0 ); // phpcs:ignore
		$existing = $wpdb->get_results( "SHOW COLUMNS FROM `{$bc}`", ARRAY_A );
		$cols     = array_column( $existing, 'Field' );
		if ( ! in_array( 'action_flags_json', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$bc}` ADD COLUMN `action_flags_json` LONGTEXT NULL" );
		}
		if ( ! in_array( 'delay_sec', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$bc}` ADD COLUMN `delay_sec` SMALLINT UNSIGNED NOT NULL DEFAULT 5" );
		}
		// bizcity_crm_broadcast_recipients — add scheduled_send_at
		$rcp = self::tbl_broadcast_recipients();
		$rcols = $wpdb->get_results( "SHOW COLUMNS FROM `{$rcp}`", ARRAY_A );
		$rcols = array_column( $rcols, 'Field' );
		if ( ! in_array( 'scheduled_send_at', $rcols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$rcp}` ADD COLUMN `scheduled_send_at` DATETIME NULL" );
		}
		// Add index idx_scheduled_send if absent
		$indexes = $wpdb->get_col( "SHOW INDEX FROM `{$rcp}` WHERE Key_name = 'idx_scheduled_send'", 0 );
		if ( empty( $indexes ) ) {
			$wpdb->query( "ALTER TABLE `{$rcp}` ADD INDEX `idx_scheduled_send` (`status`, `scheduled_send_at`)" );
		}
	}

	/**
	 * Diagnostic helper — return missing tables (empty array == all good).
	 *
	 * [2026-07-05 Johnny Chu] R-SHOW-TABLES — reuse table_exists() (information_schema + dual-cache).
	 */
	public static function missing_tables(): array {
		$missing = array();
		foreach ( self::all_tables() as $key => $tbl ) {
			if ( ! self::table_exists( $tbl ) ) {
				$missing[ $key ] = $tbl;
			}
		}
		return $missing;
	}

	/**
	 * [2026-07-03 Johnny Chu] PHASE-0.46 M1 — v1.24.0
	 * Create bizcity_crm_activities + bizcity_crm_submissions tables if missing.
	 * Idempotent: uses dbDelta (CREATE TABLE IF NOT EXISTS equivalent).
	 */
	public static function migrate_phase_049(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// bizcity_crm_activities — entity-typed activity log for any CRM entity
		$act = self::tbl_crm_activities();
		dbDelta( "CREATE TABLE `{$act}` (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type      VARCHAR(32)     NOT NULL,
			entity_id        BIGINT UNSIGNED NOT NULL,
			type             VARCHAR(32)     NOT NULL DEFAULT 'note',
			title            VARCHAR(255)    NOT NULL DEFAULT '',
			body             LONGTEXT        NULL,
			user_id          BIGINT UNSIGNED NULL,
			user_label       VARCHAR(190)    NULL,
			call_date             DATETIME        NULL,
			call_agent_wp_user_id BIGINT UNSIGNED NULL,
			call_agent_label      VARCHAR(190)    NULL,
			created_at       DATETIME        NOT NULL,
			deleted_at       DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_created (created_at),
			KEY idx_deleted (deleted_at)
		) {$charset};" );

		// bizcity_crm_submissions — unified submission pipeline
		$sub = self::tbl_crm_submissions();
		dbDelta( "CREATE TABLE `{$sub}` (
			id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type             VARCHAR(32)     NOT NULL DEFAULT 'cf7',
			source_ref_id           BIGINT UNSIGNED NOT NULL,
			contact_name            VARCHAR(255)    NULL,
			contact_phone           VARCHAR(32)     NULL,
			contact_email           VARCHAR(190)    NULL,
			follow_status           VARCHAR(32)     NOT NULL DEFAULT 'new',
			assigned_to_wp_user_id  BIGINT UNSIGNED NULL,
			assigned_by_wp_user_id  BIGINT UNSIGNED NULL,
			assigned_at             DATETIME        NULL,
			source_meta_json        LONGTEXT        NULL,
			pipeline_opp_id         BIGINT UNSIGNED NULL,
			created_at              DATETIME        NOT NULL,
			updated_at              DATETIME        NOT NULL,
			deleted_at              DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY idx_source (source_type, source_ref_id),
			KEY idx_follow (follow_status),
			KEY idx_assignee (assigned_to_wp_user_id),
			KEY idx_deleted (deleted_at)
		) {$charset};" );

		// Invalidate bizcity_tbl_exists static + wp_cache for both tables
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $act );
			bizcity_tbl_invalidate( $sub );
		}
	}
}

endif; // class_exists BizCity_CRM_DB_Installer_V2

// Backward-compat alias — code cũ vẫn gọi BizCity_CRM_DB_Installer.
if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) && ! class_exists( 'BizCity_CRM_DB_Installer', false ) ) {
	class_alias( 'BizCity_CRM_DB_Installer_V2', 'BizCity_CRM_DB_Installer' );
}
