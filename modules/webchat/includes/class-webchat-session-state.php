<?php
/**
 * Canonical encrypted filestore owner for WebChat session state.
 *
 * Messages remain in bizcity_webchat_messages. This service owns session
 * metadata, lifecycle state, counters and working context.
 *
 * @package BizCity_Twin_AI
 * @subpackage Modules\Webchat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_WebChat_Session_State', false ) ) {
	return;
}

final class BizCity_WebChat_Session_State {

	const CONTRACT_ID = 'modules.webchat.session_state';
	const SCHEMA_VERSION = '1.0';

	private static $instance = null;
	private static $cache = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function available() {
		return class_exists( 'BizCity_File_Contract_Registry' )
			&& class_exists( 'BizCity_Business_JSONL_File_Store' )
			&& BizCity_File_Contract_Registry::has( self::CONTRACT_ID );
	}

	public function create( $user_id, $client_name = '', $platform_type = 'ADMINCHAT', $title = '', $data = array() ) {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — create session state as an encrypted folded business record.
		$session_id = 'wcs_' . wp_generate_uuid4();
		return $this->create_for_session( $session_id, $user_id, $client_name, $platform_type, $title, $data );
	}

	public function create_for_session( $session_id, $user_id, $client_name = '', $platform_type = 'WEBCHAT', $title = '', $data = array() ) {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — create state for the caller's stable session identity.
		$session_id = sanitize_text_field( (string) $session_id );
		if ( $session_id === '' ) {
			return array( 'id' => 0, 'session_id' => '', 'title' => $title );
		}
		$now = current_time( 'mysql' );
		$record = $this->defaults( $session_id, $user_id, $client_name, $platform_type, $title, $data );
		$record['created_at'] = $now;
		$record['updated_at'] = $now;
		if ( ! $this->write( $record ) ) {
			return array( 'id' => 0, 'session_id' => $session_id, 'title' => $title );
		}
		return array( 'id' => (int) $record['legacy_id'], 'session_id' => $session_id, 'title' => $title );
	}

	public function get_by_id( $id ) {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — resolve compatibility IDs through the folded filestore model.
		if ( ! self::available() || (int) $id <= 0 ) {
			return null;
		}
		$rows = BizCity_Business_JSONL_File_Store::query( self::CONTRACT_ID, array(
			'blog_id' => get_current_blog_id(),
			'limit' => 5000,
			'days' => 3650,
		) );
		foreach ( $rows as $row ) {
			if ( (int) ( $row['legacy_id'] ?? 0 ) === (int) $id ) {
				return $this->normalize( $row );
			}
		}
		return null;
	}

	public function get_by_session( $session_id, $platform_type = '' ) {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — resolve by tenant, platform and stable session identity.
		$session_id = sanitize_text_field( (string) $session_id );
		if ( ! self::available() || $session_id === '' ) {
			return null;
		}
		$platform_type = $this->normalize_platform( $session_id, $platform_type );
		$key = $this->cache_key( $session_id, $platform_type );
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}
		$rows = BizCity_Business_JSONL_File_Store::query( self::CONTRACT_ID, array(
			'blog_id' => get_current_blog_id(),
			'limit' => 5000,
			'days' => 3650,
		) );
		$state = null;
		foreach ( $rows as $row ) {
			if ( (string) ( $row['session_id'] ?? '' ) === $session_id && (string) ( $row['platform_type'] ?? '' ) === $platform_type ) {
				$state = $this->normalize( $row );
				break;
			}
		}
		self::$cache[ $key ] = $state;
		return $state;
	}

	public function list_for_user( $user_id, $platform_type = null, $limit = 30, $project_id = null, $status = 'active' ) {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — list bounded owner-scoped session records without SQL metadata queries.
		if ( ! self::available() ) {
			return array();
		}
		$args = array(
			'blog_id' => get_current_blog_id(),
			'limit' => max( 1, min( 5000, (int) $limit * 3 ) ),
			'days' => 3650,
		);
		if ( null !== $user_id && (int) $user_id > 0 ) {
			$args['user_id'] = (int) $user_id;
		}
		if ( $platform_type ) {
			$args['platform_type'] = $this->normalize_platform( '', $platform_type );
		}
		$rows = BizCity_Business_JSONL_File_Store::query( self::CONTRACT_ID, $args );
		$out = array();
		foreach ( $rows as $row ) {
			$state = $this->normalize( $row );
			if ( $platform_type && (string) $state->platform_type !== $this->normalize_platform( '', $platform_type ) ) {
				continue;
			}
			if ( $status !== 'all' && (string) $state->status !== (string) $status ) {
				continue;
			}
			if ( $project_id !== null && (string) $state->project_id !== (string) $project_id ) {
				continue;
			}
			$out[] = $state;
		}
		usort( $out, function ( $left, $right ) {
			return strcmp( (string) $right->last_message_at . (string) $right->started_at, (string) $left->last_message_at . (string) $left->started_at );
		} );
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	public function list_by_project( $project_id, $limit = 50 ) {
		return $this->list_for_user( null, null, $limit, $project_id, 'all' );
	}

	public function update( $id, $data ) {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — fold session lifecycle mutations into the encrypted state record.
		$state = $this->get_by_id( $id );
		if ( ! $state ) {
			return false;
		}
		$record = (array) $state;
		$allowed = array( 'title', 'title_generated', 'project_id', 'character_id', 'status', 'rolling_summary', 'summary_updated_at', 'context_tokens', 'message_count', 'last_message_at', 'last_message_preview', 'ended_at', 'meta', 'kci_ratio', 'session_memory_mode', 'session_focus_summary', 'session_open_loops', 'session_next_actions', 'session_memory_updated_at', 'context_layers_snapshot' );
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$record[ $key ] = $data[ $key ];
			}
		}
		$record['updated_at'] = current_time( 'mysql' );
		$ok = $this->write( $record );
		if ( $ok ) {
			self::$cache[ $this->cache_key( $record['session_id'], $record['platform_type'] ) ] = $this->normalize( $record );
		}
		return $ok;
	}

	public function update_by_session( $session_id, $data, $platform_type = '' ) {
		$state = $this->get_by_session( $session_id, $platform_type );
		if ( ! $state ) {
			return false;
		}
		return $this->update( $state->id, $data );
	}

	public function update_message_stats( $session_id, $data ) {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — update counters/title/preview after message SQL is committed, without session SQL.
		$platform_type = isset( $data['platform_type'] ) ? $data['platform_type'] : '';
		$state = $this->get_by_session( $session_id, $platform_type );
		if ( ! $state ) {
			$created = $this->create_for_session( $session_id, $data['user_id'] ?? get_current_user_id(), $data['client_name'] ?? '', $platform_type ?: 'WEBCHAT' );
			$state = $this->get_by_id( $created['id'] );
		}
		if ( ! $state ) {
			return false;
		}
		$update = array(
			'message_count' => (int) $state->message_count + 1,
			'last_message_at' => current_time( 'mysql' ),
			'last_message_preview' => mb_substr( (string) ( $data['message_text'] ?? '' ), 0, 200 ),
		);
		if ( ( $data['message_from'] ?? 'user' ) === 'user' && empty( $state->title ) && ! (int) $state->title_generated ) {
			$update['title'] = $this->generate_title( (string) ( $data['message_text'] ?? '' ) );
			$update['title_generated'] = 1;
		}
		return $this->update( $state->id, $update );
	}

	public function delete( $id ) {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — delete session state by tombstone, leaving message ownership to WebChat message cleanup.
		$state = $this->get_by_id( $id );
		if ( ! $state || ! self::available() ) {
			return false;
		}
		$ok = BizCity_Business_JSONL_File_Store::delete( self::CONTRACT_ID, $state->record_id, array(
			'blog_id' => get_current_blog_id(),
			'user_id' => (int) $state->user_id,
			'session_id' => (string) $state->session_id,
			'platform_type' => (string) $state->platform_type,
		) );
		unset( self::$cache[ $this->cache_key( $state->session_id, $state->platform_type ) ] );
		return $ok;
	}

	public function close_all( $user_id, $platform_type = 'ADMINCHAT' ) {
		$count = 0;
		foreach ( $this->list_for_user( $user_id, $platform_type, 5000, null, 'active' ) as $state ) {
			if ( $this->update( $state->id, array( 'status' => 'closed', 'ended_at' => current_time( 'mysql' ) ) ) ) {
				$count++;
			}
		}
		return $count;
	}

	public function record_id( $session_id, $platform_type = '' ) {
		$scope = (string) get_current_blog_id() . '|' . $this->normalize_platform( $session_id, $platform_type ) . '|' . (string) $session_id;
		if ( class_exists( 'BizCity_Codec' ) && function_exists( 'wp_salt' ) ) {
			return 'wbst_' . BizCity_Codec::hmac_sha256( $scope, wp_salt( 'auth' ), false );
		}
		return 'wbst_' . hash( 'sha256', $scope );
	}

	private function defaults( $session_id, $user_id, $client_name, $platform_type, $title, $data ) {
		return array(
			'record_id' => $this->record_id( $session_id, $platform_type ),
			'record_kind' => 'session_state',
			'legacy_id' => $this->new_legacy_id(),
			'blog_id' => get_current_blog_id(),
			'session_id' => sanitize_text_field( (string) $session_id ),
			'user_id' => (int) $user_id,
			'client_name' => sanitize_text_field( (string) $client_name ),
			'platform_type' => $this->normalize_platform( $session_id, $platform_type ),
			'project_id' => sanitize_text_field( (string) ( $data['project_id'] ?? '' ) ),
			'character_id' => (int) ( $data['character_id'] ?? 0 ),
			'title' => sanitize_text_field( (string) $title ),
			'title_generated' => 0,
			'status' => 'active',
			'rolling_summary' => '',
			'summary_updated_at' => null,
			'context_tokens' => 0,
			'message_count' => 0,
			'last_message_at' => null,
			'last_message_preview' => '',
			'meta' => array(),
			'kci_ratio' => 80,
			'session_memory_mode' => 'off',
			'session_focus_summary' => '',
			'session_open_loops' => array(),
			'session_next_actions' => array(),
			'session_memory_updated_at' => null,
			'context_layers_snapshot' => array(),
			'started_at' => current_time( 'mysql' ),
			'ended_at' => null,
		);
	}

	private function write( array $record ) {
		if ( ! self::available() ) {
			return false;
		}
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::CONTRACT_ID, $record, 'upsert' );
		return is_array( $receipt );
	}

	private function normalize( $record ) {
		$defaults = $this->defaults( $record['session_id'] ?? '', $record['user_id'] ?? 0, $record['client_name'] ?? '', $record['platform_type'] ?? '', $record['title'] ?? '', $record );
		$record = array_merge( $defaults, (array) $record );
		$record['id'] = (int) $record['legacy_id'];
		$record['user_id'] = (int) $record['user_id'];
		$record['message_count'] = (int) $record['message_count'];
		$record['title_generated'] = (int) $record['title_generated'];
		$record['meta'] = is_array( $record['meta'] ) ? $record['meta'] : ( json_decode( (string) $record['meta'], true ) ?: array() );
		return (object) $record;
	}

	private function new_legacy_id() {
		return wp_rand( 100000000, 2147483000 );
	}

	private function normalize_platform( $session_id, $platform_type = '' ) {
		$platform_type = strtoupper( sanitize_key( (string) $platform_type ) );
		if ( in_array( $platform_type, array( 'ADMINCHAT', 'WEBCHAT', 'TWINCHAT', 'TWINCHAT_BE', 'WEBCHAT_GUEST' ), true ) ) {
			return $platform_type;
		}
		return strpos( (string) $session_id, 'adminchat_' ) === 0 ? 'ADMINCHAT' : 'WEBCHAT';
	}

	private function cache_key( $session_id, $platform_type ) {
		return (string) get_current_blog_id() . '|' . $this->normalize_platform( $session_id, $platform_type ) . '|' . (string) $session_id;
	}

	private function generate_title( $message ) {
		$message = trim( preg_replace( '/\s+/', ' ', (string) $message ) );
		if ( $message === '' ) {
			return 'Hội thoại mới';
		}
		if ( mb_strlen( $message ) <= 40 ) {
			return $message;
		}
		$short = mb_substr( $message, 0, 40 );
		$space = mb_strrpos( $short, ' ' );
		return mb_substr( $short, 0, $space > 20 ? $space : 40 ) . '...';
	}
}
