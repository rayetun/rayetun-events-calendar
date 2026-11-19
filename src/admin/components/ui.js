import { useState, useEffect, useRef } from '@wordpress/element';
import { Spinner, Popover } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, useApp } from '../lib';
import { Icon } from '../icons';

/**
 * Load a REST path; reloads when the path changes or the app is refreshed.
 *
 * @param {string|null} path REST path under rayec/v1, or null to skip.
 */
export function useLoad( path ) {
	const { version } = useApp();
	const [ state, setState ] = useState( {
		data: null,
		loading: !! path,
		error: '',
	} );

	useEffect( () => {
		if ( ! path ) {
			return undefined;
		}
		let cancelled = false;
		setState( ( previous ) => ( { ...previous, loading: true } ) );
		api.get( path )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setState( { data, loading: false, error: '' } );
				}
			} )
			.catch( ( error ) => {
				if ( ! cancelled ) {
					setState( {
						data: null,
						loading: false,
						error:
							error?.message ||
							__(
								'Something went wrong while loading.',
								'rayetun-events-calendar'
							),
					} );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ path, version ] );

	return state;
}

export function PageHeader( { title, subtitle, children } ) {
	return (
		<header className="rayec-head">
			<div>
				<h1 className="rayec-head__title">{ title }</h1>
				{ subtitle && (
					<p className="rayec-head__sub">{ subtitle }</p>
				) }
			</div>
			{ children && (
				<div className="rayec-head__actions">{ children }</div>
			) }
		</header>
	);
}

export function Panel( { title, icon, action, children, className = '' } ) {
	return (
		<section className={ `rayec-panel ${ className }` }>
			{ title && (
				<div className="rayec-panel__head">
					<h2 className="rayec-panel__title">
						{ icon && <Icon name={ icon } size={ 16 } /> }
						{ title }
					</h2>
					{ action }
				</div>
			) }
			<div className="rayec-panel__body">{ children }</div>
		</section>
	);
}

export function Loading() {
	return (
		<div className="rayec-loading" role="status">
			<Spinner />
			<span>{ __( 'Loading…', 'rayetun-events-calendar' ) }</span>
		</div>
	);
}

export function ErrorNote( { message } ) {
	return (
		<p className="rayec-error" role="alert">
			<Icon name="alert" />
			{ message }
		</p>
	);
}

export function Empty( { icon = 'planner', title, children, action } ) {
	return (
		<div className="rayec-empty">
			<span className="rayec-empty__icon">
				<Icon name={ icon } size={ 26 } />
			</span>
			<h3 className="rayec-empty__title">{ title }</h3>
			{ children && <p className="rayec-empty__text">{ children }</p> }
			{ action }
		</div>
	);
}

const STATUS_LABELS = {
	cancelled: __( 'Cancelled', 'rayetun-events-calendar' ),
	postponed: __( 'Postponed', 'rayetun-events-calendar' ),
	moved_online: __( 'Moved online', 'rayetun-events-calendar' ),
	draft: __( 'Draft', 'rayetun-events-calendar' ),
	pending: __( 'Pending review', 'rayetun-events-calendar' ),
	private: __( 'Private', 'rayetun-events-calendar' ),
};

/**
 * Badges for anything that is not "published and going ahead".
 *
 * @param {Object} props
 * @param {string} props.eventStatus Event status.
 * @param {string} props.postStatus  Post status.
 */
export function StatusBadges( { eventStatus, postStatus } ) {
	return (
		<>
			{ STATUS_LABELS[ postStatus ] && (
				<span className="rayec-badge rayec-badge--muted">
					{ STATUS_LABELS[ postStatus ] }
				</span>
			) }
			{ STATUS_LABELS[ eventStatus ] && (
				<span className="rayec-badge rayec-badge--warn">
					{ STATUS_LABELS[ eventStatus ] }
				</span>
			) }
		</>
	);
}

/**
 * One occurrence as a clickable row.
 *
 * @param {Object} props
 * @param {Object} props.item Occurrence.
 */
/**
 * "8 of 12 booked", or "3 booked" when there is no limit; empty when the
 * event takes no registrations.
 *
 * @param {number|null} booked   Places booked.
 * @param {number|null} capacity Places on the date, 0 for no limit.
 * @return {string} Label.
 */
