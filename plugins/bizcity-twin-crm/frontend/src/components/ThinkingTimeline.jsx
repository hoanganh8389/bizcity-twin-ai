import React, { useState } from 'react';

/**
 * ThinkingTimeline — collapsible "AI thinking" panel rendered next to an
 * AI-generated outgoing message.
 *
 * Reads from message.ai_metadata = {
 *   trace_uuid, notebook_id, character_id, latency_ms,
 *   steps: [{ name, ms, detail }],
 *   sources: [{ id, source_id, content, citation }],
 *   prompt,
 * }
 *
 * Steps recognised: resolve_context, kg_retrieval, llm_generate, dispatch.
 * Unknown steps are still rendered with raw JSON detail.
 */

const STEP_META = {
	resolve_context: { icon: '🧭', label: 'Resolve context',  color: 'bg-slate-100 text-slate-700 border-slate-200' },
	apply_persona:   { icon: '🎭', label: 'Apply persona',    color: 'bg-amber-100 text-amber-800 border-amber-200' },
	kg_retrieval:    { icon: '🔍', label: 'KG retrieval',     color: 'bg-sky-100 text-sky-800 border-sky-200' },
	llm_generate:    { icon: '🧠', label: 'LLM generate',     color: 'bg-violet-100 text-violet-800 border-violet-200' },
	dispatch:        { icon: '📤', label: 'Dispatch',         color: 'bg-emerald-100 text-emerald-800 border-emerald-200' },
};

function fmtMs( ms ) {
	const v = Number( ms ) || 0;
	if ( v < 1000 ) { return v + 'ms'; }
	return ( v / 1000 ).toFixed( 2 ) + 's';
}

