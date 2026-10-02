<?php
/**
 * Remote Zalo Hub — project a Guru into the fields the remote agent PATCH accepts (XS5, doc 51 §4.2).
 *
 * Pure function: no I/O beyond the injectable readers, so it is unit-testable without WordPress. Guru is
 * the ONE source (R-GURU-SOURCE) — this only builds the READ-ONLY chiếu (projection); it never writes
 * anything back into the Guru. Only `editableFields` the remote reports are ever included, and only
 * instruction + FAQ ever leave the site (R-GURU-PRIVATE: no notebook, no CRM content, no other Guru's data).
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Agent_Projector', false ) ) {
	return;
}

// [2026-09-30 Claude Sonnet 5] PHASE-0.82 XS5 (51 §4.2) — the projector: Guru profile + tuning -> remote agent fields.
final class BizCity_Remote_Zalo_Agent_Projector {

	/** Bumped when compose_persona()'s shape changes, so a stored fingerprint always re-diffs after a code change. */
	const PROJECTOR_VERSION = 'rzh-agent@1';
	const PERSONA_MAX = 8000;

	/** Human labels for §4's "not applicable on Remote" list (guide has no field for any of these). */
	const NOT_APPLIED = array(
		'notebooks'              => 'Tri thức notebook (Guru)',
		'context_source'         => 'Nguồn ngữ cảnh CRM/Context Bank (Guru)',
		'disabled_tools'         => 'Công cụ tắt/bật',
		'office_hours'           => 'Giờ làm việc',
		'hybrid_mode'            => 'Chế độ hybrid',
		'vision_mode'            => 'Xem ảnh khách gửi (Guru)',
		'daily_message_cap'      => 'Trần tin/ngày',
		'debounce_seconds'       => 'Chờ gộp tin',
		'turn_timeout_seconds'   => 'Trần thời gian một lượt',
	);

	/**
	 * @param array $readers Test seams: profile(character_id)->array, tuning()->array. Omitted ⇒ real owners.
	 * @return array{fields:array,not_applied:array,warnings:array,fingerprint:string}
	 */
	public static function project( int $character_id, array $editable_fields, array $readers = array() ): array {
		$profile = self::profile( $character_id, $readers );
		$tuning  = self::tuning( $readers );
		$editable = array_flip( array_map( 'strval', $editable_fields ) );
		$fields   = array();
		$warnings = array();
		$not_applied = array();
		foreach ( self::NOT_APPLIED as $label ) { $not_applied[] = array( 'key' => $label, 'label' => $label ); }

		if ( isset( $editable['persona'] ) ) {
			$persona = self::compose_persona( (string) ( $profile['instruction']['text'] ?? '' ), (array) ( $profile['instruction']['faq'] ?? array() ), $warnings );
			$fields['persona'] = $persona;
		}
		if ( isset( $editable['name'] ) ) {
			$name = trim( (string) ( $profile['guru']['name'] ?? '' ) );
			if ( '' !== $name ) { $fields['name'] = mb_substr( $name, 0, 100 ); }
		}
		if ( isset( $editable['icon'] ) ) {
			$icon = trim( (string) ( $profile['guru']['icon'] ?? '' ) );
			// Only forward something that looks like a single emoji/short glyph, never a URL/path (Remote takes an emoji, not an image).
			if ( '' !== $icon && false === strpos( $icon, '/' ) && mb_strlen( $icon ) <= 8 ) { $fields['icon'] = $icon; }
		}
		if ( isset( $editable['maxSteps'] ) && is_numeric( $tuning['max_tool_steps'] ?? null ) ) {
			$fields['maxSteps'] = max( 1, min( 30, (int) $tuning['max_tool_steps'] + 1 ) );
		}
		if ( isset( $editable['reasoningEffort'] ) && in_array( $tuning['reasoning_effort'] ?? null, array( 'off', 'low', 'medium', 'high' ), true ) ) {
			$fields['reasoningEffort'] = (string) $tuning['reasoning_effort'];
		}
		if ( isset( $editable['sttEnabled'] ) && array_key_exists( 'stt_enabled', $tuning ) ) {
			$fields['sttEnabled'] = (bool) $tuning['stt_enabled'];
		}
		return array(
			'fields'      => $fields,
			'not_applied' => $not_applied,
			'warnings'    => $warnings,
			'fingerprint' => hash( 'sha256', self::PROJECTOR_VERSION . '|' . wp_json_encode( self::sorted( $fields ) ) ),
		);
	}

	/** Instruction first; FAQ appended only while it fits, in order, never truncating a Q/A pair mid-way. */
	public static function compose_persona( string $instruction, array $faq, array &$warnings ): string {
		$text = trim( $instruction );
		if ( mb_strlen( $text ) > self::PERSONA_MAX ) {
			$text = mb_substr( $text, 0, self::PERSONA_MAX );
			$warnings[] = 'persona_truncated';
			return $text;
		}
		$candidates = array();
		foreach ( $faq as $row ) {
			$q = trim( (string) ( $row['q'] ?? '' ) );
			$a = trim( (string) ( $row['a'] ?? '' ) );
			if ( '' === $q && '' === $a ) { continue; }
			$candidates[] = "Hỏi: {$q}\nĐáp: {$a}";
		}
		$blocks = array();
		foreach ( $candidates as $block ) {
			$attempt = trim( $text . "\n\n### Hỏi đáp nhanh\n" . implode( "\n\n", array_merge( $blocks, array( $block ) ) ) );
			if ( mb_strlen( $attempt ) > self::PERSONA_MAX ) { break; }
			$blocks[] = $block;
		}
		if ( count( $blocks ) < count( $candidates ) ) {
			$warnings[] = 'faq_partial:' . count( $blocks ) . '/' . count( $candidates );
		}
		if ( ! $blocks ) { return $text; }
		return trim( $text . "\n\n### Hỏi đáp nhanh\n" . implode( "\n\n", $blocks ) );
	}

	private static function profile( int $character_id, array $readers ): array {
		if ( isset( $readers['profile'] ) && is_callable( $readers['profile'] ) ) { return (array) call_user_func( $readers['profile'], $character_id ); }
		return class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::profile( $character_id ) : array();
	}

	private static function tuning( array $readers ): array {
		if ( isset( $readers['tuning'] ) && is_callable( $readers['tuning'] ) ) { return (array) call_user_func( $readers['tuning'] ); }
		return class_exists( 'BizCity_Bot_Config_Repo' ) ? (array) BizCity_Bot_Config_Repo::get_tuning() : array();
	}

	private static function sorted( array $fields ): array { ksort( $fields ); return $fields; }
}
