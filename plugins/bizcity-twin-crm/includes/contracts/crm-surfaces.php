<?php
/**
 * CRM framework boundary — canonical surface descriptors (PHASE-0.60 C5).
 *
 * @package BizCity_Twin_CRM
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bizcity_crm_surface_descriptors' ) ) {
	/**
	 * [2026-09-20 Johnny Chu] PHASE-0.60 C60-G03 — each surface now carries a `guide` block
	 * (C9). Setting Panel contract v1 has no `docs` destination yet (C60-G01, owned by Core/
	 * TwinShell), so nothing reads this block at runtime today — it is declared here so a
	 * future registrar has one place to read from instead of every surface being patched again.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function bizcity_crm_surface_descriptors(): array {
		return array(
			'crm.inbox' => array( 'id' => 'crm.inbox', 'title' => 'CRM Inbox', 'label' => 'Inbox', 'slug' => 'bizcity-crm', 'action' => 'crm.inbox.open', 'public_slug' => '/crm/', 'render' => array( 'BizCity_CRM_Admin_Menu', 'render_inbox_page' ),
				'guide' => array( 'id' => 'crm.inbox.guide', 'title' => 'Hướng dẫn — CRM Inbox', 'source' => 'docs/guides/crm-inbox.md', 'audience' => 'crm.inbox.open' ),
			),
			'crm.channels' => array( 'id' => 'crm.channels', 'title' => 'CRM Channels', 'label' => 'Channels', 'slug' => 'bizcity-crm-channels', 'action' => 'crm.channel.manage', 'render' => array( 'BizCity_CRM_Admin_Menu', 'render_channels_page' ),
				'guide' => array( 'id' => 'crm.channels.guide', 'title' => 'Hướng dẫn — CRM Channels', 'source' => 'docs/guides/crm-channels.md', 'audience' => 'crm.channel.manage' ),
			),
			'crm.add_inbox' => array( 'id' => 'crm.add_inbox', 'title' => 'Add CRM Inbox', 'label' => 'Add Inbox', 'slug' => 'bizcity-crm-add-inbox', 'action' => 'crm.channel.manage', 'render' => array( 'BizCity_CRM_Admin_Menu', 'render_add_inbox_wizard' ),
				'guide' => array( 'id' => 'crm.add_inbox.guide', 'title' => 'Hướng dẫn — Add Inbox', 'source' => 'docs/guides/crm-add-inbox.md', 'audience' => 'crm.channel.manage' ),
			),
			'crm.settings' => array( 'id' => 'crm.settings', 'title' => 'CRM Settings', 'label' => 'Settings', 'slug' => 'bizcity-crm-settings', 'action' => 'crm.settings.manage', 'render' => array( 'BizCity_CRM_Admin_Menu', 'render_settings_page' ),
				'guide' => array( 'id' => 'crm.settings.guide', 'title' => 'Hướng dẫn — CRM Settings', 'source' => 'docs/guides/crm-settings.md', 'audience' => 'crm.settings.manage' ),
			),
			'crm.identity_queue' => array( 'id' => 'crm.identity_queue', 'title' => 'CRM Identity Queue', 'label' => 'Identity Queue', 'slug' => 'bizcity-crm-identity-queue', 'action' => 'crm.rules.manage', 'render' => array( 'BizCity_CRM_Admin_Menu', 'render_identity_queue_page' ),
				'guide' => array( 'id' => 'crm.identity_queue.guide', 'title' => 'Hướng dẫn — Identity Queue', 'source' => 'docs/guides/crm-identity-queue.md', 'audience' => 'crm.rules.manage' ),
			),
		);
	}
}
