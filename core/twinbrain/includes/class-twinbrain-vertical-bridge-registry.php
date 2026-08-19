<?php
/**
 * Canonical Vertical Brain Mode / Plugin Bridge registry.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry', false ) ) {
	return;
}

final class BizCity_TwinBrain_Vertical_Bridge_Registry {

	/**
	 * Return the built-in and plugin-extended vertical catalog.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		// [2026-08-16 Johnny Chu] CCG-4 — canonical catalog for / Vertical Plugin suggestions.
		$verticals = array(
			array( 'id' => 'astro', 'label' => 'Astro', 'role' => 'Hồ sơ cá nhân và transit.', 'owner_plugin' => 'bizcoach-pro', 'output_shape' => 'narrative', 'guest_allowed' => false, 'min_plan' => 'free', 'icon' => 'Sparkles' ),
			array( 'id' => 'quick', 'label' => 'Web Quick', 'role' => 'Tìm kiếm web nhanh và tổng hợp.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'narrative', 'guest_allowed' => true, 'min_plan' => 'free', 'icon' => 'Globe' ),
			array( 'id' => 'deep', 'label' => 'Deep Research', 'role' => 'Tìm kiếm và nghiên cứu đa nguồn.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'narrative', 'guest_allowed' => true, 'min_plan' => 'free', 'icon' => 'Telescope' ),
			array( 'id' => 'social', 'label' => 'Social Listening', 'role' => 'Tìm bài viết và tín hiệu hot trên mạng xã hội.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'list_and_narrative', 'guest_allowed' => false, 'min_plan' => 'plus', 'icon' => 'Users' ),
			array( 'id' => 'products', 'label' => 'Super-MRO', 'role' => 'Tra cứu và tư vấn vật tư công nghiệp.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'list_and_narrative', 'guest_allowed' => true, 'min_plan' => 'free', 'icon' => 'PackageSearch' ),
			array( 'id' => 'company', 'label' => 'Company Brief', 'role' => 'Nghiên cứu brand và website.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'narrative', 'guest_allowed' => false, 'min_plan' => 'plus', 'icon' => 'Building2' ),
			array( 'id' => 'med', 'label' => 'Medical', 'role' => 'Nghiên cứu y khoa có disclaimer.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'narrative', 'guest_allowed' => false, 'min_plan' => 'plus', 'icon' => 'Stethoscope' ),
			array( 'id' => 'scholar', 'label' => 'Học thuật', 'role' => 'Tìm nguồn học thuật và citation.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'list_and_narrative', 'guest_allowed' => false, 'min_plan' => 'plus', 'icon' => 'GraduationCap' ),
			array( 'id' => 'nutri', 'label' => 'Dinh dưỡng', 'role' => 'Nghiên cứu dinh dưỡng có disclaimer.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'narrative', 'guest_allowed' => false, 'min_plan' => 'plus', 'icon' => 'Apple' ),
			array( 'id' => 'law', 'label' => 'Pháp luật', 'role' => 'Tra cứu văn bản pháp luật.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'list_and_narrative', 'guest_allowed' => false, 'min_plan' => 'plus', 'icon' => 'Scale' ),
			array( 'id' => 'tax', 'label' => 'Thuế', 'role' => 'Tra cứu văn bản và chính sách thuế.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'list_and_narrative', 'guest_allowed' => false, 'min_plan' => 'plus', 'icon' => 'Receipt' ),
			array( 'id' => 'gov', 'label' => 'Chính sách / Tin nhà nước', 'role' => 'Tra cứu tin và chính sách chính thống.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'list_and_narrative', 'guest_allowed' => false, 'min_plan' => 'plus', 'icon' => 'Landmark' ),
			array( 'id' => 'woo_bizops', 'label' => 'Woo BizOps', 'role' => 'Dữ liệu doanh thu, đơn hàng và khách hàng WooCommerce.', 'owner_plugin' => 'core/twinbrain', 'output_shape' => 'table_and_narrative', 'guest_allowed' => false, 'min_plan' => 'free', 'icon' => 'BarChart3', 'sensitive' => true ),
		);
		$verticals = apply_filters( 'bizcity_twinbrain_vertical_bridge_registry', $verticals );
		return array_values( array_filter( (array) $verticals, static function ( $row ) {
			return is_array( $row ) && sanitize_key( (string) ( $row['id'] ?? '' ) ) !== '';
		} ) );
	}

	public static function get( string $id ): ?array {
		// [2026-08-16 Johnny Chu] CCG-4 — resolve one registered Vertical Plugin by stable slug.
		$id = sanitize_key( $id );
		foreach ( self::all() as $vertical ) {
			if ( sanitize_key( (string) ( $vertical['id'] ?? '' ) ) === $id ) {
				return $vertical;
			}
		}
		return null;
	}

	/**
	 * Extract a leading `/vertical_slug` command, mirroring the exact
	 * `#workflow_slug` grammar so literal text reliably maps to web_mode
	 * even when a client never set the web_mode request param.
	 *
	 * @return array{slug:string,args:string}|null
	 */
	public static function extract( string $text ): ?array {
		// [2026-08-16 Johnny Chu] CCG-2 — deterministic exact-slug parity for the explicit `/` grammar.
		$text = ltrim( (string) $text );
		if ( $text === '' || $text[0] !== '/' ) {
			return null;
		}
		if ( ! preg_match( '/^\/([a-zA-Z0-9_-]+)(?:\s+(.*))?$/s', $text, $matches ) ) {
			return null;
		}
		$slug = strtolower( (string) $matches[1] );
		if ( ! self::get( $slug ) ) {
			return null;
		}
		return array(
			'slug' => $slug,
			'args' => isset( $matches[2] ) ? trim( (string) $matches[2] ) : '',
		);
	}
}
