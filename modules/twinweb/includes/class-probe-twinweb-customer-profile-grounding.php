<?php
/**
 * BizCity Diagnostics — modules.twin_gpt.customer_profile_grounding probe.
 *
 * R-DDV: 3-layer evidence for Twin GPT customer profile grounding.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-19 (PHASE-TWIN-GPT-PROFILE-GROUNDING)
 */

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — DDV probe for subject-first customer profile layer.
defined( 'ABSPATH' ) || exit;

$bizcity_twinweb_profile_probe_root = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR
	: dirname( __DIR__, 3 ) . '/';
$bizcity_twinweb_profile_probe_iface = $bizcity_twinweb_profile_probe_root . 'core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $bizcity_twinweb_profile_probe_iface ) ) {
	require_once $bizcity_twinweb_profile_probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Customer_Profile_Grounding', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Customer_Profile_Grounding implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.customer_profile_grounding'; }
	public function label(): string { return 'Twin GPT · Customer Profile Grounding'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: profile templates, tenant-scoped wp_usermeta answers, subject profile layer and composer contract.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 88; }
	public function icon(): string { return 'id'; }
	public function estimate_ms(): int { return 35; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 3 ) . '/';

		// [2026-07-21 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — use loaded class file paths when deploy layout differs from dev source tree.
		$core_file     = $this->class_file_or_fallback( 'BizCity_TwinBrain_Subject_Profile_Layer', $root . 'core/twinbrain/includes/class-twinbrain-subject-profile-layer.php' );
		$runtime_file  = $this->class_file_or_fallback( 'BizCity_TwinBrain_Runtime', $root . 'core/twinbrain/includes/class-twinbrain-runtime.php' );
		$composer_file = $this->class_file_or_fallback( 'BizCity_TwinBrain_Final_Composer', $root . 'core/twinbrain/includes/class-twinbrain-final-composer.php' );
		$surface_file  = $this->class_file_or_fallback( 'BizCity_TwinWeb_Profile_Grounding', __DIR__ . '/class-twinweb-profile-grounding.php' );
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — DDV covers CRM operator visibility for mirrored customer profile summaries.
		$crm_contacts_file = $root . 'plugins/bizcity-twin-crm/frontend/src/routes/contacts/ContactsTab.jsx';
		$seed_dir      = $root . 'modules/twinweb/profile-templates/';

		$core_src     = is_readable( $core_file ) ? (string) file_get_contents( $core_file ) : '';
		$runtime_src  = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';
		$composer_src = is_readable( $composer_file ) ? (string) file_get_contents( $composer_file ) : '';
		$surface_src  = is_readable( $surface_file ) ? (string) file_get_contents( $surface_file ) : '';
		$crm_contacts_src = is_readable( $crm_contacts_file ) ? (string) file_get_contents( $crm_contacts_file ) : '';
		$seed_files   = is_dir( $seed_dir ) ? glob( $seed_dir . '*.json' ) : array();
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — CRM React source is optional on dist-only deployments; runtime CRM mirror remains the authoritative gate.
		$crm_ui_marker_ok = '' === $crm_contacts_src
			|| ( false !== strpos( $crm_contacts_src, 'TwinGptProfilePanel' )
				&& false !== strpos( $crm_contacts_src, 'hasTwinGptProfile' )
				&& false !== strpos( $crm_contacts_src, 'aiProfileOnly' )
				&& false !== strpos( $crm_contacts_src, 'twin_gpt_profile' ) );
		$disk_missing = array();
		$disk_checks = array(
			'core layer source'        => '' !== $core_src,
			'runtime source'           => '' !== $runtime_src,
			'composer source'          => '' !== $composer_src,
			'surface source'           => '' !== $surface_src,
			'seed templates >= 8'      => is_array( $seed_files ) && count( $seed_files ) >= 8,
			'core META_PREFIX'         => false !== strpos( $core_src, 'META_PREFIX' ),
			'core meta prefix'         => false !== strpos( $core_src, 'bizcity_twin_profile_' ),
			'core seed guard'          => false !== strpos( $core_src, 'ensure_seed_templates_imported_if_empty' ),
			'core saved hook'          => false !== strpos( $core_src, 'bizcity_twin_profile_saved' ),
			'runtime resolved event'   => false !== strpos( $runtime_src, 'subject_profile_resolved' ),
			'runtime collect context'  => false !== strpos( $runtime_src, 'collect_subject_profile_context' ),
			'composer contract'        => false !== strpos( $composer_src, 'SUBJECT PROFILE CONTRACT' ),
			'surface answers route'    => false !== strpos( $surface_src, '/me/profile-answers' ),
			'surface global marker'    => false !== strpos( $surface_src, 'bizcityTwinProfileGrounding' ),
			'surface CRM sync'         => false !== strpos( $surface_src, 'sync_profile_to_crm_contact' ),
			'surface CRM profile key'  => false !== strpos( $surface_src, 'twin_gpt_profile' ),
			'CRM operator UI optional' => $crm_ui_marker_ok,
		);
		foreach ( $disk_checks as $label => $ok ) {
			if ( ! $ok ) { $disk_missing[] = $label; }
		}
		$loaded_contract_ok = class_exists( 'BizCity_TwinBrain_Subject_Profile_Layer' )
			&& class_exists( 'BizCity_TwinWeb_Profile_Grounding' )
			&& method_exists( 'BizCity_TwinBrain_Subject_Profile_Layer', 'collect_for_user' )
			&& method_exists( 'BizCity_TwinWeb_Profile_Grounding', 'me_put_template_answers' )
			&& method_exists( 'BizCity_TwinWeb_Profile_Grounding', 'sync_profile_to_crm_contact' )
			&& shortcode_exists( 'bizcity_twin_profile_grounding' )
			&& is_array( $seed_files )
			&& count( $seed_files ) >= 8;

		$disk_ok = ( '' !== $core_src
			&& '' !== $runtime_src
			&& '' !== $composer_src
			&& '' !== $surface_src
			&& is_array( $seed_files )
			&& count( $seed_files ) >= 8
			&& false !== strpos( $core_src, 'META_PREFIX' )
			&& false !== strpos( $core_src, 'bizcity_twin_profile_' )
			&& false !== strpos( $core_src, 'ensure_seed_templates_imported_if_empty' )
			&& false !== strpos( $core_src, 'bizcity_twin_profile_saved' )
			&& false !== strpos( $runtime_src, 'subject_profile_resolved' )
			&& false !== strpos( $runtime_src, 'collect_subject_profile_context' )
			&& false !== strpos( $composer_src, 'SUBJECT PROFILE CONTRACT' )
			&& false !== strpos( $surface_src, '/me/profile-answers' )
			&& false !== strpos( $surface_src, 'bizcityTwinProfileGrounding' )
			&& false !== strpos( $surface_src, 'sync_profile_to_crm_contact' )
			&& false !== strpos( $surface_src, 'twin_gpt_profile' )
			&& $crm_ui_marker_ok )
			|| $loaded_contract_ok;
		$step = array(
			'label'  => 'Disk · subject profile, CRM sync and operator UI markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? ( empty( $disk_missing ) ? 'Core layer, TwinWeb surface, 8 seed templates, runtime events, composer contract, CRM sync hook and CRM operator UI markers are present.' : 'Source marker drift tolerated because loaded contract, shortcode and seed templates are present. Missing source markers: ' . implode( ', ', $disk_missing ) )
				: 'Missing markers: ' . implode( ', ', $disk_missing ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) { $pass = false; }

		$crm_hook_ok = function_exists( 'has_action' )
			&& false !== has_action( 'bizcity_twin_profile_saved', array( 'BizCity_TwinWeb_Profile_Grounding', 'sync_profile_to_crm_contact' ) );
		$loader_ok = class_exists( 'BizCity_TwinBrain_Subject_Profile_Layer' )
			&& class_exists( 'BizCity_TwinWeb_Profile_Grounding' )
			&& method_exists( 'BizCity_TwinBrain_Subject_Profile_Layer', 'collect_for_user' )
			&& method_exists( 'BizCity_TwinWeb_Profile_Grounding', 'me_put_template_answers' )
			&& method_exists( 'BizCity_TwinWeb_Profile_Grounding', 'sync_profile_to_crm_contact' )
			&& $crm_hook_ok
			&& shortcode_exists( 'bizcity_twin_profile_grounding' );
		$step = array(
			'label'  => 'Loader · classes, methods, shortcode and CRM sync hook registered',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok
				? 'Subject layer, TwinWeb profile surface, customer shortcode and CRM contact enrichment hook are loaded.'
				: 'Subject layer class, TwinWeb surface class, REST methods, shortcode or CRM contact enrichment hook are not loaded.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_ok ) { $pass = false; }

		$runtime_ok = false;
		$runtime_detail = 'Runtime skipped because loader prerequisites failed.';
		if ( $loader_ok ) {
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — DDV runtime must not depend on an already-authenticated Diagnostics request.
			$origin_uid = (int) get_current_user_id();
			$uid = $origin_uid > 0 ? $origin_uid : $this->find_runtime_user_id();
			if ( $uid <= 0 ) {
				$runtime_detail = 'Runtime requires at least one WP user to exercise tenant-scoped usermeta.';
			} else {
				if ( $origin_uid !== $uid && function_exists( 'wp_set_current_user' ) ) {
					wp_set_current_user( $uid );
				}
				$blog_id = (int) get_current_blog_id();
				$meta_key = BizCity_TwinBrain_Subject_Profile_Layer::meta_key_for_blog( $blog_id );
				$had_meta = metadata_exists( 'user', $uid, $meta_key );
				$old_meta = get_user_meta( $uid, $meta_key, true );
				$sentinel = '__bizcity_twin_profile_missing__';
				$old_bindings = get_option( BizCity_TwinBrain_Subject_Profile_Layer::OPTION_BINDINGS, $sentinel );
				$crm_tbl = class_exists( 'BizCity_CRM_DB_Installer_V2' ) && method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_contacts' ) ? BizCity_CRM_DB_Installer_V2::tbl_contacts() : '';
				$crm_table_ready = $crm_tbl !== '' && $this->table_exists( $crm_tbl );
				$crm_user = get_userdata( $uid );
				$crm_before = $crm_table_ready ? $this->find_crm_contact_row( $crm_tbl, $uid, $crm_user ) : null;
				$crm_created_contact_id = 0;
				$act_tbl = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb']->prefix . 'bizcity_crm_activities' : '';
				$act_table_ready = $act_tbl !== '' && $this->table_exists( $act_tbl );
				$act_max_before = $act_table_ready ? $this->max_activity_id( $act_tbl ) : 0;
				try {
					$layer = BizCity_TwinBrain_Subject_Profile_Layer::instance();
					$bindings = $layer->save_bindings( array(
						'by_vertical' => array( 'career' => 'career_coach' ),
						'by_mode'     => array( 'notebooks' => 'career_coach' ),
						'default'     => 'career_coach',
					) );
					$saved = $layer->save_user_answers( $uid, 'career_coach', array(
						'current_role'   => 'Synthetic D-wave Architect',
						'career_goal'    => 'Ship subject-first Notebook personalization',
						'biggest_blocker'=> 'Generic answers without customer context',
					), true, $blog_id );
					$collected = $layer->collect_for_user( $uid, 'Tôi nên định vị sự nghiệp thế nào?', array(
						'vertical' => 'career',
						'web_mode' => 'off',
					) );

					$rest_ok = false;
					if ( class_exists( 'WP_REST_Request' ) ) {
						$req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/me/profile-templates' );
						$data = $this->response_data( BizCity_TwinWeb_Profile_Grounding::me_get_templates( $req ) );
						$rest_ok = ! empty( $data['success'] ) && ! empty( $data['templates'] ) && ! empty( $data['profile'] );
					}

					$crm_ok = true;
					$crm_detail = 'CRM mirror skipped because contact table is not available in this runtime.';
					if ( $crm_table_ready ) {
						$crm_after = $this->find_crm_contact_row( $crm_tbl, $uid, $crm_user );
						$crm_created_contact_id = ( ! $crm_before && ! empty( $crm_after['id'] ) ) ? (int) $crm_after['id'] : 0;
						$attrs = json_decode( (string) ( $crm_after['additional_attributes'] ?? '' ), true );
						$mirror = is_array( $attrs ) && isset( $attrs['twin_gpt_profile'] ) && is_array( $attrs['twin_gpt_profile'] ) ? $attrs['twin_gpt_profile'] : array();
						$activity_ok = ! $act_table_ready || $this->has_profile_activity_after( $act_tbl, (int) ( $crm_after['id'] ?? 0 ), $act_max_before );
						$crm_ok = ! empty( $crm_after['id'] )
							&& (int) ( $mirror['blog_id'] ?? 0 ) === $blog_id
							&& (string) ( $mirror['last_template_slug'] ?? '' ) === 'career_coach'
							&& (int) ( $mirror['facts_count'] ?? 0 ) >= 2
							&& $activity_ok;
						$crm_detail = $crm_ok
							? 'CRM contact mirror and activity timeline entry were written for the synthetic profile save.'
							: 'CRM contact mirror or profile activity timeline entry was not observed after synthetic save.';
					}

					$runtime_ok = ! is_wp_error( $saved )
						&& ! empty( $bindings['by_vertical']['career'] )
						&& ! empty( $collected['active'] )
						&& (int) ( $collected['facts_count'] ?? 0 ) >= 2
						&& false !== strpos( (string) ( $collected['context_md'] ?? '' ), 'Synthetic D-wave Architect' )
						&& empty( $collected['missing_required'] )
						&& $rest_ok
						&& $crm_ok;
					$runtime_detail = $runtime_ok
						? 'Synthetic current-user profile saved to tenant-scoped usermeta, collected as subject context, REST /me/profile-templates returned profile shape. ' . $crm_detail
						: 'Synthetic profile save/collect/REST/CRM contract failed. ' . $crm_detail;
				} catch ( Throwable $e ) {
					$runtime_detail = 'Runtime exception: ' . $e->getMessage();
				} finally {
					$this->restore_crm_probe_row( $crm_tbl, $crm_before, $crm_created_contact_id, $act_tbl, $act_max_before );
					if ( $had_meta ) {
						update_user_meta( $uid, $meta_key, $old_meta );
					} else {
						delete_user_meta( $uid, $meta_key );
					}
					if ( $sentinel === $old_bindings ) {
						delete_option( BizCity_TwinBrain_Subject_Profile_Layer::OPTION_BINDINGS );
					} else {
						update_option( BizCity_TwinBrain_Subject_Profile_Layer::OPTION_BINDINGS, $old_bindings, false );
					}
					if ( class_exists( 'BizCity_Cache' ) ) {
						BizCity_Cache::flush_group( 'chat' );
					}
					if ( $origin_uid !== $uid && function_exists( 'wp_set_current_user' ) ) {
						wp_set_current_user( $origin_uid );
					}
				}
			}
		}

		$step = array(
			'label'  => 'Runtime · tenant-scoped answers collect as subject context',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $runtime_ok ) { $pass = false; }

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Twin GPT customer profile grounding contract is ready.' : 'Twin GPT customer profile grounding contract is incomplete.',
			'error'    => $pass ? '' : 'customer_profile_grounding_failed',
			'fix_hint' => $pass ? '' : 'Check Subject Profile Layer, /me/profile-answers REST routes, seed templates and Final Composer subject contract.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Runtime step restores usermeta/options immediately.
	}

	private function response_data( $response ) {
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$data = $response->get_data();
			return is_array( $data ) ? $data : array();
		}
		if ( is_array( $response ) ) {
			return $response;
		}
		return array();
	}

	private function class_file_or_fallback( $class_name, $fallback ) {
		// [2026-07-21 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — reflection-backed DDV disk evidence for production path variance.
		if ( class_exists( $class_name, false ) ) {
			try {
				$ref = new ReflectionClass( $class_name );
				$file = (string) $ref->getFileName();
				if ( $file !== '' && is_readable( $file ) ) {
					return $file;
				}
			} catch ( Exception $e ) {
				// Fall through to configured path.
			}
		}
		return $fallback;
	}

	private function find_runtime_user_id() {
		if ( ! function_exists( 'get_users' ) ) {
			return 0;
		}
		$ids = get_users( array(
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		) );
		if ( empty( $ids ) ) {
			$ids = get_users( array(
				'blog_id' => 0,
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			) );
		}
		return empty( $ids ) ? 0 : (int) reset( $ids );
	}

	private function table_exists( $table_name ) {
		if ( '' === (string) $table_name ) {
			return false;
		}
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table_name );
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
	}

	private function find_crm_contact_row( $table_name, $user_id, $user ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table_name}` WHERE wp_user_id = %d AND deleted_at IS NULL LIMIT 1", (int) $user_id ), ARRAY_A );
		if ( $row || ! $user ) {
			return $row;
		}
		$email = sanitize_email( (string) $user->user_email );
		if ( '' !== $email ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table_name}` WHERE email = %s AND deleted_at IS NULL LIMIT 1", $email ), ARRAY_A );
		}
		return $row;
	}

	private function max_activity_id( $table_name ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table_name}`" );
	}

	private function has_profile_activity_after( $table_name, $contact_id, $min_id ) {
		if ( $contact_id <= 0 ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$table_name}` WHERE id > %d AND entity_type = %s AND entity_id = %d AND title LIKE %s LIMIT 1",
			(int) $min_id,
			'contact',
			(int) $contact_id,
			'Hồ sơ AI cập nhật%'
		) );
	}

	private function restore_crm_probe_row( $table_name, $old_row, $created_contact_id, $activity_table, $activity_min_id ) {
		if ( '' === (string) $table_name ) {
			return;
		}
		global $wpdb;
		if ( $created_contact_id > 0 ) {
			$wpdb->delete( $table_name, array( 'id' => (int) $created_contact_id ), array( '%d' ) );
		} elseif ( is_array( $old_row ) && ! empty( $old_row['id'] ) ) {
			$restore = array();
			foreach ( array( 'name', 'email', 'phone', 'wp_user_id', 'additional_attributes', 'updated_at' ) as $key ) {
				if ( array_key_exists( $key, $old_row ) ) {
					$restore[ $key ] = $old_row[ $key ];
				}
			}
			if ( ! empty( $restore ) ) {
				$wpdb->update( $table_name, $restore, array( 'id' => (int) $old_row['id'] ) );
			}
		}
		if ( '' !== (string) $activity_table && $this->table_exists( $activity_table ) ) {
			$contact_id = $created_contact_id > 0 ? $created_contact_id : (int) ( is_array( $old_row ) ? ( $old_row['id'] ?? 0 ) : 0 );
			if ( $contact_id > 0 ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM `{$activity_table}` WHERE id > %d AND entity_type = %s AND entity_id = %d AND title LIKE %s",
					(int) $activity_min_id,
					'contact',
					(int) $contact_id,
					'Hồ sơ AI cập nhật%'
				) );
			}
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ) {
	if ( class_exists( 'BizCity_Probe_TwinWeb_Customer_Profile_Grounding', false ) ) {
		$probes[] = new BizCity_Probe_TwinWeb_Customer_Profile_Grounding();
	}
	return $probes;
} );
