<?php
/**
 * TwinBrain Astro Subject Profile Service.
 *
 * Canonical subject profile builder shared by TwinBrain runtime and
 * Automation astro actions.
 *
 * Cache Contract:
 *   group: bcpro (shared)
 *   key  : astro_subject_profile_v1_{coachee_id}_{hash}
 *   key  : astro_subject_context_v1_{coachee_id}_{hash}
 *   ttl  : BizCity_Cache::TTL_MEDIUM
 *   inv  : hash-based auto-rotation; explicit flush is optional.
 *
 * [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — unified subject profile
 * service for astro surfaces.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Astro_Subject_Profile_Service {

	const CACHE_GROUP = 'bcpro';
	const CACHE_VER   = 'v1';

	private static $instance = null;

	/**
	 * @return BizCity_TwinBrain_Astro_Subject_Profile_Service
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resolve canonical subject profile by user (self-coachee first).
	 *
	 * @param int $user_id
	 * @return array
	 */
	public function resolve_by_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return $this->empty_result( 'astro_subject_user_missing' );
		}

		$coachee_row = $this->resolve_self_coachee_row( $user_id );
		if ( ! is_array( $coachee_row ) || empty( $coachee_row['id'] ) ) {
			return $this->empty_result( 'astro_coachee_not_found' );
		}

		return $this->resolve_by_coachee(
			(int) $coachee_row['id'],
			$user_id,
			(string) ( $coachee_row['full_name'] ?? '' )
		);
	}

	/**
	 * Resolve canonical subject profile by explicit coachee.
	 *
	 * @param int    $coachee_id
	 * @param int    $user_id
	 * @param string $name_hint
	 * @return array
	 */
	public function resolve_by_coachee( $coachee_id, $user_id = 0, $name_hint = '' ) {
		$coachee_id = (int) $coachee_id;
		$user_id    = (int) $user_id;
		$name_hint  = (string) $name_hint;

		if ( $coachee_id <= 0 ) {
			return $this->empty_result( 'astro_coachee_not_found' );
		}

		$coachee_row = $this->load_coachee_row( $coachee_id );
		if ( ! is_array( $coachee_row ) ) {
			return $this->empty_result( 'astro_coachee_not_found' );
		}

		$coachee_name = trim( (string) ( $coachee_row['full_name'] ?? '' ) );
		if ( $coachee_name === '' ) {
			$coachee_name = trim( $name_hint );
		}

		$birth = $this->extract_birth( $coachee_row );
		$systems = ! empty( $birth['date'] ) ? array( 'western', 'vedic' ) : array();

		$natal_chart_url = '';
		if ( function_exists( 'bcpro_get_astro_public_url' ) ) {
			$natal_chart_url = (string) bcpro_get_astro_public_url( $coachee_id, 'western' );
		}
		if ( $natal_chart_url === '' && function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			$natal_chart_url = (string) bccm_get_natal_chart_public_url( $coachee_id );
		}

		$astro_row = $this->load_western_astro_row( $coachee_id );
		$fingerprint = $this->build_fingerprint( $coachee_row, $astro_row, $natal_chart_url );
		$cache_profile_key = 'astro_subject_profile_' . self::CACHE_VER . '_' . $coachee_id . '_' . $fingerprint;
		$cache_context_key = 'astro_subject_context_' . self::CACHE_VER . '_' . $coachee_id . '_' . $fingerprint;

		$cached_profile = $this->cache_get( $cache_profile_key );
		$cached_context = $this->cache_get( $cache_context_key );
		if ( is_string( $cached_profile ) && $cached_profile !== '' && is_string( $cached_context ) ) {
			return array(
				'success'            => true,
				'user_id'            => $user_id,
				'coachee_id'         => $coachee_id,
				'coachee_name'       => $coachee_name,
				'systems_available'  => $systems,
				'birth'              => $birth,
				'natal_chart_url'    => $natal_chart_url,
				'natal_profile_md'   => $cached_profile,
				'transit_context_md' => $cached_context,
				'source'             => 'cache',
				'_degraded'          => empty( $birth['date'] ) ? 'astro_birth_data_missing' : null,
			);
		}

		$summary_arr = $this->json_decode_array( isset( $astro_row['summary'] ) ? $astro_row['summary'] : '' );
		$traits_arr  = $this->json_decode_array( isset( $astro_row['traits'] ) ? $astro_row['traits'] : '' );

		$natal_profile_md = $this->build_natal_profile_md(
			$coachee_name,
			$birth,
			$summary_arr,
			$traits_arr,
			$natal_chart_url
		);
		$transit_context_md = $this->build_transit_context_md(
			$coachee_name,
			$birth,
			$summary_arr,
			$traits_arr,
			$natal_chart_url
		);

		$this->cache_set( $cache_profile_key, $natal_profile_md );
		$this->cache_set( $cache_context_key, $transit_context_md );

		return array(
			'success'            => true,
			'user_id'            => $user_id,
			'coachee_id'         => $coachee_id,
			'coachee_name'       => $coachee_name,
			'systems_available'  => $systems,
			'birth'              => $birth,
			'natal_chart_url'    => $natal_chart_url,
			'natal_profile_md'   => $natal_profile_md,
			'transit_context_md' => $transit_context_md,
			'source'             => 'fresh',
			'_degraded'          => empty( $birth['date'] ) ? 'astro_birth_data_missing' : null,
		);
	}

	/**
	 * @param string $reason
	 * @return array
	 */
	private function empty_result( $reason ) {
		return array(
			'success'            => false,
			'user_id'            => 0,
			'coachee_id'         => 0,
			'coachee_name'       => '',
			'systems_available'  => array(),
			'birth'              => array(),
			'natal_chart_url'    => '',
			'natal_profile_md'   => '',
			'transit_context_md' => '',
			'source'             => 'degraded',
			'_degraded'          => (string) $reason,
		);
	}

	/**
	 * @param int $user_id
	 * @return array|null
	 */
	private function resolve_self_coachee_row( $user_id ) {
		if ( function_exists( 'bccm_get_self_coachee' ) ) {
			$row = bccm_get_self_coachee( $user_id );
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				return $row;
			}
		}
		if ( function_exists( 'bccm_get_or_create_user_coachee' ) ) {
			$row = bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' );
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @param int $coachee_id
	 * @return array|null
	 */
	private function load_coachee_row( $coachee_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_coachees';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $table ) ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $coachee_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int $coachee_id
	 * @return array
	 */
	private function load_western_astro_row( $coachee_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_astro';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $table ) ) {
			return array();
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT summary, traits FROM {$table} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
				$coachee_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}

	/**
	 * @param array $row
	 * @return array
	 */
	private function extract_birth( array $row ) {
		$birth = array();
		$map = array(
			'dob'         => 'date',
			'birth_date'  => 'date',
			'birth_time'  => 'time',
			'birth_place' => 'place',
			'birth_tz'    => 'tz',
		);
		foreach ( $map as $col => $key ) {
			if ( ! empty( $row[ $col ] ) && empty( $birth[ $key ] ) ) {
				$birth[ $key ] = (string) $row[ $col ];
			}
		}
		return $birth;
	}

	/**
	 * @param array  $coachee_row
	 * @param array  $astro_row
	 * @param string $natal_chart_url
	 * @return string
	 */
	private function build_fingerprint( array $coachee_row, array $astro_row, $natal_chart_url ) {
		$seed = array(
			'dob'      => (string) ( $coachee_row['dob'] ?? '' ),
			'btime'    => (string) ( $coachee_row['birth_time'] ?? '' ),
			'bplace'   => (string) ( $coachee_row['birth_place'] ?? '' ),
			'btz'      => (string) ( $coachee_row['birth_tz'] ?? '' ),
			'summary'  => (string) ( $astro_row['summary'] ?? '' ),
			'traits'   => (string) ( $astro_row['traits'] ?? '' ),
			'natalUrl' => (string) $natal_chart_url,
		);
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $seed, JSON_UNESCAPED_UNICODE )
			: json_encode( $seed );
		return substr( md5( (string) $json ), 0, 16 );
	}

	/**
	 * @param mixed $raw
	 * @return array
	 */
	private function json_decode_array( $raw ) {
		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param string $coachee_name
	 * @param array  $birth
	 * @param array  $summary
	 * @param array  $traits
	 * @param string $natal_chart_url
	 * @return string
	 */
	private function build_natal_profile_md( $coachee_name, array $birth, array $summary, array $traits, $natal_chart_url ) {
		$lines = array();
		$lines[] = '### Ho so natal chu the';
		if ( $coachee_name !== '' ) {
			$lines[] = '- Chu the: **' . $coachee_name . '**';
		}
		if ( ! empty( $birth['date'] ) ) {
			$lines[] = '- Ngay sinh: ' . (string) $birth['date'];
		}
		if ( ! empty( $birth['time'] ) ) {
			$lines[] = '- Gio sinh: ' . (string) $birth['time'];
		}
		if ( ! empty( $birth['place'] ) ) {
			$lines[] = '- Noi sinh: ' . (string) $birth['place'];
		}

		$sun  = (string) ( $summary['sun_sign'] ?? '' );
		$moon = (string) ( $summary['moon_sign'] ?? '' );
		$asc  = (string) ( $summary['ascendant_sign'] ?? '' );
		if ( $sun !== '' || $moon !== '' || $asc !== '' ) {
			$big3 = array();
			if ( $sun !== '' ) { $big3[] = 'Sun ' . $sun; }
			if ( $moon !== '' ) { $big3[] = 'Moon ' . $moon; }
			if ( $asc !== '' ) { $big3[] = 'ASC ' . $asc; }
			$lines[] = '- Big 3: ' . implode( ' | ', $big3 );
		}

		$aspect_summary = $this->build_aspect_summary_line( $traits );
		if ( $aspect_summary !== '' ) {
			$lines[] = '- Signature natal: ' . $aspect_summary;
		}

		if ( $natal_chart_url !== '' ) {
			$lines[] = '- Nguon natal: [astro:natal#' . $natal_chart_url . ']';
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param string $coachee_name
	 * @param array  $birth
	 * @param array  $summary
	 * @param array  $traits
	 * @param string $natal_chart_url
	 * @return string
	 */
	private function build_transit_context_md( $coachee_name, array $birth, array $summary, array $traits, $natal_chart_url ) {
		$lines = array();
		$lines[] = '### Context natal cho luan giai transit';
		if ( $coachee_name !== '' ) {
			$lines[] = '- Nguoi duoc luan: **' . $coachee_name . '**';
		}
		if ( ! empty( $birth['date'] ) ) {
			$lines[] = '- Moc thoi gian goc: ' . (string) $birth['date']
				. ( ! empty( $birth['time'] ) ? ' ' . (string) $birth['time'] : '' );
		}
		$core = array();
		if ( ! empty( $summary['sun_sign'] ) ) {
			$core[] = 'Sun ' . (string) $summary['sun_sign'];
		}
		if ( ! empty( $summary['moon_sign'] ) ) {
			$core[] = 'Moon ' . (string) $summary['moon_sign'];
		}
		if ( ! empty( $summary['ascendant_sign'] ) ) {
			$core[] = 'ASC ' . (string) $summary['ascendant_sign'];
		}
		if ( ! empty( $core ) ) {
			$lines[] = '- Truc tinh cach co loi: ' . implode( ', ', $core );
		}
		$aspect_summary = $this->build_aspect_summary_line( $traits );
		if ( $aspect_summary !== '' ) {
			$lines[] = '- Mau nang luong natal can doi chieu voi transit: ' . $aspect_summary;
		}
		if ( $natal_chart_url !== '' ) {
			$lines[] = '- Citation natal bat buoc khi can: [astro:natal#' . $natal_chart_url . ']';
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param array $traits
	 * @return string
	 */
	private function build_aspect_summary_line( array $traits ) {
		$aspects = array();
		if ( isset( $traits['aspects'] ) && is_array( $traits['aspects'] ) ) {
			$aspects = $traits['aspects'];
		}
		if ( empty( $aspects ) ) {
			return '';
		}
		$parts = array();
		foreach ( $aspects as $a ) {
			if ( ! is_array( $a ) ) { continue; }
			$left  = trim( (string) ( $a['planet_1'] ?? $a['from'] ?? '' ) );
			$right = trim( (string) ( $a['planet_2'] ?? $a['to'] ?? '' ) );
			$type  = trim( (string) ( $a['aspect'] ?? $a['aspect_type'] ?? '' ) );
			if ( $left === '' || $right === '' || $type === '' ) { continue; }
			$parts[] = $left . ' ' . $type . ' ' . $right;
			if ( count( $parts ) >= 2 ) { break; }
		}
		return implode( '; ', $parts );
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	private function cache_get( $key ) {
		if ( class_exists( 'BizCity_Cache' ) ) {
			return BizCity_Cache::get( self::CACHE_GROUP, $key );
		}
		return false;
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @return void
	 */
	private function cache_set( $key, $value ) {
		if ( ! class_exists( 'BizCity_Cache' ) ) {
			return;
		}
		$ttl = defined( 'BizCity_Cache::TTL_MEDIUM' )
			? BizCity_Cache::TTL_MEDIUM
			: 300;
		BizCity_Cache::set( self::CACHE_GROUP, $key, (string) $value, $ttl );
	}
}
