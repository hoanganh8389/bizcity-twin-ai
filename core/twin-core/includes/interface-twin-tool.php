<?php
/**
 * Bizcity Twin AI — Twin Tool Interface
 *
 * Sprint 4.7a — Mọi tool consume bởi Twin_Agent_Loop phải implement interface này.
 * Tool đăng ký qua filter `bizcity_twin_register_tool`.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

interface BizCity_Twin_Tool {

	/**
	 * Tool slug (lowercase, snake_case). Dùng làm key trong registry và trong
	 * LLM tool-call output: `<tool>{name}(...)</tool>`.
	 */
	public function name(): string;

	/**
	 * Mô tả ngắn gọn cho LLM — 1–2 câu, hướng dẫn KHI NÀO dùng.
	 */
	public function description(): string;

	/**
	 * JSON Schema mô tả arguments. Dùng để render trong system prompt và validate input.
	 *
	 * Ví dụ:
	 *   [
	 *     'type' => 'object',
	 *     'properties' => [
	 *       'query'  => [ 'type' => 'string', 'description' => 'Search text' ],
	 *       'top_k'  => [ 'type' => 'integer', 'default' => 5, 'minimum' => 1, 'maximum' => 20 ],
	 *     ],
	 *     'required' => [ 'query' ],
	 *   ]
	 */
	public function parameters_schema(): array;

	/**
	 * Thực thi tool.
	 *
	 * @param array $args     Arguments do LLM cung cấp (đã pass JSON Schema validation cơ bản).
	 * @param array $context  [ 'scope' => ['type','id'], 'user_id' => N, 'session_id' => '...' ]
	 *
	 * @return array {
	 *   ok            : bool,
	 *   result        : mixed,           // sẽ được JSON-encode vào prompt cho LLM
	 *   summary       : string,          // mô tả ngắn cho SSE tool_result event
	 *   sources       : array (optional),// passages/sources để feed sang FE
	 *   citation_ids  : string[] (optional) // ['a3x9','b2m7'] - chip IDs FE render
	 *   error         : string (optional)
	 * }
	 */
	public function execute( array $args, array $context ): array;
}
