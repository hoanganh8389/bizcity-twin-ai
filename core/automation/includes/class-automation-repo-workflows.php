<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @license    GPL-2.0-or-later
 *
 * BizCity_Automation_Repo_Workflows — wpdb-backed repository for the
 * `bizcity_automation_workflows` table (BE-1).
 *
 * Pure CRUD; no business logic, no REST coupling. Always sanitises input
 * with whitelist + wpdb->prepare. Returns plain associative arrays so the
 * REST controller can json_encode directly.
 *
 * Schema contract: core/diagnostics/changelog/core.automation.json v1.0.0.
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Repo_Workflows {

	const TABLE = 'bizcity_automation_workflows';

	const TRIGGER_TYPES = array(
		'manual',
		'zalo_inbound',
		'fb_message',      // BE-6.D — Facebook Messenger DM (was fb_comment alias).
		'fb_comment',      // Facebook Page feed comment.
		'telegram_inbound',// BE-6.D — Telegram bot inbound.
		'cron',
		'webhook',
		'twinbrain_intent',// BE-6.E hook — chat intent from TwinBrain runtime.
		'twinbrain_turn_completed', // BE-7.A — synthesis_done / final_done / agent_loop_done.
		'twinbrain_tool_decided',   // BE-7.A — Stage 3 tool suggestion fired.
	);

	public static function table(): string {
		return BizCity_Automation_Installer::table( self::TABLE );
	}

	/**
	 * Insert a new workflow. Returns the inserted row (with id) or WP_Error.
	 *
	 * @param array<string,mixed> $input Validated input.
	 */
	public static function create( array $input ) {
		global $wpdb;
		BizCity_Automation_Installer::ensure();

		$row = self::normalise( $input );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$row['created_at'] = current_time( 'mysql' );
		$row['updated_at'] = current_time( 'mysql' );
		$row['created_by'] = (int) get_current_user_id();
		$row['version']    = 1;

		// Auto slug if blank.
		if ( $row['slug'] === '' ) {
			$row['slug'] = 'wf_' . wp_generate_password( 8, false, false );
		}
		if ( self::find_by_slug( $row['slug'] ) ) {
			return new WP_Error( 'duplicate_slug', __( 'Slug đã tồn tại.', 'bizcity-twin-ai' ), array( 'status' => 409 ) );
		}

		$ok = $wpdb->insert( self::table(), $row );
		if ( $ok === false ) {
			return new WP_Error( 'db_insert_failed', $wpdb->last_error ?: 'insert failed', array( 'status' => 500 ) );
		}
		return self::find( (int) $wpdb->insert_id );
	}

	public static function update( int $id, array $input ) {
		global $wpdb;
		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'Workflow không tồn tại.', 'bizcity-twin-ai' ), array( 'status' => 404 ) );
		}
		$row = self::normalise( $input, true );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		// Drop empty fields so PATCH-style updates don't blank columns.
		$row = array_filter( $row, static function ( $v ) { return $v !== null; } );
		if ( ! empty( $row['slug'] ) && $row['slug'] !== $existing['slug'] ) {
			$other = self::find_by_slug( $row['slug'] );
			if ( $other && (int) $other['id'] !== $id ) {
				return new WP_Error( 'duplicate_slug', __( 'Slug đã tồn tại.', 'bizcity-twin-ai' ), array( 'status' => 409 ) );
			}
		}
		$row['version']    = (int) $existing['version'] + 1;
		$row['updated_at'] = current_time( 'mysql' );

		$ok = $wpdb->update( self::table(), $row, array( 'id' => $id ) );
		if ( $ok === false ) {
			return new WP_Error( 'db_update_failed', $wpdb->last_error ?: 'update failed', array( 'status' => 500 ) );
		}
		return self::find( $id );
	}

	public static function soft_delete( int $id ) {
		global $wpdb;
		$ok = $wpdb->update( self::table(), array( 'enabled' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		return $ok !== false;
	}

	public static function hard_delete( int $id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ) !== false;
	}

	public static function duplicate( int $id ) {
		$src = self::find( $id );
		if ( ! $src ) {
			return new WP_Error( 'not_found', __( 'Workflow không tồn tại.', 'bizcity-twin-ai' ), array( 'status' => 404 ) );
		}
		$copy = $src;
		unset( $copy['id'], $copy['created_at'], $copy['updated_at'], $copy['created_by'], $copy['version'] );
		$copy['slug']        = $src['slug'] . '_copy_' . wp_generate_password( 4, false, false );
		$copy['name']        = $src['name'] . ' (copy)';
		$copy['enabled']     = 0;
		return self::create( $copy );
	}

	public static function find( int $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	public static function find_by_slug( string $slug ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s', $slug ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Query workflows.
	 *
	 * @param array{enabled?:int,trigger_type?:string,tag?:string,limit?:int,offset?:int,search?:string} $args
	 * @return array{rows: array<int,array<string,mixed>>, total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		BizCity_Automation_Installer::ensure();

		$where  = array( '1=1' );
		$params = array();

		if ( isset( $args['enabled'] ) && $args['enabled'] !== '' && $args['enabled'] !== null ) {
			$where[]  = 'enabled = %d';
			$params[] = (int) $args['enabled'];
		}
		if ( ! empty( $args['trigger_type'] ) ) {
			$where[]  = 'trigger_type = %s';
			$params[] = (string) $args['trigger_type'];
		}
		if ( ! empty( $args['tag'] ) ) {
			$where[]  = 'tags LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['tag'] ) . '%';
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '( name LIKE %s OR slug LIKE %s )';
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$sql_where = implode( ' AND ', $where );
		$rows_sql  = "SELECT * FROM " . self::table() . " WHERE {$sql_where} ORDER BY updated_at DESC LIMIT {$limit} OFFSET {$offset}";
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $rows_sql, ...$params ) : $rows_sql, ARRAY_A );
		$rows = array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );

		$total_sql = "SELECT COUNT(*) FROM " . self::table() . " WHERE {$sql_where}";
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, ...$params ) : $total_sql );

		return array( 'rows' => $rows, 'total' => $total );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Validation + normalisation
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function normalise( array $in, bool $is_update = false ) {
		$row = array(
			'slug'                => isset( $in['slug'] )         ? self::slugify( (string) $in['slug'] ) : ( $is_update ? null : '' ),
			'name'                => isset( $in['name'] )         ? wp_strip_all_tags( (string) $in['name'] ) : ( $is_update ? null : '' ),
			'description'         => isset( $in['description'] )  ? wp_kses_post( (string) $in['description'] ) : ( $is_update ? null : '' ),
			'enabled'             => isset( $in['enabled'] )      ? (int) (bool) $in['enabled'] : ( $is_update ? null : 1 ),
			'graph_json'          => null,
			'trigger_type'        => null,
			'trigger_config_json' => null,
			'tags'                => null,
			'debug_breakpoints_json' => null,
		);

		// Trigger type whitelist.
		if ( isset( $in['trigger_type'] ) ) {
			if ( ! in_array( $in['trigger_type'], self::TRIGGER_TYPES, true ) ) {
				return new WP_Error( 'invalid_trigger_type', sprintf( __( 'trigger_type không hợp lệ: %s', 'bizcity-twin-ai' ), $in['trigger_type'] ), array( 'status' => 400 ) );
			}
			$row['trigger_type'] = (string) $in['trigger_type'];
		} elseif ( ! $is_update ) {
			$row['trigger_type'] = 'manual';
		}

		// Tags: accept array OR comma-separated string.
		if ( isset( $in['tags'] ) ) {
			$tags = is_array( $in['tags'] ) ? $in['tags'] : preg_split( '/\s*,\s*/', (string) $in['tags'] );
			$tags = array_filter( array_map( 'sanitize_title_with_dashes', $tags ?: array() ) );
			$row['tags'] = implode( ',', array_slice( array_unique( $tags ), 0, 16 ) );
		} elseif ( ! $is_update ) {
			$row['tags'] = '';
		}

		// Trigger config JSON.
		if ( array_key_exists( 'trigger_config', $in ) ) {
			$row['trigger_config_json'] = is_string( $in['trigger_config'] )
				? $in['trigger_config']
				: wp_json_encode( $in['trigger_config'] );
		} elseif ( array_key_exists( 'trigger_config_json', $in ) ) {
			$row['trigger_config_json'] = (string) $in['trigger_config_json'];
		}

		// Graph JSON — accept array OR pre-stringified.
		if ( array_key_exists( 'graph', $in ) ) {
			$check = self::validate_graph( $in['graph'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$row['graph_json'] = wp_json_encode( $in['graph'] );
		} elseif ( array_key_exists( 'graph_json', $in ) ) {
			$decoded = is_string( $in['graph_json'] ) ? json_decode( $in['graph_json'], true ) : null;
			if ( $decoded !== null ) {
				$check = self::validate_graph( $decoded );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
				$row['graph_json'] = (string) $in['graph_json'];
			}
		}

		// Debug breakpoints (PG-S5) — accept array or pre-stringified JSON.
		if ( array_key_exists( 'debug_breakpoints', $in ) ) {
			$bp = is_array( $in['debug_breakpoints'] ) ? $in['debug_breakpoints'] : array();
			$row['debug_breakpoints_json'] = $bp ? wp_json_encode( $bp ) : null;
		} elseif ( array_key_exists( 'debug_breakpoints_json', $in ) ) {
			$raw = (string) $in['debug_breakpoints_json'];
			$row['debug_breakpoints_json'] = $raw !== '' ? $raw : null;
		}
		return $row;
	}

	/**
	 * Validate the FE graph payload structure.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return new WP_Error( 'invalid_graph', __( 'graph phải là object.', 'bizcity-twin-ai' ), array( 'status' => 400 ) );
		}
		$nodes = isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? $graph['nodes'] : null;
		$edges = isset( $graph['edges'] ) && is_array( $graph['edges'] ) ? $graph['edges'] : array();
		if ( $nodes === null ) {
			return new WP_Error( 'invalid_graph', __( 'graph.nodes thiếu hoặc không phải array.', 'bizcity-twin-ai' ), array( 'status' => 400 ) );
		}

		$ids = array();
		$has_trigger = false;
		foreach ( $nodes as $i => $n ) {
			if ( ! is_array( $n ) || empty( $n['id'] ) || ! is_string( $n['id'] ) ) {
				return new WP_Error( 'invalid_graph', sprintf( __( 'Node #%d thiếu id.', 'bizcity-twin-ai' ), $i ), array( 'status' => 400 ) );
			}
			$ids[ $n['id'] ] = true;
			if ( ( $n['type'] ?? '' ) === 'trigger' ) {
				$has_trigger = true;
			}
		}
		if ( ! $has_trigger ) {
			return new WP_Error( 'invalid_graph', __( 'Workflow phải có ít nhất 1 node trigger.', 'bizcity-twin-ai' ), array( 'status' => 400 ) );
		}
		foreach ( $edges as $i => $e ) {
			if ( ! is_array( $e ) || empty( $e['source'] ) || empty( $e['target'] ) ) {
				return new WP_Error( 'invalid_graph', sprintf( __( 'Edge #%d thiếu source/target.', 'bizcity-twin-ai' ), $i ), array( 'status' => 400 ) );
			}
			if ( empty( $ids[ $e['source'] ] ) || empty( $ids[ $e['target'] ] ) ) {
				return new WP_Error( 'invalid_graph', sprintf( __( 'Edge #%d trỏ tới node không tồn tại.', 'bizcity-twin-ai' ), $i ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/** Hydrate db row → API payload (decode JSON columns). */
	public static function hydrate( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['enabled']        = (int) $row['enabled'];
		$row['version']        = (int) $row['version'];
		$row['created_by']     = (int) $row['created_by'];
		$row['graph']          = isset( $row['graph_json'] )         && $row['graph_json']         !== null && $row['graph_json']         !== '' ? json_decode( $row['graph_json'], true )         : null;
		$row['trigger_config'] = isset( $row['trigger_config_json'] ) && $row['trigger_config_json'] !== null && $row['trigger_config_json'] !== '' ? json_decode( $row['trigger_config_json'], true ) : null;
		$row['debug_breakpoints'] = isset( $row['debug_breakpoints_json'] ) && $row['debug_breakpoints_json'] !== null && $row['debug_breakpoints_json'] !== ''
			? ( json_decode( (string) $row['debug_breakpoints_json'], true ) ?: array() )
			: array();
		$row['tags_array']     = $row['tags'] !== '' ? explode( ',', (string) $row['tags'] ) : array();
		return $row;
	}

	private static function slugify( string $s ): string {
		$s = sanitize_title_with_dashes( $s );
		// Underscores ok in our slug pattern; sanitize_title strips them so re-translate dashes.
		return substr( $s, 0, 64 );
	}
}
