<?php
/**
 * ORPHAN FILE — DO NOT USE
 *
 * Canonical owner: core/skills (BizCity_Skill_REST_API + the Skills admin page)
 *
 * Skill Library SPA mount point that nothing enqueued: the matching bundle assets/js/skill-editor.js was never registered with wp_enqueue_script, and core/skills owns the live skill UI (CORE-REDUCTION-WP-01 K-03).
 *
 * Retired 2026-09-23 under R-ORPHAN-FILE: this file declares
 * ZERO classes, functions and hooks. The historical body below is kept for reference
 * only, inside `if ( false )`, and is never parsed as a declaration. Never require it,
 * never treat it as a source of truth, and never rename a live file to `_deleted` as a
 * way to switch it off.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( false ) { // ── historical body — reference only ──────────────────────────
	/**
	 * Skill Library — Admin Page Template
	 *
	 * Renders the React SPA mount point for the Skill Library editor.
	 *
	 * @package    Bizcity_Twin_AI
	 * @subpackage Core\Knowledge
	 */

	defined( 'ABSPATH' ) or die( 'OOPS...' );
	?>
	<div class="wrap sk-wrap">
	    <div id="skill-library-root">
	        <div style="text-align:center;padding:60px 20px;color:#94a3b8;">
	            <span class="dashicons dashicons-update spin" style="font-size:32px;width:32px;height:32px;"></span>
	            <p>Đang tải Skill Library...</p>
	        </div>
	    </div>
	</div>

<?php }
