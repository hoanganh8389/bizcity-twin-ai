<?php
/**
 * BizCity REST Error trait — unified WP_Error builder with fix-hint payload.
 *
 * Use in any REST controller to emit error responses that the FE
 * `humanizeError()` helper can render with the 3 standard CTAs (Why? · Fix ·
 * Report). Payload shape:
 *
 *   WP_Error( $code, $message, [
 *       'status'    => int,
 *       'fix'       => [ 'url' => string, 'label' => string, 'kind' => string ],
 *       'ctx'       => array (optional structured context),
 *       'reportable'=> bool (default true),
 *   ] )
 *
 * `fix` is auto-populated from `BizCity_Error_Reporter::suggest_fix()`. Pass
 * a custom array via the 4th argument to override.
 *
 * Common shortcuts:
 *   - $this->err_forbidden( $code, $msg, $ctx )       → 403
 *   - $this->err_not_found( $code, $msg, $ctx )       → 404
 *   - $this->err_validation( $code, $msg, $ctx )      → 422
 *   - $this->err_quota( $code, $msg, $ctx )           → 402  (auto-record critical)
 *   - $this->err_table_missing( $table, $module )     → 503  (auto-record critical)
 *   - $this->err_server( $code, $msg, $ctx )          → 500
 *
 * Recording: pass `record=true` (default for critical helpers) to also
 * fire `BizCity_Error_Reporter::record()` so the error surfaces in the
 * admin Diagnostics → Error Reports tab even if FE telemetry never fires.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21  (PHASE-0.41 Lát 3)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( trait_exists( 'BizCity_REST_Error' ) ) {
	return;
}

trait BizCity_REST_Error {

	/**
	 * Build a WP_Error with the unified payload shape.
	 *
	 * @param string     $code     Canonical error code (snake_case).
	 * @param string     $message  Human-readable message (Vietnamese OK).
	 * @param int        $status   HTTP status.
	 * @param array      $ctx      Structured context (sanitized before send).
	 * @param array|null $fix      Optional override of the suggest_fix() output.
	 * @param bool       $record   When true, also persist via Error_Reporter.
	 * @return WP_Error
	 */
	protected function err( string $code, string $message, int $status = 400, array $ctx = [], $fix = null, bool $record = false ): WP_Error {
		if ( $fix === null && class_exists( 'BizCity_Error_Reporter' ) ) {
			$fix = BizCity_Error_Reporter::suggest_fix( $code );
		}
		$data = [
			'status'     => $status,
			'fix'        => is_array( $fix ) ? $fix : [],
			'ctx'        => $ctx,
			'reportable' => $status >= 500 || $code === 'table_missing' || $code === 'database_unavailable',
		];

		if ( $record && class_exists( 'BizCity_Error_Reporter' ) ) {
			BizCity_Error_Reporter::record( [
				'code'        => $code,
				'module'      => $this->rest_error_module(),
				'http_status' => $status,
				'title'       => $message,
				'detail'      => $message,
				'context'     => $ctx,
				'source'      => 'be',
			] );
		}

		return new WP_Error( $code, $message, $data );
	}

	/** 401 — unauthenticated. */
	protected function err_unauthorized( string $code, string $message, array $ctx = [] ): WP_Error {
		return $this->err( $code, $message, 401, $ctx );
	}

	/** 403 — authenticated but not allowed. */
	protected function err_forbidden( string $code, string $message, array $ctx = [] ): WP_Error {
		return $this->err( $code, $message, 403, $ctx );
	}

	/** 404 — resource missing (logical, not table). */
	protected function err_not_found( string $code, string $message, array $ctx = [] ): WP_Error {
		return $this->err( $code, $message, 404, $ctx );
	}

	/** 422 — validation / user-fixable input issue. */
	protected function err_validation( string $code, string $message, array $ctx = [] ): WP_Error {
		return $this->err( $code, $message, 422, $ctx );
	}

	/** 402 — quota / tier gate. Records to telemetry (critical). */
	protected function err_quota( string $code, string $message, array $ctx = [] ): WP_Error {
		return $this->err( $code, $message, 402, $ctx, null, true );
	}

	/** 429 — rate limited. */
	protected function err_rate_limited( string $code, string $message, array $ctx = [] ): WP_Error {
		return $this->err( $code, $message, 429, $ctx );
	}

	/** 500 — unexpected server fail. Records to telemetry. */
	protected function err_server( string $code, string $message, array $ctx = [] ): WP_Error {
		return $this->err( $code, $message, 500, $ctx, null, true );
	}

	/**
	 * 503 — module-DB not provisioned. Always recorded as critical so admin
	 * gets emailed + the row appears in Error Reports tab even when FE never
	 * forwarded telemetry (e.g. cron / webhook callers).
	 */
	protected function err_table_missing( string $table, string $module = '', string $message = '' ): WP_Error {
		$ctx = [ 'table' => $table, 'module' => $module ?: $this->rest_error_module() ];
		if ( $message === '' ) {
			$message = sprintf(
				'Bảng %s chưa được tạo trên blog này. Bấm "Tự sửa" để Provisioner cài lại.',
				$table
			);
		}
		// Fire soft-guard notice too so the admin banner shows up.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_diagnostics_notice', $module ?: $this->rest_error_module(), [
				'table'   => $table,
				'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			] );
		}
		return $this->err( 'table_missing', $message, 503, $ctx, null, true );
	}

	/**
	 * Hook for the using class to identify itself in telemetry. Override to
	 * return a stable string like "research.rest" or "twinbrain.stream".
	 */
	protected function rest_error_module(): string {
		return static::class;
	}
}
