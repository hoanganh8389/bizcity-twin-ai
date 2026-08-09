<?php
/**
 * BizCity Automation Workflow Catalog — compact guide for conversational surfaces.
 *
 * Reads the existing workflow repository and trigger_config_json contract. This
 * is a read-only catalog; workflow execution remains owned by the trigger matcher.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Workflow_Catalog {

	const CACHE_TTL = 300;

	/**
	 * Return enabled workflows visible to a channel zone.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_scope( string $zone = 'admin', int $guru_id = 0 ): array {
		$zone    = in_array( $zone, array( 'admin', 'crm' ), true ) ? $zone : 'admin';
		$cache_key = 'bizcity_auto_catalog_' . $zone . '_' . $guru_id;
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return array();
		}

		$result = BizCity_Automation_Repo_Workflows::query( array(
			'enabled' => 1,
			'zone'    => $zone,
			'limit'   => 100,
		) );
		$entries = array();
		foreach ( (array) ( $result['rows'] ?? array() ) as $workflow ) {
			$entry = self::normalize_workflow( $workflow, $guru_id );
			if ( $entry ) {
				$entries[] = $entry;
			}
		}

		set_transient( $cache_key, $entries, self::CACHE_TTL );
		return $entries;
	}

	/**
	 * Render a compact, factual guide for an LLM prompt or direct response.
	 */
	public static function render_guide_md( array $entries ): string {
		if ( empty( $entries ) ) {
			return 'Hiện chưa có kịch bản tự động nào đang bật trong phạm vi này.';
		}

		$lines = array( '### Các kịch bản tự động đang bật', '' );
		foreach ( $entries as $entry ) {
			$terms = array_merge( $entry['keywords'], $entry['filters'], $entry['slash_commands'] );
			$terms = array_values( array_unique( array_filter( array_map( 'trim', $terms ) ) ) );
			$trigger = empty( $terms ) ? 'theo cấu hình riêng' : implode( ', ', array_map( static function ( $term ) {
				return '"' . $term . '"';
			}, $terms ) );
			$lines[] = sprintf( '- **%s** — kích hoạt bằng: %s', $entry['name'], $trigger );
		}
		$lines[] = '';
		$lines[] = 'Hãy nhắn đúng từ khoá hoặc slash command để chạy kịch bản. Kịch bản có side effect sẽ cần xác nhận theo luồng của nó.';
		return implode( "\n", $lines );
	}

	/**
	 * Return trigger terms for fuzzy suggestions.
	 *
	 * @return array<int,array{term:string,workflow_id:int,workflow_name:string}>
	 */
	public static function all_trigger_terms( string $zone = 'admin' ): array {
		$terms = array();
		foreach ( self::list_for_scope( $zone ) as $entry ) {
			foreach ( array_merge( $entry['keywords'], $entry['filters'], $entry['slash_commands'] ) as $term ) {
				$term = trim( (string) $term );
				if ( $term === '' ) {
					continue;
				}
				$terms[] = array(
					'term'          => $term,
					'workflow_id'   => (int) $entry['id'],
					'workflow_name' => (string) $entry['name'],
				);
			}
		}
		return $terms;
	}

	/**
	 * Suggest a likely trigger for a short leading @mention or slash command.
	 *
	 * @return array{term:string,workflow_id:int,workflow_name:string,distance:int}|null
	 */
	public static function suggest_trigger( string $text, string $zone = 'admin' ): ?array {
		if ( ! preg_match( '/^\s*([\/@][^\s]+)/u', $text, $match ) ) {
			return null;
		}
		$prefix = substr( $match[1], 0, 1 );
		$input  = self::compact_trigger( $match[1] );
		if ( $input === '' ) {
			return null;
		}

		$best = null;
		foreach ( self::all_trigger_terms( $zone ) as $candidate ) {
			$term = (string) $candidate['term'];
			if ( substr( trim( $term ), 0, 1 ) !== $prefix ) {
				continue;
			}
			$distance = levenshtein( $input, self::compact_trigger( $term ) );
			$limit    = strlen( $input ) > 8 ? 3 : 2;
			if ( $distance > $limit || ( $best && $distance >= $best['distance'] ) ) {
				continue;
			}
			$best = array_merge( $candidate, array( 'distance' => $distance ) );
		}
		return $best;
	}

	public static function flush_cache(): void {
		foreach ( array( 'admin', 'crm' ) as $zone ) {
			for ( $guru_id = 0; $guru_id <= 50; $guru_id++ ) {
				delete_transient( 'bizcity_auto_catalog_' . $zone . '_' . $guru_id );
			}
		}
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function normalize_workflow( array $workflow, int $guru_id ): ?array {
		$config = $workflow['trigger_config_json'] ?? ( $workflow['trigger_config'] ?? array() );
		if ( is_string( $config ) ) {
			$config = json_decode( $config, true );
		}
		if ( ! is_array( $config ) ) {
			$config = array();
		}
		$config_guru = (int) ( $config['guru_id'] ?? 0 );
		if ( $guru_id > 0 && $config_guru > 0 && $guru_id !== $config_guru ) {
			return null;
		}

		$keywords = array();
		foreach ( (array) ( $config['keywords'] ?? array() ) as $keyword ) {
			if ( is_string( $keyword ) ) {
				$keywords[] = $keyword;
			}
		}
		$slash = $config['slash_command'] ?? '';
		$slash_commands = is_array( $slash ) ? $slash : array( $slash );
		$slash_commands = array_values( array_filter( array_map( static function ( $term ) {
			$term = trim( (string) $term );
			return $term === '' ? '' : ( strpos( $term, '/' ) === 0 ? $term : '/' . $term );
		}, $slash_commands ) ) );

		return array(
			'id'              => (int) ( $workflow['id'] ?? 0 ),
			'name'            => sanitize_text_field( (string) ( $workflow['name'] ?? $workflow['slug'] ?? 'Kịch bản không tên' ) ),
			'slug'            => sanitize_key( (string) ( $workflow['slug'] ?? '' ) ),
			'trigger_type'    => sanitize_key( (string) ( $workflow['trigger_type'] ?? '' ) ),
			'zone'            => sanitize_key( (string) ( $config['zone'] ?? 'admin' ) ),
			'keywords'        => array_values( array_filter( array_map( 'sanitize_text_field', $keywords ) ) ),
			'filters'         => self::split_terms( (string) ( $config['filter'] ?? '' ) ),
			'slash_commands'  => $slash_commands,
			'description'     => sanitize_text_field( (string) ( $workflow['description'] ?? '' ) ),
		);
	}

	/**
	 * Split the same delimiter style accepted by the trigger matcher.
	 */
	private static function split_terms( string $value ): array {
		$parts = preg_split( '/[|,\n]+/', $value );
		return array_values( array_filter( array_map( 'sanitize_text_field', (array) $parts ) ) );
	}

	private static function compact_trigger( string $value ): string {
		$value = strtolower( remove_accents( trim( $value ) ) );
		$value = preg_replace( '/[^a-z0-9\/@]+/i', '', $value );
		return (string) $value;
	}
}