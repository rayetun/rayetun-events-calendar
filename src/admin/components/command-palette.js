import { useState, useEffect, useRef } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { applyFilters } from '@wordpress/hooks';

import { api, useApp } from '../lib';
import { Icon } from '../icons';

/**
 * Ctrl or Cmd + K: find an event, start one, or go anywhere, from the keyboard.
 *
 * The search box is a combobox and the results a listbox: arrow keys move
 * through the results while focus stays in the box, Enter does what is
 * highlighted, and Escape closes. How many results there are is announced
 * once the typing stops, not on every key.
 *
 * @param {Object}   props
 * @param {Array}    props.screens  Screens of the dashboard.
 * @param {Function} props.onClose  Close the palette.
 * @param {Function} props.onSetup  Open the setup guide.
 */
export default function CommandPalette( { screens, onClose, onSetup } ) {
	const { openEvent, go, notify, refresh } = useApp();
	const [ query, setQuery ] = useState( '' );
	const [ events, setEvents ] = useState( [] );
	const [ searching, setSearching ] = useState( false );
	const [ active, setActive ] = useState( 0 );
	const list = useRef();

	const needle = query.trim().toLowerCase();

	// Events matching what is typed, from two letters on.
	useEffect( () => {
		if ( needle.length < 2 ) {
			setEvents( [] );
			return undefined;
		}
		let stale = false;
		setSearching( true );
		const timer = setTimeout( () => {
			api.get(
				`/events?when=all&per_page=6&search=${ encodeURIComponent(
					needle
				) }`
			)
				.then( ( result ) => {
					if ( ! stale ) {
						setEvents( result.items || [] );
					}
				} )
				.catch( () => ! stale && setEvents( [] ) )
				.finally( () => ! stale && setSearching( false ) );
		}, 220 );
		return () => {
			stale = true;
			clearTimeout( timer );
		};
	}, [ needle ] );

	const duplicate = ( event ) =>
		api
			.post( `/events/${ event.id }/duplicate` )
			.then( ( copy ) => {
				notify(
					sprintf(
						/* translators: %s: event title. */
						__(
							'Copied “%s” as a draft.',
							'rayetun-events-calendar'
						),
						event.title
					)
				);
				refresh();
				openEvent( copy.id );
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	/**
	 * Things to do. Add-ons add their own: { id, label, icon, run() }.
	 */
	const commands = applyFilters( 'rayec.commands', [
		{
			id: 'new',
			label: __( 'New event', 'rayetun-events-calendar' ),
			icon: 'plus',
			run: () => openEvent( {} ),
		},
		...screens.map( ( screen ) => ( {
			id: `go${ screen.path.replace( /\//g, '-' ) }`,
			/* translators: %s: name of a dashboard screen, such as "Planner". */
			label: sprintf( __( 'Go to %s', 'rayetun-events-calendar' ), screen.label ),
			icon: screen.icon,
			run: () => go( screen.path ),
		} ) ),
		{
			id: 'setup',
			label: __( 'Open the setup guide', 'rayetun-events-calendar' ),
			icon: 'help',
			run: onSetup,
		},
	] );

	const items = [
		...commands.filter(
			( command ) =>
				! needle || command.label.toLowerCase().includes( needle )
		),
		...events.flatMap( ( event ) => [
			{
				id: `open-${ event.id }`,
				label: event.title,
				hint: event.next
					? event.next.when_label
					: __( 'No dates to come', 'rayetun-events-calendar' ),
				icon: 'planner',
				run: () => openEvent( event.id ),
			},
			{
				id: `copy-${ event.id }`,
				label: sprintf(
					/* translators: %s: event title. */
					__( 'Duplicate “%s”', 'rayetun-events-calendar' ),
					event.title
				),
				icon: 'layers',
				run: () => duplicate( event ),
			},
		] ),
	];

	// Back to the top whenever the results change.
	useEffect( () => setActive( 0 ), [ needle, events ] );

	// Say how many there are once the typing has settled.
	useEffect( () => {
		if ( searching ) {
			return undefined;
		}
		const timer = setTimeout( () => {
			speak(
				items.length
					? sprintf(
							/* translators: %d: number of results. */
							_n(
								'%d result. Use the up and down arrows to choose.',
								'%d results. Use the up and down arrows to choose.',
								items.length,
								'rayetun-events-calendar'
							),
							items.length
					  )
					: __( 'Nothing found.', 'rayetun-events-calendar' )
			);
		}, 500 );
		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ items.length, searching ] );

	// Keep the highlighted result in view.
	useEffect( () => {
		const node = list.current?.querySelector( '[aria-selected="true"]' );
		if ( node && node.scrollIntoView ) {
			node.scrollIntoView( { block: 'nearest' } );
		}
	}, [ active ] );

	const run = ( item ) => {
		onClose();
		item.run();
	};

	const onKeyDown = ( event ) => {
		if ( ! items.length ) {
			return;
		}
		if ( event.key === 'ArrowDown' ) {
			event.preventDefault();
			setActive( ( active + 1 ) % items.length );
		} else if ( event.key === 'ArrowUp' ) {
			event.preventDefault();
			setActive( ( active - 1 + items.length ) % items.length );
		} else if ( event.key === 'Home' && event.ctrlKey ) {
			event.preventDefault();
			setActive( 0 );
		} else if ( event.key === 'End' && event.ctrlKey ) {
			event.preventDefault();
			setActive( items.length - 1 );
		} else if ( event.key === 'Enter' ) {
			event.preventDefault();
			run( items[ active ] );
		}
	};

	const current = items[ active ];

	return (
		<Modal
			title={ __( 'Find or do anything', 'rayetun-events-calendar' ) }
			onRequestClose={ onClose }
			className="rayec-palette"
			overlayClassName="rayec-palette-overlay"
		>
			<div className="rayec-palette__search">
				<input
					type="text"
					// The palette opens to type into: focus belongs here.
					// eslint-disable-next-line jsx-a11y/no-autofocus
					autoFocus
					role="combobox"
					aria-expanded="true"
					aria-controls="rayec-palette-list"
					aria-autocomplete="list"
					aria-activedescendant={
						current ? `rayec-palette-${ current.id }` : undefined
					}
					aria-label={ __(
						'Search for an action or an event',
						'rayetun-events-calendar'
					) }
					placeholder={ __(
						'Type an event name, or an action such as “new”…',
						'rayetun-events-calendar'
					) }
					value={ query }
					onChange={ ( event ) => setQuery( event.target.value ) }
					onKeyDown={ onKeyDown }
				/>
				{ searching && <Spinner /> }
			</div>

			<ul
				id="rayec-palette-list"
				role="listbox"
				ref={ list }
				className="rayec-palette__list"
				aria-label={ __( 'Results', 'rayetun-events-calendar' ) }
			>
				{ items.map( ( item, index ) => (
					// The input owns the keyboard (aria-activedescendant), so the
					// options only need to answer the mouse.
					// eslint-disable-next-line jsx-a11y/click-events-have-key-events
					<li
						key={ item.id }
						id={ `rayec-palette-${ item.id }` }
						role="option"
						aria-selected={ index === active }
						className={ `rayec-palette__item${
							index === active ? ' is-active' : ''
						}` }
						onMouseMove={ () => index !== active && setActive( index ) }
						onClick={ () => run( item ) }
					>
						<Icon name={ item.icon || 'more' } size={ 16 } />
						<span className="rayec-palette__label">
							{ item.label }
						</span>
						{ item.hint && (
							<span className="rayec-palette__hint">
								{ item.hint }
							</span>
						) }
					</li>
				) ) }
			</ul>
			{ ! items.length && ! searching && (
				<p className="rayec-palette__none">
					{ __( 'Nothing found.', 'rayetun-events-calendar' ) }
				</p>
			) }
			<p className="rayec-palette__keys" aria-hidden="true">
				{ __(
					'↑ ↓ to choose · Enter to open · Esc to close',
					'rayetun-events-calendar'
				) }
			</p>
		</Modal>
	);
}
