<?php
/**
 * BizCity Content Creator — Persona Tool Provider (Wave F7.0b, 2026-05-14).
 *
 * Bridges Content Creator artifacts (rows in `bizcity_creator_files` + chunk
 * metadata in `bizcity_creator_chunk_meta` joined to studio output content)
 * into the Notebook Persona pipeline so each generated content draft becomes
 * cite-able in subsequent MPR turns (R-MPRT §6.5 — Producer plugin contract).
 *
 * Role classification per R-MPRT §6.5:
 *   • `content_creator_execute` tool  → tool_class='producer' (artifact_created)
 *   • This provider                   → renders artifact rows into Passage[]
 *
 * Source kind owned: `content_creator_artifact` (R-PP-3).
 *
 * Passage layout per artifact:
 *   1. Overview  — title + template + outline summary (always)
 *   2..N. Per chunk_meta row — body = studio_outputs.content, metadata =
 *         { platform, stage_label, hashtags, cta_text }
 *
 * Citation anchor format: `persona:content_creator_artifact#<file_id>:<section>`
 *
 * @package    Bizcity_Content_Creator
 * @subpackage Persona
 * @since      0.1.29 (F7.0b)
 * @link       PHASE-0-RULE-MPR-THINKING.md §6.5 (Producer plugin)
 * @link       PHASE-0-RULE-PERSONA-PROVIDER.md (R-PP-1..R-PP-8)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BZCC_Persona_Provider' ) ) {
	return;
}

if ( ! class_exists( 'BizCity_Persona_Tool_Provider' ) ) {
	// Twin AI core not loaded — stay silent, registry won't pick us up either.
	return;
}

class BZCC_Persona_Provider extends BizCity_Persona_Tool_Provider {

	const KIND_ARTIFACT = 'content_creator_artifact';

	/* ──────────────────────────────────────────────────────────
	 * R-PP-1 — Identity
	 * ────────────────────────────────────────────────────────── */

	public function id(): string {
		return 'content-creator';
	}

	public function label(): string {
		return __( 'Content Creator — Bài viết & sáng tạo', 'bizcity-content-creator' );
	}

	public function version(): string {
		return '1.0.0';
	}

	/* ──────────────────────────────────────────────────────────
	 * R-PP-3 — Source kinds owned
	 * ────────────────────────────────────────────────────────── */

	public function get_source_kinds(): array {
		return array( self::KIND_ARTIFACT );
	}

	/* ──────────────────────────────────────────────────────────
	 * R-PP-5 — Tool definitions (declarative re-publish)
	 *
	 * Heavy logic lives in agents/register-content-agent.php (`content_creator_execute`).
	 * Persona only re-publishes minimal metadata so notebook UI / smart chips
	 * can locate and call the tool by name. R-MPRT §6.5: `tool_class=producer`.
	 * ────────────────────────────────────────────────────────── */

	public function get_tool_definitions(): array {
		return array(
			array(
				'name'          => 'content_creator_execute',
				'label'         => __( 'Tạo nội dung', 'bizcity-content-creator' ),
				'description'   => __( 'Sinh bài viết / kịch bản từ template Content Creator.', 'bizcity-content-creator' ),
				'tool_class'    => 'producer',
				'side_effect'   => 'write',
				'cost_class'    => 'medium',
				'required_caps' => array( 'read' ),
			),
		);
	}

	public function get_smart_source_chips(): array {
		return array(
			array(
				'tool'   => 'content_creator_execute',
				'label'  => __( 'Tạo nội dung từ template', 'bizcity-content-creator' ),
				'icon'   => '✍️',
				'action' => 'persona_artifact_dialog',
			),
		);
	}

	/* ──────────────────────────────────────────────────────────
	 * R-PP-1 / R-MPRT §6.5 — render artifact → Passage[]
	 *
	 * `$artifact` may arrive in two shapes:
	 *   1. A raw `bizcity_creator_files` row (ARRAY_A).
	 *   2. A synthetic payload from artifact_created (file_id + title + edit_url).
	 *
	 * We always re-fetch the file row + chunks for canonical content.
	 * ────────────────────────────────────────────────────────── */

	public function render_to_passages( string $kind, array $artifact ): array {
		if ( $kind !== self::KIND_ARTIFACT ) {
			return array();
		}

		$file_id = isset( $artifact['file_id'] )
			? (int) $artifact['file_id']
			: ( isset( $artifact['id'] ) ? (int) $artifact['id'] : 0 );
		if ( $file_id <= 0 ) {
			return array();
		}
		if ( ! class_exists( 'BZCC_File_Manager' ) || ! class_exists( 'BZCC_Chunk_Meta_Manager' ) ) {
			return array();
		}

		$file = BZCC_File_Manager::get_by_id( $file_id );
		if ( ! $file ) {
			return array();
		}

		$template_label = '';
		if ( class_exists( 'BZCC_Template_Manager' ) && (int) $file->template_id > 0 ) {
			$tpl = BZCC_Template_Manager::get_by_id( (int) $file->template_id );
			if ( $tpl ) {
				$template_label = (string) ( $tpl->title ?? $tpl->slug ?? '' );
			}
		}

		$base_meta = array(
			'kind'        => self::KIND_ARTIFACT,
			'artifact_id' => $file_id,
			'template_id' => (int) $file->template_id,
			'template'    => $template_label,
			'title'       => (string) $file->title,
		);

		$anchor = function ( $section ) use ( $file_id ) {
			return sprintf( 'persona:%s#%d:%s', self::KIND_ARTIFACT, $file_id, $section );
		};

		$passages = array();

		// 1) Overview passage — always emitted.
		$overview_lines = array(
			sprintf( '# %s', $file->title ?: __( 'Nội dung Content Creator', 'bizcity-content-creator' ) ),
		);
		if ( $template_label !== '' ) {
			$overview_lines[] = sprintf( '_Template:_ %s', $template_label );
		}
		if ( ! empty( $file->outline ) ) {
			$overview_lines[] = '';
			$overview_lines[] = '## Outline';
			$overview_lines[] = $this->trim_text( (string) $file->outline, 1200 );
		}

		$passages[] = array(
			'title'           => sprintf( '✍️ %s — Tổng quan', $file->title ?: 'Content draft' ),
			'body'            => trim( implode( "\n", $overview_lines ) ),
			'metadata'        => array_merge( $base_meta, array( 'section' => 'overview' ) ),
			'citation_anchor' => $anchor( 'overview' ),
		);

		// 2..N) Per-chunk passages (joined with studio_outputs.content).
		$chunks = BZCC_Chunk_Meta_Manager::get_by_file_with_content( $file_id );
		if ( is_array( $chunks ) ) {
			foreach ( $chunks as $chunk ) {
				$body = isset( $chunk->content ) ? (string) $chunk->content : '';
				if ( $body === '' && isset( $chunk->notes ) ) {
					// Fallback to notes when no studio output yet (mid-generation).
					$body = (string) $chunk->notes;
				}
				if ( trim( $body ) === '' ) {
					continue; // Skip empty chunks — nothing cite-able.
				}

				$idx       = (int) ( $chunk->chunk_index ?? 0 );
				$platform  = (string) ( $chunk->platform   ?? '' );
				$stage_lbl = (string) ( $chunk->stage_label ?? '' );
				$emoji     = (string) ( $chunk->stage_emoji ?? '' );
				$cta       = (string) ( $chunk->cta_text    ?? '' );
				$tags      = (string) ( $chunk->hashtags    ?? '' );

				$title_parts = array();
				if ( $emoji !== '' )     { $title_parts[] = $emoji; }
				if ( $stage_lbl !== '' ) { $title_parts[] = $stage_lbl; }
				if ( $platform !== '' )  { $title_parts[] = '[' . $platform . ']'; }
				$passage_title = implode( ' ', $title_parts );
				if ( $passage_title === '' ) {
					$passage_title = sprintf( 'Chunk #%d', $idx );
				}

				$body_lines = array( $body );
				if ( $cta !== '' )  { $body_lines[] = ''; $body_lines[] = '**CTA:** ' . $cta; }
				if ( $tags !== '' ) { $body_lines[] = '**Tags:** ' . $tags; }

				$passages[] = array(
					'title'           => $passage_title,
					'body'            => $this->trim_text( implode( "\n", $body_lines ), 4000 ),
					'metadata'        => array_merge( $base_meta, array(
						'section'     => 'chunk_' . $idx,
						'chunk_index' => $idx,
						'platform'    => $platform,
						'stage'       => $stage_lbl,
					) ),
					'citation_anchor' => $anchor( 'chunk_' . $idx ),
				);
			}
		}

		return $passages;
	}

	/* ──────────────────────────────────────────────────────────
	 * R-PP-6 — system prompt enrichment (≤ 600 tokens)
	 * ────────────────────────────────────────────────────────── */

	public function enrich_system_prompt( int $user_id, int $character_id, array $ctx ): string {
		// Keep silent unless explicitly engaged via tool — content drafts are
		// long-form and would blow the budget if always injected.
		return '';
	}

	/* ──────────────────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────────────────── */

	private function trim_text( string $text, int $max ): string {
		$text = trim( $text );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return mb_substr( $text, 0, $max ) . '…';
		}
		if ( strlen( $text ) > $max ) {
			return substr( $text, 0, $max ) . '…';
		}
		return $text;
	}
}
