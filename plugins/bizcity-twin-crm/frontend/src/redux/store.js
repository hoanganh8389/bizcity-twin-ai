import { configureStore } from '@reduxjs/toolkit';
import { crmApi } from './api/crmApi.js';
import { schedulerApi } from './api/schedulerApi.js';
import { bzdocApi } from './api/bzdocApi.js';
// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — register automationCareApi
import { automationCareApi } from './api/automationCareApi.js';
// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — ZNS + Email broadcast via bizcity-channel/v1
import { cgBroadcastApi } from './api/cgBroadcastApi.js';
import uiTabsReducer from './uiTabs.js';
import uiPrefsReducer from './uiPrefs.js';

export const store = configureStore( {
	reducer: {
		[ crmApi.reducerPath ]: crmApi.reducer,
		[ schedulerApi.reducerPath ]: schedulerApi.reducer,
		[ bzdocApi.reducerPath ]: bzdocApi.reducer,
		[ automationCareApi.reducerPath ]: automationCareApi.reducer,
		[ cgBroadcastApi.reducerPath ]: cgBroadcastApi.reducer,
		uiTabs: uiTabsReducer,
		uiPrefs: uiPrefsReducer,
	},
	middleware: ( getDefault ) => getDefault().concat( crmApi.middleware, schedulerApi.middleware, bzdocApi.middleware, automationCareApi.middleware, cgBroadcastApi.middleware ),
} );
