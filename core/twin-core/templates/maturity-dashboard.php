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
 * Maturity Dashboard Template v4 — Knowledge Dashboard Overview
 *
 * Shows 3 grouped stat rows + overview charts.
 * Detail tabs are now on separate pages (Training, Memory, Monitor).
 *
 * @package BizCity_Twin_Core
 */
defined( 'ABSPATH' ) or die();
$td = 'bizcity-twin-ai';
$iframe_param = ! empty( $_GET['bizcity_iframe'] ) ? '&bizcity_iframe=1' : '';
?>
<div class="wrap bizcity-maturity-wrap">

	<!-- Header -->
	<div class="maturity-header">
		<div class="maturity-header__title">
			<h1>🧬 Knowledge Dashboard</h1>
			<div class="maturity-header__overall">
				<span class="overall-label"><?php esc_html_e( 'Knowledge Score', $td ); ?></span>
				<span class="overall-value" id="overall-score">—</span>
				<span class="overall-max">/ 100</span>
			</div>
		</div>
		<div class="maturity-header__level" id="maturity-level"></div>
	</div>

	<!-- Loading -->
	<div class="maturity-loading" id="maturity-loading">
		<div class="maturity-loading__spinner"></div>
		<p><?php esc_html_e( 'Analyzing data from 29 tables...', $td ); ?></p>
	</div>

	<!-- Content -->
	<div class="maturity-content" id="maturity-content" style="display:none">

		<!-- Row 1: Active Training -->
		<div class="stats-group">
			<div class="stats-group__label">📚 <?php esc_html_e( 'Active Training', $td ); ?></div>
			<div class="maturity-stats">
				<button class="stat-card" data-tab="overview" aria-selected="true">
					<span class="stat-icon">📊</span>
					<span class="stat-value" id="stat-overall">0</span>
					<span class="stat-label"><?php esc_html_e( 'Overview', $td ); ?></span>
				</button>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-training' . $iframe_param ) ); ?>" data-stat="quickfaq">
					<span class="stat-icon">❓</span>
					<span class="stat-value" id="stat-quickfaq">0</span>
					<span class="stat-label">Quick FAQ</span>
				</a>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-training' . $iframe_param ) ); ?>" data-stat="sources">
					<span class="stat-icon">📄</span>
					<span class="stat-value" id="stat-sources">0</span>
					<span class="stat-label"><?php esc_html_e( 'Documents', $td ); ?></span>
				</a>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-training' . $iframe_param ) ); ?>" data-stat="knowledge">
					<span class="stat-icon">🎭</span>
					<span class="stat-value" id="stat-knowledge">0</span>
					<span class="stat-label"><?php esc_html_e( 'Knowledge', $td ); ?></span>
				</a>
			</div>
		</div>

		<!-- Row 2: Auto-analyzed -->
		<div class="stats-group">
			<div class="stats-group__label">🧠 <?php esc_html_e( 'Auto-analyzed', $td ); ?></div>
			<div class="maturity-stats">
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-memory-hub' . $iframe_param ) ); ?>" data-stat="memories">
					<span class="stat-icon">🧠</span>
					<span class="stat-value" id="stat-memories">0</span>
					<span class="stat-label">User Memory</span>
				</a>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-memory-hub' . $iframe_param ) ); ?>" data-stat="episodic">
					<span class="stat-icon">🔮</span>
					<span class="stat-value" id="stat-episodic">0</span>
					<span class="stat-label">Episodic</span>
				</a>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-memory-hub' . $iframe_param ) ); ?>" data-stat="rolling">
					<span class="stat-icon">🔄</span>
					<span class="stat-value" id="stat-rolling">0</span>
					<span class="stat-label">Rolling</span>
				</a>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-memory-hub' . $iframe_param ) ); ?>" data-stat="notes">
					<span class="stat-icon">📝</span>
					<span class="stat-value" id="stat-notes">0</span>
					<span class="stat-label">Research</span>
				</a>
			</div>
		</div>

		<!-- Row 3: Resources -->
		<div class="stats-group">
			<div class="stats-group__label">💬 <?php esc_html_e( 'Resources', $td ); ?></div>
			<div class="maturity-stats">
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-monitor' . $iframe_param ) ); ?>" data-stat="sessions">
					<span class="stat-icon">💬</span>
					<span class="stat-value" id="stat-sessions">0</span>
					<span class="stat-label"><?php esc_html_e( 'Sessions', $td ); ?></span>
				</a>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-monitor' . $iframe_param ) ); ?>" data-stat="goals">
					<span class="stat-icon">🎯</span>
					<span class="stat-value" id="stat-goals">0</span>
					<span class="stat-label"><?php esc_html_e( 'Goals', $td ); ?></span>
				</a>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-monitor' . $iframe_param ) ); ?>" data-stat="messages">
					<span class="stat-icon">📨</span>
					<span class="stat-value" id="stat-messages">0</span>
					<span class="stat-label"><?php esc_html_e( 'Messages', $td ); ?></span>
				</a>
				<a class="stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-monitor' . $iframe_param ) ); ?>" data-stat="trend">
					<span class="stat-icon">📈</span>
					<span class="stat-value" id="stat-trend">0</span>
					<span class="stat-label"><?php esc_html_e( 'Trend', $td ); ?></span>
				</a>
			</div>
		</div>

		<!-- ═══ TAB: Overview ═══ -->
		<div class="maturity-tab-panel active" id="panel-overview">
			<div class="maturity-row">
				<div class="maturity-card maturity-card--full">
					<h3>🌊 Knowledge Wave — <?php esc_html_e( 'Knowledge flow', $td ); ?></h3>
					<p class="card-desc"><?php esc_html_e( 'Resources absorbed (file, web, text…) → memories created', $td ); ?></p>
					<div class="chart-container chart-container--wave"><canvas id="chart-wave"></canvas></div>
				</div>
			</div>
			<div class="maturity-row">
				<div class="maturity-card maturity-card--radar">
					<h3><?php esc_html_e( '5 Maturity Dimensions', $td ); ?></h3>
					<div class="chart-container chart-container--radar"><canvas id="chart-radar"></canvas></div>
					<div class="dimension-list" id="dimension-list"></div>
				</div>
				<div class="maturity-card maturity-card--growth">
					<h3><?php esc_html_e( 'Knowledge growth (30 days)', $td ); ?></h3>
					<div class="chart-container"><canvas id="chart-growth"></canvas></div>
				</div>
			</div>
			<div class="maturity-row">
				<div class="maturity-card maturity-card--full">
					<h3><?php esc_html_e( 'Knowledge score over time', $td ); ?></h3>
					<div class="chart-container"><canvas id="chart-timeline"></canvas></div>
				</div>
			</div>
			<div class="maturity-row">
				<div class="maturity-card maturity-card--full">
					<h3><?php esc_html_e( 'Daily goal execution', $td ); ?></h3>
					<div class="chart-container"><canvas id="chart-execution"></canvas></div>
				</div>
			</div>
		</div>

		<!-- Detail panels moved to dedicated pages: Training, Memory Hub, Chat Monitor -->

	</div><!-- .maturity-content -->
</div><!-- .wrap -->
