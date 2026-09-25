<?php
/**
 * BizCity KG-Hub — Source Adapter Interface (E1)
 *
 * Contract that every per-modality file extractor must implement so the
 * Sources_Service ingest pipeline can stay format-agnostic.
 *
 * Output schema (`extract()` return) intentionally mirrors the contract in
 * PHASE-0.7-LEARNING-EXTEND.md §2.4:
 *
 *   [
 *     'text'     => string,            // perceptual proxy (chunkable text)
 *     'segments' => array<int,array>,  // [{t_start_ms?, t_end_ms?, page_num?, text}]
 *     'assets'   => array<int,array>,  // [{kind, relative_path, mime, bytes, ...}]
 *     'modality' => string,            // 'text'|'office'|'pdf_text'|'pdf_scan'|'image'|'audio'|'video'|'youtube'
 *     'meta'     => array,             // free-form (page_count, lang, duration_ms, etc.)
 *   ]
 *
 * Returning a WP_Error is the documented failure path — callers MUST handle it
 * and translate into a structured 4xx response (see UI-ERR humanizer).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub\Adapters
 * @since      2026-05-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

interface BizCity_KG_Source_Adapter {

	/**
	 * Decide whether this adapter can handle the given extension/MIME pair.
	 *
	 * Match priority is MIME first, extension fallback. Implementations should
	 * return true on either match so the registry can short-circuit.
	 *
	 * @param string $ext  Lowercased file extension without dot. May be ''.
	 * @param string $mime Best-effort MIME (may be '' when unavailable).
	 * @return bool
	 */
	public static function supports( $ext, $mime );

	/**
	 * Extract perceptual text + assets from the file.
	 *
	 * @param string $file_path Absolute filesystem path to the local file.
	 * @param array  $opts      Adapter-specific options (lang, scope_id, ...).
	 * @return array|WP_Error   See class header for return shape.
	 */
	public function extract( $file_path, array $opts );

	/**
	 * Stable identifier (slug) used for diagnostics + filter naming.
	 * Example: 'pdf', 'office_docx', 'image', 'audio'.
	 *
	 * @return string
	 */
	public static function id();
}
