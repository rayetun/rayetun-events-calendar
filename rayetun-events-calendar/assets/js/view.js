/**
 * RayEtun Accessible Events Calendar — moving around the calendar without a page reload.
 *
 * Every control is a plain link or a form submit first. This module only
 * intercepts the ones that stay inside the calendar and swaps the region in
 * place, so the address bar, the back button and a blocked module all behave.
 *
 * Links out of the calendar — an event, a venue, the subscribe link — are left
 * alone, because they lead to a different page.
 *
 * The booking card works the same way: its form posts normally, and this
 * module sends it in the background instead and redraws only the card.
 */

import {
	store,
	getContext,
	getConfig,
	withSyncEvent,
} from '@wordpress/interactivity';

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
		 * A date was chosen in the booking form: the details beside the form
		 * (the split template) follow it. Each option carries its own words,
		 * written by the server, so nothing is worked out here.
		 *
		 * @param {Event} event Change.
		 */
		pick( event ) {
			const select = event.target;
			const option = select && select.selectedOptions && select.selectedOptions[ 0 ];
			const card = select && select.closest && select.closest( '.rayec-book' );
			if ( ! option || ! card ) {
				return;
			}
			[ 'date', 'time', 'places' ].forEach( ( key ) => {
				if ( undefined === option.dataset[ key ] ) {
					return;
				}
				card.querySelectorAll( `[data-rayec-pick="${ key }"]` ).forEach(
					( node ) => {
						node.textContent = option.dataset[ key ];
					}
				);
			} );
			if ( undefined !== option.dataset.fill ) {
				card.querySelectorAll( '[data-rayec-pick="fill"]' ).forEach(
					( node ) => {
						node.style.width = `${ option.dataset.fill }%`;
						node.parentNode.classList.toggle(
							'is-full',
							'100' === option.dataset.fill
						);
					}
				);
			}
		},

		/**
		 * Follow a calendar control in place. Bound once, on the calendar itself.
		 *
		 * @param {MouseEvent} event Click.
		 */
		navigate: withSyncEvent( function* ( event ) {
			const link =
				event.target.closest && event.target.closest( 'a[data-rayec-nav]' );
			if ( ! link || ! plainClick( event ) ) {
				return;
			}
			event.preventDefault();
			const { actions } = yield import( '@wordpress/interactivity-router' );
			yield actions.navigate( link.href );
			updated();
		} ),

		/**
		 * Run a search or a filter change in place.
		 *
		 * @param {SubmitEvent} event Submit.
		 */
		search: withSyncEvent( function* ( event ) {
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
		} ),

		/**
		 * Book a place without leaving the page.
		 *
		 * Bound to the booking card, so it hears the submit of the form inside
		 * it, however often the card is redrawn. The form is sent as it would
		 * be anyway, with one extra field asking for the answer as JSON when
		 * the booking goes through:
		 *
		 * - Booked: the card is redrawn from the page that says so, and focus
		 *   moves to that message. An answer that leads somewhere else, such
		 *   as an add-on's checkout, is followed as a normal link.
		 * - Not booked: the page comes back with the form and its errors, the
		 *   card is redrawn from it and focus moves to the list of errors.
		 *
		 * Anything unexpected falls back to posting the form normally, so a
		 * booking is never lost to the script.
		 *
		 * @param {SubmitEvent} event Submit.
		 */
		book: withSyncEvent( function* ( event ) {
			const form = event.target;
			if (
				! form.classList ||
				! form.classList.contains( 'rayec-book__form' ) ||
				// A page that turns client navigation off (core's Query Loop
				// can) gets the ordinary form.
				( getConfig( 'core/router' ) || {} ).clientNavigationDisabled
			) {
				return;
			}
			event.preventDefault();
			const context = getContext();
			if ( context.busy ) {
				return;
			}
			context.busy = true;

			const id = form.elements.rayec_rsvp_event.value;
			const here = new URL( window.location.href );
			here.hash = '';
			const body = new FormData( form );
			body.append( 'rayec_rsvp_js', '1' );

			let to = null;
			let html = '';
			try {
				const response = yield window.fetch( form.action, {
					method: 'POST',
					body,
					credentials: 'same-origin',
				} );
				const type = response.headers.get( 'content-type' ) || '';
				if ( response.ok && type.includes( 'application/json' ) ) {
					const answer = yield response.json();
					to = new URL( answer.redirect, window.location.href );
				} else if ( response.ok && type.includes( 'text/html' ) ) {
					html = yield response.text();
				}
			} catch ( error ) {}

			if ( to && ( to.origin !== here.origin || to.pathname !== here.pathname ) ) {
				window.location.assign( to.href );
				return;
			}
			if ( ! to && ! html ) {
				// Nothing usable came back: send the form the ordinary way.
				context.busy = false;
				form.submit();
				return;
			}

			const { actions } = yield import( '@wordpress/interactivity-router' );
			if ( to ) {
				to.hash = '';
				yield actions.navigate( to.href, {
					replace: true,
					force: true,
					loadingAnimation: false,
					screenReaderAnnouncement: false,
				} );
			} else {
				yield actions.navigate( here.href, {
					html,
					replace: true,
					force: true,
					loadingAnimation: false,
					screenReaderAnnouncement: false,
				} );
			}
			// The redrawn card keeps this context (the router carries context
			// across a navigation), so it has to be told it is no longer busy.
			context.busy = false;
			updated();

			// Say what happened, the way the page would after a reload: focus
			// the message, which a screen reader then reads out.
			yield new Promise( ( resolve ) => window.requestAnimationFrame( resolve ) );
			const message =
				document.getElementById( `rayec-register-errors-${ id }` ) ||
				document.getElementById( `rayec-register-status-${ id }` );
			if ( message ) {
				message.focus();
			}
		} ),

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
