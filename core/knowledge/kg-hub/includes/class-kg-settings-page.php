<?php
/**
 * Bizcity Twin AI — KG_Settings_Page
 *
 * Phase 0.5 Sprint 1.
 *
 * Submenu under Knowledge Graph for Cost Guard settings + today's usage widget.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Settings_Page {

	const PAGE_SLUG  = 'bizcity-kg-hub-settings';
	const NONCE_KEY  = 'bizcity_kg_settings_save';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_submenu_page(
			BizCity_KG_Admin_Menu::PAGE_SLUG,
			__( 'KG Settings & Cost', 'bizcity-knowledge' ),
			__( 'Settings & Cost', 'bizcity-knowledge' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function render() {
		$guard = BizCity_KG_Cost_Guard::instance();
		$saved = false;

		if ( ! empty( $_POST ) && check_admin_referer( self::NONCE_KEY ) ) {
			update_option( BizCity_KG_Cost_Guard::OPT_ENABLED,    isset( $_POST['cg_enabled'] ) ? 1 : 0, false );
			update_option( BizCity_KG_Cost_Guard::OPT_QUOTA_USER, max( 1,   (int)   $_POST['cg_quota'] ), false );
			update_option( BizCity_KG_Cost_Guard::OPT_CAP_USD,    max( 0.1, (float) $_POST['cg_cap']   ), false );
			update_option( BizCity_KG_Cost_Guard::OPT_DEDUPE_TH,  max( 0.5, min( 1.0, (float) $_POST['cg_dedupe'] ) ), false );
			update_option( BizCity_KG_Cost_Guard::OPT_BATCH_SIZE, max( 1, min( 20, (int) $_POST['cg_batch'] ) ), false );
			$saved = true;
		}

		$summary = $guard->summary_today();
		$enabled = $guard->is_enabled();
		$quota   = $guard->quota_per_user();
		$cap     = $guard->daily_cap_usd();
		$dedupe  = $guard->dedupe_threshold();
		$batch   = $guard->batch_size();

		$pct      = (int) round( $summary['pct'] * 100 );
		$bar_col  = $pct >= 80 ? '#dc2626' : ( $pct >= 50 ? '#f59e0b' : '#10b981' );
		?>
		<div class="wrap">
			<h1>🛡️ KG Hub — Settings & Cost Guard</h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px;">
				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;">
					<h2 style="margin-top:0;">Today's Usage</h2>
					<div style="font-size:32px;font-weight:700;color:<?php echo esc_attr( $bar_col ); ?>;">
						$<?php echo number_format( $summary['spent_usd'], 4 ); ?>
						<span style="font-size:16px;color:#6b7280;">/ $<?php echo number_format( $cap, 2 ); ?></span>
					</div>
					<div style="background:#f3f4f6;height:10px;border-radius:5px;overflow:hidden;margin-top:8px;">
						<div style="width:<?php echo $pct; ?>%;height:100%;background:<?php echo esc_attr( $bar_col ); ?>;transition:width .3s;"></div>
					</div>
					<table class="widefat striped" style="margin-top:14px;">
						<tbody>
							<tr><td><strong>LLM calls</strong></td><td><?php echo (int) $summary['calls']; ?></td></tr>
							<tr><td><strong>Input tokens</strong></td><td><?php echo number_format( $summary['in_tokens'] ); ?></td></tr>
							<tr><td><strong>Output tokens</strong></td><td><?php echo number_format( $summary['out_tokens'] ); ?></td></tr>
							<tr><td><strong>Cap remaining</strong></td><td>$<?php echo number_format( max( 0, $cap - $summary['spent_usd'] ), 4 ); ?></td></tr>
						</tbody>
					</table>
					<p style="color:#6b7280;font-size:12px;margin-top:10px;">
						Auto-resets daily. Email alert fires at 80% cap.
					</p>
				</div>

				<form method="post" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;">
					<?php wp_nonce_field( self::NONCE_KEY ); ?>
					<h2 style="margin-top:0;">Cost Guard Settings</h2>

					<table class="form-table" role="presentation">
						<tr>
							<th><label for="cg_enabled">Enabled</label></th>
							<td><label><input type="checkbox" id="cg_enabled" name="cg_enabled" value="1" <?php checked( $enabled ); ?> />
								Enforce quota and cap (recommended)</label></td>
						</tr>
						<tr>
							<th><label for="cg_quota">Daily quota / user</label></th>
							<td><input type="number" id="cg_quota" name="cg_quota" value="<?php echo (int) $quota; ?>" min="1" max="10000" class="small-text" />
								<span style="color:#6b7280;">passages per user per day</span></td>
						</tr>
						<tr>
							<th><label for="cg_cap">Daily site cap (USD)</label></th>
							<td>$ <input type="number" id="cg_cap" name="cg_cap" value="<?php echo esc_attr( number_format( $cap, 2, '.', '' ) ); ?>" min="0.10" step="0.10" class="small-text" />
								<span style="color:#6b7280;">hard stop for the whole site</span></td>
						</tr>
						<tr>
							<th><label for="cg_dedupe">Dedupe cosine threshold</label></th>
							<td><input type="number" id="cg_dedupe" name="cg_dedupe" value="<?php echo esc_attr( number_format( $dedupe, 2, '.', '' ) ); ?>" min="0.50" max="1.00" step="0.01" class="small-text" />
								<span style="color:#6b7280;">skip near-duplicate passages (≥ this similarity)</span></td>
						</tr>
						<tr>
							<th><label for="cg_batch">Extract batch size</label></th>
							<td><input type="number" id="cg_batch" name="cg_batch" value="<?php echo (int) $batch; ?>" min="1" max="20" class="small-text" />
								<span style="color:#6b7280;">passages per single LLM call (higher = cheaper but riskier)</span></td>
						</tr>
					</table>

					<p><button type="submit" class="button button-primary">Save Settings</button></p>
				</form>
			</div>

			<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-top:20px;">
				<h2 style="margin-top:0;">Recent Activity</h2>
				<?php $this->render_recent_table(); ?>
			</div>
		</div>
		<?php
	}

	private function render_recent_table() {
		global $wpdb;
		$t = BizCity_KG_Cost_Guard::instance()->table();
		$rows = $wpdb->get_results(
			"SELECT id, day, user_id, operation, notebook_id, passage_id, input_tokens, output_tokens, cost_usd, created_at
			 FROM {$t}
			 ORDER BY id DESC LIMIT 30",
			ARRAY_A
		) ?: [];

		if ( ! $rows ) {
			echo '<p style="color:#6b7280;">No usage recorded yet.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>When</th><th>User</th><th>Op</th><th>Notebook</th><th>Passage</th><th>In tok</th><th>Out tok</th><th>Cost USD</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
			echo '<td>' . (int) $r['user_id'] . '</td>';
			echo '<td><code>' . esc_html( $r['operation'] ) . '</code></td>';
			echo '<td>' . ( $r['notebook_id'] ? (int) $r['notebook_id'] : '—' ) . '</td>';
			echo '<td>' . ( $r['passage_id']  ? (int) $r['passage_id']  : '—' ) . '</td>';
			echo '<td>' . number_format( (int) $r['input_tokens'] ) . '</td>';
			echo '<td>' . number_format( (int) $r['output_tokens'] ) . '</td>';
			echo '<td>$' . number_format( (float) $r['cost_usd'], 6 ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
