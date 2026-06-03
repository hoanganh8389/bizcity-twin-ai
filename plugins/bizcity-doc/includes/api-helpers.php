<?php
/**
 * BizCity Doc — Public API helpers (R-OF / PHASE-0-RULE-OUTPUT-FILES)
 *
 * `bzdoc_register_document( $args )` is the canonical hub-side helper that
 * any generator plugin (tool-image, video-kling, content-creator, twinchat
 * studio, user upload, …) MUST call to register an output file. There is no
 * other writer of `wp_*_bzdoc_documents` allowed by R-OF-2.
 *
 * @package bizcity-doc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whitelist of allowed `generator` slugs (R-OF-7).
 *
 * @return string[]
 */
function bzdoc_get_generator_whitelist(): array {
	$default = [
		'user_upload',
		'bizcity-doc',
		'bizcity-tool-image',
		'bizcity-video-kling',
		'bizcity-content-creator',
		'twinchat-studio',
		'crm-inbox',
		'workflow-block',
	];
	/** @var string[] $list */
	$list = apply_filters( 'bzdoc_register_generator', $default );
	return array_values( array_unique( array_filter( array_map( 'strval', $list ) ) ) );
}

/**
 * Register a document in the canonical bzdoc_documents store.
 *
 * Required keys: `generator`, `doc_type`.
 * Optional keys: `notebook_id`, `source_skeleton_version`, `origin`, `job_id`,
 *                `media_id`, `file_url`, `mime`, `size_bytes`, `title`,
 *                `schema_json`, `template_name`, `theme_name`, `status`,
 *                `parent_event_uuid`, `created_by`.
 *
 * Returns the new document ID, or WP_Error on validation failure.
 *
 * @param array<string, mixed> $args
 * @return int|WP_Error
 */
