<?php
/**
 * BizCity Personal — Notebook File Store
 *
 * Lưu nội dung notebook page dưới dạng .md files trong uploads dir.
 * Inspired by core/skills BizCity_Skill_Manager: file-based, YAML frontmatter, .htaccess secured.
 *
 * Storage path:
 *   wp-content/uploads/bizcity-personal/{blog_id}/notebooks/{page_id}.md
 *
 * File format (YAML frontmatter + Markdown body):
 *   ---
 *   title: Ghi chú họp sáng
 *   page_id: 42
 *   notebook_id: 3
 *   user_id: 1
 *   tags: [meeting, project-x]
 *   mood: focused
 *   created_at: 2026-06-24 08:00:00
 *   updated_at: 2026-06-24 10:30:00
 *   ---
 *
 *   # Nội dung markdown...
 *
 * PHP 7.4 compatible — no union types, no nullsafe, no match, no str_contains.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since      2026-06-24 (PHASE-HOME-NOTEBOOKS v1.0)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Notebook_File_Store' ) ) { return; }

class BizCity_Personal_Notebook_File_Store {

	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — file store for notebook pages

	/**
	 * Get base upload directory for notebooks.
	 * wp-content/uploads/bizcity-personal/{blog_id}/notebooks/
	 *
	 * @return string Absolute path with trailing slash
	 */
	public static function get_base_dir() {
		$upload_dir = wp_upload_dir();
		$blog_id    = get_current_blog_id();
		return $upload_dir['basedir'] . '/bizcity-personal/' . $blog_id . '/notebooks/';
	}

	/**
	 * Ensure base directory exists and is protected from direct web access.
	 *
	 * @return bool
	 */
	public static function ensure_dir() {
		$base = self::get_base_dir();
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		// .htaccess — block direct web access
		$htaccess = $base . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}
		// index.php fallback
		$index = $base . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return is_dir( $base );
	}

	/**
	 * Write notebook page content to .md file.
	 *
	 * @param int    $page_id     DB row ID of the page.
	 * @param string $title       Page title.
	 * @param string $content     Markdown body content.
	 * @param array  $meta        { notebook_id:int, user_id:int, tags:string[], mood:string, created_at:string }
	 * @return string|false       Filename (relative to base_dir) on success, false on failure.
	 */
	public static function write_page( $page_id, $title, $content, array $meta ) {
		self::ensure_dir();
		$base     = self::get_base_dir();
		$filename = 'page-' . (int) $page_id . '.md';

		// Build YAML frontmatter
		$tags_arr = isset( $meta['tags'] ) && is_array( $meta['tags'] ) ? $meta['tags'] : array();
		$tags_str = implode( ', ', array_map( 'sanitize_text_field', $tags_arr ) );
		$mood     = isset( $meta['mood'] )       ? sanitize_text_field( $meta['mood'] )       : '';
		$nb_id    = isset( $meta['notebook_id'] ) ? (int) $meta['notebook_id']                 : 0;
		$user_id  = isset( $meta['user_id'] )    ? (int) $meta['user_id']                      : 0;
		$created  = isset( $meta['created_at'] ) ? $meta['created_at']                         : current_time( 'mysql' );

		// Sanitize title — no newlines in frontmatter
		$fm_title = str_replace( array( "\n", "\r", '"' ), array( ' ', '', "'" ), (string) $title );

		$fm  = "---\n";
		$fm .= 'title: "' . $fm_title . "\"\n";
		$fm .= 'page_id: ' . (int) $page_id . "\n";
		$fm .= 'notebook_id: ' . $nb_id . "\n";
		$fm .= 'user_id: ' . $user_id . "\n";
		if ( $tags_str ) { $fm .= 'tags: [' . $tags_str . "]\n"; }
		if ( $mood )     { $fm .= 'mood: ' . $mood . "\n"; }
		$fm .= 'created_at: ' . $created . "\n";
		$fm .= 'updated_at: ' . current_time( 'mysql' ) . "\n";
		$fm .= "---\n\n";

		$result = file_put_contents( $base . $filename, $fm . $content );
		return ( false !== $result ) ? $filename : false;
	}

	/**
	 * Read page content from .md file.
	 *
	 * @param string $relative_path  e.g. 'page-42.md'
	 * @return array|false  { content:string, meta:array } or false if not found.
	 */
	public static function read_page( $relative_path ) {
		$base = self::get_base_dir();
		// basename() prevents path traversal
		$path = $base . basename( $relative_path );
		if ( ! file_exists( $path ) ) { return false; }
		$raw = file_get_contents( $path );
		if ( false === $raw ) { return false; }

		$meta    = array();
		$content = $raw;

		// Parse YAML frontmatter (--- ... ---)
		if ( substr( $raw, 0, 3 ) === '---' ) {
			$end = strpos( $raw, "\n---\n", 4 );
			if ( false !== $end ) {
				$fm_str  = substr( $raw, 4, $end - 4 );
				$content = ltrim( substr( $raw, $end + 5 ) );
				foreach ( explode( "\n", $fm_str ) as $line ) {
					$pos = strpos( $line, ':' );
					if ( false !== $pos ) {
						$k = trim( substr( $line, 0, $pos ) );
						$v = trim( substr( $line, $pos + 1 ) );
						// Strip surrounding quotes
						if ( strlen( $v ) >= 2 && $v[0] === '"' && $v[ strlen($v) - 1 ] === '"' ) {
							$v = substr( $v, 1, -1 );
						}
						$meta[ $k ] = $v;
					}
				}
			}
		}
		return array( 'content' => $content, 'meta' => $meta );
	}

	/**
	 * Delete a page .md file.
	 *
	 * @param string $relative_path
	 * @return bool
	 */
	public static function delete_page( $relative_path ) {
		$base = self::get_base_dir();
		$path = $base . basename( $relative_path );
		if ( file_exists( $path ) ) {
			return @unlink( $path );
		}
		return true; // already gone
	}

	/**
	 * Generate a 120-char plain-text excerpt from markdown content.
	 *
	 * @param string $content
	 * @return string
	 */
	public static function make_excerpt( $content ) {
		// Strip markdown syntax (headings, bold, code, links)
		$plain = preg_replace( '/^#{1,6}\s+/m', '', $content );
		$plain = preg_replace( '/```[\s\S]*?```/', '', $plain );
		$plain = preg_replace( '/`[^`]+`/', '', $plain );
		$plain = preg_replace( '/\*\*([^*]+)\*\*/', '$1', $plain );
		$plain = preg_replace( '/\*([^*]+)\*/', '$1', $plain );
		$plain = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $plain );
		$plain = preg_replace( '/^[-*>\s]+/m', '', $plain );
		$plain = trim( preg_replace( '/\s+/', ' ', $plain ) );
		return mb_substr( $plain, 0, 120 );
	}

	/**
	 * Count words in markdown content.
	 *
	 * @param string $content
	 * @return int
	 */
	public static function word_count( $content ) {
		$plain = preg_replace( '/```[\s\S]*?```/', '', $content );
		$plain = preg_replace( '/[`*#>\[\]()_~]/u', ' ', $plain );
		$words = preg_split( '/\s+/u', trim( $plain ), -1, PREG_SPLIT_NO_EMPTY );
		return $words ? count( $words ) : 0;
	}
}
