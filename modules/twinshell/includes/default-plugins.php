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
 *   workflow   → admin: bizcity-workspace?tab=workflow   (mode=link)
 *   tools      → /tools-map/            (intent tool map)
 *   skills     → /skills/               (skills page)
 *   gateway    → admin: bizchat-gateway                  (mode=link)
 *   explore    → admin: bizcity-marketplace              (mode=link)
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
			'label'       => __( 'Plans & Scripts',               $td ),
			'icon'        => 'creator',
			'emoji'       => '✍️',
			'mode'        => 'embed',
			'public_slug' => '/creator/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'tab', 'session_id' ],
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
		],
		[
			'id'          => 'canva',
			'label'       => __( 'Banners & Flyers',              $td ),
			'icon'        => 'image',
			'emoji'       => '🖼️',
			'mode'        => 'embed',
			'public_slug' => '/canva/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'template' ],
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
		],
		// ── Knowledge ─────────────────────────────────────────────────
		[
			'id'          => 'mindmap',
			'label'       => __( 'Mindmap',                       $td ),
			'icon'        => 'notebook',
			'emoji'       => '🧠',
			'mode'        => 'embed',
			'public_slug' => '/mindmap/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id' ],
		],
		[
			'id'          => 'note',
			'label'       => __( 'Notebook',                      $td ),
			'icon'        => 'notebook',
			'emoji'       => '📖',
			'mode'        => 'embed',
			'public_slug' => '/note/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id' ],
		],
		// ── Operations (bottom — utilities & system links) ────────────
		[
			'id'          => 'tasks',
			'label'       => __( 'Tasks',                         $td ),
			'icon'        => 'tasks',
			'emoji'       => '📋',
			'mode'        => 'embed',
			'public_slug' => '/tasks/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id', 'status' ],
		],
		[
			'id'          => 'sessions',
			'label'       => __( 'Chat Sessions',                 $td ),
			'icon'        => 'sessions',
			'emoji'       => '🗂️',
			'mode'        => 'embed',
			'public_slug' => '/chat-sessions/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id' ],
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
			'label'       => __( 'Workflow',                      $td ),
			'icon'        => 'tools',
			'emoji'       => '🔄',
			'mode'        => 'link',
			'target_url'  => admin_url( 'admin.php?page=bizcity-workspace&tab=workflow' ),
			'capability'  => 'read',
			'section'     => 'bottom',
		],
		[
			'id'          => 'tools',
			'label'       => __( 'Tools',                         $td ),
			'icon'        => 'tools',
			'emoji'       => '🛠️',
			'mode'        => 'embed',
			'public_slug' => '/tools-map/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [],
		],
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
			'id'          => 'gateway',
			'label'       => __( 'Gateway',                       $td ),
			'icon'        => 'tools',
			'emoji'       => '🔌',
			'mode'        => 'link',
			'target_url'  => admin_url( 'admin.php?page=bizchat-gateway' ),
			'capability'  => 'read',
			'section'     => 'bottom',
		],
		[
			'id'          => 'explore',
			'label'       => __( 'Marketplace',                   $td ),
			'icon'        => 'explore',
			'emoji'       => '🔍',
			'mode'        => 'link',
			'target_url'  => admin_url( 'admin.php?page=bizcity-marketplace' ),
			'capability'  => 'read',
			'section'     => 'bottom',
		],
		// Phase 0.7 Wave E — KG Learning Hub (analytics + cleanup).
		// Page itself enforces tighter cap (`bizcity_view_kg_learning`); the
		// shell entry stays at `read` so the icon shows for everyone — the
		// 403 from the inner page is the canonical gate.
		[
			'id'          => 'learning-hub',
			'label'       => __( 'Learning Hub',                  $td ),
			'icon'        => 'mindmap',
			'emoji'       => '🧠',
			'mode'        => 'embed',
			'public_slug' => '/learning-hub/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'cortex', 'scope', 'range' ],
			'desc'        => __( 'KG learning analytics & cleanup.', $td ),
		],
	];

	return array_merge( $defaults, $plugins );
}, 5 );
