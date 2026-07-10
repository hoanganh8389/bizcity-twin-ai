import { createSlice } from '@reduxjs/toolkit';

/**
 * UI tab state — top tab bar in TwinChat-style single viewport.
 * activeTab persists in URL hash (handled in shell/TabBar).
 */
const initialState = {
	activeTab: 'dashboard',
	lastVisited: {}, // { tabId: timestamp }
};

const slice = createSlice( {
	name: 'uiTabs',
	initialState,
	reducers: {
		setActiveTab( state, action ) {
			state.activeTab = action.payload;
			state.lastVisited[ action.payload ] = Date.now();
		},
	},
} );

export const { setActiveTab } = slice.actions;
export default slice.reducer;
