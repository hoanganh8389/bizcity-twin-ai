<?php
/**
 * Frontend Result View — AI-generated content display.
 *
 * AIVA-inspired layout:
 *   - Header with success icon + title
 *   - Vertical stepper (collapsible sections, progress bars)
 *   - Platform tabs with content cards
 *   - Action buttons: Copy, Edit, Schedule, Generate Image, Save
 *
 * Variables available:
 *   $file      — file object
 *   $template  — template object
 *   $chunks    — array of chunk objects (with content)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$browse_url  = home_url( 'creator/' );

/**
 * Lightweight markdown → HTML converter for saved content.
 * Mirrors frontend simpleMarkdown() so reload and live look the same.
 */
function bzcc_markdown_to_html( string $md ): string {
	// Strip prompt preamble that some LLMs echo back
	$prompt_headers = [
		'System Prompt:', 'Chunk Prompt:', 'Outline Prompt:',
		'YÊU CẦU:', 'QUY TẮC:', 'QUY TẮC BẮT BUỘC:', 'QUY TẮC VIẾT BẮT BUỘC:',
		'BỐI CẢNH:', 'FORMAT:', 'NHẮC LẠI:',
	];
	$has_preamble = false;
	foreach ( $prompt_headers as $h ) {
		if ( mb_stripos( $md, $h ) !== false ) {
			$has_preamble = true;
			break;
		}
	}
	if ( $has_preamble && preg_match( '/\n(\d+\.\s+\S|#{1,4}\s|[-*•]\s|\*\*[^*])/', $md, $m, PREG_OFFSET_CAPTURE ) ) {
		$content_start = $m[0][1];
		$prefix = substr( $md, 0, $content_start );
		$prefix_has_header = false;
		foreach ( $prompt_headers as $h ) {
			if ( mb_stripos( $prefix, $h ) !== false ) {
				$prefix_has_header = true;
				break;
			}
		}
		if ( $prefix_has_header ) {
			$md = ltrim( substr( $md, $content_start ) );
		}
	}

	// ── Extract fenced code blocks (mermaid, etc.) before escaping ──
	$code_blocks = [];
	$md = preg_replace_callback( '/```(\w*)\n([\s\S]*?)```/', function ( $m ) use ( &$code_blocks ) {
		$lang = strtolower( trim( $m[1] ) );
		$code = $m[2];
		$idx  = count( $code_blocks );
		if ( $lang === 'mermaid' ) {
			$code_blocks[] = '<div class="bzcc-mermaid" data-mermaid="' . esc_attr( trim( $code ) ) . '"><pre class="mermaid">' . esc_html( trim( $code ) ) . '</pre></div>';
		} else {
			$code_blocks[] = '<pre class="bzcc-code-block"><code' . ( $lang ? ' class="language-' . esc_attr( $lang ) . '"' : '' ) . '>' . esc_html( $code ) . '</code></pre>';
		}
		return "\x00CODEBLOCK_{$idx}\x00";
	}, $md );

	$md = esc_html( $md );

	// ── Restore code block placeholders ──
	$md = preg_replace_callback( '/\x00CODEBLOCK_(\d+)\x00/', function ( $m ) use ( &$code_blocks ) {
		return $code_blocks[ (int) $m[1] ] ?? '';
	}, $md );

	// ── Parse markdown tables ──
	$md = preg_replace_callback( '/(^\|.+\|\s*$\n?){2,}/m', function ( $m ) {
		$rows = array_filter( array_map( 'trim', explode( "\n", trim( $m[0] ) ) ) );
		if ( count( $rows ) < 2 ) return $m[0];

		$html     = '<div class="bzcc-table-wrap"><table>';
		$is_first = true;
		foreach ( $rows as $row ) {
			// Skip separator rows (|---|---|)
			if ( preg_match( '/^\|[\s:?\-|]+\|$/', $row ) ) continue;
			$cells = array_map( 'trim', explode( '|', trim( $row, '| ' ) ) );
			$tag   = $is_first ? 'th' : 'td';
			$html .= $is_first ? '<thead><tr>' : '<tr>';
			foreach ( $cells as $cell ) {
				$html .= "<{$tag}>{$cell}</{$tag}>";
			}
			$html .= $is_first ? '</tr></thead><tbody>' : '</tr>';
			$is_first = false;
		}
		$html .= '</tbody></table></div>';
		return $html;
	}, $md );

	// Headers
	$md = preg_replace( '/^##### (.+)$/m', '<h5>$1</h5>', $md );
	$md = preg_replace( '/^#### (.+)$/m',  '<h4>$1</h4>', $md );
	$md = preg_replace( '/^### (.+)$/m',   '<h3>$1</h3>', $md );
	$md = preg_replace( '/^## (.+)$/m',    '<h2>$1</h2>', $md );
	$md = preg_replace( '/^# (.+)$/m',     '<h1>$1</h1>', $md );
	// Horizontal rules
	$md = preg_replace( '/^[-*_]{3,}\s*$/m', '<hr>', $md );
	// Images: ![alt](url)
	$md = preg_replace( '/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" class="bzcc-chunk-image" loading="lazy" />', $md );
	// Bold / italic
	$md = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md );
	$md = preg_replace( '/\*(.+?)\*/',     '<em>$1</em>', $md );
	// Checkboxes
	$md = preg_replace( '/☐/', '<span class="bzcc-checkbox">☐</span>', $md );
	$md = preg_replace( '/☑/', '<span class="bzcc-checkbox bzcc-checkbox--checked">☑</span>', $md );
	// Ordered lists
	$md = preg_replace( '/^\d+[\.)\s]\s*(.+)$/m', '<li class="bzcc-ol-item">$1</li>', $md );
	// Unordered lists
	$md = preg_replace( '/^[\-\*•] (.+)$/m', '<li>$1</li>', $md );
	$md = preg_replace( '/(<li class="bzcc-ol-item">.*?<\/li>\s*)+/s', '<ol>$0</ol>', $md );
	$md = preg_replace( '/(<li>.*?<\/li>\s*)+/s', '<ul>$0</ul>', $md );
	// Clean nested list wrapping
	$md = str_replace( '<ul><ol>', '<ol>', $md );
	$md = str_replace( '</ol></ul>', '</ol>', $md );
	// Line breaks into paragraphs
	$md = preg_replace( '/\n{2,}/', '</p><p>', $md );
	$md = '<p>' . $md . '</p>';
	// Clean up empty tags
	$md = str_replace( '<p></p>', '', $md );
	$md = preg_replace( '/<p>\s*<(h[1-5]|ul|ol|hr|div|table|pre)/', '<$1', $md );
	$md = preg_replace( '/<\/(h[1-5]|ul|ol|div|table|pre)>\s*<\/p>/', '</$1>', $md );
	$md = preg_replace( '/<p>\s*<hr>\s*<\/p>/', '<hr>', $md );
	return $md;
}

