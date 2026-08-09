<?php
/**
 * Deployment-neutral BizCity Twin extension contracts.
 *
 * @package BizCity\FrameworkSdk
 * @since 1.0.0
 */

namespace BizCity\Twin\Contracts;

interface ToolInterface {
	public function id();
	public function label();
	public function schema();
	public function run( array $args, array $context = [] );
}

interface SkillInterface {
	public function id();
	public function label();
	public function instructions();
	public function subTools();
	public function meta();
}

interface AgentInterface {
	public function id();
	public function name();
	public function meta();
	public function run( $input, array $context = [] );
}

interface ChannelAdapterInterface {
	public function id();
	public function platform();
	public function zone();
	public function normalizeInbound( array $payload );
	public function send( array $message, array $context = [] );
	public function meta();
}

interface KgSourceAdapterInterface {
	public function id();
	public function sourceType();
	public function supports( array $source );
	public function fetch( array $source, array $context = [] );
	public function toPassages( array $payload, array $context = [] );
	public function meta();
}

interface WorkflowBlockInterface {
	public function nodeId();
	public function label();
	public function inputSchema();
	public function outputSchema();
	public function execute( array $input, array $context = [] );
	public function sideEffects();
	public function meta();
}

interface PersonaProviderInterface {
	public function id();
	public function label();
	public function profile();
	public function systemInstructions( array $context = [] );
	public function meta();
}

interface OutputRendererInterface {
	public function id();
	public function artifactType();
	public function supports( array $output );
	public function render( array $output, array $context = [] );
	public function meta();
}

// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — SDK capability/runtime contracts.
interface CapabilityGuardInterface {
	public function can( $scope, array $context = [] );
	public function scopeLevel( $scope );
}

interface RuntimePolicyInterface {
	public function policy();
}
