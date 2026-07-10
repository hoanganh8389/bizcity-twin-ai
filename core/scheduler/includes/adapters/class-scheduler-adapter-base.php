<?php
/**
 * BizCity Scheduler — Abstract base adapter (shared validate helpers).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler\Adapters
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-scheduler-event-adapter.php';

if ( class_exists( 'BizCity_Scheduler_Adapter_Base' ) ) {
	return;
}

abstract class BizCity_Scheduler_Adapter_Base implements BizCity_Scheduler_Event_Adapter {

	/**
	 * Decode metadata field (string|array) → array.
	 *
	 * @param mixed $meta
	 * @return array
	 */
	protected function meta_array( $meta ) {
		if ( is_array( $meta ) ) {
			return $meta;
		}
		if ( is_string( $meta ) && $meta !== '' ) {
			$decoded = json_decode( $meta, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	/**
	 * Generic schema-driven validator.
	 * Schema format: [ 'field' => [ 'type' => 'string|int|bool|array', 'required' => bool ] ]
	 *
	 * @param array $payload Toàn payload truyền vào create_event (đã có 'metadata').
	 * @return true|WP_Error
	 */
	public function validate( array $payload ) {
		$meta   = $this->meta_array( $payload['metadata'] ?? [] );
		$schema = $this->metadata_schema();
		foreach ( $schema as $field => $spec ) {
			$required = ! empty( $spec['required'] );
			$exists   = array_key_exists( $field, $meta );
			if ( $required && ( ! $exists || $meta[ $field ] === '' || $meta[ $field ] === null ) ) {
				return new WP_Error(
					'sched_adapter_missing_field',
					sprintf( '[%s] thiếu field metadata bắt buộc: %s', $this->event_type(), $field )
				);
			}
			if ( ! $exists ) {
				continue;
			}
			$type = isset( $spec['type'] ) ? $spec['type'] : 'string';
			if ( ! $this->type_ok( $meta[ $field ], $type ) ) {
				return new WP_Error(
					'sched_adapter_bad_type',
					sprintf( '[%s] field "%s" sai kiểu, kỳ vọng %s', $this->event_type(), $field, $type )
				);
			}
		}
		return true;
	}

	/**
	 * @param mixed  $value
	 * @param string $type
	 * @return bool
	 */
	protected function type_ok( $value, $type ) {
		switch ( $type ) {
			case 'string': return is_string( $value );
			case 'int':    return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			case 'bool':   return is_bool( $value ) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
			case 'array':  return is_array( $value );
			default:       return true;
		}
	}

	/**
	 * Default no-op — publishers tự subscribe `bizcity_scheduler_reminder_fire`.
	 *
	 * @param array $event
	 * @return void
	 */
	public function on_fire( array $event ) {
		// Intentionally empty.
	}

	/**
	 * Default no-op.
	 *
	 * @param array $event
	 * @return void
	 */
	public function on_completed( array $event ) {
		// Intentionally empty.
	}
}
