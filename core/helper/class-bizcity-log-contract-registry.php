<?php
/**
 * Canonical registry for JSONL log contracts.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_Log_Contract_Registry', false ) ) {
	return;
}

final class BizCity_Log_Contract_Registry {

	private static $contracts = array();

	/**
	 * Register one canonical JSONL source for shared readers and diagnostics.
	 *
	 * @param string $id Contract identifier.
	 * @param array  $contract Contract metadata.
	 * @return bool
	 */
	public static function register( $id, array $contract ) {
		// [2026-08-25 Johnny Chu] PHASE-1.29-LOG-REGISTRY — keep one validated metadata owner for every JSONL source.
		$id = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $id );
		if ( $id === '' ) {
			return false;
		}

		$defaults = array(
			'owner_module'        => '',
			'label'               => $id,
			'jsonl_folder'        => '',
			'jsonl_module'        => '',
			'segments_template'   => array(),
			'related_sql_tables'  => array(),
			'retention_days'      => 7,
			'reader'              => 'BizCity_JSONL_File_Logger',
			'status'              => 'active',
			'indexed'             => false,
			'storage_scope'       => 'blog',
		);
		$normalized = array_merge( $defaults, $contract );

		foreach ( array( 'owner_module', 'label', 'jsonl_folder', 'jsonl_module', 'reader' ) as $field ) {
			if ( ! is_string( $normalized[ $field ] ) || $normalized[ $field ] === '' ) {
				return false;
			}
		}
		if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $normalized['jsonl_folder'] )
			|| ! preg_match( '/^[A-Za-z0-9_.-]+$/', $normalized['jsonl_module'] ) ) {
			return false;
		}
		if ( ! is_array( $normalized['segments_template'] ) || ! is_array( $normalized['related_sql_tables'] ) ) {
			return false;
		}

		$normalized['retention_days'] = max( 1, (int) $normalized['retention_days'] );
		$normalized['indexed'] = ! empty( $normalized['indexed'] );
		$normalized['storage_scope'] = sanitize_key( (string) $normalized['storage_scope'] );
		if ( ! in_array( $normalized['storage_scope'], array( 'blog', 'network', 'global' ), true ) ) {
			return false;
		}
		foreach ( self::$contracts as $existing_id => $existing_contract ) {
			if ( $existing_id !== $id
				&& (string) ( $existing_contract['jsonl_folder'] ?? '' ) === $normalized['jsonl_folder']
				&& (string) ( $existing_contract['jsonl_module'] ?? '' ) === $normalized['jsonl_module'] ) {
				return false;
			}
		}
		if ( isset( self::$contracts[ $id ] ) ) {
			$existing = self::$contracts[ $id ];
			if ( $existing['owner_module'] !== $normalized['owner_module']
				|| $existing['jsonl_folder'] !== $normalized['jsonl_folder']
				|| $existing['jsonl_module'] !== $normalized['jsonl_module'] ) {
				return false;
			}
			self::$contracts[ $id ] = array_merge( $existing, $normalized );
			return true;
		}

		self::$contracts[ $id ] = $normalized;
		return true;
	}

	/**
	 * Return one registered contract or null.
	 */
	public static function get( $id ) {
		return isset( self::$contracts[ $id ] ) ? self::$contracts[ $id ] : null;
	}

	/**
	 * Return all registered contracts in registration order.
	 */
	public static function all() {
		return self::$contracts;
	}

	/**
	 * Return whether an identifier is registered.
	 */
	public static function has( $id ) {
		return isset( self::$contracts[ $id ] );
	}

	/** Resolve one immutable contract from its canonical folder/module pair. */
	public static function resolve( $folder, $module ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — callers resolve contract identity from registry metadata, never from request-controlled paths.
		$folder = (string) $folder;
		$module = (string) $module;
		$found = null;
		foreach ( self::$contracts as $id => $contract ) {
			if ( (string) ( $contract['jsonl_folder'] ?? '' ) !== $folder || (string) ( $contract['jsonl_module'] ?? '' ) !== $module ) {
				continue;
			}
			if ( null !== $found ) {
				return null;
			}
			$found = array( 'id' => $id, 'contract' => $contract );
		}
		return $found;
	}
}