function StepCard( { step } ) {
	const meta = STEP_META[ step.name ] || { icon: '•', label: step.name, color: 'bg-slate-50 text-slate-600 border-slate-200' };
	const d    = step.detail || {};
	return (
		<div className={ 'border ' + meta.color + ' rounded px-2 py-1.5 text-[11px]' }>
			<div className="flex items-center gap-1.5">
				<span>{ meta.icon }</span>
				<span className="font-semibold">{ meta.label }</span>
				<span className="ml-auto font-mono text-[10px] opacity-70">{ fmtMs( step.ms ) }</span>
			</div>
			{ step.name === 'resolve_context' ? (
				<div className="mt-1 space-y-1 text-[10px]">
					<div className="grid grid-cols-3 gap-1">
						<span title="notebook_id">📘 nb#{ d.notebook_id }{ d.notebook_source ? <span className="opacity-60"> · { d.notebook_source }</span> : null }</span>
						<span title="character_id">{ d.character_id ? `🎭 G${ d.character_id }` : '🎭 —' }</span>
						<span title="prompt chars">✍ { d.prompt_chars || 0 } chars</span>
					</div>
					{ d.guru_on_duty ? (
						<div className="bg-white/60 rounded p-1 border border-slate-200/60">
							<div className="font-semibold opacity-80 mb-0.5">Twin Guru on Duty</div>
							<div>binding: { d.guru_on_duty.binding_found
								? <span className="text-emerald-700">✓ { d.guru_on_duty.platform } · { d.guru_on_duty.account_id } · mode={ d.guru_on_duty.binding_mode || 'auto' }</span>
								: <span className="text-rose-600">✗ no binding for { d.guru_on_duty.platform }/{ d.guru_on_duty.account_id }</span>
							}</div>
							{ d.guru_on_duty.guru_uuid ? <div>guru_uuid: <span className="font-mono">{ d.guru_on_duty.guru_uuid }</span></div> : null }
							<div>attached notebooks: <span className="font-mono">{ Array.isArray( d.guru_on_duty.notebook_ids ) && d.guru_on_duty.notebook_ids.length > 0 ? d.guru_on_duty.notebook_ids.join( ', ' ) : '—' }</span> { Array.isArray( d.notebooks_eligible ) && d.notebooks_eligible.length >= 3 ? <span className="ml-1 px-1 bg-amber-100 text-amber-800 rounded">MPR-eligible ({ d.notebooks_eligible.length })</span> : null }</div>
						</div>
					) : null }
					{ d.service_template ? (
						<div className="bg-white/60 rounded p-1 border border-amber-200/70">
							<div className="font-semibold opacity-80 mb-0.5">Service Template</div>
							<div>→ <span className="font-mono">{ d.service_template.slug }</span> { d.service_template.label ? <span className="opacity-70">· { d.service_template.label }</span> : null }</div>
							<div>role: <span className="font-mono">{ d.service_template.role_scope || '—' }</span> · char: <span className="font-mono">{ d.service_template.char_role || '—' }</span> · src: <span className="opacity-70">{ d.service_template.source }</span></div>
							<div>budget: <span className="font-mono">~{ d.service_template.max_chars_target || 0 }ch</span> / <span className="font-mono">{ d.service_template.max_tokens_hint || 0 }tok</span> · chunk ≤ <span className="font-mono">{ d.service_template.per_chunk_max || '—' }</span></div>
							{ Array.isArray( d.service_template.allowed_channels ) && d.service_template.allowed_channels.length > 0 ? <div>channels: <span className="font-mono">{ d.service_template.allowed_channels.join( ', ' ) }</span></div> : null }
						</div>
					) : null }
				</div>
			) : null }
			{ step.name === 'apply_persona' ? (
				<div className="mt-1 text-[10px] space-y-0.5">
					<div>template: <span className="font-mono">{ d.template_slug }</span> { d.template_label ? <span className="opacity-70">· { d.template_label }</span> : null }</div>
					<div>role_scope: <span className="font-mono">{ d.role_scope || '—' }</span> · char_role: <span className="font-mono">{ d.char_role || '—' }</span> · src: <span className="opacity-70">{ d.source || '—' }</span></div>
					<div>persona prefix: <span className="font-mono">{ d.prefix_chars || 0 } chars</span> · target ≤ <span className="font-mono">{ d.max_chars_target || 0 }</span> · token cap <span className="font-mono">{ d.max_tokens_hint || 0 }</span></div>
				</div>
			) : null }
			{ step.name === 'kg_retrieval' ? (
				<div className="mt-1 text-[10px] space-y-0.5">
					<div>passages: <span className="font-mono">{ d.passages || 0 }</span> · mode: <span className="font-mono">{ d.mode || '—' }</span></div>
					{ Array.isArray( d.seed_entities ) && d.seed_entities.length > 0 ? (
						<div className="truncate" title={ d.seed_entities.join( ', ' ) }>
							seeds: { d.seed_entities.slice( 0, 4 ).map( ( s ) => (
								<span key={ s } className="inline-block px-1 mx-0.5 bg-white/60 rounded">{ s }</span>
							) ) }
							{ d.seed_entities.length > 4 ? <span className="opacity-60">+{ d.seed_entities.length - 4 }</span> : null }
						</div>
					) : null }
					{ Array.isArray( d.kg_steps ) && d.kg_steps.length > 0 ? (
						<details className="mt-0.5">
							<summary className="cursor-pointer opacity-70 hover:opacity-100">5-step pipeline</summary>
							<ol className="ml-3 mt-1 space-y-0.5 list-decimal">
								{ d.kg_steps.map( ( s, i ) => (
									<li key={ i }>{ s.name } — { Object.entries( s ).filter( ( [ k ] ) => k !== 'name' ).map( ( [ k, v ] ) => `${ k }:${ v }` ).join( ' · ' ) }</li>
								) ) }
							</ol>
						</details>
					) : null }
				</div>
			) : null }
			{ step.name === 'llm_generate' ? (
				<div className="mt-1 text-[10px] space-y-0.5">
					<div>{ d.provider || '—' } · <span className="font-mono">{ d.model || '—' }</span></div>
					<div>reply: { d.reply_chars || 0 } chars{ d.usage && d.usage.total_tokens ? ` · ${ d.usage.total_tokens } tok` : '' }</div>
					{ d.note ? <div className="opacity-70 truncate" title={ d.note }>note: { d.note }</div> : null }
				</div>
			) : null }
			{ step.name === 'dispatch' ? (
				<div className="mt-1 text-[10px]">
					{ d.sent ? '✓ sent' : '✗ failed' } · <span className="font-mono">{ d.platform || '—' }</span>
					{ d.error ? <span className="text-rose-600"> — { d.error }</span> : null }
				</div>
			) : null }
			{ ! STEP_META[ step.name ] ? (
				<pre className="mt-1 text-[10px] whitespace-pre-wrap font-mono opacity-70">{ JSON.stringify( d, null, 1 ) }</pre>
			) : null }
		</div>
	);
}

