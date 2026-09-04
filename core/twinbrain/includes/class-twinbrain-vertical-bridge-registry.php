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
		// [2026-08-28 Johnny Chu] PHASE-1.32 — canonical bridge metadata feeds MPR, channel, automation, and admin contract checks.
		// [2026-08-16 Johnny Chu] CCG-4 — canonical catalog for / Vertical Plugin suggestions.
		// [2026-08-28 Johnny Chu] PHASE-1.32 — keep all free vertical packages usable by default in Twin GPT mode policy derivation.
		$verticals = array(
			self::row( 'astro', 'Astro', 'Hồ sơ cá nhân và transit.', 'bizcoach-pro', 'narrative', false, 'free', 'Sparkles' ),
			self::row( 'quick', 'Web Quick', 'Tìm kiếm web nhanh và tổng hợp.', 'core/twinbrain', 'narrative', true, 'free', 'Globe' ),
			self::row( 'deep', 'Deep Research', 'Tìm kiếm và nghiên cứu đa nguồn.', 'core/twinbrain', 'narrative', true, 'free', 'Telescope' ),
			self::row( 'social', 'Social Listening', 'Tìm bài viết và tín hiệu hot trên mạng xã hội.', 'core/twinbrain', 'list_and_narrative', false, 'plus', 'Users' ),
			self::row( 'products', 'Super-MRO', 'Tra cứu và tư vấn vật tư công nghiệp.', 'core/twinbrain', 'list_and_narrative', true, 'free', 'PackageSearch' ),
			self::row( 'company', 'Company Brief', 'Nghiên cứu brand và website.', 'core/twinbrain', 'narrative', false, 'plus', 'Building2' ),
			self::row( 'med', 'Medical', 'Nghiên cứu y khoa có disclaimer.', 'core/twinbrain', 'narrative', false, 'plus', 'Stethoscope' ),
			self::row( 'scholar', 'Học thuật', 'Tìm nguồn học thuật và citation.', 'core/twinbrain', 'list_and_narrative', false, 'plus', 'GraduationCap' ),
			self::row( 'nutri', 'Dinh dưỡng', 'Nghiên cứu dinh dưỡng có disclaimer.', 'core/twinbrain', 'narrative', false, 'plus', 'Apple' ),
			self::row( 'law', 'Pháp luật', 'Tra cứu văn bản pháp luật.', 'core/twinbrain', 'list_and_narrative', false, 'plus', 'Scale' ),
			self::row( 'tax', 'Thuế', 'Tra cứu văn bản và chính sách thuế.', 'core/twinbrain', 'list_and_narrative', false, 'plus', 'Receipt' ),
			self::row( 'gov', 'Chính sách / Tin nhà nước', 'Tra cứu tin và chính sách chính thống.', 'core/twinbrain', 'list_and_narrative', false, 'plus', 'Landmark' ),
			array_merge( self::row( 'woo_bizops', 'Woo BizOps', 'Dữ liệu doanh thu, đơn hàng và khách hàng WooCommerce.', 'core/twinbrain', 'table_and_narrative', false, 'free', 'BarChart3' ), array( 'sensitive' => true ) ),
		);
		$verticals = apply_filters( 'bizcity_twinbrain_vertical_bridge_registry', $verticals );
		return array_values( array_filter( (array) $verticals, static function ( $row ) {
			return is_array( $row ) && sanitize_key( (string) ( $row['id'] ?? '' ) ) !== '';
		} ) );
	}

	private static function row( string $id, string $label, string $role, string $owner, string $output_shape, bool $guest_allowed, string $min_plan, string $icon, bool $default_enabled = true ): array {
		return array(
			'id' => $id,
			'label' => $label,
			'role' => $role,
			'owner_plugin' => $owner,
			'output_shape' => $output_shape,
			'guest_allowed' => $guest_allowed,
			'min_plan' => $min_plan,
			'icon' => $icon,
			'default_enabled' => $default_enabled,
			'contract_id' => 'twinbrain.vertical.' . $id . '.v1',
			'mpr_layers' => array( 2, 5 ),
			'automation_mode' => 'built_in_mpr',
			'channel_entry' => 'core/channel-gateway.normalized_envelope',
			'admin_surface' => 'plugins/bizcity-twin-crm',
		);
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
