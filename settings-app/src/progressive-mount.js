import { useEffect } from '@wordpress/element';

export function useProgressiveMount( appId, fallbackId ) {
	useEffect( () => {
		const app = document.getElementById( appId );
		const fallback = document.getElementById( fallbackId );
		if ( ! app || ! fallback ) {
			return undefined;
		}

		app.hidden = false;
		fallback.hidden = true;

		return () => {
			app.hidden = true;
			fallback.hidden = false;
		};
	}, [ appId, fallbackId ] );
}
