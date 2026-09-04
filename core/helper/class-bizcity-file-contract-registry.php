<?php
/**
 * Canonical registry for business filestore contracts.
 *
 * Business records are distinct from operational logs: they are encrypted,
 * append-only JSONL records with a folded latest-state read model.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_File_Contract_Registry', false ) ) {
	return;
}

final class BizCity_File_Contract_Registry {

	private static $contracts = array();

	/**
	 * Register one business filestore contract.
	 *
	 * @param string $id Contract identifier.
	 * @param array  $contract Contract metadata.
	 * @return bool
	 */
	public static function register( $id, array $contract ) {
		$id = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $id );
		if ( $id === '' ) {
			return false;
		}

		$defaults = array(
			'owner_module'       => '',
			'label'              => $id,
			'folder'             => '',
			'module'             => '',
			'record_key'         => 'record_id',
			'schema_version'     => '1.0',
			'related_sql_tables' => array(),
			'retention_days'     => 30,
			'storage_scope'      => 'blog',
			'status'             => 'active',
		);
		$normalized = array_merge( $defaults, $contract );

		foreach ( array( 'owner_module', 'label', 'folder', 'module' ) as $field ) {
			if ( ! is_string( $normalized[ $field ] ) || $normalized[ $field ] === '' ) {
				return false;
			}
		}
		if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $normalized['folder'] )
			|| ! preg_match( '/^[A-Za-z0-9_.-]+$/', $normalized['module'] ) ) {
			return false;
		}
		if ( ! is_array( $normalized['related_sql_tables'] ) ) {
			return false;
		}
		$normalized['retention_days'] = max( 1, (int) $normalized['retention_days'] );
		$normalized['storage_scope']  = sanitize_key( (string) $normalized['storage_scope'] );
		if ( $normalized['storage_scope'] !== 'blog' ) {
			return false;
		}

		foreach ( self::$contracts as $existing_id => $existing ) {
			if ( $existing_id !== $id
				&& (string) $existing['folder'] === $normalized['folder']
				&& (string) $existing['module'] === $normalized['module'] ) {
				return false;
			}
		}
		if ( isset( self::$contracts[ $id ] ) ) {
			$existing = self::$contracts[ $id ];
			if ( $existing['owner_module'] !== $normalized['owner_module']
				|| $existing['folder'] !== $normalized['folder']
				|| $existing['module'] !== $normalized['module'] ) {
				return false;
			}
			self::$contracts[ $id ] = array_merge( $existing, $normalized );
			return true;
		}

		self::$contracts[ $id ] = $normalized;
		return true;
	}

	public static function get( $id ) {
		return isset( self::$contracts[ $id ] ) ? self::$contracts[ $id ] : null;
	}

	public static function has( $id ) {
		return isset( self::$contracts[ $id ] );
	}

	public static function all() {
		return self::$contracts;
	}
}