$form_data   = json_decode( $file->form_data, true ) ?: [];
$outline     = json_decode( $file->outline, true ) ?: [];
$is_pending  = in_array( $file->status, [ 'pending', 'generating' ], true );
$is_complete = $file->status === 'completed';

// Extract image URLs from form_data for gen-video picker
$form_images = [];
foreach ( $form_data as $key => $val ) {
	if ( is_string( $val ) && substr( $key, -4 ) === '_url' && preg_match( '/\.(jpe?g|png|webp|gif)/i', $val ) ) {
		$label = ucwords( str_replace( [ '_url', '_' ], [ '', ' ' ], $key ) );
		$form_images[] = [ 'url' => $val, 'label' => $label ];
	}
}

// Group chunks by platform
$platforms      = [];
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
$platform_labels = [
	'facebook'  => 'Facebook',
	'tiktok'    => 'TikTok',
	'instagram' => 'Instagram',
	'youtube'   => 'YouTube Short',
	'zalo'      => 'Zalo/SMS',
	'email'     => 'Email',
	'image'     => 'Ảnh QC',
	'video'     => 'Video',
];

// Stage colors (gradient pairs)
$stage_colors = [
	'awareness'  => 'from-blue-500 to-cyan-500',
	'interest'   => 'from-purple-500 to-violet-500',
	'trust'      => 'from-amber-500 to-orange-500',
	'action'     => 'from-green-500 to-emerald-500',
	'loyalty'    => 'from-rose-500 to-pink-500',
];
$stage_labels = [
	'awareness' => [ '👁️', 'Nhận biết' ],
	'interest'  => [ '💡', 'Quan tâm' ],
	'trust'     => [ '🤝', 'Tin tưởng' ],
	'action'    => [ '🎯', 'Hành động' ],
	'loyalty'   => [ '❤️', 'Trung thành' ],
];

