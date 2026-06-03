<?php
/**
 * BizCity_Automation_Block — interface mọi block PHẢI implement.
 *
 * Mirror FE registry `core/automation/frontend/src/blocks/registry.js`.
 * Contract:
 *   - id()       → 'trigger.zalo_inbound', 'action.search_kg', …
 *   - kind()     → 'trigger' | 'action' | 'llm' | 'logic' | 'group'
 *   - meta()     → catalog payload echo về FE qua REST /blocks
 *   - execute()  → BE-3 runner gọi: input = ($ctx, $data), output = array
 *                  hoặc WP_Error.
 *
 * KHÔNG implement HTTP/LLM/DB trực tiếp ở block. Delegate sang module
 * core đã có (BizCity_LLM_Client, BizCity_Knowledge, gateway senders…).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

interface BizCity_Automation_Block {

	/** Unique block id (FE-side identifier, vd "action.search_kg"). */
	public function id(): string;

	/** trigger | action | llm | logic | group */
	public function kind(): string;

	/**
	 * Catalog metadata trả về FE (label, category, defaults, fields…).
	 * Phải tương thích shape FE registry để palette + inspector hoạt động.
	 *
	 * @return array{
	 *   label: string,
	 *   category: string,
	 *   short?: string,
	 *   defaults?: array,
	 *   fields?: array,
	 *   color?: string,
	 *   icon?: string
	 * }
	 */
	public function meta(): array;

	/**
	 * Execute block trong runner context.
	 *
	 * @param array $ctx  Merged ctx từ trigger + upstream nodes.
	 * @param array $data Node `data` (đã resolve template tokens).
	 * @return array|WP_Error  Output ghi vào ctx[node_id].
	 */
	public function execute( array $ctx, array $data );
}
