<?php
/**
 * DDV probe for the Phase 0.41 public extension manifest contract.
 *
 * This probe is read-only. It inspects the public catalog, JSON artifacts and
 * manifest policy fixtures without loading a provider, registry or database.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-02 (PHASE-0.41-CRM-ONE-BRAIN)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Framework_Extension_Manifest', false ) ) {
	return;
}

final class BizCity_Probe_Framework_Extension_Manifest implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.framework.extension_manifest'; }
	public function label(): string { return 'Public extension manifest contract'; }
	public function description(): string { return 'Kiểm tra catalog, JSON fixture matrix và policy fail-closed của extension-manifest@1.0.0.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 20; }
	public function icon(): string { return 'file-check-2'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — verify the public manifest catalog and negative policy fixtures without provider or database side effects.
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$dir   = $root . 'core/twin-core/contracts/schema/public/v1/';
		$catalog_path = $dir . 'contract-catalog.json';
		$catalog = $this->read_json( $catalog_path );
		$catalog_ok = is_array( $catalog );
		$missing = array();
		$json_errors = array();

		if ( $catalog_ok ) {
			$contracts = isset( $catalog['contracts'] ) && is_array( $catalog['contracts'] ) ? $catalog['contracts'] : array();
			foreach ( $contracts as $contract ) {
				if ( ! is_array( $contract ) ) {
					$json_errors[] = 'catalog contract entry is not an object';
					continue;
				}
				$refs = array();
				if ( isset( $contract['schema'] ) ) {
					$refs[] = (string) $contract['schema'];
				}
				$fixtures = isset( $contract['fixtures'] ) && is_array( $contract['fixtures'] ) ? $contract['fixtures'] : array();
				foreach ( array( 'valid', 'invalid' ) as $key ) {
					if ( isset( $fixtures[ $key ] ) ) {
						$refs[] = (string) $fixtures[ $key ];
					}
				}
				foreach ( array( 'additional_valid', 'additional_invalid' ) as $key ) {
					if ( isset( $fixtures[ $key ] ) && is_array( $fixtures[ $key ] ) ) {
						$refs = array_merge( $refs, array_map( 'strval', $fixtures[ $key ] ) );
					}
				}
				foreach ( $refs as $relative ) {
					$path = $dir . ltrim( $relative, '/' );
					if ( ! is_file( $path ) || ! is_readable( $path ) ) {
						$missing[] = $relative;
						continue;
					}
					$decoded = $this->read_json( $path );
					if ( ! is_array( $decoded ) ) {
						$json_errors[] = $relative;
					}
				}
			}
		}
		$steps[] = array(
			'label'  => 'Disk - public catalog, schemas and fixtures are readable JSON',
			'status' => $catalog_ok && empty( $missing ) && empty( $json_errors ) ? 'pass' : 'fail',
			'detail' => $catalog_ok && empty( $missing ) && empty( $json_errors )
				? 'Catalog 1.7.0 and every referenced contract artifact are readable.'
				: 'Missing or invalid public contract artifacts: ' . implode( ', ', array_merge( $missing, $json_errors ) ),
		);
		if ( ! $catalog_ok || ! empty( $missing ) || ! empty( $json_errors ) ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Public extension manifest contract artifacts are incomplete.',
				'fix_hint'=> 'Restore the catalog and every referenced JSON schema/fixture, then rerun this probe.',
				'steps'   => $steps,
			);
		}

		$loader_ok = interface_exists( 'BizCity_Diagnostics_Probe', false )
			&& class_exists( 'BizCity_Probe_Framework_Extension_Manifest', false );
		$steps[] = array(
			'label'  => 'Loader - extension manifest probe contract is loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Probe interface and named probe class are loaded.' : 'Probe interface or class is unavailable.',
		);
		if ( ! $loader_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Extension manifest probe did not load.',
				'fix_hint'=> 'Load the probe through the Diagnostics Safe Loader and rerun the narrow probe.',
				'steps'   => $steps,
			);
		}

		$contracts = isset( $catalog['contracts'] ) && is_array( $catalog['contracts'] ) ? $catalog['contracts'] : array();
		$manifest_contract = null;
		foreach ( $contracts as $contract ) {
			if ( is_array( $contract ) && isset( $contract['id'] ) && 'extension-manifest' === $contract['id'] ) {
				$manifest_contract = $contract;
				break;
			}
		}
		$valid_ref = is_array( $manifest_contract ) && isset( $manifest_contract['fixtures']['valid'] )
			? (string) $manifest_contract['fixtures']['valid']
			: '';
		$invalid_ref = is_array( $manifest_contract ) && isset( $manifest_contract['fixtures']['invalid'] )
			? (string) $manifest_contract['fixtures']['invalid']
			: '';
		$valid = $valid_ref !== '' ? $this->read_json( $dir . $valid_ref ) : null;
		$invalid = $invalid_ref !== '' ? $this->read_json( $dir . $invalid_ref ) : null;
		$semantic_errors = is_array( $valid ) ? $this->manifest_errors( $valid ) : array( 'valid fixture is not an object' );
		$negative_ok = is_array( $invalid ) && ! isset( $invalid['extension_id'] ) && empty( $invalid['channels'] );
		$unsupported_ref = 'fixtures/extension-manifest.unsupported-capability.invalid.json';
		$unsupported = $this->read_json( $dir . $unsupported_ref );
		$unsupported_ok = is_array( $unsupported ) && in_array( 'brain.read_private_everywhere', $unsupported['capabilities'] ?? array(), true ) && ! in_array( 'brain.read_private_everywhere', $this->supported_capabilities(), true );
		$range_ref = 'fixtures/extension-manifest.invalid-range.invalid.json';
		$invalid_range = $this->read_json( $dir . $range_ref );
		$range_ok = is_array( $invalid_range ) && ! $this->is_framework_range( (string) ( $invalid_range['requires_framework'] ?? '' ) );
		$n_minus_one = $this->read_json( $dir . 'fixtures/extension-manifest.n-minus-one.valid.json' );
		$n_minus_one_ok = is_array( $n_minus_one ) && empty( $this->manifest_errors( $n_minus_one ) ) && isset( $n_minus_one['x_optional_extension_metadata'] );
		$slack = $this->read_json( $dir . 'fixtures/extension-manifest.slack.valid.json' );
		$tiktok = $this->read_json( $dir . 'fixtures/extension-manifest.tiktok.valid.json' );
		$shopee = $this->read_json( $dir . 'fixtures/extension-manifest.shopee.valid.json' );
		$slack_channel = is_array( $slack ) ? (array) ( $slack['channels'][0] ?? array() ) : array();
		$tiktok_channel = is_array( $tiktok ) ? (array) ( $tiktok['channels'][0] ?? array() ) : array();
		$shopee_channels = is_array( $shopee ) ? (array) ( $shopee['channels'] ?? array() ) : array();
		$shopee_by_slug = array();
		foreach ( $shopee_channels as $channel ) {
			if ( is_array( $channel ) ) {
				$shopee_by_slug[ (string) ( $channel['slug'] ?? '' ) ] = $channel;
			}
		}
		$future_policy_ok = is_array( $slack ) && is_array( $tiktok ) && is_array( $shopee )
			&& (string) ( $slack_channel['zone'] ?? '' ) === 'admin'
			&& (string) ( $slack_channel['identity_policy'] ?? '' ) === 'workspace_account_user'
			&& (string) ( $slack_channel['crm_policy'] ?? '' ) === 'disabled'
			&& (string) ( $slack_channel['brain_policy'] ?? '' ) === 'user_bound'
			&& (string) ( $tiktok_channel['zone'] ?? '' ) === 'customer'
			&& (string) ( $tiktok_channel['crm_policy'] ?? '' ) === 'enabled'
			&& (string) ( $tiktok_channel['brain_policy'] ?? '' ) === 'guest_channel'
			&& isset( $shopee_by_slug['shopee_chat'], $shopee_by_slug['shopee_commerce'] )
			&& (string) $shopee_by_slug['shopee_chat']['zone'] === 'customer'
			&& (string) $shopee_by_slug['shopee_chat']['crm_policy'] === 'enabled'
			&& (string) $shopee_by_slug['shopee_commerce']['zone'] === 'admin'
			&& (string) $shopee_by_slug['shopee_commerce']['crm_policy'] === 'not_applicable'
			&& in_array( 'commerce.order.read', (array) $shopee['capabilities'], true )
			&& in_array( 'fulfillment.read', (array) $shopee['capabilities'], true );
		$steps[] = array(
			'label'  => 'Runtime - N-1 optional fields and future channel policy matrix',
			'status' => $n_minus_one_ok && $future_policy_ok ? 'pass' : 'fail',
			'detail' => $n_minus_one_ok && $future_policy_ok ? 'N-1 manifest remains valid on the current contract and Slack/TikTok/Shopee policies keep identity, zone and commerce boundaries.' : 'Compatibility or future channel policy matrix failed.',
		);
		$runtime_ok = '1.7.0' === (string) ( $catalog['catalog_version'] ?? '' )
			&& 23 === count( $contracts )
			&& is_array( $manifest_contract )
			&& empty( $semantic_errors )
			&& $negative_ok
			&& $unsupported_ok
			&& $range_ok
			&& $n_minus_one_ok
			&& $future_policy_ok;
		$steps[] = array(
			'label'  => 'Runtime - valid manifest and fail-closed negative matrix',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_ok
				? 'Catalog 1.7.0, 23 contracts, valid policy and unsupported/range negatives passed.'
				: 'Manifest semantic policy or negative fixture expectation failed.',
		);

		return array(
			'status'  => $runtime_ok ? 'pass' : 'fail',
			'summary' => $runtime_ok ? 'Extension manifest contract passed.' : 'Extension manifest contract has validation failures.',
			'fix_hint'=> $runtime_ok ? '' : 'Keep extension-manifest additive, reject unsupported capabilities/ranges, and rerun the focused contract probe.',
			'steps'   => $steps,
		);
	}

	private function read_json( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		try {
			$value = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
			return is_array( $value ) ? $value : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function supported_capabilities(): array {
		return array( 'channel.inbound', 'channel.outbound', 'action.notify', 'context.admit', 'commerce.order.read', 'fulfillment.read' );
	}

	private function is_framework_range( string $range ): bool {
		return (bool) preg_match( '/^(?:(?:\^|~|>=|>|<=|<|=)?[0-9]+\.[0-9]+(?:\.[0-9]+|\.x)?)(?:\s+(?:(?:\^|~|>=|>|<=|<|=)?[0-9]+\.[0-9]+(?:\.[0-9]+|\.x)?))*$/', trim( $range ) );
	}

	private function manifest_errors( array $manifest ): array {
		$errors = array();
		if ( 'extension-manifest' !== (string) ( $manifest['contract'] ?? '' ) ) {
			$errors[] = 'contract';
		}
		if ( ! $this->is_framework_range( (string) ( $manifest['requires_framework'] ?? '' ) ) ) {
			$errors[] = 'requires_framework';
		}
		foreach ( (array) ( $manifest['capabilities'] ?? array() ) as $capability ) {
			if ( ! in_array( $capability, $this->supported_capabilities(), true ) ) {
				$errors[] = 'unsupported_capability';
			}
		}
		$slugs = array();
		foreach ( (array) ( $manifest['channels'] ?? array() ) as $channel ) {
			$slug = (string) ( $channel['slug'] ?? '' );
			if ( $slug === '' || in_array( $slug, $slugs, true ) ) {
				$errors[] = 'channel_slug';
			}
			$slugs[] = $slug;
			if ( 'customer' === (string) ( $channel['zone'] ?? '' ) && ! in_array( 'gpt_member', (array) ( $channel['surface_policy'] ?? array() ), true ) && ! in_array( 'gpt_guest', (array) ( $channel['surface_policy'] ?? array() ), true ) ) {
				$errors[] = 'customer_surface';
			}
			if ( 'enabled' === (string) ( $channel['crm_policy'] ?? '' ) && 'none' === (string) ( $channel['context_policy'] ?? '' ) ) {
				$errors[] = 'crm_context';
			}
		}
		$requires = (array) ( $manifest['diagnostics']['requires'] ?? array() );
		foreach ( array( 'disk', 'loader', 'runtime' ) as $evidence ) {
			if ( ! in_array( $evidence, $requires, true ) ) {
				$errors[] = 'diagnostics_' . $evidence;
			}
		}
		return $errors;
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Framework_Extension_Manifest';
	return $probes;
} );
