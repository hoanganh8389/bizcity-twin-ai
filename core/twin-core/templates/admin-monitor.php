<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Admin Chat Monitor Page — Sessions, Goals, Messages (monitor), Trend
 *
 * @package BizCity_Twin_Core
 */
defined( 'ABSPATH' ) or die();
$td = 'bizcity-twin-ai';
?>
<script>var bizcPageContext = 'monitor';</script>
<div class="wrap bizcity-maturity-wrap">

	<!-- Header -->
	<div class="maturity-header">
		<div class="maturity-header__title">
			<h1>📊 Chat Monitor</h1>
			<div class="maturity-header__overall">
				<span class="overall-label"><?php esc_html_e( 'Session tracking & trends', $td ); ?></span>
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
			<button class="stat-card" data-tab="sessions" aria-selected="true">
				<span class="stat-icon">💬</span>
				<span class="stat-value" id="stat-sessions">0</span>
				<span class="stat-label"><?php esc_html_e( 'Sessions', $td ); ?></span>
			</button>
			<button class="stat-card" data-tab="goals">
				<span class="stat-icon">🎯</span>
				<span class="stat-value" id="stat-goals">0</span>
				<span class="stat-label"><?php esc_html_e( 'Goals', $td ); ?></span>
			</button>
			<button class="stat-card" data-tab="messages">
				<span class="stat-icon">📨</span>
				<span class="stat-value" id="stat-messages">0</span>
				<span class="stat-label"><?php esc_html_e( 'Messages', $td ); ?></span>
			</button>
			<button class="stat-card" data-tab="trend">
				<span class="stat-icon">📈</span>
				<span class="stat-value" id="stat-trend">0</span>
				<span class="stat-label"><?php esc_html_e( 'Trend', $td ); ?></span>
			</button>
		</div>

		<!-- ═══ TAB: Sessions ═══ -->
		<div class="maturity-tab-panel active" id="panel-sessions">
			<div class="maturity-row">
				<div class="maturity-card maturity-card--full">
					<h3>💬 <?php esc_html_e( 'Sessions by platform', $td ); ?></h3>
					<div class="chart-container" style="height:220px"><canvas id="chart-session-platforms"></canvas></div>
				</div>
			</div>
			<div class="maturity-card maturity-card--full">
				<h3><?php esc_html_e( 'Session list', $td ); ?></h3>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-sessions"></div>
			</div>
		</div>

		<!-- ═══ TAB: Goals ═══ -->
		<div class="maturity-tab-panel" id="panel-goals">
			<div class="maturity-row">
				<div class="maturity-card maturity-card--full">
					<h3>🎯 <?php esc_html_e( 'Goal status', $td ); ?></h3>
					<div class="chart-container" style="height:220px"><canvas id="chart-goal-status"></canvas></div>
				</div>
			</div>
			<div class="maturity-card maturity-card--full">
				<h3><?php esc_html_e( 'Goal list', $td ); ?></h3>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-goals"></div>
			</div>
		</div>

		<!-- ═══ TAB: Messages (Monitor-style) ═══ -->
		<div class="maturity-tab-panel" id="panel-messages">
			<div class="maturity-row">
				<div class="maturity-card maturity-card--full">
					<h3>📨 <?php esc_html_e( 'Messages by type', $td ); ?></h3>
					<div class="chart-container" style="height:200px"><canvas id="chart-msg-types"></canvas></div>
				</div>
			</div>
			<div class="maturity-card maturity-card--full">
				<h3><?php esc_html_e( 'Recent messages (Monitor)', $td ); ?></h3>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-messages"></div>
			</div>
		</div>

		<!-- ═══ TAB: Trend ═══ -->
		<div class="maturity-tab-panel" id="panel-trend">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>📈 <?php esc_html_e( 'Maturity trend — Snapshot Timeline', $td ); ?></h3>
				</div>
				<p class="card-desc"><?php esc_html_e( 'Compare 5 dimensions day-by-day (automatic snapshot data)', $td ); ?></p>
				<div class="chart-container chart-container--trend"><canvas id="chart-trend"></canvas></div>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-trend"></div>
			</div>
		</div>

	</div>
</div>
