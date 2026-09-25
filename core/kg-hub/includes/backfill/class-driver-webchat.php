<?php
/**
 * Bizcity Twin AI — KG Backfill Driver: TwinChat / Webchat
 *
 * Migrates `bizcity_webchat_sources` + `bizcity_webchat_source_chunks` →
 * `kg_sources` + `kg_source_chunks`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-28 — Phase 0.6.5 Wave B
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Backfill_Driver_Webchat extends BizCity_KG_Backfill_Driver {

	protected $cortex_key   = 'webchat';
	protected $plugin_name  = 'twinchat';
	protected $source_table = 'bizcity_webchat_sources';
	protected $chunks_table = 'bizcity_webchat_source_chunks';

	/**
	 * Webchat already uses VARCHAR project_id — UUID, `sess_xxx`, `wcs_xxx`,
	 * or numeric notebook id. Pass through; integers stay raw to keep
	 * compatibility with existing webchat queries that match on integer id.
	 */
	protected function project_id( array $src ): string {
		return (string) ( $src['project_id'] ?? '' );
	}

	protected function scope( array $src ): array {
		$pid = (string) ( $src['project_id'] ?? '' );
		if ( is_numeric( $pid ) && (int) $pid > 0 ) {
			return [ 'scope_type' => 'notebook', 'scope_id' => $pid, 'notebook_id' => (int) $pid ];
		}
		if ( strpos( $pid, 'sess_' ) === 0 ) {
			return [ 'scope_type' => 'session', 'scope_id' => $pid, 'notebook_id' => 0 ];
		}
		return [ 'scope_type' => 'notebook', 'scope_id' => $pid !== '' ? $pid : '0', 'notebook_id' => 0 ];
	}
}
