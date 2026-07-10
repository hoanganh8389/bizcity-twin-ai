<?php
/**
 * BizCity CRM — Guru-on-Duty Resolver
 *
 * Translates an inbox (channel_type, channel_ref_id) into:
 *   1. character_id   ← `_bizcity_channel_bindings` (PHASE 0.31 binding table)
 *   2. notebook_ids[] ← `bizcity_notebook_character_attachments` JOIN
 *                        `bizcity_characters` ON guru_uuid
 *
 * Used by AI Replier + Auto-Reply Listener so the conversation auto-reply
 * is grounded in the notebooks the **Twin Guru on Duty** has attached
 * (per character-edit screen "Notebooks" tab), not just the inbox-level
 * default_notebook_id fallback.
 *
 * Returns trace-friendly arrays for inclusion in `ai_metadata.steps[]`.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Guru_Resolver {

	/**
	 * Resolve full Guru-on-Duty context for a CRM inbox row.
	 *
	 * @param array $inbox  CRM inbox row (with channel_type + channel_ref_id).
	 * @return array {
	 *   character_id : int  (0 if no binding),
	 *   guru_uuid    : string,
	 *   notebooks    : int[] (attached notebook ids; empty when none),
	 *   binding_mode : string ('auto'|'hybrid'|'manual'|''),
	 *   trace        : array (ready to push into ai_metadata.steps[].detail),
	 * }
	 */
	public static function resolve_for_inbox( array $inbox ): array {
		$out = array(
			'character_id' => 0,
			'guru_uuid'    => '',
			'notebooks'    => array(),
			'binding_mode' => '',
			'trace'        => array(
				'platform'        => (string) ( $inbox['channel_type']   ?? '' ),
				'account_id'      => (string) ( $inbox['channel_ref_id'] ?? '' ),
				'binding_found'   => false,
				'attachments_qry' => '',
			),
		);

		$platform   = strtoupper( (string) ( $inbox['channel_type']   ?? '' ) );
		$account_id = (string) ( $inbox['channel_ref_id'] ?? '' );
		if ( $platform === '' || $account_id === '' ) {
			return $out;
		}
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return $out;
		}

		global $wpdb;
		$bind_tbl = BizCity_Channel_Binding::table();

		// [2026-06-29 Johnny Chu] HOTFIX — CRM inbox stores channel_type='facebook' → UPPER='FACEBOOK'
		// but Channel Gateway binding is saved as 'FB_MESS' (FacebookPages.jsx PLATFORM const).
		// Build a search list so both values hit in one query.
		$platform_aliases = array( $platform );
		if ( $platform === 'FACEBOOK' ) {
			$platform_aliases[] = 'FB_MESS';
		} elseif ( $platform === 'FB_MESS' ) {
			$platform_aliases[] = 'FACEBOOK';
		} elseif ( $platform === 'ZALO_OA' ) {
			$platform_aliases[] = 'ZALO_BOT';
		}
		$placeholders = implode( ',', array_fill( 0, count( $platform_aliases ), '%s' ) );

		// Step 1 — bind (platform, account_id) → character_id.
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			"SELECT character_id, mode FROM {$bind_tbl}
			  WHERE UPPER(platform) IN ({$placeholders})
			    AND ( account_id=%s OR account_id='*' )
			    AND status=1 AND character_id > 0
			  ORDER BY ( account_id=%s ) DESC, id DESC
			  LIMIT 1",
			...array_merge( $platform_aliases, array( $account_id, $account_id ) )
		), ARRAY_A );

		if ( ! $row ) {
			return $out;
		}
		$out['character_id'] = (int) $row['character_id'];
		$out['binding_mode'] = (string) ( $row['mode'] ?? 'auto' );
		$out['trace']['binding_found'] = true;
		$out['trace']['binding_mode']  = $out['binding_mode'];

		// Step 2 — character_id → attached notebooks.
		// TWO schemas live side-by-side:
		//   (A) PHASE 0.34.2 — `kg_notebooks.character_id = ?` (1:N FK column).
		//       This is what the character-edit UI's "Notebooks" tab writes
		//       (admin_post_bizcity_character_notebook_attach @ class-admin-menu.php:5000).
		//   (B) PHASE 0.21+ — `bizcity_notebook_character_attachments(notebook_id, guru_uuid)`
		//       N:N table for marketplace-imported gurus.
		// Merge both, dedupe.
		$char_tbl = $wpdb->prefix . 'bizcity_characters';
		$guru_uuid = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT guru_uuid FROM {$char_tbl} WHERE id=%d LIMIT 1",
			$out['character_id']
		) );
		$out['guru_uuid']            = $guru_uuid;
		$out['trace']['guru_uuid']   = $guru_uuid ? substr( $guru_uuid, 0, 8 ) . '…' : '';

		$nb_ids = array();

		// (A) bizcity_kg_notebooks.character_id = ? — the canonical, UI-writable path.
		$nb_tbl = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_notebooks()
			: $wpdb->prefix . 'bizcity_kg_notebooks';
		$rows_a = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$nb_tbl} WHERE character_id=%d ORDER BY id ASC",
			$out['character_id']
		) );
		foreach ( (array) $rows_a as $id ) { $nb_ids[ (int) $id ] = true; }
		$out['trace']['notebooks_by_character_id'] = array_map( 'intval', (array) $rows_a );

		// (B) bizcity_notebook_character_attachments JOIN by guru_uuid.
		if ( $guru_uuid ) {
			$att_tbl = $wpdb->prefix . 'bizcity_notebook_character_attachments';
			$rows_b  = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT notebook_id FROM {$att_tbl} WHERE guru_uuid=%s",
				$guru_uuid
			) );
			foreach ( (array) $rows_b as $id ) { $nb_ids[ (int) $id ] = true; }
			$out['trace']['notebooks_by_guru_uuid'] = array_map( 'intval', (array) $rows_b );
		}

		ksort( $nb_ids );
		$out['notebooks']               = array_keys( $nb_ids );
		$out['trace']['notebook_count'] = count( $out['notebooks'] );
		$out['trace']['notebook_ids']   = $out['notebooks'];

		return $out;
	}
}
