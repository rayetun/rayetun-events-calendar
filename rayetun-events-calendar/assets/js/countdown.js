/**
 * RayEtun Accessible Events Calendar — countdown.
 *
 * The figure is already written out by PHP, so the block reads correctly with
 * this file blocked. All this does is keep it fresh while the page is open.
 */
( function ( i18n ) {
	'use strict';

	if ( ! i18n ) {
		return;
	}

	var __ = i18n.__;
	var _n = i18n._n;
	var sprintf = i18n.sprintf;
	var DOMAIN = 'rayetun-events-calendar';

	/** How long is left, in the same words PHP uses. */
	function untilText( seconds ) {
		var days = Math.floor( seconds / 86400 );
		var hours = Math.floor( ( seconds % 86400 ) / 3600 );
		var minutes = Math.floor( ( seconds % 3600 ) / 60 );

		var dayText = sprintf( _n( '%d day', '%d days', days, DOMAIN ), days );
		var hourText = sprintf( _n( '%d hour', '%d hours', hours, DOMAIN ), hours );
		var minuteText = sprintf(
			_n( '%d minute', '%d minutes', minutes, DOMAIN ),
			minutes
		);
		/* translators: 1: a longer unit such as "2 days", 2: a shorter one such as "3 hours". */
		var both = __( '%1$s and %2$s', DOMAIN );

		if ( days > 0 ) {
			return hours > 0 ? sprintf( both, dayText, hourText ) : dayText;
		}
		if ( hours > 0 ) {
			return minutes > 0 ? sprintf( both, hourText, minuteText ) : hourText;
		}
		if ( minutes > 0 ) {
			return minuteText;
		}
		return __( 'less than a minute', DOMAIN );
	}

	document
		.querySelectorAll( '[data-rayec-until]' )
		.forEach( function ( element ) {
			var starts = Date.parse( element.getAttribute( 'data-rayec-until' ) );
			var ends = Date.parse( element.getAttribute( 'data-rayec-end' ) );
			if ( isNaN( starts ) ) {
				return;
			}

			var timer = 0;

			function update() {
				var left = Math.round( ( starts - Date.now() ) / 1000 );
				if ( left > 0 ) {
					/* translators: %s: a length of time, such as "2 days and 3 hours". */
					element.textContent = sprintf(
						__( 'Starts in %s', DOMAIN ),
						untilText( left )
					);
					// Tick every second in the last hour, every minute before that.
					timer = window.setTimeout( update, left > 3600 ? 60000 : 1000 );
					return;
				}
				if ( ! isNaN( ends ) && ends > Date.now() ) {
					element.textContent = __( 'Happening now', DOMAIN );
					timer = window.setTimeout( update, 60000 );
					return;
				}
				element.textContent = __( 'This event has finished.', DOMAIN );
			}

			update();

			// Stop counting while the tab is hidden, and catch up on return.
			document.addEventListener( 'visibilitychange', function () {
				window.clearTimeout( timer );
				if ( ! document.hidden ) {
					update();
				}
			} );
		} );
} )( window.wp && window.wp.i18n );
