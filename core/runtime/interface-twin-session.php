<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.13 — Task 1.13.4
 * BizCity_Twin_Session interface — 5-method contract for session adapters.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( interface_exists( 'BizCity_Twin_Session' ) ) return;

/**
 * BizCity Twin Session Contract
 *
 * All session adapters (rolling, in-memory, persistent, etc.) must implement
 * this interface. The Runner consumes this interface exclusively — it never
 * depends on a concrete session class.
 *
 * Message items are plain associative arrays:
 *   ['role' => 'user|assistant|system|tool', 'content' => '...']
 */
interface BizCity_Twin_Session {

	/**
	 * Return the stable session identifier.
	 *
	 * @return string  e.g. "ses_abc123" or a conversation_id
	 */
	public function get_session_id(): string;

	/**
	 * Return the full message history, newest-last.
	 *
	 * @param int|null $limit  When set, return only the last N items.
	 * @return array<int, array>
	 */
	public function get_items( $limit = null ): array;

	/**
	 * Append one or more message items to the session.
	 *
	 * @param array<int, array> $items
	 */
	public function add_items( array $items ): void;

	/**
	 * Remove and return the most-recently added item, or null if empty.
	 *
	 * @return array|null
	 */
	public function pop_item(): ?array;

	/**
	 * Discard all items from the session.
	 */
	public function clear(): void;
}
