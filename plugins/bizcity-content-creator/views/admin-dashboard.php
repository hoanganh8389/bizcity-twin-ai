<?php
/**
 * Admin Dashboard — shows overview: template count, file count, recent files.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$categories = BZCC_Category_Manager::get_all_active();
$templates  = BZCC_Template_Manager::get_all_active();
$cat_count  = count( $categories );
$tpl_count  = count( $templates );
?>
<div class="wrap bzcc-wrap">
	<h1>✨ Content Creator</h1>
	<p class="description">Tạo nội dung sáng tạo từ template — AI outline + chunk generation.</p>

	<!-- Stats -->
	<div class="bzcc-stats">
		<div class="bzcc-stat-card">
			<span class="bzcc-stat-number"><?php echo (int) $cat_count; ?></span>
			<span class="bzcc-stat-label">Danh mục</span>
		</div>
		<div class="bzcc-stat-card">
			<span class="bzcc-stat-number"><?php echo (int) $tpl_count; ?></span>
			<span class="bzcc-stat-label">Templates</span>
		</div>
	</div>

	<!-- Categories -->
	<h2>📂 Danh mục</h2>
	<?php if ( empty( $categories ) ) : ?>
		<p>Chưa có danh mục nào. <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-creator-categories' ) ); ?>">Tạo danh mục</a></p>
	<?php else : ?>
		<div class="bzcc-category-grid">
			<?php foreach ( $categories as $cat ) : ?>
				<div class="bzcc-category-card">
					<div class="bzcc-category-icon"><?php echo esc_html( $cat->icon_emoji ?: '📁' ); ?></div>
					<div class="bzcc-category-info">
						<strong><?php echo esc_html( $cat->title ); ?></strong>
						<span class="description"><?php echo esc_html( $cat->description ); ?></span>
						<span class="bzcc-badge"><?php echo (int) $cat->tool_count; ?> templates</span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<!-- Featured Templates -->
	<?php $featured = BZCC_Template_Manager::get_featured( 6 ); ?>
	<?php if ( ! empty( $featured ) ) : ?>
		<h2>⭐ Templates nổi bật</h2>
		<div class="bzcc-template-grid">
			<?php foreach ( $featured as $tpl ) : ?>
				<div class="bzcc-template-card">
					<div class="bzcc-template-header">
						<span class="bzcc-template-icon"><?php echo esc_html( $tpl->icon_emoji ?: '📝' ); ?></span>
						<?php if ( $tpl->badge_text ) : ?>
							<span class="bzcc-template-badge" style="background: <?php echo esc_attr( $tpl->badge_color ?: '#6366f1' ); ?>">
								<?php echo esc_html( $tpl->badge_text ); ?>
							</span>
						<?php endif; ?>
					</div>
					<h3><?php echo esc_html( $tpl->title ); ?></h3>
					<p><?php echo esc_html( wp_trim_words( $tpl->description, 20 ) ); ?></p>
					<div class="bzcc-template-meta">
						<span>📊 <?php echo (int) $tpl->use_count; ?> lần sử dụng</span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
