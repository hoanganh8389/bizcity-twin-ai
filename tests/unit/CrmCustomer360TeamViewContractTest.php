<?php
/**
 * PHASE-0.50 C-02 / master roadmap M3-01 — the B2 serializer turns the flat team-360 read model into
 * `customer-360-team-view@1.1.0`, and its output validates against the published schema file
 * (not a copy): required keys, additionalProperties:false, const/enum/pattern/type/maxItems.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-customer-360-team-view.php';

final class CrmCustomer360TeamViewContractTest extends TestCase {

    private const SCHEMA = '/core/twin-core/contracts/schema/public/v1/customer-360-team-view.schema.json';

    /** Shape of what GET /crm-contacts/{id}/team-360 composes today (flat, full contact row). */
    private function runtime_view(): array {
        return array(
            'contract' => 'customer-360-team-view',
            'version'  => '1.0.0',
            'as_of'    => '2026-09-18 09:20:00',
            'contact'  => array(
                'id' => 9001, 'first_name' => 'Lan', 'last_name' => '', 'name' => 'Chị Lan',
                'email' => 'lan@example.test', 'phone' => '0901234111', 'title' => null, 'account_id' => null,
                'owner_id' => 42, 'wp_user_id' => 77, 'acquisition_source' => 'facebook:lead_form',
                'acquisition_meta' => array( 'ad_id' => '123' ), 'tags' => array( 'Mua lại', array( 'bad' ) ),
                'additional_attributes' => array( 'zalo_uid' => '8452301199887766' ),
                'created_at' => '2026-05-02 14:00:00', 'updated_at' => '2026-09-01 10:00:00',
            ),
            'owner' => array( 'user_id' => 42, 'display_name' => 'Nguyễn Hương', 'conversation_id' => 88, 'inbox_id' => 13 ),
            'identity_conflicts' => array( 'open' => 1, 'review_url' => '' ),
            'conversations' => array(
                array( 'conversation_id' => 88, 'inbox_id' => 13, 'inbox_name' => 'Zalo Hương 1', 'channel_type' => 'zalo_personal', 'status' => 'open',
                    'assignee' => array( 'user_id' => 42, 'display_name' => 'Nguyễn Hương' ), 'last_activity_at' => '2026-09-16 10:05:00',
                    'created_at' => '2026-05-02 14:00:00', 'session_state' => 'connected', 'phone_owner' => array( 'user_id' => 42, 'display_name' => 'Nguyễn Hương' ) ),
            ),
            'touched_by' => array(
                array( 'user_id' => 42, 'display_name' => 'Nguyễn Hương', 'replies' => 37, 'first_at' => '2026-05-02 14:03:00', 'last_at' => '2026-09-16 10:05:00', 'inbox_ids' => array( 13 ) ),
                array( 'user_id' => 70, 'display_name' => 'Trần B (team khác)', 'replies' => 2, 'first_at' => '2026-06-10 08:00:00', 'last_at' => '2026-06-10 08:05:00', 'inbox_ids' => array( 21 ) ),
                array( 'user_id' => 71, 'display_name' => 'Lê C (team khác)', 'replies' => 1, 'first_at' => '2026-06-01 08:00:00', 'last_at' => '2026-06-20 08:05:00', 'inbox_ids' => array( 22 ) ),
            ),
            'automated_replies' => 5,
            'orders' => array(
                array( 'order_id' => 7001, 'number' => '7001', 'status' => 'completed', 'total' => 1890000.0, 'currency' => 'VND', 'paid' => true,
                    'created_at' => '2026-09-01 11:00:00', 'assignee' => array( 'user_id' => 42, 'display_name' => 'Nguyễn Hương' ), 'attribution' => 'assignee_at_create', 'conversation_id' => 88 ),
                array( 'order_id' => 6120, 'number' => '6120', 'status' => 'completed', 'total' => 890000.0, 'currency' => 'vnd', 'paid' => true,
                    'created_at' => '2026-06-11 09:00:00', 'assignee' => null, 'attribution' => 'something_else', 'conversation_id' => null ),
            ),
            'orders_available' => true,
            'marketing' => array( 'acquisition_source' => 'facebook:lead_form', 'acquisition_meta' => array( 'ad_id' => '123' ), 'tags' => array( 'Mua lại', array( 'bad' ) ), 'first_seen_at' => '2026-05-02 14:00:00' ),
            'open_tasks' => 1,
            'can' => array( 'view_owner_workspace' => true, 'assign_task' => true ),
        );
    }

    private function contract( array $visible = array( 10, 42 ) ): array {
        return BizCity_CRM_Customer_360_Team_View::to_contract( $this->runtime_view(), 10, 1258, '2026-09-18T09:20:00+07:00', $visible, array( 42 => 'agent', 10 => 'lead' ) );
    }

    public function test_output_validates_against_the_published_schema(): void {
        $schema = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . self::SCHEMA ), true );
        $errors = $this->validate( $schema, $this->contract(), '$', $schema );
        $this->assertSame( array(), $errors );
    }

    public function test_runtime_only_contact_fields_never_leave_the_serializer(): void {
        $json = json_encode( $this->contract() );
        foreach ( array( 'zalo_uid', '8452301199887766', 'acquisition_meta', 'additional_attributes', 'wp_user_id', 'owner_id' ) as $leak ) {
            $this->assertStringNotContainsString( $leak, $json, $leak . ' must not be in the contract output' );
        }
    }

    public function test_b2_leader_gets_full_and_masked_phone(): void {
        $contact = $this->contract()['data']['contact'];
        $this->assertSame( '0901234111', $contact['phone'] );
        $this->assertSame( '09******11', $contact['phone_masked'] );
        $this->assertSame( 'lan@example.test', $contact['email'] );
    }

    public function test_staff_outside_the_actor_roster_collapse_into_one_anonymous_row(): void {
        $touched = $this->contract()['data']['touched_by'];
        $this->assertCount( 2, $touched );
        $this->assertSame( 42, $touched[0]['user_id'] );
        $other = $touched[1];
        $this->assertNull( $other['user_id'] );
        $this->assertSame( 'Nhân viên khác', $other['display_name'] );
        $this->assertSame( 3, $other['replies'] );
        $this->assertSame( '2026-06-01 08:00:00', $other['first_at'] );
        $this->assertSame( '2026-06-20 08:05:00', $other['last_at'] );
        $this->assertArrayNotHasKey( 'inbox_ids', $other );
    }

    public function test_admin_roster_null_keeps_every_name(): void {
        $touched = BizCity_CRM_Customer_360_Team_View::to_contract( $this->runtime_view(), 1, 1258, '2026-09-18T09:20:00+07:00', null, array( 42 => 'agent' ) )['data']['touched_by'];
        $this->assertSame( array( 42, 70, 71 ), array_column( $touched, 'user_id' ) );
    }

    public function test_orders_are_normalized_and_unknown_attribution_is_null(): void {
        $items = $this->contract()['data']['orders']['items'];
        $this->assertSame( 'VND', $items[1]['currency'] );
        $this->assertNull( $items[1]['attribution'] );
        $this->assertSame( 'assignee_at_create', $items[0]['attribution'] );
    }

    public function test_subject_is_the_owner_and_coverage_names_the_missing_owner_history(): void {
        $out = $this->contract();
        $this->assertSame( array( 'user_id' => 42, 'display_name' => 'Nguyễn Hương', 'team_role' => 'agent' ), $out['subject'] );
        $this->assertFalse( $out['coverage']['complete'] );
        $this->assertContains( 'journey.assignment_history', $out['coverage']['degraded_sources'] );
        $this->assertSame( array( 'Mua lại' ), $out['data']['marketing']['labels'] );
        $this->assertArrayNotHasKey( 'review_url', $out['data']['identity_conflicts'] );
    }

    public function test_no_owner_and_no_woo_degrades_instead_of_inventing(): void {
        $view = $this->runtime_view();
        $view['owner'] = null;
        $view['orders_available'] = false;
        $view['orders'] = array();
        $out = BizCity_CRM_Customer_360_Team_View::to_contract( $view, 10, 1258, '2026-09-18T09:20:00+07:00', array( 10 ), array( 10 => 'lead' ) );
        $this->assertSame( 10, $out['subject']['user_id'] );
        $this->assertNull( $out['data']['owner'] );
        $this->assertContains( 'orders.woo', $out['coverage']['degraded_sources'] );
        $schema = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . self::SCHEMA ), true );
        $this->assertSame( array(), $this->validate( $schema, $out, '$', $schema ) );
    }

    public function test_the_schema_check_itself_rejects_a_leaked_field_and_a_wrong_surface(): void {
        $schema = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . self::SCHEMA ), true );
        $out = $this->contract();
        $out['data']['contact']['zalo_uid'] = '8452301199887766';
        $out['surface'] = 'C_PUBLIC_TWINGPT';
        $errors = $this->validate( $schema, $out, '$', $schema );
        $this->assertContains( '$.data.contact.zalo_uid: additional property', $errors );
        $this->assertContains( '$.surface: const', $errors );
    }

    // ── Minimal JSON-Schema subset used by the public contracts ──────────

    private function validate( array $schema, $value, string $path, array $root ): array {
        if ( isset( $schema['$ref'] ) ) {
            $node = $root;
            foreach ( explode( '/', substr( $schema['$ref'], 2 ) ) as $part ) { $node = $node[ $part ]; }
            return $this->validate( $node, $value, $path, $root );
        }
        $errors = array();
        if ( array_key_exists( 'const', $schema ) && $value !== $schema['const'] ) { $errors[] = "$path: const"; }
        if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) { $errors[] = "$path: enum"; }
        if ( isset( $schema['type'] ) ) {
            $ok = false;
            foreach ( (array) $schema['type'] as $type ) { $ok = $ok || $this->is_type( $value, $type ); }
            if ( ! $ok ) { return array( "$path: type" ); }
        }
        if ( is_string( $value ) ) {
            if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/u', $value ) ) { $errors[] = "$path: pattern"; }
            if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > $schema['maxLength'] ) { $errors[] = "$path: maxLength"; }
            if ( isset( $schema['minLength'] ) && mb_strlen( $value ) < $schema['minLength'] ) { $errors[] = "$path: minLength"; }
        }
        if ( ( is_int( $value ) || is_float( $value ) ) && isset( $schema['minimum'] ) && $value < $schema['minimum'] ) { $errors[] = "$path: minimum"; }
        if ( is_array( $value ) && $this->is_type( $value, 'array' ) ) {
            if ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) { $errors[] = "$path: maxItems"; }
            if ( isset( $schema['items'] ) ) {
                foreach ( $value as $i => $item ) { $errors = array_merge( $errors, $this->validate( $schema['items'], $item, "{$path}[{$i}]", $root ) ); }
            }
        } elseif ( is_array( $value ) ) {
            foreach ( (array) ( $schema['required'] ?? array() ) as $key ) {
                if ( ! array_key_exists( $key, $value ) ) { $errors[] = "$path.$key: required"; }
            }
            foreach ( $value as $key => $item ) {
                if ( isset( $schema['properties'][ $key ] ) ) {
                    $errors = array_merge( $errors, $this->validate( $schema['properties'][ $key ], $item, "$path.$key", $root ) );
                } elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
                    $errors[] = "$path.$key: additional property";
                }
            }
        }
        return $errors;
    }

    private function is_type( $value, string $type ): bool {
        switch ( $type ) {
            case 'object':  return is_array( $value ) && ( array() === $value ? false : array_keys( $value ) !== range( 0, count( $value ) - 1 ) );
            case 'array':   return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
            case 'string':  return is_string( $value );
            case 'integer': return is_int( $value );
            case 'number':  return is_int( $value ) || is_float( $value );
            case 'boolean': return is_bool( $value );
            case 'null':    return null === $value;
        }
        return false;
    }
}
