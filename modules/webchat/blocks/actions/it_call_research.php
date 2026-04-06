<?php
/**
 * WaicAction Block: it_call_research — Web Research + Source Import
 *
 * Searches the web via Tavily, ranks results, extracts content, and writes
 * sources to BOTH bizcity_rces (long-term notebook) AND bizcity_webchat_sources
 * (sidebar reactive). Optionally embeds for RAG.
 *
 * Dual-write pattern ensures sources appear in the Source Panel sidebar immediately
 * while also persisting in the Companion Notebook for future sessions.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\WebChat\Blocks\Actions
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      Phase 1.10 Sprint 0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class WaicAction_it_call_research extends WaicAction {

	protected $_code  = 'it_call_research';
	protected $_order = 0;

	/** @var string Log prefix */
	private const LOG_PREFIX = '[it_call_research]';

	public function __construct( $block = null ) {
		$this->_name = __( '🔍 Research — Web Search + Sources', 'bizcity-twin-ai' );
		$this->_desc = __( 'Tìm kiếm web qua Tavily, xếp hạng, trích xuất nội dung, import vào Sources sidebar + Notebook.', 'bizcity-twin-ai' );
		$this->setBlock( $block );
	}

	/* ================================================================
	 *  Settings — UI fields in workflow editor
	 * ================================================================ */

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$this->_settings = [
			'research_query' => [
				'type'      => 'textarea',
				'label'     => __( 'Research Query *', 'bizcity-twin-ai' ),
				'default'   => '{{node#1.text}}',
				'rows'      => 3,
				'variables' => true,
				'desc'      => __( 'Từ khóa tìm kiếm. Hỗ trợ {{node#X.var}} variable.', 'bizcity-twin-ai' ),
			],
			'max_results' => [
				'type'    => 'select',
				'label'   => __( 'Max Tavily results', 'bizcity-twin-ai' ),
				'default' => '10',
				'options' => [
					'5'  => '5',
					'10' => '10',
					'15' => '15',
					'20' => '20',
				],
			],
			'language' => [
				'type'    => 'select',
				'label'   => __( 'Search language', 'bizcity-twin-ai' ),
				'default' => 'vi',
				'options' => [
					'vi' => 'Tiếng Việt',
					'en' => 'English',
				],
			],
			'extract_top_n' => [
				'type'    => 'select',
				'label'   => __( 'Extract full content — top N results', 'bizcity-twin-ai' ),
				'default' => '3',
				'options' => [
					'0' => 'Skip extraction',
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'5' => '5',
				],
				'desc' => __( 'Số lượng URL cần extract full content (chậm hơn nhưng chất lượng cao).', 'bizcity-twin-ai' ),
			],
			'do_embed' => [
				'type'    => 'select',
				'label'   => __( 'Auto-embed for RAG?', 'bizcity-twin-ai' ),
				'default' => '1',
				'options' => [
					'1' => __( 'Yes — embed ngay', 'bizcity-twin-ai' ),
					'0' => __( 'No — chỉ lưu text', 'bizcity-twin-ai' ),
				],
			],
		];
	}

	/* ================================================================
	 *  Variables — output for downstream {{node#X.var}}
	 * ================================================================ */

	public function getVariables() {
		if ( empty( $this->_variables ) ) {
			$this->setVariables();
		}
		return $this->_variables;
	}

	public function setVariables() {
		$this->_variables = [
			'source_ids'       => __( 'Comma-separated source IDs (bizcity_rces)', 'bizcity-twin-ai' ),
			'source_count'     => __( 'Number of sources imported', 'bizcity-twin-ai' ),
			'total_chars'      => __( 'Total characters of extracted content', 'bizcity-twin-ai' ),
			'chunk_count'      => __( 'Total chunks after embedding', 'bizcity-twin-ai' ),
			'research_summary' => __( 'JSON array of {url, title, score, chars}', 'bizcity-twin-ai' ),
		];
	}

	/* ================================================================
	 *  getResults — Main execution
	 * ================================================================ */

	/**
	 * @param int   $taskId    Current task ID.
	 * @param array $variables Pipeline variables (includes _session_id, _user_id, node#X, etc.)
	 * @param int   $step      Current step index.
	 * @return array { result: array, error: string, status: int }
	 */
	public function getResults( $taskId, $variables, $step = 0 ) {
		$start_time = microtime( true );

		// ── Resolve settings with variable substitution ──
		$settings = $this->getSettings();
		$query    = $this->resolveVariable( $settings['research_query']['default'] ?? '', $variables );
		$max      = (int) ( $settings['max_results']['default'] ?? 10 );
		$language = $settings['language']['default'] ?? 'vi';
		$top_n    = (int) ( $settings['extract_top_n']['default'] ?? 3 );
		$do_embed = (int) ( $settings['do_embed']['default'] ?? 1 );

		// Override from block instance settings
		$block_settings = $this->getBlock() ? ( $this->getBlock()->getSettings() ?? [] ) : [];
		if ( ! empty( $block_settings['research_query'] ) ) {
			$query = $this->resolveVariable( $block_settings['research_query'], $variables );
		}
		if ( isset( $block_settings['max_results'] ) ) {
			$max = (int) $block_settings['max_results'];
		}
		if ( isset( $block_settings['language'] ) ) {
			$language = $block_settings['language'];
		}
		if ( isset( $block_settings['extract_top_n'] ) ) {
			$top_n = (int) $block_settings['extract_top_n'];
		}
		if ( isset( $block_settings['do_embed'] ) ) {
			$do_embed = (int) $block_settings['do_embed'];
		}

		// ── Execution state ──
		$session_id = $variables['_session_id'] ?? $variables['session_id'] ?? '';
		$user_id    = (int) ( $variables['_user_id'] ?? $variables['user_id'] ?? 0 );
		$channel    = $variables['_channel'] ?? 'webchat';

		// 1 session = 1 project: resolve project_id from session
		$project_id = $this->resolve_project_id( $session_id );

		error_log( self::LOG_PREFIX . ' START query="' . mb_substr( $query, 0, 100 ) . '" max=' . $max . ' lang=' . $language . ' extract=' . $top_n . ' embed=' . $do_embed . ' session=' . $session_id . ' project=' . $project_id );

		if ( empty( $query ) ) {
			return $this->make_error_result( 'Research query is empty' );
		}

		// ── Trace: execute_start (Working Panel SSE) ──
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_start', [
			'node_code'  => 'it_call_research',
			'label'      => 'Đang tìm kiếm: ' . mb_substr( $query, 0, 60 ),
			'session_id' => $session_id,
		], 'info', 0 );

		// ── Step 1: Check existing webchat_sources matching query ──
		$existing = $this->find_existing_sources( $session_id, $query );
		if ( ! empty( $existing ) ) {
			error_log( self::LOG_PREFIX . ' Found ' . count( $existing ) . ' existing sources matching query — reusing' );
		}

		// ── Step 2: Tavily web search ──
		if ( ! class_exists( 'BCN_Tavily_Client' ) ) {
			return $this->make_error_result( 'BCN_Tavily_Client class not available' );
		}

		$search_results = BCN_Tavily_Client::search( $query, $max, $language );

		if ( is_wp_error( $search_results ) ) {
			error_log( self::LOG_PREFIX . ' Tavily error: ' . $search_results->get_error_message() );
			return $this->make_error_result( 'Tavily search failed: ' . $search_results->get_error_message() );
		}

		if ( empty( $search_results ) ) {
			error_log( self::LOG_PREFIX . ' Tavily returned 0 results' );
			return $this->make_result( [], 0, 0, 0, '[]' );
		}

		error_log( self::LOG_PREFIX . ' Tavily returned ' . count( $search_results ) . ' results' );

		// ── Step 3: Rank results ──
		if ( class_exists( 'BCN_Research_Ranker' ) ) {
			$ranked = BCN_Research_Ranker::rank( $search_results, $max );
			error_log( self::LOG_PREFIX . ' Ranked top ' . count( $ranked ) . ' results' );
		} else {
			$ranked = $search_results;
			error_log( self::LOG_PREFIX . ' BCN_Research_Ranker not available — using raw results' );
		}

		// ── Step 4: Extract full content for top N ──
		$extractor = null;
		if ( $top_n > 0 && class_exists( 'BCN_Source_Extractor' ) ) {
			$extractor = new BCN_Source_Extractor();
		}

		$source_ids   = [];
		$total_chars  = 0;
		$summary_data = [];

		foreach ( $ranked as $i => $item ) {
			$url     = $item['url'] ?? '';
			$title   = $item['title'] ?? '';
			$content = $item['content'] ?? $item['excerpt'] ?? '';
			$score   = $item['score'] ?? 0;

			if ( empty( $url ) ) {
				continue;
			}

			// Check if this URL already exists in session sources
			if ( $this->url_exists_in_session( $session_id, $url ) ) {
				error_log( self::LOG_PREFIX . ' URL already exists in session: ' . $url );
				continue;
			}

			// Extract full content for top N
			if ( $extractor && $i < $top_n ) {
				$extracted = $extractor->extract_url( $url );
				if ( ! empty( $extracted['text'] ) && empty( $extracted['error'] ) ) {
					$content = $extracted['text'];
					error_log( self::LOG_PREFIX . ' Extracted ' . mb_strlen( $content ) . ' chars from ' . $url );
				}
			}

			if ( empty( $content ) ) {
				continue;
			}

			$char_count = mb_strlen( $content );
			$total_chars += $char_count;

			// ── Step 5: DUAL-WRITE — bizcity_rces (BCN) ──
			$bcn_source_id = $this->write_to_bcn_sources( $project_id, $user_id, $session_id, [
				'source_type' => 'url',
				'source_url'  => $url,
				'title'       => $title,
				'content'     => $content,
				'score'       => $score,
			] );

			// ── Step 5b: DUAL-WRITE — bizcity_webchat_sources (sidebar) ──
			$webchat_source_id = $this->write_to_webchat_sources( $session_id, $user_id, [
				'source_type' => 'url',
				'url'         => $url,
				'title'       => $title,
				'content'     => $content,
			] );

			if ( $bcn_source_id && ! is_wp_error( $bcn_source_id ) ) {
				$source_ids[] = $bcn_source_id;
			}

			$summary_data[] = [
				'url'    => $url,
				'title'  => $title,
				'score'  => round( $score, 2 ),
				'chars'  => $char_count,
				'bcn_id' => $bcn_source_id,
				'wcs_id' => $webchat_source_id,
			];

			error_log( self::LOG_PREFIX . ' Source #' . ( $i + 1 ) . ': ' . $url . ' | bcn_id=' . $bcn_source_id . ' wcs_id=' . $webchat_source_id . ' chars=' . $char_count );
		}

		// ── Step 6: Embed if requested ──
		$chunk_count = 0;
		if ( $do_embed && ! empty( $source_ids ) && $project_id && class_exists( 'BCN_Embedder' ) ) {
			$embedder    = new BCN_Embedder();
			$embed_result = $embedder->embed_project( $project_id );
			$chunk_count  = $embed_result['success'] ?? 0;
			error_log( self::LOG_PREFIX . ' Embedded: total=' . ( $embed_result['total'] ?? 0 ) . ' success=' . $chunk_count . ' failed=' . ( $embed_result['failed'] ?? 0 ) );
		}

		// ── Step 7: Send sidebar notification via chat message ──
		$this->notify_sources_added( $session_id, $user_id, $channel, count( $source_ids ), $query );

		$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );
		error_log( self::LOG_PREFIX . ' DONE sources=' . count( $source_ids ) . ' chars=' . $total_chars . ' chunks=' . $chunk_count . ' (' . $elapsed_ms . 'ms)' );

		// ── Step 8: Studio output entry (Studio tab + bizcity_webchat_studio_outputs) ──
		$this->save_studio_output( $session_id, $user_id, $taskId, count( $source_ids ), $query, $summary_data );

		// ── Step 9: Working Panel trace — execute_done ──
		$this->emit_pipeline_trace( $session_id, count( $source_ids ), $total_chars, $elapsed_ms );

		return $this->make_result(
			$source_ids,
			count( $source_ids ),
			$total_chars,
			$chunk_count,
			wp_json_encode( $summary_data )
		);
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Resolve project_id from session (1 session = 1 project).
	 *
	 * @param string $session_id
	 * @return string
	 */
	private function resolve_project_id( $session_id ) {
		if ( empty( $session_id ) ) {
			return '';
		}

		global $wpdb;
		$sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';

		$project_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT project_id FROM {$sessions_table} WHERE session_id = %s LIMIT 1",
			$session_id
		) );

		// If no project_id yet, use session_id as project_id (1:1 mapping)
		if ( empty( $project_id ) ) {
			$project_id = 'sess_' . substr( md5( $session_id ), 0, 12 );

			// Update session with the new project_id
			$wpdb->update(
				$sessions_table,
				[ 'project_id' => $project_id, 'updated_at' => current_time( 'mysql' ) ],
				[ 'session_id' => $session_id ]
			);

			error_log( self::LOG_PREFIX . ' Created project_id=' . $project_id . ' for session=' . $session_id );
		}

		return $project_id;
	}

	/**
	 * Check if URL already exists in session webchat_sources.
	 *
	 * @param string $session_id
	 * @param string $url
	 * @return bool
	 */
	private function url_exists_in_session( $session_id, $url ) {
		if ( empty( $session_id ) || empty( $url ) ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_sources';

		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND url = %s LIMIT 1",
			$session_id,
			$url
		) );
	}

	/**
	 * Find existing webchat_sources in session matching query keywords.
	 *
	 * @param string $session_id
	 * @param string $query
	 * @return array
	 */
	private function find_existing_sources( $session_id, $query ) {
		if ( empty( $session_id ) || empty( $query ) ) {
			return [];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_sources';

		// Simple keyword match on title
		$keyword = '%' . $wpdb->esc_like( mb_substr( $query, 0, 50 ) ) . '%';
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, url FROM {$table} WHERE session_id = %s AND title LIKE %s LIMIT 10",
			$session_id,
			$keyword
		), ARRAY_A );

		return $results ? $results : [];
	}

	/**
	 * Write source to bizcity_rces (BCN long-term storage).
	 *
	 * @param string $project_id
	 * @param int    $user_id
	 * @param string $session_id
	 * @param array  $data { source_type, source_url, title, content, score }
	 * @return int|WP_Error Source ID or error
	 */
	private function write_to_bcn_sources( $project_id, $user_id, $session_id, array $data ) {
		if ( ! class_exists( 'BCN_Sources' ) || empty( $project_id ) ) {
			return 0;
		}

		$sources = new BCN_Sources();
		return $sources->add( $project_id, [
			'source_type'  => $data['source_type'],
			'source_url'   => $data['source_url'],
			'title'        => $data['title'],
			'content_text' => $data['content'],
			'skip_extract' => true, // Already extracted
			'metadata'     => [
				'search_score'   => $data['score'] ?? 0,
				'imported_by'    => 'it_call_research',
				'session_id'     => $session_id,
			],
		] );
	}

	/**
	 * Write source to bizcity_webchat_sources (sidebar reactive).
	 *
	 * @param string $session_id
	 * @param int    $user_id
	 * @param array  $data { source_type, url, title, content }
	 * @return int|false Insert ID or false
	 */
	private function write_to_webchat_sources( $session_id, $user_id, array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_sources';

		$inserted = $wpdb->insert( $table, [
			'session_id'       => $session_id,
			'user_id'          => $user_id,
			'source_type'      => $data['source_type'] ?? 'url',
			'title'            => mb_substr( $data['title'] ?? '', 0, 500 ),
			'url'              => $data['url'] ?? '',
			'content'          => $data['content'] ?? '',
			'embedding_status' => 'pending',
			'chunk_count'      => 0,
			'created_at'       => current_time( 'mysql' ),
		] );

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Send a chat message notifying user that sources were added.
	 * This triggers the frontend polling → SET_SOURCES → sidebar update.
	 *
	 * @param string $session_id
	 * @param int    $user_id
	 * @param string $channel
	 * @param int    $count
	 * @param string $query
	 */
	private function notify_sources_added( $session_id, $user_id, $channel, $count, $query ) {
		if ( empty( $session_id ) || $count < 1 ) {
			return;
		}

		// Use WebChat Database to log a pipeline_progress message
		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}

		$db = BizCity_WebChat_Database::instance();
		$db->log_message( [
			'session_id'    => $session_id,
			'user_id'       => 0,
			'client_name'   => 'Research Bot',
			'message_id'    => uniqid( 'research_' ),
			'message_text'  => sprintf(
				'🔍 **Research hoàn tất!** Đã tìm và import **%d nguồn** cho: "%s"',
				$count,
				mb_substr( $query, 0, 80 )
			),
			'message_from'  => 'bot',
			'message_type'  => 'pipeline_progress',
			'platform_type' => ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT',
			'tool_name'     => 'it_call_research',
			'meta'          => wp_json_encode( [
				'action'       => 'sources_added',
				'source_count' => $count,
				'query'        => $query,
			] ),
		] );
	}

	/**
	 * Resolve {{node#X.var}} variables in a string.
	 *
	 * @param string $text
	 * @param array  $variables
	 * @return string
	 */
	private function resolveVariable( $text, $variables ) {
		if ( strpos( $text, '{{' ) === false ) {
			return $text;
		}

		return preg_replace_callback( '/\{\{(node#\d+)\.(\w+)\}\}/', function ( $m ) use ( $variables ) {
			$node_key = $m[1]; // e.g. "node#1"
			$var_key  = $m[2]; // e.g. "text"
			if ( isset( $variables[ $node_key ][ $var_key ] ) ) {
				return $variables[ $node_key ][ $var_key ];
			}
			if ( isset( $variables[ $node_key ] ) && is_string( $variables[ $node_key ] ) ) {
				return $variables[ $node_key ];
			}
			return $m[0]; // Return original if not found
		}, $text );
	}

	/**
	 * Write a studio output entry to bizcity_webchat_studio_outputs.
	 * Called directly (bypasses on_execution_completed event which requires verified + content tool_types).
	 *
	 * @param string $session_id
	 * @param int    $user_id
	 * @param int    $task_id
	 * @param int    $count         Number of sources imported.
	 * @param string $query         Search query used.
	 * @param array  $summary_data  Array of {url, title, score, chars}.
	 */
	private function save_studio_output( $session_id, $user_id, $task_id, $count, $query, array $summary_data ) {
		if ( ! class_exists( 'BizCity_Output_Store' ) ) {
			return;
		}

		$content = sprintf( "Đã tìm và import **%d nguồn** cho: \"%s\"\n\n", $count, mb_substr( $query, 0, 100 ) );
		foreach ( array_slice( $summary_data, 0, 5 ) as $s ) {
			$label    = ! empty( $s['title'] ) ? $s['title'] : $s['url'];
			$url      = $s['url'] ?? '';
			$chars    = isset( $s['chars'] ) ? number_format( (int) $s['chars'] ) . ' ký tự' : '';
			$content .= '- [' . esc_html( $label ) . '](' . esc_url( $url ) . ')' . ( $chars ? ' — ' . $chars : '' ) . "\n";
		}

		BizCity_Output_Store::save_artifact( [
			'tool_id'    => 'it_call_research',
			'caller'     => 'pipeline',
			'session_id' => $session_id,
			'user_id'    => (int) $user_id,
			'task_id'    => $task_id ?: null,
			'data'       => [
				'title'   => sprintf( 'Research — %d sources found', $count ),
				'content' => $content,
			],
		], 'research' );
	}

	/**
	 * Fire bizcity_intent_pipeline_log action to update Working Panel (SSE) with execute_done.
	 *
	 * @param string $session_id
	 * @param int    $count       Number of sources.
	 * @param int    $total_chars Total characters extracted.
	 * @param float  $elapsed_ms  Duration in milliseconds.
	 */
	private function emit_pipeline_trace( $session_id, $count, $total_chars, $elapsed_ms ) {
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_done', [
			'node_code'  => 'it_call_research',
			'label'      => sprintf( 'Research — %d nguồn, %s ký tự', $count, number_format( (int) $total_chars ) ),
			'has_error'  => 'false',
			'session_id' => $session_id,
		], 'info', (int) $elapsed_ms );
	}

	/**
	 * Build a success result array.
	 */
	private function make_result( array $source_ids, $count, $total_chars, $chunk_count, $summary_json ) {
		return [
			'result' => [
				'source_ids'       => implode( ',', $source_ids ),
				'source_count'     => (string) $count,
				'total_chars'      => (string) $total_chars,
				'chunk_count'      => (string) $chunk_count,
				'research_summary' => $summary_json,
			],
			'error'  => '',
			'status' => 3, // completed
		];
	}

	/**
	 * Build an error result array.
	 */
	private function make_error_result( $error_message ) {
		error_log( self::LOG_PREFIX . ' ERROR: ' . $error_message );
		return [
			'result' => [
				'source_ids'       => '',
				'source_count'     => '0',
				'total_chars'      => '0',
				'chunk_count'      => '0',
				'research_summary' => '[]',
			],
			'error'  => $error_message,
			'status' => 3,
		];
	}
}
