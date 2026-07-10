import React, { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { Sun, Moon } from 'lucide-react';
import { toggleTheme } from '../redux/uiPrefs.js';

/**
 * Applies the active theme to <html data-theme="..."> and exposes a toggle button.
 */
export default function ThemeToggle() {
	const theme = useSelector( ( s ) => s.uiPrefs.theme );
	const dispatch = useDispatch();

	useEffect( () => {
		const root = document.documentElement;
		root.setAttribute( 'data-theme', theme );
		root.classList.toggle( 'dark', theme === 'dark' );
	}, [ theme ] );

	return (
		<button
			type="button"
			className="bzc-icon-btn"
			onClick={ () => dispatch( toggleTheme() ) }
			title={ theme === 'dark' ? 'Sang chế độ sáng' : 'Sang chế độ tối' }
			aria-label="Toggle theme"
		>
			{ theme === 'dark' ? <Sun size={ 16 } /> : <Moon size={ 16 } /> }
		</button>
	);
}
