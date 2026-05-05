<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.13 — Task 1.13.5
 * BizCity_Twin_Rolling_Session — In-memory BizCity_Twin_Session adapter.
 *
 * Vòng 1 MVP: pure in-memory — no DB write per turn. Sufficient for
 * single-request stateless runs. Persistent rolling-window upgrades
 * come in Vòng 2 (session continuation across HTTP requests).
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Rolling_Session' ) ) return;

/**
 * BizCity Twin Rolling Session (in-memory)
 *
 * Implements BizCity_Twin_Session with a simple PHP array backing store.
 * The session lives for the duration of one PHP request.
 *
 * For multi-turn runs (Vòng 2+) this adapter can be replaced with a
 * DB-backed variant without changing any Runner or Controller code.
 */
final class BizCity_Twin_Rolling_Session implements BizCity_Twin_Session {

	/** @var string */
	private $session_id;

	/** @var array<int, array> */
	private $items = [];

	/**
	 * @param string|null $session_id  Provide an existing id to continue a session.
	 *                                 Generates a new id when null.
	 */
	public function __construct( $session_id = null ) {
		if ( $session_id !== null && $session_id !== '' ) {
			$this->session_id = $session_id;
		} else {
			$this->session_id = 'ses_' . bin2hex( self::random_bytes( 8 ) );
		}
	}

	/* ================================================================
	 *  BizCity_Twin_Session implementation
	 * ================================================================ */

	public function get_session_id(): string {
		return $this->session_id;
	}

	/**
	 * @param int|null $limit
	 * @return array<int, array>
	 */
	public function get_items( $limit = null ): array {
		if ( $limit !== null && $limit > 0 ) {
			return array_slice( $this->items, -$limit );
		}
		return $this->items;
	}

	/**
	 * @param array<int, array> $items
	 */
	public function add_items( array $items ): void {
		foreach ( $items as $item ) {
			$this->items[] = $item;
		}
	}

	public function pop_item(): ?array {
		if ( empty( $this->items ) ) {
			return null;
		}
		return array_pop( $this->items );
	}

	public function clear(): void {
		$this->items = [];
	}

	/* ================================================================
	 *  Internal helpers
	 * ================================================================ */

	private static function random_bytes( int $len ): string {
		return function_exists( 'random_bytes' ) ? random_bytes( $len ) : openssl_random_pseudo_bytes( $len );
	}
}
