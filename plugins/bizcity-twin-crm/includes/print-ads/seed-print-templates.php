<?php
/**
 * BizCity CRM — Print-Ads seed loader (M-PA.W1).
 *
 * Loads 12 default templates from `data/print-templates-seed.json` into the
 * `{prefix}bzcrm_print_templates` table. Idempotent: existing slugs are
 * either skipped (default) or updated when `$force === true`.
 *
 * Pattern mirrors `plugins/bizcity-tool-image/includes/seed-templates.php`.
 *
 * @package BizCity_Twin_CRM
 * @since   0.32.3 (M-PA.W1)
 *
 * @param bool $force When true, re-imports and OVERWRITES existing
 *                    local_seed rows (matched by slug). user_custom rows are
 *                    never touched. Default false: skip rows whose slug
 *                    already exists.
 * @return array{imported:int,updated:int,skipped:int,errors:array<int,string>}
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bzcrm_seed_print_templates' ) ) :
function bzcrm_seed_print_templates( bool $force = false ): array {
	global $wpdb;

	$result = array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );

	if ( ! class_exists( 'BizCity_CRM_Print_Templates_Installer' ) ) {
		$result['errors'][] = 'Installer class missing.';
		return $result;
	}

	$seed_path = BIZCITY_CRM_DIR . '/data/print-templates-seed.json';
	if ( ! file_exists( $seed_path ) ) {
		$result['errors'][] = 'Seed file not found: data/print-templates-seed.json';
		return $result;
	}

	$raw  = file_get_contents( $seed_path );
	$rows = json_decode( $raw, true );
	if ( ! is_array( $rows ) ) {
		$result['errors'][] = 'Seed JSON parse error: ' . json_last_error_msg();
		return $result;
	}

	$tbl = BizCity_CRM_Print_Templates_Installer::tbl_templates();
	$now = current_time( 'mysql' );

	foreach ( $rows as $i => $item ) {
		if ( ! is_array( $item ) ) { continue; }

		$slug = isset( $item['slug'] ) ? sanitize_title( (string) $item['slug'] ) : '';
		if ( $slug === '' ) {
			$result['errors'][] = "Row #{$i}: missing slug.";
			continue;
		}

		// Lookup existing.
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE slug = %s LIMIT 1",
			$slug
		) );

		$payload = array(
			'slug'              => $slug,
			'source'            => 'local_seed',
			'remote_id'         => null,
			'template_type'     => isset( $item['template_type'] ) ? substr( (string) $item['template_type'], 0, 40 ) : 'print_ad',
			'title'             => isset( $item['title'] ) ? substr( (string) $item['title'], 0, 190 ) : $slug,
			'description'       => isset( $item['description'] ) ? (string) $item['description'] : null,
			'ref_image_url'     => isset( $item['ref_image_url'] ) ? (string) $item['ref_image_url'] : null,
			'base_prompt'       => (string) ( $item['base_prompt'] ?? '' ),
			'qr_slot_json'      => isset( $item['qr_slot_json'] )    ? wp_json_encode( $item['qr_slot_json'] )    : null,
			'brand_slot_json'   => isset( $item['brand_slot_json'] ) ? wp_json_encode( $item['brand_slot_json'] ) : null,
			'target_aspect'     => isset( $item['target_aspect'] )     ? substr( (string) $item['target_aspect'], 0, 20 ) : '1:1',
			'recommended_model' => isset( $item['recommended_model'] ) ? substr( (string) $item['recommended_model'], 0, 40 ) : 'flux-pro',
			'sort_order'        => isset( $item['sort_order'] ) ? (int) $item['sort_order'] : 0,
			'status'            => 'active',
			'updated_at'        => $now,
		);

		if ( $exists > 0 ) {
			if ( ! $force ) { $result['skipped']++; continue; }
			$ok = $wpdb->update( $tbl, $payload, array( 'id' => $exists ) );
			if ( $ok === false ) {
				$result['errors'][] = "Row {$slug}: update failed — " . $wpdb->last_error;
			} else {
				$result['updated']++;
			}
		} else {
			$payload['created_at'] = $now;
			$ok = $wpdb->insert( $tbl, $payload );
			if ( ! $ok ) {
				$result['errors'][] = "Row {$slug}: insert failed — " . $wpdb->last_error;
			} else {
				$result['imported']++;
			}
		}
	}

	return $result;
}
endif;
