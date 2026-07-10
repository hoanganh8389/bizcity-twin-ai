<?php
/**
 * BizCity CRM — Customer Source Adapter contract.
 *
 * A "Customer Source" feeds the Sales Pipeline Kanban with raw prospects from
 * any data origin. Each implementation yields a flat list of normalized
 * customer records via fetch_recent(); the BizCity_CRM_Pipeline_Sync service
 * is the only consumer — it upserts an opportunity per (code, external_ref).
 *
 * Built-in sources (registered in bootstrap.php via filter
 * `bizcity_crm_register_customer_sources`):
 *   - messenger      → Facebook inbox conversations (bizcity_crm_contact_inboxes)
 *   - dino_tichdiem  → wp_dino_users (bizgpt-dino-tichdiem)
 *   - user_points    → wp_user_points (user-points)
 *
 * 3rd-party plugins may add more by hooking the same filter and returning
 * any object implementing this interface.
 *
 * @package BizCity_Twin_CRM
 * @since   1.16.0
 */

defined( 'ABSPATH' ) || exit;

interface BizCity_CRM_Customer_Source {

	/**
	 * Stable machine code, used as `opportunities.source` value and registry key.
	 * Must match /^[a-z][a-z0-9_]{1,31}$/.
	 */
	public function code(): string;

	/** Human label for diagnostics / admin UI. */
	public function label(): string;

	/**
	 * Yield up to $limit customer rows. Implementations MUST be cheap (LIMIT
	 * + index) — sync runs both on cron and on inbound webhook fan-out.
	 *
	 * @param int|null $since_ts Unix ts; if not null, only return rows updated
	 *                           since this moment (best-effort — implementations
	 *                           may return more if they cannot filter precisely).
	 * @param int      $limit    Max rows.
	 *
	 * @return array<int, array{
	 *     external_ref:        string,        // REQUIRED. Stable PK inside source (PSID, phone, dino phone_number, user_points.id…).
	 *     name:                string,        // Display name; falls back to "Khách " . substr(external_ref,-4).
	 *     phone:               string|null,   // Normalized phone if known.
	 *     email:               string|null,
	 *     channel:             string|null,   // Optional human channel hint (e.g. 'facebook', 'loyalty').
	 *     has_phone:           bool,          // Convenience flag — drives prospecting→qualification promotion.
	 *     last_activity_at:    string|null,   // MySQL DATETIME (UTC) for ordering / since filter.
	 *     meta:                array          // Free-form, persisted JSON-encoded into opportunities.custom_json.source_meta.
	 * }>
	 */
	public function fetch_recent( ?int $since_ts, int $limit ): array;
}
