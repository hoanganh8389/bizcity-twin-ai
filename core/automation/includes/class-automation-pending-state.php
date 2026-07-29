<?php
/**
 * BizCity_Automation_Pending_State — multi-turn conversational slot store.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION BE-7.C (2026-05-30)
 *
 * Replaces legacy transient pattern `bizgpt_image_<md5(client_id)>` with a
 * canonical, automation-aware slot keyed by canonical `chat_id` (e.g.
 * `zalo_<id>`, `zalobot_<bot>_<id>`, `fb_<page>_<psid>`, `adminchat_<sess>`).
 *
 * Storage: WP transient (object cache friendly, TTL-backed). No DDL.
 *
 * Schema of payload:
 *   [
 *     'intent'         => string   // free-form slot intent ID, e.g. 'awaiting_post_image'
 *     'workflow_id'    => int      // workflow to resume on next inbound (priority over keyword/fallback)
 *     'slots'          => array    // free-form key/value bag
 *     'attachment_url' => string   // legacy alias: latest captured media URL (image/file)
 *     'attachments'    => array    // canonical multi-attachment list, max 14 items
 *     'created_at'     => int      // unix
 *   ]
 *
 * TTL: 15 minutes (matches legacy `bizgpt_image_*`).
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Pending_State {

	const PREFIX        = 'bizcity_auto_pending_';
	const DEFAULT_TTL   = 900; // 15 minutes — same as legacy transient.

	private static function key( string $chat_id ): string {
		return self::PREFIX . md5( $chat_id );
	}

	private static function aliases( string $chat_id ): array {
		// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45-FIX — Zalo listener may emit private/non-private chat_id variants for the same user.
		$aliases = array( $chat_id );
		if ( preg_match( '/^zalobot_(\d+)_private_(.+)$/', $chat_id, $m ) ) {
			$aliases[] = 'zalobot_' . $m[1] . '_' . $m[2];
		} elseif ( preg_match( '/^zalobot_(\d+)_(.+)$/', $chat_id, $m ) && strpos( $m[2], 'private_' ) !== 0 ) {
			$aliases[] = 'zalobot_' . $m[1] . '_private_' . $m[2];
		}
		return array_values( array_unique( array_filter( $aliases ) ) );
	}

	private static function normalize_attachment( array $attachment ): array {
		// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — canonical per-item attachment shape for automation flows.
		$url = trim( (string) ( $attachment['url'] ?? $attachment['source_url'] ?? $attachment['attachment_url'] ?? '' ) );
		$wp_url = trim( (string) ( $attachment['wp_url'] ?? '' ) );
		if ( $url === '' && $wp_url !== '' ) { $url = $wp_url; }
		return array(
			'kind'          => sanitize_key( (string) ( $attachment['kind'] ?? $attachment['type'] ?? 'image' ) ),
			'url'           => $url,
			'source_url'    => trim( (string) ( $attachment['source_url'] ?? $url ) ),
			'wp_url'        => $wp_url,
			'attachment_id' => (int) ( $attachment['attachment_id'] ?? 0 ),
			'message_id'    => trim( (string) ( $attachment['message_id'] ?? '' ) ),
			'received_at'   => (int) ( $attachment['received_at'] ?? time() ),
		);
	}

	private static function normalize_attachments( array $payload ): array {
		$items = array();
		if ( ! empty( $payload['attachments'] ) && is_array( $payload['attachments'] ) ) {
			foreach ( $payload['attachments'] as $item ) {
				if ( is_array( $item ) ) {
					$items[] = self::normalize_attachment( $item );
				} elseif ( is_string( $item ) && trim( $item ) !== '' ) {
					$items[] = self::normalize_attachment( array( 'url' => $item ) );
				}
			}
		}
		if ( ! empty( $payload['attachment_url'] ) ) {
			$items[] = self::normalize_attachment( array(
				'url'        => (string) $payload['attachment_url'],
				'source_url' => (string) $payload['attachment_url'],
			) );
		}
		$dedup = array();
		$url_to_key = array();
		foreach ( $items as $item ) {
			$url = (string) ( $item['url'] ?? '' );
			if ( $url === '' ) { continue; }
			$key = (string) ( $item['message_id'] ?? '' );
			if ( $key === '' ) { $key = $url; }
			if ( isset( $url_to_key[ $url ] ) ) {
				$key = $url_to_key[ $url ];
			}
			$url_to_key[ $url ] = $key;
			$dedup[ $key ] = $item;
		}
		$items = array_values( $dedup );
		usort( $items, static function ( $a, $b ) {
			return (int) ( $a['received_at'] ?? 0 ) <=> (int) ( $b['received_at'] ?? 0 );
		} );
		return array_slice( $items, -14 );
	}

	private static function normalize_payload( array $payload ): array {
		// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — keep legacy attachment_url in sync with canonical attachments[].
		$attachments = self::normalize_attachments( $payload );
		if ( ! empty( $attachments ) ) {
			$payload['attachments'] = $attachments;
			$latest = end( $attachments );
			$payload['attachment_url'] = (string) ( $latest['wp_url'] ?: $latest['url'] );
		}
		return $payload;
	}

	/**
	 * Get current pending state for a chat_id (or empty array).
	 */
	public static function get( string $chat_id ): array {
		if ( $chat_id === '' ) { return array(); }
		$states = array();
		foreach ( self::aliases( $chat_id ) as $alias ) {
			$raw = get_transient( self::key( $alias ) );
			if ( is_array( $raw ) ) {
				$states[] = $raw;
			}
		}
		if ( empty( $states ) ) { return array(); }
		usort( $states, static function ( $a, $b ) {
			return (int) ( $a['created_at'] ?? 0 ) <=> (int) ( $b['created_at'] ?? 0 );
		} );
		$merged = array();
		foreach ( $states as $state ) {
			$merged = array_merge( $merged, $state );
			if ( isset( $state['slots'] ) && is_array( $state['slots'] ) ) {
				$merged['slots'] = array_merge( (array) ( $merged['slots'] ?? array() ), $state['slots'] );
			}
		}
		$all_attachments = array();
		foreach ( $states as $state ) {
			$all_attachments = array_merge( $all_attachments, self::normalize_attachments( $state ) );
		}
		if ( ! empty( $all_attachments ) ) {
			$merged['attachments'] = self::normalize_attachments( array( 'attachments' => $all_attachments ) );
			$latest = end( $merged['attachments'] );
			$merged['attachment_url'] = (string) ( $latest['wp_url'] ?: $latest['url'] );
		}
		if ( empty( $merged['attachment_url'] ) ) {
			for ( $i = count( $states ) - 1; $i >= 0; $i-- ) {
				if ( ! empty( $states[ $i ]['attachment_url'] ) ) {
					$merged['attachment_url'] = (string) $states[ $i ]['attachment_url'];
					break;
				}
			}
		}
		return $merged;
	}

	/**
	 * Replace pending state. $payload is merged with `created_at`.
	 */
	public static function set( string $chat_id, array $payload, int $ttl = self::DEFAULT_TTL ): bool {
		if ( $chat_id === '' ) { return false; }
		$payload = self::normalize_payload( $payload );
		$payload['created_at'] = time();
		$ok = false;
		foreach ( self::aliases( $chat_id ) as $alias ) {
			$ok = (bool) set_transient( self::key( $alias ), $payload, max( 60, $ttl ) ) || $ok;
		}
		return $ok;
	}

	/**
	 * Shallow-merge into existing state (slots[] merged, top-level overwrite).
	 */
	public static function patch( string $chat_id, array $patch, int $ttl = self::DEFAULT_TTL ): bool {
		$cur = self::get( $chat_id );
		if ( isset( $patch['slots'] ) && is_array( $patch['slots'] ) ) {
			$cur['slots'] = array_merge( (array) ( $cur['slots'] ?? array() ), $patch['slots'] );
			unset( $patch['slots'] );
		}
		if ( isset( $patch['attachments'] ) && is_array( $patch['attachments'] ) ) {
			$patch['attachments'] = array_merge( (array) ( $cur['attachments'] ?? array() ), $patch['attachments'] );
		}
		$next = self::normalize_payload( array_merge( $cur, $patch ) );
		return self::set( $chat_id, $next, $ttl );
	}

	public static function append_attachment( string $chat_id, array $attachment, int $ttl = self::DEFAULT_TTL ): bool {
		// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — append one inbound image/file without losing earlier same-batch images.
		if ( $chat_id === '' ) { return false; }
		$cur = self::get( $chat_id );
		$attachments = (array) ( $cur['attachments'] ?? array() );
		$attachments[] = self::normalize_attachment( $attachment );
		$cur['attachments'] = $attachments;
		return self::set( $chat_id, $cur, $ttl );
	}

	public static function clear( string $chat_id ): void {
		if ( $chat_id === '' ) { return; }
		foreach ( self::aliases( $chat_id ) as $alias ) {
			delete_transient( self::key( $alias ) );
		}
	}

	/**
	 * Returns true iff pending state present AND has a workflow_id to resume.
	 */
	public static function has_resume( string $chat_id ): bool {
		$st = self::get( $chat_id );
		return ! empty( $st['workflow_id'] );
	}
}
