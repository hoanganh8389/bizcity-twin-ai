<?php
/**
 * PHASE-0.50 C-04 (part 2) — rules behind the per-employee rollups (§5.4).
 *
 * Pure functions only; the projector, reader and backfill need WordPress, Woo and
 * the reporting tables and are verified on a real site.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-staff-metrics.php';

final class CrmStaffMetricsTest extends TestCase {

    public function test_confirmed_and_estimated_revenue_never_share_a_metric(): void {
        foreach ( array( 'assignee_at_create', 'creator_fallback', 'backfill_event' ) as $confirmed ) {
            $this->assertSame( 'orders_attributed', BizCity_CRM_Staff_Metrics::order_metric( $confirmed ) );
        }
        $this->assertSame( 'orders_estimated', BizCity_CRM_Staff_Metrics::order_metric( 'backfill_current' ) );
        // Web checkout / unattributed orders never count toward an employee (0.48F §3.4 rule 5).
        $this->assertSame( '', BizCity_CRM_Staff_Metrics::order_metric( '' ) );
        $this->assertSame( '', BizCity_CRM_Staff_Metrics::order_metric( 'unattributable' ) );
    }

    /**
     * @return array<string,array{0:string,1:?string,2:string,3:array<int,string>}>
     */
    public function transitions(): array {
        return array(
            'handoff created'           => array( 'sent', '2026-09-20', '', array( 'tasks_assigned' ) ),
            'accepted is not a fact'    => array( 'accepted', '2026-09-20', '', array() ),
            'returned is not done'      => array( 'returned', '2026-09-20', '2026-09-19 10:00:00', array() ),
            'done without due date'     => array( 'done', null, '2026-09-19 10:00:00', array( 'tasks_done' ) ),
            'done before due'           => array( 'done', '2026-09-20', '2026-09-19 10:00:00', array( 'tasks_done', 'tasks_done_with_due', 'tasks_done_on_time' ) ),
            'done on the due day'       => array( 'done', '2026-09-20', '2026-09-20 23:59:00', array( 'tasks_done', 'tasks_done_with_due', 'tasks_done_on_time' ) ),
            'done late'                 => array( 'done', '2026-09-20', '2026-09-21 08:00:00', array( 'tasks_done', 'tasks_done_with_due' ) ),
            'zero date is no due date'  => array( 'done', '0000-00-00', '2026-09-21 08:00:00', array( 'tasks_done' ) ),
        );
    }

    /**
     * @dataProvider transitions
     */
    public function test_task_metrics( string $to, ?string $due, string $done_at, array $expected ): void {
        $this->assertSame( $expected, BizCity_CRM_Staff_Metrics::task_metrics( $to, $due, $done_at ) );
    }

    public function test_summary_keeps_estimates_apart_and_hides_small_rate_samples(): void {
        $summary = BizCity_CRM_Staff_Metrics::summarize( array(
            'orders_attributed'   => array( 'count' => 14, 'sum' => 42500000.0 ),
            'orders_estimated'    => array( 'count' => 2, 'sum' => 3900000.0 ),
            'tasks_assigned'      => array( 'count' => 9, 'sum' => 9.0 ),
            'tasks_done'          => array( 'count' => 6, 'sum' => 6.0 ),
            'tasks_done_with_due' => array( 'count' => 4, 'sum' => 4.0 ),
            'tasks_done_on_time'  => array( 'count' => 3, 'sum' => 3.0 ),
        ) );

        $this->assertSame( array( 'count' => 14, 'revenue' => 42500000.0 ), $summary['orders'] );
        $this->assertSame( array( 'count' => 2, 'revenue' => 3900000.0 ), $summary['orders_estimated'] );
        $this->assertSame( 9, $summary['tasks']['assigned'] );
        $this->assertNull( $summary['tasks']['on_time_rate'], 'A rate over fewer than 5 tasks is hidden, not shown as 75%.' );
    }

    public function test_summary_publishes_rate_with_its_basis(): void {
        $summary = BizCity_CRM_Staff_Metrics::summarize( array(
            'tasks_done_with_due' => array( 'count' => 8, 'sum' => 8.0 ),
            'tasks_done_on_time'  => array( 'count' => 6, 'sum' => 6.0 ),
        ) );

        $this->assertSame( array( 'num' => 6, 'den' => 8, 'value' => 75.0 ), $summary['tasks']['on_time_rate'] );
        $this->assertSame( array( 'count' => 0, 'revenue' => 0.0 ), $summary['orders'] );
    }
}