export function bookedLabel( booked, capacity ) {
	if ( booked === null || booked === undefined ) {
		return '';
	}
	return capacity
		? sprintf(
				/* translators: 1: places booked, 2: places in total. */
				__( '%1$d of %2$d booked', 'rayetun-events-calendar' ),
				booked,
				capacity
		  )
		: sprintf(
				/* translators: %d: places booked. */
				_n( '%d booked', '%d booked', booked, 'rayetun-events-calendar' ),
				booked
		  );
}

export function OccurrenceRow( { item } ) {
	const { openEvent } = useApp();
	const [ peekProps, peek ] = usePeek( item );
	return (
		<li className="rayec-occ">
			<span
				className="rayec-occ__dot"
				style={ item.color ? { background: item.color } : undefined }
				aria-hidden="true"
			/>
			<button
				type="button"
				className="rayec-occ__main"
				onClick={ () => openEvent( item.event_id ) }
				{ ...peekProps }
			>
				<span className="rayec-occ__title">
					{ item.title }
					{ item.recurring && (
						<span
							className="rayec-occ__repeat"
							title={ __(
								'Repeating event',
								'rayetun-events-calendar'
							) }
						>
							<Icon name="repeat" size={ 13 } />
							<span className="screen-reader-text">
								{ __(
									'(repeats)',
									'rayetun-events-calendar'
								) }
							</span>
						</span>
					) }
				</span>
				<span className="rayec-occ__meta">
					{ item.when_label }
					{ item.venue_name ? ` · ${ item.venue_name }` : '' }
					{ bookedLabel( item.booked, item.capacity )
						? ` · ${ bookedLabel( item.booked, item.capacity ) }`
						: '' }
				</span>
			</button>
			<StatusBadges
				eventStatus={ item.event_status }
				postStatus={ item.post_status }
			/>
			{ peek }
		</li>
	);
}

/**
 * Preview card for an occurrence, shown on hover and on keyboard focus.
 *
 * Returns handlers to spread onto the trigger and the card to render beside it.
 * The card repeats what opening the event shows, so it is hidden from assistive
 * technology; it stays open while the pointer is over it and closes with Escape.
 *
 * @param {Object} item Occurrence.
 */
export function usePeek( item ) {
	const [ open, setOpen ] = useState( false );
	const [ anchor, setAnchor ] = useState( null );
	const timer = useRef( 0 );

	useEffect( () => () => clearTimeout( timer.current ), [] );

	const later = ( value, delay ) => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => setOpen( value ), delay );
	};
	const close = () => {
		clearTimeout( timer.current );
		setOpen( false );
	};

	const triggerProps = {
		ref: setAnchor,
		onMouseEnter: () => later( true, 250 ),
		onMouseLeave: () => later( false, 180 ),
		onFocus: () => later( true, 0 ),
		onBlur: () => later( false, 120 ),
		onMouseDown: close,
		onDragStart: close,
		onKeyDown: ( event ) => {
			if ( event.key === 'Escape' && open ) {
				event.stopPropagation();
				close();
			}
		},
	};

	const card = open && anchor && (
		<Popover
			anchor={ anchor }
			placement="right-start"
			offset={ 8 }
			focusOnMount={ false }
			noArrow
			className="rayec-peek"
		>
			<div
				className="rayec-peek__card"
				aria-hidden="true"
				onMouseEnter={ () => clearTimeout( timer.current ) }
				onMouseLeave={ () => later( false, 180 ) }
			>
				{ item.image && (
					<img src={ item.image } alt="" loading="lazy" />
				) }
				<div className="rayec-peek__body">
					<p className="rayec-peek__when">{ item.when_label }</p>
					<p className="rayec-peek__title">{ item.title }</p>
					{ ( item.venue_name || item.format === 'online' ) && (
						<p className="rayec-peek__meta">
							<Icon name="pin" size={ 13 } />
							{ item.format === 'online'
								? __( 'Online', 'rayetun-events-calendar' )
								: item.venue_name }
						</p>
					) }
					{ item.rrule_text && (
						<p className="rayec-peek__meta">
							<Icon name="repeat" size={ 13 } />
							{ item.rrule_text }
						</p>
					) }
					{ item.summary && (
						<p className="rayec-peek__text">{ item.summary }</p>
					) }
					<p className="rayec-peek__badges">
						<StatusBadges
							eventStatus={ item.event_status }
							postStatus={ item.post_status }
						/>
					</p>
				</div>
			</div>
		</Popover>
	);

	return [ triggerProps, card ];
}