<?php
/**
 * Phase 0.13 / Vòng 3 — Sprint 6
 * Admin page wrappers for tests/run-triage-test.php and a tiny SSE/polling
 * reconnect demo. Mounts under Tools → "Twin Triage Test" + "Twin SSE Demo".
 *
 * Loaded by bizcity-twin-ai.php only when WP_DEBUG is true OR the constant
 * BIZCITY_TWIN_ENABLE_TESTS is defined.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	if ( ! current_user_can( 'manage_options' ) ) return;

	add_management_page(
		'Twin Triage Test',
		'Twin Triage Test',
		'manage_options',
		'bizcity-twin-triage-test',
		'bizcity_twin_triage_test_page'
	);

	add_management_page(
		'Twin SSE/Polling Demo',
		'Twin SSE/Polling Demo',
		'manage_options',
		'bizcity-twin-sse-demo',
		'bizcity_twin_sse_demo_page'
	);

	// Sit under Intent Monitor when present, fallback to Tools menu.
	$intent_parent = class_exists( 'BizCity_Intent_Monitor', false ) ? 'bizcity-intent-monitor' : null;
	if ( $intent_parent ) {
		add_submenu_page(
			$intent_parent,
			'Intent Pre-Rules Test',
			'Intent Shell — Pre-Rules Test',
			'manage_options',
			'bizcity-intent-pre-rules-test',
			'bizcity_intent_pre_rules_test_page'
		);
	} else {
		add_management_page(
			'Intent Pre-Rules Test',
			'Intent Pre-Rules Test',
			'manage_options',
			'bizcity-intent-pre-rules-test',
			'bizcity_intent_pre_rules_test_page'
		);
	}
}, 12 );

function bizcity_twin_triage_test_page(): void {
	echo '<div class="wrap"><h1>Twin Root — Triage Accuracy Test</h1>';
	echo '<p>Target: <strong>≥ 80%</strong> trên 20 prompt. Mỗi lần chạy ≈ 20 LLM call (tốn token).</p>';

	$run = isset( $_GET['run'] ) && $_GET['run'] === '1'
		&& isset( $_GET['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bizcity_twin_triage_run' );

	$run_url = wp_nonce_url(
		admin_url( 'tools.php?page=bizcity-twin-triage-test&run=1' ),
		'bizcity_twin_triage_run'
	);

	printf(
		'<p><a href="%s" class="button button-primary">▶ Run 20-prompt fixture</a></p>',
		esc_url( $run_url )
	);

	if ( $run ) {
		echo '<h2>Live output</h2><pre style="background:#111;color:#0f0;padding:12px;max-height:600px;overflow:auto;">';
		require __DIR__ . '/run-triage-test.php';
		echo '</pre>';
	}

	$last = get_option( 'bizcity_twin_triage_last_report', null );
	if ( is_array( $last ) ) {
		echo '<h2>Last report</h2>';
		printf(
			'<p>Ran at: <code>%s</code> · Score: <strong>%d/%d (%.1f%%)</strong></p>',
			esc_html( $last['ran_at'] ?? '?' ),
			(int) ( $last['correct'] ?? 0 ),
			(int) ( $last['total'] ?? 0 ),
			(float) ( $last['pct'] ?? 0 )
		);
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Prompt</th><th>Expect</th><th>Got</th><th>OK?</th><th>ms</th></tr></thead><tbody>';
		foreach ( ( $last['cases'] ?? [] ) as $i => $c ) {
			printf(
				'<tr><td>%d</td><td>%s</td><td><code>%s</code></td><td><code>%s</code></td><td>%s</td><td>%d</td></tr>',
				$i + 1,
				esc_html( (string) ( $c['prompt'] ?? '' ) ),
				esc_html( (string) ( $c['expected'] ?? '' ) ),
				esc_html( (string) ( $c['picked'] ?? '(none)' ) ),
				! empty( $c['ok'] ) ? '✅' : '❌',
				(int) ( $c['ms'] ?? 0 )
			);
		}
		echo '</tbody></table>';
	}

	echo '</div>';
}

function bizcity_twin_sse_demo_page(): void {
	$rest_root = esc_url_raw( rest_url( 'bizcity-twin/v1/' ) );
	$nonce     = wp_create_nonce( 'wp_rest' );
	?>
	<div class="wrap">
		<h1>Twin Polling — Disconnect/Reconnect Demo (Vòng 3 Deliverable #3)</h1>
		<p>Demo verify <code>useTwinAgentStream</code> backoff khi mạng lỗi, resume từ <code>?since=N</code>.</p>
		<ol>
			<li>Click <strong>Start mindmap run</strong> — sinh 1 sub-run dài (HIL pause).</li>
			<li>Mở DevTools → Network → throttle <em>Offline</em> 5–10s rồi <em>Online</em> lại.</li>
			<li>Quan sát log bên dưới: <code>fetch error</code> → backoff → <code>resumed</code> → events tiếp tục với <code>seq</code> liên tục (không nhảy số).</li>
		</ol>

		<p>
			<button id="bc-sse-start" class="button button-primary">▶ Start mindmap run</button>
			<button id="bc-sse-clear" class="button">Clear log</button>
		</p>

		<pre id="bc-sse-log" style="background:#111;color:#0f0;padding:12px;height:480px;overflow:auto;font-size:12px;"></pre>

		<script>
		(function () {
			const REST  = <?php echo wp_json_encode( $rest_root ); ?>;
			const NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			const $log  = document.getElementById( 'bc-sse-log' );
			const $btn  = document.getElementById( 'bc-sse-start' );
			const $clr  = document.getElementById( 'bc-sse-clear' );

			function log( msg, color ) {
				const ts = new Date().toLocaleTimeString();
				const line = document.createElement( 'div' );
				if ( color ) line.style.color = color;
				line.textContent = '[' + ts + '] ' + msg;
				$log.appendChild( line );
				$log.scrollTop = $log.scrollHeight;
			}

			$clr.onclick = () => { $log.textContent = ''; };

			$btn.onclick = async () => {
				$btn.disabled = true;
				log( 'POST /run agent_name=mindmap', '#9cf' );
				try {
					const res = await fetch( REST + 'run', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': NONCE,
						},
						credentials: 'same-origin',
						body: JSON.stringify( {
							agent_name: 'mindmap',
							messages: [ { role: 'user', content: 'Vẽ mindmap về dinh dưỡng cho học sinh' } ],
						} ),
					} );
					const data = await res.json();
					log( 'run_id=' + data.run_id + '  status=' + data.status, '#9cf' );
					if ( data.run_id ) {
						pollLoop( data.run_id );
					} else {
						log( 'no run_id in response: ' + JSON.stringify( data ), '#f66' );
						$btn.disabled = false;
					}
				} catch ( e ) {
					log( 'POST /run failed: ' + e.message, '#f66' );
					$btn.disabled = false;
				}
			};

			async function pollLoop( runId ) {
				let since = 0;
				let backoff = 1500;
				const MAX_BACKOFF = 8000;
				const TERMINAL = new Set( [ 'final', 'failed' ] );
				let stopped = false;
				let pollCount = 0;

				while ( ! stopped ) {
					pollCount++;
					try {
						const res = await fetch( REST + 'events/' + encodeURIComponent( runId ) + '?since=' + since, {
							method: 'GET',
							headers: { 'X-WP-Nonce': NONCE },
							credentials: 'same-origin',
						} );
						if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
						const data = await res.json();
						if ( data.next_since > since ) {
							since = data.next_since;
						}
						for ( const ev of data.events ) {
							log( '  seq=' + ev.seq + ' type=' + ev.event_type, '#0f0' );
							if ( TERMINAL.has( ev.event_type ) ) {
								stopped = true;
								log( 'Terminal event reached. Stop.', '#9cf' );
							}
						}
						backoff = 1500;
					} catch ( e ) {
						backoff = Math.min( backoff * 2, MAX_BACKOFF );
						log( 'fetch error #' + pollCount + ' → backoff ' + backoff + 'ms (' + e.message + ')', '#fa0' );
					}
					if ( ! stopped ) {
						await new Promise( ( r ) => setTimeout( r, backoff ) );
					}
				}
				$btn.disabled = false;
			}
		})();
		</script>
	</div>
	<?php
}

function bizcity_intent_pre_rules_test_page(): void {
	echo '<div class="wrap"><h1>Intent Shell — Pre-Rules Test</h1>';
	echo '<p>Target: <strong>100% pass</strong>. Pure regex, không tốn LLM token.</p>';

	$run = isset( $_GET['run'] ) && $_GET['run'] === '1'
		&& isset( $_GET['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bizcity_intent_pre_rules_run' );

	$run_url_base = menu_page_url( 'bizcity-intent-pre-rules-test', false );
	if ( ! $run_url_base ) {
		$run_url_base = admin_url( 'tools.php?page=bizcity-intent-pre-rules-test' );
	}
	$run_url = wp_nonce_url(
		add_query_arg( 'run', '1', $run_url_base ),
		'bizcity_intent_pre_rules_run'
	);

	printf(
		'<p><a href="%s" class="button button-primary">▶ Run pre-rules fixture</a></p>',
		esc_url( $run_url )
	);

	if ( $run ) {
		echo '<h2>Live output</h2><pre style="background:#111;color:#0f0;padding:12px;max-height:600px;overflow:auto;">';
		require __DIR__ . '/run-pre-rules-test.php';
		echo '</pre>';
	}

	$last = get_option( 'bizcity_intent_pre_rules_last_report', null );
	if ( is_array( $last ) ) {
		echo '<h2>Last report</h2>';
		printf(
			'<p>Ran at: <code>%s</code> · Score: <strong>%d/%d (%.1f%%)</strong></p>',
			esc_html( $last['ran_at'] ?? '?' ),
			(int) ( $last['pass'] ?? 0 ),
			(int) ( $last['total'] ?? 0 ),
			(float) ( $last['pct'] ?? 0 )
		);
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Message</th><th>try_match (exp/got)</th><th>intent_kind (exp/got)</th><th>OK?</th><th>µs</th></tr></thead><tbody>';
		foreach ( ( $last['cases'] ?? [] ) as $i => $c ) {
			printf(
				'<tr><td>%d</td><td>%s</td><td><code>%s</code> / <code>%s</code></td><td><code>%s</code> / <code>%s</code></td><td>%s</td><td>%d</td></tr>',
				$i + 1,
				esc_html( (string) ( $c['msg'] ?? '' ) ),
				esc_html( (string) ( $c['expected_try_match']  ?? '(null)' ) ),
				esc_html( (string) ( $c['got_try_match']       ?? '(null)' ) ),
				esc_html( (string) ( $c['expected_intent_kind'] ?? '(null)' ) ),
				esc_html( (string) ( $c['got_intent_kind']      ?? '(null)' ) ),
				! empty( $c['ok'] ) ? '✅' : '❌',
				(int) ( $c['us'] ?? 0 )
			);
		}
		echo '</tbody></table>';
	}

	echo '</div>';
}
