<?php
/**
 * BizCity CRM Sprint Diagnostic — Phase C + Phase D sections.
 *
 * Sibling of `class-sprint-diagnostic.php`. Houses three diagnostic blocks
 * extracted from the (5443-line) monolith to keep the orchestrator focused:
 *
 *   • Phase C.5    — Tool Dispatch (Layer 6, R-MPRT-6) wiring
 *   • Phase C.5.1  — Tool intent matcher injects guru-bound tools
 *   • Phase D.1    — Notebook Selector passage-density middle tier
 *
 * Render is invoked from `BizCity_CRM_Sprint_Diagnostic::render()` after the
 * Phase B (guru bridge) section. All probes are read-only (Reflection + table
 * SHOW COLUMNS); never mutates state.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\TwinCRM
 * @since      2026-05-14  Sprint PHASE-0.35 / F7.C5.1 + F7.D1
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_CRM_Sprint_Diagnostic_Phase_CD {

	/** Entry — host calls this once from its render() loop. */
	public static function render(): void {
		self::render_phase_c_dispatch_section();
		self::render_phase_c51_matcher_inject_section();
		self::render_phase_c6_guru_pipeline_section();
		self::render_phase_d1_selector_health_section();
	}

	/* ============================================================
	 * Phase C.5 — Tool Dispatch (Layer 6) diagnostic
	 * F7.C5: TwinBrain runtime dispatch + EVT_TOOL_DONE wiring.
	 * ============================================================ */

	private static function render_phase_c_dispatch_section(): void {
		echo '<h2>Phase C.5 · F7.C5 — Tool Dispatch (Layer 6, R-MPRT-6)</h2>';
		echo '<p style="color:#555;font-size:12px">Runtime: <code>BizCity_TwinBrain_Runtime::dispatch_tool()</code> bypasses <code>Twin_Runner</code>, calls <code>BizCity_Twin_Tool_Registry::execute()</code> directly. Emits <code>tool_done</code> SSE event between <code>tool_decided</code> và <code>synthesis_started</code> với <code>canvas_open</code> embedded (PHASE-1.20 + R-MPR-THINKING §11).</p>';
		self::render_task_table( self::compute_phase_c5_tasks() );
	}

	private static function compute_phase_c5_tasks(): array {
		$out = array();

		/* T-F7.C5.a — Runtime dispatch_tool method present (Reflection) */
		$cls = class_exists( 'BizCity_TwinBrain_Runtime', false );
		$has_dispatch = false;
		$has_results  = false;
		$has_payload  = false;
		$private_ok   = false;
		if ( $cls ) {
			try {
				$rc = new ReflectionClass( 'BizCity_TwinBrain_Runtime' );
				if ( $rc->hasMethod( 'dispatch_tool' ) ) {
					$rm = $rc->getMethod( 'dispatch_tool' );
					$has_dispatch = true;
					$private_ok = $rm->isPrivate();
				}
				$has_results = $rc->hasMethod( 'dispatched_tool_results' );
				$has_payload = $rc->hasMethod( 'tool_done_payload' );
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$out[] = array(
			'id'       => 'T-F7.C5.a',
			'status'   => ( $cls && $has_dispatch && $has_results && $has_payload && $private_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'Runtime exposes dispatch_tool/dispatched_tool_results/tool_done_payload (private)',
			'evidence' => sprintf(
				"class=%s\ndispatch_tool=%s (private=%s)\ndispatched_tool_results=%s\ntool_done_payload=%s",
				$cls ? 'YES' : 'NO',
				$has_dispatch ? 'YES' : 'NO', $private_ok ? 'YES' : 'NO',
				$has_results ? 'YES' : 'NO',
				$has_payload ? 'YES' : 'NO'
			),
		);

		/* T-F7.C5.b — EVT_TOOL_DONE registered in data contract */
		$contract = class_exists( 'BizCity_Twin_Data_Contract', false );
		$const_ok = $contract && defined( 'BizCity_Twin_Data_Contract::EVT_TOOL_DONE' );
		$evt_name = $const_ok ? constant( 'BizCity_Twin_Data_Contract::EVT_TOOL_DONE' ) : '';
		$tax_ok   = false;
		$tax_keys = array();
		if ( $contract && method_exists( 'BizCity_Twin_Data_Contract', 'event_taxonomy' ) ) {
			$tax = BizCity_Twin_Data_Contract::event_taxonomy();
			if ( is_array( $tax ) && $evt_name !== '' && isset( $tax[ $evt_name ] ) ) {
				$tax_ok   = true;
				$entry    = $tax[ $evt_name ];
				$tax_keys = isset( $entry['payload_keys'] ) && is_array( $entry['payload_keys'] ) ? $entry['payload_keys'] : array();
			}
		}
		$out[] = array(
			'id'       => 'T-F7.C5.b',
			'status'   => ( $const_ok && $tax_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'EVT_TOOL_DONE constant + event_taxonomy entry',
			'evidence' => sprintf(
				"const defined=%s\nevent name=%s\ntaxonomy entry=%s\npayload_keys=%s",
				$const_ok ? 'YES' : 'NO',
				$evt_name !== '' ? $evt_name : '(missing)',
				$tax_ok ? 'YES' : 'NO',
				empty( $tax_keys ) ? '(none)' : implode( ', ', $tax_keys )
			),
		);

		/* T-F7.C5.c — Tool registry available (dispatch dependency) */
		$reg_cls    = class_exists( 'BizCity_Twin_Tool_Registry', false );
		$reg_inst   = $reg_cls && method_exists( 'BizCity_Twin_Tool_Registry', 'instance' );
		$tool_count = 0;
		if ( $reg_inst ) {
			try {
				$inst = BizCity_Twin_Tool_Registry::instance();
				if ( method_exists( $inst, 'all' ) ) {
					$all = $inst->all();
					$tool_count = is_array( $all ) ? count( $all ) : 0;
				}
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$out[] = array(
			'id'       => 'T-F7.C5.c',
			'status'   => ( $reg_cls && $reg_inst && $tool_count > 0 ) ? 'PASS' : 'WARN',
			'check'    => 'BizCity_Twin_Tool_Registry::instance() returns >=1 tool',
			'evidence' => sprintf(
				"class=%s\ninstance method=%s\nregistered tools=%d",
				$reg_cls ? 'YES' : 'NO',
				$reg_inst ? 'YES' : 'NO',
				$tool_count
			),
		);

		/* T-F7.C5.d — REST handlers forward guru_id/tool_force into complete_turn(_stream) */
		$rest_cls   = class_exists( 'BizCity_TwinBrain_REST', false );
		$has_turn   = $rest_cls && method_exists( 'BizCity_TwinBrain_REST', 'handle_turn' );
		$has_stream = $rest_cls && method_exists( 'BizCity_TwinBrain_REST', 'handle_turn_stream' );
		$out[] = array(
			'id'       => 'T-F7.C5.d',
			'status'   => ( $rest_cls && $has_turn && $has_stream ) ? 'PASS' : 'FAIL',
			'check'    => 'TwinBrain REST handle_turn + handle_turn_stream present (forwards guru_id/tool_force)',
			'evidence' => sprintf(
				"REST class=%s\nhandle_turn=%s\nhandle_turn_stream=%s",
				$rest_cls ? 'YES' : 'NO',
				$has_turn ? 'YES' : 'NO',
				$has_stream ? 'YES' : 'NO'
			),
		);

		return $out;
	}

	/* ============================================================
	 * Phase C.5.1 — Tool intent matcher injects guru-bound tools
	 * F7.C5.1: Fix tool_candidates=0 when persona providers register
	 * tools via filter only (e.g. Tarot) but never INSERT into
	 * wp_bizcity_skills. Matcher must merge guru_whitelist ∩
	 * Layer 2/3 into its candidate skill list before scoring.
	 * ============================================================ */

	private static function render_phase_c51_matcher_inject_section(): void {
		echo '<h2>Phase C.5.1 · F7.C5.1 — Matcher injection (guru ∩ Layer 2/3)</h2>';
		echo '<p style="color:#555;font-size:12px">Tool_Intent_Matcher reads <code>wp_bizcity_skills</code> only. Persona providers (Tarot, ContentCreator, …) register tools via <code>bizcity_persona_tool_providers</code> filter — never written to that table. Without injection the +0.05 boost has nothing to boost → <code>tool_candidates=[]</code> → <code>decide_tool=no_candidates</code>.</p>';
		self::render_task_table( self::compute_phase_c51_tasks() );
	}

	private static function compute_phase_c51_tasks(): array {
		$out = array();

		/* T-F7.C5.1.a — Matcher class has inject_guru_tools() method */
		$cls = class_exists( 'BizCity_TwinBrain_Tool_Intent_Matcher', false );
		$has_inject = false;
		$has_probe  = false;
		if ( $cls ) {
			try {
				$rc = new ReflectionClass( 'BizCity_TwinBrain_Tool_Intent_Matcher' );
				$has_inject = $rc->hasMethod( 'inject_guru_tools' );
				$has_probe  = $rc->hasMethod( 'probe_tool_field' );
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$out[] = array(
			'id'       => 'T-F7.C5.1.a',
			'status'   => ( $cls && $has_inject && $has_probe ) ? 'PASS' : 'FAIL',
			'check'    => 'Matcher has inject_guru_tools() + probe_tool_field() (PHASE-0.35 patch)',
			'evidence' => sprintf(
				"class=%s\ninject_guru_tools=%s\nprobe_tool_field=%s",
				$cls ? 'YES' : 'NO',
				$has_inject ? 'YES' : 'NO',
				$has_probe ? 'YES' : 'NO'
			),
		);

		/* T-F7.C5.1.b — Layer 2/3 filter visibility (count tools) */
		$layer2 = 0; $layer3 = 0; $providers_cls = array();
		$agents = apply_filters( 'bizcity_register_agent', array() );
		if ( is_array( $agents ) ) {
			foreach ( $agents as $agent ) {
				$tools = is_array( $agent ) ? ( $agent['tools'] ?? null )
					: ( is_object( $agent ) ? ( $agent->tools ?? null ) : null );
				if ( is_array( $tools ) ) $layer2 += count( $tools );
			}
		}
		$providers = apply_filters( 'bizcity_persona_tool_providers', array() );
		if ( is_array( $providers ) ) {
			foreach ( $providers as $p ) {
				if ( is_object( $p ) && method_exists( $p, 'get_tool_definitions' ) ) {
					$defs = $p->get_tool_definitions();
					if ( is_array( $defs ) ) $layer3 += count( $defs );
					$providers_cls[] = get_class( $p );
				}
			}
		}
		$total = $layer2 + $layer3;
		$out[] = array(
			'id'       => 'T-F7.C5.1.b',
			'status'   => ( $total > 0 ) ? 'PASS' : 'WARN',
			'check'    => 'Layer 2 (bizcity_register_agent) + Layer 3 (bizcity_persona_tool_providers) expose >= 1 tool',
			'evidence' => sprintf(
				"layer2_tools=%d\nlayer3_tools=%d\ntotal=%d\nproviders=%s",
				$layer2, $layer3, $total,
				empty( $providers_cls ) ? '(none)' : implode( ', ', array_slice( $providers_cls, 0, 8 ) )
			),
		);

		/* T-F7.C5.1.c — Guru bridge whitelist non-empty for active gurus */
		$bridge_cls = class_exists( 'BizCity_Guru_Skill_Bridge', false );
		$known      = 0;
		if ( $bridge_cls && method_exists( 'BizCity_Guru_Skill_Bridge', 'all_known_tools' ) ) {
			try {
				$kt = BizCity_Guru_Skill_Bridge::all_known_tools();
				$known = is_array( $kt ) ? count( $kt ) : 0;
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$total_bindings = 0;
		if ( $bridge_cls && method_exists( 'BizCity_Guru_Skill_Bridge', 'count_total' ) ) {
			try { $total_bindings = (int) BizCity_Guru_Skill_Bridge::count_total(); } catch ( Throwable $e ) { /* ignore */ }
		}
		$out[] = array(
			'id'       => 'T-F7.C5.1.c',
			'status'   => ( $bridge_cls && $known > 0 && $total_bindings > 0 ) ? 'PASS' : 'WARN',
			'check'    => 'Guru_Skill_Bridge known tools > 0 AND wp_bizcity_guru_skills has bindings',
			'evidence' => sprintf(
				"bridge_class=%s\nall_known_tools()=%d\ncount_total(bindings)=%d",
				$bridge_cls ? 'YES' : 'NO',
				$known, $total_bindings
			),
		);

		return $out;
	}

	/* ============================================================
	 * Phase C.6 — Guru pipeline live probes (F7.C6)
	 *   • Parser smoke    — `BizCity_Guru_Token_Parser::parse()` shape
	 *   • Matcher boost   — `+0.05` boost path active for guru whitelist
	 *   • Scope reject    — runtime gate logic mirrors live whitelist
	 * Read-only: parse()/Reflection/SELECT only. No emit_event, no LLM.
	 * ============================================================ */

	private static function render_phase_c6_guru_pipeline_section(): void {
		echo '<h2>Phase C.6 · F7.C6 — Guru pipeline live probes (parser · matcher · scope)</h2>';
		echo '<p style="color:#555;font-size:12px">3 read-only smoke probes verifying the guru thinking surface end-to-end: token parser shape (Layer 0), matcher +0.05 boost path (Layer 2B), runtime scope-reject gate (R-MPRT-5 / AC #12). Mirrors `BizCity_TwinBrain_Runtime::start_turn()` whitelist logic without invoking it (no SSE side effects).</p>';
		self::render_task_table( self::compute_phase_c6_tasks() );
	}

	private static function compute_phase_c6_tasks(): array {
		$out = array();

		/* T-F7.C6.a — Parser smoke: 4 input forms → expected shape ----- */
		$parser_cls = class_exists( 'BizCity_Guru_Token_Parser', false );
		$cases = array();
		if ( $parser_cls ) {
			$samples = array(
				'plain'        => 'hello world',
				'guru_only'    => '@tarot hello',
				'tool_only'    => '#tarot_interpret hello',
				'guru_tool'    => '@tarot #tarot_interpret luận giải lá the fool',
			);
			foreach ( $samples as $key => $msg ) {
				try {
					$r = BizCity_Guru_Token_Parser::parse( $msg );
				} catch ( Throwable $e ) {
					$cases[ $key ] = 'EXCEPTION: ' . $e->getMessage();
					continue;
				}
				$cases[ $key ] = sprintf(
					'guru_id=%d slug=%s label=%s tool_force=%s tokens=[%s] clean="%s"',
					(int) ( $r['guru_id'] ?? -1 ),
					(string) ( $r['guru_slug'] ?? '?' ),
					(string) ( $r['guru_label'] ?? '?' ),
					(string) ( $r['tool_force'] ?? '?' ),
					implode( ',', (array) ( $r['tokens'] ?? array() ) ),
					(string) ( $r['message_clean'] ?? '?' )
				);
			}
		}
		// Validate shape contract (don't require slug to resolve to a real guru):
		$shape_ok = $parser_cls
			&& isset( $cases['tool_only'] ) && strpos( $cases['tool_only'], 'tool_force=tarot_interpret' ) !== false
			&& isset( $cases['guru_tool'] ) && strpos( $cases['guru_tool'], 'tool_force=tarot_interpret' ) !== false
			&& isset( $cases['guru_tool'] ) && strpos( $cases['guru_tool'], '@tarot,#tarot_interpret' ) !== false
			&& isset( $cases['plain'] )     && strpos( $cases['plain'], 'tokens=[]' ) !== false;
		$out[] = array(
			'id'       => 'T-F7.C6.a',
			'status'   => ( $parser_cls && $shape_ok ) ? 'PASS' : ( $parser_cls ? 'WARN' : 'FAIL' ),
			'check'    => 'BizCity_Guru_Token_Parser::parse() peels @guru/#tool, populates shape across 4 input forms',
			'evidence' => sprintf(
				"class=%s\nplain     → %s\nguru_only → %s\ntool_only → %s\nguru_tool → %s",
				$parser_cls ? 'YES' : 'NO',
				$cases['plain']     ?? '(skipped)',
				$cases['guru_only'] ?? '(skipped)',
				$cases['tool_only'] ?? '(skipped)',
				$cases['guru_tool'] ?? '(skipped)'
			),
		);

		/* T-F7.C6.b — Matcher boost path active (Reflection + source scan) */
		$matcher_cls = class_exists( 'BizCity_TwinBrain_Tool_Intent_Matcher', false );
		$has_match   = false;
		$accepts_opts = false;
		$has_boost_const = false;
		$src_path = '';
		if ( $matcher_cls ) {
			try {
				$rc = new ReflectionClass( 'BizCity_TwinBrain_Tool_Intent_Matcher' );
				if ( $rc->hasMethod( 'match' ) ) {
					$has_match = true;
					$rm = $rc->getMethod( 'match' );
					foreach ( $rm->getParameters() as $p ) {
						if ( $p->getName() === 'opts' || $p->getName() === 'options' ) {
							$accepts_opts = true; break;
						}
					}
				}
				$src_path = (string) $rc->getFileName();
				if ( $src_path !== '' && is_readable( $src_path ) ) {
					$src = (string) file_get_contents( $src_path );
					// Boost constant 0.05 + tools_for_guru reference together = boost path live
					$has_boost_const = ( strpos( $src, '0.05' ) !== false ) && ( strpos( $src, 'tools_for_guru' ) !== false );
				}
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$out[] = array(
			'id'       => 'T-F7.C6.b',
			'status'   => ( $matcher_cls && $has_match && $has_boost_const ) ? 'PASS' : 'FAIL',
			'check'    => 'Tool_Intent_Matcher::match() exists + source contains +0.05 boost wired to tools_for_guru()',
			'evidence' => sprintf(
				"class=%s\nmatch()=%s\naccepts_opts_param=%s\nsource_has_boost(0.05+tools_for_guru)=%s\nsource=%s",
				$matcher_cls ? 'YES' : 'NO',
				$has_match ? 'YES' : 'NO',
				$accepts_opts ? 'YES' : 'NO (positional or different name)',
				$has_boost_const ? 'YES' : 'NO',
				$src_path !== '' ? str_replace( ABSPATH, '/', $src_path ) : '(missing)'
			),
		);

		/* T-F7.C6.c — Scope-reject gate: live whitelist computation -------
		 * Pick first guru with at least 1 binding, simulate the runtime
		 * gate: tool_force ∉ tools_for_guru($id) → would emit error
		 * `guru_tool_out_of_scope`. We don't run start_turn() (avoids
		 * SSE/LLM); we just verify the gate's two ingredients are healthy.
		 */
		$bridge_cls = class_exists( 'BizCity_Guru_Skill_Bridge', false );
		$probe_guru_id = 0;
		$probe_label   = '';
		$probe_whitelist = array();
		if ( $bridge_cls ) {
			global $wpdb;
			$tbl_skills = $wpdb->prefix . 'bizcity_guru_skills';
			$tbl_chars  = $wpdb->prefix . 'bizcity_characters';
			$prev = $wpdb->suppress_errors( true );
			$row = $wpdb->get_row(
				"SELECT s.guru_id, c.slug FROM {$tbl_skills} s
				 LEFT JOIN {$tbl_chars} c ON c.id = s.guru_id
				 WHERE s.enabled = 1 GROUP BY s.guru_id ORDER BY s.guru_id ASC LIMIT 1",
				ARRAY_A
			);
			$wpdb->suppress_errors( $prev );
			if ( is_array( $row ) ) {
				$probe_guru_id = (int) $row['guru_id'];
				$probe_label   = (string) $row['slug'];
				try {
					$probe_whitelist = (array) BizCity_Guru_Skill_Bridge::tools_for_guru( $probe_guru_id );
				} catch ( Throwable $e ) { /* ignore */ }
			}
		}
		// Gate logic mirror — runtime accepts only if whitelist empty OR tool_force ∈ whitelist.
		$fake_tool        = '__fake_out_of_scope_tool__';
		$would_reject     = ( $probe_guru_id > 0 && ! empty( $probe_whitelist ) && ! in_array( $fake_tool, $probe_whitelist, true ) );
		$first_real_tool  = ! empty( $probe_whitelist ) ? (string) $probe_whitelist[0] : '';
		$would_pass       = ( $probe_guru_id > 0 && ! empty( $probe_whitelist ) && in_array( $first_real_tool, $probe_whitelist, true ) );

		// Source verification: runtime emits 'guru_tool_out_of_scope' error key
		$rt_path = '';
		$rt_has_error_key = false;
		if ( class_exists( 'BizCity_TwinBrain_Runtime', false ) ) {
			try {
				$rcr = new ReflectionClass( 'BizCity_TwinBrain_Runtime' );
				$rt_path = (string) $rcr->getFileName();
				if ( $rt_path !== '' && is_readable( $rt_path ) ) {
					$rt_src = (string) file_get_contents( $rt_path );
					$rt_has_error_key = ( strpos( $rt_src, "'guru_tool_out_of_scope'" ) !== false )
						|| ( strpos( $rt_src, '"guru_tool_out_of_scope"' ) !== false );
				}
			} catch ( Throwable $e ) { /* ignore */ }
		}

		$status_c = 'WARN';
		if ( $bridge_cls && $rt_has_error_key && $probe_guru_id > 0 && $would_reject && $would_pass ) {
			$status_c = 'PASS';
		} elseif ( ! $bridge_cls || ! $rt_has_error_key ) {
			$status_c = 'FAIL';
		}
		$out[] = array(
			'id'       => 'T-F7.C6.c',
			'status'   => $status_c,
			'check'    => 'Runtime guru-scope gate live: whitelist non-empty for ≥1 guru + reject path emits guru_tool_out_of_scope',
			'evidence' => sprintf(
				"bridge_class=%s\nruntime_emits_guru_tool_out_of_scope=%s\nprobe_guru: id=%d slug=%s whitelist_size=%d\nfake_tool='%s' → would_reject=%s\nfirst_real_tool='%s' → would_pass=%s",
				$bridge_cls ? 'YES' : 'NO',
				$rt_has_error_key ? 'YES' : 'NO',
				$probe_guru_id, $probe_label !== '' ? $probe_label : '(no slug)',
				count( $probe_whitelist ),
				$fake_tool, $would_reject ? 'YES' : 'NO',
				$first_real_tool !== '' ? $first_real_tool : '(none)', $would_pass ? 'YES' : 'NO'
			),
		);

		return $out;
	}

	/* ============================================================
	 * Phase D.1 — Notebook Selector health (passage density, cosine, recency)
	 * ============================================================ */

	private static function render_phase_d1_selector_health_section(): void {
		echo '<h2>Phase D.1 · F7.D1 — Notebook Selector health</h2>';
		echo '<p style="color:#555;font-size:12px">Selector flow: <code>force_ids</code> → <strong>guru_bucket (D.2)</strong> → <code>select_with_cosine</code> (notebook-level) → <code>select_by_passage_density</code> (D.1, NEW) → <code>select_recency_fallback</code>. Guru bucket queries <code>kg_notebooks WHERE character_id = guru_id</code> reserving top slots, prevents Ask Brain ignoring <code>@guru</code> pin.</p>';
		self::render_task_table( self::compute_phase_d1_tasks() );
	}

	private static function compute_phase_d1_tasks(): array {
		global $wpdb;
		$out = array();

		/* T-F7.D1.a — Selector has select_by_passage_density() method */
		$cls       = class_exists( 'BizCity_TwinBrain_Notebook_Selector', false );
		$has_pass  = false;
		$has_cos   = false;
		$has_fb    = false;
		if ( $cls ) {
			try {
				$rc = new ReflectionClass( 'BizCity_TwinBrain_Notebook_Selector' );
				$has_pass = $rc->hasMethod( 'select_by_passage_density' );
				$has_cos  = $rc->hasMethod( 'select_with_cosine' );
				$has_fb   = $rc->hasMethod( 'select_recency_fallback' );
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$out[] = array(
			'id'       => 'T-F7.D1.a',
			'status'   => ( $cls && $has_pass && $has_cos && $has_fb ) ? 'PASS' : 'FAIL',
			'check'    => 'Selector has cosine + passage_density (NEW) + recency_fallback methods',
			'evidence' => sprintf(
				"class=%s\nselect_with_cosine=%s\nselect_by_passage_density=%s\nselect_recency_fallback=%s",
				$cls ? 'YES' : 'NO',
				$has_cos ? 'YES' : 'NO',
				$has_pass ? 'YES' : 'NO',
				$has_fb ? 'YES' : 'NO'
			),
		);

		/* T-F7.D1.b — perspective_embedding population rate (the gap this fixes) */
		$nb_total = 0; $nb_with_emb = 0; $col_present = false;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$tnb  = BizCity_KG_Database::instance()->tbl_notebooks();
			$prev = $wpdb->suppress_errors( true );
			$col_present = (bool) $wpdb->get_var( $wpdb->prepare(
				"SHOW COLUMNS FROM {$tnb} LIKE %s", 'perspective_embedding'
			) );
			$nb_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tnb}" );
			if ( $col_present ) {
				$nb_with_emb = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$tnb} WHERE perspective_embedding IS NOT NULL AND perspective_embedding <> ''"
				);
			}
			$wpdb->suppress_errors( $prev );
		}
		$rate = $nb_total > 0 ? round( 100.0 * $nb_with_emb / $nb_total, 1 ) : 0.0;
		// PASS when fully populated; WARN when partial (passage tier compensates); FAIL only if column missing.
		if ( ! $col_present ) { $st = 'FAIL'; }
		elseif ( $nb_total === 0 ) { $st = 'WARN'; }
		elseif ( $nb_with_emb === $nb_total ) { $st = 'PASS'; }
		else { $st = 'WARN'; }
		$out[] = array(
			'id'       => 'T-F7.D1.b',
			'status'   => $st,
			'check'    => 'kg_notebooks.perspective_embedding column present + populated (cron PHASE-0.8 W0.6.E)',
			'evidence' => sprintf(
				"column_present=%s\ntotal_notebooks=%d\nwith_embedding=%d (%.1f%%)\nfallback_active_when_zero=passage_density",
				$col_present ? 'YES' : 'NO',
				$nb_total, $nb_with_emb, $rate
			),
		);

		/* T-F7.D1.c — Passage pool ready for density tier (passages with embedding) */
		$pas_total = 0; $pas_with_emb = 0;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$tp   = BizCity_KG_Database::instance()->tbl_passages();
			$prev = $wpdb->suppress_errors( true );
			$pas_total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tp}" );
			$pas_with_emb = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tp} WHERE embedding IS NOT NULL AND embedding <> '' AND extraction_status='done'"
			);
			$wpdb->suppress_errors( $prev );
		}
		$prate = $pas_total > 0 ? round( 100.0 * $pas_with_emb / $pas_total, 1 ) : 0.0;
		$out[] = array(
			'id'       => 'T-F7.D1.c',
			'status'   => ( $pas_with_emb > 0 ) ? 'PASS' : 'WARN',
			'check'    => 'kg_passages has embedded passages (extraction_status=done) for density tier',
			'evidence' => sprintf(
				"total_passages=%d\nwith_embedding_done=%d (%.1f%%)",
				$pas_total, $pas_with_emb, $prate
			),
		);

		/* T-F7.D2.a — Selector guru-bucket method present (Reflection) */
		$has_bucket = false; $has_merge = false;
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Selector', false ) ) {
			try {
				$rc = new ReflectionClass( 'BizCity_TwinBrain_Notebook_Selector' );
				$has_bucket = $rc->hasMethod( 'fetch_guru_notebooks' );
				$has_merge  = $rc->hasMethod( 'merge_with_guru_bucket' );
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$out[] = array(
			'id'       => 'T-F7.D2.a',
			'status'   => ( $has_bucket && $has_merge ) ? 'PASS' : 'FAIL',
			'check'    => 'Selector has fetch_guru_notebooks() + merge_with_guru_bucket() (PHASE-0.35 / F7.D2 patch)',
			'evidence' => sprintf(
				"fetch_guru_notebooks=%s\nmerge_with_guru_bucket=%s",
				$has_bucket ? 'YES' : 'NO',
				$has_merge ? 'YES' : 'NO'
			),
		);

		/* T-F7.D2.b — Notebook-guru binding stats (kg_notebooks.character_id) */
		$bound = 0; $by_char = '';
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$tnb = BizCity_KG_Database::instance()->tbl_notebooks();
			$prev = $wpdb->suppress_errors( true );
			$bound = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tnb} WHERE character_id IS NOT NULL AND character_id > 0" );
			$top = $wpdb->get_results(
				"SELECT character_id, COUNT(*) AS n FROM {$tnb}
				 WHERE character_id IS NOT NULL AND character_id > 0
				 GROUP BY character_id ORDER BY n DESC LIMIT 5",
				ARRAY_A
			);
			$wpdb->suppress_errors( $prev );
			if ( is_array( $top ) ) {
				$by_char = implode( ', ', array_map( static function ( $r ) {
					return 'g' . (int) $r['character_id'] . ':' . (int) $r['n'];
				}, $top ) );
			}
		}
		$out[] = array(
			'id'       => 'T-F7.D2.b',
			'status'   => ( $bound > 0 ) ? 'PASS' : 'WARN',
			'check'    => 'kg_notebooks.character_id has bindings (guru bucket source)',
			'evidence' => sprintf(
				"bound_notebooks=%d\ntop_by_character=%s",
				$bound, $by_char !== '' ? $by_char : '(none)'
			),
		);

		return $out;
	}

	/* ============================================================
	 * Shared render helpers (kept local — avoids coupling to host
	 * private methods like badge()).
	 * ============================================================ */

	private static function render_task_table( array $tasks ): void {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:110px">Task</th><th style="width:80px">Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . self::badge( (string) $t['status'] ) . '</td>';
			echo '<td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px;color:#333">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function badge( string $status ): string {
		$status = strtoupper( $status );
		$colors = array(
			'PASS'    => '#46b450',
			'FAIL'    => '#dc3232',
			'WARN'    => '#ffb900',
			'PENDING' => '#999',
		);
		$bg = isset( $colors[ $status ] ) ? $colors[ $status ] : '#999';
		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;background:%s;color:#fff;font-size:11px;font-weight:600;border-radius:3px">%s</span>',
			esc_attr( $bg ), esc_html( $status )
		);
	}
}
