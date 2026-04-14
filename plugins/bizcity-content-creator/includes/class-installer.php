<?php
/**
 * Database installer — creates 4 tables on activation.
 *
 * Tables:
 *   bizcity_creator_categories
 *   bizcity_creator_templates
 *   bizcity_creator_files
 *   bizcity_creator_chunk_meta
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Installer {

	/* ── Table helpers (per-site with wpdb->prefix) ── */

	public static function table_categories(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_creator_categories';
	}

	public static function table_templates(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_creator_templates';
	}

	public static function table_files(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_creator_files';
	}

	public static function table_chunk_meta(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_creator_chunk_meta';
	}

	/* ── Activation ── */

	public static function activate(): void {
		self::create_tables();
		self::seed_defaults();
	}

	/* ── Self-healing ── */

	public static function maybe_create_tables(): void {
		if ( get_option( 'bzcc_db_version' ) === BZCC_VERSION ) {
			return;
		}
		self::create_tables();
	}

	/* ── dbDelta ── */

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$t_cat   = self::table_categories();
		$t_tpl   = self::table_templates();
		$t_file  = self::table_files();
		$t_chunk = self::table_chunk_meta();

		$sql = "
CREATE TABLE {$t_cat} (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(100) NOT NULL,
  title       VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  icon_url    VARCHAR(500) NOT NULL DEFAULT '',
  icon_emoji  VARCHAR(20) NOT NULL DEFAULT '',
  parent_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  tool_count  INT NOT NULL DEFAULT 0,
  status      VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_parent (parent_id),
  KEY idx_status (status)
) {$charset};

CREATE TABLE {$t_tpl} (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug            VARCHAR(100) NOT NULL,
  category_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  title           VARCHAR(255) NOT NULL DEFAULT '',
  description     TEXT NOT NULL,
  icon_url        VARCHAR(500) NOT NULL DEFAULT '',
  icon_emoji      VARCHAR(20) NOT NULL DEFAULT '',
  form_fields     LONGTEXT NOT NULL,
  system_prompt   LONGTEXT NOT NULL,
  outline_prompt  LONGTEXT NOT NULL,
  chunk_prompt    LONGTEXT NOT NULL,
  model_purpose   VARCHAR(50) NOT NULL DEFAULT 'content_creation',
  temperature     DECIMAL(3,2) NOT NULL DEFAULT 0.70,
  max_tokens      INT NOT NULL DEFAULT 4000,
  wizard_steps    LONGTEXT NOT NULL,
  output_platforms LONGTEXT NOT NULL,
  skill_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tags            VARCHAR(500) NOT NULL DEFAULT '',
  badge_text      VARCHAR(50) NOT NULL DEFAULT '',
  badge_color     VARCHAR(20) NOT NULL DEFAULT '',
  use_count       INT NOT NULL DEFAULT 0,
  is_featured     TINYINT(1) NOT NULL DEFAULT 0,
  sort_order      INT NOT NULL DEFAULT 0,
  status          VARCHAR(20) NOT NULL DEFAULT 'active',
  author_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_category (category_id),
  KEY idx_status (status),
  KEY idx_featured (is_featured)
) {$charset};

CREATE TABLE {$t_file} (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  template_id             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  project_id              VARCHAR(50) NOT NULL DEFAULT '',
  session_id              VARCHAR(100) NOT NULL DEFAULT '',
  intent_conversation_id  VARCHAR(64) NOT NULL DEFAULT '',
  form_data               LONGTEXT NOT NULL,
  outline                 LONGTEXT NOT NULL,
  outline_status          VARCHAR(20) NOT NULL DEFAULT 'pending',
  memory_spec_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  title                   VARCHAR(500) NOT NULL DEFAULT '',
  status                  VARCHAR(20) NOT NULL DEFAULT 'pending',
  chunk_count             INT NOT NULL DEFAULT 0,
  chunk_done              INT NOT NULL DEFAULT 0,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_template (template_id),
  KEY idx_project (project_id),
  KEY idx_intent_conv (intent_conversation_id),
  KEY idx_status (status)
) {$charset};

