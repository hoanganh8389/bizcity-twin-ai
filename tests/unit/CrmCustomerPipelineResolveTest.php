<?php
/**
 * PHASE-0.52 R-PIPE-2/4/8 — how a customer's pipeline stage is resolved (pure function, no DB).
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
}
require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-customer-pipeline.php';
require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-pipeline-rest.php';

final class CrmCustomerPipelineResolveTest extends TestCase {

    private const NOW = 1790000000;

    private function settings(): array { return BizCity_CRM_Customer_Pipeline::default_settings(); }
    private function daysAgo( int $d ): int { return self::NOW - $d * DAY_IN_SECONDS; }

    private function resolve( array $facts, ?array $pipe = null ): array {
        return BizCity_CRM_Customer_Pipeline::resolve( $facts + array( 'created_ts' => $this->daysAgo( 40 ) ), $pipe, self::NOW, $this->settings() );
    }

    public function test_facts_only(): void {
        $this->assertSame( 'target', $this->resolve( array() )['stage'] );
        $this->assertSame( 'contacted', $this->resolve( array( 'first_out_ts' => $this->daysAgo( 2 ), 'last_out_ts' => $this->daysAgo( 1 ) ) )['stage'] );
        $won = $this->resolve( array( 'paid' => 1, 'first_paid_ts' => $this->daysAgo( 2 ), 'last_order_ts' => $this->daysAgo( 2 ) ) );
        $this->assertSame( 'won', $won['stage'] );
        $this->assertFalse( $won['manual_won_pending'] );
        $this->assertSame( 'repeat', $this->resolve( array( 'paid' => 2, 'first_paid_ts' => $this->daysAgo( 9 ), 'second_paid_ts' => $this->daysAgo( 1 ), 'last_order_ts' => $this->daysAgo( 1 ) ) )['stage'] );
    }

    public function test_manual_move_wins_over_older_facts_even_backwards(): void {
        // Contacted by message 10 days ago, moved by hand to "consult" 2 days ago.
        $r = $this->resolve( array( 'first_out_ts' => $this->daysAgo( 10 ) ), array( 'stage' => 'consult', 'at_ts' => $this->daysAgo( 2 ), 'source' => 'manual' ) );
        $this->assertSame( 'consult', $r['stage'] );
        $this->assertSame( 2, $r['days'] );
        $this->assertSame( 'manual', $r['source'] );
    }

    public function test_manual_won_without_woo_order_is_allowed_and_flagged(): void {
        $r = $this->resolve( array( 'first_out_ts' => $this->daysAgo( 5 ) ), array( 'stage' => 'won', 'at_ts' => $this->daysAgo( 1 ), 'source' => 'manual' ) );
        $this->assertSame( 'won', $r['stage'] );
        $this->assertTrue( $r['manual_won_pending'] );
    }

    public function test_newer_fact_pushes_up_but_never_down(): void {
        // Moved to "quote" 5 days ago, paid order 1 day ago ⇒ won.
        $up = $this->resolve( array( 'paid' => 1, 'first_paid_ts' => $this->daysAgo( 1 ), 'last_order_ts' => $this->daysAgo( 1 ) ), array( 'stage' => 'quote', 'at_ts' => $this->daysAgo( 5 ), 'source' => 'manual' ) );
        $this->assertSame( 'won', $up['stage'] );
        $this->assertSame( 'auto', $up['source'] );
        // Moved by hand to "consult" AFTER a message: a newer outgoing message (lower stage) does not pull it down.
        $keep = $this->resolve( array( 'first_out_ts' => $this->daysAgo( 1 ), 'last_out_ts' => $this->daysAgo( 1 ) ), array( 'stage' => 'consult', 'at_ts' => $this->daysAgo( 3 ), 'source' => 'manual' ) );
        $this->assertSame( 'consult', $keep['stage'] );
        // Manual "won" then the Woo order arrives later ⇒ still won, now backed by the order.
        $attached = $this->resolve( array( 'paid' => 1, 'first_paid_ts' => $this->daysAgo( 1 ), 'last_order_ts' => $this->daysAgo( 1 ) ), array( 'stage' => 'won', 'at_ts' => $this->daysAgo( 3 ), 'source' => 'manual' ) );
        $this->assertSame( 'won', $attached['stage'] );
        $this->assertFalse( $attached['manual_won_pending'] );
    }

    public function test_lost_needs_time_to_return_and_an_order_revives_it(): void {
        $lost = $this->resolve( array( 'first_out_ts' => $this->daysAgo( 20 ) ), array( 'stage' => 'lost', 'at_ts' => $this->daysAgo( 10 ), 'source' => 'manual' ) );
        $this->assertSame( 'lost', $lost['stage'] );
        $back = $this->resolve( array( 'first_out_ts' => $this->daysAgo( 200 ) ), array( 'stage' => 'lost', 'at_ts' => $this->daysAgo( 120 ), 'source' => 'manual' ) );
        $this->assertSame( 'target', $back['stage'] );
        $bought = $this->resolve( array( 'paid' => 1, 'first_paid_ts' => $this->daysAgo( 2 ), 'last_order_ts' => $this->daysAgo( 2 ) ), array( 'stage' => 'lost', 'at_ts' => $this->daysAgo( 10 ), 'source' => 'manual' ) );
        $this->assertSame( 'won', $bought['stage'] );
    }

    public function test_dormant_flag_after_silence(): void {
        $r = $this->resolve( array( 'paid' => 1, 'first_paid_ts' => $this->daysAgo( 50 ), 'last_order_ts' => $this->daysAgo( 50 ), 'last_activity_ts' => $this->daysAgo( 45 ) ) );
        $this->assertSame( 'dormant', $r['stage'] );
        $this->assertSame( 'won', $r['base_stage'] );
        $this->assertTrue( $r['dormant'] );
    }

    public function test_stuck_uses_the_three_day_default_and_can_be_switched_off(): void {
        $r = $this->resolve( array( 'first_out_ts' => $this->daysAgo( 10 ) ), array( 'stage' => 'quote', 'at_ts' => $this->daysAgo( 3 ), 'source' => 'manual' ) );
        $this->assertTrue( $r['stuck'] );
        $s = $this->settings();
        $s['stuck_days']['quote'] = 0;
        $off = BizCity_CRM_Customer_Pipeline::resolve( array( 'created_ts' => $this->daysAgo( 40 ) ), array( 'stage' => 'quote', 'at_ts' => $this->daysAgo( 9 ), 'source' => 'manual' ), self::NOW, $s );
        $this->assertFalse( $off['stuck'] );
        $this->assertFalse( $this->resolve( array( 'paid' => 1, 'first_paid_ts' => $this->daysAgo( 9 ), 'last_order_ts' => $this->daysAgo( 9 ) ) )['stuck'], 'won is never stuck' );
    }

    public function test_opportunity_mapping_ignores_auto_synced_early_deals(): void {
        $flagged = BizCity_CRM_Customer_Pipeline::pipe_from_opportunity( array( 'id' => 5, 'stage' => 'proposal', 'status' => 'open', 'custom_json' => json_encode( array( 'pipeline' => true, 'pipeline_stage' => 'quote', 'stage_at' => 100, 'source' => 'outcome', 'steps' => array( 'quote' => array( true ) ) ) ), 'updated_at' => '2026-09-01 00:00:00' ) );
        $this->assertSame( 'quote', $flagged['stage'] );
        $this->assertSame( 100, $flagged['at_ts'] );
        $this->assertFalse( $flagged['legacy'] );
        $synced = BizCity_CRM_Customer_Pipeline::pipe_from_opportunity( array( 'id' => 6, 'stage' => 'qualification', 'status' => 'open', 'source' => 'zalo_personal', 'custom_json' => '{}', 'updated_at' => '2026-09-01 00:00:00' ) );
        $this->assertNull( $synced, 'Pipeline_Sync "has phone" deals must not put customers in Đang tư vấn' );
        $human = BizCity_CRM_Customer_Pipeline::pipe_from_opportunity( array( 'id' => 7, 'stage' => 'negotiation', 'status' => 'open', 'source' => '', 'custom_json' => '', 'updated_at' => '2026-09-01 00:00:00' ) );
        $this->assertSame( 'quote', $human['stage'] );
        $this->assertTrue( $human['legacy'] );
        $lost = BizCity_CRM_Customer_Pipeline::pipe_from_opportunity( array( 'id' => 8, 'stage' => 'qualification', 'status' => 'lost', 'source' => 'woo', 'custom_json' => '', 'updated_at' => '2026-09-01 00:00:00' ) );
        $this->assertSame( 'lost', $lost['stage'] );
    }

    public function test_settings_are_bounded(): void {
        $s = BizCity_CRM_Customer_Pipeline::sanitize_settings( array( 'stuck_days' => array( 'quote' => 999, 'target' => -4 ), 'steps' => array( 'quote' => array( 'A', '', 'B', 'C', 'D', 'E', 'F', 'G' ) ), 'lost_reasons' => array( 'Giá', 'Giá', '' ) ) );
        $this->assertSame( 365, $s['stuck_days']['quote'] );
        $this->assertSame( 0, $s['stuck_days']['target'] );
        $this->assertSame( 3, $s['stuck_days']['consult'] );
        $this->assertCount( 6, $s['steps']['quote'] );
        $this->assertSame( array( 'Giá' ), $s['lost_reasons'] );
    }

    public function test_segments(): void {
        $row = static function ( array $o ) { return $o + array( 'stage' => 'target', 'days' => 0, 'stuck' => false, 'has_next' => true ); };
        $this->assertTrue( BizCity_CRM_Pipeline_REST::in_segment( 'quote_stuck', $row( array( 'stage' => 'quote', 'stuck' => true ) ) ) );
        $this->assertFalse( BizCity_CRM_Pipeline_REST::in_segment( 'quote_stuck', $row( array( 'stage' => 'quote' ) ) ) );
        $this->assertTrue( BizCity_CRM_Pipeline_REST::in_segment( 'no_next', $row( array( 'stage' => 'consult', 'has_next' => false ) ) ) );
        $this->assertFalse( BizCity_CRM_Pipeline_REST::in_segment( 'no_next', $row( array( 'stage' => 'won', 'has_next' => false ) ) ) );
        $this->assertTrue( BizCity_CRM_Pipeline_REST::in_segment( 'won_d3', $row( array( 'stage' => 'won', 'days' => 3 ) ) ) );
    }
}
