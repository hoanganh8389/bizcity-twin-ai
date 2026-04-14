<?php
/**
 * Admin Templates — list + add/edit.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Handle form submit ── */
if ( isset( $_POST['bzcc_tpl_nonce'] ) && wp_verify_nonce( $_POST['bzcc_tpl_nonce'], 'bzcc_save_template' ) ) {
	$tpl_data = [
		'slug'             => sanitize_title( $_POST['slug'] ?? '' ),
		'category_id'      => absint( $_POST['category_id'] ?? 0 ),
		'title'            => sanitize_text_field( $_POST['title'] ?? '' ),
		'description'      => sanitize_textarea_field( $_POST['description'] ?? '' ),
		'icon_emoji'       => sanitize_text_field( $_POST['icon_emoji'] ?? '' ),
		'tags'             => sanitize_text_field( $_POST['tags'] ?? '' ),
		'badge_text'       => sanitize_text_field( $_POST['badge_text'] ?? '' ),
		'badge_color'      => sanitize_hex_color( $_POST['badge_color'] ?? '' ) ?: '',
		'is_featured'      => absint( $_POST['is_featured'] ?? 0 ),
		'sort_order'       => absint( $_POST['sort_order'] ?? 0 ),
		'status'           => sanitize_key( $_POST['status'] ?? 'active' ),
		'form_fields'      => wp_unslash( $_POST['form_fields'] ?? '[]' ),
		'system_prompt'    => wp_unslash( $_POST['system_prompt'] ?? '' ),
		'outline_prompt'   => wp_unslash( $_POST['outline_prompt'] ?? '' ),
		'chunk_prompt'     => wp_unslash( $_POST['chunk_prompt'] ?? '' ),
		'model_purpose'    => sanitize_key( $_POST['model_purpose'] ?? 'content_creation' ),
		'temperature'      => floatval( $_POST['temperature'] ?? 0.7 ),
		'max_tokens'       => absint( $_POST['max_tokens'] ?? 4000 ),
		'wizard_steps'     => wp_unslash( $_POST['wizard_steps'] ?? '[]' ),
		'output_platforms' => wp_unslash( $_POST['output_platforms'] ?? '[]' ),
	];

	$edit_id = absint( $_POST['edit_id'] ?? 0 );

	if ( $edit_id ) {
		BZCC_Template_Manager::update( $edit_id, $tpl_data );
		$msg = 'Đã cập nhật template.';
	} else {
		$new_insert_id = BZCC_Template_Manager::insert( $tpl_data );
		if ( $new_insert_id ) {
			$redirect_url = admin_url( 'admin.php?page=' . ( BZCC_Admin_Menu::MENU_SLUG . '-templates' ) . '&action=edit&id=' . $new_insert_id . '&msg=created' );
			wp_safe_redirect( $redirect_url );
			exit;
		}
		$msg = 'Đã tạo template mới.';
	}
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
}

/* ── Handle actions ── */
if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) ) {
	$act = sanitize_key( $_GET['action'] );
	$tid = absint( $_GET['id'] );

	if ( $act === 'delete' && wp_verify_nonce( $_GET['_wpnonce'], 'bzcc_delete_tpl_' . $tid ) ) {
		BZCC_Template_Manager::delete( $tid );
		echo '<div class="notice notice-warning is-dismissible"><p>Đã xóa template.</p></div>';
	}

	if ( $act === 'duplicate' && wp_verify_nonce( $_GET['_wpnonce'], 'bzcc_dup_tpl_' . $tid ) ) {
		$new_id = BZCC_Template_Manager::duplicate( $tid );
		if ( $new_id ) {
			echo '<div class="notice notice-success is-dismissible"><p>Đã tạo bản sao (ID: ' . (int) $new_id . ').</p></div>';
		}
	}
}

$page_slug  = BZCC_Admin_Menu::MENU_SLUG . '-templates';
$categories = BZCC_Category_Manager::get_all_active();
$editing    = null;

if ( $action === 'edit' && $id ) {
	$editing = BZCC_Template_Manager::get_by_id( $id );
}