CREATE TABLE {$t_chunk} (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  studio_output_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  chunk_index      INT NOT NULL DEFAULT 0,
  node_status      VARCHAR(20) NOT NULL DEFAULT 'pending',
  platform         VARCHAR(50) NOT NULL DEFAULT '',
  stage_label      VARCHAR(100) NOT NULL DEFAULT '',
  stage_emoji      VARCHAR(20) NOT NULL DEFAULT '',
  hashtags         TEXT NOT NULL,
  cta_text         VARCHAR(500) NOT NULL DEFAULT '',
  image_url        VARCHAR(500) NOT NULL DEFAULT '',
  image_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  video_url        VARCHAR(500) NOT NULL DEFAULT '',
  video_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  notes            TEXT NOT NULL,
  edit_count       INT NOT NULL DEFAULT 0,
  last_prompt      TEXT NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_file (file_id),
  KEY idx_studio (studio_output_id),
  KEY idx_file_index (file_id, chunk_index),
  KEY idx_node_status (node_status)
) {$charset};
";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$old_suppress = $wpdb->suppress_errors( true );
		if ( isset( $wpdb->gwpdb ) && $wpdb->gwpdb instanceof wpdb ) {
			$wpdb->gwpdb->suppress_errors( true );
		} elseif ( method_exists( $wpdb, 'biz_ensure_gwpdb' ) ) {
			$gw = $wpdb->biz_ensure_gwpdb();
			if ( $gw ) {
				$gw->suppress_errors( true );
			}
		}

		dbDelta( $sql );

		$wpdb->suppress_errors( $old_suppress );
		if ( isset( $wpdb->gwpdb ) && $wpdb->gwpdb instanceof wpdb ) {
			$wpdb->gwpdb->suppress_errors( false );
		}

		update_option( 'bzcc_db_version', BZCC_VERSION );
	}

	/* ── Seed defaults ── */

	public static function seed_defaults(): void {
		global $wpdb;
		$t_cat = self::table_categories();

		// Only seed if table is empty
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_cat}" );
		if ( $count > 0 ) {
			return;
		}

		$now  = current_time( 'mysql', true );
		$cats = [
			[ 'slug' => 'marketing',  'title' => 'Marketing & Quảng cáo', 'icon_emoji' => '📣', 'sort_order' => 1, 'description' => 'Chiến dịch marketing, quảng cáo, bán hàng' ],
			[ 'slug' => 'sales',      'title' => 'Bán hàng & Copywriting', 'icon_emoji' => '🛒', 'sort_order' => 2, 'description' => 'Viết bài bán hàng, mô tả sản phẩm, landing page' ],
			[ 'slug' => 'learning',   'title' => 'Học tập & Phát triển',   'icon_emoji' => '📚', 'sort_order' => 3, 'description' => 'Kế hoạch học tập, ghi chú, tóm tắt' ],
			[ 'slug' => 'legal',      'title' => 'Pháp lý & Văn bản',     'icon_emoji' => '⚖️', 'sort_order' => 4, 'description' => 'Soạn hợp đồng, đơn từ, văn bản pháp lý' ],
		];

		foreach ( $cats as $cat ) {
			$wpdb->insert( $t_cat, array_merge( $cat, [
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			] ) );
		}

		// Seed 2 starter templates
		self::seed_templates( $wpdb );
	}

	/** Seed starter templates. */
	private static function seed_templates( wpdb $wpdb ): void {
		$t_tpl = self::table_templates();
		$t_cat = self::table_categories();
		$now   = current_time( 'mysql', true );

		// Get sales category ID
		$sales_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t_cat} WHERE slug = %s", 'sales'
		) );

		$marketing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t_cat} WHERE slug = %s", 'marketing'
		) );

		$templates = [
			[
				'slug'        => 'copywriting',
				'category_id' => $sales_id ?: 1,
				'title'       => 'Viết bài bán hàng không thể cưỡng lại',
				'description' => 'Tạo bài bán hàng thuyết phục cho Facebook, TikTok, Zalo — AI phân tích USP và viết theo phễu AIDA.',
				'icon_emoji'  => '✍️',
				'tags'        => 'viết bài bán hàng,copywriting,bài quảng cáo,viết content',
				'is_featured' => 1,
				'badge_text'  => '🔥 Phổ biến',
				'badge_color' => '#ff6b35',
				'form_fields' => wp_json_encode( [
					[
						'slug'        => 'product_name',
						'label'       => 'Sản phẩm dịch vụ bạn kinh doanh là gì?',
						'type'        => 'textarea',
						'placeholder' => 'Mô tả càng chi tiết càng tốt — tên sản phẩm, giá, USP',
						'required'    => true,
						'grid'        => 'full',
						'sort_order'  => 1,
					],
					[
						'slug'        => 'extra_info',
						'label'       => 'Thông tin bổ sung (ưu đãi, quà tặng)?',
						'type'        => 'textarea',
						'placeholder' => 'Ví dụ: Giảm 30%, miễn phí ship, tặng kèm...',
						'required'    => false,
						'grid'        => 'full',
						'sort_order'  => 2,
					],
					[
						'slug'        => 'tone',
						'label'       => 'Giọng điệu',
						'type'        => 'select',
						'options'     => [
							[ 'value' => 'convince',  'label' => 'Thuyết phục' ],
							[ 'value' => 'humorous',  'label' => 'Hài hước' ],
							[ 'value' => 'inspire',   'label' => 'Truyền cảm hứng' ],
							[ 'value' => 'warm',      'label' => 'Ấm áp' ],
						],
						'required'    => false,
						'grid'        => 'half',
						'sort_order'  => 3,
					],
					[
						'slug'        => 'language',
						'label'       => 'Ngôn ngữ',
						'type'        => 'select',
						'options'     => [
							[ 'value' => 'vi', 'label' => 'Tiếng Việt' ],
							[ 'value' => 'en', 'label' => 'English' ],
						],
						'required'    => false,
						'grid'        => 'half',
						'sort_order'  => 4,
					],
				] ),
				'system_prompt'  => "Bạn là chuyên gia copywriting với 10 năm kinh nghiệm viết bài bán hàng.\nPhong cách: {{tone}}. Ngôn ngữ: {{language}}.\nSản phẩm: {{product_name}}\nThông tin thêm: {{extra_info}}\n\nViết nội dung theo phễu AIDA (Attention → Interest → Desire → Action).",
				'outline_prompt' => "Hãy tạo outline cho chiến dịch bán sản phẩm này.\nTrả về JSON với format:\n{\"title\": \"...\", \"summary\": \"...\", \"sections\": [{\"index\": 0, \"title\": \"...\", \"platform\": \"facebook|tiktok|zalo\", \"stage\": \"awareness|interest|action\", \"stage_label\": \"Nhận biết|Quan tâm|Hành động\", \"stage_emoji\": \"👁️|💡|🎯\", \"instructions\": \"...\", \"content_type\": \"image_post|video_script|text_message\", \"word_count\": 200}], \"metadata\": {\"total_sections\": N, \"platforms\": [...], \"funnel_stages\": [...]}}",
				'chunk_prompt'   => "Viết nội dung cho phần: {{chunk_title}}\nNền tảng: {{platform}}\nGiai đoạn phễu: {{stage}}\nHướng dẫn: {{outline_item}}\n\nViết khoảng {{word_count}} từ. Format markdown. Kết thúc bằng CTA phù hợp.",
			],
			[
				'slug'        => 'marketing_campaign',
				'category_id' => $marketing_id ?: 1,
				'title'       => 'Chiến dịch Marketing đa nền tảng',
				'description' => 'Lập kế hoạch và tạo nội dung chiến dịch marketing toàn diện cho Facebook, TikTok, Zalo.',
				'icon_emoji'  => '🚀',
				'tags'        => 'chiến dịch marketing,campaign,kế hoạch marketing,lập chiến dịch',
				'is_featured' => 1,
				'badge_text'  => '✨ Mới',
				'badge_color' => '#6366f1',
				'form_fields' => wp_json_encode( [
					[
						'slug'        => 'product_name',
						'label'       => 'Sản phẩm / Dịch vụ',
						'type'        => 'textarea',
						'placeholder' => 'Mô tả sản phẩm, giá, target audience',
						'required'    => true,
						'grid'        => 'full',
						'sort_order'  => 1,
					],
					[
						'slug'        => 'goal',
						'label'       => 'Mục tiêu chiến dịch',
						'type'        => 'select',
						'options'     => [
							[ 'value' => 'awareness',  'label' => 'Tăng nhận biết thương hiệu' ],
							[ 'value' => 'leads',      'label' => 'Thu thập leads' ],
							[ 'value' => 'sales',      'label' => 'Tăng doanh số' ],
							[ 'value' => 'engagement', 'label' => 'Tăng tương tác' ],
						],
						'required'    => true,
						'grid'        => 'half',
						'sort_order'  => 2,
					],
					[
						'slug'        => 'platforms',
						'label'       => 'Nền tảng',
						'type'        => 'checkbox',
						'options'     => [
							[ 'value' => 'facebook', 'label' => 'Facebook' ],
							[ 'value' => 'tiktok',   'label' => 'TikTok' ],
							[ 'value' => 'zalo',     'label' => 'Zalo OA' ],
							[ 'value' => 'website',  'label' => 'Website/Blog' ],
						],
						'required'    => true,
						'grid'        => 'half',
						'sort_order'  => 3,
					],
					[
						'slug'        => 'duration',
						'label'       => 'Thời gian chiến dịch',
						'type'        => 'select',
						'options'     => [
							[ 'value' => '1week',  'label' => '1 tuần' ],
							[ 'value' => '2weeks', 'label' => '2 tuần' ],
							[ 'value' => '1month', 'label' => '1 tháng' ],
						],
						'required'    => false,
						'grid'        => 'half',
						'sort_order'  => 4,
					],
					[
						'slug'        => 'tone',
						'label'       => 'Giọng điệu',
						'type'        => 'select',
						'options'     => [
							[ 'value' => 'professional', 'label' => 'Chuyên nghiệp' ],
							[ 'value' => 'friendly',     'label' => 'Thân thiện' ],
							[ 'value' => 'humorous',     'label' => 'Hài hước' ],
							[ 'value' => 'inspire',      'label' => 'Truyền cảm hứng' ],
						],
						'required'    => false,
						'grid'        => 'half',
						'sort_order'  => 5,
					],
				] ),
				'system_prompt'  => "Bạn là chuyên gia digital marketing.\nSản phẩm: {{product_name}}\nMục tiêu: {{goal}}\nNền tảng: {{platforms}}\nThời gian: {{duration}}\nGiọng điệu: {{tone}}\n\nLập kế hoạch chiến dịch marketing đa nền tảng.",
				'outline_prompt' => "Hãy tạo outline chiến dịch marketing cho sản phẩm này.\nMục tiêu: {{goal}}\nNền tảng: {{platforms}}\nTrả về JSON outline format chuẩn (xem system prompt).",
				'chunk_prompt'   => "Viết nội dung cho: {{chunk_title}}\nNền tảng: {{platform}}\nGiai đoạn: {{stage}}\nHướng dẫn: {{outline_item}}\n\nFormat markdown, khoảng {{word_count}} từ.",
			],
		];

		foreach ( $templates as $tpl ) {
			$wpdb->insert( $t_tpl, array_merge( $tpl, [
				'status'           => 'active',
				'wizard_steps'     => '[]',
				'output_platforms' => '["facebook","tiktok","zalo"]',
				'model_purpose'    => 'content_creation',
				'temperature'      => 0.70,
				'max_tokens'       => 4000,
				'author_id'        => get_current_user_id(),
				'created_at'       => $now,
				'updated_at'       => $now,
			] ) );
		}

		// Update tool_count on categories
		$wpdb->query( "UPDATE {$t_cat} c SET c.tool_count = (SELECT COUNT(*) FROM {$t_tpl} t WHERE t.category_id = c.id AND t.status = 'active')" );
	}
}
