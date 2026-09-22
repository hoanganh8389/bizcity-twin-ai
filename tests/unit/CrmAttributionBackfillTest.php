<?php
/**
 * PHASE-0.50 C-04 — D4 attribution backfill decision (0.48F §3.4).
 *
 * Only the pure decision is unit-tested here; the batch reads Woo orders and the
 * reporting events table and is verified on a real site (dry-run first).
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-attribution-backfill.php';

final class CrmAttributionBackfillTest extends TestCase {

    /**
     * @return array<string,array{0:bool,1:?int,2:int,3:string,4:int}>
     */
    public function decisions(): array {
        return array(
            // A fact at/before order time is the truth, even if someone else owns the conversation today (rule 4).
            'fact names an assignee'                 => array( true, 42, 57, BizCity_CRM_Attribution_Backfill::OUTCOME_EVENT, 42 ),
            'fact names an assignee, none today'     => array( true, 42, 0, BizCity_CRM_Attribution_Backfill::OUTCOME_EVENT, 42 ),
            // Unassigned at that moment: legacy orders have no creator, so do not guess.
            'fact says unassigned'                   => array( true, null, 57, BizCity_CRM_Attribution_Backfill::OUTCOME_UNATTRIBUTABLE, 0 ),
            'fact says assignee 0'                   => array( true, 0, 57, BizCity_CRM_Attribution_Backfill::OUTCOME_UNATTRIBUTABLE, 0 ),
            // No fact at all: current assignee, flagged as an estimate.
            'no fact, current assignee'              => array( false, null, 57, BizCity_CRM_Attribution_Backfill::OUTCOME_CURRENT, 57 ),
            'no fact, nobody assigned'               => array( false, null, 0, BizCity_CRM_Attribution_Backfill::OUTCOME_UNATTRIBUTABLE, 0 ),
        );
    }

    /**
     * @dataProvider decisions
     */
    public function test_decide( bool $event_found, ?int $event_assignee, int $current, string $outcome, int $assignee ): void {
        $decision = BizCity_CRM_Attribution_Backfill::decide( $event_found, $event_assignee, $current );

        $this->assertSame( $outcome, $decision['outcome'] );
        $this->assertSame( $assignee, $decision['assignee_id'] );
    }

    public function test_written_outcomes_stay_inside_the_public_attribution_vocabulary(): void {
        // customer-360-team-view@1.0.0 `orders.items[].attribution` enum; `unattributable` is marker-only, never written as attribution.
        $public = array( 'assignee_at_create', 'creator_fallback', 'backfill_event', 'backfill_current' );
        $this->assertContains( BizCity_CRM_Attribution_Backfill::OUTCOME_EVENT, $public );
        $this->assertContains( BizCity_CRM_Attribution_Backfill::OUTCOME_CURRENT, $public );
        $this->assertNotContains( BizCity_CRM_Attribution_Backfill::OUTCOME_UNATTRIBUTABLE, $public );
    }
}
