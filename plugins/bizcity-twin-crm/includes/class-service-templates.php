<?php
/**
 * BizCity CRM — Service Template Registry (PHASE-0.35-GURU-SERVICES §G.4)
 *
 * Each Twin Guru on Duty plays a **role** (external/internal) and a
 * **service template** (customer_service, telesale, page_inbox, comment_reply,
 * seeding, …). The template defines:
 *
 *   - role_scope          : 'external' | 'internal' | 'both'
 *   - persona_prefix      : prepended to character.system_prompt
 *   - style_guide         : tone/length rules ("ngắn gọn 2-3 câu", "có emoji", …)
 *   - max_chars_target    : aim for this length when composing reply
 *   - max_tokens_hint     : LLM ceiling so reply doesn't drift long
 *   - per_chunk_max_chars : channel send chunk size (overrides default)
 *   - allowed_channels    : whitelist of channel_type when role_scope='external'
 *
 * Filterable: `bizcity_crm_service_templates` to add/override templates.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Service_Templates {

	const META_KEY_ROLE           = 'crm_role';            // external|internal|both
	const META_KEY_TEMPLATE       = 'crm_template';        // slug (preset starting point)
	const META_KEY_CUSTOM_PERSONA = 'crm_custom_persona';  // free-text overlay (overrides persona_prefix)
	const META_KEY_CUSTOM_STYLE   = 'crm_custom_style';    // free-text overlay (overrides style_guide)

	/**
	 * Marketplace product slug prefix used to gate premium templates via
	 * BizCity_Market_Entitlements::has(). A template entry can declare
	 * `premium => true` and `product_slug => 'crm-template-xxx'` — when
	 * present and the current blog lacks entitlement, the template is
	 * filtered out for non-admin contexts.
	 */
	const PRODUCT_TYPE = 'crm_template';

	/** @return array<string,array> */
	public static function all(): array {
		$base = array(
			'customer_service' => array(
				'label'              => 'Chăm sóc khách hàng (Customer Service)',
				'role_scope'         => 'external',
				'persona_prefix'     => "Bạn là nhân viên Chăm sóc Khách hàng chuyên nghiệp. " .
				                        "Thái độ niềm nở, thân thiện, kiên nhẫn. " .
				                        "Mục tiêu: làm khách thấy được lắng nghe, giải đáp đúng vấn đề, đề xuất bước tiếp theo cụ thể.",
				'style_guide'        => "- Xưng 'em', gọi khách 'anh/chị'.\n- Chào hỏi ngắn rồi đi thẳng vào câu trả lời.\n- Trả lời 4-8 câu, có cấu trúc rõ.\n- Khi cần chốt, hỏi 1 câu mở để khách chọn (vd: 'anh/chị muốn em hỗ trợ thêm phần nào?').",
				'max_chars_target'   => 800,
				'max_tokens_hint'    => 400,
				'per_chunk_max_chars'=> 1800,
				'allowed_channels'   => array( 'facebook', 'zalo', 'telegram', 'web' ),
			),
			'telesale' => array(
				'label'              => 'Telesale / Tư vấn bán hàng',
				'role_scope'         => 'external',
				'persona_prefix'     => "Bạn là nhân viên tư vấn bán hàng giỏi. " .
				                        "Lắng nghe nhu cầu khách, gợi ý sản phẩm phù hợp, dẫn khách tới quyết định mua. " .
				                        "Không pushy. Tin tưởng vào sản phẩm/dịch vụ.",
				'style_guide'        => "- Xưng 'em', gọi khách 'anh/chị'.\n- 1 câu xác nhận nhu cầu + 2-3 ý lợi ích cụ thể (giá, ưu đãi, kết quả) + 1 câu kêu gọi hành động (CTA) rõ ràng.\n- Tổng ≤ 6 câu. Có thể dùng 1 emoji 🎯/✨ nếu phù hợp.\n- Luôn kết bằng câu hỏi: 'Anh/chị có muốn em gửi báo giá / đặt lịch / chốt đơn không ạ?'",
				'max_chars_target'   => 600,
				'max_tokens_hint'    => 300,
				'per_chunk_max_chars'=> 1800,
				'allowed_channels'   => array( 'facebook', 'zalo', 'telegram' ),
			),
			'page_inbox' => array(
				'label'              => 'Trực Page (FB Inbox / Zalo OA)',
				'role_scope'         => 'external',
				'persona_prefix'     => "Bạn là người trực tin nhắn fanpage. Trả lời nhanh, đúng trọng tâm, giữ giọng thương hiệu.",
				'style_guide'        => "- 2-4 câu, ngắn gọn.\n- Trả lời thẳng câu hỏi → đề xuất bước kế.\n- Không liệt kê dài. Không markdown nặng.",
				'max_chars_target'   => 500,
				'max_tokens_hint'    => 250,
				'per_chunk_max_chars'=> 1800,
				'allowed_channels'   => array( 'facebook', 'zalo' ),
			),
			'comment_reply' => array(
				'label'              => 'Trả lời comment Facebook',
				'role_scope'         => 'external',
				'persona_prefix'     => "Bạn trả lời comment trên bài đăng Facebook. " .
				                        "Cực ngắn, thân thiện, mời khách inbox khi cần thông tin chi tiết.",
				'style_guide'        => "- Tối đa 1-2 câu.\n- Có thể dùng 1 emoji.\n- Mẫu: 'Cảm ơn anh/chị nhé ❤. Mình inbox riêng để em gửi chi tiết nha.'\n- KHÔNG dán giá/policy trên comment công khai.",
				'max_chars_target'   => 180,
				'max_tokens_hint'    => 80,
				'per_chunk_max_chars'=> 500,
				'allowed_channels'   => array( 'facebook' ),
			),
			'seeding' => array(
				'label'              => 'Seeding (comment mồi, review)',
				'role_scope'         => 'external',
				'persona_prefix'     => "Bạn viết comment seeding tự nhiên như khách hàng thật chia sẻ trải nghiệm. " .
				                        "Không sales-y, không khoa trương.",
				'style_guide'        => "- 1-3 câu, giọng khách hàng (xưng 'mình').\n- 1 chi tiết cụ thể (vd: 'mình mua tuần trước, giao 2 ngày').\n- Hấp dẫn, gợi tò mò.\n- KHÔNG xưng 'shop', 'em', 'bên mình'.",
				'max_chars_target'   => 220,
				'max_tokens_hint'    => 100,
				'per_chunk_max_chars'=> 500,
				'allowed_channels'   => array( 'facebook' ),
			),
			'internal_assistant' => array(
				'label'              => 'Trợ lý nội bộ (Internal)',
				'role_scope'         => 'internal',
				'persona_prefix'     => "Bạn là trợ lý nội bộ cho nhân viên công ty. " .
				                        "Trả lời chi tiết, trích dẫn nguồn rõ ràng, không cần lịch sự khách hàng.",
				'style_guide'        => "- Trả lời đầy đủ, có cấu trúc, dùng markdown.\n- Trích nguồn [src:S#p#] rõ ràng.\n- Có thể dài nếu cần thiết.",
				'max_chars_target'   => 2000,
				'max_tokens_hint'    => 1500,
				'per_chunk_max_chars'=> 3500,
				'allowed_channels'   => array( 'web', 'twinchat', 'crm' ),
			),
			'none' => array(
				'label'              => '— Không áp template (raw character prompt) —',
				'role_scope'         => 'both',
				'persona_prefix'     => '',
				'style_guide'        => '',
				'max_chars_target'   => 0,
				'max_tokens_hint'    => 0,
				'per_chunk_max_chars'=> 0,
				'allowed_channels'   => array(),
			),
		);
		return (array) apply_filters( 'bizcity_crm_service_templates', $base );
	}

	/**
	 * Templates that the current blog is allowed to use. Premium templates
	 * (declared with `premium => true` + optional `product_slug`) are
	 * filtered out unless the marketplace entitlement is active.
	 */
	public static function entitled(): array {
		$all      = self::all();
		$blog_id  = get_current_blog_id();
		$has_market = class_exists( 'BizCity_Market_Entitlements' );
		$out = array();
		foreach ( $all as $slug => $tpl ) {
			$is_premium  = ! empty( $tpl['premium'] );
			$product_key = (string) ( $tpl['product_slug'] ?? ( 'crm-template-' . $slug ) );
			$entitled    = true;
			if ( $is_premium ) {
				$entitled = $has_market
					? BizCity_Market_Entitlements::has( $blog_id, $product_key, self::PRODUCT_TYPE )
					: false;
				$entitled = (bool) apply_filters( 'bizcity_crm_template_entitled', $entitled, $slug, $tpl, $blog_id );
			}
			$tpl['_premium']  = $is_premium;
			$tpl['_entitled'] = $entitled;
			$out[ $slug ]     = $tpl;
		}
		return $out;
	}

	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Resolve the active template for a character. Reads `character.settings`
	 * JSON for `crm_template` slug, falls back to 'customer_service' for
	 * external-channel characters with no explicit template, or 'none' otherwise.
	 *
	 * @param int    $character_id
	 * @param string $channel_type  CRM inbox channel_type (facebook|zalo|web|crm…)
	 * @return array {slug, template, source, char_role}
	 */
	public static function resolve_for_character( int $character_id, string $channel_type = '' ): array {
		$out = array(
			'slug'      => 'none',
			'template'  => self::get( 'none' ),
			'source'    => 'default',
			'char_role' => 'both',
		);
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return $out;
		}
		$db   = BizCity_Knowledge_Database::instance();
		$char = $db->get_character( $character_id );
		if ( ! $char ) { return $out; }

		$settings = isset( $char->settings ) && $char->settings
			? ( is_array( $char->settings ) ? $char->settings : ( json_decode( (string) $char->settings, true ) ?: array() ) )
			: array();

		$out['char_role'] = (string) ( $settings[ self::META_KEY_ROLE ] ?? 'both' );
		$slug             = (string) ( $settings[ self::META_KEY_TEMPLATE ] ?? '' );

		if ( $slug && self::get( $slug ) ) {
			$tpl_full = self::get( $slug );
			// Premium gating: if template is premium and not entitled, downgrade.
			if ( ! empty( $tpl_full['premium'] ) ) {
				$entitled_map = self::entitled();
				if ( empty( $entitled_map[ $slug ]['_entitled'] ) ) {
					$out['slug']           = 'none';
					$out['template']       = self::apply_overrides( self::get( 'none' ), $settings );
					$out['source']         = 'premium_locked';
					$out['locked_premium'] = $slug;
					return $out;
				}
			}
			$out['slug']     = $slug;
			$out['template'] = self::apply_overrides( $tpl_full, $settings );
			$out['source']   = 'character_settings';
			return $out;
		}

		// [2026-07-06 Johnny Chu] PHASE-0.39 GURU-BIND HOTFIX — normalize Zone-1 aliases so
		// channel_type="zalo_oa" resolves to the same external defaults as "zalo".
		$channel_norm = strtolower( (string) $channel_type );
		if ( in_array( $channel_norm, array( 'zalo_oa', 'zalo_personal' ), true ) ) {
			$channel_norm = 'zalo';
		}

		// Channel-aware default when nothing set.
		$is_external = in_array( $channel_norm, array( 'facebook', 'zalo', 'telegram' ), true );
		if ( $is_external ) {
			$out['slug']     = 'page_inbox';
			$out['template'] = self::apply_overrides( self::get( 'page_inbox' ), $settings );
			$out['source']   = 'channel_default';
		} else {
			$out['template'] = self::apply_overrides( $out['template'], $settings );
		}
		return $out;
	}

	/**
	 * Apply per-character free-text overrides (custom persona / style) on top of
	 * a preset template. Empty overrides leave the preset untouched.
	 */
	private static function apply_overrides( ?array $template, array $settings ): ?array {
		if ( ! is_array( $template ) ) { return $template; }
		$persona = trim( (string) ( $settings[ self::META_KEY_CUSTOM_PERSONA ] ?? '' ) );
		$style   = trim( (string) ( $settings[ self::META_KEY_CUSTOM_STYLE ]   ?? '' ) );
		if ( $persona !== '' ) { $template['persona_prefix'] = $persona; $template['_overridden_persona'] = true; }
		if ( $style   !== '' ) { $template['style_guide']    = $style;   $template['_overridden_style']   = true; }
		return $template;
	}

	/**
	 * Build augmented system prompt prefix from template + base prompt.
	 *
	 * @return string  '' if template is 'none' or no persona_prefix.
	 */
	public static function build_persona_prefix( array $template ): string {
		$lines = array();
		if ( ! empty( $template['persona_prefix'] ) ) {
			$lines[] = '【Vai trò】 ' . trim( (string) $template['persona_prefix'] );
		}
		if ( ! empty( $template['style_guide'] ) ) {
			$lines[] = '【Phong cách trả lời】';
			$lines[] = trim( (string) $template['style_guide'] );
		}
		if ( ! empty( $template['max_chars_target'] ) ) {
			$lines[] = '【Độ dài】 Mục tiêu khoảng ' . (int) $template['max_chars_target'] . ' ký tự. Không vượt quá nhiều.';
		}
		return $lines ? implode( "\n", $lines ) . "\n\n" : '';
	}

	/**
	 * Persist role+template selection on a character. Optional 4th arg lets
	 * callers also overwrite the free-text persona/style overlays in one go.
	 *
	 * @param array $extras  Optional. Keys: 'custom_persona', 'custom_style'.
	 */
	public static function save_for_character( int $character_id, string $role, string $template_slug, array $extras = array() ): bool {
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) ) { return false; }
		$db   = BizCity_Knowledge_Database::instance();
		$char = $db->get_character( $character_id );
		if ( ! $char ) { return false; }

		$settings = isset( $char->settings ) && $char->settings
			? ( is_array( $char->settings ) ? $char->settings : ( json_decode( (string) $char->settings, true ) ?: array() ) )
			: array();

		$role          = in_array( $role, array( 'external', 'internal', 'both' ), true ) ? $role : 'both';
		$template_slug = self::get( $template_slug ) ? $template_slug : 'none';

		$settings[ self::META_KEY_ROLE ]     = $role;
		$settings[ self::META_KEY_TEMPLATE ] = $template_slug;

		if ( array_key_exists( 'custom_persona', $extras ) ) {
			$settings[ self::META_KEY_CUSTOM_PERSONA ] = trim( (string) $extras['custom_persona'] );
		}
		if ( array_key_exists( 'custom_style', $extras ) ) {
			$settings[ self::META_KEY_CUSTOM_STYLE ] = trim( (string) $extras['custom_style'] );
		}

		$res = $db->update_character( $character_id, array( 'settings' => $settings ) );
		return $res === true;
	}
}
