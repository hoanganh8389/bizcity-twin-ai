/**
 * Tiny formatting helpers shared by Inbox views.
 */

/**
 * Relative time string (Chatwoot-style): "now", "5m", "2h", "Yesterday", "Mon", "12 May".
 */
export function fmtRelTime( raw ) {
	if ( ! raw ) { return ''; }
	let ts;
	if ( raw instanceof Date ) {
		ts = raw.getTime();
	} else if ( typeof raw === 'number' ) {
		ts = raw < 1e12 ? raw * 1000 : raw;
	} else {
		ts = Date.parse( String( raw ).replace( ' ', 'T' ) );
	}
	if ( isNaN( ts ) ) { return ''; }
	const now  = Date.now();
	const diff = Math.max( 0, Math.floor( ( now - ts ) / 1000 ) );
	if ( diff < 45 )       { return 'now'; }
	if ( diff < 3600 )     { return Math.floor( diff / 60 ) + 'm'; }
	if ( diff < 86400 )    { return Math.floor( diff / 3600 ) + 'h'; }
	const d = new Date( ts );
	const today = new Date(); today.setHours( 0, 0, 0, 0 );
	const yest  = new Date( today ); yest.setDate( yest.getDate() - 1 );
	const dDay  = new Date( d ); dDay.setHours( 0, 0, 0, 0 );
	if ( dDay.getTime() === yest.getTime() ) { return 'Yest'; }
	if ( now - ts < 7 * 86400 * 1000 )       { return d.toLocaleDateString( undefined, { weekday: 'short' } ); }
	return d.toLocaleDateString( undefined, { day: '2-digit', month: 'short' } );
}

/**
 * Absolute time formatted compactly (HH:MM, or DD/MM HH:MM).
 */
export function fmtAbsTime( raw ) {
	if ( ! raw ) { return ''; }
	const ts = typeof raw === 'string' ? Date.parse( raw.replace( ' ', 'T' ) ) : raw;
	if ( isNaN( ts ) ) { return ''; }
	const d = new Date( ts );
	const sameDay = ( new Date() ).toDateString() === d.toDateString();
	const hh = String( d.getHours() ).padStart( 2, '0' );
	const mm = String( d.getMinutes() ).padStart( 2, '0' );
	if ( sameDay ) { return `${ hh }:${ mm }`; }
	return `${ String( d.getDate() ).padStart( 2, '0' ) }/${ String( d.getMonth() + 1 ).padStart( 2, '0' ) } ${ hh }:${ mm }`;
}

/**
 * Channel meta — icon + accent color per channel_type code.
 * [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND R-ZONE — added zalo_oa (Zone 1 customer OA)
 * and renamed zalo (Zalo Bot Zone 2 legacy) to correct label.
 */
const CHANNEL_META = {
	facebook:  { icon: '📘', label: 'Messenger',  color: '#1877F2' },
	zalo_oa:   { icon: '💬', label: 'Zalo OA',    color: '#006AF5' },
	zalo:      { icon: '🤖', label: 'Zalo Bot',   color: '#0055C4' },
	telegram:  { icon: '✈️', label: 'Telegram',   color: '#0088CC' },
	webchat:   { icon: '🌐', label: 'Webchat',    color: '#7C3AED' },
	hotline:   { icon: '☎️', label: 'Hotline',    color: '#DB2777' },
};
export function channelMeta( code ) {
	const k = String( code || '' ).toLowerCase();
	return CHANNEL_META[ k ] || { icon: '💼', label: k.toUpperCase() || 'Channel', color: '#475569' };
}

/**
 * Avatar palette (deterministic per name) for fallback initial bubbles.
 */
const PALETTE = [
	'from-rose-400 to-rose-600',
	'from-amber-400 to-amber-600',
	'from-emerald-400 to-emerald-600',
	'from-sky-400 to-sky-600',
	'from-indigo-400 to-indigo-600',
	'from-violet-400 to-violet-600',
	'from-pink-400 to-pink-600',
	'from-teal-400 to-teal-600',
];
export function avatarGradient( seed ) {
	const s = String( seed || '?' );
	let h = 0;
	for ( let i = 0; i < s.length; i++ ) { h = ( h * 31 + s.charCodeAt( i ) ) >>> 0; }
	return PALETTE[ h % PALETTE.length ];
}

export function initials( name, fallback = '?' ) {
	if ( ! name ) { return fallback; }
	return String( name ).trim().split( /\s+/ ).map( ( s ) => s[ 0 ] ).slice( 0, 2 ).join( '' ).toUpperCase() || fallback;
}
