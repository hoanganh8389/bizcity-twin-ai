<?php
/**
 * BizCity Diagnostics — core.crm.g567 probe (PHASE-0.40 G5.5 + G6.5 + G7.4).
 *
 * 3 DDV rows còn thiếu sau G0-UI thru G4:
 *
 * G5.5 — AI Suggest (dry_run):
 *   Kiểm tra POST /bizcity-crm/v1/conversations/{id}/ai-reply có `dispatch` param (=false → draft mode).
 *   FE: Composer.jsx có triggerAiSuggest() với dry_run/dispatch=false.
 *
 * G6.5 — ERP Suite (tasks + notes_doc):
 *   Kiểm tra task status transition REST (PATCH /tasks/{id}) + notes_doc CRUD routes.
 *   FE: TasksTab.jsx 5-column Kanban + DocumentsTab.jsx NotesDocPanel.
 *
 * G7.4 — Integrations UI (channels tab) + Discord action placeholder:
 *   FE: ChannelsTab.jsx có Discord entry.
 *   BE: class-action-notify-discord.php placeholder OR discord mention in ChannelsTab.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-13 (PHASE-0.40 G5.5+G6.5+G7.4 / R-DDV)
 */

// [2026-06-13 Johnny Chu] PHASE-0.40 G5.5+G6.5+G7.4 — DDV probe CRM ERP+AI+Integrations gaps
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_CRM_G567', false ) ) {
	return;
}

