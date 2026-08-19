<?php
/**
 * TwinWeb — MPR citation to canonical source ledger bridge.
 *
 * Queues public web citations emitted by TwinBrain and persists them through
 * the existing TwinChat/KG source ingest contract in the owning notebook.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Citation_Source_Persistence', false ) ) {
	return;
}

class BizCity_TwinWeb_Citation_Source_Persistence {

	const ACTION_READY = 'bizcity_twinbrain_citations_ready';
	const ACTION_RUN   = 'bizcity_twinweb_persist_web_citations';
	const MAX_CITATIONS = 8;

	public static function init() {
		add_action( self::ACTION_READY, array( __CLASS__, 'queue' ), 20, 4 );
		add_action( self::ACTION_RUN, array( __CLASS__, 'run' ), 10, 1 );
	}

	/**
	 * Queue one bounded citation batch without delaying the chat response.
	 */
	public static function queue( $trace_id, $session_id, $citations, $opts ) {
		// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — persist only notebook-scoped web citations; never guess a tenant or fallback to blog 1.
		if ( ! is_array( $opts ) || ! is_array( $citations ) ) {
			return;
		}

		$notebook_id = (int) ( $opts['notebook_id'] ?? 0 );
		$user_id     = (int) ( $opts['user_id'] ?? get_current_user_id() );
		if ( $notebook_id <= 0 || $user_id <= 0 ) {
			return;
		}

		$normalized = self::normalize_citations( $citations );
		if ( empty( $normalized ) ) {
			return;
		}

		$payload = array(
			'notebook_id' => $notebook_id,
			'user_id'     => $user_id,
			'trace_id'    => sanitize_text_field( (string) $trace_id ),
			'session_id'  => sanitize_text_field( (string) $session_id ),
			'surface'     => sanitize_key( (string) ( $opts['surface'] ?? '' ) ),
			'web_mode'    => sanitize_key( (string) ( $opts['web_mode'] ?? '' ) ),
			'citations'   => $normalized,
		);

		// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — avoid duplicate single events when one turn emits both stream and replay callbacks.
		if ( false === wp_next_scheduled( self::ACTION_RUN, array( $payload ) ) ) {
			wp_schedule_single_event( time() + 5, self::ACTION_RUN, array( $payload ) );
		}
	}

	/**
	 * Ingest queued web URLs through the existing source service.
	 */
	public static function run( $payload ) {
		if ( ! is_array( $payload ) ) {
			return;
		}

		$notebook_id = (int) ( $payload['notebook_id'] ?? 0 );
		$user_id     = (int) ( $payload['user_id'] ?? 0 );
		$citations   = isset( $payload['citations'] ) && is_array( $payload['citations'] ) ? $payload['citations'] : array();
		if ( $notebook_id <= 0 || $user_id <= 0 || empty( $citations ) ) {
			return;
		}

		$service = self::source_service();
		if ( ! $service || ! self::owns_notebook( $notebook_id, $user_id ) ) {
			self::note_event( 'web_citation_persist_refused', array( 'reason' => 'notebook_access_denied' ) );
			return;
		}

		$counters = array(
			'citation_count' => count( $citations ),
			'persisted'      => 0,
			'duplicates'     => 0,
			'failed'         => 0,
		);

		foreach ( $citations as $citation ) {
			$url = (string) ( $citation['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}

			try {
				$result = $service->ingest( $notebook_id, $user_id, array(
					'type'     => 'url',
					'title'    => (string) ( $citation['title'] ?? $url ),
					'url'      => $url,
					'metadata' => array(
						'origin'          => 'twinbrain_mpr',
						'auto_discovered' => true,
						'trace_id'        => (string) ( $payload['trace_id'] ?? '' ),
						'session_id'      => (string) ( $payload['session_id'] ?? '' ),
						'surface'         => (string) ( $payload['surface'] ?? '' ),
						'web_mode'        => (string) ( $payload['web_mode'] ?? '' ),
						'citation_token'  => (string) ( $citation['token'] ?? '' ),
					),
				) );
			} catch ( \Throwable $e ) {
				$result = new WP_Error( 'source_ingest_exception', 'Source ingest exception.' );
			}

			if ( is_wp_error( $result ) ) {
				$counters['failed']++;
				self::note_event( 'web_citation_ingest_error', array( 'reason' => 'source_ingest_error' ) );
			} elseif ( ! empty( $result['duplicate'] ) ) {
				$counters['duplicates']++;
			} else {
				$counters['persisted']++;
			}
		}

		self::note( $counters );
	}

	private static function normalize_citations( array $citations ) {
		$out  = array();
		$seen = array();
		foreach ( $citations as $citation ) {
			if ( is_string( $citation ) ) {
				$citation = array( 'url' => $citation );
			}
			if ( ! is_array( $citation ) ) {
				continue;
			}

			$url = (string) ( $citation['web_url'] ?? $citation['url'] ?? $citation['origin_url'] ?? '' );
			$url = esc_url_raw( trim( $url ) );
			$parts = wp_parse_url( $url );
			if ( '' === $url || ! is_array( $parts ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				continue;
			}
			$key = strtolower( $url );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[] = array(
				'url'   => $url,
				'title' => sanitize_text_field( (string) ( $citation['web_title'] ?? $citation['title'] ?? $url ) ),
				'token' => sanitize_text_field( (string) ( $citation['token'] ?? $citation['citation'] ?? '' ) ),
			);
			if ( count( $out ) >= self::MAX_CITATIONS ) {
				break;
			}
		}
		return $out;
	}

	private static function source_service() {
		return class_exists( 'BizCity_TwinChat_Sources_Service' )
			? BizCity_TwinChat_Sources_Service::instance()
			: null;
	}

	private static function owns_notebook( $notebook_id, $user_id ) {
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return false;
		}
		$notebook = BizCity_KG_Notebook_Service::instance()->get( (int) $notebook_id );
		return is_array( $notebook ) && (int) ( $notebook['owner_id'] ?? 0 ) === (int) $user_id;
	}

	private static function note( array $counters ) {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note( array( 'web_citations' => $counters ) );
		}
	}

	private static function note_event( $event, array $context ) {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note_event( $event, $context );
		}
	}
}
