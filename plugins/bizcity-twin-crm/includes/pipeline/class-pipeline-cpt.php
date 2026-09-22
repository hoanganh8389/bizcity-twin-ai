<?php
/**
 * BizCity CRM — `bzcrm_pipeline` post type (PHASE-0.63A WP-1.1, lane S).
 *
 * The home of a pipeline definition. Deliberately `show_ui => false`: the editing surface is the
 * pipeline management screen of WP-5.6, whose acceptance is that a team lead who does not know JSON
 * can add a step and an SLA without calling IT. The WordPress post editor would fail that test, and
 * having two editors for the same document would let a hand-edited post_content break every run.
 *
 * Capability maps onto `bizcity_crm_manage_rules` (the same cap `crm.rules.manage` resolves to in
 * `BizCity_CRM_Authority`), so pipeline configuration follows the boundary contract of PHASE-0.60
 * rather than inventing a third permission axis.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-1.1)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_CPT', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_CPT {

	const POST_TYPE = 'bzcrm_pipeline';

	public static function register(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$cap = class_exists( 'BizCity_CRM_Capabilities' ) && defined( 'BizCity_CRM_Capabilities::CAP_MANAGE_RULES' )
			? BizCity_CRM_Capabilities::CAP_MANAGE_RULES
			: 'bizcity_crm_manage_rules';

		register_post_type( self::POST_TYPE, array(
			'label'               => 'CRM Pipeline',
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'hierarchical'        => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'supports'            => array( 'title' ),
			'capability_type'     => array( 'bzcrm_pipeline', 'bzcrm_pipelines' ),
			'map_meta_cap'        => true,
			'capabilities'        => array(
				'edit_post'              => $cap,
				'read_post'              => $cap,
				'delete_post'            => $cap,
				'edit_posts'             => $cap,
				'edit_others_posts'      => $cap,
				'publish_posts'          => $cap,
				'read_private_posts'     => $cap,
				'delete_posts'           => $cap,
				'delete_private_posts'   => $cap,
				'delete_published_posts' => $cap,
				'delete_others_posts'    => $cap,
				'edit_private_posts'     => $cap,
				'edit_published_posts'   => $cap,
				'create_posts'           => $cap,
			),
		) );

		// The registry caches per request; a definition saved through the post API must not go stale.
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'flush_registry' ), 10, 0 );
		add_action( 'deleted_post', array( __CLASS__, 'flush_registry' ), 10, 0 );
	}

	public static function flush_registry(): void {
		if ( class_exists( 'BizCity_CRM_Pipeline_Registry' ) ) {
			BizCity_CRM_Pipeline_Registry::flush_cache();
		}
	}
}
