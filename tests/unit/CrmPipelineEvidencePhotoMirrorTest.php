<?php
/**
 * PHASE-0.69 §4.5/L-08 — the pure decision logic behind mirroring an evidence photo out of a provider's
 * CDN into WP Media: which document types qualify, and which paths are "still remote" vs. "already local".
 * The actual network download (`download_url()`/`media_handle_sideload()`) needs a real WordPress install
 * and network access, neither available here — that half is exercised by hand on a real site, not by this
 * test. What IS covered, and is exactly the part most likely to silently regress, is the classification
 * that decides whether to even attempt it.
 *
 * Private static methods on `BizCity_CRM_Pipeline_Run_Service`, reached via reflection — no test in this
 * suite needs a public API for this, and adding one just for testability would be scope creep.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/pipeline/class-pipeline-run-service.php';

final class CrmPipelineEvidencePhotoMirrorTest extends TestCase {

	private function call( string $method, array $args ) {
		$reflection = new ReflectionMethod( BizCity_CRM_Pipeline_Run_Service::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	public function test_photo_and_image_qualify_but_other_evidence_types_do_not(): void {
		$this->assertTrue( $this->call( 'is_evidence_photo_type', array( 'photo' ) ) );
		$this->assertTrue( $this->call( 'is_evidence_photo_type', array( 'IMAGE' ) ) );
		$this->assertFalse( $this->call( 'is_evidence_photo_type', array( 'file' ) ) );
		$this->assertFalse( $this->call( 'is_evidence_photo_type', array( 'signature' ) ) );
		$this->assertFalse( $this->call( 'is_evidence_photo_type', array( 'note' ) ) );
	}

	public function test_a_zalo_cdn_link_is_remote(): void {
		$this->assertTrue( $this->call( 'is_remote_evidence_url', array( 'https://zalo-cdn.example.com/photos/abc.jpg' ) ) );
	}

	public function test_a_relative_or_non_http_path_is_not_treated_as_remote(): void {
		// Not `https?://` at all — this method only classifies "still needs mirroring", not "is valid".
		$this->assertFalse( $this->call( 'is_remote_evidence_url', array( '/wp-content/uploads/2026/09/abc.jpg' ) ) );
		$this->assertFalse( $this->call( 'is_remote_evidence_url', array( '' ) ) );
	}

	public function test_safe_default_when_the_uploads_base_cannot_be_determined(): void {
		// This test process's shared `wp_upload_dir()` stub (`BusinessJsonlFileStoreTest.php`, loaded
		// first alphabetically for the whole suite) returns no `baseurl` at all — the exact situation the
		// production code's `'' === $upload_base` branch exists for. The safe default must be "treat it as
		// remote, attempt the mirror" (worst case: one redundant download), never "assume it's already
		// local and skip" (worst case: the photo silently never gets mirrored, exactly the bug this phase
		// fixes). This is also, incidentally, the one case this sandboxed suite CAN prove deterministically
		// — the true "already-local, skip" branch needs a real `baseurl`, which needs a real WordPress
		// install this test environment does not have.
		$this->assertSame( '', (string) ( function_exists( 'wp_upload_dir' ) ? ( wp_upload_dir()['baseurl'] ?? '' ) : '' ), 'precondition: no baseurl available in this test process' );
		$this->assertTrue( $this->call( 'is_remote_evidence_url', array( 'https://zalo-cdn.example.com/photos/abc.jpg' ) ) );
	}
}
