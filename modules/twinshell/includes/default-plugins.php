<?php
/**
 * Twin Shell — Default plugin registrations.
 *
 * Maps the bundled BizCity plugin pages (already routed at their own
 * pretty-URLs by their respective modules) into the Twin Shell ActivityBar.
 * The shell does NOT create new pages — it only wraps the existing slugs in
 * an iframe and provides a unified left-side nav.
 *
 * URL mapping (must match the existing rewrite rules):
 *   twinchat   → /twinchat/             (modules/twinchat)
 *   creator    → /creator/              (plugins/bizcity-content-creator)
 *   doc        → /tool-doc/             (plugins/bizcity-doc)
 *   image      → /tool-image/           (plugins/bizcity-tool-image)
 *   profile    → /profile-studio/       (plugins/bizcity-tool-image)
 *   canva      → /canva/                (plugins/bizcity-tool-image)
 *   video      → /kling-video/          (plugins/bizcity-video-kling)
 *   web        → /tool-pagebuilder/     (plugins/bizcity-pagebuilder)
 *   mindmap    → /mindmap/              (plugins/bizcity-mindmap)
 *   note       → /note/                 (plugins/bizcity-notebook)
 *   tasks      → /tasks/                (core/intent)
 *   sessions   → /chat-sessions/        (core/intent)
 *   scheduler  → /scheduler/            (core/scheduler)
 *   workflow   → admin: bizcity-automation               (mode=link)
 *   tools      → /tools-map/            (intent tool map)
 *   skills     → /skills/               (skills page)
 *   gateway    → admin: bizchat-gateway                  (mode=link)
 *   explore    → admin: bizcity-marketplace              (mode=link)
 *
 * ── Activation gating (2026-06-02) ─────────────────────────────────────
 * Each entry MAY declare `requires`:
 *   [ 'const'    => 'CONST_NAME'  ]   — defined(CONST_NAME)
 *   [ 'class'    => 'Class_Name'  ]   — class_exists(...)
 *   [ 'function' => 'fn_name'     ]   — function_exists(...)
 *   [ 'plugin'   => 'slug/file.php' ] — is_plugin_active(...)
 * Entries WITHOUT `requires` are considered **core** and ALWAYS show:
 *   twinchat · gateway · scheduler · workflow · skills · settings · account.
 * Non-core entries whose requirement fails are hidden from the ActivityBar.
 * Bookmarked URLs (`/twin/?plugin=xxx`) hitting a locked entry render the
 * “Plugin chưa được kích hoạt / gói Pro” notice (see class-twin-shell-page).
 *
 * Language convention (PHASE-0-RULE-LANGUAGE):
 *   - Source labels are written in English.
 *   - Vietnamese (and other locales) ship via .po/.mo under
 *     bizcity-twin-ai/languages/, text-domain `bizcity-twin-ai`.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

add_filter( 'bizcity_twin_register_plugins', static function ( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		$plugins = [];
	}

	$td = 'bizcity-twin-ai';

	$defaults = [
		// ── Core workspace ────────────────────────────────────────────
		[
			'id'          => 'twinchat',
			'label'       => __( 'Twin Chat',                     $td ),
			'icon'        => 'home',
			'emoji'       => '💬',
			'mode'        => 'embed',
			'public_slug' => '/twinchat/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'session', 'notebook', 'notebook_id', 'thread' ],
			'desc'        => __( 'Workspace chat & notebooks.',   $td ),
		],
		// ── Authoring ─────────────────────────────────────────────────
		[
			'id'          => 'creator',
			'label'       => __( 'Brain Factory',                $td ),
			'icon'        => 'creator',
			'emoji'       => '🧠',
			'mode'        => 'embed',
			'public_slug' => '/creator/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'tab', 'session_id' ],
			'requires'    => [ 'class' => 'BizCity_Content_Creator' ],
		],
		[
			'id'          => 'doc',
			'label'       => __( 'Documents & Slides',            $td ),
			'icon'        => 'doc',
			'emoji'       => '📄',
			'mode'        => 'embed',
			'public_slug' => '/tool-doc/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'doc', 'id', 'tab' ],
			'requires'    => [ 'const' => 'BZDOC_VERSION' ],
		],
		// ── Customer Operations ───────────────────────────────────────
		[
			'id'          => 'gateway',
			'label'       => __( 'Channels',                      $td ),
			'icon'        => 'gateway',
			'emoji'       => '🔌',
			'mode'        => 'link',
			'target_url'  => admin_url( 'admin.php?page=bizchat-gateway-spa' ),
			'capability'  => 'read',
			'section'     => 'top',
		],
		[
			'id'          => 'crm',
			'label'       => __( 'CRM Inbox',                     $td ),
			'icon'        => 'funnel',
			'emoji'       => '📥',
			'mode'        => 'embed',
			'public_slug' => '/crm/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'tab', 'inbox', 'thread', 'contact_id' ],
			'desc'        => __( 'Unified multi-channel inbox (Facebook / Zalo / WebChat) with Twin Brain trace.', $td ),
			'requires'    => [ 'class' => 'BizCity_Twin_CRM' ],
		],
		// ── Visual / Design ───────────────────────────────────────────
		[
			'id'          => 'image',
			'label'       => __( 'Product Images',                $td ),
			'icon'        => 'image',
			'emoji'       => '🎨',
			'mode'        => 'embed',
			'public_slug' => '/tool-image/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'tab' ],
			'requires'    => [ 'const' => 'BZTIMG_VERSION' ],
		],
		[
			'id'          => 'profile',
			'label'       => __( 'Portrait Studio',               $td ),
			'icon'        => 'image',
			'emoji'       => '🧑‍🎨',
			'mode'        => 'embed',
			'public_slug' => '/profile-studio/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'tab' ],
			'requires'    => [ 'const' => 'BZTIMG_VERSION' ],
		],
		[
			'id'          => 'video',
			'label'       => __( 'Video Studio',                  $td ),
			'icon'        => 'video',
			'emoji'       => '🎬',
			'mode'        => 'embed',
			'public_slug' => '/kling-video/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'tab', 'mode' ],
			'requires'    => [ 'const' => 'BIZCITY_VIDEO_KLING_VERSION' ],
		],
		// ── Web ───────────────────────────────────────────────────────
		[
			'id'          => 'web',
			'label'       => __( 'Web Builder',                   $td ),
			'icon'        => 'web',
			'emoji'       => '🌐',
			'mode'        => 'embed',
			'public_slug' => '/tool-pagebuilder/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'page' ],
			'requires'    => [ 'class' => 'BizCity_Pagebuilder' ],
		],
		// ── Knowledge ─────────────────────────────────────────────────
		// Mindmap & Notebook entries removed from ActivityBar (still reachable
		// from Workspace / inline tools); keep registry lean per UX feedback.
		// 2026-05-13 — twin-builder removed from ActivityBar per UX feedback
		// (still reachable via /wp-admin/?page=bizcity-twin-builder direct URL).
		// ── Operations (bottom — utilities & system links) ────────────
		[
			'id'          => 'account',
			'label'       => __( 'Account & Billing',             $td ),
			'icon'        => 'wallet',
			'emoji'       => '💳',
			'mode'        => 'link',
			// Unified BizCity API key registration — works on every client install
			// even when local plugins (bizcity-llm-router, mu bizcity-openrouter)
			// are NOT present. One key serves every BizCity JSON service.
			'target_url'  => 'https://bizcity.vn/my-account/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'desc'        => __( 'Register the unified BizCity API key, top-up & PayPal billing.', $td ),
		],
		[
			'id'          => 'scheduler',
			'label'       => __( 'Reminders',                     $td ),
			'icon'        => 'scheduler',
			'emoji'       => '📅',
			'mode'        => 'embed',
			'public_slug' => '/scheduler/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id' ],
		],
		[
			'id'          => 'workflow',
			'label'       => __( 'Automation',                    $td ),
			'icon'        => 'automation',
			'emoji'       => '🔄',
			'mode'        => 'link',
			'target_url'  => admin_url( 'admin.php?page=bizcity-automation' ),
			'capability'  => 'read',
			'section'     => 'bottom',
		],
		// 2026-05-13 — `tools` removed from ActivityBar (still reachable at /tools-map/).
		[
			'id'          => 'skills',
			'label'       => __( 'Skills',                        $td ),
			'icon'        => 'skills',
			'emoji'       => '⚡',
			'mode'        => 'embed',
			'public_slug' => '/skills/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id' ],
		],
		[
			'id'          => 'settings',
			'label'       => __( 'Settings',                      $td ),
			'icon'        => 'settings',
			'emoji'       => '⚙️',
			'mode'        => 'link',
			'target_url'  => admin_url( 'admin.php?page=bizcity-twinchat-settings' ),
			'capability'  => 'manage_options',
			'section'     => 'bottom',
			'desc'        => __( 'TwinChat & BizCity API key — single source of truth for the whole ecosystem.', $td ),
		],
		// 2026-05-13 — `channels` and `explore` removed from bottom ActivityBar
		// (channels merged into `gateway`; marketplace reachable via direct URL).
		// Learning Hub entry intentionally hidden from ActivityBar (still
		// reachable via direct /learning-hub/ URL when needed).
	];

	return array_merge( $defaults, $plugins );
}, 5 );
