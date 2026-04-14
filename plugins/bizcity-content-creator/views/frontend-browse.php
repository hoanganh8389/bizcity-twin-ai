<?php
/**
 * Frontend Browse View — Category grid + Featured templates + Template cards.
 *
 * Variables available:
 *   $categories      — array of category objects
 *   $featured        — array of featured template objects
 *   $active_category — slug of active category filter (or '')
 *
 * AIVA-inspired layout:
 *   1. Search bar
 *   2. Featured templates (hero cards with gradient badges)
 *   3. Category grid (2×4, hover lift, active highlight)
 *   4. Template cards (filtered by active category)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<!-- ── Header ── -->
<div class="bzcc-header">
	<div class="bzcc-header__left">
		<span class="bzcc-header__icon">✨</span>
		<div>
			<h1 class="bzcc-header__title">Content Creator</h1>
			<p class="bzcc-header__desc">Chọn công cụ và tạo nội dung chuyên nghiệp với AI</p>
		</div>
	</div>
	<div class="bzcc-header__search">
		<svg class="bzcc-search-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
		<input type="text" class="bzcc-search-input" id="bzcc-search" placeholder="Tìm kiếm công cụ..." autocomplete="off">
	</div>
</div>

<?php if ( ! empty( $featured ) ) : ?>
<!-- ── Featured Templates ── -->
<section class="bzcc-section">
	<h2 class="bzcc-section__title">
		<span class="bzcc-section__emoji">⭐</span>
		Công cụ nổi bật
	</h2>
	<div class="bzcc-featured-grid">
		<?php foreach ( $featured as $tpl ) :
			$form_url = BZCC_Frontend::get_template_url( (int) $tpl->id );

			$badge_style = '';
			if ( $tpl->badge_color ) {
				$badge_style = 'background:' . esc_attr( $tpl->badge_color ) . ';color:#fff;';
			}
		?>
		<a href="<?php echo esc_url( $form_url ); ?>" class="bzcc-featured-card" data-template-id="<?php echo (int) $tpl->id; ?>">
			<?php if ( $tpl->badge_text ) : ?>
				<span class="bzcc-featured-card__badge" style="<?php echo $badge_style; ?>"><?php echo esc_html( $tpl->badge_text ); ?></span>
			<?php endif; ?>
			<div class="bzcc-featured-card__icon">
				<?php if ( $tpl->icon_url ) : ?>
					<img src="<?php echo esc_url( $tpl->icon_url ); ?>" alt="" class="bzcc-icon-img">
				<?php else : ?>
					<span class="bzcc-icon-emoji"><?php echo esc_html( $tpl->icon_emoji ?: '📝' ); ?></span>
				<?php endif; ?>
			</div>
			<h3 class="bzcc-featured-card__title"><?php echo esc_html( $tpl->title ); ?></h3>
			<p class="bzcc-featured-card__desc"><?php echo esc_html( $tpl->description ); ?></p>
			<div class="bzcc-featured-card__meta">
				<span class="bzcc-use-count">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
					<?php echo number_format_i18n( $tpl->use_count ); ?> lượt dùng
				</span>
			</div>
		</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<!-- ── Category Grid ── -->
<section class="bzcc-section">
	<h2 class="bzcc-section__title">
		<span class="bzcc-section__emoji">📂</span>
		Danh mục
	</h2>
	<div class="bzcc-category-grid">
		<button type="button"
			class="bzcc-category-card <?php echo '' === $active_category ? 'bzcc-category-card--active' : ''; ?>"
			data-category=""
		>
			<span class="bzcc-category-card__icon">🔥</span>
			<span class="bzcc-category-card__name">Tất cả</span>
			<span class="bzcc-category-card__count">
				<?php
				$total = 0;
				foreach ( $categories as $c ) { $total += (int) $c->tool_count; }
				echo $total . ' công cụ';
				?>
			</span>
		</button>
		<?php foreach ( $categories as $cat ) : ?>
		<button type="button"
			class="bzcc-category-card <?php echo $active_category === $cat->slug ? 'bzcc-category-card--active' : ''; ?>"
			data-category="<?php echo esc_attr( $cat->slug ); ?>"
			data-category-id="<?php echo (int) $cat->id; ?>"
		>
			<span class="bzcc-category-card__icon">
				<?php if ( $cat->icon_url ) : ?>
					<img src="<?php echo esc_url( $cat->icon_url ); ?>" alt="" class="bzcc-cat-icon-img">
				<?php else : ?>
					<?php echo esc_html( $cat->icon_emoji ?: '📁' ); ?>
				<?php endif; ?>
			</span>
			<span class="bzcc-category-card__name"><?php echo esc_html( $cat->title ); ?></span>
			<span class="bzcc-category-card__count"><?php echo (int) $cat->tool_count; ?> công cụ</span>
		</button>
		<?php endforeach; ?>
	</div>
</section>

<!-- ── Template Cards ── -->
<section class="bzcc-section" id="bzcc-templates-section">
	<h2 class="bzcc-section__title">
		<span class="bzcc-section__emoji">🛠️</span>
		<span id="bzcc-templates-title">Tất cả công cụ</span>
	</h2>
	<div class="bzcc-template-grid" id="bzcc-template-grid">
		<?php
		// Load all templates initially; JS will filter
		$all_templates = BZCC_Template_Manager::get_all_active();
		foreach ( $all_templates as $tpl ) :
			$cat = null;
			foreach ( $categories as $c ) {
				if ( (int) $c->id === (int) $tpl->category_id ) {
					$cat = $c;
					break;
				}
			}
		$form_url = BZCC_Frontend::get_template_url( (int) $tpl->id );
		?>
		<a href="<?php echo esc_url( $form_url ); ?>"
		   class="bzcc-tpl-card"
		   data-template-id="<?php echo (int) $tpl->id; ?>"
		   data-category-id="<?php echo (int) $tpl->category_id; ?>"
		   data-category-slug="<?php echo esc_attr( $cat ? $cat->slug : '' ); ?>"
		   data-title="<?php echo esc_attr( strtolower( $tpl->title ) ); ?>"
		   data-tags="<?php echo esc_attr( strtolower( $tpl->tags ?? '' ) ); ?>"
		>
			<div class="bzcc-tpl-card__header">
				<div class="bzcc-tpl-card__icon">
					<?php if ( $tpl->icon_url ) : ?>
						<img src="<?php echo esc_url( $tpl->icon_url ); ?>" alt="" class="bzcc-icon-img">
					<?php else : ?>
						<span class="bzcc-icon-emoji"><?php echo esc_html( $tpl->icon_emoji ?: '📝' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $tpl->badge_text ) : ?>
					<span class="bzcc-tpl-card__badge" style="<?php echo $tpl->badge_color ? 'background:' . esc_attr( $tpl->badge_color ) . ';color:#fff;' : ''; ?>"><?php echo esc_html( $tpl->badge_text ); ?></span>
				<?php endif; ?>
			</div>
			<h3 class="bzcc-tpl-card__title"><?php echo esc_html( $tpl->title ); ?></h3>
			<p class="bzcc-tpl-card__desc"><?php echo esc_html( $tpl->description ); ?></p>
			<div class="bzcc-tpl-card__footer">
				<?php if ( $cat ) : ?>
					<span class="bzcc-tpl-card__cat"><?php echo esc_html( $cat->icon_emoji . ' ' . $cat->title ); ?></span>
				<?php endif; ?>
				<span class="bzcc-tpl-card__uses"><?php echo number_format_i18n( $tpl->use_count ); ?> lượt</span>
			</div>
		</a>
		<?php endforeach; ?>
	</div>

	<!-- Empty state -->
	<div class="bzcc-empty" id="bzcc-empty-state" style="display:none;">
		<span class="bzcc-empty__icon">🔍</span>
		<p>Không tìm thấy công cụ nào phù hợp.</p>
	</div>
</section>
