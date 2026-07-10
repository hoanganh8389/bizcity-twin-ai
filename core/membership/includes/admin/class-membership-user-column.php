<?php
/**
 * Bizcity Twin AI — Membership_User_Column
 *
 * PHASE-MEMBERSHIP M1.
 *
 * Adds a "Plan" column to the WP Users list table and a manual plan-assign
 * box on the edit-user / profile screen. Gives admins CRUD over a member's
 * plan without any payment step (goal #2 — tự set gói cho member).
 *
 * PHP 7.4-safe.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_User_Column {

	const NONCE_ACTION = 'bizcity_membership_set_plan';
	const NONCE_FIELD  = 'bizcity_membership_nonce';

	/** @var bool */
	private static $booted = false;

	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );

		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_box' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_box' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_box' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_box' ) );
	}

	/* ── List table column ──────────────────────────────────────────────── */

	/**
	 * @param array $columns
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['bizcity_plan'] = __( 'Plan', 'bizcity-twin-ai' );
		return $columns;
	}

	/**
	 * @param string $output
	 * @param string $column
	 * @param int    $user_id
	 * @return string
	 */
	public static function render_column( $output, $column, $user_id ) {
		if ( 'bizcity_plan' !== $column ) {
			return $output;
		}
		$slug     = BizCity_Membership_Manager::instance()->plan_for_user( $user_id );
		$plan     = BizCity_Membership_Plan_Registry::instance()->get( $slug );
		// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache
		$source   = class_exists( 'BizCity_User_Meta_Cache' )
			? (string) BizCity_User_Meta_Cache::get( $user_id, BizCity_Membership_Manager::META_SOURCE, '' )
			: (string) get_user_meta( $user_id, BizCity_Membership_Manager::META_SOURCE, true );
		$label    = esc_html( $plan['label'] );
		$badge_bg  = '#e5e7eb';
		$badge_fg  = '#111827';
		if ( $slug !== 'free' ) {
			$badge_bg = '#dbeafe';
			$badge_fg = '#1e3a8a';
		}
		$html = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:' . esc_attr( $badge_bg ) . ';color:' . esc_attr( $badge_fg ) . ';">' . $label . '</span>';
		if ( $source !== '' && $source !== BizCity_Membership_Manager::SOURCE_DEFAULT ) {
			$html .= '<br><small style="color:#6b7280;">' . esc_html( $source ) . '</small>';
		}
		return $html;
	}

	/* ── Profile box (manual assign) ────────────────────────────────────── */

	/**
	 * @param WP_User $user
	 * @return void
	 */
	public static function render_profile_box( $user ) {
		if ( ! current_user_can( 'edit_users' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$registry = BizCity_Membership_Plan_Registry::instance();
		$manager  = BizCity_Membership_Manager::instance();
		$current  = $manager->plan_for_user( $user->ID );
		// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache
		$until    = class_exists( 'BizCity_User_Meta_Cache' )
			? (string) BizCity_User_Meta_Cache::get( $user->ID, BizCity_Membership_Manager::META_VALID_UNTIL, '' )
			: (string) get_user_meta( $user->ID, BizCity_Membership_Manager::META_VALID_UNTIL, true );
		$until_in = $until !== '' ? esc_attr( gmdate( 'Y-m-d', strtotime( $until ) ) ) : '';
		?>
		<h2><?php esc_html_e( 'BizCity Membership', 'bizcity-twin-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="bizcity_member_plan"><?php esc_html_e( 'Plan', 'bizcity-twin-ai' ); ?></label></th>
				<td>
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<select name="bizcity_member_plan" id="bizcity_member_plan">
						<?php foreach ( $registry->all() as $slug => $plan ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
								<?php echo esc_html( $plan['label'] . ' — ' . $registry->price_label( $slug ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Assign a plan manually (no payment). Leave expiry empty for lifetime.', 'bizcity-twin-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="bizcity_member_valid_until"><?php esc_html_e( 'Expires on', 'bizcity-twin-ai' ); ?></label></th>
				<td>
					<input type="date" name="bizcity_member_valid_until" id="bizcity_member_valid_until" value="<?php echo $until_in; ?>" />
					<p class="description"><?php esc_html_e( 'Empty = lifetime / no expiry.', 'bizcity-twin-ai' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	public static function save_profile_box( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! isset( $_POST['bizcity_member_plan'] ) ) {
			return;
		}

		$plan  = sanitize_key( wp_unslash( $_POST['bizcity_member_plan'] ) );
		$until = isset( $_POST['bizcity_member_valid_until'] )
			? sanitize_text_field( wp_unslash( $_POST['bizcity_member_valid_until'] ) )
			: '';

		$valid_until = '';
		if ( $until !== '' ) {
			$ts = strtotime( $until . ' 23:59:59' );
			if ( $ts ) {
				$valid_until = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		BizCity_Membership_Manager::instance()->set_plan(
			$user_id,
			$plan,
			$valid_until,
			BizCity_Membership_Manager::SOURCE_ADMIN
		);
	}
}