function SourceRow( { src, idx } ) {
	const [ open, setOpen ] = useState( false );
	const text = String( src.content || '' );
	const preview = text.length > 220 ? text.slice( 0, 220 ) + '…' : text;
	return (
		<li className="border border-slate-200 bg-white rounded px-2 py-1.5 text-[11px]">
			<div className="flex items-center gap-1.5">
				<span className="font-mono text-[10px] px-1 bg-slate-100 rounded">[{ idx + 1 }]</span>
				<span className="font-mono text-[10px] text-sky-700">{ src.citation }</span>
				<button type="button" onClick={ () => setOpen( ( v ) => ! v ) } className="ml-auto text-[10px] text-slate-500 hover:text-slate-800">
					{ open ? '▴' : '▾' }
				</button>
			</div>
			<div className="mt-1 text-slate-700 whitespace-pre-wrap">{ open ? text : preview }</div>
		</li>
	);
}

export default function ThinkingTimeline( { aiMetadata } ) {
	const [ open, setOpen ] = useState( false );
	if ( ! aiMetadata || typeof aiMetadata !== 'object' ) { return null; }

	const steps   = Array.isArray( aiMetadata.steps )   ? aiMetadata.steps   : [];
	const sources = Array.isArray( aiMetadata.sources ) ? aiMetadata.sources : [];
	if ( steps.length === 0 && sources.length === 0 ) { return null; }

	const totalMs = aiMetadata.latency_ms || steps.reduce( ( a, s ) => a + ( Number( s.ms ) || 0 ), 0 );

	return (
		<div className="mt-1 max-w-[72%]">
			<button
				type="button"
				onClick={ () => setOpen( ( v ) => ! v ) }
				className="w-full text-left text-[10px] px-2 py-1 border border-slate-200 bg-slate-50 hover:bg-slate-100 rounded flex items-center gap-1.5"
				title="Show AI thinking timeline"
			>
				<span>🧠</span>
				<span className="font-semibold">Thinking</span>
				<span className="text-slate-500">{ steps.length } steps · { sources.length } sources · { fmtMs( totalMs ) }</span>
				{ aiMetadata.notebook_id ? <span className="font-mono text-[9px] text-slate-400 ml-auto">nb#{ aiMetadata.notebook_id }</span> : null }
				<span className="ml-1 text-slate-400">{ open ? '▴' : '▾' }</span>
			</button>
			{ open ? (
				<div className="mt-1 border border-slate-100 bg-white rounded p-2 space-y-2">
					{ aiMetadata.prompt ? (
						<div className="text-[10px] text-slate-500">
							<span className="font-semibold">prompt:</span> <span className="italic">{ aiMetadata.prompt }</span>
						</div>
					) : null }
					{ steps.length > 0 ? (
						<div className="space-y-1">
							{ steps.map( ( s, i ) => <StepCard key={ i } step={ s } /> ) }
						</div>
					) : null }
					{ sources.length > 0 ? (
						<div>
							<div className="text-[10px] font-semibold text-slate-600 mb-1">📚 Sources ({ sources.length })</div>
							<ul className="space-y-1">
								{ sources.map( ( s, i ) => <SourceRow key={ i } src={ s } idx={ i } /> ) }
							</ul>
						</div>
					) : null }
				</div>
			) : null }
		</div>
	);
}
