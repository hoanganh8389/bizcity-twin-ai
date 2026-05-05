<?php
/**
 * Bizcity Twin AI — KG Backfill Driver: BizDoc
 *
 * Migrates `bzdoc_project_sources` + `bzdoc_project_source_chunks`. BizDoc
 * scopes by integer `doc_id`; we wrap as `doc_<id>` to keep cross-plugin
 * project_id namespace unique.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-28 — Phase 0.6.5 Wave B
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Backfill_Driver_BizDoc extends BizCity_KG_Backfill_Driver {

	protected $cortex_key   = 'bizdoc';
	protected $plugin_name  = 'bizdoc';
	protected $source_table = 'bzdoc_project_sources';
	protected $chunks_table = 'bzdoc_project_source_chunks';

	protected function project_id( array $src ): string {
		$doc_id = (int) ( $src['doc_id'] ?? 0 );
		return $doc_id > 0 ? 'doc_' . $doc_id : '';
	}

	protected function scope( array $src ): array {
		$doc_id = (int) ( $src['doc_id'] ?? 0 );
		return [
			'scope_type'  => 'document',
			'scope_id'    => $doc_id > 0 ? 'doc_' . $doc_id : '0',
			'notebook_id' => 0,
		];
	}
}
