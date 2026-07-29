<?php
/**
 * Bizcity Twin AI — KG Graph Embedding Migration cron.
 *
 * Moves legacy entity/relation embedding LONGTEXT values into notebook-scoped
 * .embed.bin sidecars, then clears the SQL column after a successful append.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-07-23
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Graph_Embedding_Migration {

	const HOOK       = 'bizcity_kg_graph_embedding_migration';
	const SCHEDULE   = 'bizcity_kg_5min';
	const BATCH_SIZE = 100;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function is_enabled() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — feature flag for graph embedding LONGTEXT drain.
		$enabled = false;
		if ( class_exists( 'BizCity_Cron_Tier_Settings' ) && method_exists( 'BizCity_Cron_Tier_Settings', 'is_graph_embedding_migration_enabled' ) ) {
			$enabled = BizCity_Cron_Tier_Settings::is_graph_embedding_migration_enabled();
		} else {
			$enabled = (bool) get_option( 'bizcity_kg_v08_graph_embedding_migration_enabled', false );
		}
		return (bool) apply_filters( 'bizcity_kg_v08_graph_embedding_migration', $enabled );
	}

	public function bind() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — bind tier-aware cron only when migration flag is enabled.
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );

		if ( self::is_enabled() ) {
			if ( class_exists( 'BizCity_Cron_Tier_Settings' ) ) {
				BizCity_Cron_Tier_Settings::ensure_hook_interval( self::HOOK );
			} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
			}
			BizCity_KG_Filestore_Backfill::wake_due_events();
			return;
		}

		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		BizCity_KG_Filestore_Backfill::wake_due_events();
	}

	public function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'KG Graph Embedding Migration (5 min)',
			);
		}
		return $schedules;
	}

	public function run() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — cron entry emits diagnostics evidence per tick.
		$report = $this->run_once();
		update_option( 'bizcity_kg_graph_embedding_migration_last_run', array( 'at' => time(), 'report' => $report ), false );
		if ( empty( $report['skipped'] ) ) {
			do_action( 'bizcity_diagnostics_notice', 'kg_graph_embedding_migration', array(
				'blog_id'   => get_current_blog_id(),
				'report'    => $report,
				'timestamp' => time(),
			) );
		}
	}

	public function run_once() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — drain entity/relation vectors in bounded batches.
		if ( ! self::is_enabled() ) {
			return array( 'entities' => 0, 'relations' => 0, 'errors' => 0, 'scrubbed_existing' => 0, 'malformed' => 0, 'skipped' => true );
		}

		$lock_key = $this->acquire_lock();
		if ( ! $lock_key ) {
			return array( 'entities' => 0, 'relations' => 0, 'errors' => 0, 'scrubbed_existing' => 0, 'malformed' => 0, 'skipped' => true, 'reason' => 'lock_active' );
		}

		try {
			$started_at = microtime( true );
			$entities   = $this->migrate_kind( 'entity', self::BATCH_SIZE );
			$relations  = $this->migrate_kind( 'relation', self::BATCH_SIZE );

			return array(
				'entities'          => (int) $entities['migrated'],
				'relations'         => (int) $relations['migrated'],
				'errors'            => (int) $entities['errors'] + (int) $relations['errors'],
				'scrubbed_existing' => (int) $entities['scrubbed_existing'] + (int) $relations['scrubbed_existing'],
				'malformed'         => (int) $entities['malformed'] + (int) $relations['malformed'],
				'elapsed_ms'        => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			);
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — public wrapper so the
	 * Migration Console "housekeeping" runner (and its chunked AJAX buttons)
	 * can manually drain the entity/relation embedding LONGTEXT backlog on
	 * demand instead of waiting on the tier-based 5-10min cron tick
	 * (BATCH_SIZE=100/kind/tick). Same lock-free single-pass semantics as the
	 * internal cron call; caller decides its own batch size/time budget.
	 *
	 * @param string $kind  'entity' or 'relation'.
	 * @param int    $limit Max rows to drain in this call.
	 * @return array { migrated, errors, scrubbed_existing, malformed }
	 */
	public function migrate_batch( $kind, $limit ) {
		return $this->migrate_kind( $kind, $limit );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — synchronous single-row
	 * drain, called right after BizCity_KG_Filestore_Dispatcher::after_entity_insert()/
	 * after_relation_insert() flips storage_ver=2. Stops brand-new entities/
	 * relations from ever sitting on the embedding LONGTEXT column beyond the
	 * insert request itself, instead of leaking into the batched-cron backlog.
	 *
	 * @param string $kind  'entity' or 'relation'.
	 * @param string $table Fully-qualified table name (already resolved by caller).
	 * @param array  $row   Must include id, notebook_id, embedding (raw column value).
	 * @return string|false One of migrate_row()'s result codes, or false when skipped/disabled.
	 */
	public function migrate_row_now( $kind, $table, array $row ) {
		if ( ! self::is_enabled() ) { return false; }
		if ( empty( $row['embedding'] ) ) { return false; }
		return $this->migrate_row( $kind, $table, $row );
	}

	private function acquire_lock() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — avoid overlapping cron appends on the same blog.
		$lock_key = 'bizcity_kg_graph_embed_migrate_' . (int) get_current_blog_id();
		if ( get_transient( $lock_key ) ) {
			return false;
		}
		set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );
		return $lock_key;
	}

	private function migrate_kind( $kind, $limit ) {
		global $wpdb;

		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — select only rows still carrying SQL LONGTEXT embeddings.
		$db    = BizCity_KG_Database::instance();
		$table = ( 'relation' === $kind ) ? $db->tbl_relations() : $db->tbl_entities();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, embedding, metadata
			   FROM {$table}
			  WHERE embedding IS NOT NULL AND embedding<>''
			  ORDER BY id ASC LIMIT %d",
			(int) $limit
		), ARRAY_A ) ?: array();

		$out = array( 'migrated' => 0, 'errors' => 0, 'scrubbed_existing' => 0, 'malformed' => 0 );
		foreach ( $rows as $row ) {
			$result = $this->migrate_row( $kind, $table, $row );
			if ( 'migrated' === $result ) {
				$out['migrated']++;
			} elseif ( 'existing' === $result ) {
				$out['scrubbed_existing']++;
			} elseif ( 'malformed' === $result ) {
				$out['malformed']++;
			} else {
				$out['errors']++;
			}
		}
		return $out;
	}

	private function migrate_row( $kind, $table, array $row ) {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — append vector sidecar then clear SQL only after success.
		$record_id   = (int) $row['id'];
		$notebook_id = (int) $row['notebook_id'];
		$vector      = BizCity_KG_Database::decode_embedding( $row['embedding'] );

		if ( ! is_array( $vector ) || empty( $vector ) ) {
			$this->mark_malformed_and_clear( $table, $record_id, $row );
			return 'malformed';
		}

		$bin_path = $this->bin_path( $kind, $notebook_id );
		if ( is_wp_error( $bin_path ) ) {
			error_log( '[KG Graph Embedding Migration] path error: ' . $bin_path->get_error_message() );
			return 'error';
		}

		$uid = ( 'relation' === $kind ? 'relation:' : 'entity:' ) . $record_id;
		if ( $this->uid_exists( $bin_path, $uid ) ) {
			$this->clear_embedding( $table, $record_id );
			return 'existing';
		}

		$prepared = $this->prepare_existing_bin_for_append( $bin_path );
		if ( is_wp_error( $prepared ) ) {
			error_log( '[KG Graph Embedding Migration] idx error: ' . $prepared->get_error_message() );
			return 'error';
		}

		$idx_row = array(
			'uid'         => $uid,
			'kind'        => $kind,
			'notebook_id' => $notebook_id,
		);
		if ( 'relation' === $kind ) {
			$idx_row['relation_id'] = $record_id;
		} else {
			$idx_row['entity_id'] = $record_id;
		}

		$result = BizCity_KG_Vector_File_Store::instance()->append(
			$bin_path,
			array( $vector ),
			array( $idx_row ),
			array( 'dim' => count( $vector ), 'model_id' => BizCity_KG_Vector_File_Store::DEFAULT_MODEL )
		);
		if ( is_wp_error( $result ) ) {
			error_log( '[KG Graph Embedding Migration] append error: ' . $result->get_error_message() );
			return 'error';
		}

		$this->clear_embedding( $table, $record_id );
		return 'migrated';
	}

	private function bin_path( $kind, $notebook_id ) {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — notebook-local graph vector sidecars.
		$uuid = BizCity_KG_Notebook_Folder::instance()->notebook_uuid( (int) $notebook_id );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		$root = BizCity_KG_Notebook_Folder::instance()->path( 'notebooks', $uuid );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$file = ( 'relation' === $kind ) ? 'relations.embed.bin' : 'entities.embed.bin';
		return rtrim( $root, '/\\' ) . '/' . $file;
	}

	private function uid_exists( $bin_path, $uid ) {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — idempotency guard for crash-after-append-before-scrub.
		$idx_path = BizCity_KG_Vector_File_Store::instance()->idx_path( $bin_path );
		$idx      = BizCity_KG_Vector_File_Store::instance()->load_idx( $idx_path );
		if ( is_wp_error( $idx ) || empty( $idx['rows'] ) || ! is_array( $idx['rows'] ) ) {
			return false;
		}
		foreach ( $idx['rows'] as $idx_row ) {
			if ( isset( $idx_row['uid'] ) && (string) $idx_row['uid'] === (string) $uid ) {
				return true;
			}
		}
		return false;
	}

	private function prepare_existing_bin_for_append( $bin_path ) {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — refuse append if existing idx is corrupt/missing.
		if ( ! file_exists( $bin_path ) ) {
			return true;
		}
		$header = BizCity_KG_Vector_File_Store::instance()->header_validate( $bin_path );
		if ( is_wp_error( $header ) ) {
			return $header;
		}
		$idx = BizCity_KG_Vector_File_Store::instance()->load_idx( BizCity_KG_Vector_File_Store::instance()->idx_path( $bin_path ) );
		return is_wp_error( $idx ) ? $idx : true;
	}

	private function clear_embedding( $table, $record_id ) {
		global $wpdb;
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — SQL LONGTEXT is no longer source of truth after verified sidecar write.
		$wpdb->update( $table, array( 'embedding' => null ), array( 'id' => (int) $record_id ), array( '%s' ), array( '%d' ) );
	}

	private function mark_malformed_and_clear( $table, $record_id, array $row ) {
		global $wpdb;
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — malformed JSON embeddings are unusable; clear them with an audit marker.
		$metadata = array();
		if ( ! empty( $row['metadata'] ) ) {
			$decoded = json_decode( (string) $row['metadata'], true );
			if ( is_array( $decoded ) ) {
				$metadata = $decoded;
			}
		}
		$metadata['_kg_embed_migrate_error'] = array(
			'code' => 'malformed_embedding_json',
			'ts'   => gmdate( 'c' ),
		);
		$wpdb->update(
			$table,
			array( 'embedding' => null, 'metadata' => wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
			array( 'id' => (int) $record_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}