<?php
defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Vertical_Bridge_Registry {

	public static function all(): array {
		$verticals = array(
			self::row( 'orphan', 'Orphan Vertical', 'Granted to a plugin that never declared it.', 'orphan-vertical', 'narrative', false, 'free', 'Sparkle' ),
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
}
