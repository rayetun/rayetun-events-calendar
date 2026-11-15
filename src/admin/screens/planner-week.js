import { useState, useMemo, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { config, formatDate } from '../lib';
import { Icon } from '../icons';

/** Pixels per minute: one hour is 48px tall. */
export const PX = 0.8;
const SNAP = 15;
const DAY = 1440;

/**
 * What is being dragged right now. The browser hides drag data until the drop,
 * so the board keeps its own note to draw a preview while the pointer moves.
 */
export const drag = { item: null, grabY: 0, day: '', first: true, resizing: false };

/** Minutes after midnight of a "YYYY-MM-DDTHH:MM" stamp. */
export const minutesOf = ( stamp ) =>
	parseInt( stamp.slice( 11, 13 ), 10 ) * 60 +
	parseInt( stamp.slice( 14, 16 ), 10 );

/** Length of an occurrence in minutes. */
export const lengthOf = ( item ) =>
	Math.round(
		( Date.parse( item.end + ':00Z' ) - Date.parse( item.start + ':00Z' ) ) /
			60000
	);

/** A time of day in the site's language, from minutes after midnight. */
export const clock = ( minutes ) =>
	new Intl.DateTimeFormat( config.locale || undefined, {
		timeZone: 'UTC',
		hour: 'numeric',
		minute: '2-digit',
	} ).format( new Date( Date.UTC( 2024, 0, 1, 0, minutes ) ) );

const hhmm = ( minutes ) =>
	`${ String( Math.floor( minutes / 60 ) ).padStart( 2, '0' ) }:${ String(
		minutes % 60
	).padStart( 2, '0' ) }`;

/** The timed events of one day, laid out side by side where they overlap. */
function segmentsFor( items, day ) {
	const list = [];
	items.forEach( ( item ) => {
		if ( item.all_day ) {
			return;
		}
		const startDay = item.start.slice( 0, 10 );
		const endDay = item.end.slice( 0, 10 );
		if ( startDay > day || endDay < day ) {
			return;
		}
		const from = startDay === day ? minutesOf( item.start ) : 0;
		let to = endDay === day ? minutesOf( item.end ) : DAY;
		if ( startDay !== day && to === 0 ) {
			// Ended at midnight: nothing to show on this day.
			return;
		}
		if ( to <= from ) {
			to = from + SNAP;
		}
		list.push( {
			item,
			from,
			to,
			// Short events still need room for a line of text.
			visualTo: Math.max( to, from + 28 ),
			first: startDay === day,
			last: endDay === day,
		} );
	} );
	list.sort( ( a, b ) => a.from - b.from || b.to - a.to );

	let cluster = [];
	let clusterEnd = -1;
	const flush = () => {
		const cols = Math.max( ...cluster.map( ( seg ) => seg.col ) ) + 1;
		cluster.forEach( ( seg ) => {
			seg.cols = cols;
		} );
		cluster = [];
		clusterEnd = -1;
	};
	list.forEach( ( seg ) => {
		if ( cluster.length && seg.from >= clusterEnd ) {
			flush();
		}
		const used = cluster
			.filter( ( other ) => other.visualTo > seg.from )
			.map( ( other ) => other.col );
		let col = 0;
		while ( used.includes( col ) ) {
			col++;
		}
		seg.col = col;
		cluster.push( seg );
		clusterEnd = Math.max( clusterEnd, seg.visualTo );
	} );
	if ( cluster.length ) {
		flush();
	}
	return list;
}

const isOurs = ( event ) =>
	event.dataTransfer.types.includes( 'application/x-rayec' );

const dropped = ( event ) => {
	try {
		return JSON.parse( event.dataTransfer.getData( 'application/x-rayec' ) );
	} catch ( e ) {
		return null; // Not one of ours.
	}
};

/**
 * Seven days as columns with a time scale.
 *
 * Drag a block to another day or time, or pull its lower edge to change its
 * length. Every one of those changes is also in the block's menu, so nothing
 * here needs a pointer.
 */
export default function WeekBoard( {
	days,
	items,
	now,
	renderChip,
	onDropAt,
	onResize,
	onAdd,
	lanes,
	laneOf,
} ) {
	const [ ghost, setGhost ] = useState( null );
	const [ resize, setResize ] = useState( null );
	const session = useRef( null );

	// Columns are the days of a week, or, with lanes, the venues and rooms of one day.
	const cols = useMemo(
		() =>
			lanes
				? lanes.map( ( lane ) => ( {
						key: lane.key,
						day: days[ 0 ],
						lane,
						items: items.filter(
							( item ) => laneOf( item ) === lane.key
						),
				  } ) )
				: days.map( ( day ) => ( { key: day, day, lane: null, items } ) ),
		// eslint-disable-next-line react-hooks/exhaustive-deps -- laneOf is rebuilt with lanes.
		[ days, items, lanes ]
	);

	const columns = useMemo(
		() => cols.map( ( col ) => segmentsFor( col.items, col.day ) ),
		[ cols ]
	);

	const allDay = useMemo(
		() =>
			cols.map( ( col ) =>
				col.items.filter(
					( item ) =>
						item.all_day &&
						item.start.slice( 0, 10 ) <= col.day &&
						item.end.slice( 0, 10 ) >= col.day
				)
			),
		[ cols ]
	);

	// 7am to 9pm by default, stretched to fit anything earlier or later.
	const [ lo, hi ] = useMemo( () => {
		let from = 7 * 60;
		let to = 21 * 60;
		columns.flat().forEach( ( seg ) => {
			from = Math.min( from, Math.floor( seg.from / 60 ) * 60 );
			to = Math.max( to, Math.ceil( seg.visualTo / 60 ) * 60 );
		} );
		return [ Math.max( 0, from ), Math.min( DAY, to ) ];
	}, [ columns ] );

	const height = ( hi - lo ) * PX;
	const hours = [];
	for ( let minute = lo; minute < hi; minute += 60 ) {
		hours.push( minute );
	}

	const timeAt = ( event, snap, offset = 0 ) => {
		const rect = event.currentTarget.getBoundingClientRect();
		const raw = lo + ( event.clientY - rect.top - offset ) / PX;
		return Math.max(
			lo,
			Math.min( hi - SNAP, Math.round( raw / snap ) * snap )
		);
	};

	// Only the first day of a timed event can change its start time.
	const dragsTime = () =>
		drag.item && ! drag.item.all_day && drag.first !== false;

	const over = ( event, day, timed ) => {
		if ( ! isOurs( event ) ) {
			return;
		}
		event.preventDefault();
		const minutes =
			timed && dragsTime() ? timeAt( event, SNAP, drag.grabY ) : null;
		setGhost( ( current ) =>
			current &&
			current.day === day &&
			current.minutes === minutes &&
			current.timed === timed
				? current
				: { day, minutes, timed }
		);
	};

	const drop = ( event, day, timed, lane ) => {
		event.preventDefault();
		setGhost( null );
		const item = dropped( event );
		if ( ! item ) {
			return;
		}
		const minutes =
			timed && ! item.all_day && drag.first !== false
				? timeAt( event, SNAP, drag.grabY )
				: null;
		onDropAt( item, day, minutes, drag.day || item.date, lane );
	};

	const leave = ( event ) => {
		if ( ! event.currentTarget.contains( event.relatedTarget ) ) {
			setGhost( null );
		}
	};

	/* Stretch: pull the lower edge of a block. */
	const resizeStart = ( event, seg ) => {
		event.stopPropagation();
		try {
			event.currentTarget.setPointerCapture( event.pointerId );
		} catch ( e ) {
			// Without capture the stretch still works while the pointer stays on the handle.
		}
		drag.resizing = true;
		session.current = { y: event.clientY, seg, delta: 0 };
		setResize( { id: seg.item.id, delta: 0 } );
	};
	const resizeMove = ( event ) => {
		const current = session.current;
		if ( ! current ) {
			return;
		}
		const { seg } = current;
		let delta =
			Math.round( ( event.clientY - current.y ) / PX / SNAP ) * SNAP;
		delta = Math.max(
			SNAP - ( seg.to - seg.from ),
			Math.min( DAY - seg.to, delta )
		);
		if ( delta !== current.delta ) {
			current.delta = delta;
			setResize( { id: seg.item.id, delta } );
		}
	};
	const resizeEnd = ( commit ) => {
		const current = session.current;
		session.current = null;
		drag.resizing = false;
		setResize( null );
		if ( commit && current && current.delta ) {
			onResize(
				current.seg.item,
				Math.max( SNAP, lengthOf( current.seg.item ) + current.delta )
			);
		}
	};

	const dayLabel = ( day ) =>
		formatDate( day, { weekday: 'long', day: 'numeric', month: 'long' } );
	const label = ( day, lane ) =>
		lane ? `${ lane.label }, ${ dayLabel( day ) }` : dayLabel( day );

	return (
		<div className="rayec-wk-scroll">
			<div
				className={ `rayec-wk${ lanes ? ' has-lanes' : '' }` }
				style={ {
					'--rayec-wk-height': `${ height }px`,
					'--rayec-wk-cols': cols.length,
					minWidth: lanes ? 58 + cols.length * 150 : undefined,
				} }
			>
				<div className="rayec-wk__row rayec-wk__head">
					<div className="rayec-wk__gutter" />
					{ cols.map( ( { day, key, lane } ) => (
						<div
							key={ key }
							className={ `rayec-wk__dayhead${
								day === now ? ' is-today' : ''
							}` }
						>
							<h3
								className="rayec-wk__dayname"
								aria-current={ day === now ? 'date' : undefined }
							>
								<span aria-hidden="true">
									{ lane ? (
										<span className="rayec-wk__lane">
											{ lane.label }
										</span>
									) : (
										formatDate( day, { weekday: 'short' } )
									) }
									<span className="rayec-board__num">
										{ parseInt( day.slice( 8 ), 10 ) }
									</span>
								</span>
								<span className="screen-reader-text">
									{ label( day, lane ) }
								</span>
							</h3>
							<button
								type="button"
								className="rayec-board__add"
								onClick={ () => onAdd( day, undefined, lane ) }
								aria-label={ sprintf(
									/* translators: %s: date. */
									__(
										'New event on %s',
										'rayetun-events-calendar'
									),
									label( day, lane )
								) }
							>
								<Icon name="plus" size={ 14 } />
							</button>
						</div>
					) ) }
				</div>

				<div className="rayec-wk__row rayec-wk__allday">
					<div className="rayec-wk__gutter">
						{ __( 'All day', 'rayetun-events-calendar' ) }
					</div>
					{ cols.map( ( { day, key, lane }, index ) => (
						<div
							key={ key }
							className={ `rayec-wk__cell${
								ghost && ghost.day === key && ! ghost.timed
									? ' is-drop'
									: ''
							}` }
							onDragOver={ ( event ) => over( event, key, false ) }
							onDragLeave={ leave }
							onDrop={ ( event ) => drop( event, day, false, lane ) }
						>
							{ allDay[ index ].length > 0 && (
								<ul
									className="rayec-board__events"
									aria-label={ sprintf(
										/* translators: %s: date. */
										__(
											'All-day events on %s',
											'rayetun-events-calendar'
										),
										label( day, lane )
									) }
								>
									{ allDay[ index ].map( ( item ) =>
										renderChip( item, {
											key: `${ item.id }-${ day }`,
											day,
											first: true,
										} )
									) }
								</ul>
							) }
						</div>
					) ) }
				</div>

				<div className="rayec-wk__row rayec-wk__body">
					<div className="rayec-wk__gutter" aria-hidden="true">
						{ hours.map( ( minute ) => (
							<span
								key={ minute }
								className="rayec-wk__hour"
								style={ { top: ( minute - lo ) * PX } }
							>
								{ clock( minute ) }
							</span>
						) ) }
					</div>
					{ cols.map( ( { day, key, lane }, index ) => {
						const showGhost =
							ghost && ghost.day === key && ghost.timed;
						const ghostLength = drag.item
							? Math.max( SNAP, lengthOf( drag.item ) )
							: 60;
						return (
							// The column is a pointer shortcut only: the + button above and each block's menu do the same from the keyboard.
							// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
							<div
								key={ key }
								className={ `rayec-wk__col${
									day === now ? ' is-today' : ''
								}${
									showGhost && ghost.minutes === null
										? ' is-drop'
										: ''
								}` }
								onDragOver={ ( event ) => over( event, key, true ) }
								onDragLeave={ leave }
								onDrop={ ( event ) => drop( event, day, true, lane ) }
								onClick={ ( event ) => {
									if ( event.target === event.currentTarget ) {
										onAdd( day, hhmm( timeAt( event, 30 ) ), lane );
									}
								} }
							>
								{ columns[ index ].length > 0 && (
									<ul
										className="rayec-wk__events"
										aria-label={ label( day, lane ) }
									>
										{ columns[ index ].map( ( seg ) => {
											const stretching =
												resize &&
												resize.id === seg.item.id &&
												seg.last;
											const to =
												seg.to +
												( stretching ? resize.delta : 0 );
											const width = 100 / seg.cols;
											return renderChip( seg.item, {
												key: `${ seg.item.id }-${ day }`,
												day,
												first: seg.first,
												className: stretching
													? 'is-stretching'
													: '',
												note: stretching
													? sprintf(
															/* translators: %s: time of day. */
															__(
																'until %s',
																'rayetun-events-calendar'
															),
															clock( to % DAY )
													  )
													: '',
												style: {
													top: ( seg.from - lo ) * PX,
													height: Math.max(
														22,
														( to - seg.from ) * PX - 1
													),
													insetInlineStart: `${
														seg.col * width
													}%`,
													width: `calc(${ width }% - 3px)`,
												},
												handle: seg.last && ! seg.item.multi && (
													<span
														className="rayec-wk__handle"
														aria-hidden="true"
														onMouseDown={ ( event ) =>
															event.preventDefault()
														}
														onPointerDown={ ( event ) =>
															resizeStart( event, seg )
														}
														onPointerMove={ resizeMove }
														onPointerUp={ () =>
															resizeEnd( true )
														}
														onPointerCancel={ () =>
															resizeEnd( false )
														}
													/>
												),
											} );
										} ) }
									</ul>
								) }
								{ showGhost && ghost.minutes !== null && (
									<div
										className="rayec-wk__ghost"
										aria-hidden="true"
										style={ {
											top: ( ghost.minutes - lo ) * PX,
											height: ghostLength * PX,
										} }
									>
										{ clock( ghost.minutes ) }
									</div>
								) }
							</div>
						);
					} ) }
				</div>
			</div>
		</div>
	);
}
