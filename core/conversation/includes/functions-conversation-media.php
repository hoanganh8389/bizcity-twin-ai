<?php
/**
 * Bizcity Twin AI — chat image helpers (base64 data URL → Media Library).
 *
 * [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 FATAL-SWEEP — ported from the archived webchat bootstrap.
 * Admin Chat and the Chat Send Service still turn pasted images into attachments through these two
 * functions; without them raw data URLs were stored as image "URLs". The port only accepts raster image
 * types (the archived version trusted the data-URL subtype as the file extension).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Conversation
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! function_exists( 'bizcity_save_base64_to_media' ) ) {
	/**
	 * Save a base64 image data URL as a Media Library attachment.
	 *
	 * @param string $base64_data e.g. "data:image/png;base64,...".
	 * @param string $filename    Optional file name (extension is always taken from the whitelist).
	 * @return array|WP_Error { attachment_id, url, file, type }
	 */
	function bizcity_save_base64_to_media( $base64_data, $filename = '' ) {
		if ( ! is_string( $base64_data ) || ! preg_match( '/^data:image\/([a-z0-9.+-]+);base64,(.+)$/is', $base64_data, $matches ) ) {
			return new WP_Error( 'invalid_format', 'Invalid base64 data URL format' );
		}
		$mime_map = array(
			'jpeg' => array( 'jpg', 'image/jpeg' ),
			'jpg'  => array( 'jpg', 'image/jpeg' ),
			'png'  => array( 'png', 'image/png' ),
			'gif'  => array( 'gif', 'image/gif' ),
			'webp' => array( 'webp', 'image/webp' ),
		);
		$subtype = strtolower( $matches[1] );
		if ( ! isset( $mime_map[ $subtype ] ) ) {
			return new WP_Error( 'unsupported_type', 'Unsupported image type' );
		}
		list( $ext, $mime_type ) = $mime_map[ $subtype ];

		$data = base64_decode( $matches[2], true );
		if ( false === $data || '' === $data ) {
			return new WP_Error( 'decode_error', 'Failed to decode base64 data' );
		}

		$base     = '' !== (string) $filename ? preg_replace( '/\.[^.]+$/', '', (string) $filename ) : 'upload_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false );
		$filename = sanitize_file_name( $base . '.' . $ext );

		$upload = wp_upload_bits( $filename, null, $data );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'write_error', 'Failed to write file to uploads' );
		}
		$file_path = $upload['file'];

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime_type,
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_path ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path,
			0
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $file_path );
			return is_wp_error( $attachment_id ) ? $attachment_id : new WP_Error( 'attachment_error', 'Failed to create attachment' );
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file_path ) );

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'file'          => $file_path,
			'type'          => $mime_type,
		);
	}
}

if ( ! function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
	/**
	 * Keep http(s) URLs, turn base64 image data URLs into Media Library URLs, drop anything else.
	 *
	 * @param array $images
	 * @return string[]
	 */
	function bizcity_convert_images_to_media_urls( $images ) {
		if ( empty( $images ) || ! is_array( $images ) ) {
			return array();
		}
		$result = array();
		foreach ( $images as $img ) {
			if ( ! is_string( $img ) ) {
				continue;
			}
			if ( preg_match( '/^https?:\/\//i', $img ) ) {
				$result[] = $img;
				continue;
			}
			if ( 0 === strpos( $img, 'data:image/' ) ) {
				$media = bizcity_save_base64_to_media( $img );
				if ( ! is_wp_error( $media ) ) {
					$result[] = $media['url'];
				}
			}
		}
		return $result;
	}
}