function bzdoc_register_document( array $args ) {
	global $wpdb;

	$generator = isset( $args['generator'] ) ? (string) $args['generator'] : '';
	$doc_type  = isset( $args['doc_type'] ) ? (string) $args['doc_type'] : '';

	if ( $generator === '' || ! in_array( $generator, bzdoc_get_generator_whitelist(), true ) ) {
		return new WP_Error(
			'bzdoc_invalid_generator',
			sprintf( 'Generator "%s" is not in the R-OF-7 whitelist.', $generator )
		);
	}
	if ( $doc_type === '' ) {
		return new WP_Error( 'bzdoc_missing_doc_type', 'doc_type is required.' );
	}

	$origin = isset( $args['origin'] ) ? (string) $args['origin'] : 'generated';
	if ( ! in_array( $origin, [ 'upload', 'generated' ], true ) ) {
		$origin = 'generated';
	}

	$user_id = isset( $args['created_by'] ) ? (int) $args['created_by']
		: ( isset( $args['user_id'] ) ? (int) $args['user_id'] : (int) get_current_user_id() );

	$notebook_id = isset( $args['notebook_id'] ) ? (int) $args['notebook_id'] : 0;
	$skeleton_v  = isset( $args['source_skeleton_version'] ) ? (int) $args['source_skeleton_version'] : 0;

	// R-OF-4 — snapshot skeleton_version automatically when notebook_id provided.
	if ( $notebook_id > 0 && $skeleton_v === 0 && class_exists( 'BizCity_KG_Skeleton_Adapter' ) ) {
		$skeleton_v = (int) BizCity_KG_Skeleton_Adapter::get_version( $notebook_id );
	}

	$media_id = isset( $args['media_id'] ) && $args['media_id'] !== null
		? (int) $args['media_id']
		: null;

	$file_url = isset( $args['file_url'] ) ? (string) $args['file_url'] : '';
	if ( $file_url === '' && $media_id ) {
		$resolved = wp_get_attachment_url( $media_id );
		if ( is_string( $resolved ) ) {
			$file_url = $resolved;
		}
	}

	$mime = isset( $args['mime'] ) ? (string) $args['mime'] : '';
	if ( $mime === '' && $media_id ) {
		$post = get_post( $media_id );
		if ( $post instanceof WP_Post ) {
			$mime = (string) $post->post_mime_type;
		}
	}

	$size_bytes = isset( $args['size_bytes'] ) ? (int) $args['size_bytes'] : 0;
	if ( $size_bytes === 0 && $media_id ) {
		$path = get_attached_file( $media_id );
		if ( is_string( $path ) && file_exists( $path ) ) {
			$size_bytes = (int) filesize( $path );
		}
	}

	$schema_json = $args['schema_json'] ?? [];
	if ( ! is_string( $schema_json ) ) {
		$schema_json = wp_json_encode( $schema_json );
	}
	if ( ! is_string( $schema_json ) ) {
		$schema_json = '{}';
	}

	$now = current_time( 'mysql' );

	// Idempotency guard: same (generator, job_id) inserted twice → return existing.
	$job_id = isset( $args['job_id'] ) && $args['job_id'] !== null ? (int) $args['job_id'] : null;
	if ( $job_id && $generator !== '' ) {
		$table = $wpdb->prefix . 'bzdoc_documents';
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE generator = %s AND job_id = %d LIMIT 1",
			$generator,
			$job_id
		) );
		if ( $existing_id > 0 ) {
			return $existing_id;
		}
	}

	$row = [
		'user_id'                 => $user_id,
		'doc_type'                => $doc_type,
		'title'                   => isset( $args['title'] ) ? (string) $args['title'] : '',
		'template_name'           => isset( $args['template_name'] ) ? (string) $args['template_name'] : 'blank',
		'theme_name'              => isset( $args['theme_name'] ) ? (string) $args['theme_name'] : 'modern',
		'schema_json'             => $schema_json,
		'status'                  => isset( $args['status'] ) ? (string) $args['status'] : 'ready',
		'notebook_id'             => $notebook_id,
		'source_skeleton_version' => $skeleton_v,
		'generator'               => $generator,
		'origin'                  => $origin,
		'job_id'                  => $job_id,
		'media_id'                => $media_id,
		'file_url'                => $file_url,
		'mime'                    => $mime,
		'size_bytes'              => $size_bytes,
		'parent_event_uuid'       => isset( $args['parent_event_uuid'] ) ? (string) $args['parent_event_uuid'] : null,
		'created_at'              => $now,
		'updated_at'              => $now,
	];

	$formats = [
		'%d', // user_id
		'%s', // doc_type
		'%s', // title
		'%s', // template_name
		'%s', // theme_name
		'%s', // schema_json
		'%s', // status
		'%d', // notebook_id
		'%d', // source_skeleton_version
		'%s', // generator
		'%s', // origin
		$job_id === null ? '%s' : '%d', // job_id (nullable)
		$media_id === null ? '%s' : '%d', // media_id (nullable)
		'%s', // file_url
		'%s', // mime

		'%d', // size_bytes
		'%s', // parent_event_uuid
		'%s', // created_at
		'%s', // updated_at
	];

	$table = $wpdb->prefix . 'bzdoc_documents';
	$ok    = $wpdb->insert( $table, $row, $formats );
	if ( $ok === false ) {
		// Self-heal once: maybe schema patch hasn't run.
		if ( class_exists( 'BZDoc_Installer' ) ) {
			BZDoc_Installer::maybe_create_tables( true );
			$ok = $wpdb->insert( $table, $row, $formats );
		}
	}
	if ( $ok === false ) {
		return new WP_Error( 'bzdoc_insert_failed', $wpdb->last_error ?: 'Insert failed' );
	}

	$doc_id = (int) $wpdb->insert_id;

	// R-OF-6 / R-EVT-6 — fire so consumers (CRM list, Notebook Files, Skeleton bumper) can react.
	do_action( 'bzdoc_document_created', $doc_id, $row );

	return $doc_id;
}

/**
 * Soft-delete: flip status to `deleted` so the retention cron will purge later.
 *
 * @param int $doc_id
 * @return bool|WP_Error
 */
function bzdoc_soft_delete_document( int $doc_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'bzdoc_documents';
	$ok    = $wpdb->update(
		$table,
		[ 'status' => 'deleted', 'updated_at' => current_time( 'mysql' ) ],
		[ 'id' => $doc_id ],
		[ '%s', '%s' ],
		[ '%d' ]
	);
	if ( $ok === false ) {
		return new WP_Error( 'bzdoc_soft_delete_failed', $wpdb->last_error ?: 'Update failed' );
	}
	do_action( 'bzdoc_document_soft_deleted', $doc_id );
	return true;
}
