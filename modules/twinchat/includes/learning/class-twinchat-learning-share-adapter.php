<?php
/**
 * Bizcity TwinChat — Learning Share-Link Adapter
 *
 * PHASE-0.48-LEARNING-LOG-SHARE-LINK — packages "generate a public, no-login
 * link to the learning console log of one notebook/source" as a small
 * reusable adapter so it can be called from:
 *   1) the TwinChat admin UI (Source drawer "Share" button — future FE work), and
 *   2) an Automation Template Workflow action block
 *      (see core/automation/includes/blocks/actions/class-action-learning-share-link.php)
 *      for the case-study flow: user sends `@ghichu` note + .doc/.xlsx
 *      attachment → capture_to_notebook ingests it → this adapter mints a
 *      link → the workflow replies to the user with that link so they can
 *      watch their own file being learned (cron or admin-ajax) without
 *      logging into wp-admin.
 *
 * Design goals:
 *   - STATELESS token: no new DB table/schema (nothing to add to R-DCL). The
 *     token itself carries `notebook_id` + `source_id` + `exp`, signed with
 *     an HMAC over `wp_salt('auth')` so it cannot be forged or extended
 *     client-side. Resolving it does NOT require a DB round-trip.
 *   - SCOPED: a token only ever unlocks the ONE (notebook_id, source_id)
 *     pair it was minted for — resolving it never returns other notebooks'
 *     data. The REST reader (class-twinchat-rest-learning.php::public_share_view())
 *     additionally filters raw log lines by `job=<id>` so a public link can
 *     never leak another job's console output (OWASP A01).
 *   - Reuses the EXISTING daily learning log file + stats parser
 *     (BizCity_TwinChat_REST_Learning::analyze_debug_log_file()) instead of
 *     inventing a second logging pipeline — see PHASE-0.44-LEARNING-LOG-DAILY-ANALYTICS.md.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since      2026-07-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Share_Adapter {

	/** Default link lifetime — 30 days, long enough for a slow document to finish learning + be reviewed. */
	const DEFAULT_TTL_S = 30 * DAY_IN_SECONDS;

	/** Hard ceiling so a workflow author can't accidentally mint a link that never expires. */
	const MAX_TTL_S = 180 * DAY_IN_SECONDS;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Mint a signed, stateless share token for one (notebook_id, source_id) pair.
	 *
	 * @param int   $notebook_id Required.
	 * @param int   $source_id   0 = "whole notebook" (all sources), otherwise scoped to one source/file.
	 * @param array $opts        { ttl_s?: int, job_id?: int (pin to one learning job instead of "latest") }
	 * @return array|WP_Error { token, url, notebook_slug, expires_at (UTC mysql), expires_ts }
	 */
	public function create_link( $notebook_id, $source_id = 0, array $opts = array() ) {
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'notebook_id required to mint a learning share link.' );
		}
		$source_id = max( 0, (int) $source_id );
		$job_id    = max( 0, (int) ( $opts['job_id'] ?? 0 ) );
		$ttl_s     = (int) ( $opts['ttl_s'] ?? self::DEFAULT_TTL_S );
		$ttl_s     = $ttl_s > 0 ? min( $ttl_s, self::MAX_TTL_S ) : self::DEFAULT_TTL_S;
		$exp       = time() + $ttl_s;

		$payload = array(
			'nb'  => $notebook_id,
			'sid' => $source_id,
			'job' => $job_id,
			'exp' => $exp,
		);
		$token = $this->encode( $payload );
		$url   = $this->build_url( $token );
		$notebook_slug = $this->resolve_notebook_slug( $notebook_id );
		if ( $source_id > 0 && $notebook_slug !== '' ) {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R3 — expose the stable notebook slug on every source-scoped share link.
			$url = add_query_arg( array( 'nb_slug' => $notebook_slug ), $url );
		}

		return array(
			'token'      => $token,
			'url'        => $url,
			'expires_at' => gmdate( 'Y-m-d H:i:s', $exp ),
			'expires_ts' => $exp,
			'notebook_id'=> $notebook_id,
			'source_id'  => $source_id,
			'job_id'     => $job_id,
			'notebook_slug' => $notebook_slug,
		);
	}

	/**
	 * Resolve the stable human-facing slug stored in notebook settings.
	 *
	 * @param int $notebook_id
	 * @return string
	 */
	private function resolve_notebook_slug( $notebook_id ) {
		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R3 — keep legacy notebooks linkable while new rows use settings.slug.
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return '';
		}
		$notebook = BizCity_KG_Notebook_Service::instance()->get( (int) $notebook_id );
		if ( ! is_array( $notebook ) ) {
			return '';
		}
		$settings = is_array( $notebook['settings'] ?? null ) ? $notebook['settings'] : array();
		$slug     = sanitize_title( (string) ( $settings['slug'] ?? '' ) );
		if ( $slug !== '' ) {
			return $slug;
		}

		$slug = sanitize_title( (string) ( $notebook['name'] ?? '' ) );
		return $slug !== '' ? $slug : 'nb-' . (int) $notebook_id;
	}

	/**
	 * Validate + decode a share token minted by {@see create_link()}.
	 *
	 * @param string $token
	 * @return array|WP_Error { notebook_id, source_id, job_id, expires_ts }
	 */
	public function resolve_token( $token ) {
		$token = trim( (string) $token );
		if ( $token === '' ) {
			return new WP_Error( 'token_missing', 'Thiếu link theo dõi.', array(
				'hint'      => 'Yêu cầu link chia sẻ mới từ Twin GPT.',
				'help_code' => 'learning_share_token_missing',
			) );
		}

		$parts = explode( '.', $token, 2 );
		if ( count( $parts ) !== 2 ) {
			return new WP_Error( 'token_invalid', 'Link theo dõi không hợp lệ.', array(
				'hint'      => 'Kiểm tra lại đường link được gửi, có thể đã bị cắt bớt.',
				'help_code' => 'learning_share_token_invalid',
			) );
		}

		list( $body_b64, $sig ) = $parts;
		$expected_sig = $this->sign( $body_b64 );
		if ( ! hash_equals( $expected_sig, $sig ) ) {
			return new WP_Error( 'token_invalid', 'Link theo dõi không hợp lệ hoặc đã bị chỉnh sửa.', array(
				'hint'      => 'Yêu cầu link chia sẻ mới từ Twin GPT.',
				'help_code' => 'learning_share_token_invalid',
			) );
		}

		// [2026-08-20 Johnny Chu] CODEC-CORE — delegate share token URL-safe JSON decoding.
		$json = BizCity_Codec::base64url_decode( $body_b64 );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || empty( $data['nb'] ) ) {
			return new WP_Error( 'token_invalid', 'Link theo dõi không hợp lệ.', array(
				'hint'      => 'Yêu cầu link chia sẻ mới từ Twin GPT.',
				'help_code' => 'learning_share_token_invalid',
			) );
		}

		$exp = (int) ( $data['exp'] ?? 0 );
		if ( $exp > 0 && $exp < time() ) {
			return new WP_Error( 'token_expired', 'Link theo dõi đã hết hạn.', array(
				'hint'      => 'Yêu cầu link chia sẻ mới từ Twin GPT.',
				'help_code' => 'learning_share_token_expired',
			) );
		}

		return array(
			'notebook_id' => (int) $data['nb'],
			'source_id'   => (int) ( $data['sid'] ?? 0 ),
			'job_id'      => (int) ( $data['job'] ?? 0 ),
			'expires_ts'  => $exp,
		);
	}

	/**
	 * Public URL for a token. Filterable so sites that mount TwinChat under a
	 * different front-end route (or a future dedicated `/hoc/` slug) can
	 * override without touching this class.
	 */
	public function build_url( $token ) {
		$rest_url = rest_url( ( defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : 'bizcity-twinchat/v1' ) . '/learning/share/' . rawurlencode( $token ) );
		// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK — until the
		// dedicated public FE page ships, the link resolves directly to the
		// REST JSON payload. Once modules/twinchat/ui ships a `/hoc/{token}`
		// public route, switch the default here via this filter without
		// breaking already-sent links (token format is unchanged).
		return (string) apply_filters( 'bizcity_twinchat_learning_share_url', $rest_url, $token );
	}

	protected function encode( array $payload ) {
		$json    = wp_json_encode( $payload );
		// [2026-08-20 Johnny Chu] CODEC-CORE — preserve share token wire format through shared Base64URL/HMAC primitives.
		$body_b64 = BizCity_Codec::base64url_encode( $json );
		return $body_b64 . '.' . $this->sign( $body_b64 );
	}

	protected function sign( $body_b64 ) {
		// Same HMAC family already used for the passage_worker internal token
		// (wp_hash()) — no new secret to manage/rotate.
		return substr( BizCity_Codec::hmac_sha256( (string) $body_b64, wp_salt( 'auth' ), false ), 0, 40 );
	}

	/** Restore base64 padding stripped during encode() (URL-safe tokens omit it). */
	protected function pad_b64( $s ) {
		$s = (string) $s;
		$mod = strlen( $s ) % 4;
		if ( $mod > 0 ) {
			$s .= str_repeat( '=', 4 - $mod );
		}
		return $s;
	}
}
