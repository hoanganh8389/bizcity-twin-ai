import { createSlice } from '@reduxjs/toolkit';

const STORAGE_KEY = 'bzc-ui-prefs';

function load() {
	try {
		const raw = localStorage.getItem( STORAGE_KEY );
		if ( raw ) { return JSON.parse( raw ); }
	} catch ( e ) { /* noop */ }
	return {};
}

const persisted = load();

const initialState = {
	theme: persisted.theme || 'light',           // 'light' | 'dark'
	commandOpen: false,
	density: persisted.density || 'comfortable', // 'comfortable' | 'compact'
};

const slice = createSlice( {
	name: 'uiPrefs',
	initialState,
	reducers: {
		setTheme( state, action ) {
			state.theme = action.payload === 'dark' ? 'dark' : 'light';
			try { localStorage.setItem( STORAGE_KEY, JSON.stringify( { theme: state.theme, density: state.density } ) ); } catch ( e ) { /* noop */ }
		},
		toggleTheme( state ) {
			state.theme = state.theme === 'dark' ? 'light' : 'dark';
			try { localStorage.setItem( STORAGE_KEY, JSON.stringify( { theme: state.theme, density: state.density } ) ); } catch ( e ) { /* noop */ }
		},
		setCommandOpen( state, action ) { state.commandOpen = !! action.payload; },
		toggleCommand( state ) { state.commandOpen = ! state.commandOpen; },
	},
} );

export const { setTheme, toggleTheme, setCommandOpen, toggleCommand } = slice.actions;
export default slice.reducer;
