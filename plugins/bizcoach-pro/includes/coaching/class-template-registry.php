<?php
/**
 * BizCoach Pro — Template Registry (in-memory canonical store).
 *
 * Holds active coach templates after Loader primes from JSON files + DB rows.
 * Pure data structure — no I/O. Loader populates; Persona/Intent providers consume.
 *
 * Template shape (validated by Loader):
 *   [
 *     'slug'           => 'career_coach',
 *     'label'          => 'Career Coach',
 *     'base_type'      => 'career_coach',
 *     'schema_version' => '1.0',
 *     'icon'           => '💼',
 *     'description'    => '...',
 *     'questions'      => [ [ 'key'=>..., 'label'=>..., 'type'=>'text|select|date', 'required'=>bool, 'choices'=>[] ], ... ],
 *     'generators'     => [ [ 'key'=>'gen_overview', 'label'=>'Tổng quan', 'prompt'=>'...', 'output'=>'json|markdown' ], ... ],
 *     'source'         => 'file' | 'db',
 *     'status'         => 'active' | 'draft' | 'archived',
 *   ]
 *
 * @since 0.1.0 (PHASE-0.36 / R-PROD-HUB)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Template_Registry' ) ) { return; }

class BizCoach_Pro_Template_Registry {

	/** @var array<string, array> slug => template */
	private static $store = [];

	/** Reset (test only). */
	public static function reset() { self::$store = []; }

	/** Add or replace template. Returns true on add, false if dropped (invalid). */
	public static function set( array $template ) {
		$slug = isset( $template['slug'] ) ? (string) $template['slug'] : '';
		if ( $slug === '' ) { return false; }
		self::$store[ $slug ] = $template;
		return true;
	}

	public static function get( $slug ) {
		$slug = (string) $slug;
		return isset( self::$store[ $slug ] ) ? self::$store[ $slug ] : null;
	}

	public static function has( $slug ) {
		return isset( self::$store[ (string) $slug ] );
	}

	/**
	 * @param string|null $status filter (default: 'active'); pass null for all.
	 * @return array<int, array>
	 */
	public static function all( $status = 'active' ) {
		if ( $status === null ) { return array_values( self::$store ); }
		$out = [];
		foreach ( self::$store as $tpl ) {
			$st = isset( $tpl['status'] ) ? $tpl['status'] : 'active';
			if ( $st === $status ) { $out[] = $tpl; }
		}
		return $out;
	}

	public static function count_active() {
		return count( self::all( 'active' ) );
	}

	/** Slug-list of active templates — used by Persona Provider for tool generation. */
	public static function active_slugs() {
		$out = [];
		foreach ( self::all( 'active' ) as $tpl ) {
			$out[] = $tpl['slug'];
		}
		return $out;
	}
}
