<?php
/**
 * Profile Gift Wheel provider contract and registry.
 *
 * @package Bizcity_Twin_AI
 */
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Profile_Wheel_Provider', false ) ) {
interface BizCity_Profile_Wheel_Provider {
	public function key();
	public function is_available();
	public function register_hooks( $callback );
	public function list_for_user( $user_id, $is_admin = false );
	public function can_use( $wheel_id, $user_id, $is_admin = false );
	public function member_can_use( $user_id, $owner_user_id, array $assigned_user_ids );
	public function assigned_user_ids( $wheel_id );
	public function set_assigned_user_ids( $wheel_id, array $user_ids );
	public function render( $wheel_id );
	public function stats( $wheel_id );
}
}

if ( ! class_exists( 'BizCity_Profile_Mabel_Wheel_Provider', false ) ) {
final class BizCity_Profile_Mabel_Wheel_Provider implements BizCity_Profile_Wheel_Provider {

	public function key() {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — identify the first wheel provider.
		return 'mabel';
	}

	public function is_available() {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — detect the optional vendor without making it a hard dependency.
		return class_exists( '\MABEL_WOF\\Code\\Services\\Wheel_service' );
	}

	public function register_hooks( $callback ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: keep vendor hook names inside the Mabel adapter.
		if ( $this->is_available() ) { add_action( 'wof_play', $callback, 10, 1 ); }
	}

	public function list_for_user( $user_id, $is_admin = false ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — list only published wheels permitted for this member.
		if ( ! $this->is_available() ) { return array(); }
		$items = array();
		foreach ( (array) \MABEL_WOF\Code\Services\Wheel_service::get_all_wheels() as $wheel ) {
			$wheel_id = absint( is_object( $wheel ) ? ( $wheel->id ?? 0 ) : ( $wheel['id'] ?? 0 ) );
			if ( $wheel_id <= 0 || ! $this->can_use( $wheel_id, $user_id, $is_admin ) ) { continue; }
			$post = get_post( $wheel_id );
			$items[] = array( 'provider' => $this->key(), 'id' => $wheel_id, 'label' => $post ? (string) $post->post_title : 'Wheel #' . $wheel_id );
		}
		return $items;
	}

	public function can_use( $wheel_id, $user_id, $is_admin = false ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — enforce author or explicit many-to-many assignment ownership.
		$wheel_id = absint( $wheel_id );
		$user_id = (int) $user_id;
		$post = $wheel_id > 0 ? get_post( $wheel_id ) : null;
		if ( ! $post || 'publish' !== $post->post_status ) { return false; }
		if ( $is_admin || current_user_can( 'manage_options' ) ) { return true; }
		return $this->member_can_use( $user_id, (int) $post->post_author, $this->assigned_user_ids( $wheel_id ) );
	}

	public function member_can_use( $user_id, $owner_user_id, array $assigned_user_ids ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — express shared-wheel owner isolation as a deterministic provider rule.
		$user_id = (int) $user_id;
		return $user_id > 0 && ( $user_id === (int) $owner_user_id || in_array( $user_id, array_map( 'intval', $assigned_user_ids ), true ) );
	}

	public function assigned_user_ids( $wheel_id ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — read the provider-owned assignment list.
		$assigned = get_post_meta( absint( $wheel_id ), '_bizcity_profile_wheel_users', true );
		$assigned = is_string( $assigned ) ? json_decode( $assigned, true ) : $assigned;
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $assigned ) ? $assigned : array() ) ) ) );
	}

	public function set_assigned_user_ids( $wheel_id, array $user_ids ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — persist a normalized assignment list through provider storage.
		$wheel_id = absint( $wheel_id );
		if ( ! $wheel_id || ! $this->can_use( $wheel_id, get_current_user_id(), true ) ) { return false; }
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( empty( $user_ids ) ) {
			delete_post_meta( $wheel_id, '_bizcity_profile_wheel_users' );
			return true;
		}
		return $this->assigned_user_ids( $wheel_id ) === $user_ids || false !== update_post_meta( $wheel_id, '_bizcity_profile_wheel_users', wp_json_encode( $user_ids ) );
	}

	public function render( $wheel_id ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — render through the vendor shortcode boundary only.
		$wheel_id = absint( $wheel_id );
		return $wheel_id > 0 && $this->is_available() && 'publish' === get_post_status( $wheel_id ) && shortcode_exists( 'wof_wheel' )
			? do_shortcode( '[wof_wheel id="' . $wheel_id . '"]' )
			: '';
	}

	public function stats( $wheel_id ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — project provider counters without creating a Profile campaign table.
		global $wpdb;
		$table = $wpdb->prefix . 'wof_optins';
		if ( ! $this->is_available() || ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) ) {
			return array( 'plays' => 0, 'wins' => 0, 'optins' => 0 );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS plays, SUM(CASE WHEN winning <> 0 THEN 1 ELSE 0 END) AS wins, SUM(CASE WHEN type = 0 THEN 1 ELSE 0 END) AS optins FROM `' . $table . '` WHERE wheel_id = %d', absint( $wheel_id ) ), ARRAY_A );
		return array( 'plays' => (int) ( $row['plays'] ?? 0 ), 'wins' => (int) ( $row['wins'] ?? 0 ), 'optins' => (int) ( $row['optins'] ?? 0 ) );
	}
}
}

