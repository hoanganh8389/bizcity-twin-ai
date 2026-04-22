<!DOCTYPE html>
<html lang="vi">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Code Builder — BizCity</title>
	<?php wp_head(); ?>
</head>
<body class="bzcode-editor-page">

<div id="bzcode-app" data-project-id="<?php echo esc_attr( $project_id ); ?>">

	<!-- Top Bar -->
	<header class="bzcode-header">
		<div class="bzcode-header__left">
			<a href="<?php echo esc_url( home_url( '/tool-code/' ) ); ?>" class="bzcode-logo">
				🖥️ <span>Code Builder</span>
			</a>
		</div>
		<div class="bzcode-header__center">
			<div class="bzcode-stack-selector" id="bzcode-stack-selector">
				<!-- Populated by JS -->
			</div>
		</div>
		<div class="bzcode-header__right">
			<button class="bzcode-btn bzcode-btn--secondary" id="bzcode-btn-download">⬇ Download</button>
			<button class="bzcode-btn bzcode-btn--primary" id="bzcode-btn-publish">🚀 Xuất bản</button>
			<button class="bzcode-btn bzcode-btn--danger" id="bzcode-btn-delete-page" style="display:none;" title="Xóa trang đã xuất bản">🗑️ Xóa page</button>
		</div>
	</header>

	<!-- Main Layout: 2-column (Sources+Chat | Editor+Preview) -->
	<div class="bzcode-workspace">

		<!-- LEFT COLUMN: Sources + Chat -->
		<div class="bzcode-left-col">

			<!-- Sources Panel (rendered by BZTwinSources widget) -->
			<aside class="bzcode-panel bzcode-panel--sources" id="bzcode-sources-panel">
				<!-- Widget renders its own DOM here -->
			</aside>

			<!-- Chat / Prompt Panel -->
			<aside class="bzcode-panel bzcode-panel--chat" id="bzcode-chat-panel">
				<div class="bzcode-chat__header">
					<h3>💬 Chỉnh sửa</h3>
					<span class="bzcode-chat__hint">Yêu cầu AI chỉnh sửa nội dung</span>
				</div>

				<!-- Start Pane — input modes (shown when no project) -->
				<div class="bzcode-start-pane" id="bzcode-start-pane">
					<div class="bzcode-input-tabs" id="bzcode-input-tabs">
						<button class="bzcode-input-tab bzcode-input-tab--active" data-input-tab="upload">📸 Upload</button>
						<button class="bzcode-input-tab" data-input-tab="url">🌐 URL</button>
						<button class="bzcode-input-tab" data-input-tab="text">✏️ Mô tả</button>
						<button class="bzcode-input-tab" data-input-tab="import">📋 Import</button>
					</div>

					<div class="bzcode-input-panel bzcode-input-panel--active" data-input-panel="upload">
						<div class="bzcode-dropzone" id="bzcode-dropzone">
							<p>📸 Kéo thả screenshot/mockup vào đây</p>
							<p class="bzcode-dropzone__hint">hoặc click để chọn file</p>
							<input type="file" accept="image/*" multiple hidden id="bzcode-file-input">
						</div>
						<button class="bzcode-btn bzcode-btn--secondary bzcode-btn--block" id="bzcode-btn-screencapture" title="Chụp màn hình trực tiếp">
							🖥️ Chụp màn hình
						</button>
					</div>

					<div class="bzcode-input-panel" data-input-panel="url">
						<div class="bzcode-url-input">
							<input type="url" id="bzcode-url-input" placeholder="https://example.com" autocomplete="url" spellcheck="false">
							<button class="bzcode-btn bzcode-btn--primary" id="bzcode-btn-fetch-url">Chụp →</button>
						</div>
						<p class="bzcode-input-hint">Nhập URL trang web → AI chụp screenshot và clone lại</p>
						<div class="bzcode-url-preview" id="bzcode-url-preview"></div>
					</div>

					<div class="bzcode-input-panel" data-input-panel="text">
						<p class="bzcode-input-hint">Mô tả trang web bạn muốn tạo bên ô chat phía dưới, rồi nhấn <strong>Tạo Code ▶</strong></p>
					</div>

					<div class="bzcode-input-panel" data-input-panel="import">
						<textarea id="bzcode-import-code" placeholder="Paste HTML/CSS/JS code vào đây..." rows="8" spellcheck="false"></textarea>
						<button class="bzcode-btn bzcode-btn--primary bzcode-btn--block" id="bzcode-btn-import">📋 Import Code</button>
						<p class="bzcode-input-hint">Paste code HTML có sẵn → chỉnh sửa bằng AI</p>
					</div>
				</div>

				<!-- Chat Messages -->
				<div class="bzcode-chat__messages" id="bzcode-chat-messages">
					<div class="bzcode-chat__empty">
						<div class="bzcode-chat__empty-icon">💬</div>
						Gửi yêu cầu tạo hoặc chỉnh sửa code tại đây.
						<div class="bzcode-chat__empty-hint">VD: "Tạo trang landing page giới thiệu sản phẩm"<br>"Đổi màu nền sang xanh", "Thêm form liên hệ"</div>
					</div>
				</div>

				<!-- Input -->
				<div class="bzcode-chat__input">
					<div class="bzcode-image-preview" id="bzcode-image-preview"></div>
					<textarea id="bzcode-prompt-input" placeholder="Yêu cầu chỉnh sửa..." rows="3"></textarea>
					<div class="bzcode-chat__actions">
						<button class="bzcode-btn bzcode-btn--attach" id="bzcode-btn-attach" title="Đính kèm ảnh screenshot">
							📎 Screenshot
						</button>
						<input type="file" accept="image/*" multiple hidden id="bzcode-chat-file-input">
						<button class="bzcode-btn bzcode-btn--primary" id="bzcode-btn-send">CHỈNH SỬA ▶</button>
					</div>
					<div class="bzcode-chat__drop-overlay" id="bzcode-chat-drop-overlay">📸 Thả ảnh screenshot vào đây</div>
				</div>

				<!-- History / Checkpoints (bottom of chat) -->
				<div class="bzcode-history-inline" id="bzcode-history-inline">
					<div class="bzcode-history__header">
						<button class="bzcode-history__toggle" id="bzcode-btn-toggle-history">
							📋 Lịch sử (<span id="bzcode-history-count">0</span> phiên bản)
						</button>
						<button class="bzcode-btn bzcode-btn--sm" id="bzcode-btn-refresh-history" title="Refresh">🔄</button>
					</div>
					<div class="bzcode-history__list" id="bzcode-history-list" style="display:none;">
						<p class="bzcode-history__empty">Chưa có checkpoint nào.</p>
					</div>
				</div>
			</aside>

		</div>

		<!-- RIGHT COLUMN: Editor + Preview -->
		<section class="bzcode-panel bzcode-panel--editor" id="bzcode-editor-panel">
			<div class="bzcode-editor__tabs">
				<div class="bzcode-tab bzcode-tab--active" data-tab="code">📝 Code</div>
				<div class="bzcode-tab" data-tab="preview">👁 Preview</div>
				<div class="bzcode-tab" data-tab="split">⬜ Split</div>
			</div>

			<!-- Code Editor Area -->
			<div class="bzcode-editor__code" id="bzcode-code-area">
				<div class="bzcode-code-status" id="bzcode-code-status">
					<span class="bzcode-code-status__dots"><span></span><span></span><span></span></span>
					<span class="bzcode-code-status__text">AI đang sinh code...</span>
				</div>
				<textarea id="bzcode-code-editor" spellcheck="false"></textarea>
			</div>

			<!-- Preview iframe -->
			<div class="bzcode-editor__preview" id="bzcode-preview-area" style="display:none;">
				<iframe id="bzcode-preview-iframe" sandbox="allow-scripts allow-forms allow-same-origin"></iframe>
			</div>
		</section>

	</div>

</div>

<?php wp_footer(); ?>
</body>
</html>
