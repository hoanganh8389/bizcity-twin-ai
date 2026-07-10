<?php
/**
 * TwinBrain — Skill `ingest_document` (Phase 3.5-WC P6).
 *
 * Cho phép admin/NV dán text hoặc URL vào chat với Guru và yêu cầu ingest
 * vào notebook đang active. Skill class = 'P' (Producer) — mặc định cần
 * `allow_producer = 1` trong admin-chat grant.
 *
 * Khi Admin Chat Policy trả về `confirm` (class D mới cần HIL), skip và
 * gửi confirm message. Khi trả về `allow`, ingest ngay.
 *
 * Trigger text pattern (handled by Tool_Intent_Matcher):
 *   #ingest <url>
 *   #ingest <text>
 *   ingest_document <url|text>
 *
 * Args từ LLM (JSON Schema bên dưới):
 *   type          : 'text' | 'url'   (default: auto-detect)
 *   content       : plain text to ingest (khi type=text)
 *   url           : URL to scrape (khi type=url)
 *   title         : optional title override
 *   notebook_id   : optional notebook override (default: ctx['guru_id']'s attached notebook)
 *
 * @tool_class P   (Producer — không reversible, content vào KG)
 * @package BizCity_Twin_CRM
 * @since   PHASE 3.5-WC
 */

defined( 'ABSPATH' ) || exit;

class BizCity_TwinBrain_Tool_Ingest_Document implements BizCity_Twin_Tool {

	public function name(): string {
		return 'ingest_document';
	}

	/**
	 * tool_class returns 'P' (Producer). Runtime calls this if method exists.
	 * Policy gate uses it to apply 'allow_producer' check on admin-chat grant.
	 */
	public function tool_class(): string {
		return 'P';
	}

	public function description(): string {
		return 'Ingest a text passage or URL into the active notebook (Knowledge Graph). '
			. 'Use when the user explicitly asks to "add", "ingest", "lưu vào notebook", '
			. '"học từ URL/text". Do NOT use for search or retrieval.';
	}

	public function parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'        => array(
					'type'        => 'string',
					'enum'        => array( 'text', 'url' ),
					'description' => '"text" to ingest plain text, "url" to scrape a URL.',
				),
				'content'     => array(
					'type'        => 'string',
					'description' => 'Plain text content to ingest (required when type=text).',
				),
				'url'         => array(
					'type'        => 'string',
					'description' => 'URL to scrape and ingest (required when type=url).',
				),
				'title'       => array(
					'type'        => 'string',
					'description' => 'Optional title for the document.',
				),
				'notebook_id' => array(
					'type'        => 'integer',
					'description' => 'Notebook (scope) ID. Defaults to the guru\'s attached notebook.',
				),
			),
			'required'   => array( 'type' ),
		);
	}

	public function execute( array $args, array $context ): array {
		// [2026-06-13 Johnny Chu] PHASE-0.40 G3 P6 — ingest_document tool execute

		$type    = (string) ( $args['type']    ?? '' );
		$content = (string) ( $args['content'] ?? '' );
		$url     = (string) ( $args['url']     ?? '' );
		$title   = (string) ( $args['title']   ?? '' );

		// Auto-detect type when caller omits.
		if ( $type === '' ) {
			$type = ( strncmp( $content, 'http', 4 ) === 0 || strncmp( $url, 'http', 4 ) === 0 )
				? 'url'
				: 'text';
		}

		// Resolve notebook_id: explicit arg > guru's attached notebook.
		$notebook_id = (int) ( $args['notebook_id'] ?? $context['notebook_id'] ?? 0 );
		$guru_id     = (int) ( $context['guru_id']   ?? 0 );
		$user_id     = (int) ( $context['user_id']   ?? get_current_user_id() );

		if ( ! $notebook_id && $guru_id > 0 ) {
			$notebook_id = $this->resolve_notebook_from_guru( $guru_id );
		}

		if ( ! $notebook_id ) {
			return array(
				'ok'      => false,
				'error'   => 'no_notebook',
				'summary' => '',
				'result'  => null,
			);
		}

		if ( ! class_exists( 'BizCity_KG_Facade' ) ) {
			return array(
				'ok'      => false,
				'error'   => 'kg_facade_missing',
				'summary' => '',
				'result'  => null,
			);
		}

		$scope   = array( 'plugin' => 'kg_hub', 'scope_id' => $notebook_id );
		$payload = array( 'type' => $type );

		if ( $title !== '' ) {
			$payload['title'] = $title;
		}

		if ( $type === 'url' ) {
			$raw_url = $url !== '' ? $url : $content;
			// Basic URL validation — no user-facing output of raw value.
			if ( ! filter_var( $raw_url, FILTER_VALIDATE_URL ) ) {
				return array(
					'ok'      => false,
					'error'   => 'invalid_url',
					'summary' => '',
					'result'  => null,
				);
			}
			$payload['url']  = esc_url_raw( $raw_url );
			$payload['title'] = $payload['title'] ?? $raw_url;
		} else {
			// type = text
			if ( $content === '' ) {
				return array(
					'ok'      => false,
					'error'   => 'content_empty',
					'summary' => '',
					'result'  => null,
				);
			}
			if ( mb_strlen( $content ) > 100000 ) {
				$content = mb_substr( $content, 0, 100000 );
			}
			$payload['content'] = $content;
			$payload['title']   = $payload['title'] ?? mb_substr( $content, 0, 80 ) . '…';
		}

		$result = BizCity_KG_Facade::ingest( $scope, $payload );

		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'error'   => $result->get_error_code() . ': ' . $result->get_error_message(),
				'summary' => '',
				'result'  => null,
			);
		}

		$source_id   = (int) ( $result['source_id']  ?? 0 );
		$chunk_count = (int) ( $result['chunk_count'] ?? 0 );

		$display = $type === 'url'
			? 'URL ' . ( isset( $payload['url'] ) ? '(' . substr( $payload['url'], 0, 60 ) . '…)' : '' )
			: '"' . mb_substr( $payload['title'] ?? '', 0, 60 ) . '"';

		$summary = sprintf(
			'✅ Đã ingest %s vào notebook #%d — %d passages.',
			$display,
			$notebook_id,
			$chunk_count
		);

		// [2026-06-13 Johnny Chu] PHASE-0.40 G3 P8 — fire action for notification center
		do_action( 'bizcity_ingest_document_complete', $guru_id, $user_id, $notebook_id, array(
			'source_id'   => $source_id,
			'chunk_count' => $chunk_count,
			'type'        => $type,
			'title'       => $payload['title'] ?? '',
		) );

		return array(
			'ok'           => true,
			'summary'      => $summary,
			'result'       => array(
				'source_id'   => $source_id,
				'notebook_id' => $notebook_id,
				'chunk_count' => $chunk_count,
				'type'        => $type,
			),
			'citation_ids' => $source_id > 0 ? array( 'src:S' . $source_id ) : array(),
		);
	}

	/**
	 * Resolve the first notebook attached to a guru character.
	 * Reads `notebook_id` column from `wp_bizcity_characters` (set by Guru admin).
	 */
	private function resolve_notebook_from_guru( int $guru_id ): int {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_characters';
		if ( ! bizcity_tbl_exists( $tbl ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return 0;
		}
		$nb = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM `{$tbl}` WHERE id=%d LIMIT 1",
			$guru_id
		) );
		return $nb;
	}
}
