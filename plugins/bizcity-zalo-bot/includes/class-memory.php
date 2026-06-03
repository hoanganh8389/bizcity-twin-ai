<?php
/**
 * BizCity - Zalo Bot Memory Builder (LLM-powered)
 * - Read wp_bizcity_zalo_bot_logs
 * - Extract key memories using OpenAI GPT-4o-nano
 * - Upsert to wp_bizcity_zalo_bot_memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_Memory {
	
	private static $instance = null;
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Get memory table name
	 */
	public static function memory_table() {
		global $wpdb;
		return $wpdb->base_prefix . 'bizcity_zalo_bot_memory';
	}
	
	/**
	 * Get logs table name
	 */
	public static function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_zalo_bot_logs';
	}
	
	/**
	 * Build memories from logs using LLM
	 */
	public static function build_from_logs( $args = array() ) {
		global $wpdb;
		
		$args = wp_parse_args( $args, array(
			'bot_id'    => 0,
			'client_id' => '',
			'user_id'   => '',
			'limit'     => 200,
			'since_id'  => 0,
			'blog_id'   => get_current_blog_id(),
		) );
		
		$table_logs = self::logs_table();
		$table_memory = self::memory_table();
		
// 1) Fetch logs (include both user messages AND bot replies for full conversation context)
		$where = "WHERE (event_name='message.text.received' OR event_name='bot.reply') AND text != ''";
		$params = array();
		
		if ( (int) $args['bot_id'] > 0 ) {
			$where .= " AND bot_id=%d";
			$params[] = (int) $args['bot_id'];
		}
		
		if ( ! empty( $args['client_id'] ) ) {
			$where .= " AND client_id=%s";
			$params[] = $args['client_id'];
		}
		
		if ( ! empty( $args['user_id'] ) ) {
			$where .= " AND user_id=%s";
			$params[] = $args['user_id'];
		}
		
		if ( (int) $args['since_id'] > 0 ) {
			$where .= " AND id>%d";
			$params[] = (int) $args['since_id'];
		}
		
		$sql = "SELECT id, bot_id, client_id, user_id, text, display_name, created_at
		        FROM {$table_logs}
		        {$where}
		        ORDER BY id DESC
		        LIMIT " . (int) $args['limit'];
		
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		
		if ( ! $rows ) {
			return array( 'ok' => true, 'count' => 0, 'inserted' => 0, 'updated' => 0 );
		}
		
		$inserted = 0;
		$updated = 0;
		
		// Group messages by user for batch processing
		$user_messages = array();
		foreach ( $rows as $r ) {
				// Group by client_id — bot replies belong to the same user's conversation
				$user_key = $r['client_id'] ?: $r['user_id'];
				if ( ! isset( $user_messages[ $user_key ] ) ) {
					$user_messages[ $user_key ] = array(
						'bot_id' => $r['bot_id'],
						'client_id' => $r['client_id'],
						'user_id' => $r['user_id'],
						'display_name' => $r['display_name'],
						'messages' => array(),
					);
				} elseif ( empty( $user_messages[ $user_key ]['display_name'] ) && ! empty( $r['display_name'] ) && $r['event_name'] !== 'bot.reply' ) {
					$user_messages[ $user_key ]['display_name'] = $r['display_name'];
				}
				// Tag role for LLM context building
				$r['role'] = ( $r['event_name'] === 'bot.reply' ) ? 'assistant' : 'user';
		}
		
		// Process each user's messages
		foreach ( $user_messages as $user_data ) {
			// Extract memories using LLM
			$memories = self::extract_memories_llm( $user_data['messages'] );
			
			foreach ( $memories as $mem ) {
				$mem['blog_id'] = (int) $args['blog_id'];
				$mem['bot_id'] = (int) $user_data['bot_id'];
				$mem['client_id'] = (string) $user_data['client_id'];
				$mem['user_id'] = (string) $user_data['user_id'];
				
				// Find source log IDs
				$source_ids = array();
				foreach ( $user_data['messages'] as $msg ) {
					$source_ids[] = $msg['id'];
				}
				$mem['source_log_ids'] = implode( ',', $source_ids );
				$mem['last_seen'] = current_time( 'mysql' );
				
				$res = self::upsert_memory( $table_memory, $mem );
				if ( $res === 'insert' ) {
					$inserted++;
				}
				if ( $res === 'update' ) {
					$updated++;
				}
			}
		}
		
		return array(
			'ok' => true,
			'count' => count( $rows ),
			'inserted' => $inserted,
			'updated' => $updated,
		);
	}
	
	/**
	 * Extract memories from messages using BizCity LLM Gateway (R-1API canonical).
	 *
	 * @since 2026-06-02 Refactored to route via BizCity_LLM_Client (was direct OpenAI POST).
	 */
	private static function extract_memories_llm( $messages ) {
		// R-1API: route via canonical BizCity LLM Gateway. KHÔNG đọc twf_openai_api_key.
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			error_log( '[BizCity Zalo Bot Memory] BizCity_LLM_Client missing — skip extraction.' );
			return array();
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! method_exists( $llm, 'is_ready' ) || ! $llm->is_ready() ) {
			error_log( '[BizCity Zalo Bot Memory] BizCity API key not configured — skip extraction.' );
			return array();
		}

		// Build conversation context
		$conversation = array();
		foreach ( $messages as $msg ) {
			$conversation[] = array(
				'role' => 'user',
				'content' => $msg['text'],
				'timestamp' => $msg['created_at'],
			);
		}

		// Prepare LLM prompt
		$prompt = self::build_extraction_prompt( $conversation );

		$result = $llm->chat(
			array(
				array( 'role' => 'system', 'content' => $prompt['system'] ),
				array( 'role' => 'user',   'content' => $prompt['user'] ),
			),
			array(
				'purpose'     => 'fast',
				'temperature' => 0.3,
				'max_tokens'  => 1000,
			)
		);

		if ( is_wp_error( $result ) ) {
			error_log( '[BizCity Zalo Bot Memory] Gateway error: ' . $result->get_error_message() );
			return array();
		}
		if ( is_array( $result ) && empty( $result['success'] ) ) {
			error_log( '[BizCity Zalo Bot Memory] Gateway returned failure: ' . wp_json_encode( $result ) );
			return array();
		}

		// BizCity_LLM_Client::chat() returns array with 'content' key on success.
		$llm_output = '';
		if ( is_array( $result ) ) {
			if ( isset( $result['content'] ) ) {
				$llm_output = (string) $result['content'];
			} elseif ( isset( $result['choices'][0]['message']['content'] ) ) {
				$llm_output = (string) $result['choices'][0]['message']['content'];
			} elseif ( isset( $result['data']['content'] ) ) {
				$llm_output = (string) $result['data']['content'];
			}
		}

		if ( $llm_output === '' ) {
			error_log( '[BizCity Zalo Bot Memory] Empty LLM response: ' . wp_json_encode( $result ) );
			return array();
		}

		return self::parse_llm_output( $llm_output );
	}
	
	/**
	 * Build extraction prompt for LLM
	 */
	private static function build_extraction_prompt( $conversation ) {
		$messages_text = '';
		foreach ( $conversation as $msg ) {
			$messages_text .= "- " . $msg['content'] . "\n";
		}
		
		$system = "Bạn là trợ lý AI chuyên phân tích tâm lý người dùng. Nhiệm vụ của bạn là trích xuất các \"ký ức\" (memories) quan trọng từ đoạn hội thoại của người dùng.

Các loại ký ức cần trích xuất:
1. **identity** - Thông tin cá nhân: tên, tuổi, nghề nghiệp, sở thích cá nhân
2. **preference** - Sở thích/Không thích: thích gì, ghét gì, ưu tiên gì
3. **goal** - Mục tiêu: muốn đạt được điều gì, kế hoạch tương lai
4. **pain** - Vấn đề/Nỗi đau: stress, lo âu, vấn đề đang gặp phải
5. **constraint** - Giới hạn của chủ nhân: thiếu thời gian, thiếu tiền, dị ứng, không thể làm gì, ràng buộc về tài chính/địa lý/sức khỏe
6. **habit** - Thói quen: làm gì thường xuyên, pattern hành vi
7. **relationship** - Quan hệ: gia đình, bạn bè, đồng nghiệp
8. **fact** - Sự kiện/Thông tin: các thông tin khác có thể hữu ích

Yêu cầu output:
- Format JSON array với các object: {\"type\": \"...\", \"key\": \"...\", \"text\": \"...\", \"score\": 0-100}
- \"key\": slug ngắn gọn (VD: \"likes:milk_tea\", \"pain:stress\", \"goal:save_money\")
- \"text\": Câu mô tả chuẩn hóa bằng tiếng Việt
- \"score\": Độ quan trọng (0-100), càng quan trọng/rõ ràng càng cao

Chỉ trích xuất những thông tin có giá trị, bỏ qua lời chào hỏi thông thường.";
		
		$user = "Đây là các tin nhắn của người dùng:\n\n{$messages_text}\n\nHãy trích xuất các memories quan trọng dưới dạng JSON array.";
		
		return array(
			'system' => $system,
			'user' => $user,
		);
	}
	
	/**
	 * Parse LLM output to structured memories
	 */
	private static function parse_llm_output( $output ) {
		// Try to extract JSON from output
		if ( preg_match( '/\[.*\]/s', $output, $matches ) ) {
			$json = $matches[0];
			$memories = json_decode( $json, true );
			
			if ( is_array( $memories ) ) {
				$result = array();
				foreach ( $memories as $mem ) {
					if ( isset( $mem['type'], $mem['key'], $mem['text'], $mem['score'] ) ) {
						$result[] = array(
							'memory_type' => sanitize_text_field( $mem['type'] ),
							'memory_key' => sanitize_text_field( $mem['key'] ),
							'memory_text' => sanitize_textarea_field( $mem['text'] ),
							'score' => min( 100, max( 0, (int) $mem['score'] ) ),
						);
					}
				}
				return $result;
			}
		}
		
		// Fallback: parse line by line if JSON fails
		error_log( '[BizCity Zalo Bot Memory] Failed to parse JSON, trying line-by-line parsing' );
		return array();
	}
	
	/**
	 * Upsert memory to database
	 */
	private static function upsert_memory( $table, $mem ) {
		global $wpdb;
		
		$now = current_time( 'mysql' );
		
		// Try find existing by (blog_id, bot_id, client_id, user_id, memory_key)
		$exists_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table}
			 WHERE blog_id=%d AND bot_id=%d AND client_id=%s AND user_id=%s AND memory_key=%s
			 LIMIT 1",
			(int) $mem['blog_id'],
			(int) $mem['bot_id'],
			(string) $mem['client_id'],
			(string) $mem['user_id'],
			(string) $mem['memory_key']
		) );
		
		if ( $exists_id > 0 ) {
			// Update: increase score + times_seen, concat source_log_ids, last_seen
			$score_increment = max( 1, (int) ( $mem['score'] / 5 ) );
			
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				 SET score = LEAST(100, score + %d),
				     times_seen = times_seen + 1,
				     last_seen = %s,
				     source_log_ids = CONCAT_WS(',', source_log_ids, %s),
				     updated_at = %s
				 WHERE id=%d",
				$score_increment,
				(string) $mem['last_seen'],
				(string) $mem['source_log_ids'],
				$now,
				$exists_id
			) );
			
			return 'update';
		}
		
		// Insert new memory
		$wpdb->insert( $table, array(
			'blog_id' => (int) $mem['blog_id'],
			'bot_id' => (int) $mem['bot_id'],
			'client_id' => (string) $mem['client_id'],
			'user_id' => (string) $mem['user_id'],
			'memory_type' => (string) $mem['memory_type'],
			'memory_key' => (string) $mem['memory_key'],
			'memory_text' => (string) $mem['memory_text'],
			'score' => (int) $mem['score'],
			'times_seen' => 1,
			'last_seen' => (string) $mem['last_seen'],
			'source_log_ids' => (string) $mem['source_log_ids'],
			'created_at' => $now,
			'updated_at' => $now,
		) );
		
		return 'insert';
	}
	
	/**
	 * Get memories for a user
	 */
	public static function get_memories( $args = array() ) {
		global $wpdb;
		
		$args = wp_parse_args( $args, array(
			'blog_id' => get_current_blog_id(),
			'bot_id' => 0,
			'client_id' => '',
			'user_id' => '',
			'memory_type' => '',
			'limit' => 100,
			'order_by' => 'score',
		) );
		
		$table = self::memory_table();
		
		$where = array( 'blog_id = %d' );
		$params = array( (int) $args['blog_id'] );
		
		if ( (int) $args['bot_id'] > 0 ) {
			$where[] = 'bot_id = %d';
			$params[] = (int) $args['bot_id'];
		}
		
		if ( ! empty( $args['client_id'] ) ) {
			$where[] = 'client_id = %s';
			$params[] = $args['client_id'];
		}
		
		if ( ! empty( $args['user_id'] ) ) {
			$where[] = 'user_id = %s';
			$params[] = $args['user_id'];
		}
		
		if ( ! empty( $args['memory_type'] ) ) {
			$where[] = 'memory_type = %s';
			$params[] = $args['memory_type'];
		}
		
		$where_sql = implode( ' AND ', $where );
		
		$order_by = in_array( $args['order_by'], array( 'score', 'times_seen', 'created_at', 'updated_at' ) ) ? $args['order_by'] : 'score';
		
		$sql = "SELECT * FROM {$table}
		        WHERE {$where_sql}
		        ORDER BY {$order_by} DESC, id DESC
		        LIMIT %d";
		
		$params[] = (int) $args['limit'];
		
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
	}
	
	/**
	 * Get memory statistics
	 */
	public static function get_stats( $args = array() ) {
		global $wpdb;
		
		$args = wp_parse_args( $args, array(
			'blog_id' => get_current_blog_id(),
			'bot_id' => 0,
			'client_id' => '',
		) );
		
		$table = self::memory_table();
		
		$where = array( 'blog_id = %d' );
		$params = array( (int) $args['blog_id'] );
		
		if ( (int) $args['bot_id'] > 0 ) {
			$where[] = 'bot_id = %d';
			$params[] = (int) $args['bot_id'];
		}
		
		if ( ! empty( $args['client_id'] ) ) {
			$where[] = 'client_id = %s';
			$params[] = $args['client_id'];
		}
		
		$where_sql = implode( ' AND ', $where );
		
		// Count by type
		$sql = "SELECT memory_type, COUNT(*) as count, AVG(score) as avg_score
		        FROM {$table}
		        WHERE {$where_sql}
		        GROUP BY memory_type
		        ORDER BY count DESC";
		
		$by_type = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		
		// Total count
		$total_sql = "SELECT COUNT(*) as total,
		              SUM(CASE WHEN memory_type='pain' THEN 1 ELSE 0 END) as pain_count,
		              SUM(CASE WHEN memory_type='constraint' THEN 1 ELSE 0 END) as constraint_count,
		              SUM(CASE WHEN memory_type='goal' THEN 1 ELSE 0 END) as goal_count
		              FROM {$table}
		              WHERE {$where_sql}";
		
		$totals = $params ? $wpdb->get_row( $wpdb->prepare( $total_sql, ...$params ), ARRAY_A ) : $wpdb->get_row( $total_sql, ARRAY_A );
		
		return array(
			'by_type' => $by_type,
			'totals' => $totals,
		);
	}
}
