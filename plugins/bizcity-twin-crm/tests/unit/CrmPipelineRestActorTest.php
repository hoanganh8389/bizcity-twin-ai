<?php
/**
 * Pipeline REST — a client-supplied `actor_id` must never reach the run service.
 *
 * `BizCity_CRM_Pipeline_Run_Service` trusts `$args['actor_id']` for audit rows (`by`, `raised_by`)
 * and task `created_by`, so the REST layer is the only place that can guarantee it is the
 * logged-in user. Covers transition_run, transition_exception, open_run and set_appointment.
 *
 * Run: php tests/unit/CrmPipelineRestActorTest.php
 * No WordPress required — WP classes and the run service are minimal recording stubs.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

$GLOBALS['crm_current_user'] = 7;

function get_current_user_id() { return $GLOBALS['crm_current_user']; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}
class WP_REST_Request implements ArrayAccess {
	private $params;
	public function __construct( array $params ) { $this->params = $params; }
	public function get_json_params() { return $this->params; }
	public function get_body_params() { return $this->params; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	public function offsetExists( $offset ): bool { return isset( $this->params[ $offset ] ); }
	public function offsetGet( $offset ): mixed { return $this->params[ $offset ] ?? null; }
	public function offsetSet( $offset, $value ): void { $this->params[ $offset ] = $value; }
	public function offsetUnset( $offset ): void { unset( $this->params[ $offset ] ); }
}

/** Every service entry point records the `$args` it received, keyed by method. */
class BizCity_CRM_Pipeline_Run_Service {
	public static $calls = array();
	public static function get_run( $id ) { return array( 'id' => (int) $id, 'contact_id' => 5 ); }
	public static function __callStatic( $name, $args ) {
		self::$calls[ $name ] = end( $args );
		return array( 'id' => 1 );
	}
	public static function open_run( $contact_id, $kind, $args = array() ) { self::$calls['open_run'] = $args; return 99; }
}
class BizCity_CRM_Customer_Pipeline {
	public static function b2_inbox_ids( $user_id ) { return array( 1 ); }
	public static function contact_in_scope( $contact_id, $inboxes ) { return 5 === (int) $contact_id; }
}

require __DIR__ . '/../../includes/class-pipeline-rest.php';

$pass = 0; $fail = 0;
function check_actor( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; }
}

$forged = 999; // a different user than the logged-in one (7)

$cases = array(
	'transition_run'       => array( 'transition_run', array( 'id' => 1, 'action' => 'start', 'stage_key' => 'P1.1', 'actor_id' => $forged ), 'start_stage' ),
	'transition_exception' => array( 'transition_exception', array( 'id' => 1, 'action' => 'open', 'exception_key' => 'qc_fail', 'actor_id' => $forged ), 'raise_exception' ),
	'open_run'             => array( 'open_run', array( 'contact_id' => 5, 'kind' => 'production', 'actor_id' => $forged ), 'open_run' ),
	'set_appointment'      => array( 'set_appointment', array( 'id' => 1, 'appointment_at' => '2026-10-01 09:00:00', 'actor_id' => $forged ), 'set_appointment' ),
);
foreach ( $cases as $label => $case ) {
	list( $method, $params, $service_method ) = $case;
	BizCity_CRM_Pipeline_Run_Service::$calls = array();
	$response = BizCity_CRM_Pipeline_REST::$method( new WP_REST_Request( $params ) );
	$args = BizCity_CRM_Pipeline_Run_Service::$calls[ $service_method ] ?? null;
	check_actor( "{$label}: service was reached (200)", 200 === $response->status && is_array( $args ) );
	check_actor( "{$label}: forged actor_id is replaced by the logged-in user", is_array( $args ) && 7 === ( $args['actor_id'] ?? null ) );
}

// Without any client-supplied actor_id the logged-in user is still used.
BizCity_CRM_Pipeline_Run_Service::$calls = array();
BizCity_CRM_Pipeline_REST::transition_run( new WP_REST_Request( array( 'id' => 1, 'action' => 'complete', 'stage_key' => 'P1.1' ) ) );
check_actor( 'transition_run: absent actor_id defaults to logged-in user', 7 === ( BizCity_CRM_Pipeline_Run_Service::$calls['complete_stage']['actor_id'] ?? null ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
