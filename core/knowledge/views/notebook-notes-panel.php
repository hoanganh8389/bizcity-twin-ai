<?php
/**
 * Bizcity Twin AI — Notebook Notes Panel (PHASE 0.31 T-S3.2)
 *
 * Server-rendered list of recent passages with two per-note buttons:
 *   • "Tag note"        → open inline input → POST /passages/{id}/tag
 *   • "Trigger workflow"→ POST /passages/{id}/trigger-workflow
 *
 * Designed as a self-contained drop-in (no React, no build step) so
 * non-technical users (and Hương) can wire workflows from any page.
 *
 * Mount:
 *   • Shortcode: [bizcity_notebook_notes notebook_id=22 limit=20]
 *   • PHP include:
 *       set_query_var( 'bizcity_nb_id', 22 );
 *       require WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/views/notebook-notes-panel.php';
 *
 * Dependencies: WP REST + nonce, BizCity_KG_Source_Service::list_passages().
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Knowledge\Views
 * @since PHASE 0.31 Sprint 4 follow-up (T-S3.2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! is_user_logged_in() ) {
	echo '<div class="bizcity-notes-panel notice notice-warning"><p>'
		. esc_html__( 'Please log in to view notebook notes.', 'ai-copilot-content-generator' )
		. '</p></div>';
	return;
}

$notebook_id = isset( $atts['notebook_id'] )
	? (int) $atts['notebook_id']
	: (int) get_query_var( 'bizcity_nb_id', 0 );

$limit = isset( $atts['limit'] ) ? max( 1, min( 100, (int) $atts['limit'] ) ) : 20;

if ( $notebook_id <= 0 || ! class_exists( 'BizCity_KG_Source_Service' ) ) {
	echo '<div class="bizcity-notes-panel notice notice-error"><p>'
		. esc_html__( 'Invalid notebook_id or KG service unavailable.', 'ai-copilot-content-generator' )
		. '</p></div>';
	return;
}

// Permission gate: rely on REST controller's own check, but pre-validate to fail fast.
if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
	$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
	if ( ! $nb ) {
		echo '<div class="bizcity-notes-panel notice notice-error"><p>'
			. esc_html__( 'Notebook not found.', 'ai-copilot-content-generator' )
			. '</p></div>';
		return;
	}
	$owner = (int) ( $nb['owner_id'] ?? $nb['user_id'] ?? 0 );
	if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
		echo '<div class="bizcity-notes-panel notice notice-error"><p>'
			. esc_html__( 'No access to this notebook.', 'ai-copilot-content-generator' )
			. '</p></div>';
		return;
	}
}

$passages = BizCity_KG_Source_Service::instance()->list_passages( $notebook_id, [ 'limit' => $limit ] );
$rest_ns  = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : 'bizcity-twinchat/v1';
$rest_url = esc_url_raw( rest_url( $rest_ns . '/passages/' ) );
$nonce    = wp_create_nonce( 'wp_rest' );

// Default trigger tag (filterable, mirrors REST controller default).
$default_trigger_tag = (string) apply_filters( 'bizcity_twin_default_trigger_tag', 'trigger' );
?>
<div class="bizcity-notes-panel" data-notebook-id="<?php echo (int) $notebook_id; ?>" data-rest-base="<?php echo esc_attr( $rest_url ); ?>" data-rest-nonce="<?php echo esc_attr( $nonce ); ?>" data-trigger-tag="<?php echo esc_attr( $default_trigger_tag ); ?>">
	<style>
		.bizcity-notes-panel{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327;max-width:880px;}
		.bizcity-notes-panel h3{margin:0 0 12px;font-size:16px;font-weight:600;}
		.bizcity-notes-panel .bnp-empty{padding:16px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:6px;color:#646970;text-align:center;}
		.bizcity-notes-panel .bnp-list{list-style:none;padding:0;margin:0;display:grid;gap:10px;}
		.bizcity-notes-panel .bnp-item{padding:12px 14px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start;}
		.bizcity-notes-panel .bnp-item-content{font-size:13px;color:#2c3338;white-space:pre-wrap;word-break:break-word;max-height:90px;overflow:hidden;}
		.bizcity-notes-panel .bnp-item-meta{font-size:11px;color:#787c82;margin-top:6px;}
		.bizcity-notes-panel .bnp-actions{display:flex;flex-direction:column;gap:6px;min-width:140px;}
		.bizcity-notes-panel .bnp-btn{cursor:pointer;border:1px solid #2271b1;background:#fff;color:#2271b1;padding:5px 10px;border-radius:4px;font-size:12px;font-weight:500;transition:.15s;}
		.bizcity-notes-panel .bnp-btn:hover{background:#2271b1;color:#fff;}
		.bizcity-notes-panel .bnp-btn.bnp-trigger{border-color:#d63638;color:#d63638;}
		.bizcity-notes-panel .bnp-btn.bnp-trigger:hover{background:#d63638;color:#fff;}
		.bizcity-notes-panel .bnp-btn[disabled]{opacity:.6;cursor:wait;}
		.bizcity-notes-panel .bnp-status{font-size:11px;color:#646970;min-height:14px;}
		.bizcity-notes-panel .bnp-status.bnp-ok{color:#00a32a;}
		.bizcity-notes-panel .bnp-status.bnp-err{color:#d63638;}
		.bizcity-notes-panel .bnp-tag-input{display:none;margin-top:4px;width:100%;padding:4px 6px;border:1px solid #c3c4c7;border-radius:3px;font-size:12px;}
	</style>

	<h3><?php echo esc_html( sprintf(
		/* translators: %1$d notebook id, %2$d count */
		__( 'Notebook #%1$d — %2$d recent notes', 'ai-copilot-content-generator' ),
		$notebook_id,
		count( $passages )
	) ); ?></h3>

	<?php if ( empty( $passages ) ) : ?>
		<div class="bnp-empty"><?php esc_html_e( 'No notes yet. Add a passage to start.', 'ai-copilot-content-generator' ); ?></div>
	<?php else : ?>
		<ul class="bnp-list">
			<?php foreach ( $passages as $p ) :
				$pid     = (int) $p['id'];
				$content = (string) ( $p['content'] ?? '' );
				$origin  = (string) ( $p['origin'] ?? '' );
				$created = (string) ( $p['created_at'] ?? '' );
				$snippet = mb_substr( $content, 0, 280 );
				if ( mb_strlen( $content ) > 280 ) { $snippet .= '…'; }
				?>
				<li class="bnp-item" data-passage-id="<?php echo $pid; ?>">
					<div>
						<div class="bnp-item-content"><?php echo esc_html( $snippet ); ?></div>
						<div class="bnp-item-meta">
							<?php echo esc_html( sprintf( '#%d · %s · %s', $pid, $origin, $created ) ); ?>
						</div>
					</div>
					<div class="bnp-actions">
						<button type="button" class="bnp-btn bnp-tag-note" data-action="tag-note">
							🏷 <?php esc_html_e( 'Tag note', 'ai-copilot-content-generator' ); ?>
						</button>
						<input type="text" class="bnp-tag-input" placeholder="<?php esc_attr_e( 'tag (e.g. publish-fb)', 'ai-copilot-content-generator' ); ?>" maxlength="64">
						<button type="button" class="bnp-btn bnp-trigger" data-action="trigger-workflow" data-trigger-workflow="1">
							⚡ <?php esc_html_e( 'Trigger workflow', 'ai-copilot-content-generator' ); ?>
						</button>
						<div class="bnp-status" aria-live="polite"></div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<script>
	(function () {
		const panel = document.currentScript.parentElement;
		if ( ! panel || panel.dataset.bnpInit === '1' ) return;
		panel.dataset.bnpInit = '1';

		const restBase    = panel.dataset.restBase;
		const nonce       = panel.dataset.restNonce;
		const triggerTag  = panel.dataset.triggerTag || 'trigger';

		function setStatus(item, msg, kind) {
			const el = item.querySelector('.bnp-status');
			if (!el) return;
			el.textContent = msg || '';
			el.classList.remove('bnp-ok','bnp-err');
			if (kind) el.classList.add('bnp-' + kind);
		}

		async function postJSON(url, body) {
			const r = await fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify(body || {})
			});
			const j = await r.json().catch(() => ({}));
			if (!r.ok || j.code) {
				throw new Error((j && (j.message || j.code)) || ('HTTP ' + r.status));
			}
			return j;
		}

		panel.addEventListener('click', async function (ev) {
			const btn = ev.target.closest('.bnp-btn');
			if (!btn) return;
			const item = btn.closest('.bnp-item');
			if (!item) return;
			const pid  = item.dataset.passageId;
			if (!pid) return;

			const action = btn.dataset.action;

			if (action === 'tag-note') {
				const input = item.querySelector('.bnp-tag-input');
				if (input.style.display !== 'block') {
					input.style.display = 'block';
					input.focus();
					setStatus(item, 'Type a tag and press Enter.', null);
					return;
				}
				const tag = (input.value || '').trim();
				if (!tag) { setStatus(item, 'Tag empty.', 'err'); return; }
				btn.disabled = true; setStatus(item, 'Tagging…', null);
				try {
					await postJSON(restBase + pid + '/tag', { tag, action: 'added' });
					setStatus(item, 'Tagged: ' + tag, 'ok');
					input.value = '';
				} catch (e) {
					setStatus(item, 'Error: ' + e.message, 'err');
				} finally { btn.disabled = false; }
				return;
			}

			if (action === 'trigger-workflow') {
				btn.disabled = true; setStatus(item, 'Triggering…', null);
				try {
					await postJSON(restBase + pid + '/trigger-workflow', { tag: triggerTag });
					setStatus(item, 'Workflow event fired (#' + triggerTag + ').', 'ok');
				} catch (e) {
					setStatus(item, 'Error: ' + e.message, 'err');
				} finally { btn.disabled = false; }
			}
		});

		// Submit tag on Enter inside the input.
		panel.addEventListener('keydown', function (ev) {
			if (ev.key !== 'Enter' || !ev.target.classList.contains('bnp-tag-input')) return;
			ev.preventDefault();
			const item = ev.target.closest('.bnp-item');
			const btn  = item && item.querySelector('.bnp-tag-note');
			if (btn) btn.click();
		});
	})();
	</script>
</div>
