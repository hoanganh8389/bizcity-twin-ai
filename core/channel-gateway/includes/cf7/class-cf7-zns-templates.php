<?php
/**
 * CF7 ZNS Templates — CRUD catalog qua WP Options.
 *
 * Option key: bizcity_cg_zns_templates
 * Shape:      array keyed by temp_id (string)
 *   temp_id:    string  — eSMS TempID (e.g. '595298')
 *   name:       string  — tên gợi nhớ
 *   oa_id:      string  — OA ID mặc định
 *   vars:       array   — [ { var_name, example, description, required } ]
 *   status:     string  — 'active' | 'inactive'
 *   notes:      string
 *   created_at: string
 *   updated_at: string
 *
 * Cache group: bzcc_zns_tpl
 * Keys:        all_active, all, tpl_{temp_id}
 *
 * [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-ZNS-TEMPLATE-CATALOG (2026-06-28)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CF7_ZNS_Templates' ) ) {
	return;
}

class BizCity_CF7_ZNS_Templates {

	const OPTION = 'bizcity_cg_zns_templates';
	const GROUP  = 'bzcc_zns_tpl';

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * List all templates.
	 *
	 * @param  string $status 'active' | 'inactive' | 'all'
	 * @return array  Array of template items (values), NOT keyed.
	 */
	public static function get_all( $status = 'active' ) {
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — cache wrap
		$cache_key = ( $status === 'all' ) ? 'all' : 'all_active';
		$cached    = class_exists( 'BizCity_Cache' )
			? BizCity_Cache::get( self::GROUP, $cache_key )
			: false;
		if ( false !== $cached ) {
			return $cached;
		}

		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_status = isset( $item['status'] ) ? (string) $item['status'] : 'active';
			if ( $status !== 'all' && $item_status !== $status ) {
				continue;
			}
			$out[] = self::shape( $item );
		}

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::GROUP, $cache_key, $out );
		}
		return $out;
	}

	/**
	 * Get single template by temp_id.
	 *
	 * @param  string $temp_id
	 * @return array|null
	 */
	public static function get( $temp_id ) {
		$temp_id   = sanitize_text_field( (string) $temp_id );
		$cache_key = 'tpl_' . $temp_id;
		$cached    = class_exists( 'BizCity_Cache' )
			? BizCity_Cache::get( self::GROUP, $cache_key )
			: false;
		if ( false !== $cached ) {
			return $cached;
		}

		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $temp_id ] ) ) {
			return null;
		}

		$item = self::shape( $raw[ $temp_id ] );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::GROUP, $cache_key, $item );
		}
		return $item;
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Save (create or update) a template. Keyed by temp_id.
	 *
	 * @param  array $data
	 * @return bool
	 */
	public static function save( array $data ) {
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — upsert keyed by temp_id
		$item    = self::sanitize( $data );
		$temp_id = $item['temp_id'];
		if ( $temp_id === '' ) {
			return false;
		}

		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$now = current_time( 'c' );
		if ( ! isset( $raw[ $temp_id ] ) ) {
			$item['created_at'] = $now;
		} else {
			$item['created_at'] = isset( $raw[ $temp_id ]['created_at'] ) ? $raw[ $temp_id ]['created_at'] : $now;
		}
		$item['updated_at'] = $now;

		$raw[ $temp_id ] = $item;
		$ok = update_option( self::OPTION, $raw, false );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::GROUP );
		}
		return $ok;
	}

	/**
	 * Delete a template by temp_id.
	 *
	 * @param  string $temp_id
	 * @return bool
	 */
	public static function delete( $temp_id ) {
		$temp_id = sanitize_text_field( (string) $temp_id );
		$raw     = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $temp_id ] ) ) {
			return false;
		}
		unset( $raw[ $temp_id ] );
		$ok = update_option( self::OPTION, $raw, false );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::GROUP );
		}
		return $ok;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Sanitize incoming data.
	 *
	 * @param  array $raw
	 * @return array
	 */
	private static function sanitize( array $raw ) {
		return array(
			'temp_id'    => sanitize_text_field( (string) ( isset( $raw['temp_id'] ) ? $raw['temp_id'] : '' ) ),
			'name'       => sanitize_text_field( (string) ( isset( $raw['name'] )    ? $raw['name']    : '' ) ),
			'oa_id'      => sanitize_text_field( (string) ( isset( $raw['oa_id'] )   ? $raw['oa_id']   : '' ) ),
			'vars'       => self::sanitize_vars( isset( $raw['vars'] ) && is_array( $raw['vars'] ) ? $raw['vars'] : array() ),
			'status'     => in_array( isset( $raw['status'] ) ? $raw['status'] : '', array( 'active', 'inactive' ), true )
							? (string) $raw['status'] : 'active',
			'notes'      => sanitize_textarea_field( (string) ( isset( $raw['notes'] ) ? $raw['notes'] : '' ) ),
		);
	}

	/**
	 * Sanitize vars array.
	 * Each item: { var_name, example, description, required }
	 *
	 * @param  array $raw
	 * @return array
	 */
	private static function sanitize_vars( array $raw ) {
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$var_name = sanitize_text_field( (string) ( isset( $item['var_name'] ) ? $item['var_name'] : '' ) );
			if ( $var_name === '' ) {
				continue;
			}
			$out[] = array(
				'var_name'    => $var_name,
				'example'     => sanitize_text_field( (string) ( isset( $item['example'] )     ? $item['example']     : '' ) ),
				'description' => sanitize_text_field( (string) ( isset( $item['description'] ) ? $item['description'] : '' ) ),
				'required'    => ! empty( $item['required'] ),
			);
		}
		return $out;
	}

	/**
	 * Shape a stored item for REST output — ensure all keys present.
	 *
	 * @param  array $item
	 * @return array
	 */
	private static function shape( array $item ) {
		return array(
			'temp_id'    => (string) ( isset( $item['temp_id'] )    ? $item['temp_id']    : '' ),
			'name'       => (string) ( isset( $item['name'] )       ? $item['name']       : '' ),
			'oa_id'      => (string) ( isset( $item['oa_id'] )      ? $item['oa_id']      : '' ),
			'vars'       => isset( $item['vars'] ) && is_array( $item['vars'] ) ? $item['vars'] : array(),
			'status'     => (string) ( isset( $item['status'] )     ? $item['status']     : 'active' ),
			'notes'      => (string) ( isset( $item['notes'] )      ? $item['notes']      : '' ),
			'created_at' => (string) ( isset( $item['created_at'] ) ? $item['created_at'] : '' ),
			'updated_at' => (string) ( isset( $item['updated_at'] ) ? $item['updated_at'] : '' ),
		);
	}
}

// ── Cache Registry (R-CACHE) ──────────────────────────────────────────────────
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzcc_zns_tpl', 'core.channel-gateway', array(
		'all_active'    => array( 'ttl' => BizCity_Cache::TTL_LONG, 'desc' => 'All active ZNS templates' ),
		'all'           => array( 'ttl' => BizCity_Cache::TTL_LONG, 'desc' => 'All ZNS templates (any status)' ),
		'tpl_{temp_id}' => array( 'ttl' => BizCity_Cache::TTL_LONG, 'desc' => 'Single template by temp_id' ),
	) );
}
