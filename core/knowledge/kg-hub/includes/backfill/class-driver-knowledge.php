<?php
/**
 * Bizcity Twin AI — KG Backfill Driver: Knowledge (legacy character KB)
 *
 * Migrates `bizcity_knowledge_sources` + `bizcity_knowledge_chunks`. Knowledge
 * scopes by integer `character_id`; wrapped as `agent_<id>` to keep namespace
 * unique across plugins.
 *
 * Legacy column names differ slightly from the unified shape:
 *   • title           ← source_name
 *   • content_text    ← content
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-28 — Phase 0.6.5 Wave B
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Backfill_Driver_Knowledge extends BizCity_KG_Backfill_Driver {

	protected $cortex_key   = 'knowledge';
	protected $plugin_name  = 'knowledge';
	protected $source_table = 'bizcity_knowledge_sources';
	protected $chunks_table = 'bizcity_knowledge_chunks';

	protected function project_id( array $src ): string {
		$cid = (int) ( $src['character_id'] ?? 0 );
		return $cid > 0 ? 'agent_' . $cid : '';
	}

	protected function scope( array $src ): array {
		$cid = (int) ( $src['character_id'] ?? 0 );
		return [
			'scope_type'  => 'character',
			'scope_id'    => $cid > 0 ? 'agent_' . $cid : '0',
			'notebook_id' => 0,
		];
	}

	protected function title( array $src ): string {
		$id = (int) ( $src['id'] ?? 0 );
		return (string) ( $src['source_name'] ?? $src['source_url'] ?? "Knowledge source #{$id}" );
	}

	/** Knowledge has no `user_id` column; owner is implicitly the character owner. */
	protected function user_id( array $src ): int {
		return 0;
	}
}
