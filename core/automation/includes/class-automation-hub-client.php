<?php
/**
 * BizCity_Automation_Hub_Client — proxy client sang Hub BizCity
 * cho automation workflow template library (Branch #17).
 *
 * Trạng thái: STUB — Branch #17 chưa được build trên hub server.
 * Khi hub ready, thay `_stub_response()` bằng call thật qua BizCity_LLM_Client.
 *
 * Pattern: R-GW-8 compliant — KHÔNG gọi bizcity.vn trực tiếp từ FE.
 * FE → bizcity-automation/v1/hub-templates/* → (PHP proxy) → bizcity.vn/bizcity/v1/automation-templates/*
 *
 * [2026-06-16 Johnny Chu] PHASE-ATH W2 — Hub client stub.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Automation_Hub_Client {

	/**
	 * Hub API endpoint base path (on bizcity.vn).
	 * Used when Branch #17 is ready.
	 */
	const HUB_PATH = '/wp-json/bizcity/v1/automation-templates';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ─── Public API ──────────────────────────────────────────────────────────

	/**
	 * Browse hub templates.
	 *
	 * @param array{category?:string,plan?:string,search?:string,page?:int,per_page?:int} $args
	 * @return array{_degraded:bool,rows:array,total:int,categories:array}
	 */
	public function browse( array $args = array() ) {
		// [2026-06-16 Johnny Chu] PHASE-ATH W2 — stub until Branch #17 exists on hub.
		if ( ! $this->is_hub_ready() ) {
			return $this->_degraded( 'Hub endpoint chưa được deploy (Branch #17 pending).' );
		}

		$qs = http_build_query( array_filter( array(
			'category' => isset( $args['category'] ) ? (string) $args['category'] : '',
			'plan'     => isset( $args['plan'] )     ? (string) $args['plan']     : '',
			'search'   => isset( $args['search'] )   ? (string) $args['search']   : '',
			'page'     => isset( $args['page'] )     ? (int)    $args['page']     : 1,
			'per_page' => isset( $args['per_page'] ) ? (int)    $args['per_page'] : 18,
		) ) );

		$resp = $this->_hub_get( self::HUB_PATH . '?' . $qs );
		if ( $resp['_degraded'] ) {
			return $resp;
		}

		$data = $resp['data'];
		return array(
			'_degraded'  => false,
			'rows'       => isset( $data['templates'] ) ? (array) $data['templates'] : array(),
			'total'      => isset( $data['total'] )     ? (int)   $data['total']     : 0,
			'categories' => isset( $data['categories'] ) ? (array) $data['categories'] : array(),
		);
	}

	/**
	 * Get category list from hub.
	 *
	 * @return array{_degraded:bool,categories:array}
	 */
	public function categories() {
		// [2026-06-16 Johnny Chu] PHASE-ATH W2 — stub until Branch #17 exists.
		if ( ! $this->is_hub_ready() ) {
			return array( '_degraded' => true, 'categories' => array() );
		}

		$resp = $this->_hub_get( self::HUB_PATH . '/categories' );
		if ( $resp['_degraded'] ) {
			return array( '_degraded' => true, 'categories' => array() );
		}

		return array(
			'_degraded'  => false,
			'categories' => isset( $resp['data']['categories'] ) ? (array) $resp['data']['categories'] : array(),
		);
	}

	/**
	 * Get detail for one hub template including graph_json.
	 *
	 * @param int $hub_id
	 * @return array{_degraded:bool,template:array|null}
	 */
	public function get_detail( $hub_id ) {
		// [2026-06-16 Johnny Chu] PHASE-ATH W2 — stub.
		if ( ! $this->is_hub_ready() ) {
			return array( '_degraded' => true, 'template' => null );
		}

		$resp = $this->_hub_get( self::HUB_PATH . '/' . (int) $hub_id );
		if ( $resp['_degraded'] ) {
			return array( '_degraded' => true, 'template' => null );
		}

		return array(
			'_degraded' => false,
			'template'  => isset( $resp['data']['template'] ) ? (array) $resp['data']['template'] : null,
		);
	}

	/**
	 * Submit a local template to hub for community sharing.
	 *
	 * @param array $payload { slug, name, description, category, trigger_type, tags, graph_json }
	 * @return array{_degraded:bool,hub_id:int,status:string}
	 */
	public function submit( array $payload ) {
		// [2026-06-16 Johnny Chu] PHASE-ATH W2 — stub.
		if ( ! $this->is_hub_ready() ) {
			return array( '_degraded' => true, 'hub_id' => 0, 'status' => 'not_submitted' );
		}

		$resp = $this->_hub_post( self::HUB_PATH, $payload );
		if ( $resp['_degraded'] ) {
			return array( '_degraded' => true, 'hub_id' => 0, 'status' => 'submit_failed' );
		}

		$data = $resp['data'];
		return array(
			'_degraded' => false,
			'hub_id'    => isset( $data['id'] ) ? (int) $data['id'] : 0,
			'status'    => isset( $data['status'] ) ? (string) $data['status'] : 'pending_review',
		);
	}

	// ─── Private helpers ─────────────────────────────────────────────────────

	/**
	 * Whether the hub client is configured and hub Branch #17 is reachable.
	 * Currently always false until Branch #17 is deployed.
	 *
	 * @return bool
	 */
	private function is_hub_ready() {
		// [2026-06-16 Johnny Chu] PHASE-ATH W5 — Branch #17 deployed. Use real BizCity_LLM_Client readiness.
		return class_exists( 'BizCity_LLM_Client' ) && BizCity_LLM_Client::instance()->is_ready();
	}

	/**
	 * GET request to hub. Returns ['_degraded'=>true] on any failure.
	 *
	 * @param string $path Full path after gateway URL.
	 * @return array{_degraded:bool,data:array}
	 */
	private function _hub_get( $path ) {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array( '_degraded' => true, 'data' => array() );
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return array( '_degraded' => true, 'data' => array() );
		}

		$url  = rtrim( (string) $client->get_gateway_url(), '/' ) . $path;
		$resp = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $client->get_api_key(),
				'Accept'        => 'application/json',
			),
		) );

		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) >= 400 ) {
			return array( '_degraded' => true, 'data' => array() );
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			return array( '_degraded' => true, 'data' => array() );
		}

		return array( '_degraded' => false, 'data' => $body );
	}

	/**
	 * POST request to hub. Returns ['_degraded'=>true] on any failure.
	 *
	 * @param string $path
	 * @param array  $payload
	 * @return array{_degraded:bool,data:array}
	 */
	private function _hub_post( $path, array $payload ) {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array( '_degraded' => true, 'data' => array() );
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return array( '_degraded' => true, 'data' => array() );
		}

		$url  = rtrim( (string) $client->get_gateway_url(), '/' ) . $path;
		$resp = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $client->get_api_key(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) >= 400 ) {
			return array( '_degraded' => true, 'data' => array() );
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			return array( '_degraded' => true, 'data' => array() );
		}

		return array( '_degraded' => false, 'data' => $body );
	}

	/**
	 * Build a degraded-OPEN response for browse().
	 *
	 * @param string $reason
	 * @return array
	 */
	private function _degraded( $reason = '' ) {
		return array(
			'_degraded'  => true,
			'_reason'    => $reason,
			'rows'       => array(),
			'total'      => 0,
			'categories' => array(),
		);
	}

	/**
	 * Bulk upsert templates to Hub — idempotent, status='published' auto-set by server.
	 *
	 * [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — Called from seeder (main site only) after
	 * seeding local table. Pushes all builtin templates to Hub so sub-sites can browse via HubTemplateTab
	 * instead of relying on per-blog seeded copies.
	 *
	 * POST /bizcity/v1/automation-templates/bulk (admin Bearer)
	 *
	 * @param array $templates Array of template rows. Each row: { slug, name, description, category,
	 *                          tags[], plan, trigger_type, author, graph_json }
	 * @return array{ _degraded:bool, inserted:int, updated:int, skipped:int }
	 */
	public function sync_bulk( array $templates ) {
		if ( empty( $templates ) ) {
			return array( '_degraded' => false, 'inserted' => 0, 'updated' => 0, 'skipped' => 0 );
		}
		if ( ! $this->is_hub_ready() ) {
			return array( '_degraded' => true, '_reason' => 'Hub not ready.', 'inserted' => 0, 'updated' => 0, 'skipped' => 0 );
		}

		// Send in chunks of 100 to stay within request size limits
		$chunk_size = 100;
		$total_in   = 0;
		$total_up   = 0;
		$total_skip = 0;

		$chunks = array_chunk( $templates, $chunk_size );
		foreach ( $chunks as $chunk ) {
			$resp = $this->_hub_post( self::HUB_PATH . '/bulk', array( 'templates' => $chunk ) );
			if ( $resp['_degraded'] ) {
				return array( '_degraded' => true, '_reason' => 'bulk POST failed.', 'inserted' => $total_in, 'updated' => $total_up, 'skipped' => $total_skip );
			}
			$data        = isset( $resp['data']['data'] ) ? $resp['data']['data'] : ( isset( $resp['data'] ) ? $resp['data'] : array() );
			$total_in   += (int) ( $data['inserted'] ?? 0 );
			$total_up   += (int) ( $data['updated']  ?? 0 );
			$total_skip += (int) ( $data['skipped']  ?? 0 );
		}

		return array(
			'_degraded' => false,
			'inserted'  => $total_in,
			'updated'   => $total_up,
			'skipped'   => $total_skip,
		);
	}
}