if ( $action === 'add' || $editing ) :
?>
<?php
if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'created' ) {
	echo '<div class="notice notice-success is-dismissible"><p>Đã tạo template mới. Bạn có thể tiếp tục chỉnh sửa.</p></div>';
}
?>
<div class="wrap bzcc-wrap">
	<h1>
		<?php echo $editing ? 'Sửa Template' : 'Tạo Template mới'; ?>
		<?php if ( $editing ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug . '&action=add' ) ); ?>" class="page-title-action">＋ Thêm mới</a>
			<a href="#" class="page-title-action bzcc-tpl-export" data-id="<?php echo (int) $editing->id; ?>">📤 Export JSON</a>
			<a href="#" class="page-title-action bzcc-tpl-duplicate" data-id="<?php echo (int) $editing->id; ?>">📋 Nhân bản</a>
		<?php endif; ?>
	</h1>

	<form method="post" class="bzcc-template-form">
		<?php wp_nonce_field( 'bzcc_save_template', 'bzcc_tpl_nonce' ); ?>
		<input type="hidden" name="edit_id" value="<?php echo $editing ? (int) $editing->id : 0; ?>">

		<div class="bzcc-form-grid">
			<!-- Basic info -->
			<div class="bzcc-form-section">
				<h2>📝 Thông tin cơ bản</h2>
				<table class="form-table">
					<tr>
						<th><label for="title">Tên template</label></th>
						<td><input type="text" name="title" id="title" class="large-text" required
							value="<?php echo esc_attr( $editing->title ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="slug">Slug</label></th>
						<td><input type="text" name="slug" id="slug" class="regular-text" required
							value="<?php echo esc_attr( $editing->slug ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="category_id">Danh mục</label></th>
						<td>
							<select name="category_id" id="category_id">
								<option value="0">— Chọn danh mục —</option>
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo (int) $cat->id; ?>"
										<?php selected( $editing->category_id ?? 0, $cat->id ); ?>>
										<?php echo esc_html( $cat->icon_emoji . ' ' . $cat->title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="description">Mô tả</label></th>
						<td><textarea name="description" id="description" rows="3" class="large-text"><?php
							echo esc_textarea( $editing->description ?? '' );
						?></textarea></td>
					</tr>
					<tr>
						<th><label for="icon_emoji">Icon Emoji</label></th>
						<td>
							<div class="bzcc-emoji-picker" data-target="icon_emoji">
								<button type="button" class="bzcc-emoji-picker__trigger">
									<span class="bzcc-emoji-picker__preview"><?php echo esc_html( $editing->icon_emoji ?? '' ); ?></span>
									<span class="bzcc-emoji-picker__placeholder">Chọn icon</span>
									<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
								</button>
								<div class="bzcc-emoji-picker__dropdown"></div>
								<input type="hidden" name="icon_emoji" id="icon_emoji" value="<?php echo esc_attr( $editing->icon_emoji ?? '' ); ?>">
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="tags">Tags (comma-separated)</label></th>
						<td><input type="text" name="tags" id="tags" class="large-text"
							value="<?php echo esc_attr( $editing->tags ?? '' ); ?>"
							placeholder="viết bài bán hàng,copywriting,content"></td>
					</tr>
					<tr>
						<th>Badge</th>
						<td>
							<div class="bzcc-badge-picker">
								<div class="bzcc-badge-picker__presets" id="bzcc-badge-presets">
									<button type="button" class="bzcc-badge-picker__chip" data-badge="🔥 Phổ biến" data-color="#ef4444">
										<span class="bzcc-badge-picker__chip-preview" style="background:#ef4444;">🔥 Phổ biến</span>
									</button>
									<button type="button" class="bzcc-badge-picker__chip" data-badge="⭐ Mới" data-color="#f59e0b">
										<span class="bzcc-badge-picker__chip-preview" style="background:#f59e0b;">⭐ Mới</span>
									</button>
									<button type="button" class="bzcc-badge-picker__chip" data-badge="💎 Premium" data-color="#6366f1">
										<span class="bzcc-badge-picker__chip-preview" style="background:#6366f1;">💎 Premium</span>
									</button>
									<button type="button" class="bzcc-badge-picker__chip" data-badge="🚀 Hot" data-color="#ec4899">
										<span class="bzcc-badge-picker__chip-preview" style="background:#ec4899;">🚀 Hot</span>
									</button>
									<button type="button" class="bzcc-badge-picker__chip" data-badge="✅ Miễn phí" data-color="#10b981">
										<span class="bzcc-badge-picker__chip-preview" style="background:#10b981;">✅ Miễn phí</span>
									</button>
									<button type="button" class="bzcc-badge-picker__chip bzcc-badge-picker__chip--none" data-badge="" data-color="">
										<span>✕ Không badge</span>
									</button>
								</div>
								<div class="bzcc-badge-picker__custom">
									<input type="text" name="badge_text" class="regular-text" style="width:180px"
										value="<?php echo esc_attr( $editing->badge_text ?? '' ); ?>" placeholder="Hoặc nhập tùy chỉnh...">
									<input type="color" name="badge_color" style="vertical-align:middle"
										value="<?php echo esc_attr( $editing->badge_color ?? '#6366f1' ); ?>">
									<span class="bzcc-badge-picker__live-preview" id="bzcc-badge-live-preview"></span>
								</div>
							</div>
						</td>
					</tr>
					<tr>
						<th>Tùy chọn</th>
						<td>
							<label><input type="checkbox" name="is_featured" value="1"
								<?php checked( $editing->is_featured ?? 0, 1 ); ?>> Nổi bật</label><br>
							<label>Thứ tự: <input type="number" name="sort_order" class="small-text"
								value="<?php echo (int) ( $editing->sort_order ?? 0 ); ?>"></label>
						</td>
					</tr>
					<tr>
						<th><label for="status">Trạng thái</label></th>
						<td>
							<select name="status" id="status">
								<option value="active" <?php selected( $editing->status ?? 'active', 'active' ); ?>>Active</option>
								<option value="draft" <?php selected( $editing->status ?? '', 'draft' ); ?>>Draft</option>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<!-- Form Fields — Multi-Step Builder -->
			<div class="bzcc-form-section">
				<h2>📋 Form Builder</h2>
				<p class="description" style="margin-bottom:12px">
					Mặc định là 1 bước. Nhấn "Thêm bước" để tạo wizard nhiều bước — Frontend sẽ tự hiển thị dạng step-by-step.
				</p>
				<div id="bzcc-form-builder"></div>
				<textarea name="form_fields" rows="4" class="large-text code" style="display:none"><?php
					echo esc_textarea( $editing->form_fields ?? '[]' );
				?></textarea>
				<textarea name="wizard_steps" rows="4" class="large-text code" style="display:none"><?php
					echo esc_textarea( $editing->wizard_steps ?? '[]' );
				?></textarea>
			</div>

			<!-- AI Prompt Pipeline -->
			<div class="bzcc-form-section bzcc-prompt-section">
				<h2>🤖 AI Prompt Pipeline</h2>

				<!-- Pipeline flow diagram -->
				<div class="bzcc-pipeline-flow">
					<div class="bzcc-pipeline-step bzcc-pipeline-step--active">
						<span class="bzcc-pipeline-num">1</span>
						<span class="bzcc-pipeline-label">User điền form</span>
					</div>
					<span class="bzcc-pipeline-arrow">→</span>
					<div class="bzcc-pipeline-step bzcc-pipeline-step--active">
						<span class="bzcc-pipeline-num">2</span>
						<span class="bzcc-pipeline-label">System Prompt</span>
					</div>
					<span class="bzcc-pipeline-arrow">→</span>
					<div class="bzcc-pipeline-step">
						<span class="bzcc-pipeline-num">3</span>
						<span class="bzcc-pipeline-label">Outline <small>(tùy chọn)</small></span>
					</div>
					<span class="bzcc-pipeline-arrow">→</span>
					<div class="bzcc-pipeline-step">
						<span class="bzcc-pipeline-num">4</span>
						<span class="bzcc-pipeline-label">Chunks <small>(tùy chọn)</small></span>
					</div>
					<span class="bzcc-pipeline-arrow">→</span>
					<div class="bzcc-pipeline-step bzcc-pipeline-step--result">
						<span class="bzcc-pipeline-num">✓</span>
						<span class="bzcc-pipeline-label">Kết quả</span>
					</div>
				</div>

				<!-- Variable tags (populated by JS from form_fields) -->
				<div class="bzcc-prompt-vars" id="bzccPromptVars">
					<span class="bzcc-prompt-vars__title">📎 Biến có sẵn <small>(bấm để chèn vào prompt đang focus)</small>:</span>
					<div class="bzcc-prompt-vars__tags" id="bzccVarTags">
						<span class="bzcc-prompt-vars__empty">Thêm trường ở Form Builder bên trên để có biến dùng.</span>
					</div>
				</div>

				<!-- 1. System Prompt -->
				<div class="bzcc-prompt-card">
					<div class="bzcc-prompt-card__header">
						<div class="bzcc-prompt-card__icon">🧠</div>
						<div class="bzcc-prompt-card__info">
							<h3>System Prompt <span class="bzcc-prompt-required">bắt buộc</span></h3>
							<p>Đây là <strong>hướng dẫn chính</strong> gửi đến AI. Bạn mô tả AI là ai, cần tạo nội dung gì, 
							   theo phong cách nào. Dùng <code>{{slug}}</code> để chèn dữ liệu user nhập.</p>
						</div>
						<button type="button" class="bzcc-prompt-autogen" id="bzccAutoGenPrompt" title="Tự động tạo mẫu prompt từ các trường trong Form Builder">
							✨ Gợi ý prompt
						</button>
					</div>
					<textarea name="system_prompt" id="system_prompt" rows="8" class="large-text code bzcc-prompt-textarea" 
						placeholder="Ví dụ:&#10;Bạn là chuyên gia viết content {{platform}}.&#10;Hãy viết bài về chủ đề &quot;{{topic}}&quot; với giọng văn {{tone}}.&#10;Đối tượng: {{audience}}.&#10;Yêu cầu: hấp dẫn, có CTA, dài khoảng {{word_count}} từ."><?php
						echo esc_textarea( $editing->system_prompt ?? '' );
					?></textarea>
					<div class="bzcc-prompt-card__tip">
						💡 <strong>Mẹo:</strong> Prompt càng chi tiết → kết quả càng chính xác. Mô tả rõ giọng văn, độ dài, cấu trúc mong muốn.
					</div>
				</div>

				<!-- 2. Outline Prompt -->
				<div class="bzcc-prompt-card bzcc-prompt-card--optional">
					<div class="bzcc-prompt-card__header">
						<div class="bzcc-prompt-card__icon">📝</div>
						<div class="bzcc-prompt-card__info">
							<h3>Outline Prompt <span class="bzcc-prompt-optional">tùy chọn</span></h3>
							<p>Dùng cho <strong>bài viết dài</strong>. AI sẽ tạo dàn ý trước, rồi viết từng phần theo dàn ý.
							   Bỏ trống nếu chỉ cần AI viết liền 1 lần.</p>
						</div>
						<button type="button" class="bzcc-prompt-card__toggle" data-target="outline_prompt_wrap">
							<span class="dashicons dashicons-arrow-down-alt2"></span>
						</button>
					</div>
					<div id="outline_prompt_wrap" class="bzcc-prompt-card__body <?php echo empty( $editing->outline_prompt ?? '' ) ? 'bzcc-prompt-card__body--collapsed' : ''; ?>">
						<textarea name="outline_prompt" id="outline_prompt" rows="6" class="large-text code bzcc-prompt-textarea"
							placeholder="Ví dụ:&#10;Dựa trên yêu cầu trên, hãy tạo dàn ý gồm 5-7 heading chính.&#10;Mỗi heading kèm 1 dòng mô tả nội dung sẽ viết.&#10;Format: ## Heading\nMô tả ngắn"><?php
							echo esc_textarea( $editing->outline_prompt ?? '' );
						?></textarea>
					</div>
					<div class="bzcc-prompt-card__tip">
						💡 Khi bật Outline, AI sẽ gọi 2 lần: lần 1 tạo dàn ý, lần 2+ viết chi tiết từng phần.
					</div>
				</div>

				<!-- 3. Chunk Prompt -->
				<div class="bzcc-prompt-card bzcc-prompt-card--optional">
					<div class="bzcc-prompt-card__header">
						<div class="bzcc-prompt-card__icon">✂️</div>
						<div class="bzcc-prompt-card__info">
							<h3>Chunk Prompt <span class="bzcc-prompt-optional">tùy chọn</span></h3>
							<p>Dùng cùng Outline. Prompt này chạy cho <strong>mỗi section</strong> trong dàn ý.
							   Biến đặc biệt: <code>{{outline}}</code> = toàn bộ dàn ý, <code>{{current_section}}</code> = heading đang viết.</p>
						</div>
						<button type="button" class="bzcc-prompt-card__toggle" data-target="chunk_prompt_wrap">
							<span class="dashicons dashicons-arrow-down-alt2"></span>
						</button>
					</div>
					<div id="chunk_prompt_wrap" class="bzcc-prompt-card__body <?php echo empty( $editing->chunk_prompt ?? '' ) ? 'bzcc-prompt-card__body--collapsed' : ''; ?>">
						<textarea name="chunk_prompt" id="chunk_prompt" rows="6" class="large-text code bzcc-prompt-textarea"
							placeholder="Ví dụ:&#10;Đây là dàn ý bài viết:&#10;{{outline}}&#10;&#10;Hãy viết chi tiết phần: {{current_section}}&#10;Giữ đúng giọng văn, dài 200-400 từ cho phần này."><?php
							echo esc_textarea( $editing->chunk_prompt ?? '' );
						?></textarea>
					</div>
					<div class="bzcc-prompt-card__tip">
						💡 Nếu bỏ trống Outline Prompt phía trên thì Chunk Prompt cũng không cần.
					</div>
				</div>

				<!-- Model Settings -->
				<div class="bzcc-model-settings">
					<h3>⚙️ Cấu hình Model</h3>
					<div class="bzcc-model-grid">
						<div class="bzcc-model-card">
							<label class="bzcc-model-label">🎯 Mục đích sử dụng</label>
							<select name="model_purpose" class="bzcc-model-select">
								<option value="content_creation" <?php selected( $editing->model_purpose ?? 'content_creation', 'content_creation' ); ?>>
									✍️ Tạo nội dung (content_creation)
								</option>
								<option value="chat" <?php selected( $editing->model_purpose ?? '', 'chat' ); ?>>
									💬 Chat / tư vấn (chat)
								</option>
								<option value="analysis" <?php selected( $editing->model_purpose ?? '', 'analysis' ); ?>>
									📊 Phân tích dữ liệu (analysis)
								</option>
							</select>
							<p class="bzcc-model-hint">Ảnh hưởng đến model AI được chọn tự động.</p>
						</div>

						<div class="bzcc-model-card">
							<label class="bzcc-model-label">🎨 Nhiệt độ (Temperature)</label>
							<div class="bzcc-temp-control">
								<input type="range" name="temperature" id="bzcc_temp_range"
									min="0" max="2" step="0.05"
									value="<?php echo esc_attr( $editing->temperature ?? 0.7 ); ?>"
									class="bzcc-temp-slider">
								<output id="bzcc_temp_value" class="bzcc-temp-output"><?php echo esc_html( $editing->temperature ?? 0.7 ); ?></output>
							</div>
							<div class="bzcc-temp-scale">
								<span>🎯 Chính xác</span>
								<span>⚖️ Cân bằng</span>
								<span>🎲 Sáng tạo</span>
							</div>
							<p class="bzcc-model-hint">Thấp (0.1-0.3): chính xác, ít biến đổi. Cao (0.8-1.5): sáng tạo, đa dạng hơn.</p>
						</div>

						<div class="bzcc-model-card">
							<label class="bzcc-model-label">📏 Độ dài tối đa (Max tokens)</label>
							<input type="number" name="max_tokens" class="bzcc-model-input"
								value="<?php echo (int) ( $editing->max_tokens ?? 4000 ); ?>"
								min="100" max="128000" step="100">
							<p class="bzcc-model-hint">1 token ≈ 0.75 từ tiếng Anh, ≈ 0.5 từ tiếng Việt. 4000 tokens ≈ bài viết ~2000 từ.</p>
						</div>
					</div>
				</div>
			</div>

			<!-- Advanced JSON -->
			<div class="bzcc-form-section">
				<h2>⚙️ Advanced (JSON)</h2>
				<label>Output Platforms:</label>
				<textarea name="output_platforms" rows="3" class="large-text code"><?php
					echo esc_textarea( $editing->output_platforms ?? '["facebook","tiktok","zalo"]' );
				?></textarea>
			</div>
		</div>

		<?php submit_button( $editing ? 'Cập nhật Template' : 'Tạo Template' ); ?>
	</form>
</div>

<?php else :
	$templates = BZCC_Template_Manager::get_all();
?>
<div class="wrap bzcc-wrap">
	<h1>
		📝 Templates
		<a href="<?php echo esc_url( admin_url( "admin.php?page={$page_slug}&action=add" ) ); ?>" class="page-title-action">Thêm mới</a>
	</h1>

	<!-- ── Toolbar: Import / Export ── -->
	<div class="bzcc-tpl-toolbar" style="margin:16px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
		<button type="button" class="button" id="bzcc-export-all" title="Export tất cả templates thành file JSON">
			📤 Export tất cả
		</button>
		<button type="button" class="button" id="bzcc-import-btn" title="Import templates từ file JSON">
			📥 Import JSON
		</button>
		<input type="file" id="bzcc-import-file" accept=".json,application/json" style="display:none">
		<span id="bzcc-import-status" style="font-size:13px;color:#64748b;"></span>
	</div>

	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th>ID</th>
				<th>Icon</th>
				<th>Tên</th>
				<th>Danh mục</th>
				<th>Sử dụng</th>
				<th>Featured</th>
				<th>Trạng thái</th>
				<th>Hành động</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $templates ) ) : ?>
				<tr><td colspan="8">Chưa có template.</td></tr>
			<?php else : ?>
				<?php foreach ( $templates as $tpl ) :
					$cat = $tpl->category_id ? BZCC_Category_Manager::get_by_id( (int) $tpl->category_id ) : null;
				?>
					<tr data-tpl-id="<?php echo (int) $tpl->id; ?>">
						<td><?php echo (int) $tpl->id; ?></td>
						<td><?php echo esc_html( $tpl->icon_emoji ); ?></td>
						<td>
							<strong>
								<a href="<?php echo esc_url( admin_url( "admin.php?page={$page_slug}&action=edit&id={$tpl->id}" ) ); ?>">
									<?php echo esc_html( $tpl->title ); ?>
								</a>
							</strong>
							<br><code><?php echo esc_html( $tpl->slug ); ?></code>
						</td>
						<td><?php echo $cat ? esc_html( $cat->icon_emoji . ' ' . $cat->title ) : '—'; ?></td>
						<td><?php echo (int) $tpl->use_count; ?></td>
						<td><?php echo $tpl->is_featured ? '⭐' : ''; ?></td>
						<td><?php echo esc_html( $tpl->status ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( "admin.php?page={$page_slug}&action=edit&id={$tpl->id}" ) ); ?>">Sửa</a>
							|
							<a href="#" class="bzcc-tpl-duplicate" data-id="<?php echo (int) $tpl->id; ?>">Nhân bản</a>
							|
							<a href="#" class="bzcc-tpl-export" data-id="<?php echo (int) $tpl->id; ?>">Export</a>
							|
							<a href="<?php echo esc_url( wp_nonce_url(
								admin_url( "admin.php?page={$page_slug}&action=delete&id={$tpl->id}" ),
								'bzcc_delete_tpl_' . $tpl->id
							) ); ?>" onclick="return confirm('Xóa template này?');" class="delete">Xóa</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>