if ( ! class_exists( 'BizCity_Profile_Wheel_Provider_Registry', false ) ) {
final class BizCity_Profile_Wheel_Provider_Registry {

	private static $providers = array();

	public static function register( BizCity_Profile_Wheel_Provider $provider ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — register provider adapters at file load time.
		self::$providers[ $provider->key() ] = $provider;
	}

	public static function get( $key ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — resolve providers by normalized public key.
		return self::$providers[ sanitize_key( (string) $key ) ] ?? null;
	}

	public static function default_key() {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — preserve legacy wheel content without coupling callers to a vendor key.
		foreach ( self::$providers as $key => $provider ) { return (string) $key; }
		return '';
	}

	public static function list_for_user( $user_id, $is_admin = false ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — aggregate provider catalogs while preserving provider identity.
		$items = array();
		foreach ( self::$providers as $provider ) { $items = array_merge( $items, (array) $provider->list_for_user( $user_id, $is_admin ) ); }
		return $items;
	}

	public static function register_hooks( $callback ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: let each installed provider attach its own play adapter.
		foreach ( self::$providers as $provider ) { $provider->register_hooks( $callback ); }
	}

	public static function can_use( $provider_key, $wheel_id, $user_id, $is_admin = false ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — delegate wheel permission checks to the selected provider.
		$provider = self::get( $provider_key );
		return $provider ? (bool) $provider->can_use( $wheel_id, $user_id, $is_admin ) : false;
	}

	public static function assigned_user_ids( $provider_key, $wheel_id ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — expose assignment reads without leaking provider storage details.
		$provider = self::get( $provider_key );
		return $provider ? (array) $provider->assigned_user_ids( $wheel_id ) : array();
	}

	public static function set_assigned_user_ids( $provider_key, $wheel_id, array $user_ids ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — delegate normalized assignment writes to the selected provider.
		$provider = self::get( $provider_key );
		return $provider ? (bool) $provider->set_assigned_user_ids( $wheel_id, $user_ids ) : false;
	}

	public static function render( $provider_key, $wheel_id ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — render through the selected provider adapter.
		$provider = self::get( $provider_key );
		return $provider ? (string) $provider->render( $wheel_id ) : '';
	}

	public static function stats( $provider_key, $wheel_id ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — return a stable zero-counter fallback for unavailable providers.
		$provider = self::get( $provider_key );
		return $provider ? (array) $provider->stats( $wheel_id ) : array( 'plays' => 0, 'wins' => 0, 'optins' => 0 );
	}
}
}

if ( class_exists( 'BizCity_Profile_Wheel_Provider_Registry', false )
	&& class_exists( 'BizCity_Profile_Mabel_Wheel_Provider', false )
	&& method_exists( 'BizCity_Profile_Wheel_Provider_Registry', 'get' )
	&& method_exists( 'BizCity_Profile_Wheel_Provider_Registry', 'register' )
	&& ! BizCity_Profile_Wheel_Provider_Registry::get( 'mabel' ) ) {
	// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — register Mabel even when a legacy loader supplied the registry class first.
	BizCity_Profile_Wheel_Provider_Registry::register( new BizCity_Profile_Mabel_Wheel_Provider() );
}
