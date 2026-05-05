<?php
/**
 * Bizcity Twin AI — Twin Tool Registry
 *
 * Sprint 4.7a — Singleton registry cho mọi `BizCity_Twin_Tool`. Plugin đăng ký
 * tool qua filter `bizcity_twin_register_tool`. Twin_Agent_Loop lấy danh sách
 * tool theo subset cho phép từ caller.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	require_once __DIR__ . '/interface-twin-tool.php';
}

class BizCity_Twin_Tool_Registry {

	/** @var BizCity_Twin_Tool_Registry|null */
	private static $instance = null;

	/** @var array<string, BizCity_Twin_Tool> */
	private $tools = [];

	/** @var bool */
	private $loaded = false;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Lazy-load: gọi filter lần đầu khi cần.
	 */
	private function ensure_loaded(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		/**
		 * Plugin add tool:
		 *   add_filter( 'bizcity_twin_register_tool', function( $registry ) {
		 *       $registry['my_tool'] = new My_Tool();
		 *       return $registry;
		 *   } );
		 *
		 * Registry trả về: array<string, BizCity_Twin_Tool>
		 */
		$external = apply_filters( 'bizcity_twin_register_tool', [] );
		if ( is_array( $external ) ) {
			foreach ( $external as $name => $tool ) {
				if ( $tool instanceof BizCity_Twin_Tool ) {
					$this->tools[ (string) $tool->name() ] = $tool;
				}
			}
		}
	}

	/**
	 * Đăng ký programmatic (không qua filter).
	 */
	public function register( BizCity_Twin_Tool $tool ): void {
		$this->ensure_loaded();
		$this->tools[ $tool->name() ] = $tool;
	}

	public function get( string $name ): ?BizCity_Twin_Tool {
		$this->ensure_loaded();
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Lấy tất cả tool. Nếu truyền $allowed (whitelist) thì chỉ trả subset.
	 *
	 * @param string[]|null $allowed
	 * @return array<string, BizCity_Twin_Tool>
	 */
	public function get_all( ?array $allowed = null ): array {
		$this->ensure_loaded();
		if ( null === $allowed ) {
			return $this->tools;
		}
		$out = [];
		foreach ( $allowed as $name ) {
			if ( isset( $this->tools[ $name ] ) ) {
				$out[ $name ] = $this->tools[ $name ];
			}
		}
		return $out;
	}

	/**
	 * Render danh sách tool thành đoạn system-prompt cho LLM.
	 *
	 * Format này LLM-agnostic — work với mọi provider (OpenAI, Anthropic, Gemini,
	 * Ollama). LLM được hướng dẫn output `<tool name="x">{json args}</tool>`.
	 *
	 * @param string[]|null $allowed
	 */
	public function render_prompt_section( ?array $allowed = null ): string {
		$tools = $this->get_all( $allowed );
		if ( empty( $tools ) ) {
			return '';
		}

		$lines   = [];
		$lines[] = '## AVAILABLE TOOLS';
		$lines[] = 'You can call these tools to gather information BEFORE answering. Each tool returns JSON.';
		$lines[] = '';
		$lines[] = 'TO CALL A TOOL: output EXACTLY this format on its own line and STOP:';
		$lines[] = '<tool name="TOOL_NAME">{"arg1":"value","arg2":123}</tool>';
		$lines[] = '';
		$lines[] = 'Rules:';
		$lines[] = '- Only ONE tool call per response. After STOP, the system runs the tool and replies with results.';
		$lines[] = '- If you have enough information, DO NOT call any tool — write the final answer directly.';
		$lines[] = '- Maximum 3 tool calls per conversation. Plan accordingly.';
		$lines[] = '- Tool args MUST be a single-line valid JSON object.';
		$lines[] = '';
		$lines[] = '### Tool catalogue:';

		foreach ( $tools as $tool ) {
			$schema_json = wp_json_encode( $tool->parameters_schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$lines[]     = '';
			$lines[]     = '#### ' . $tool->name();
			$lines[]     = $tool->description();
			$lines[]     = 'Schema: ' . $schema_json;
		}

		$lines[] = '';
		$lines[] = '### Example tool call:';
		$lines[] = '<tool name="search_kg">{"query":"founder of BizCity","top_k":3}</tool>';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Parse LLM output để trích tool call. Trả về NULL nếu không có.
	 *
	 * Match: `<tool name="xxx">{...json...}</tool>` (multi-line JSON OK).
	 *
	 * @return array{name:string,args:array,raw:string}|null
	 */
	public static function parse_tool_call( string $llm_output ): ?array {
		if ( false === strpos( $llm_output, '<tool' ) ) {
			return null;
		}
		// Greedy không tốt — dùng non-greedy, tôn trọng newline trong JSON.
		if ( ! preg_match( '#<tool\s+name=["\']([a-z0-9_]+)["\']\s*>(.*?)</tool>#is', $llm_output, $m ) ) {
			return null;
		}
		$name    = strtolower( trim( $m[1] ) );
		$raw_arg = trim( $m[2] );
		$args    = [];
		if ( '' !== $raw_arg ) {
			$decoded = json_decode( $raw_arg, true );
			if ( is_array( $decoded ) ) {
				$args = $decoded;
			}
		}
		return [
			'name' => $name,
			'args' => $args,
			'raw'  => $m[0],
		];
	}
}
