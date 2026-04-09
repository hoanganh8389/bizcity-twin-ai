<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skill Manager — File-based Skill CRUD
 *
 * Skills are Markdown files stored in core/skills/library/
 * organized by category (subfolder).
 *
 * File structure:
 *   library/
 *     content/
 *       write-sales-post.md
 *     automation/
 *       create-followup-flow.md
 *
 * Each .md file has YAML front-matter for metadata:
 *   ---
 *   title: Viết bài bán hàng
 *   modes: [content, planning]
 *   triggers: [bài bán hàng, sales post]
 *   tools: [create_post]
 *   plugins: []
 *   priority: 70
 *   status: active
 *   version: "1.0"
 *   ---
 *   # Content body in Markdown...
 *
 * @package  BizCity_Skills
 * @since    2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_Manager {

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Ensure library directory exists with security protections.
	 */
	public function __construct() {
		if ( ! is_dir( BIZCITY_SKILLS_LIBRARY ) ) {
			wp_mkdir_p( BIZCITY_SKILLS_LIBRARY );
		}
		$this->protect_library_dir();
		$this->seed_default_folders();
	}

	/**
	 * Add .htaccess + index.php to block direct web access.
	 */
	private function protect_library_dir(): void {
		$htaccess = BIZCITY_SKILLS_LIBRARY . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}
		$index = BIZCITY_SKILLS_LIBRARY . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Seed default category folders on first init.
	 */
	private function seed_default_folders(): void {
		$defaults = [ 'content', 'tools', 'note', 'nhat-ky' ];
		foreach ( $defaults as $folder ) {
			$dir = BIZCITY_SKILLS_LIBRARY . $folder;
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
				file_put_contents( $dir . DIRECTORY_SEPARATOR . 'index.php', "<?php\n// Silence is golden.\n" );
			}
		}
	}

	/* ================================================================
	 *  File System Tree — For React File Manager
	 * ================================================================ */

	/**
	 * Build file-system tree array compatible with ReactFileManager.
	 *
	 * @return array FileSystemType: [{id, name, isDir, path?, parentId?, lastModified?}]
	 */
	public function get_file_tree(): array {
		$tree = [];
		$id_counter = 1; // "0" reserved for root

		// Root entry
		$tree[] = [
			'id'   => '0',
			'name' => '/',
			'path' => '/',
			'isDir' => true,
		];

		$base = rtrim( BIZCITY_SKILLS_LIBRARY, '/\\' );
		$this->scan_dir( $base, '0', $tree, $id_counter );

		return $tree;
	}

	/**
	 * Recursive directory scanner.
	 */
	private function scan_dir( string $dir, string $parentId, array &$tree, int &$counter ): void {
		$entries = scandir( $dir );
		if ( ! $entries ) return;

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || $entry === 'index.php' ) continue;

			$full_path = $dir . DIRECTORY_SEPARATOR . $entry;
			$rel_path  = str_replace( rtrim( BIZCITY_SKILLS_LIBRARY, '/\\' ), '', $full_path );
			$rel_path  = str_replace( '\\', '/', $rel_path );
			$id        = (string) $counter++;

			if ( is_dir( $full_path ) ) {
				$tree[] = [
					'id'           => $id,
					'name'         => $entry,
					'isDir'        => true,
					'path'         => $rel_path,
					'parentId'     => $parentId,
					'lastModified' => filemtime( $full_path ),
				];
				$this->scan_dir( $full_path, $id, $tree, $counter );
			} else {
				// Only show .md files
				if ( pathinfo( $entry, PATHINFO_EXTENSION ) !== 'md' ) continue;

				$tree[] = [
					'id'           => $id,
					'name'         => $entry,
					'isDir'        => false,
					'parentId'     => $parentId,
					'lastModified' => filemtime( $full_path ),
				];
			}
		}
	}

	/* ================================================================
	 *  CRUD Operations
	 * ================================================================ */

	/**
	 * Read file content.
	 *
	 * @param string $relative_path Relative to library/ e.g. "/content/write-sales-post.md"
	 * @return array{frontmatter: array, content: string, raw: string}|WP_Error
	 */
	public function read_file( string $relative_path ) {
		$abs = $this->resolve_path( $relative_path );
		if ( is_wp_error( $abs ) ) return $abs;

		if ( ! file_exists( $abs ) ) {
			return new \WP_Error( 'not_found', 'File not found: ' . $relative_path );
		}

		$raw = file_get_contents( $abs );
		$parsed = $this->parse_frontmatter( $raw );

		return [
			'path'        => $relative_path,
			'frontmatter' => $parsed['frontmatter'],
			'content'     => $parsed['content'],
			'raw'         => $raw,
			'lastModified' => filemtime( $abs ),
		];
	}

	/**
	 * Write/update a file. Creates parent directory if needed.
	 *
	 * @param string $relative_path e.g. "/content/my-skill.md"
	 * @param string $raw           Full file content (frontmatter + body)
	 * @return true|WP_Error
	 */
	public function write_file( string $relative_path, string $raw ) {
		$abs = $this->resolve_path( $relative_path );
		if ( is_wp_error( $abs ) ) return $abs;

		// Ensure .md extension
		if ( pathinfo( $abs, PATHINFO_EXTENSION ) !== 'md' ) {
			return new \WP_Error( 'invalid_ext', 'Only .md files allowed' );
		}

		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$result = file_put_contents( $abs, $raw );
		if ( $result === false ) {
			return new \WP_Error( 'write_error', 'Failed to write file' );
		}

		return true;
	}

	/**
	 * Delete a file.
	 *
	 * @param string $relative_path e.g. "/content/old-skill.md"
	 * @return true|WP_Error
	 */
	public function delete_file( string $relative_path ) {
		$abs = $this->resolve_path( $relative_path );
		if ( is_wp_error( $abs ) ) return $abs;

		if ( ! file_exists( $abs ) ) {
			return new \WP_Error( 'not_found', 'File not found' );
		}

		if ( is_dir( $abs ) ) {
			// Only delete empty directories
			$entries = array_diff( scandir( $abs ), [ '.', '..', 'index.php' ] );
			if ( count( $entries ) > 0 ) {
				return new \WP_Error( 'not_empty', 'Folder is not empty' );
			}
			rmdir( $abs );
		} else {
			unlink( $abs );
		}

		return true;
	}

	/**
	 * Create a new category folder.
	 *
	 * @param string $folder_name e.g. "content" or "/content/sub"
	 * @return true|WP_Error
	 */
	public function create_folder( string $folder_name ) {
		$abs = $this->resolve_path( '/' . ltrim( $folder_name, '/' ) );
		if ( is_wp_error( $abs ) ) return $abs;

		if ( is_dir( $abs ) ) {
			return new \WP_Error( 'exists', 'Folder already exists' );
		}

		$result = wp_mkdir_p( $abs );
		if ( ! $result ) {
			return new \WP_Error( 'create_error', 'Failed to create folder' );
		}

		// Block direct web access inside subfolder
		$idx = $abs . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! file_exists( $idx ) ) {
			file_put_contents( $idx, "<?php\n// Silence is golden.\n" );
		}

		return true;
	}

	/* ================================================================
	 *  Skill Matching — Used by context layer
	 * ================================================================ */

	/**
	 * Find matching skills for a given context.
	 *
	 * Delegates to SQL-based BizCity_Skill_Database when available (Phase 1.4a).
	 * Falls back to file-based scanning if DB class not loaded.
	 *
	 * Scoring:
	 *   slash_commands exact match  → +30
	 *   mode match                  → +30
	 *   related_tools / tools match → +25
	 *   goal as tool                → +25
	 *   plugins active              → +20
	 *   trigger keyword in message  → +15
	 *   priority bonus              → 0-10
	 *   Threshold: ≥ 15
	 *
	 * @param array $criteria {mode, goal, tool, message, slash_command, limit}
	 * @return array [{path, frontmatter, content, score, reasons}]
	 */
	public function find_matching( array $criteria ): array {
		// ── Phase 1.4a: Delegate to SQL database if available ──
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			$db    = BizCity_Skill_Database::instance();
			$match = $db->find_matching( $criteria );

			if ( $match ) {
				// Normalize SQL result to match file-based format
				$rows = isset( $match['id'] ) ? [ $match ] : $match;
				$results = [];
				foreach ( $rows as $row ) {
					$results[] = [
						'path'        => 'sql://' . $row['skill_key'],
						'frontmatter' => [
							'title'          => $row['title'],
							'category'       => $row['category'] ?? 'general',
							'triggers'       => json_decode( $row['triggers_json'] ?? '[]', true ) ?: [],
							'slash_commands' => array_filter( explode( ',', $row['slash_commands'] ?? '' ) ),
							'modes'          => array_filter( explode( ',', $row['modes'] ?? '' ) ),
							'tools'          => json_decode( $row['tools_json'] ?? '[]', true ) ?: [],
							'priority'       => (int) ( $row['priority'] ?? 50 ),
							'status'         => $row['status'] ?? 'active',
						],
						'content'     => $row['content'],
						'score'       => $row['_score'] ?? 0,
						'reasons'     => [ 'source:sql' ],
						'skill_id'    => $row['id'] ?? null,
					];
				}
				if ( ! empty( $results ) ) {
					return $results;
				}
			}
		}

		// ── Fallback: File-based scanning (legacy) ──
		return $this->find_matching_files( $criteria );
	}

	/**
	 * File-based skill matching (legacy path).
	 */
	private function find_matching_files( array $criteria ): array {
		$mode          = $criteria['mode'] ?? '';
		$goal          = $criteria['goal'] ?? '';
		$tool          = $criteria['tool'] ?? '';
		$message       = mb_strtolower( $criteria['message'] ?? '' );
		$slash_command = $criteria['slash_command'] ?? '';
		$limit         = $criteria['limit'] ?? 3;

		// Auto-detect slash command from message if not explicitly provided
		if ( ! $slash_command && preg_match( '/^\s*\/([a-z_]+)/i', $message, $sm ) ) {
			$slash_command = '/' . strtolower( $sm[1] );
		}

		$skills = $this->get_all_skills();
		$scored = [];

		foreach ( $skills as $sk ) {
			$fm = $sk['frontmatter'];
			if ( ( $fm['status'] ?? 'active' ) !== 'active' ) continue;

			$score   = 0;
			$reasons = [];

			// Slash command exact match (+30)
			$slashes = $fm['slash_commands'] ?? [];
			if ( $slash_command && ! empty( $slashes ) ) {
				foreach ( $slashes as $sc ) {
					$sc_clean = ltrim( trim( $sc ), '/' );
					$cmd_clean = ltrim( $slash_command, '/' );
					if ( strtolower( $sc_clean ) === strtolower( $cmd_clean ) ) {
						$score += 30;
						$reasons[] = 'slash:' . $slash_command;
						break;
					}
				}
			}

			// Mode match (+30)
			$modes = $fm['modes'] ?? [];
			if ( $mode && in_array( $mode, $modes, true ) ) {
				$score += 30;
				$reasons[] = 'mode:' . $mode;
			}

			// Tool match — check both 'related_tools' and legacy 'tools' (+25)
			$skill_tools = array_merge(
				(array) ( $fm['related_tools'] ?? [] ),
				(array) ( $fm['tools'] ?? [] )
			);
			if ( $tool && in_array( $tool, $skill_tools, true ) ) {
				$score += 25;
				$reasons[] = 'tool:' . $tool;
			}

			// Goal matches a tool (+25)
			if ( $goal && in_array( $goal, $skill_tools, true ) ) {
				$score += 25;
				$reasons[] = 'goal_as_tool:' . $goal;
			}

			// Plugin match (+20)
			$plugins = $fm['plugins'] ?? [];
			if ( ! empty( $plugins ) ) {
				foreach ( $plugins as $p ) {
					if ( is_plugin_active( $p . '/' . $p . '.php' ) || defined( 'BIZCITY_' . strtoupper( str_replace( '-', '_', $p ) ) . '_DIR' ) ) {
						$score += 20;
						$reasons[] = 'plugin:' . $p;
						break;
					}
				}
			}

			// Trigger keyword match (+15)
			$triggers = $fm['triggers'] ?? [];
			foreach ( $triggers as $kw ) {
				if ( $kw && mb_strpos( $message, mb_strtolower( $kw ) ) !== false ) {
					$score += 15;
					$reasons[] = 'trigger:' . $kw;
					break;
				}
			}

			// Priority bonus (0-10)
			$priority = (int) ( $fm['priority'] ?? 50 );
			$score += (int) round( $priority / 10 );

			if ( $score >= 15 ) {
				$scored[] = [
					'path'        => $sk['path'],
					'frontmatter' => $fm,
					'content'     => $sk['content'],
					'score'       => $score,
					'reasons'     => $reasons,
				];
			}
		}

		// Sort by score DESC
		usort( $scored, function ( $a, $b ) { return $b['score'] - $a['score']; } );

		return array_slice( $scored, 0, $limit );
	}

	/**
	 * Read all .md skill files with parsed frontmatter.
	 *
	 * @return array [{path, frontmatter, content}]
	 */
	public function get_all_skills(): array {
		$skills = [];
		$this->collect_skills( BIZCITY_SKILLS_LIBRARY, $skills );
		return $skills;
	}

	private function collect_skills( string $dir, array &$skills ): void {
		$entries = scandir( $dir );
		if ( ! $entries ) return;

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || $entry === 'index.php' ) continue;

			$full = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $full ) ) {
				$this->collect_skills( $full, $skills );
			} elseif ( pathinfo( $entry, PATHINFO_EXTENSION ) === 'md' ) {
				$rel = str_replace( rtrim( BIZCITY_SKILLS_LIBRARY, '/\\' ), '', $full );
				$rel = str_replace( '\\', '/', $rel );

				$raw    = file_get_contents( $full );
				$parsed = $this->parse_frontmatter( $raw );

				$skills[] = [
					'path'        => $rel,
					'frontmatter' => $parsed['frontmatter'],
					'content'     => $parsed['content'],
					'raw'         => $raw,
				];
			}
		}
	}

	/**
	 * Get a single skill by key.
	 *
	 * Tries SQL database first (if available), then falls back to
	 * scanning the file-system library matching by `name` frontmatter
	 * field or file basename.
	 *
	 * @param string $skill_key  Slug/key identifying the skill.
	 * @return array|null        Normalised skill array or null if not found.
	 *                           Shape: {path, frontmatter: array, content: string, score: null}
	 */
	public function get_skill( string $skill_key ): ?array {
		if ( empty( $skill_key ) ) {
			return null;
		}

		// ── 1. SQL database (Phase 1.4a) ──────────────────────────
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			$db  = BizCity_Skill_Database::instance();
			$uid = get_current_user_id();

			// Try per-user first, then global (user_id = 0)
			$row = $db->get_by_key( $skill_key, $uid ) ?? $db->get_by_key( $skill_key, 0 );

			if ( $row ) {
				return [
					'path'        => 'sql://' . $row['skill_key'],
					'frontmatter' => [
						'title'       => $row['title'] ?? '',
						'description' => $row['description'] ?? '',
						'category'    => $row['category'] ?? 'general',
						'key'         => $row['skill_key'],
					],
					'content'     => $row['content'] ?? '',
					'score'       => null,
				];
			}
		}

		// ── 2. File-system scan — match by frontmatter `name` or basename ──
		foreach ( $this->get_all_skills() as $skill ) {
			$fm   = $skill['frontmatter'] ?? [];
			$name = $fm['name'] ?? sanitize_title( $fm['title'] ?? basename( $skill['path'], '.md' ) );
			if ( $name === $skill_key ) {
				return $skill;
			}
		}

		// ── 3. Direct path read (skill_key = "folder/slug" → "/folder/slug.md") ──
		$path   = '/' . ltrim( $skill_key, '/' ) . '.md';
		$result = $this->read_file( $path );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		return null;
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Resolve relative path to absolute, with directory-traversal protection.
	 *
	 * Blocks: null bytes, encoded traversal, symlink escapes, and
	 * any resolved path outside BIZCITY_SKILLS_LIBRARY.
	 *
	 * @param string $relative_path
	 * @return string|WP_Error
	 */
	private function resolve_path( string $relative_path ) {
		// Block null bytes (poison byte attack)
		if ( strpos( $relative_path, "\0" ) !== false ) {
			return new \WP_Error( 'traversal', 'Invalid path' );
		}

		// Normalize
		$relative_path = str_replace( '\\', '/', $relative_path );
		$relative_path = '/' . ltrim( $relative_path, '/' );

		// Decode then check for traversal (catches %2e%2e, %252e etc.)
		$decoded = rawurldecode( $relative_path );
		if ( preg_match( '/\.\./', $decoded ) ) {
			return new \WP_Error( 'traversal', 'Directory traversal not allowed' );
		}

		// Only allow safe characters: alphanumeric, hyphens, underscores, dots, slashes
		if ( preg_match( '/[^a-zA-Z0-9\-_\.\/\p{L}\p{N}]/u', ltrim( $decoded, '/' ) ) ) {
			return new \WP_Error( 'invalid_chars', 'Path contains invalid characters' );
		}

		$abs = rtrim( BIZCITY_SKILLS_LIBRARY, '/\\' ) . $relative_path;
		$abs = str_replace( '/', DIRECTORY_SEPARATOR, $abs );

		// Double-check resolved path is within library
		$real_library = realpath( BIZCITY_SKILLS_LIBRARY );
		if ( ! $real_library ) {
			// Library dir doesn't exist yet — trust the mkdir will handle it
			return $abs;
		}

		// For new files, check parent dir
		$check_path = file_exists( $abs ) ? $abs : dirname( $abs );
		$real_check = realpath( $check_path );

		if ( $real_check && strpos( $real_check, $real_library ) !== 0 ) {
			return new \WP_Error( 'traversal', 'Path is outside skills library' );
		}

		return $abs;
	}

	/**
	 * Parse YAML front-matter from a Markdown string.
	 * Simple parser — no external deps.
	 *
	 * @param string $raw
	 * @return array{frontmatter: array, content: string}
	 */
	public function parse_frontmatter( string $raw ): array {
		$fm      = [];
		$content = $raw;

		if ( preg_match( '/\A---\s*\n(.*?)\n---\s*\n(.*)\z/s', $raw, $m ) ) {
			$content = $m[2];
			// YAML-subset parser — supports inline [a,b] AND multi-line - item lists
			$lines       = explode( "\n", $m[1] );
			$current_key = null;

			foreach ( $lines as $line ) {
				$trimmed = rtrim( $line );
				if ( $trimmed === '' || $trimmed[0] === '#' ) continue;

				// Multi-line list item: "  - value" or "* value"
				if ( preg_match( '/^\s+[-*]\s+(.*)$/', $trimmed, $li ) ) {
					if ( $current_key !== null ) {
						$item = trim( $li[1], '"\' ' );
						if ( $item !== '' ) {
							if ( ! is_array( $fm[ $current_key ] ) ) {
								$fm[ $current_key ] = [];
							}
							$fm[ $current_key ][] = $item;
						}
					}
					continue;
				}

				// Key: value line
				if ( preg_match( '/^(\w[\w_-]*)\s*:\s*(.*)$/', $trimmed, $kv ) ) {
					$key = $kv[1];
					$val = trim( $kv[2] );
					$current_key = $key;

					if ( $val === '' ) {
						// Value comes on next lines as - items
						$fm[ $key ] = [];
					} elseif ( preg_match( '/^\[(.+)\]$/', $val, $arr ) ) {
						// Inline array: [a, b, c]
						$fm[ $key ] = array_map( function ( $v ) {
							return trim( trim( $v ), '"\'' );
						}, explode( ',', $arr[1] ) );
					} elseif ( $val === '[]' ) {
						$fm[ $key ] = [];
					} elseif ( $val === 'true' ) {
						$fm[ $key ] = true;
					} elseif ( $val === 'false' ) {
						$fm[ $key ] = false;
					} elseif ( is_numeric( $val ) ) {
						$fm[ $key ] = $val + 0;
					} else {
						$fm[ $key ] = trim( $val, '"\'' );
					}
				}
			}
		}

		return [ 'frontmatter' => $fm, 'content' => $content ];
	}

	/**
	 * Detect skill archetype from frontmatter.
	 *
	 * Archetype A — Knowledge-only: no related_tools, no required_inputs.
	 * Archetype B — Single-tool:    exactly 1 related_tool.
	 * Archetype C — Multi-tool:     2+ related_tools OR output_format == json_workflow.
	 *
	 * @param array $skill  Skill array with 'frontmatter' key.
	 * @return string 'A', 'B', or 'C'
	 */
	public function detect_archetype( array $skill ): string {
		$fm = $skill['frontmatter'] ?? [];

		$related_tools = array_merge(
			(array) ( $fm['related_tools'] ?? [] ),
			(array) ( $fm['tools'] ?? [] )
		);
		$related_tools = array_filter( array_unique( $related_tools ) );

		$output_format = $fm['output_format'] ?? '';

		// C: Multi-tool workflow
		if ( count( $related_tools ) >= 2 || $output_format === 'json_workflow' ) {
			return 'C';
		}

		// B: Single-tool
		if ( count( $related_tools ) === 1 ) {
			return 'B';
		}

		// A: Knowledge-only
		return 'A';
	}

	/**
	 * Find matching skill with archetype + confidence tier (Phase 1.2).
	 *
	 * @param array $criteria Same as find_matching().
	 * @return array|null  Enriched result with archetype, confidence, primary_tool, etc. or null.
	 */
	public function find_matching_enriched( array $criteria ): ?array {
		$matches = $this->find_matching( $criteria );

		if ( empty( $matches ) ) {
			return null;
		}

		$best      = $matches[0];
		$archetype = $this->detect_archetype( $best );
		$score     = $best['score'] ?? 0;

		// Confidence tier
		$confidence = 'low';
		if ( $score >= 60 ) {
			$confidence = 'high';
		} elseif ( $score >= 30 ) {
			$confidence = 'medium';
		}

		$fm = $best['frontmatter'] ?? [];
		$related_tools = array_filter( array_unique( array_merge(
			(array) ( $fm['related_tools'] ?? [] ),
			(array) ( $fm['tools'] ?? [] )
		) ) );

		return [
			'skill'           => $best,
			'archetype'       => $archetype,
			'confidence'      => $confidence,
			'score'           => $score,
			'primary_tool'    => reset( $related_tools ) ?: '',
			'related_tools'   => array_values( $related_tools ),
			'required_inputs' => (array) ( $fm['required_inputs'] ?? [] ),
			'output_format'   => $fm['output_format'] ?? '',
			'reasons'         => $best['reasons'] ?? [],
		];
	}

	/**
	 * Build a raw Markdown string from frontmatter + content.
	 *
	 * @param array  $fm
	 * @param string $content
	 * @return string
	 */
	public function build_raw( array $fm, string $content ): string {
		$lines = [ '---' ];
		foreach ( $fm as $key => $val ) {
			if ( is_array( $val ) ) {
				$lines[] = $key . ': [' . implode( ', ', $val ) . ']';
			} elseif ( is_bool( $val ) ) {
				$lines[] = $key . ': ' . ( $val ? 'true' : 'false' );
			} elseif ( is_numeric( $val ) ) {
				$lines[] = $key . ': ' . $val;
			} else {
				$lines[] = $key . ': "' . str_replace( '"', '\\"', $val ) . '"';
			}
		}
		$lines[] = '---';
		$lines[] = '';

		return implode( "\n", $lines ) . $content;
	}
}
