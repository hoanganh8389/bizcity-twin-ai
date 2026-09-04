<?php
/**
 * Database Manager
 * Handles database table creation and queries
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
	}

	class BizCity_Zalo_Bot_Database {

		private static $instance = null;
		private static $migration_checked = false;
		private static $activated_blog_ids = array();

		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/** Create only the active bot configuration table. */
		public static function activate() {
			$blog_id = (int) get_current_blog_id();
			// [2026-09-01 Johnny Chu] R-MSDB — retain per-blog activation idempotency for multisite provisioning.
			if ( isset( self::$activated_blog_ids[ $blog_id ] ) ) {
				return;
			}
			self::$activated_blog_ids[ $blog_id ] = true;
			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();
			$table_bots = $wpdb->prefix . 'bizcity_zalo_bots';
			$sql_bots = "CREATE TABLE IF NOT EXISTS $table_bots (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				bot_name varchar(255) NOT NULL,
				bot_token varchar(500) NOT NULL,
				app_id varchar(100) DEFAULT '',
				app_secret varchar(255) DEFAULT '',
				oa_id varchar(100) DEFAULT '',
				webhook_url varchar(500) DEFAULT '',
				webhook_secret varchar(100) DEFAULT '',
				status varchar(20) DEFAULT 'active',
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY status (status)
			) $charset_collate;";
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			dbDelta( $sql_bots );
			// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — never create the retired Zalo Bot SQL log projection.
			self::maybe_migrate();
			update_option( 'bizcity_zalo_bot_db_version', '1.3.0' );
		}

		private static function maybe_migrate() {
			// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — retired Zalo Bot logs have no SQL migration path.
			self::$migration_checked = true;
		}
	
	/**
	 * Get all active bots
	 */
	public function get_active_bots() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		return $wpdb->get_results( "SELECT * FROM $table WHERE status = 'active' ORDER BY id DESC" );
	}
	
	/**
	 * Get bot by ID
	 */
	public function get_bot( $bot_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $bot_id ) );
	}
	
	/**
	 * Create or update bot
	 */
	public function save_bot( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		
		if ( isset( $data['id'] ) && $data['id'] > 0 ) {
			// Update
			$wpdb->update( $table, $data, array( 'id' => $data['id'] ) );
			return $data['id'];
		} else {
			// Insert
			unset( $data['id'] );
			$wpdb->insert( $table, $data );
			return $wpdb->insert_id;
		}
	}

	public function save_platform_identity_from_get_me( $bot_id, $response ) {
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — persist Zalo Bot Platform getMe.id/account_name so customer MyChannels can render bot profile + group invite links.
		$bot_id = (int) $bot_id;
		if ( $bot_id <= 0 || ! is_array( $response ) ) {
			return array();
		}
		$result = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : $response;
		$platform_id  = isset( $result['id'] ) ? sanitize_text_field( (string) $result['id'] ) : '';
		$account_name = sanitize_text_field( (string) ( $result['account_name'] ?? $result['username'] ?? '' ) );
		$display_name = sanitize_text_field( (string) ( $result['display_name'] ?? $result['first_name'] ?? '' ) );
		$account_type = sanitize_text_field( (string) ( $result['account_type'] ?? '' ) );
		$identifier = trim( $platform_id . ' ' . $account_name );
		if ( $identifier === '' ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		$wpdb->update( $table, array( 'oa_id' => $identifier ), array( 'id' => $bot_id ), array( '%s' ), array( '%d' ) );
		$identity = array(
			'bot_platform_id' => $platform_id,
			'account_name'    => $account_name,
			'display_name'    => $display_name,
			'account_type'    => $account_type,
			'identifier'      => $identifier,
			'bot_url'         => $platform_id !== '' ? 'https://bot.zaloplatforms.com/bots/' . rawurlencode( $platform_id ) : '',
			'invite_url'      => $account_name !== '' ? 'https://bot.zaloplatforms.com/groups/invite/' . rawurlencode( $account_name ) : '',
			'updated_at'      => current_time( 'mysql' ),
		);
		update_option( 'bizcity_zalo_bot_platform_identity_' . $bot_id, $identity, false );
		return $identity;
	}
	
	/**
	 * Delete bot
	 */
	public function delete_bot( $bot_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		return $wpdb->delete( $table, array( 'id' => $bot_id ) );
	}
	
	/**
	 * Log event
	 */
	public function log_event( $bot_id, $event_name, $event_data, $client_id = '', $message_id = '', $display_name = '', $text = '' ) {
		// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — route active Zalo bot log writes through canonical channel JSONL first.
		$event_data_json = is_array( $event_data ) ? wp_json_encode( $event_data ) : (string) $event_data;
		$jsonl_ok = $this->write_channel_log( array(
			'bot_id'       => (int) $bot_id,
			'event_name'   => (string) $event_name,
			'event_data'   => $event_data_json,
			'client_id'    => (string) $client_id,
			'user_id'      => (string) $client_id,
			'message_id'   => (string) $message_id,
			'display_name' => (string) $display_name,
			'text'         => (string) $text,
		) );
		if ( $jsonl_ok ) {
			return 1;
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — JSONL failure is fail-closed; the retired SQL projection is never written.
		return 0;
	}
	
	/**
	 * Get logs
	 */
	public function get_logs( $args = array() ) {
		$defaults = array(
			'bot_id' => 0,
			'event_name' => '',
			'limit' => 50,
			'offset' => 0,
		);
		
		$args = wp_parse_args( $args, $defaults );

		// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — read canonical channel JSONL history first.
		$jsonl_rows = $this->read_channel_logs( $args );
		if ( is_array( $jsonl_rows ) ) {
			return $jsonl_rows;
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — logger unavailable means stable empty history, never SQL fallback.
		return array();
	}
	
	/**
	 * Get unique client IDs for a bot (for testing)
	 */
	public function get_user_ids( $bot_id = 0 ) {
		// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — aggregate unique users from canonical channel JSONL when available.
		$jsonl_rows = $this->get_logs( array( 'bot_id' => (int) $bot_id, 'limit' => 2000 ) );
		if ( is_array( $jsonl_rows ) && ! empty( $jsonl_rows ) ) {
			$agg = array();
			foreach ( $jsonl_rows as $row ) {
				$client_id = isset( $row->client_id ) ? (string) $row->client_id : '';
				if ( $client_id === '' ) {
					$client_id = isset( $row->user_id ) ? (string) $row->user_id : '';
				}
				if ( $client_id === '' ) {
					continue;
				}
				$created_at = isset( $row->created_at ) ? (string) $row->created_at : '';
				if ( ! isset( $agg[ $client_id ] ) || strcmp( $created_at, (string) $agg[ $client_id ]['last_seen'] ) > 0 ) {
					$agg[ $client_id ] = array(
						'user_id'      => $client_id,
						'last_seen'    => $created_at,
						'display_name' => isset( $row->display_name ) ? (string) $row->display_name : '',
					);
				}
			}
			if ( ! empty( $agg ) ) {
				usort( $agg, static function ( $left, $right ) {
					return strcmp( (string) $right['last_seen'], (string) $left['last_seen'] );
				} );
				$agg = array_slice( $agg, 0, 50 );
				return array_map( static function ( $item ) {
					return (object) $item;
				}, $agg );
			}
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — do not reconstruct user lists from retired SQL.
		return array();
	}

	private function write_channel_log( array $payload ) {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || ! method_exists( 'BizCity_Channel_File_Logger', 'write' ) ) {
			return false;
		}
		$event_name = sanitize_text_field( (string) ( $payload['event_name'] ?? '' ) );
		if ( $event_name === '' ) {
			$event_name = 'zalo_bot_event';
		}
		$ctx = array(
			'bot_id'        => (int) ( $payload['bot_id'] ?? 0 ),
			'event_name'    => $event_name,
			'event_data'    => (string) ( $payload['event_data'] ?? '' ),
			'client_id'     => (string) ( $payload['client_id'] ?? '' ),
			'user_id'       => (string) ( $payload['user_id'] ?? '' ),
			'message_id'    => (string) ( $payload['message_id'] ?? '' ),
			'display_name'  => (string) ( $payload['display_name'] ?? '' ),
			'text'          => (string) ( $payload['text'] ?? '' ),
			'legacy_schema' => 'bizcity_zalo_bot_logs',
		);
		return (bool) BizCity_Channel_File_Logger::write(
			BizCity_Channel_File_Logger::CH_ZALO_BOT,
			BizCity_Channel_File_Logger::LEVEL_INFO,
			$event_name,
			'zalo_bot_event',
			$ctx
		);
	}

	private function read_channel_logs( array $args ) {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! method_exists( 'BizCity_JSONL_File_Logger', 'query_contract' ) ) {
			return null;
		}
		$limit  = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$scan   = min( 5000, $limit + $offset + 300 );
		$bot_id = (int) ( $args['bot_id'] ?? 0 );
		$event_name = (string) ( $args['event_name'] ?? '' );

		$rows = BizCity_JSONL_File_Logger::query_contract( 'core.channel_gateway.zalo_bot', array(
			'days'  => 45,
			'limit' => $scan,
			'filter' => static function ( $row ) use ( $bot_id, $event_name ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				if ( $bot_id > 0 && (int) ( $ctx['bot_id'] ?? 0 ) !== $bot_id ) {
					return false;
				}
				if ( $event_name !== '' && (string) ( $row['event'] ?? '' ) !== $event_name ) {
					return false;
				}
				return true;
			},
		) );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$rows = array_slice( $rows, $offset, $limit );
		$out  = array();
		foreach ( $rows as $row ) {
			$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
			$event_uuid = (string) ( $row['event_uuid'] ?? $ctx['event_uuid'] ?? '' );
			$ts = (string) ( $row['ts'] ?? '' );
			$created_at = str_replace( 'T', ' ', substr( $ts, 0, 19 ) );
			$id = (int) ( $ctx['legacy_log_id'] ?? 0 );
			if ( $id <= 0 ) {
				$id = abs( (int) crc32( 'zalo|' . $event_uuid . '|' . $ts ) );
				if ( $id <= 0 ) {
					$id = 1;
				}
			}
			$event_data = (string) ( $ctx['event_data'] ?? '' );
			if ( $event_data === '' ) {
				$event_data = (string) ( $row['msg'] ?? '' );
			}
			$out[] = (object) array(
				'id'           => $id,
				'bot_id'       => (int) ( $ctx['bot_id'] ?? 0 ),
				'event_name'   => (string) ( $row['event'] ?? '' ),
				'event_data'   => $event_data,
				'client_id'    => (string) ( $ctx['client_id'] ?? '' ),
				'user_id'      => (string) ( $ctx['user_id'] ?? ( $ctx['client_id'] ?? '' ) ),
				'message_id'   => (string) ( $ctx['message_id'] ?? '' ),
				'display_name' => (string) ( $ctx['display_name'] ?? '' ),
				'text'         => (string) ( $ctx['text'] ?? '' ),
				'created_at'   => $created_at,
			);
		}
		return $out;
	}
}
