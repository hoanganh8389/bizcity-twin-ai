<?php
/**
 * Frontend Form View — Dynamic form rendered from template.form_fields.
 *
 * Variables available:
 *   $template    — template object
 *   $category    — category object (or null)
 *   $form_fields — decoded & sorted array of field definitions
 *
 * AIVA-inspired layout:
 *   - Sticky header with template info + step indicator
 *   - Card-wrapped form sections
 *   - Checkbox option cards with emoji + title + description + badges
 *   - Full-width textarea with placeholder
 *   - Bottom action bar: Back + Submit with zap icon
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$browse_url = home_url( 'creator/' );

// Group fields into wizard steps if defined
$wizard_steps = json_decode( $template->wizard_steps ?? '[]', true ) ?: [];
$has_wizard   = ! empty( $wizard_steps ) && count( $wizard_steps ) > 1;
$has_confirm  = $has_wizard; // Auto-add confirm step for multi-step wizards
$step_count   = $has_wizard ? count( $wizard_steps ) + ( $has_confirm ? 1 : 0 ) : 1;
?>

<!-- ── Template Header ── -->
<div class="bzcc-form-header">
	<div class="bzcc-form-header__left">
		<a href="<?php echo esc_url( $browse_url ); ?>" class="bzcc-back-link" title="Quay lại">
			<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
		</a>
		<div class="bzcc-form-header__icon">
			<?php if ( $template->icon_url ) : ?>
				<img src="<?php echo esc_url( $template->icon_url ); ?>" alt="" class="bzcc-icon-img">
			<?php else : ?>
				<span class="bzcc-icon-emoji bzcc-icon-emoji--lg"><?php echo esc_html( $template->icon_emoji ?: '📝' ); ?></span>
			<?php endif; ?>
		</div>
		<div>
			<h1 class="bzcc-form-header__title"><?php echo esc_html( $template->title ); ?></h1>
			<p class="bzcc-form-header__desc"><?php echo esc_html( $template->description ); ?></p>
		</div>
	</div>
	<?php if ( $template->badge_text ) : ?>
		<span class="bzcc-form-header__badge" style="<?php echo $template->badge_color ? 'background:' . esc_attr( $template->badge_color ) . ';color:#fff;' : ''; ?>">
			<?php echo esc_html( $template->badge_text ); ?>
		</span>
	<?php endif; ?>
</div>

<?php if ( $has_wizard ) : ?>
<!-- ── Step Indicator ── -->
<div class="bzcc-steps">
	<div class="bzcc-steps__track">
		<?php foreach ( $wizard_steps as $i => $step ) : $step_num = $i + 1; ?>
			<div class="bzcc-step <?php echo $step_num === 1 ? 'bzcc-step--active' : ''; ?>" data-step="<?php echo $step_num; ?>">
				<div class="bzcc-step__circle"><?php echo $step_num; ?></div>
				<div class="bzcc-step__label"><?php echo esc_html( $step['label'] ?? "Bước {$step_num}" ); ?></div>
			</div>
			<div class="bzcc-step__line"></div>
		<?php endforeach; ?>
		<?php if ( $has_confirm ) : $confirm_num = count( $wizard_steps ) + 1; ?>
			<div class="bzcc-step" data-step="<?php echo $confirm_num; ?>">
				<div class="bzcc-step__circle">✓</div>
				<div class="bzcc-step__label">Xác nhận</div>
			</div>
		<?php endif; ?>
	</div>
	<div class="bzcc-steps__counter">
		Bước <span id="bzcc-current-step">1</span> / <?php echo $step_count; ?>
	</div>
</div>
<?php endif; ?>

<!-- ── Form ── -->
<form id="bzcc-form" class="bzcc-form" data-template-id="<?php echo (int) $template->id; ?>">
	<?php wp_nonce_field( 'bzcc_frontend', 'bzcc_nonce' ); ?>
	<input type="hidden" name="template_id" value="<?php echo (int) $template->id; ?>">

	<?php if ( $has_wizard ) : ?>
		<!-- Wizard mode: group fields by step -->
		<?php foreach ( $wizard_steps as $i => $step ) :
			$step_num    = $i + 1;
			$step_fields = $step['fields'] ?? [];
		?>
		<div class="bzcc-form-step <?php echo $step_num === 1 ? 'bzcc-form-step--active' : ''; ?>" data-step="<?php echo $step_num; ?>">
			<div class="bzcc-form-card">
				<h2 class="bzcc-form-card__title">
					<span class="bzcc-form-card__num"><?php echo $step_num; ?></span>
					<?php echo esc_html( $step['label'] ?? "Bước {$step_num}" ); ?>
				</h2>
				<?php if ( ! empty( $step['description'] ) ) : ?>
					<p class="bzcc-form-card__desc"><?php echo esc_html( $step['description'] ); ?></p>
				<?php endif; ?>

				<div class="bzcc-fields">
					<?php
					foreach ( $form_fields as $field ) {
						if ( in_array( $field['slug'] ?? '', $step_fields, true ) ) {
							BZCC_Frontend::render_field( $field );
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php endforeach; ?>

		<?php if ( $has_confirm ) : $confirm_step_num = count( $wizard_steps ) + 1; ?>
		<!-- Confirm Step (auto-generated for multi-step wizards) -->
		<div class="bzcc-form-step" data-step="<?php echo $confirm_step_num; ?>">
			<div class="bzcc-form-card">
				<div class="bzcc-confirm-header">
					<div class="bzcc-confirm-icon">
						<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
					</div>
					<h2 class="bzcc-form-card__title">Xác nhận thông tin</h2>
					<p class="bzcc-form-card__desc">Kiểm tra lại thông tin trước khi AI tạo nội dung cho bạn</p>
				</div>
				<div id="bzcc-confirm-body" class="bzcc-confirm-body">
					<!-- Populated by JS -->
				</div>
			</div>
		</div>
		<?php endif; ?>

	<?php else : ?>
		<!-- Single-page mode: all fields -->
		<div class="bzcc-form-card">
			<h2 class="bzcc-form-card__title">
				<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/></svg>
				Nhập thông tin
			</h2>
			<p class="bzcc-form-card__desc">Điền thông tin bên dưới để AI tạo nội dung cho bạn</p>

			<div class="bzcc-fields">
				<?php
				foreach ( $form_fields as $field ) {
					BZCC_Frontend::render_field( $field );
				}
				?>
			</div>
		</div>
	<?php endif; ?>

	<!-- ── Action Bar ── -->
	<div class="bzcc-form-actions">
		<?php if ( $has_wizard ) : ?>
			<button type="button" class="bzcc-btn bzcc-btn--outline" id="bzcc-btn-prev" style="display:none;">
				<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
				Quay lại
			</button>
			<button type="button" class="bzcc-btn bzcc-btn--primary" id="bzcc-btn-next">
				Tiếp tục
				<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
			</button>
		<?php endif; ?>

		<a href="<?php echo esc_url( $browse_url ); ?>" class="bzcc-btn bzcc-btn--outline <?php echo $has_wizard ? 'bzcc-hide' : ''; ?>" id="bzcc-btn-back">
			Quay lại
		</a>
		<button type="submit" class="bzcc-btn bzcc-btn--primary <?php echo $has_wizard ? 'bzcc-hide' : ''; ?>" id="bzcc-btn-submit">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
			Tạo nội dung
		</button>
	</div>
</form>

<!-- ── Loading overlay ── -->
<div class="bzcc-loading" id="bzcc-loading" style="display:none;">
	<div class="bzcc-loading__spinner"></div>
	<p class="bzcc-loading__text">AI đang tạo nội dung cho bạn...</p>
</div>
