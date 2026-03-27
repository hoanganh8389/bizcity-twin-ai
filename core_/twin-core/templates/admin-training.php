<?php
/**
 * Admin Training Page — Quick FAQ, Documents, Website
 *
 * Inline editable tables with export/import support.
 *
 * @package BizCity_Twin_Core
 */
defined( 'ABSPATH' ) or die();
?>
<script>var bizcPageContext = 'training';</script>
<div class="wrap bizcity-maturity-wrap">

	<!-- Header -->
	<div class="maturity-header">
		<div class="maturity-header__title">
			<h1>📚 Đào tạo AI</h1>
			<div class="maturity-header__overall">
				<span class="overall-label">Dữ liệu huấn luyện chủ động</span>
			</div>
		</div>
	</div>

	<!-- Loading -->
	<div class="maturity-loading" id="maturity-loading">
		<div class="maturity-loading__spinner"></div>
		<p>Đang tải dữ liệu...</p>
	</div>

	<!-- Content -->
	<div class="maturity-content" id="maturity-content" style="display:none">

		<!-- Stats Row -->
		<div class="maturity-stats">
			<button class="stat-card" data-tab="quickfaq" aria-selected="true">
				<span class="stat-icon">❓</span>
				<span class="stat-value" id="stat-quickfaq">0</span>
				<span class="stat-label">Quick FAQ</span>
			</button>
			<button class="stat-card" data-tab="sources">
				<span class="stat-icon">📄</span>
				<span class="stat-value" id="stat-sources">0</span>
				<span class="stat-label">Tài liệu</span>
			</button>
			<button class="stat-card" data-tab="knowledge">
				<span class="stat-icon">🎭</span>
				<span class="stat-value" id="stat-knowledge">0</span>
				<span class="stat-label">Tri thức</span>
			</button>
		</div>

		<!-- ═══ TAB: Quick FAQ ═══ -->
		<div class="maturity-tab-panel active" id="panel-quickfaq">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>❓ Quick FAQ — Huấn luyện Hỏi & Đáp</h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="quickfaq">+ Thêm</button>
						<button class="bk-btn-export" data-tab="quickfaq" data-format="json">📤 JSON</button>
						<button class="bk-btn-export" data-tab="quickfaq" data-format="csv">📤 CSV</button>
						<label class="bk-btn-import">📥 Import<input type="file" accept=".json,.csv" data-tab="quickfaq" hidden></label>
					</div>
				</div>
				<div class="detail-loading">Đang tải...</div>
				<div class="detail-list" id="detail-quickfaq"></div>
			</div>
		</div>

		<!-- ═══ TAB: Sources / Tài liệu ═══ -->
		<div class="maturity-tab-panel" id="panel-sources">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>📄 Nguồn tài liệu đào tạo</h3>
				</div>
				<p class="card-desc">Tệp, văn bản, URL đã upload để huấn luyện AI</p>
				<div class="detail-loading">Đang tải...</div>
				<div class="detail-list" id="detail-sources"></div>
			</div>
		</div>

		<!-- ═══ TAB: Knowledge Characters ═══ -->
		<div class="maturity-tab-panel" id="panel-knowledge">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>🎭 Nhân vật Tri thức</h3>
				</div>
				<p class="card-desc">Các nhân vật AI mang kiến thức chuyên sâu</p>
				<div class="detail-loading">Đang tải...</div>
				<div class="detail-list" id="detail-knowledge"></div>
			</div>
		</div>

	</div>
</div>
