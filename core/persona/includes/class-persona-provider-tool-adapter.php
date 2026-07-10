<?php
/**
 * BizCity Twin AI — Persona Provider Tool Adapter for Twin Tool Registry.
 *
 * Bridges declarative persona tool definitions (`get_tool_definitions`) to
 * `BizCity_Twin_Tool` runtime objects so Twin Agent can call provider tools
 * directly (without requiring a bound character).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since 2026-07-05
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Persona_Provider_Tool_Adapter', false ) ) {
	return;
}

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	return;
}

class BizCity_Persona_Provider_Tool_Adapter implements BizCity_Twin_Tool {

	/** @var object */
	private $provider;

	/** @var array<string,mixed> */
	private $tool_def = array();

	/** @var string */
	private $tool_name = '';

	/**
	 * @param object              $provider
	 * @param array<string,mixed> $tool_def
	 */
	public function __construct( $provider, array $tool_def ) {
		$this->provider  = $provider;
		$this->tool_def  = $tool_def;
		$this->tool_name = isset( $tool_def['name'] ) ? sanitize_key( (string) $tool_def['name'] ) : '';
	}

	public function name(): string {
		return $this->tool_name;
	}

	public function description(): string {
		$desc = isset( $this->tool_def['description'] ) ? trim( (string) $this->tool_def['description'] ) : '';
		if ( $desc !== '' ) {
			return $desc;
		}
		$label = isset( $this->tool_def['label'] ) ? trim( (string) $this->tool_def['label'] ) : '';
		if ( $label !== '' ) {
			return $label;
		}
		return 'Persona provider tool: ' . $this->tool_name;
	}

	public function parameters_schema(): array {
		if ( isset( $this->tool_def['parameters_schema'] ) && is_array( $this->tool_def['parameters_schema'] ) ) {
			$schema = $this->tool_def['parameters_schema'];
			if ( isset( $schema['type'] ) && (string) $schema['type'] === 'object' ) {
				return $schema;
			}
		}

		$slot_schema = isset( $this->tool_def['slot_schema'] ) && is_array( $this->tool_def['slot_schema'] )
			? $this->tool_def['slot_schema']
			: array();
		if ( isset( $slot_schema['type'] ) && (string) $slot_schema['type'] === 'object' ) {
			return $slot_schema;
		}

		$properties = array();
		$required   = array();
		foreach ( $slot_schema as $arg_name => $meta ) {
			if ( ! is_string( $arg_name ) || $arg_name === '' ) {
				continue;
			}
			$entry = array(
				'type' => 'string',
			);
			if ( is_string( $meta ) ) {
				$entry['type'] = $this->map_simple_type( $meta );
			} elseif ( is_array( $meta ) ) {
				if ( isset( $meta['type'] ) && is_string( $meta['type'] ) ) {
					$entry['type'] = $this->map_simple_type( (string) $meta['type'] );
				}
				if ( isset( $meta['label'] ) ) {
					$entry['description'] = (string) $meta['label'];
				} elseif ( isset( $meta['description'] ) ) {
					$entry['description'] = (string) $meta['description'];
				}
				if ( isset( $meta['enum'] ) && is_array( $meta['enum'] ) ) {
					$entry['enum'] = array_values( array_map( 'strval', $meta['enum'] ) );
				} elseif ( isset( $meta['choices'] ) && is_array( $meta['choices'] ) ) {
					$entry['enum'] = array_values( array_map( 'strval', $meta['choices'] ) );
				}
				if ( ! empty( $meta['required'] ) ) {
					$required[] = $arg_name;
				}
			}
			$properties[ $arg_name ] = $entry;
		}

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( array_unique( $required ) );
		}
		return $schema;
	}

	public function execute( array $args, array $context ): array {
		// [2026-07-05 Johnny Chu] HOTFIX — execute provider tools directly in Twin Agent loop.
		if ( isset( $this->tool_def['extra'] ) && is_array( $this->tool_def['extra'] ) ) {
			$args['__tool_extra'] = $this->tool_def['extra'];
		}
		if ( strpos( $this->tool_name, 'create_coach_map_' ) === 0 && empty( $args['template_slug'] ) ) {
			$args['template_slug'] = substr( $this->tool_name, strlen( 'create_coach_map_' ) );
		}

		$callback = $this->resolve_callback();
		if ( is_callable( $callback ) ) {
			try {
				$result = $this->invoke_callback( $callback, $args, $context );
			} catch ( \Throwable $e ) {
				return array(
					'ok'    => false,
					'error' => $e->getMessage(),
				);
			}
			return $this->normalize_result( $result );
		}

		$canvas = $this->dispatch_via_canvas_adapter( $args, $context );
		if ( null !== $canvas ) {
			return $this->normalize_result( $canvas );
		}

		return array(
			'ok'    => false,
			'error' => 'Tool callback is not available for ' . $this->tool_name,
		);
	}

	/**
	 * @return callable|null
	 */
	private function resolve_callback() {
		if ( isset( $this->tool_def['callback'] ) && is_callable( $this->tool_def['callback'] ) ) {
			return $this->tool_def['callback'];
		}
		$fallback_method = 'tool_' . $this->tool_name;
		if ( is_object( $this->provider ) && method_exists( $this->provider, $fallback_method ) ) {
			return array( $this->provider, $fallback_method );
		}
		return null;
	}

	/**
	 * @param callable $callback
	 * @return mixed
	 */
	private function invoke_callback( $callback, array $args, array $context ) {
		$param_count = 2;
		try {
			if ( is_array( $callback ) ) {
				$ref = new \ReflectionMethod( $callback[0], $callback[1] );
				$param_count = $ref->getNumberOfParameters();
			} else {
				$ref = new \ReflectionFunction( $callback );
				$param_count = $ref->getNumberOfParameters();
			}
		} catch ( \Throwable $e ) {
			$param_count = 2;
		}

		if ( $param_count <= 0 ) {
			return call_user_func( $callback );
		}
		if ( 1 === $param_count ) {
			return call_user_func( $callback, $args );
		}
		return call_user_func( $callback, $args, $context );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function dispatch_via_canvas_adapter( array $args, array $context ): ?array {
		if ( ! class_exists( 'BizCity_Canvas_Adapter' ) || ! method_exists( 'BizCity_Canvas_Adapter', 'get_handler' ) ) {
			return null;
		}
		$handler = BizCity_Canvas_Adapter::get_handler( $this->tool_name );
		if ( ! $handler || ! is_callable( $handler ) ) {
			return null;
		}

		$canvas_context = array(
			'user_id'    => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
			'session_id' => isset( $context['session_id'] ) ? (string) $context['session_id'] : '',
			'notebook_id'=> isset( $context['scope']['id'] ) ? (int) $context['scope']['id'] : 0,
			'scope_type' => isset( $context['scope']['type'] ) ? (string) $context['scope']['type'] : 'notebook',
			'message'    => isset( $context['user_message'] ) ? (string) $context['user_message'] : '',
			'entities'   => $args,
		);
		return BizCity_Canvas_Adapter::dispatch( $this->tool_name, $args, $canvas_context );
	}

	/**
	 * @param mixed $raw
	 * @return array<string,mixed>
	 */
	private function normalize_result( $raw ): array {
		if ( is_wp_error( $raw ) ) {
			return array(
				'ok'     => false,
				'error'  => (string) $raw->get_error_message(),
				'result' => array(
					'code' => (string) $raw->get_error_code(),
					'data' => $raw->get_error_data(),
				),
			);
		}

		if ( is_array( $raw ) ) {
			if ( array_key_exists( 'ok', $raw ) ) {
				if ( ! isset( $raw['summary'] ) ) {
					$raw['summary'] = $this->tool_name . ( ! empty( $raw['ok'] ) ? ' executed' : ' failed' );
				}
				return $raw;
			}
			$summary = isset( $raw['reply'] ) ? (string) $raw['reply'] : ( $this->tool_name . ' executed' );
			return array(
				'ok'      => true,
				'result'  => $raw,
				'summary' => $summary,
			);
		}

		return array(
			'ok'      => true,
			'result'  => $raw,
			'summary' => $this->tool_name . ' executed',
		);
	}

	private function map_simple_type( string $type ): string {
		$type = strtolower( trim( $type ) );
		if ( in_array( $type, array( 'int', 'integer', 'number', 'float', 'double' ), true ) ) {
			return 'number';
		}
		if ( in_array( $type, array( 'bool', 'boolean' ), true ) ) {
			return 'boolean';
		}
		if ( in_array( $type, array( 'array', 'list' ), true ) ) {
			return 'array';
		}
		return 'string';
	}
}
