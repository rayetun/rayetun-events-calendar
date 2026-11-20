/**
 * RayEtun Accessible Events Calendar — front-end enhancements.
 *
 * Everything on the page works without this file. With it:
 * 1. the month grid gains arrow-key navigation,
 * 2. events show a preview card on hover and on keyboard focus,
 * 3. venue maps load from OpenStreetMap when the visitor asks for them.
 */
( function () {
	'use strict';

	/* 1. Arrow keys in the month grid ----------------------------------- */

	/**
	 * The days of one grid, read fresh each time: a month change swaps them.
	 *
	 * @param {HTMLElement} table The grid.
	 * @return {Array} The day cells.
	 */
	function daysOf( table ) {
		return Array.prototype.slice.call(
			table.querySelectorAll( 'td[data-rayec-date]' )
		);
	}

	function setUpGrid( table ) {
		var cells = daysOf( table );
		if ( ! cells.length ) {
			return;
		}

		function setCurrent( cell, focus ) {
			var old = table.querySelector( 'td[tabindex="0"]' );
			if ( old ) {
				old.setAttribute( 'tabindex', '-1' );
			}
			cell.setAttribute( 'tabindex', '0' );
			if ( focus ) {
				cell.focus();
			}
		}

		cells.forEach( function ( cell ) {
			cell.setAttribute( 'tabindex', '-1' );
		} );
		setCurrent(
			table.querySelector( 'td.is-today' ) ||
				table.querySelector( 'td.has-events:not(.is-outside)' ) ||
				table.querySelector( 'td:not(.is-outside)' ) ||
				cells[ 0 ],
			false
		);

		// The listeners go on the table, which survives an in-place month change.
		if ( table.hasAttribute( 'data-rayec-keys' ) ) {
			return;
		}
		table.setAttribute( 'data-rayec-keys', '' );

		table.addEventListener( 'keydown', function ( event ) {
			var cell = event.target.closest( 'td[data-rayec-date]' );
			if ( ! cell ) {
				return;
			}
			cells = daysOf( table );
			var index = cells.indexOf( cell );
			var column = index % 7;
			var next = null;

			switch ( event.key ) {
				case 'ArrowRight':
					next = index + 1;
					break;
				case 'ArrowLeft':
					next = index - 1;
					break;
				case 'ArrowDown':
					next = index + 7;
					break;
				case 'ArrowUp':
					next = index - 7;
					break;
				case 'Home':
					next = index - column;
					break;
				case 'End':
					next = index - column + 6;
					break;
				case 'PageDown':
					next = cells.length - 7 + column;
					break;
				case 'PageUp':
					next = column;
					break;
				case 'Enter':
					if ( event.target === cell ) {
						var link = cell.querySelector( 'a' );
						if ( link ) {
							event.preventDefault();
							link.focus();
						}
					}
					return;
				default:
					return;
			}

			if ( next !== null && next >= 0 && next < cells.length ) {
				event.preventDefault();
				setCurrent( cells[ next ], true );
			}
		} );

		table.addEventListener( 'focusin', function ( event ) {
			var cell = event.target.closest( 'td[data-rayec-date]' );
			if ( cell && '0' !== cell.getAttribute( 'tabindex' ) ) {
				setCurrent( cell, false );
			}
		} );
	}

	function setUpGrids() {
		document.querySelectorAll( '[data-rayec-grid]' ).forEach( setUpGrid );
	}

	setUpGrids();
	// The calendar can change in place, without a page load; see view.js.
	document.addEventListener( 'rayec-updated', setUpGrids );
	// And again when the visitor goes back or forward through those changes.
	window.addEventListener( 'popstate', function () {
		window.setTimeout( setUpGrids, 0 );
	} );

	/* 2. Preview cards ---------------------------------------------------
	 * The card repeats what the event page says, so it is hidden from
	 * assistive technology. It stays open while the pointer is over it,
	 * closes with Escape, and never traps focus (WCAG 1.4.13).
	 */

	var canHover = window.matchMedia && window.matchMedia( '(hover: hover)' ).matches;
	var card = null;
	var owner = null;
	var hideTimer = 0;
	var showTimer = 0;

	function hideCard() {
		clearTimeout( showTimer );
		clearTimeout( hideTimer );
		if ( card ) {
			card.hidden = true;
		}
		owner = null;
	}

	function showCard( chip ) {
		var source = document.getElementById( chip.getAttribute( 'data-rayec-pv' ) );
		var calendar = chip.closest( '.rayec-cal' );
		if ( ! source || ! calendar || window.innerWidth <= 640 ) {
			return;
		}
		if ( ! card ) {
			card = document.createElement( 'div' );
			card.className = 'rayec-pv';
			card.setAttribute( 'aria-hidden', 'true' );
			card.addEventListener( 'mouseenter', function () {
				clearTimeout( hideTimer );
			} );
			card.addEventListener( 'mouseleave', hideCard );
		}
		if ( card.parentNode !== calendar ) {
			calendar.appendChild( card );
		}

		card.innerHTML = source.innerHTML;
		var when = card.querySelector( '.rayec-pv__when' );
		if ( when ) {
			when.textContent = chip.getAttribute( 'data-rayec-when' ) || '';
		}
		card.hidden = false;
		owner = chip;

		// Place it beside the chip, flipping to stay inside the calendar and the viewport.
		var box = calendar.getBoundingClientRect();
		var rect = chip.getBoundingClientRect();
		var width = card.offsetWidth;
		var height = card.offsetHeight;
		var left = rect.left - box.left;
		if ( left + width > box.width ) {
			left = Math.max( 0, box.width - width );
		}
		var top = rect.bottom - box.top + 6;
		if ( rect.bottom + height + 12 > window.innerHeight && rect.top - height - 6 > 0 ) {
			top = rect.top - box.top - height - 6;
		}
		card.style.left = left + 'px';
		card.style.top = top + 'px';
	}

	document.addEventListener( 'mouseover', function ( event ) {
		if ( ! canHover ) {
			return;
		}
		var chip = event.target.closest && event.target.closest( '[data-rayec-pv]' );
		if ( chip && chip !== owner ) {
			clearTimeout( hideTimer );
			clearTimeout( showTimer );
			showTimer = setTimeout( function () {
				showCard( chip );
			}, 180 );
		}
	} );

	document.addEventListener( 'mouseout', function ( event ) {
		var chip = event.target.closest && event.target.closest( '[data-rayec-pv]' );
		if ( chip && ! chip.contains( event.relatedTarget ) ) {
			clearTimeout( showTimer );
			// A short grace period lets the pointer travel onto the card.
			hideTimer = setTimeout( hideCard, 220 );
		}
	} );

	document.addEventListener( 'focusin', function ( event ) {
		var chip = event.target.closest && event.target.closest( '[data-rayec-pv]' );
		if ( chip ) {
			showCard( chip );
		} else {
			hideCard();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			hideCard();
		}
	} );

	/* 3. Click-to-load maps --------------------------------------------- */

	document.querySelectorAll( '[data-rayec-map]' ).forEach( function ( holder ) {
		var button = holder.querySelector( 'button' );
		var lat = parseFloat( holder.getAttribute( 'data-lat' ) );
		var lng = parseFloat( holder.getAttribute( 'data-lng' ) );
		if ( ! button || isNaN( lat ) || isNaN( lng ) ) {
			return;
		}
		button.hidden = false;

		button.addEventListener( 'click', function () {
			var bbox = [ lng - 0.006, lat - 0.0035, lng + 0.006, lat + 0.0035 ].join( ',' );
			var frame = document.createElement( 'iframe' );
			frame.src =
				'https://www.openstreetmap.org/export/embed.html?bbox=' +
				encodeURIComponent( bbox ) +
				'&layer=mapnik&marker=' +
				encodeURIComponent( lat + ',' + lng );
			frame.title = holder.getAttribute( 'data-title' ) || '';
			frame.loading = 'lazy';
			frame.referrerPolicy = 'no-referrer';
			holder.innerHTML = '';
			holder.classList.add( 'is-loaded' );
			holder.appendChild( frame );
			frame.focus();
		} );
	} );
} )();
