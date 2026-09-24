<?php
/**
 * PHASE-1.30-FAIL-CLOSED — pure rules behind probe core.legacy_table.install_absence.
 *
 * Pins the incident found on 2026-09-18: nine owner installers used a fail-open
 * guard (`! class_exists(Policy) || ! install_blocked()` or
 * `class_exists(Policy) && install_blocked() → return`), so a retired table was
 * recreated whenever the policy class was not loaded. Local evidence:
 * bizcity_twin_context_logs created 2026-09-17 after its 2026-09-01 retirement.
 *
 * No WordPress, no DB: the rules class is declared before the probe-interface guard.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/core/diagnostics/includes/probes/class-probe-legacy-table-install-absence.php';

class LegacyInstallAbsenceRulesTest extends TestCase {

    /** @return array<string,array{0:string,1:bool}> */
    public function guardProvider(): array {
        return array(
            'pre-fix || install when class missing' => array(
                "if ( ! class_exists( 'BizCity_Legacy_Table_Policy' ) || ! BizCity_Legacy_Table_Policy::install_blocked( \$t ) ) { dbDelta( \$sql ); }",
                true,
            ),
            'pre-fix && early return only when class exists' => array(
                "if ( class_exists( 'BizCity_Legacy_Table_Policy' ) && BizCity_Legacy_Table_Policy::install_blocked( \$t ) ) { return; }",
                true,
            ),
            'pre-fix multiline flag' => array(
                "\$retired = class_exists( 'BizCity_Legacy_Table_Policy' )\n    && BizCity_Legacy_Table_Policy::install_blocked( \$t );",
                true,
            ),
            'fixed || skip when class missing' => array(
                "if ( ! class_exists( 'BizCity_Legacy_Table_Policy' ) || BizCity_Legacy_Table_Policy::install_blocked( \$t ) ) { return; }",
                false,
            ),
            'fixed && install only when policy allows' => array(
                "if ( class_exists( 'BizCity_Legacy_Table_Policy' ) && ! BizCity_Legacy_Table_Policy::install_blocked( \$t ) ) { dbDelta( \$sql ); }",
                false,
            ),
            'fail-open shape inside a comment is ignored' => array(
                "// if ( class_exists( 'BizCity_Legacy_Table_Policy' ) && BizCity_Legacy_Table_Policy::install_blocked( \$t ) ) {\n/* ! class_exists( 'BizCity_Legacy_Table_Policy' ) || ! BizCity_Legacy_Table_Policy::install_blocked( */\n\$ok = 1;",
                false,
            ),
        );
    }

    /** @dataProvider guardProvider */
    public function test_fail_open_guard_detection( string $code, bool $expected ): void {
        $this->assertSame( $expected, BizCity_Legacy_Install_Absence_Rules::has_fail_open_guard( $code ) );
    }

    public function test_deployed_owner_installers_are_fail_closed(): void {
        $root  = dirname( __DIR__, 2 );
        $files = array(
            'core/twin-core/includes/class-twin-state-schema.php',
            'core/knowledge/includes/class-database.php',
            'core/knowledge/includes/class-user-memory.php',
            // [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-01 K-07 —
            // `core/knowledge/includes/class-skill-database.php` was retired under
            // R-ORPHAN-FILE. It was a duplicate declaration of BizCity_Skill_Database that no
            // bootstrap required, and the only deployed installer for the retired table
            // `bizcity_skill_logs`. The canonical owner, core/skills/includes/class-skill-database.php,
            // is deliberately NOT added here: it creates `bizcity_skills` only, which is an
            // active table, so the fail-closed rule does not apply to it.
            'core/knowledge/kg-hub/includes/class-kg-cleanup-service.php',
            'core/intent/includes/conversation/class-rolling-memory.php',
            'core/intent/includes/conversation/class-episodic-memory.php',
            'core/intent/includes/orchestration/class-intent-engine.php',
            'core/intent/includes/infrastructure/class-intent-logger.php',
            'modules/webchat/includes/class-webchat-database.php',
        );
        foreach ( $files as $relative ) {
            $path = $root . '/' . $relative;
            $this->assertFileExists( $path );
            $this->assertFalse(
                BizCity_Legacy_Install_Absence_Rules::has_fail_open_guard( (string) file_get_contents( $path ) ),
                $relative . ' must not install a retired table when BizCity_Legacy_Table_Policy is missing.'
            );
        }
    }

    public function test_classification_of_physical_tables(): void {
        $rules = 'BizCity_Legacy_Install_Absence_Rules';
        $this->assertSame( $rules::ABSENT, $rules::classify( false, '', '' ) );
        $this->assertSame( $rules::RETAINED, $rules::classify( true, '2026-05-01 10:00:00', '' ) );
        // The 2026-09-17 incident: created after retirement, attributable only with a baseline.
        $this->assertSame( $rules::RECREATED_UNKNOWN, $rules::classify( true, '2026-09-17 09:18:01', '' ) );
        $this->assertSame( $rules::RECREATED_UNKNOWN, $rules::classify( true, '2026-09-17 09:18:01', '2026-09-18 22:30:00' ) );
        $this->assertSame( $rules::RECREATED_AFTER_FIX, $rules::classify( true, '2026-09-17 09:18:01', '2026-09-17 00:00:00' ) );
        // Unknown CREATE_TIME is never treated as safe.
        $this->assertSame( $rules::RECREATED_UNKNOWN, $rules::classify( true, '', '2026-09-17 00:00:00' ) );
    }

    public function test_step_status_is_never_pass_for_a_recreated_table(): void {
        $rules = 'BizCity_Legacy_Install_Absence_Rules';
        $this->assertSame( 'pass', $rules::step_status( $rules::ABSENT ) );
        $this->assertSame( 'info', $rules::step_status( $rules::RETAINED ) );
        $this->assertSame( 'warn', $rules::step_status( $rules::RECREATED_UNKNOWN ) );
        $this->assertSame( 'fail', $rules::step_status( $rules::RECREATED_AFTER_FIX ) );
    }

    public function test_baseline_normalization(): void {
        $rules = 'BizCity_Legacy_Install_Absence_Rules';
        $this->assertSame( '2026-09-18 22:30:00', $rules::normalize_baseline( '2026-09-18 22:30:00' ) );
        $this->assertSame( '2026-09-18 22:30:00', $rules::normalize_baseline( ' 2026-09-18 22:30 ' ) );
        $this->assertSame( '', $rules::normalize_baseline( '' ) );
        $this->assertSame( '', $rules::normalize_baseline( 'not a date' ) );
    }
}
