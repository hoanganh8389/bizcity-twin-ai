<?php
/**
 * BizCity CRM — CF7 Success Modal
 *
 * Injects a branded success dialog overlay on the frontend whenever a CF7 form
 * is submitted successfully, replacing the default plain text response output.
 *
 * [2026-06-24 Johnny Chu] HOTFIX — moved from mu-plugin into bizcity-twin-crm
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_CF7_Success_Modal {

	public static function register(): void {
		// Only hook on frontend, only when CF7 is active.
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_footer', array( __CLASS__, 'output' ), 100 );
	}

	public static function output(): void {
		if ( ! class_exists( 'WPCF7' ) ) {
			return;
		}

		// [2026-06-24 Johnny Chu] HOTFIX — build per-form config map from email rules
		// so the modal shows cf7_notice + attachment_url instead of generic CF7 response output.
		$form_configs = array();
		if ( class_exists( 'BizCity_CRM_Email_Rules_Repo' ) ) {
			$rules = BizCity_CRM_Email_Rules_Repo::list_rules();
			foreach ( $rules as $rule ) {
				$event_key = (string) ( $rule['event_key'] ?? '' );
				// Extract numeric form ID from 'cf7_form_4355' or skip 'cf7_form_submitted'
				if ( preg_match( '/^cf7_form_(\d+)$/', $event_key, $m ) ) {
					$fid = (int) $m[1];
					if ( $fid > 0 && ! empty( $rule['is_enabled'] ) ) {
						$notice     = (string) ( $rule['cf7_notice'] ?? '' );
						$attach_url = (string) ( $rule['attachment_url'] ?? '' );
						if ( $notice !== '' || $attach_url !== '' ) {
							$form_configs[ $fid ] = array(
								'notice'         => $notice,
								'attachment_url' => $attach_url,
							);
						}
					}
				}
			}
		}
		?>
		<!-- [2026-06-24 Johnny Chu] HOTFIX bizcity-crm cf7-success-modal -->
		<style id="bzc-cf7-modal-css">
		#bzc-cf7-modal-overlay {
			display: none;
			position: fixed;
			inset: 0;
			z-index: 99999;
			background: rgba(15, 23, 42, 0.55);
			align-items: center;
			justify-content: center;
			animation: bzcFadeIn .2s ease;
		}
		#bzc-cf7-modal-overlay.is-open {
			display: flex;
		}
		@keyframes bzcFadeIn {
			from { opacity: 0; }
			to   { opacity: 1; }
		}
		#bzc-cf7-modal-box {
			background: #fff;
			border-radius: 20px;
			box-shadow: 0 24px 64px rgba(0,0,0,.22);
			padding: 40px 36px 32px;
			max-width: 420px;
			width: calc(100% - 32px);
			text-align: center;
			animation: bzcSlideUp .25s ease;
			position: relative;
		}
		@keyframes bzcSlideUp {
			from { transform: translateY(28px); opacity: 0; }
			to   { transform: translateY(0);   opacity: 1; }
		}
		#bzc-cf7-modal-icon {
			width: 72px;
			height: 72px;
			background: linear-gradient(135deg, #34d399 0%, #059669 100%);
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			margin: 0 auto 20px;
			font-size: 36px;
			line-height: 1;
		}
		#bzc-cf7-modal-title {
			font-size: 22px;
			font-weight: 700;
			color: #0f172a;
			margin: 0 0 10px;
			line-height: 1.3;
		}
		#bzc-cf7-modal-msg {
			font-size: 15px;
			color: #475569;
			margin: 0 0 28px;
			line-height: 1.6;
		}
		#bzc-cf7-modal-x {
			position: absolute;
			top: 14px;
			right: 18px;
			background: none;
			border: none;
			font-size: 22px;
			color: #94a3b8;
			cursor: pointer;
			line-height: 1;
			padding: 4px 6px;
		}
		#bzc-cf7-modal-x:hover { color: #475569; }
		</style>

		<div id="bzc-cf7-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bzc-cf7-modal-title">
			<div id="bzc-cf7-modal-box">
				<button id="bzc-cf7-modal-x" aria-label="Đóng">&#215;</button>
				<div id="bzc-cf7-modal-icon">&#10003;</div>
				<h2 id="bzc-cf7-modal-title">Đăng ký thành công!</h2>
				<p id="bzc-cf7-modal-msg">Cảm ơn bạn đã đăng ký. Chúng tôi sẽ liên hệ với bạn sớm nhất có thể.</p>

			</div>
		</div>

		<script id="bzc-cf7-modal-js">
		(function () {
			var overlay  = document.getElementById('bzc-cf7-modal-overlay');
			var msgEl    = document.getElementById('bzc-cf7-modal-msg');
			var xBtn     = document.getElementById('bzc-cf7-modal-x');
			if (!overlay) { return; }

			// Per-form config from PHP (cf7_notice + attachment_url keyed by form_id)
			var formConfigs = <?php echo wp_json_encode( $form_configs ?: new stdClass() ); ?>;

			function buildMsgHTML(notice, attachUrl) {
				var html = '';
				if (notice) {
					// Plain text → paragraphs
					var lines = notice.split('\n');
					for (var i = 0; i < lines.length; i++) {
						var line = lines[i].trim();
						if (line !== '') {
							html += '<p style="margin:0 0 8px">' + line + '</p>';
						}
					}
				}
				if (attachUrl) {
					html += '<div style="margin-top:14px">' +
						'<a href="' + attachUrl + '" target="_blank" rel="noopener noreferrer" ' +
						'style="display:inline-block;padding:10px 24px;background:linear-gradient(135deg,#6366f1,#4f46e5);' +
						'color:#fff;border-radius:50px;text-decoration:none;font-weight:600;font-size:14px">' +
						'⬇ Tải file ngay</a></div>';
				}
				return html;
			}

			function openModal(htmlContent) {
				if (msgEl) { msgEl.innerHTML = htmlContent || 'Cảm ơn bạn đã đăng ký. Chúng tôi sẽ liên hệ sớm!'; }
				overlay.classList.add('is-open');
				document.body.style.overflow = 'hidden';
				setTimeout(function () { xBtn && xBtn.focus(); }, 50);
			}

			function closeModal() {
				overlay.classList.remove('is-open');
				document.body.style.overflow = '';
			}

			xBtn && xBtn.addEventListener('click', closeModal);

			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) { closeModal(); }
			});

			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && overlay.classList.contains('is-open')) { closeModal(); }
			});

			document.addEventListener('wpcf7mailsent', function (e) {
				// CF7 v5+ provides contactFormId in event detail
				var detail = e.detail || {};
				var formId = detail.contactFormId || detail.id || 0;
				var cfg    = formId ? formConfigs[parseInt(formId, 10)] : null;

				if (cfg && (cfg.notice || cfg.attachment_url)) {
					openModal(buildMsgHTML(cfg.notice, cfg.attachment_url));
				} else {
					// Fallback: use CF7 default response output text
					var responseOutput = e.target && e.target.querySelector('.wpcf7-response-output');
					var msg = responseOutput ? responseOutput.textContent.trim() : '';
					openModal(msg ? '<p>' + msg + '</p>' : '');
				}
			}, false);
		})();
		</script>
		<?php
	}
}
