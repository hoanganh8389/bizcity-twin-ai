<?php
/**
 * Bizcity Twin AI — KG_Triplet_Extractor
 *
 * Calls an LLM (via OpenAI API key from twf_openai_api_key option, same as
 * existing chat-api.php) to extract knowledge-graph triplets from a passage,
 * then enqueues them into the review queue.
 *
 * Caches by passage content hash to avoid re-billing.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Triplet_Extractor {

	const MODEL_OPTION = 'bizcity_kg_extract_model';
	const DEFAULT_MODEL = 'gpt-4o-mini';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Extract triplets for a single passage and enqueue them.
	 *
	 * @return int|WP_Error number of triplets enqueued
	 */
	public function extract_passage( $passage_id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, content, content_hash, extraction_status
			 FROM {$db->tbl_passages()} WHERE id = %d",
			(int) $passage_id
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Passage not found' );
		}
		if ( $row['extraction_status'] === 'done' ) {
			return 0;
		}

		// Cache by content_hash (request-cheap, transient survives 24h).
		$cache_key = 'bizcity_kg_extract_' . $row['content_hash'];
		$cached    = get_transient( $cache_key );

		if ( $cached !== false && is_array( $cached ) ) {
			$triplets = $cached;
			$raw      = '[cache]';
		} else {
			// Phase 0.5 Sprint 1 — Cost Guard quota check before billable LLM call.
			if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
				$guard = BizCity_KG_Cost_Guard::instance()->can_extract( get_current_user_id(), 1 );
				if ( is_wp_error( $guard ) ) {
					$wpdb->update( $db->tbl_passages(), [
						'extraction_status' => 'skipped',
						'extraction_error'  => $guard->get_error_message(),
					], [ 'id' => (int) $passage_id ] );
					return $guard;
				}
			}

		$wpdb->update( $db->tbl_passages(), [ 'extraction_status' => 'processing' ], [ 'id' => (int) $passage_id ] );

			$result = $this->call_llm( $row['content'] );
			if ( is_wp_error( $result ) ) {
				// PHASE-0.13 Wave 10d — transient throttling (429/5xx) must NOT
				// burn the passage as a hard error. Flip it back to `pending`
				// so the next tick retries naturally.
				// 'truncated': JSON was cut at max_tokens and nothing salvageable —
				// also transient (will retry; larger passages may succeed with higher
				// max_tokens or when the model is less verbose).
				$is_transient = in_array( $result->get_error_code(), [ 'rate_limited', 'truncated' ], true );
				$wpdb->update( $db->tbl_passages(), [
					'extraction_status' => $is_transient ? 'pending' : 'error',
					'extraction_error'  => $result->get_error_message(),
				], [ 'id' => (int) $passage_id ] );
				/** PHASE-0.7 Wave 0 — surface extraction errors to learning stream. */
				do_action( 'bizcity_kg_extraction_passage_error', [
					'notebook_id' => (int) $row['notebook_id'],
					'passage_id'  => (int) $passage_id,
					'error'       => $result->get_error_message(),
					'transient'   => $is_transient,
				] );
				return $result;
			}
			$triplets = $result['triplets'];
			$raw      = $result['raw'];
			set_transient( $cache_key, $triplets, DAY_IN_SECONDS );

			// Record usage AFTER successful LLM call.
			if ( class_exists( 'BizCity_KG_Cost_Guard' ) && ! empty( $result['usage'] ) ) {
				BizCity_KG_Cost_Guard::instance()->record_usage( [
					'user_id'       => get_current_user_id(),
					'operation'     => BizCity_KG_Cost_Guard::OP_EXTRACT,
					'notebook_id'   => (int) $row['notebook_id'],
					'passage_id'    => (int) $passage_id,
					'input_tokens'  => (int) ( $result['usage']['prompt_tokens']     ?? 0 ),
					'output_tokens' => (int) ( $result['usage']['completion_tokens'] ?? 0 ),
				] );
			}
		}

		$count = BizCity_KG_Graph_Service::instance()->enqueue_triplets(
			(int) $row['notebook_id'],
			(int) $passage_id,
			$triplets,
			$raw
		);

		// Log sample triplets so ops can verify extraction quality without querying DB.
		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			$samples = array_slice( (array) $triplets, 0, 5 );
			$sample_str = implode( ' | ', array_map( static function ( $t ) {
				return sprintf( '%s →[%s]→ %s (%.2f)',
					$t['subject']   ?? '?',
					$t['predicate'] ?? '?',
					$t['object']    ?? '?',
					(float) ( $t['confidence'] ?? 0 )
				);
			}, $samples ) );
			BizCity_Twin_Debug::trace( 'kg', 'extract_passage_enqueued', [
				'passage_id'  => (int) $passage_id,
				'nb'          => (int) $row['notebook_id'],
				'count'       => $count,
				'cache_hit'   => ( $raw === '[cache]' ),
				'sample'      => $sample_str,
			] );
		}

		$wpdb->update( $db->tbl_passages(), [
			'extraction_status' => 'done',
			'extraction_error'  => '',
		], [ 'id' => (int) $passage_id ] );

		/**
		 * PHASE-0.7 Wave 0 — broadcast per-passage extraction progress.
		 * Twinchat learning bridge listens and pushes to tc_learning_events so
		 * the SSE stream surfaces "đang extract triplet" without coupling
		 * KG-Hub to the TwinChat module.
		 */
		do_action( 'bizcity_kg_extraction_passage_done', [
			'notebook_id' => (int) $row['notebook_id'],
			'passage_id'  => (int) $passage_id,
			'triplets'    => (int) $count,
			'cache_hit'   => ( $raw === '[cache]' ),
		] );

		return $count;
	}

	/**
	 * Extract for all pending passages of a notebook (sync; cap at $limit).
	 *
	 * @param int  $notebook_id
	 * @param int  $limit
	 * @param bool $force If true, reset 'done' passages back to 'pending' before extracting.
	 *                    Useful to re-populate the Knowledge Graph after passages were inserted
	 *                    with extraction_status='done' (e.g. migration or accidental skip).
	 * @return array{processed:int, total_triplets:int, errors:int}
	 */
	public function extract_notebook_pending( $notebook_id, $limit = 10, $force = false ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		// Keep work alive even if Cloudflare cuts the client at ~100s (524 Origin Timeout).
		@set_time_limit( 0 );
		@ignore_user_abort( true );

		// Hard time budget for THIS request — bail out before CF kills us so the
		// HTTP response carries a useful body (FE then re-issues the call to resume).
		$time_budget_s = (int) apply_filters( 'bizcity_kg_extract_time_budget_seconds', 75 );
		$started_at    = microtime( true );

		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'extract_start', [
				'notebook_id'   => (int) $notebook_id,
				'limit'         => (int) $limit,
				'force'         => (bool) $force,
				'time_budget_s' => $time_budget_s,
			] );
		}

		// Force-mode: reset 'done' passages so the extractor picks them up.
		if ( $force ) {
			$reset_n = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$db->tbl_passages()}
				 SET extraction_status='pending'
				 WHERE notebook_id=%d AND extraction_status='done'",
				(int) $notebook_id
			) );
			// PHASE-0.13 Wave 10c — record the reset so the evidence trail
			// can prove a force-reset (not the bug) caused the next loop.
			if ( class_exists( 'BizCity_KG_Source_Progress_Log' ) && $reset_n > 0 ) {
				BizCity_KG_Source_Progress_Log::record( [
					'notebook_id' => (int) $notebook_id,
					'event'       => 'force_reset',
					'payload'     => [ 'reset_count' => $reset_n ],
				] );
			}
		}

		// Include 'processing' rows stuck for >5 min (PHP timeout mid-flight leaves them orphaned).
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_passages()}
			 WHERE notebook_id=%d
			   AND (
			         extraction_status IN ('pending','error')
			         OR (
			              extraction_status = 'processing'
			              AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
			            )
			       )
			 ORDER BY created_at ASC LIMIT %d",
			(int) $notebook_id, (int) $limit
		) );

		$total_to_process = count( $ids );
		$total = 0; $errors = 0; $processed = 0; $time_exceeded = false;
		// PHASE-0.13 Wave 10d — track transient (rate-limited) failures separately
		// from real errors and bail out the batch after 3 consecutive throttles
		// so we don't burn the entire CF time budget hammering a 429 endpoint.
		$throttled       = 0;
		$consec_throttle = 0;
		$throttle_bail   = false;
		foreach ( $ids as $i => $pid ) {
			// Stop early if we'd risk hitting the CF 524 wall mid-flight.
			$elapsed_s = microtime( true ) - $started_at;
			if ( $elapsed_s >= $time_budget_s ) {
				$time_exceeded = true;
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'kg', 'extract_time_budget_hit', [
						'notebook_id' => (int) $notebook_id,
						'elapsed_s'   => round( $elapsed_s, 1 ),
						'remaining'   => $total_to_process - $i,
					] );
				}
				break;
			}
			if ( $consec_throttle >= 3 ) {
				$throttle_bail = true;
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'kg', 'extract_throttle_bail', [
						'notebook_id'      => (int) $notebook_id,
						'throttled_so_far' => $throttled,
						'remaining'        => $total_to_process - $i,
					] );
				}
				break;
			}
			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'kg', 'extract_passage_start', [
					'notebook_id' => (int) $notebook_id,
					'passage_id'  => (int) $pid,
					'idx'         => $i + 1,
					'of'          => $total_to_process,
				] );
			}
			$ppt0 = microtime( true );
			$res = $this->extract_passage( (int) $pid );
			if ( is_wp_error( $res ) ) {
				$is_transient = ( $res->get_error_code() === 'rate_limited' );
				if ( $is_transient ) {
					$throttled++;
					$consec_throttle++;
				} else {
					$errors++;
					$consec_throttle = 0;
				}
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'kg', 'extract_passage_error', [
						'notebook_id' => (int) $notebook_id,
						'passage_id'  => (int) $pid,
						'error'       => $res->get_error_message(),
						'transient'   => $is_transient,
						'elapsed_ms'  => (int) round( ( microtime( true ) - $ppt0 ) * 1000 ),
					] );
				}
			} else {
				$total += (int) $res;
				$processed++;
				$consec_throttle = 0;
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'kg', 'extract_passage_done', [
						'notebook_id' => (int) $notebook_id,
						'passage_id'  => (int) $pid,
						'triplets'    => (int) $res,
						'elapsed_ms'  => (int) round( ( microtime( true ) - $ppt0 ) * 1000 ),
					] );
				}
			}
		}

		// Count remaining pending so the FE can decide whether to call again.
		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$db->tbl_passages()}
			 WHERE notebook_id=%d
			   AND extraction_status IN ('pending','error','processing')",
			(int) $notebook_id
		) );

		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'extract_done', [
				'notebook_id'    => (int) $notebook_id,
				'processed'      => $processed,
				'total_triplets' => $total,
				'errors'         => $errors,
				'throttled'      => $throttled,
				'throttle_bail'  => $throttle_bail,
				'remaining'      => $remaining,
				'time_exceeded'  => $time_exceeded,
				'elapsed_s'      => round( microtime( true ) - $started_at, 1 ),
			] );
		}

		// PHASE-0.13 Wave 10d — taxonomy of batch outcome so the FE can render
		// a meaningful banner instead of a silent "0% forever":
		//   ok        — at least 1 passage done, 0 hard errors
		//   partial   — some done, some hard errors
		//   throttled — bailed out due to consecutive 429s (no real failure)
		//   failed    — nothing processed and only hard errors
		//   idle      — nothing to do (no candidates)
		if ( $total_to_process === 0 ) {
			$batch_status = 'idle';
		} elseif ( $throttle_bail || ( $processed === 0 && $throttled > 0 && $errors === 0 ) ) {
			$batch_status = 'throttled';
		} elseif ( $processed > 0 && $errors === 0 ) {
			$batch_status = 'ok';
		} elseif ( $processed > 0 ) {
			$batch_status = 'partial';
		} else {
			$batch_status = 'failed';
		}

		/**
		 * PHASE-0.7 Wave 0 — broadcast batch completion (1 cron tick or manual run).
		 * Drives the "🟢 Realtime / 🌙 Background" status pill in TwinShell hub.
		 */
		do_action( 'bizcity_kg_extraction_batch_done', [
			'notebook_id'    => (int) $notebook_id,
			'processed'      => (int) $processed,
			'total_triplets' => (int) $total,
			'errors'         => (int) $errors,
			'throttled'      => (int) $throttled,
			'throttle_bail'  => (bool) $throttle_bail,
			'remaining'      => (int) $remaining,
			'time_exceeded'  => (bool) $time_exceeded,
			'elapsed_s'      => (float) round( microtime( true ) - $started_at, 1 ),
			'status'         => $batch_status,
		] );

		return [
			'processed'      => $processed,
			'total_triplets' => $total,
			'errors'         => $errors,
			'throttled'      => $throttled,
			'remaining'      => $remaining,
			'time_exceeded'  => $time_exceeded,
			'status'         => $batch_status,
		];
	}

	// ─── LLM call ──────────────────────────────────────────────────────────

	/**
	 * @return array{triplets:array, raw:string}|WP_Error
	 */
	private function call_llm( $passage ) {
		// PHASE-0.13 Wave 10d.2 — RULE: mọi LLM call PHẢI đi qua BizCity LLM
		// Router (BUSINESS-MODEL §4.1, PHASE-0-RULE-SMART-GATEWAY-MIGRATION).
		// Trước đây class này gọi thẳng api.openai.com bằng `twf_openai_api_key`
		// → vi phạm rule, bypass billing/usage log, không hưởng được fallback
		// model + retry của router, và 429 storm không được tier-aware throttle.
		// Giờ chuyển hẳn sang BizCity_LLM_Client. Endpoint cuối cùng được
		// chọn bởi router (purpose=extract → model mapping ở Hub).
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new WP_Error(
				'no_router',
				'BizCity_LLM_Client missing — KG extractor requires the LLM Router gateway.'
			);
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return new WP_Error(
				'no_router_key',
				'BizCity LLM Router API key chưa được cấu hình (option `bizcity_llm_api_key`).'
			);
		}

		$prompt_tpl = @file_get_contents( BIZCITY_KG_HUB_PROMPTS . 'extract-triplets.txt' );
		if ( ! $prompt_tpl ) {
			return new WP_Error( 'no_prompt', 'Extraction prompt template missing' );
		}
		$prompt = str_replace( '{{PASSAGE}}', $passage, $prompt_tpl );

		// Model: option override -> router default for `extract` purpose.
		$model = get_option( self::MODEL_OPTION, '' );
		if ( $model === '' ) {
			// Let router pick default model for this purpose.
			$model = $client->get_model( 'extract' ) ?: self::DEFAULT_MODEL;
		}

		// Concurrency probe (giữ lại để trace burst).
		$concurrency_key = 'bizcity_kg_extract_inflight';
		$inflight_before = (int) get_transient( $concurrency_key );
		set_transient( $concurrency_key, $inflight_before + 1, 60 );

		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'extract_llm_call', [
				'model'        => $model,
				'transport'    => 'router_gateway',
				'gateway_url'  => $client->get_gateway_url(),
				'inflight'     => $inflight_before + 1,
				'passage_len'  => mb_strlen( (string) $passage ),
			] );
		}
		$llm_t0 = microtime( true );

		$messages = [
			[ 'role' => 'system', 'content' => 'You output strict JSON only.' ],
			[ 'role' => 'user',   'content' => $prompt ],
		];

		$result = $client->chat( $messages, [
			'purpose'     => 'extract',
			'model'       => $model,
			'temperature' => 0.1,
			'max_tokens'  => 4096,
			'timeout'     => 60,
			// `response_format` is forwarded verbatim to the gateway via
			// extra_body so OpenAI-compatible providers (gpt-4o-mini,
			// claude, etc.) honour strict JSON.
			'extra_body'  => [
				'response_format' => [ 'type' => 'json_object' ],
			],
		] );

		// Release in-flight slot.
		$inflight_after = max( 0, (int) get_transient( $concurrency_key ) - 1 );
		if ( $inflight_after > 0 ) { set_transient( $concurrency_key, $inflight_after, 60 ); }
		else { delete_transient( $concurrency_key ); }

		// Router returned an error envelope.
		if ( empty( $result['success'] ) ) {
			$err_msg     = (string) ( $result['error'] ?? 'router error' );
			$is_quota    = ! empty( $result['quota_exhausted'] );
			// Router surfaces 429 by returning success=false + error containing
			// 'rate', 'quota', 'monthly', etc. We treat all of these as
			// transient → caller flips passage back to `pending`. The router
			// itself is responsible for picking when to escalate (and for
			// switching to a fallback model on its side).
			$is_rate     = $is_quota
				|| stripos( $err_msg, 'rate' )    !== false
				|| stripos( $err_msg, '429' )     !== false
				|| stripos( $err_msg, 'quota' )   !== false
				|| stripos( $err_msg, 'monthly' ) !== false;

			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'kg', $is_rate ? 'extract_llm_throttled' : 'extract_llm_error', [
					'transport'      => 'router_gateway',
					'model'          => $result['model'] ?? $model,
					'fallback_used'  => ! empty( $result['fallback_used'] ),
					'quota_exhausted'=> $is_quota,
					'error'          => mb_substr( $err_msg, 0, 300 ),
					'inflight'       => (int) get_transient( 'bizcity_kg_extract_inflight' ),
					'elapsed_ms'     => (int) round( ( microtime( true ) - $llm_t0 ) * 1000 ),
				] );
			}

			if ( $is_rate ) {
				return new WP_Error(
					'rate_limited',
					'Router ' . ( $is_quota ? 'quota_exhausted' : 'rate_limited' ) . ' — ' . mb_substr( $err_msg, 0, 160 ),
					[
						'transport'       => 'router_gateway',
						'quota_exhausted' => $is_quota,
					]
				);
			}
			return new WP_Error( 'router_error', $err_msg, [ 'transport' => 'router_gateway' ] );
		}

		$raw   = (string) ( $result['message'] ?? '' );
		$usage = is_array( $result['usage'] ?? null ) ? $result['usage'] : [];

		$tokens_out = (int) ( $usage['completion_tokens'] ?? 0 );
		$truncated  = $tokens_out >= 4096;
		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'extract_llm_done', [
				'transport'     => 'router_gateway',
				'model'         => $result['model'] ?? $model,
				'fallback_used' => ! empty( $result['fallback_used'] ),
				'elapsed_ms'    => (int) round( ( microtime( true ) - $llm_t0 ) * 1000 ),
				'raw_len'       => mb_strlen( $raw ),
				'tokens_in'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'tokens_out'    => $tokens_out,
				'truncated'     => $truncated,
			] );
			// Warn when LLM hits max_tokens — JSON is almost certainly cut off.
			if ( $truncated ) {
				BizCity_Twin_Debug::trace( 'kg', 'extract_llm_truncated', [
					'tokens_out' => $tokens_out,
					'raw_tail'   => mb_substr( $raw, -120 ),
				] );
			}
		}
		if ( $raw === '' ) {
			return new WP_Error( 'empty', 'Empty LLM response (via router)' );
		}

		// Gemini-2.5-flash often wraps JSON in markdown code fences (```json ... ```)
		// despite the prompt asking for raw JSON. Strip them defensively before
		// parsing so we don't burn LLM quota on `bad_json` retries.
		$clean = trim( $raw );
		if ( $clean !== '' && $clean[0] !== '{' && $clean[0] !== '[' ) {
			// Try to extract the first {...} or [...] block from the response.
			if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/s', $clean, $m ) ) {
				$clean = trim( $m[1] );
			} else {
				$first = strpos( $clean, '{' );
				$last  = strrpos( $clean, '}' );
				if ( $first !== false && $last !== false && $last > $first ) {
					$clean = substr( $clean, $first, $last - $first + 1 );
				}
			}
		}

		$parsed = json_decode( $clean, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['triplets'] ) ) {
			// Partial JSON recovery: when the response was truncated (tokens_out=max),
			// extract all complete triplet objects before the cut so we don’t waste
			// the valid data that DID arrive.
			if ( $truncated ) {
				$salvaged = $this->recover_partial_triplets( $clean );
				if ( ! empty( $salvaged ) ) {
					if ( class_exists( 'BizCity_Twin_Debug' ) ) {
						BizCity_Twin_Debug::trace( 'kg', 'extract_llm_partial_recovery', [
							'salvaged' => count( $salvaged ),
						] );
					}
					return [ 'triplets' => $salvaged, 'raw' => $raw, 'usage' => $usage, 'partial' => true ];
				}
				// Nothing salvageable — mark as pending so the next batch retries
				// with hopefully a less dense passage (or a model update).
				return new WP_Error( 'truncated', 'LLM response truncated and no valid triplets salvageable' );
			}
			return new WP_Error( 'bad_json', 'LLM returned invalid JSON', [ 'raw' => $raw ] );
		}
		return [ 'triplets' => $parsed['triplets'], 'raw' => $raw, 'usage' => $usage ];
	}

	/**
	 * Recover as many complete triplet objects as possible from a truncated JSON string.
	 *
	 * Strategy: find all complete `{...}` objects inside the `"triplets": [...]` array
	 * that have all three required keys (subject, predicate, object).
	 *
	 * @param string $raw Possibly-truncated JSON string.
	 * @return array Array of triplet associative arrays (may be empty).
	 */
	private function recover_partial_triplets( string $raw ): array {
		$salvaged = [];

		// Find the opening of the triplets array.
		$array_start = strpos( $raw, '[' );
		if ( $array_start === false ) {
			return [];
		}

		// Walk the string character-by-character, collecting balanced {...} objects.
		$depth   = 0;
		$obj_start = null;
		$len     = strlen( $raw );

		for ( $i = $array_start; $i < $len; $i++ ) {
			$ch = $raw[ $i ];
			if ( $ch === '{' ) {
				if ( $depth === 0 ) {
					$obj_start = $i;
				}
				$depth++;
			} elseif ( $ch === '}' ) {
				$depth--;
				if ( $depth === 0 && $obj_start !== null ) {
					$obj_json = substr( $raw, $obj_start, $i - $obj_start + 1 );
					$obj      = json_decode( $obj_json, true );
					if (
						is_array( $obj )
						&& isset( $obj['subject'], $obj['predicate'], $obj['object'] )
						&& trim( $obj['subject'] ) !== ''
						&& trim( $obj['predicate'] ) !== ''
						&& trim( $obj['object'] ) !== ''
					) {
						$salvaged[] = $obj;
					}
					$obj_start = null;
				}
			}
		}
		return $salvaged;
	}
}
