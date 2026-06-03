<?php
/**
 * Frontend History View — List of user's creator files.
 *
 * AIVA-inspired layout:
 *   - Header with back button + title + "Tạo mới" CTA
 *   - Search + filter (status) + sort + view toggle (grid/list)
 *   - Card grid (campaigns)
 *
 * Variables available:
 *   $files      — array of file objects (with template join)
 *   $total      — total file count for this user
 *   $statuses   — available status values for filter
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$browse_url = home_url( 'creator/' );

$status_labels = [
	'pending'       => [ '⏳', 'Chờ xử lý' ],
	'outline_draft' => [ '📝', 'Nháp dàn ý' ],
	'generating'    => [ '⚡', 'Đang tạo' ],
	'completed'     => [ '✅', 'Hoàn thành' ],
	'failed'        => [ '❌', 'Lỗi' ],
];

$status_colors = [
	'pending'       => '#f59e0b',
	'outline_draft' => '#8b5cf6',
	'generating'    => '#3b82f6',
	'completed'     => '#22c55e',
	'failed'        => '#ef4444',
];

$platform_icons = [
	'facebook'  => '📘',
	'tiktok'    => '🎵',
	'instagram' => '📸',
	'youtube'   => '▶️',
	'zalo'      => '💬',
	'email'     => '📧',
	'image'     => '🖼️',
	'video'     => '🎬',
];
?>

<!-- ── Header ── -->
<div class="bzcc-history-header">
	<div class="bzcc-history-header__left">
		<a href="<?php echo esc_url( $browse_url ); ?>" class="bzcc-btn-icon" title="Quay lại">
			<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
		</a>
		<div>
			<h1 class="bzcc-history-header__title">Lịch sử nội dung</h1>
			<p class="bzcc-history-header__count"><?php echo (int) $total; ?> chiến dịch</p>
		</div>
	</div>
	<a href="<?php echo esc_url( $browse_url ); ?>" class="bzcc-btn-primary">
		<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
		Tạo mới
	</a>
</div>

<!-- ── Toolbar: Search + Filters ── -->
<div class="bzcc-history-toolbar">
	<div class="bzcc-history-search">
		<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="bzcc-history-search__icon"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
		<input type="text" class="bzcc-history-search__input" id="bzcc-history-search" placeholder="Tìm kiếm chiến dịch..." autocomplete="off">
	</div>
	<select class="bzcc-history-select" id="bzcc-history-filter-status">
		<option value="">Tất cả</option>
		<?php foreach ( $status_labels as $key => $info ) : ?>
		<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $info[0] . ' ' . $info[1] ); ?></option>
		<?php endforeach; ?>
	</select>
	<select class="bzcc-history-select" id="bzcc-history-sort">
		<option value="newest">Mới nhất</option>
		<option value="oldest">Cũ nhất</option>
		<option value="title">Theo tên</option>
	</select>
	<div class="bzcc-history-viewtoggle">
		<button type="button" class="bzcc-btn-icon bzcc-viewtoggle-btn bzcc-viewtoggle-btn--active" data-view="grid" title="Grid">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
		</button>
		<button type="button" class="bzcc-btn-icon bzcc-viewtoggle-btn" data-view="list" title="List">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h.01"/><path d="M3 12h.01"/><path d="M3 19h.01"/><path d="M8 5h13"/><path d="M8 12h13"/><path d="M8 19h13"/></svg>
		</button>
	</div>
</div>

<!-- ── Card Grid ── -->
<?php if ( empty( $files ) ) : ?>
<div class="bzcc-history-empty">
	<span style="font-size:48px;">📭</span>
	<h3>Chưa có nội dung nào</h3>
	<p>Bắt đầu tạo nội dung đầu tiên của bạn với Brain Factory — Nhà máy não số!</p>
	<a href="<?php echo esc_url( $browse_url ); ?>" class="bzcc-btn-primary">✨ Tạo nội dung</a>
</div>
<?php else : ?>
<div class="bzcc-history-grid" id="bzcc-history-grid">
	<?php foreach ( $files as $f ) :
		$file_url  = home_url( 'creator/history/' . (int) $f->id . '/' );
		$status    = $f->status ?: 'pending';
		$s_label   = $status_labels[ $status ] ?? [ '❓', $status ];
		$s_color   = $status_colors[ $status ] ?? '#9ca3af';
		$tpl_title = ! empty( $f->template_title ) ? $f->template_title : '';
		$tpl_emoji = ! empty( $f->template_emoji ) ? $f->template_emoji : '📄';
		$cat_name  = ! empty( $f->category_name ) ? $f->category_name : '';
		$title     = $f->title ?: $tpl_title ?: 'File #' . (int) $f->id;
		$created   = $f->created_at ? date_i18n( 'd/m/Y H:i', strtotime( $f->created_at ) ) : '';
		$updated   = $f->updated_at ? date_i18n( 'd/m/Y H:i', strtotime( $f->updated_at ) ) : '';

		// Platforms from chunks
		$chunk_platforms = [];
		if ( ! empty( $f->platforms_csv ) ) {
			foreach ( explode( ',', $f->platforms_csv ) as $p ) {
				$p = trim( $p );
				if ( $p && isset( $platform_icons[ $p ] ) ) {
					$chunk_platforms[ $p ] = $platform_icons[ $p ];
				}
			}
		}

		// Progress
		$progress = 0;
		if ( (int) $f->chunk_count > 0 ) {
			$progress = round( ( (int) $f->chunk_done / (int) $f->chunk_count ) * 100 );
		}
		if ( $status === 'completed' ) $progress = 100;

		// Gradient for icon bg
		$gradient_map = [
			'pending'       => 'from-amber-500 to-yellow-500',
			'outline_draft' => 'from-violet-500 to-purple-500',
			'generating'    => 'from-blue-500 to-cyan-500',
			'completed'     => 'from-green-500 to-emerald-500',
			'failed'        => 'from-red-500 to-rose-500',
		];
		$gradient = $gradient_map[ $status ] ?? 'from-gray-400 to-gray-500';
	?>
	<div class="bzcc-history-card"
	     data-file-id="<?php echo (int) $f->id; ?>"
	     data-status="<?php echo esc_attr( $status ); ?>"
	     data-title="<?php echo esc_attr( strtolower( $title ) ); ?>"
	     data-created="<?php echo esc_attr( $f->created_at ); ?>"
	     data-updated="<?php echo esc_attr( $f->updated_at ); ?>">
		<a href="<?php echo esc_url( $file_url ); ?>" class="bzcc-history-card__link">
			<!-- Card Header -->
			<div class="bzcc-history-card__top">
				<div class="bzcc-history-card__icon-wrap bzcc-gradient-<?php echo esc_attr( $status ); ?>">
					<span class="bzcc-history-card__emoji"><?php echo esc_html( $tpl_emoji ); ?></span>
				</div>
				<div class="bzcc-history-card__info">
					<h3 class="bzcc-history-card__title"><?php echo esc_html( $title ); ?></h3>
					<?php if ( $cat_name ) : ?>
						<p class="bzcc-history-card__cat"><?php echo esc_html( $cat_name ); ?></p>
					<?php elseif ( $tpl_title && $title !== $tpl_title ) : ?>
						<p class="bzcc-history-card__cat"><?php echo esc_html( $tpl_title ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Status Badge -->
			<div class="bzcc-history-card__badges">
				<span class="bzcc-history-badge" style="background:<?php echo esc_attr( $s_color ); ?>20;color:<?php echo esc_attr( $s_color ); ?>;border-color:<?php echo esc_attr( $s_color ); ?>40">
					<?php echo esc_html( $s_label[0] . ' ' . $s_label[1] ); ?>
				</span>
			</div>

			<!-- Platforms -->
			<?php if ( ! empty( $chunk_platforms ) ) : ?>
			<div class="bzcc-history-card__platforms">
				<?php foreach ( $chunk_platforms as $pk => $emoji ) : ?>
				<span class="bzcc-history-platform-tag"><?php echo esc_html( $emoji ); ?></span>
				<?php endforeach; ?>
				<?php if ( (int) $f->chunk_count > count( $chunk_platforms ) ) : ?>
				<span class="bzcc-history-platform-tag">+<?php echo (int) $f->chunk_count - count( $chunk_platforms ); ?></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- Progress bar for generating -->
			<?php if ( $status === 'generating' && $progress < 100 ) : ?>
			<div class="bzcc-history-card__progress">
				<div class="bzcc-history-card__progress-bar" style="width:<?php echo (int) $progress; ?>%"></div>
			</div>
			<?php endif; ?>

			<!-- Footer -->
			<p class="bzcc-history-card__date">
				<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
				<?php echo esc_html( $created ); ?>
			</p>
		</a>
		<button type="button" class="bzcc-history-card__delete" data-file-id="<?php echo (int) $f->id; ?>" title="Xóa">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
		</button>
	</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>
