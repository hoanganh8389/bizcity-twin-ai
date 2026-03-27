<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
abstract class WaicAction extends WaicBuilderBlock {

	public function __construct( $code ) {
		$this->_type = 'action';
	}
	
	public function controlText( $str ) {
		if ( !$str ) {
			return '';
		}
		$str = str_replace(array('```html', '```'), array('', ''), $str);
		return str_replace(array("'"), array('`'), $str);
		//return preg_replace('/\r\n|\r|\n/', '<br>', str_replace(array("'"), array('`'), $str));
	}
	public function saveImage( $imageUrl, $title = '' ) {
		global $wpdb;
		$result = array('status' => 'error', 'msg' => esc_html__('Can not save image to media', 'ai-copilot-content-generator'));
		if (!function_exists('wp_generate_attachment_metadata')) {
			include_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if (!function_exists('download_url')) {
			include_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if (!function_exists('media_handle_sideload')) {
			include_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$attId = 0;

		$array = explode('/', getimagesize($imageUrl)['mime']);
		$imageType = end($array);
		$uniqName = md5($imageUrl);
		$fileName = $uniqName . '.' . $imageType;
		$checkExist = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", '%/' . $wpdb->esc_like($fileName)));
		if ($checkExist) {
			$attId = $checkExist->post_id;
		} else {
			if (file_exists($imageUrl)) {
				$tmp = $imageUrl;
			} else {
				$tmp = download_url($imageUrl);
			}
			if (is_wp_error($tmp)) {
				WaicFrame::_()->pushError($tmp->get_error_message());
				return false;
			}
			$args = array(
				'name' => $fileName,
				'tmp_name' => $tmp,
			);
			$userId = get_current_user_id();
			if (empty($userId) || is_null($userId)) {
				$admins = get_users(array('role' => 'administrator'));
				if (!empty($admins)) {
					$userId = $admins[0]->ID;
				}
			}
			$attId = media_handle_sideload($args, 0, '', array(
				'post_title' => $title,
				'post_content' => $title,
				'post_excerpt' => $title,
				'post_author' => $userId,
			));
			if (!is_wp_error($attId)) {
				update_post_meta($attId, '_wp_attachment_image_alt', $title);
				$imageNew = get_post( $attId );
				$fullSizePath = get_attached_file($imageNew->ID);
				$attData = wp_generate_attachment_metadata($attId, $fullSizePath);
				wp_update_attachment_metadata($attId, $attData);
			} else {
				WaicFrame::_()->pushError($attId->get_error_message());
				return false;
			}
		}
		return $attId;
	}
	public function addPostImage( $postId, $attId, $alt = '' ) {
		$needUpdate = true;
		if (empty($attId)) {
			$attId = get_post_thumbnail_id($postId);
			$needUpdate = false;
			if (empty($attId)) { 
				return '';
			}
		}
		if (!empty($alt)) {
			$attUpd = wp_update_post(
				array(
					'ID' => $attId,
					'post_title' => $alt,
					'post_content' => $alt,
					'post_excerpt' => $alt,
				)
			);
			if (is_wp_error($attUpd)) {
				return $attUpd->get_error_message();
			}
			update_post_meta($attId, '_wp_attachment_image_alt', $alt);
		}
		if ($needUpdate) {
			update_post_meta($postId, '_thumbnail_id', $attId);
		}
		return '';
	}
	
	
}