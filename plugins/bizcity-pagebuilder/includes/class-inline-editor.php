<?php
/**
 * Inline Editor
 *
 * Adds contenteditable inline editing to published BZPB pages
 * 
 * @package    BizCity_Page_Builder
 * @subpackage Inline_Editor
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BZPB_Inline_Editor {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		// Validate user exists before checking capability
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return;
		}
		
		// Only for admin users
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_action( 'wp_footer', [ __CLASS__, 'render_edit_button' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/**
	 * Check if current page is a published BZPB page
	 *
	 * @return int|false Page ID or false
	 */
	private static function get_bzpb_page_id() {
		if ( ! is_singular( 'page' ) ) {
			return false;
		}

		$page_id = get_the_ID();
		$meta = get_post_meta( $page_id, '_bzpb_project_id', true );

		return $meta ? $page_id : false;
	}

	/**
	 * Render edit button overlay
	 */
	public static function render_edit_button() {
		$page_id = self::get_bzpb_page_id();
		if ( ! $page_id ) {
			return;
		}

		$project_id = get_post_meta( $page_id, '_bzpb_project_id', true );
		$edit_url = admin_url( "admin.php?page=bzpb-dashboard&project_id={$project_id}" );

		?>
		<div id="bzpb-inline-toolbar" style="display: none;">
			<div class="bzpb-toolbar-inner">
				<span class="bzpb-toolbar-label">✏️ BizCity Page Builder</span>
				<button id="bzpb-edit-toggle" class="bzpb-btn bzpb-btn-primary">Chỉnh sửa</button>
				<a href="<?php echo esc_url( $edit_url ); ?>" class="bzpb-btn bzpb-btn-secondary">Mở Editor</a>
				<button id="bzpb-save-inline" class="bzpb-btn bzpb-btn-success" style="display: none;">💾 Lưu</button>
				<button id="bzpb-cancel-inline" class="bzpb-btn bzpb-btn-danger" style="display: none;">✖ Hủy</button>
			</div>
		</div>

		<div id="bzpb-inline-sidebar" style="display: none;">
			<div class="bzpb-sidebar-header">
				<h3>Chỉnh sửa nội dung</h3>
				<button id="bzpb-close-sidebar">✖</button>
			</div>
			<div class="bzpb-sidebar-content">
				<p>Click vào các block để chỉnh sửa trực tiếp nội dung. Các thay đổi sẽ được lưu khi bạn nhấn "Lưu".</p>
				<div id="bzpb-selected-block-info"></div>
			</div>
		</div>

		<style>
		#bzpb-inline-toolbar {
			position: fixed;
			top: 32px;
			left: 50%;
			transform: translateX(-50%);
			z-index: 999999;
			background: #18181b;
			border: 1px solid #27272a;
			border-radius: 8px;
			padding: 8px 16px;
			box-shadow: 0 10px 40px rgba(0,0,0,0.5);
			animation: bzpbSlideDown .3s ease;
		}

		@keyframes bzpbSlideDown {
			from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
			to { opacity: 1; transform: translateX(-50%) translateY(0); }
		}

		.bzpb-toolbar-inner {
			display: flex;
			align-items: center;
			gap: 12px;
		}

		.bzpb-toolbar-label {
			font-size: 13px;
			color: #a1a1aa;
			font-weight: 500;
		}

		.bzpb-btn {
			padding: 6px 12px;
			border-radius: 6px;
			border: none;
			font-size: 12px;
			font-weight: 600;
			cursor: pointer;
			transition: all .2s ease;
			text-decoration: none;
			display: inline-block;
		}

		.bzpb-btn-primary {
			background: #2563eb;
			color: #fff;
		}

		.bzpb-btn-primary:hover {
			background: #1d4ed8;
		}

		.bzpb-btn-secondary {
			background: #27272a;
			color: #fafafa;
		}

		.bzpb-btn-secondary:hover {
			background: #3f3f46;
		}

		.bzpb-btn-success {
			background: #22c55e;
			color: #000;
		}

		.bzpb-btn-success:hover {
			background: #16a34a;
		}

		.bzpb-btn-danger {
			background: #ef4444;
			color: #fff;
		}

		.bzpb-btn-danger:hover {
			background: #dc2626;
		}

		#bzpb-inline-sidebar {
			position: fixed;
			top: 0;
			right: 0;
			width: 320px;
			height: 100vh;
			background: #18181b;
			border-left: 1px solid #27272a;
			z-index: 999998;
			box-shadow: -10px 0 40px rgba(0,0,0,0.5);
			animation: bzpbSlideIn .3s ease;
		}

		@keyframes bzpbSlideIn {
			from { transform: translateX(100%); }
			to { transform: translateX(0); }
		}

		.bzpb-sidebar-header {
			padding: 16px;
			border-bottom: 1px solid #27272a;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}

		.bzpb-sidebar-header h3 {
			margin: 0;
			font-size: 14px;
			color: #fafafa;
			font-weight: 600;
		}

		#bzpb-close-sidebar {
			background: none;
			border: none;
			color: #a1a1aa;
			font-size: 18px;
			cursor: pointer;
			padding: 0;
			width: 24px;
			height: 24px;
			display: flex;
			align-items: center;
			justify-content: center;
		}

		#bzpb-close-sidebar:hover {
			color: #fafafa;
		}

		.bzpb-sidebar-content {
			padding: 16px;
			color: #a1a1aa;
			font-size: 13px;
			line-height: 1.6;
		}

		.bzpb-editable {
			outline: 2px dashed rgba(37,99,235,0.5) !important;
			outline-offset: 4px;
			cursor: text !important;
			transition: outline .2s ease;
		}

		.bzpb-editable:hover {
			outline-color: rgba(37,99,235,0.8) !important;
		}

		.bzpb-editable:focus {
			outline: 2px solid #2563eb !important;
		}

		.bzpb-editing-active * {
			user-select: text !important;
		}

		@media (max-width: 768px) {
			#bzpb-inline-toolbar {
				left: 8px;
				right: 8px;
				transform: translateX(0);
				width: auto;
			}

			.bzpb-toolbar-inner {
				flex-wrap: wrap;
				justify-content: center;
			}

			#bzpb-inline-sidebar {
				width: 100%;
			}
		}
		</style>

		<script>
		(function() {
			'use strict';

			let editMode = false;
			let originalContent = {};
			let editableElements = [];

			const toolbar = document.getElementById('bzpb-inline-toolbar');
			const sidebar = document.getElementById('bzpb-inline-sidebar');
			const editToggle = document.getElementById('bzpb-edit-toggle');
			const saveBtn = document.getElementById('bzpb-save-inline');
			const cancelBtn = document.getElementById('bzpb-cancel-inline');
			const closeSidebar = document.getElementById('bzpb-close-sidebar');

			// Show toolbar
			toolbar.style.display = 'block';

			// Toggle edit mode
			editToggle.addEventListener('click', function() {
				editMode = !editMode;
				
				if (editMode) {
					enableEditMode();
				} else {
					disableEditMode();
				}
			});

			// Save changes
			saveBtn.addEventListener('click', function() {
				saveInlineEdits();
			});

			// Cancel changes
			cancelBtn.addEventListener('click', function() {
				restoreOriginalContent();
				disableEditMode();
			});

			// Close sidebar
			closeSidebar.addEventListener('click', function() {
				sidebar.style.display = 'none';
			});

			function enableEditMode() {
				document.body.classList.add('bzpb-editing-active');
				editToggle.textContent = 'Đang chỉnh sửa...';
				editToggle.style.background = '#16a34a';
				saveBtn.style.display = 'inline-block';
				cancelBtn.style.display = 'inline-block';
				sidebar.style.display = 'block';

				// Find editable blocks (target common text containers)
				const selectors = [
					'.bzpb-page h1',
					'.bzpb-page h2',
					'.bzpb-page h3',
					'.bzpb-page p',
					'.bzpb-page span:not(:empty)',
					'.bzpb-page a',
					'.bzpb-page button',
					'[class*="headline"]',
					'[class*="title"]',
					'[class*="description"]',
					'[class*="text"]'
				];

				editableElements = [];
				selectors.forEach(selector => {
					const elements = document.querySelectorAll(selector);
					elements.forEach(el => {
						// Skip if already editable or empty
						if (el.contentEditable === 'true' || !el.textContent.trim()) {
							return;
						}

						// Store original content
						const id = 'bzpb-el-' + editableElements.length;
						el.dataset.bzpbId = id;
						originalContent[id] = el.innerHTML;
						editableElements.push(el);

						// Make editable
						el.contentEditable = 'true';
						el.classList.add('bzpb-editable');

						// Add focus handler
						el.addEventListener('focus', function() {
							document.getElementById('bzpb-selected-block-info').innerHTML = 
								'<strong>Đang chỉnh sửa:</strong><br>' + 
								'<code>' + this.tagName.toLowerCase() + (this.className ? '.' + this.className.split(' ')[0] : '') + '</code>';
						});
					});
				});

				console.log('[BZPB] Enabled edit mode for ' + editableElements.length + ' elements');
			}

			function disableEditMode() {
				document.body.classList.remove('bzpb-editing-active');
				editToggle.textContent = 'Chỉnh sửa';
				editToggle.style.background = '#2563eb';
				saveBtn.style.display = 'none';
				cancelBtn.style.display = 'none';
				sidebar.style.display = 'none';

				editableElements.forEach(el => {
					el.contentEditable = 'false';
					el.classList.remove('bzpb-editable');
				});

				editMode = false;
			}

			function restoreOriginalContent() {
				editableElements.forEach(el => {
					const id = el.dataset.bzpbId;
					if (originalContent[id]) {
						el.innerHTML = originalContent[id];
					}
				});
			}

			function saveInlineEdits() {
				const changes = {};
				let hasChanges = false;

				editableElements.forEach(el => {
					const id = el.dataset.bzpbId;
					const newContent = el.innerHTML;
					
					if (newContent !== originalContent[id]) {
						changes[id] = {
							selector: getElementSelector(el),
							oldContent: originalContent[id],
							newContent: newContent
						};
						hasChanges = true;
					}
				});

				if (!hasChanges) {
					alert('Không có thay đổi nào để lưu.');
					return;
				}

				// Show loading
				saveBtn.textContent = '⏳ Đang lưu...';
				saveBtn.disabled = true;

				// Send to server
				fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: 'bzpb_save_inline_edits',
						nonce: '<?php echo wp_create_nonce( 'bzpb-inline-edit' ); ?>',
						page_id: <?php echo $page_id; ?>,
						changes: JSON.stringify(changes)
					})
				})
				.then(res => res.json())
				.then(data => {
					if (data.success) {
						alert('✅ Đã lưu thành công!');
						// Update original content
						originalContent = {};
						editableElements.forEach(el => {
							originalContent[el.dataset.bzpbId] = el.innerHTML;
						});
						location.reload(); // Reload để thấy thay đổi
					} else {
						alert('❌ Lỗi: ' + (data.data?.message || 'Unknown error'));
					}
				})
				.catch(err => {
					console.error(err);
					alert('❌ Có lỗi xảy ra khi lưu.');
				})
				.finally(() => {
					saveBtn.textContent = '💾 Lưu';
					saveBtn.disabled = false;
				});
			}

			function getElementSelector(el) {
				if (el.id) return '#' + el.id;
				if (el.className) {
					const classes = el.className.split(' ').filter(c => c && !c.startsWith('bzpb-'));
					if (classes.length) return '.' + classes[0];
				}
				return el.tagName.toLowerCase();
			}
		})();
		</script>
		<?php
	}

	/**
	 * Enqueue inline editor assets
	 */
	public static function enqueue_assets() {
		if ( ! self::get_bzpb_page_id() ) {
			return;
		}

		// Already included inline in render_edit_button()
	}
}
