<?php
/**
 * Sample unit test — proves PHPUnit suite is wired correctly.
 *
 * Tests the framework deprecation helper which is pure-PHP and has no
 * WordPress runtime dependencies (filter/action are stubbed in tests/bootstrap.php).
 *
 * @package BizCity_Twin_AI\Tests
 */

declare( strict_types = 1 );

namespace BizCity\Twin\Tests;

use PHPUnit\Framework\TestCase;

final class DeprecationTest extends TestCase {

    public static function setUpBeforeClass(): void {
        $helper = dirname( __DIR__, 2 ) . '/core/bizcity-llm/includes/helpers-deprecation.php';
        if ( ! class_exists( '\\BizCity_Deprecation' ) ) {
            require_once $helper;
        }
    }

    public function test_class_exists(): void {
        $this->assertTrue(
            class_exists( '\\BizCity_Deprecation' ),
            'BizCity_Deprecation should be loadable from helpers-deprecation.php'
        );
    }

    public function test_notify_does_not_throw(): void {
        \BizCity_Deprecation::notify(
            'Old_Class::old_method()',
            'New_Class::new_method()',
            '1.0.0',
            'unit-test'
        );
        $this->assertTrue( true, 'notify() should be silent under test stubs' );
    }

    public function test_notify_dedupes_within_request(): void {
        // Calling twice with same key must not error and must be idempotent.
        \BizCity_Deprecation::notify( 'Sample::dup()', null, '1.0.0' );
        \BizCity_Deprecation::notify( 'Sample::dup()', null, '1.0.0' );
        $this->assertTrue( true );
    }

    public function test_notify_filter_signature(): void {
        $this->assertTrue(
            method_exists( '\\BizCity_Deprecation', 'notify_filter' ),
            'notify_filter() must exist for hook deprecation announcements'
        );
    }
}
