<?php
/**
 * Bizcity Twin AI — KG Backfill Driver: BCN (Companion Notebook)
 *
 * Migrates `bizcity_rces` + `bizcity_rce_chunks` → unified KG schema.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-28 — Phase 0.6.5 Wave B
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Backfill_Driver_BCN extends BizCity_KG_Backfill_Driver {

	protected $cortex_key   = 'bcn';
	protected $plugin_name  = 'bcn';
	protected $source_table = 'bizcity_rces';
	protected $chunks_table = 'bizcity_rce_chunks';

	/** BCN project_id is already VARCHAR(50) UUID/sess_/wcs_ — pass through. */
	protected function project_id( array $src ): string {
		return (string) ( $src['project_id'] ?? '' );
	}

	protected function scope( array $src ): array {
		$pid = (string) ( $src['project_id'] ?? '' );
		if ( strpos( $pid, 'sess_' ) === 0 ) {
			return [ 'scope_type' => 'session', 'scope_id' => $pid, 'notebook_id' => 0 ];
		}
		// UUID or other → notebook scope; notebook_id stays 0 (not a legacy integer).
		return [ 'scope_type' => 'notebook', 'scope_id' => $pid !== '' ? $pid : '0', 'notebook_id' => 0 ];
	}

	/** BCN chunk table includes content_hash. */
	protected function chunk_columns(): string {
		return 'id, chunk_index, content, token_count, embedding';
	}
}