final class BizCity_Probe_CRM_G567 implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.crm.g567'; }
	public function label(): string       { return 'CRM G5/G6/G7: AI Suggest (dry_run) + ERP Suite + Integrations UI'; }
	public function description(): string {
		return '9 Disk layers covering G5.5 (ai-reply dispatch param + Composer suggest), G6.5 (task PATCH + notes_doc CRUD + TasksTab Kanban + DocumentsTab Notes), G7.4 (ChannelsTab integrations + Discord placeholder) — PHASE-0.40 R-DDV.';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 45; }
	public function icon(): string        { return 'layers'; }
	public function estimate_ms(): int    { return 120; }

	public function precondition() {
		return true;
	}

	// [2026-06-14 Johnny Chu] HOTFIX — add missing $ctx param to match BizCity_Diagnostics_Probe::run($ctx):array
	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$crm_dir = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-twin-crm/';
		$rest    = $crm_dir . 'includes/class-rest-controller.php';
		$fe_dir  = $crm_dir . 'frontend/src/';

		$rest_text = file_exists( $rest ) ? file_get_contents( $rest ) : '';
		$repo      = $crm_dir . 'includes/class-repository.php';
		$repo_text = file_exists( $repo ) ? file_get_contents( $repo ) : '';

		// ── G5.5: AI Suggest dry_run ──────────────────────────────────────────
		$has_dispatch = file_exists( $rest ) && ( false !== strpos( $rest_text, "'dispatch'" ) );
		$steps[] = array(
			'id' => 'G5.be.ai_dispatch_param', 'label' => 'G5.5 Disk: ai-reply route has `dispatch` param (false=dry_run)',
			'pass' => $has_dispatch,
			'detail' => $has_dispatch ? 'OK — dispatch param in ai-reply route → dry_run supported' : 'MISSING dispatch param in /ai-reply route',
		);
		if ( ! $has_dispatch ) { $pass = false; }

		$composer = $fe_dir . 'components/Composer.jsx';
		$comp_text = file_exists( $composer ) ? file_get_contents( $composer ) : '';
		$has_suggest_btn = file_exists( $composer ) && ( false !== strpos( $comp_text, 'triggerAiSuggest' ) );
		$steps[] = array(
			'id' => 'G5.fe.suggest_button', 'label' => 'G5.5 Disk: Composer.jsx has triggerAiSuggest() (💡 Gợi ý)',
			'pass' => $has_suggest_btn,
			'detail' => $has_suggest_btn ? 'OK — triggerAiSuggest() + "💡 Gợi ý" button in Composer.jsx' : 'MISSING — add AI suggest button to Composer.jsx',
		);
		if ( ! $has_suggest_btn ) { $pass = false; }

		// ── G6.5: ERP Suite ──────────────────────────────────────────────────
		$has_task_patch = file_exists( $rest ) && ( false !== strpos( $rest_text, '/crm-tasks/' ) && false !== strpos( $rest_text, 'EDITABLE' ) );
		$steps[] = array(
			'id' => 'G6.be.task_patch', 'label' => 'G6.5 Disk: /crm-tasks/{id} EDITABLE route (status transition)',
			'pass' => $has_task_patch,
			'detail' => $has_task_patch ? 'OK — /crm-tasks/{id} EDITABLE route present' : 'MISSING — add EDITABLE /crm-tasks/{id} to REST controller',
		);
		if ( ! $has_task_patch ) { $pass = false; }

		$has_notes_doc = file_exists( $rest ) && ( false !== strpos( $rest_text, '/crm-notes-doc' ) );
		$steps[] = array(
			'id' => 'G6.be.notes_doc_route', 'label' => 'G6.5 Disk: /crm-notes-doc REST route (CRUD)',
			'pass' => $has_notes_doc,
			'detail' => $has_notes_doc ? 'OK — /crm-notes-doc + /crm-notes-doc/{id} routes present' : 'MISSING — add crm-notes-doc REST routes',
		);
		if ( ! $has_notes_doc ) { $pass = false; }

		$tasks_fe = $fe_dir . 'routes/tasks/TasksTab.jsx';
		$tasks_ok = file_exists( $tasks_fe ) && ( false !== strpos( file_get_contents( $tasks_fe ), 'G6.2' ) );
		$steps[] = array(
			'id' => 'G6.fe.tasks_kanban', 'label' => 'G6.5 Disk: TasksTab.jsx Kanban 5-column',
			'pass' => $tasks_ok,
			'detail' => $tasks_ok ? 'OK — TasksTab.jsx 5-column Kanban (G6.2)' : ( file_exists( $tasks_fe ) ? 'TasksTab.jsx exists but G6.2 stamp missing' : 'MISSING TasksTab.jsx' ),
		);
		if ( ! $tasks_ok ) { $pass = false; }

		$docs_fe = $fe_dir . 'routes/documents/DocumentsTab.jsx';
		$docs_ok = file_exists( $docs_fe ) && ( false !== strpos( file_get_contents( $docs_fe ), 'crm_notes_doc' ) );
		$steps[] = array(
			'id' => 'G6.fe.notes_doc_panel', 'label' => 'G6.5 Disk: DocumentsTab.jsx NotesDocPanel (crm_notes_doc)',
			'pass' => $docs_ok,
			'detail' => $docs_ok ? 'OK — NotesDocPanel in DocumentsTab.jsx' : ( file_exists( $docs_fe ) ? 'DocumentsTab.jsx exists but notes_doc reference missing' : 'MISSING DocumentsTab.jsx' ),
		);
		if ( ! $docs_ok ) { $pass = false; }

		// ── G7.4: Integrations UI ────────────────────────────────────────────
		// Discord was removed from scope (2026-06-13). Only check that ChannelsTab exists.
		$channels_fe = $fe_dir . 'routes/channels/ChannelsTab.jsx';
		$has_channels = file_exists( $channels_fe );
		$steps[] = array(
			'id' => 'G7.fe.channels_tab', 'label' => 'G7.4 Disk: ChannelsTab.jsx integrations page',
			'pass' => $has_channels,
			'detail' => $has_channels ? 'OK — ChannelsTab.jsx integrations status page present' : 'MISSING — add ChannelsTab.jsx',
		);
		if ( ! $has_channels ) { $pass = false; }

		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — static DDV for Inbox triage controls.
		$h2_routes = file_exists( $rest )
			&& false !== strpos( $rest_text, '/conversations/(?P<id>\\d+)/assignee' )
			&& false !== strpos( $rest_text, '/conversations/(?P<id>\\d+)/priority' )
			&& false !== strpos( $rest_text, '/conversations/(?P<id>\\d+)/reopen' );
		$steps[] = array(
			'id' => 'H2.be.triage_routes', 'label' => 'H2 Disk: conversation assign/priority/reopen routes',
			'pass' => $h2_routes,
			'detail' => $h2_routes ? 'OK — assignee, priority and reopen routes present' : 'MISSING — H2 triage route coverage incomplete',
		);
		if ( ! $h2_routes ) { $pass = false; }

		$h2_repository = file_exists( $repo )
			&& false !== strpos( $repo_text, 'set_conversation_assignee' )
			&& false !== strpos( $repo_text, 'set_conversation_priority' )
			&& false !== strpos( $repo_text, "crm_status_changed" );
		$steps[] = array(
			'id' => 'H2.be.triage_repository', 'label' => 'H2 Disk: repository write gate + status event',
			'pass' => $h2_repository,
			'detail' => $h2_repository ? 'OK — assignment/priority write gates and status event present' : 'MISSING — H2 repository mutation contract incomplete',
		);
		if ( ! $h2_repository ) { $pass = false; }

		$detail_fe = $fe_dir . 'components/ConversationDetail.jsx';
		$list_fe   = $fe_dir . 'components/ConversationList.jsx';
		$detail_text = file_exists( $detail_fe ) ? file_get_contents( $detail_fe ) : '';
		$list_text   = file_exists( $list_fe ) ? file_get_contents( $list_fe ) : '';
		$h2_frontend = file_exists( $detail_fe ) && file_exists( $list_fe )
			&& false !== strpos( $detail_text, 'useAssignConversationMutation' )
			&& false !== strpos( $detail_text, 'useReopenConversationMutation' )
			&& false !== strpos( $list_text, "value: 3, label: 'Khẩn'" );
		$steps[] = array(
			'id' => 'H2.fe.triage_controls', 'label' => 'H2 Disk: Inbox assign/priority/reopen controls',
			'pass' => $h2_frontend,
			'detail' => $h2_frontend ? 'OK — active Inbox components expose H2 controls' : 'MISSING — active Inbox H2 controls incomplete',
		);
		if ( ! $h2_frontend ) { $pass = false; }

		// [2026-08-04 Johnny Chu] PHASE-0.48-H4 — static DDV for Customer 360 projections.
		$drawer = $fe_dir . 'components/ContactDrawer.jsx';
		$api    = $fe_dir . 'redux/api/crmApi.js';
		$drawer_text = file_exists( $drawer ) ? file_get_contents( $drawer ) : '';
		$api_text    = file_exists( $api ) ? file_get_contents( $api ) : '';
		$h4_projection = file_exists( $drawer )
			&& false !== strpos( $drawer_text, 'useGetCrmTasksQuery' )
			&& false !== strpos( $drawer_text, 'useGetCrmDocumentsQuery' )
			&& false !== strpos( $drawer_text, 'useGetCrmLeadsQuery' )
			&& false !== strpos( $drawer_text, 'useGetCrmOpportunitiesQuery' )
			&& false !== strpos( $api_text, 'related_entity_type' )
			&& false !== strpos( $api_text, "params.set( 'contact_id', args.contact_id )" );
		$steps[] = array(
			'id' => 'H4.fe.customer360_projection', 'label' => 'H4 Disk: Customer 360 tasks/documents/pipeline projections',
			'pass' => $h4_projection,
			'detail' => $h4_projection ? 'OK — ContactDrawer consumes canonical scoped RTK queries' : 'MISSING — H4 Customer 360 projection wiring incomplete',
		);
		if ( ! $h4_projection ) { $pass = false; }

		$h4_opportunity_filter = file_exists( $rest ) && false !== strpos( $rest_text, "'contact_id' => array( 'type' => 'integer' )" );
		$steps[] = array(
			'id' => 'H4.be.opportunity_contact_filter', 'label' => 'H4 Disk: opportunity contact_id filter',
			'pass' => $h4_opportunity_filter,
			'detail' => $h4_opportunity_filter ? 'OK — opportunity list accepts contact_id scope' : 'MISSING — opportunity contact_id filter',
		);
		if ( ! $h4_opportunity_filter ) { $pass = false; }

		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — static DDV for bulk triage selection UI.
		$toolbar = $fe_dir . 'components/SelectionToolbar.jsx';
		$bulk_ui = file_exists( $toolbar ) && file_exists( $list_fe )
			&& false !== strpos( file_get_contents( $toolbar ), 'useBulkLabelConversationsMutation' )
			&& false !== strpos( file_get_contents( $toolbar ), 'Promise.allSettled' )
			&& false !== strpos( $list_text, 'selectedIds' );
		$steps[] = array(
			'id' => 'H2.fe.bulk_triage', 'label' => 'H2 Disk: bulk triage selection toolbar',
			'pass' => $bulk_ui,
			'detail' => $bulk_ui ? 'OK — selection state + bulk label/status/assignment controls present' : 'MISSING — bulk triage UI incomplete',
		);
		if ( ! $bulk_ui ) { $pass = false; }

		// [2026-08-04 Johnny Chu] PHASE-0.48-H6 — static DDV for safe CSV exports.
		$export_class = $crm_dir . 'includes/class-crm-export.php';
		$contacts_fe  = $fe_dir . 'routes/contacts/ContactsTab.jsx';
		$export_ui = file_exists( $list_fe ) && file_exists( $contacts_fe )
			&& false !== strpos( $list_text, 'conversations/export' )
			&& false !== strpos( file_get_contents( $contacts_fe ), 'crm-contacts/export' );
		$export_be = file_exists( $export_class ) && file_exists( $rest )
			&& false !== strpos( file_get_contents( $export_class ), 'MAX_ROWS' )
			&& false !== strpos( $rest_text, "'/conversations/export'" )
			&& false !== strpos( $rest_text, "'/crm-contacts/export'" );
		$steps[] = array(
			'id' => 'H6.be.csv_export', 'label' => 'H6 Disk: bounded whitelist CSV helper + CRM export routes',
			'pass' => $export_be,
			'detail' => $export_be ? 'OK — bounded helper and conversations/contacts export routes present' : 'MISSING — H6 backend export contract incomplete',
		);
		if ( ! $export_be ) { $pass = false; }
		$steps[] = array(
			'id' => 'H6.fe.csv_export', 'label' => 'H6 Disk: Inbox + Contacts CSV download actions',
			'pass' => $export_ui,
			'detail' => $export_ui ? 'OK — active Inbox and Contacts surfaces call server exports' : 'MISSING — H6 frontend export actions incomplete',
		);
		if ( ! $export_ui ) { $pass = false; }

		return array( 'pass' => $pass, 'steps' => $steps );
	}

	// [2026-06-14 Johnny Chu] HOTFIX — required by BizCity_Diagnostics_Probe interface
	public function cleanup(): void {}
}

// Self-register through the standard filter.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_CRM_G567();
	return $list;
} );
