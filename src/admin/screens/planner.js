import { useState, useMemo, useEffect } from '@wordpress/element';
import { Button, DropdownMenu, MenuGroup, MenuItem, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import {
	api,
	config,
	useApp,
	today,
	addDays,
	addMonths,
	toDate,
	formatDate,
	weekdayNames,
} from '../lib';
import { Icon } from '../icons';
import { useLoad, usePeek, PageHeader, ErrorNote } from '../components/ui';
import WeekBoard, { drag, minutesOf, lengthOf, clock } from './planner-week';

/** The weeks shown for a month, as rows of "YYYY-MM-DD". */
function monthGrid( month ) {
	const first = toDate( month );
	const lead = ( first.getUTCDay() - ( config.weekStartsOn || 0 ) + 7 ) % 7;
	const daysInMonth = new Date(
		Date.UTC( first.getUTCFullYear(), first.getUTCMonth() + 1, 0 )
	).getUTCDate();
	const weeks = Math.ceil( ( lead + daysInMonth ) / 7 );
	const start = addDays( month, -lead );
	return Array.from( { length: weeks }, ( _, week ) =>
		Array.from( { length: 7 }, ( __unused, day ) =>
			addDays( start, week * 7 + day )
		)
	);
}

function MoveDialog( { item, onMove, onClose } ) {
	const [ date, setDate ] = useState( item.date );
	return (
		<Modal
			title={ sprintf(
				/* translators: %s: event title. */
				__( 'Move “%s”', 'rayetun-events-calendar' ),
				item.title
			) }
			onRequestClose={ onClose }
			size="small"
		>
			<form
				onSubmit={ ( event ) => {
					event.preventDefault();
					onMove( item, date );
					onClose();
				} }
			>
				<div className="rayec-field">
					<label htmlFor="rayec-move-date">
						{ __( 'New date', 'rayetun-events-calendar' ) }
					</label>
					<input
						id="rayec-move-date"
						className="rayec-input"
						type="date"
						value={ date }
						onChange={ ( event ) => setDate( event.target.value ) }
						required
					/>
				</div>
				<p className="rayec-muted">
					{ __(
						'The start and end times stay the same.',
						'rayetun-events-calendar'
					) }
				</p>
				<Button variant="primary" type="submit" __next40pxDefaultSize>
					{ __( 'Move event', 'rayetun-events-calendar' ) }
				</Button>
			</form>
		</Modal>
	);
}

function Chip( { item, onMove, onAskMove, onSeries, onShift, block } ) {
	const { openEvent, notify, refresh } = useApp();
	const [ peekProps, peek ] = usePeek( item );
	const movable = ! item.recurring;
	const length = lengthOf( item );

	const duplicate = () =>
		api
			.post( `/events/${ item.event_id }/duplicate` )
			.then( () => {
				notify(
					__(
						'Copy saved as a draft.',
						'rayetun-events-calendar'
					)
				);
				refresh();
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	return (
		<li
			className={ `rayec-pchip is-${ item.event_status } is-${
				item.post_status
			}${ block && block.style ? ' rayec-pchip--block' : '' } ${
				( block && block.className ) || ''
			}` }
			style={ {
				...( item.color ? { '--rayec-chip': item.color } : {} ),
				...( ( block && block.style ) || {} ),
			} }
			draggable
			onDragStart={ ( event ) => {
				if ( drag.resizing ) {
					// The lower edge is being pulled: that is a stretch, not a move.
					event.preventDefault();
					return;
				}
				drag.item = item;
				drag.day = block ? block.day : item.date;
				drag.first = block ? block.first : true;
				drag.grabY =
					event.clientY -
					event.currentTarget.getBoundingClientRect().top;
				event.dataTransfer.setData(
					'application/x-rayec',
					JSON.stringify( item )
				);
				event.dataTransfer.effectAllowed = 'move';
			} }
			onDragEnd={ () => {
				drag.item = null;
			} }
		>
			<button
				type="button"
				className="rayec-pchip__main"
				onClick={ () => openEvent( item.event_id ) }
				{ ...peekProps }
			>
				{ ! item.all_day && (
					<span className="rayec-pchip__time">
						{ item.time_label }
					</span>
				) }
				<span className="rayec-pchip__title">
					{ item.title }
					{ item.recurring && <Icon name="repeat" size={ 11 } /> }
				</span>
				{ block && block.note && (
					<span className="rayec-pchip__note">{ block.note }</span>
				) }
				<span className="screen-reader-text">
					{ `, ${ item.when_label }` }
					{ item.recurring
						? `, ${ __( 'repeats', 'rayetun-events-calendar' ) }`
						: '' }
					{ item.post_status === 'draft'
						? `, ${ __( 'draft', 'rayetun-events-calendar' ) }`
						: '' }
				</span>
			</button>
			<DropdownMenu
				className="rayec-pchip__menu"
				icon={ <Icon name="more" size={ 14 } /> }
				label={ sprintf(
					/* translators: %s: event title. */
					__( 'Actions for %s', 'rayetun-events-calendar' ),
					item.title
				) }
			>
				{ ( { onClose } ) => (
					<>
						<MenuGroup>
							<MenuItem
								onClick={ () => {
									openEvent( item.event_id );
									onClose();
								} }
							>
								{ __( 'Edit', 'rayetun-events-calendar' ) }
							</MenuItem>
							<MenuItem
								onClick={ () => {
									duplicate();
									onClose();
								} }
							>
								{ __( 'Duplicate', 'rayetun-events-calendar' ) }
							</MenuItem>
						</MenuGroup>
						{ item.recurring && (
							<MenuGroup
								label={ __(
									'Part of the series',
									'rayetun-events-calendar'
								) }
							>
								<MenuItem
									onClick={ () => {
										onSeries( item, 'detach' );
										onClose();
									} }
								>
									{ __(
										'Move just this date…',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										onSeries( item, 'skip' );
										onClose();
									} }
								>
									{ __(
										'Skip this date',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										onSeries( item, 'split' );
										onClose();
									} }
								>
									{ __(
										'Change this and following dates…',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								<MenuItem
									isDestructive
									onClick={ () => {
										onSeries( item, 'end' );
										onClose();
									} }
								>
									{ __(
										'End the series before this date',
										'rayetun-events-calendar'
									) }
								</MenuItem>
							</MenuGroup>
						) }
						{ ! item.all_day && ! item.multi && (
							<MenuGroup
								label={
									item.recurring
										? __(
												'Time, this date only',
												'rayetun-events-calendar'
										  )
										: __( 'Time', 'rayetun-events-calendar' )
								}
							>
								<MenuItem
									onClick={ () => {
										onShift( item, -30 );
										onClose();
									} }
								>
									{ __(
										'Start 30 minutes earlier',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										onShift( item, 30 );
										onClose();
									} }
								>
									{ __(
										'Start 30 minutes later',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								{ length > 30 && (
									<MenuItem
										onClick={ () => {
											onShift( item, 0, length - 30 );
											onClose();
										} }
									>
										{ __(
											'Make 30 minutes shorter',
											'rayetun-events-calendar'
										) }
									</MenuItem>
								) }
								{ length <= 1410 && (
									<MenuItem
										onClick={ () => {
											onShift( item, 0, length + 30 );
											onClose();
										} }
									>
										{ __(
											'Make 30 minutes longer',
											'rayetun-events-calendar'
										) }
									</MenuItem>
								) }
							</MenuGroup>
						) }
						{ movable && (
							<MenuGroup
								label={ __(
									'Reschedule',
									'rayetun-events-calendar'
								) }
							>
								<MenuItem
									onClick={ () => {
										onMove( item, addDays( item.date, -1 ) );
										onClose();
									} }
								>
									{ __(
										'One day earlier',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										onMove( item, addDays( item.date, 1 ) );
										onClose();
									} }
								>
									{ __(
										'One day later',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										onMove( item, addDays( item.date, 7 ) );
										onClose();
									} }
								>
									{ __(
										'One week later',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										onAskMove( item );
										onClose();
									} }
								>
									{ __(
										'Choose a date…',
										'rayetun-events-calendar'
									) }
								</MenuItem>
							</MenuGroup>
						) }
					</>
				) }
			</DropdownMenu>
			{ block && block.handle }
			{ peek }
		</li>
	);
}

export default function Planner() {
	const { openEvent, notify, refresh } = useApp();
	const now = today();
	const [ cursor, setCursor ] = useState( now );
	const [ view, setView ] = useState( () => {
		try {
			const saved = window.localStorage.getItem( 'rayecPlannerView' );
			return [ 'week', 'rooms' ].includes( saved ) ? saved : 'month';
		} catch ( e ) {
			return 'month';
		}
	} );
	const [ dropTarget, setDropTarget ] = useState( '' );
	const [ moving, setMoving ] = useState( null );

	useEffect( () => {
		try {
			window.localStorage.setItem( 'rayecPlannerView', view );
		} catch ( e ) {
			// Private windows may refuse storage; the choice just is not remembered.
		}
	}, [ view ] );

	const isRooms = view === 'rooms';
	// The week board and the by-room board share one time grid.
	const isWeek = view === 'week';
	const venues = useLoad( '/venues' );
	const month = cursor.slice( 0, 7 ) + '-01';
	const weeks = useMemo( () => monthGrid( month ), [ month ] );
	const weekDays = useMemo( () => {
		const lead =
			( toDate( cursor ).getUTCDay() - ( config.weekStartsOn || 0 ) + 7 ) %
			7;
		const start = addDays( cursor, -lead );
		return Array.from( { length: 7 }, ( _, i ) => addDays( start, i ) );
	}, [ cursor ] );
	let first = weeks[ 0 ][ 0 ];
	let last = weeks[ weeks.length - 1 ][ 6 ];
	if ( isWeek ) {
		first = weekDays[ 0 ];
		last = weekDays[ 6 ];
	} else if ( isRooms ) {
		first = cursor;
		last = cursor;
	}
	const step = ( direction ) => {
		if ( isRooms ) {
			setCursor( addDays( cursor, direction ) );
		} else if ( isWeek ) {
			setCursor( addDays( cursor, direction * 7 ) );
		} else {
			setCursor( addMonths( month, direction ) );
		}
	};
	const { data, error } = useLoad(
		`/occurrences?start=${ first }&end=${ last }`
	);

	const byDay = useMemo( () => {
		const map = {};
		( data || [] ).forEach( ( item ) => {
			// Multi-day events show on each day they cover.
			let day = item.date < first ? first : item.date;
			const end = item.end.slice( 0, 10 );
			for ( let i = 0; i < 42 && day <= end && day <= last; i++ ) {
				( map[ day ] = map[ day ] || [] ).push( item );
				day = addDays( day, 1 );
			}
		} );
		return map;
	}, [ data, first, last ] );

	// One lane per room, per venue without rooms, and one for everything else.
	const [ lanes, laneOf ] = useMemo( () => {
		const list = [];
		const raw = ( item ) =>
			item.format === 'online' || ! item.venue_id
				? 'none'
				: `${ item.venue_id }:${ item.room_id || '' }`;
		( venues.data || [] ).forEach( ( venue ) => {
			const rooms = venue.rooms || [];
			const wholeKey = `${ venue.id }:`;
			if (
				! rooms.length ||
				( data || [] ).some( ( item ) => raw( item ) === wholeKey )
			) {
				list.push( {
					key: wholeKey,
					label: rooms.length
						? sprintf(
								/* translators: %s: venue name. */
								__(
									'%s (whole venue)',
									'rayetun-events-calendar'
								),
								venue.name
						  )
						: venue.name,
					venue_id: venue.id,
					room_id: '',
				} );
			}
			rooms.forEach( ( room ) =>
				list.push( {
					key: `${ venue.id }:${ room.id }`,
					label: `${ venue.name } · ${ room.name }`,
					venue_id: venue.id,
					room_id: room.id,
				} )
			);
		} );
		const known = list.map( ( lane ) => lane.key );
		const find = ( item ) =>
			known.includes( raw( item ) ) ? raw( item ) : 'none';
		if (
			! list.length ||
			( data || [] ).some( ( item ) => find( item ) === 'none' )
		) {
			list.push( {
				key: 'none',
				label: __( 'No venue, or online', 'rayetun-events-calendar' ),
				venue_id: 0,
				room_id: '',
			} );
		}
		return [ list, find ];
	}, [ venues.data, data ] );

	// Change one date of a repeating event, with Undo restoring the series exactly.
	const series = ( item, action, newDate, extra = {} ) => {
		if ( action === 'detach' && ! newDate ) {
			setMoving( { ...item, detach: true } );
			return;
		}
		api.post( `/events/${ item.event_id }/occurrence`, {
			date: item.date,
			action,
			new_date: newDate,
			...extra,
		} )
			.then( ( result ) => {
				refresh();
				if ( action === 'split' && result.created ) {
					// The later dates are now their own series: open it for editing.
					openEvent( result.created );
				}
				const messages = {
					split: __(
						'From that date on, “%s” is a separate series you can now change.',
						'rayetun-events-calendar'
					),
					skip: __(
						'“%s” will not happen on that date.',
						'rayetun-events-calendar'
					),
					end: __(
						'“%s” now ends before that date.',
						'rayetun-events-calendar'
					),
					detach: __(
						'That date of “%s” was changed. The rest of the series is unchanged.',
						'rayetun-events-calendar'
					),
				};
				notify(
					// eslint-disable-next-line @wordpress/valid-sprintf -- message chosen from the fixed list above.
					sprintf( messages[ action ], item.title ),
					{
						undo: () =>
							api
								.post(
									`/events/${ item.event_id }/series-restore`,
									result.undo
								)
								.then( refresh ),
					}
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );
	};

	const move = ( item, date, isUndo = false ) => {
		if ( date === item.date ) {
			return;
		}
		if ( item.recurring ) {
			// Dragging one date of a series moves only that date.
			series( item, 'detach', date );
			return;
		}
		api.post( `/events/${ item.event_id }/move`, { date } )
			.then( ( result ) => {
				refresh();
				notify(
					sprintf(
						/* translators: 1: event title, 2: date. */
						__(
							'“%1$s” moved to %2$s.',
							'rayetun-events-calendar'
						),
						item.title,
						formatDate( result.date, {
							weekday: 'long',
							day: 'numeric',
							month: 'long',
						} )
					),
					isUndo
						? {}
						: {
								undo: () =>
									move(
										{ ...item, date: result.date },
										result.previous,
										true
									),
						  }
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );
	};

	// Change the start time or the length. Minutes are relative, so this works
	// whatever time zone the event is in.
	const shift = ( item, minutes, duration = null, isUndo = false ) => {
		if ( item.recurring ) {
			series( item, 'detach', item.date, {
				shift_minutes: minutes,
				...( duration === null ? {} : { duration_minutes: duration } ),
			} );
			return;
		}
		api.post( `/events/${ item.event_id }/shift`, { minutes, duration } )
			.then( ( result ) => {
				refresh();
				const day = formatDate( result.start, {
					weekday: 'long',
					day: 'numeric',
					month: 'long',
				} );
				const when = item.all_day
					? day
					: sprintf(
							/* translators: 1: date, 2: start time, 3: end time. */
							__(
								'%1$s, %2$s to %3$s',
								'rayetun-events-calendar'
							),
							day,
							clock( minutesOf( result.start ) ),
							clock( minutesOf( result.end ) )
					  );
				notify(
					sprintf(
						/* translators: 1: event title, 2: date and times. */
						__( '“%1$s” is now %2$s.', 'rayetun-events-calendar' ),
						item.title,
						when
					),
					isUndo
						? {}
						: {
								undo: () =>
									shift(
										item,
										result.undo.minutes,
										result.undo.duration,
										true
									),
						  }
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );
	};

	// A block dropped on the week board: a new day, a new time, or both.
	const place = ( item, day, minutes, fromDay, lane ) => {
		const dayDiff = Math.round(
			( toDate( day ) - toDate( fromDay ) ) / 86400000
		);
		const timeDiff =
			minutes === null ? 0 : minutes - minutesOf( item.start );
		const retime = () => {
			if ( ! dayDiff && ! timeDiff ) {
				return;
			}
			const target = addDays( item.date, dayDiff );
			if ( ! timeDiff ) {
				move( item, target );
			} else if ( item.recurring ) {
				series( item, 'detach', target, { shift_minutes: timeDiff } );
			} else {
				shift( item, dayDiff * 1440 + timeDiff );
			}
		};
		if ( ! lane || lane.key === laneOf( item ) ) {
			retime();
			return;
		}
		// Dropped in another lane: the venue or room changes.
		if ( item.recurring || item.format === 'online' ) {
			notify(
				item.recurring
					? __(
							'This event repeats, so its place is set for the whole series. It is open for you to change.',
							'rayetun-events-calendar'
					  )
					: __(
							'This is an online event. It is open so you can give it a venue.',
							'rayetun-events-calendar'
					  )
			);
			openEvent( item.event_id );
			return;
		}
		api.post( `/events/${ item.event_id }`, {
			venue_id: lane.venue_id,
			room_id: lane.room_id,
		} )
			.then( () => {
				refresh();
				notify(
					sprintf(
						/* translators: 1: event title, 2: venue or room. */
						__(
							'“%1$s” is now in %2$s.',
							'rayetun-events-calendar'
						),
						item.title,
						lane.label
					),
					{
						undo: () =>
							api
								.post( `/events/${ item.event_id }`, {
									venue_id: item.venue_id,
									room_id: item.room_id || '',
								} )
								.then( refresh ),
					}
				);
				retime();
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );
	};

	const names = weekdayNames( 'short' );
	const longNames = weekdayNames( 'long' );
	const order = Array.from(
		{ length: 7 },
		( _, i ) => ( i + ( config.weekStartsOn || 0 ) ) % 7
	);
	const monthLabel = formatDate( month, { month: 'long', year: 'numeric' } );
	const weekLabel = sprintf(
		/* translators: 1: first day of the week, 2: last day of the week. */
		__( '%1$s to %2$s', 'rayetun-events-calendar' ),
		formatDate( weekDays[ 0 ], { day: 'numeric', month: 'short' } ),
		formatDate( weekDays[ 6 ], {
			day: 'numeric',
			month: 'short',
			year: 'numeric',
		} )
	);

	return (
		<>
			<PageHeader
				title={ __( 'Planner', 'rayetun-events-calendar' ) }
				subtitle={ __(
					'Drag an event to another day. In the week view, drag to change the time and pull the lower edge to change the length. “By room” shows one day with a column for each venue and room; drag across to change where an event happens. Every change is also in the event’s menu for the keyboard. Changing one date of a repeating event leaves the rest of the series alone.',
					'rayetun-events-calendar'
				) }
			/>

			<div className="rayec-planner">
				<div className="rayec-planner__bar">
					<h2 className="rayec-planner__month" aria-live="polite">
						{ isRooms &&
							formatDate( cursor, {
								weekday: 'long',
								day: 'numeric',
								month: 'long',
								year: 'numeric',
							} ) }
						{ isWeek && weekLabel }
						{ ! isWeek && ! isRooms && monthLabel }
					</h2>
					<div
						className="rayec-seg"
						role="group"
						aria-label={ __( 'View', 'rayetun-events-calendar' ) }
					>
						<button
							type="button"
							aria-pressed={ ! isWeek && ! isRooms }
							onClick={ () => setView( 'month' ) }
						>
							{ __( 'Month', 'rayetun-events-calendar' ) }
						</button>
						<button
							type="button"
							aria-pressed={ isWeek }
							onClick={ () => setView( 'week' ) }
						>
							{ __( 'Week', 'rayetun-events-calendar' ) }
						</button>
						<button
							type="button"
							aria-pressed={ isRooms }
							onClick={ () => setView( 'rooms' ) }
						>
							{ __( 'By room', 'rayetun-events-calendar' ) }
						</button>
					</div>
					<div className="rayec-planner__nav">
						<Button
							variant="secondary"
							__next40pxDefaultSize
							onClick={ () => step( -1 ) }
							label={
								{
									month: __(
										'Previous month',
										'rayetun-events-calendar'
									),
									week: __(
										'Previous week',
										'rayetun-events-calendar'
									),
									rooms: __(
										'Previous day',
										'rayetun-events-calendar'
									),
								}[ view ]
							}
							icon={ <Icon name="chevronLeft" /> }
						/>
						<Button
							variant="secondary"
							__next40pxDefaultSize
							onClick={ () => setCursor( now ) }
						>
							{ __( 'Today', 'rayetun-events-calendar' ) }
						</Button>
						<Button
							variant="secondary"
							__next40pxDefaultSize
							onClick={ () => step( 1 ) }
							label={
								{
									month: __(
										'Next month',
										'rayetun-events-calendar'
									),
									week: __( 'Next week', 'rayetun-events-calendar' ),
									rooms: __( 'Next day', 'rayetun-events-calendar' ),
								}[ view ]
							}
							icon={ <Icon name="chevronRight" /> }
						/>
					</div>
				</div>

				{ error && <ErrorNote message={ error } /> }

				{ ( isWeek || isRooms ) && (
					<WeekBoard
						lanes={ isRooms ? lanes : null }
						laneOf={ laneOf }
						days={ isRooms ? [ cursor ] : weekDays }
						items={ data || [] }
						now={ now }
						onDropAt={ place }
						onResize={ ( item, duration ) =>
							shift( item, 0, duration )
						}
						onAdd={ ( date, time, lane ) =>
							openEvent( {
								date,
								time,
								venue_id: lane ? lane.venue_id : 0,
								room_id: lane ? lane.room_id : '',
							} )
						}
						renderChip={ ( item, block ) => (
							<Chip
								key={ block.key }
								item={ item }
								block={ block }
								onMove={ move }
								onAskMove={ setMoving }
								onSeries={ series }
								onShift={ shift }
							/>
						) }
					/>
				) }

				{ ! isWeek && ! isRooms && (
				<table className="rayec-board">
					<caption className="screen-reader-text">
						{ sprintf(
							/* translators: %s: month and year. */
							__(
								'Events in %s',
								'rayetun-events-calendar'
							),
							monthLabel
						) }
					</caption>
					<thead>
						<tr>
							{ order.map( ( index ) => (
								<th key={ index } scope="col">
									<abbr title={ longNames[ index ] }>
										{ names[ index ] }
									</abbr>
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ weeks.map( ( week ) => (
							<tr key={ week[ 0 ] }>
								{ week.map( ( day ) => {
									const items = byDay[ day ] || [];
									const outside =
										day.slice( 0, 7 ) !==
										month.slice( 0, 7 );
									const fullDate = formatDate( day, {
										weekday: 'long',
										day: 'numeric',
										month: 'long',
									} );
									return (
										<td
											key={ day }
											className={ [
												outside ? 'is-outside' : '',
												day === now ? 'is-today' : '',
												dropTarget === day
													? 'is-drop'
													: '',
											].join( ' ' ) }
											aria-current={
												day === now ? 'date' : undefined
											}
											onDragOver={ ( event ) => {
												if (
													event.dataTransfer.types.includes(
														'application/x-rayec'
													)
												) {
													event.preventDefault();
													setDropTarget( day );
												}
											} }
											onDragLeave={ () =>
												setDropTarget( '' )
											}
											onDrop={ ( event ) => {
												event.preventDefault();
												setDropTarget( '' );
												try {
													move(
														JSON.parse(
															event.dataTransfer.getData(
																'application/x-rayec'
															)
														),
														day
													);
												} catch ( e ) {
													// Not one of ours.
												}
											} }
										>
											<div className="rayec-board__day">
												<span
													className="rayec-board__num"
													aria-hidden="true"
												>
													{ parseInt(
														day.slice( 8 ),
														10
													) }
												</span>
												<span className="screen-reader-text">
													{ fullDate }
												</span>
												<button
													type="button"
													className="rayec-board__add"
													onClick={ () =>
														openEvent( {
															date: day,
														} )
													}
													aria-label={ sprintf(
														/* translators: %s: date. */
														__(
															'New event on %s',
															'rayetun-events-calendar'
														),
														fullDate
													) }
												>
													<Icon
														name="plus"
														size={ 14 }
													/>
												</button>
											</div>
											{ items.length > 0 && (
												<ul className="rayec-board__events">
													{ items.map( ( item ) => (
														<Chip
															key={ `${ item.id }-${ day }` }
															item={ item }
															onMove={ move }
															onAskMove={
																setMoving
															}
															onSeries={ series }
															onShift={ shift }
														/>
													) ) }
												</ul>
											) }
										</td>
									);
								} ) }
							</tr>
						) ) }
					</tbody>
				</table>
				) }

				{ data && data.length === 0 && (
					<p className="rayec-planner__empty">
						{ __(
							'Nothing is scheduled here yet. Use the + on any day to add an event.',
							'rayetun-events-calendar'
						) }
					</p>
				) }
			</div>

			{ moving && (
				<MoveDialog
					item={ moving }
					onMove={ move }
					onClose={ () => setMoving( null ) }
				/>
			) }
		</>
	);
}
