import React from 'react';
import { createRoot } from 'react-dom/client';
import { Provider } from 'react-redux';
import { HashRouter } from 'react-router-dom';
import { store } from './redux/store.js';
import App from './App.jsx';
import './styles.css';

const mountEl = document.getElementById( 'bizcity-crm-inbox-root' );
if ( mountEl ) {
	createRoot( mountEl ).render(
		<Provider store={ store }>
			<HashRouter>
				<App />
			</HashRouter>
		</Provider>
	);
}
