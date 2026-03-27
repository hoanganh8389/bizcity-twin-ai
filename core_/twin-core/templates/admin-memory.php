<?php
/**
 * Admin Memory Page — Memory, Episodic, Rolling, Research (Notes)
 *
 * Inline editable tables with auto-save on blur.
 *
 * @package BizCity_Twin_Core
 */
defined( 'ABSPATH' ) or die();
$td = 'bizcity-twin-ai';
?>
<script>var bizcPageContext = 'memory';</script>
<div class="wrap bizcity-maturity-wrap">

	<!-- Header -->
	<div class="maturity-header">
		<div class="maturity-header__title">
			<h1>🧠 Memory Hub</h1>
			<div class="maturity-header__overall">
				<span class="overall-label"><?php esc_html_e( 'Long-term memory & auto-analysis', $td ); ?></span>
			</div>
		</div>
	</div>

	<!-- Loading -->
	<div class="maturity-loading" id="maturity-loading">
		<div class="maturity-loading__spinner"></div>
		<p><?php esc_html_e( 'Loading data...', $td ); ?></p>
	</div>

	<!-- Content -->
	<div class="maturity-content" id="maturity-content" style="display:none">

		<!-- Stats Row -->
		<div class="maturity-stats">
			<button class="stat-card" data-tab="memories" aria-selected="true">
				<span class="stat-icon">🧠</span>
				<span class="stat-value" id="stat-memories">0</span>
				<span class="stat-label">User Memory</span>
			</button>
			<button class="stat-card" data-tab="episodic">
				<span class="stat-icon">🔮</span>
				<span class="stat-value" id="stat-episodic">0</span>
				<span class="stat-label">Episodic</span>
			</button>
			<button class="stat-card" data-tab="rolling">
				<span class="stat-icon">🔄</span>
				<span class="stat-value" id="stat-rolling">0</span>
				<span class="stat-label">Rolling</span>
			</button>
			<button class="stat-card" data-tab="notes">
				<span class="stat-icon">📝</span>
				<span class="stat-value" id="stat-notes">0</span>
				<span class="stat-label">Research</span>
			</button>
		</div>

		<!-- ═══ TAB: User Memory ═══ -->
		<div class="maturity-tab-panel active" id="panel-memories">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>🧠 User Memory — <?php esc_html_e( 'Long-term Memory', $td ); ?></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="memories">+ <?php esc_html_e( 'Add', $td ); ?></button>
						<button class="bk-btn-export" data-tab="memories" data-format="json">📤 JSON</button>
						<button class="bk-btn-export" data-tab="memories" data-format="csv">📤 CSV</button>
						<label class="bk-btn-import">📥 Import<input type="file" accept=".json,.csv" data-tab="memories" hidden></label>
					</div>
				</div>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-memories"></div>
				<div class="maturity-row" style="margin-top:16px">
					<div class="maturity-card maturity-card--full" style="padding:12px">
						<div class="chart-container" style="height:180px"><canvas id="chart-memory-types"></canvas></div>
					</div>
				</div>
			</div>
		</div>

		<!-- ═══ TAB: Episodic Memory ═══ -->
		<div class="maturity-tab-panel" id="panel-episodic">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>🔮 Episodic Memory — <?php esc_html_e( 'Events', $td ); ?></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="episodic">+ <?php esc_html_e( 'Add', $td ); ?></button>
						<button class="bk-btn-export" data-tab="episodic" data-format="json">📤 JSON</button>
						<button class="bk-btn-export" data-tab="episodic" data-format="csv">📤 CSV</button>
						<label class="bk-btn-import">📥 Import<input type="file" accept=".json,.csv" data-tab="episodic" hidden></label>
					</div>
				</div>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-episodic"></div>
				<div class="maturity-row" style="margin-top:16px">
					<div class="maturity-card maturity-card--full" style="padding:12px">
						<div class="chart-container" style="height:180px"><canvas id="chart-episodic-types"></canvas></div>
					</div>
				</div>
			</div>
		</div>

		<!-- ═══ TAB: Rolling Memory ═══ -->
		<div class="maturity-tab-panel" id="panel-rolling">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>🔄 Rolling Memory</h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="rolling">+ <?php esc_html_e( 'Add', $td ); ?></button>
					</div>
				</div>
				<p class="card-desc"><?php esc_html_e( 'Auto-summarized every 5 conversation turns. You can add, edit, or delete.', $td ); ?></p>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-rolling"></div>
			</div>
		</div>

		<!-- ═══ TAB: Notes / Research ═══ -->
		<div class="maturity-tab-panel" id="panel-notes">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>📝 <?php esc_html_e( 'Research & Notes', $td ); ?></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="notes">+ <?php esc_html_e( 'Add', $td ); ?></button>
					</div>
				</div>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-notes"></div>
				<div class="maturity-row" style="margin-top:16px">
					<div class="maturity-card maturity-card--full" style="padding:12px">
						<div class="chart-container" style="height:180px"><canvas id="chart-note-types"></canvas></div>
					</div>
				</div>
			</div>
		</div>

	</div>
</div>
