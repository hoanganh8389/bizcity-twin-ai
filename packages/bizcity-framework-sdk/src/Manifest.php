<?php
/**
 * Small immutable-by-convention manifest value object for SDK consumers.
 *
 * @package BizCity\FrameworkSdk
 * @since 1.0.0
 */

namespace BizCity\Twin\Contracts;

final class Manifest {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function id() {
		return (string) ( $this->data['id'] ?? '' );
	}

	public function version() {
		return (string) ( $this->data['version'] ?? '' );
	}

	public function capabilities() {
		return isset( $this->data['capabilities'] ) && is_array( $this->data['capabilities'] )
			? $this->data['capabilities']
			: array();
	}

	// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — expose declared permission scopes.
	public function permissions() {
		return isset( $this->data['permissions'] ) && is_array( $this->data['permissions'] )
			? array_values( array_unique( $this->data['permissions'] ) )
			: array();
	}

	public function scopeBindings() {
		return isset( $this->data['scope_bindings'] ) && is_array( $this->data['scope_bindings'] )
			? $this->data['scope_bindings']
			: array();
	}

	public function approvalGates() {
		return isset( $this->data['approval_gates'] ) && is_array( $this->data['approval_gates'] )
			? array_values( array_unique( $this->data['approval_gates'] ) )
			: array();
	}

	public function security() {
		return isset( $this->data['security'] ) && is_array( $this->data['security'] )
			? $this->data['security']
			: array();
	}

	public function toArray() {
		return $this->data;
	}
}