if ( ! empty( $chunks ) ) {
	foreach ( $chunks as $chunk ) {
		$p = $chunk->platform ?: 'general';
		if ( ! isset( $platforms[ $p ] ) ) {
			$platforms[ $p ] = [];
		}
		$platforms[ $p ][] = $chunk;
	}
}
?>
<style>

.bzcc-stepper-node__content img{max-width:100%}
</style>
<div class="bzcc-result" id="bzcc-result"
     data-file-id="<?php echo (int) $file->id; ?>"
     data-file-status="<?php echo esc_attr( $file->status ); ?>"
     data-chunk-count="<?php echo (int) $file->chunk_count; ?>"
     <?php if ( ! empty( $form_images ) ) : ?>data-form-images="<?php echo esc_attr( wp_json_encode( $form_images ) ); ?>"<?php endif; ?>>

	<!-- ── Header ── -->
	<div class="bzcc-result-header">
		<div class="bzcc-result-header__icon <?php echo $is_pending ? 'bzcc-result-header__icon--loading' : ''; ?>">
			<?php if ( $is_complete ) : ?>
				<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
			<?php elseif ( $is_pending ) : ?>
				<div class="bzcc-pulse-ring"></div>
				<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48 2.83-2.83"/></svg>
			<?php else : ?>
				<span style="font-size:32px;">🎉</span>
			<?php endif; ?>
		</div>
		<h2 class="bzcc-result-header__title">
			<?php if ( $is_pending ) : ?>
				AI đang tạo nội dung...
			<?php else : ?>
				Nội dung đã sẵn sàng!
			<?php endif; ?>
		</h2>
		<p class="bzcc-result-header__sub">
			<?php if ( $is_pending ) : ?>
				Vui lòng chờ trong giây lát
			<?php else : ?>
				Copy và sử dụng ngay
			<?php endif; ?>
		</p>
	</div>

	<!-- ── Vertical Stepper (outline progress) ── -->
	<div class="bzcc-stepper" id="bzcc-stepper">
		<?php if ( ! empty( $outline ) ) : ?>
			<?php foreach ( $outline as $i => $section ) :
				$chunk   = $chunks[ $i ] ?? null;
				$status  = $chunk ? $chunk->node_status : 'pending';
				$is_done = $status === 'completed';
				$is_gen  = $status === 'generating';
				$is_stuck = $is_gen && $is_complete; // chunk still "generating" but file marked complete = stuck
			?>
			<div class="bzcc-stepper-node <?php echo $is_done ? 'bzcc-stepper-node--done' : ( $is_stuck ? 'bzcc-stepper-node--error' : ( $is_gen ? 'bzcc-stepper-node--active' : '' ) ); ?>"
			     data-chunk-index="<?php echo $i; ?>"
			     data-chunk-id="<?php echo $chunk ? (int) $chunk->id : 0; ?>">
				<div class="bzcc-stepper-node__header" data-toggle="collapse">
					<div class="bzcc-stepper-node__icon">
						<?php if ( $is_done ) : ?>
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
						<?php elseif ( $is_stuck ) : ?>
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
						<?php elseif ( $is_gen ) : ?>
							<div class="bzcc-spinner-sm"></div>
						<?php else : ?>
							<span class="bzcc-stepper-node__num"><?php echo $i + 1; ?></span>
						<?php endif; ?>
					</div>
					<span class="bzcc-stepper-node__label">
						<?php echo esc_html( $section['emoji'] ?? '📝' ); ?>
						<?php echo esc_html( $section['label'] ?? "Phần " . ( $i + 1 ) ); ?>
					</span>
					<?php if ( ! empty( $section['platform'] ) ) : ?>
						<span class="bzcc-stepper-node__platform">
							<?php echo esc_html( $platform_icons[ $section['platform'] ] ?? '' ); ?>
							<?php echo esc_html( $platform_labels[ $section['platform'] ] ?? $section['platform'] ); ?>
						</span>
					<?php endif; ?>
					<svg class="bzcc-stepper-node__arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
				</div>
				<div class="bzcc-stepper-node__progress">
					<div class="bzcc-stepper-node__bar" style="width:<?php echo $is_done ? '100' : ( $is_stuck ? '100' : ( $is_gen ? '50' : '0' ) ); ?>%;<?php echo $is_stuck ? 'background:#f59e0b;' : ''; ?>"></div>
				</div>
				<div class="bzcc-stepper-node__body <?php echo $is_done || $is_gen || $is_stuck ? '' : 'bzcc-collapsed'; ?>">
					<div class="bzcc-stepper-node__content" id="bzcc-chunk-<?php echo $i; ?>">
						<?php if ( $chunk && ! empty( $chunk->image_url ) ) : ?>
							<div class="bzcc-chunk-image"><img src="<?php echo esc_url( $chunk->image_url ); ?>" alt="AI Generated" loading="lazy"></div>
						<?php endif; ?>
						<?php if ( $chunk && ! empty( $chunk->content ) ) : ?>
							<?php echo wp_kses_post( bzcc_markdown_to_html( $chunk->content ) ); ?>
						<?php elseif ( $is_stuck ) : ?>
							<div class="bzcc-chunk-error bzcc-chunk-error--timeout">⏱️ Phần này bị gián đoạn. Bấm "Thử lại" để tạo lại nội dung.</div>
						<?php elseif ( $is_gen ) : ?>
							<span class="bzcc-typing-indicator"><span></span><span></span><span></span></span>
						<?php endif; ?>
					</div>
					<?php if ( $is_stuck ) : ?>
					<div class="bzcc-stepper-node__actions">
						<button class="bzcc-action-btn bzcc-action-btn--retry" data-action="retry" title="Thử lại">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
							Thử lại
						</button>
					</div>
					<?php elseif ( $is_done ) : ?>
					<div class="bzcc-stepper-node__actions">
						<button class="bzcc-action-btn" data-action="copy" title="Sao chép">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
							Sao chép
						</button>
						<button class="bzcc-action-btn" data-action="edit" title="Chỉnh sửa">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>
							Chỉnh sửa
						</button>
						<button class="bzcc-action-btn bzcc-action-btn--magic" data-action="regenerate" title="Tạo lại">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/></svg>
							Đũa thần
						</button>
						<button class="bzcc-action-btn bzcc-action-btn--generate" data-action="gen-image" title="Tạo ảnh">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
							Tạo ảnh
						</button>
						<button class="bzcc-action-btn bzcc-action-btn--generate" data-action="gen-video" title="Tạo video">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect width="14" height="12" x="2" y="6" rx="2"/></svg>
							Tạo video
						</button>
						<button class="bzcc-action-btn bzcc-action-btn--outline" data-action="gen-mindmap" title="Mindmap">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 3v6"/><path d="M12 15v6"/><path d="m3 12 6 0"/><path d="m15 12 6 0"/><circle cx="12" cy="3" r="2"/><circle cx="12" cy="21" r="2"/><circle cx="3" cy="12" r="2"/><circle cx="21" cy="12" r="2"/></svg>
							Mindmap
						</button>
						<button class="bzcc-action-btn" data-action="schedule" title="Lên Lịch">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
							Lên Lịch
						</button>
						<button class="bzcc-action-btn bzcc-action-btn--outline" data-action="save" title="Lưu Kho">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
							Lưu Kho
						</button>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endforeach; ?>
		<?php else : ?>
			<!-- Placeholder for SSE-populated stepper -->
			<div class="bzcc-stepper-placeholder" id="bzcc-stepper-placeholder">
				<div class="bzcc-stepper-node bzcc-stepper-node--active" data-chunk-index="0">
					<div class="bzcc-stepper-node__header">
						<div class="bzcc-stepper-node__icon"><div class="bzcc-spinner-sm"></div></div>
						<span class="bzcc-stepper-node__label">Đang phân tích yêu cầu...</span>
					</div>
					<div class="bzcc-stepper-node__progress">
						<div class="bzcc-stepper-node__bar bzcc-bar-animate" style="width:30%;"></div>
					</div>
					<div class="bzcc-stepper-node__body">
						<div class="bzcc-stepper-node__content">
							<span class="bzcc-typing-indicator"><span></span><span></span><span></span></span>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $platforms ) ) : ?>
	<!-- ══════════════════════════════════════
	     Platform Tabs + Content Cards
	     (shown when content is generated)
	     ══════════════════════════════════════ -->
	<div class="bzcc-platforms" id="bzcc-platforms" <?php echo $is_pending ? 'style="display:none;"' : ''; ?>>
		<!-- Stage Filter -->
		<div class="bzcc-stage-filter">
			<span class="bzcc-stage-filter__label">
				<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 20a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341L21.74 4.67A1 1 0 0 0 21 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14z"/></svg>
				Giai đoạn:
			</span>
			<div class="bzcc-stage-pills">
				<button class="bzcc-pill bzcc-pill--active" data-stage="all">Tất cả</button>
				<?php foreach ( $stage_labels as $key => [ $emoji, $label ] ) : ?>
					<button class="bzcc-pill" data-stage="<?php echo esc_attr( $key ); ?>"><?php echo $emoji; ?> <?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Platform Tabs -->
		<div class="bzcc-tab-bar" role="tablist">
			<?php $first = true; foreach ( $platforms as $platform => $pcks ) : ?>
			<button class="bzcc-tab <?php echo $first ? 'bzcc-tab--active' : ''; ?>"
			        role="tab"
			        data-platform="<?php echo esc_attr( $platform ); ?>"
			        aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
				<span class="bzcc-tab__icon"><?php echo esc_html( $platform_icons[ $platform ] ?? '📄' ); ?></span>
				<span class="bzcc-tab__label"><?php echo esc_html( $platform_labels[ $platform ] ?? ucfirst( $platform ) ); ?></span>
				<span class="bzcc-tab__count"><?php echo count( $pcks ); ?></span>
			</button>
			<?php $first = false; endforeach; ?>
		</div>

		<!-- Tab Panels -->
		<?php $first = true; foreach ( $platforms as $platform => $pcks ) : ?>
		<div class="bzcc-tab-panel <?php echo $first ? 'bzcc-tab-panel--active' : ''; ?>"
		     data-platform="<?php echo esc_attr( $platform ); ?>"
		     role="tabpanel">

			<h4 class="bzcc-panel-title">
				<?php echo esc_html( $platform_icons[ $platform ] ?? '📄' ); ?>
				<?php echo esc_html( $platform_labels[ $platform ] ?? ucfirst( $platform ) ); ?>
				(<?php echo count( $pcks ); ?>)
			</h4>

			<?php foreach ( $pcks as $pi => $pchunk ) : ?>
			<div class="bzcc-post-card bzcc-post-card--<?php echo esc_attr( $platform ); ?>"
			     data-chunk-id="<?php echo (int) $pchunk->id; ?>"
			     data-stage="<?php echo esc_attr( $pchunk->stage_label ?: '' ); ?>">
				<div class="bzcc-post-card__header">
					<div class="bzcc-post-card__meta">
						<span class="bzcc-badge bzcc-badge--outline"><?php echo esc_html( $pchunk->format ?? 'text' ); ?></span>
						<span class="bzcc-post-card__num">Post <?php echo $pi + 1; ?></span>
						<?php if ( $pchunk->stage_label ) :
							$stage_key = '';
							foreach ( $stage_labels as $sk => $sv ) {
								if ( $sv[1] === $pchunk->stage_label ) { $stage_key = $sk; break; }
							}
						?>
						<span class="bzcc-stage-badge bzcc-stage-badge--<?php echo esc_attr( $stage_key ); ?>">
							<?php echo esc_html( $pchunk->stage_emoji ?: '' ); ?>
							<?php echo esc_html( $pchunk->stage_label ); ?>
						</span>
						<?php endif; ?>
					</div>
				</div>

				<div class="bzcc-post-card__content">
					<?php echo wp_kses_post( bzcc_markdown_to_html( $pchunk->content ?? '' ) ); ?>
				</div>

				<?php if ( $pchunk->hashtags ) : ?>
				<div class="bzcc-post-card__hashtags">
					<?php foreach ( explode( ',', $pchunk->hashtags ) as $tag ) : ?>
						<span class="bzcc-hashtag"><?php echo esc_html( trim( $tag ) ); ?></span>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<?php if ( $pchunk->cta_text ) : ?>
				<span class="bzcc-post-card__cta"><?php echo esc_html( $pchunk->cta_text ); ?></span>
				<?php endif; ?>

				<!-- Action Buttons -->
				<div class="bzcc-post-card__actions">
					<button class="bzcc-action-btn" data-action="copy">
						<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
						Copy
					</button>
					<button class="bzcc-action-btn" data-action="edit">
						<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>
						Chỉnh sửa
					</button>
					<button class="bzcc-action-btn" data-action="schedule">
						<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
						Lên Lịch
					</button>
					<button class="bzcc-action-btn bzcc-action-btn--generate" data-action="gen-image">
						<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
						Tạo ảnh
					</button>
					<button class="bzcc-action-btn bzcc-action-btn--outline" data-action="save">
						<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
						Lưu Kho
					</button>
				</div>

				<?php if ( $pchunk->image_url ) : ?>
				<div class="bzcc-post-card__image">
					<img src="<?php echo esc_url( $pchunk->image_url ); ?>" alt="Generated" loading="lazy">
					<div class="bzcc-post-card__image-actions">
						<button class="bzcc-action-btn bzcc-action-btn--outline" data-action="download-image">
							<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>
							Tải về
						</button>
					</div>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>

			<!-- Add More Button -->
			<button class="bzcc-add-more-btn" data-platform="<?php echo esc_attr( $platform ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
				Tạo thêm nội dung <?php echo esc_html( $platform_labels[ $platform ] ?? ucfirst( $platform ) ); ?>
			</button>
		</div>
		<?php $first = false; endforeach; ?>
	</div>
	<?php endif; ?>

	<!-- ── Navigation ── -->
	<div class="bzcc-result-nav">
		<a href="<?php echo esc_url( $browse_url ); ?>" class="bzcc-btn bzcc-btn--outline">
			← Quay lại
		</a>
		<?php if ( $is_complete ) : ?>
		<button class="bzcc-btn bzcc-btn--primary" id="bzcc-btn-export-pdf" onclick="if(typeof handleExport==='function')handleExport('pdf');">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>
			Tải PDF
		</button>
		<button class="bzcc-btn bzcc-btn--outline" id="bzcc-btn-export-word" onclick="if(typeof handleExport==='function')handleExport('word');">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
			Tải Word
		</button>
		<button class="bzcc-btn bzcc-btn--outline" id="bzcc-btn-export-pptx" onclick="if(typeof handleExport==='function')handleExport('pptx');">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg>
			Tải PPTX
		</button>
		<?php endif; ?>
	</div>
</div>
