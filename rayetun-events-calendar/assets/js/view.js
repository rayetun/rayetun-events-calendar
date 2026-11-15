/**
 * RayEtun Events Calendar — moving around the calendar without a page reload.
 *
 * Every control is a plain link or a form submit first. This module only
 * intercepts the ones that stay inside the calendar and swaps the region in
 * place, so the address bar, the back button and a blocked module all behave.
 *
 * Links out of the calendar — an event, a venue, the subscribe link — are left
 * alone, because they lead to a different page.
 */

import { store } from '@wordpress/interactivity';

/**
 * Whether the visitor means to open the link in this tab.
 *
 * @param {MouseEvent} event Click.
 * @return {boolean} True for a plain left click.
 */
function plainClick( event ) {
	return (
		! event.defaultPrevented &&
		0 === event.button &&
		! event.metaKey &&
		! event.ctrlKey &&
		! event.shiftKey &&
		! event.altKey
	);
}

/**
 * Tell calendar.js the grid has changed under it.
 */
function updated() {
	document.dispatchEvent( new CustomEvent( 'rayec-updated' ) );
}

store( 'rayec', {
	actions: {
		/**
		 * Follow a calendar control in place. Bound once, on the calendar itself.
		 *
		 * @param {MouseEvent} event Click.
		 */
		*navigate( event ) {
			const link =
				event.target.closest && event.target.closest( 'a[data-rayec-nav]' );
			if ( ! link || ! plainClick( event ) ) {
				return;
			}
			event.preventDefault();
			const { actions } = yield import( '@wordpress/interactivity-router' );
			yield actions.navigate( link.href );
			updated();
		},

		/**
		 * Run a search or a filter change in place.
		 *
		 * @param {SubmitEvent} event Submit.
		 */
		*search( event ) {
			event.preventDefault();
			const form = event.target;
			const url = new URL(
				form.getAttribute( 'action' ) || window.location.href,
				window.location.href
			);
			const params = new URLSearchParams();
			new FormData( form ).forEach( function ( value, key ) {
				// Empty fields are left out, so the address stays readable.
				if ( '' !== value ) {
					params.append( key, value );
				}
			} );
			url.search = params.toString();

			const { actions } = yield import( '@wordpress/interactivity-router' );
			yield actions.navigate( url.href );
			updated();
		},

		/**
		 * A filter applies itself as soon as it is changed.
		 *
		 * @param {Event} event Change.
		 */
		apply( event ) {
			const form = event.target.form;
			if ( form && form.requestSubmit ) {
				form.requestSubmit();
			}
		},
	},
} );
