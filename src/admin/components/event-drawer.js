import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Modal,
	Button,
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
	CheckboxControl,
	ComboboxControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';

import {
	api,
	config,
	useApp,
	today,
	addDays,
	weekdayNames,
	formatDate,
	guardLevel,
	DAY_CODES,
	parseRule,
	buildRule,
	nthWeekday,
} from '../lib';
import { Icon } from '../icons';
import { Loading, ErrorNote } from './ui';
import Attendees from './attendees';

const RSVP_DEFAULTS = { enabled: false, capacity: 0, max: 1, access: true };

const blank = ( defaults = {} ) => {
	const date = defaults.date || addDays( today(), 1 );
	// The week planner passes the time of the slot that was clicked.
	const time = /^\d{2}:\d{2}$/.test( defaults.time || '' )
		? defaults.time
		: '10:00';
	const endAt = Math.min(
		1439,
		parseInt( time.slice( 0, 2 ), 10 ) * 60 +
			parseInt( time.slice( 3 ), 10 ) +
			60
	);
	const two = ( n ) => String( n ).padStart( 2, '0' );
	return {
		id: 0,
		title: '',
		description: '',
		start: `${ date }T${ time }`,
		end: `${ date }T${ two( Math.floor( endAt / 60 ) ) }:${ two(
			endAt % 60
		) }`,
		all_day: false,
		timezone: config.timezone,
		rrule: '',
		skip_closed: false,
		event_status: 'scheduled',
		format: 'in_person',
		venue_id: defaults.venue_id || 0,
		room_id: defaults.room_id || '',
		program_id: 0,
		speaker_ids: [],
		extra_times: [],
		organizer_id: 0,
		thumbnail_id: 0,
		image: null,
		online_url: '',
		cost: '',
		categories: [],
		post_status: 'publish',
		guard: null,
		rsvp: { ...RSVP_DEFAULTS },
		access: [],
		access_notes: '',
	};
};

/** Debounce a value. */
export function useDebounced( value, delay ) {
	const [ debounced, setDebounced ] = useState( value );
	useEffect( () => {
		const timer = setTimeout( () => setDebounced( value ), delay );
		return () => clearTimeout( timer );
	}, [ value, delay ] );
	return debounced;
}

export function DateTimeField( { label, value, allDay, onChange, min } ) {
	const id = useRef(
		`rayec-dt-${ Math.random().toString( 36 ).slice( 2 ) }`
	).current;
	return (
		<div className="rayec-field">
			<label htmlFor={ id }>{ label }</label>
			<input
				id={ id }
				className="rayec-input"
				type={ allDay ? 'date' : 'datetime-local' }
				value={ allDay ? value.slice( 0, 10 ) : value }
				min={ min }
				onChange={ ( event ) => {
					const next = event.target.value;
					if ( ! next ) {
						return;
					}
					onChange(
						allDay ? `${ next }T${ value.slice( 11 ) || '10:00' }` : next
					);
				} }
			/>
		</div>
	);
}

export function RepeatFields( { rule, setRule, start } ) {
	const short = weekdayNames( 'short' );
	const long = weekdayNames( 'long' );
	const nth = nthWeekday( start );
	const ordinalNames = {
		1: __( 'first', 'rayetun-events-calendar' ),
		2: __( 'second', 'rayetun-events-calendar' ),
		3: __( 'third', 'rayetun-events-calendar' ),
		4: __( 'fourth', 'rayetun-events-calendar' ),
		'-1': __( 'last', 'rayetun-events-calendar' ),
	};
	const units = {
		DAILY: __( 'day(s)', 'rayetun-events-calendar' ),
		WEEKLY: __( 'week(s)', 'rayetun-events-calendar' ),
		MONTHLY: __( 'month(s)', 'rayetun-events-calendar' ),
		YEARLY: __( 'year(s)', 'rayetun-events-calendar' ),
	};
	// Show weekdays in the site's week order.
	const order = Array.from(
		{ length: 7 },
		( _, i ) => ( i + ( config.weekStartsOn || 0 ) ) % 7
	);

	return (
		<>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Repeats', 'rayetun-events-calendar' ) }
				value={ rule.freq }
				options={ [
					{
						label: __(
							'Does not repeat',
							'rayetun-events-calendar'
						),
						value: '',
					},
					{
						label: __( 'Daily', 'rayetun-events-calendar' ),
						value: 'DAILY',
					},
					{
						label: __( 'Weekly', 'rayetun-events-calendar' ),
						value: 'WEEKLY',
					},
					{
						label: __( 'Monthly', 'rayetun-events-calendar' ),
						value: 'MONTHLY',
					},
					{
						label: __( 'Yearly', 'rayetun-events-calendar' ),
						value: 'YEARLY',
					},
				] }
				onChange={ ( freq ) =>
					setRule( {
						...rule,
						freq,
						// A weekly series starts out on the weekday of its first date.
						days:
							freq === 'WEEKLY' && rule.days.length === 0
								? [ nth.code ]
								: rule.days,
					} )
				}
			/>

			{ rule.freq && (
				<div className="rayec-repeat">
					<div className="rayec-field rayec-field--inline">
						<label htmlFor="rayec-interval">
							{ __( 'Every', 'rayetun-events-calendar' ) }
						</label>
						<input
							id="rayec-interval"
							className="rayec-input rayec-input--num"
							type="number"
							min="1"
							max="99"
							value={ rule.interval }
							onChange={ ( event ) =>
								setRule( {
									...rule,
									interval: Math.max(
										1,
										parseInt( event.target.value, 10 ) || 1
									),
								} )
							}
						/>
						<span>{ units[ rule.freq ] }</span>
					</div>

					{ rule.freq === 'WEEKLY' && (
						<fieldset className="rayec-days">
							<legend>
								{ __( 'On', 'rayetun-events-calendar' ) }
							</legend>
							{ order.map( ( index ) => {
								const code = DAY_CODES[ index ];
								const checked = rule.days.includes( code );
								return (
									<label
										key={ code }
										className={ checked ? 'is-on' : '' }
									>
										<input
											type="checkbox"
											checked={ checked }
											onChange={ () =>
												setRule( {
													...rule,
													days: checked
														? rule.days.filter(
																( day ) =>
																	day !== code
														  )
														: [
																...rule.days,
																code,
														  ],
												} )
											}
										/>
										<span aria-hidden="true">
											{ short[ index ] }
										</span>
										<span className="screen-reader-text">
											{ long[ index ] }
										</span>
									</label>
								);
							} ) }
						</fieldset>
					) }

					{ rule.freq === 'MONTHLY' && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'On',
								'rayetun-events-calendar'
							) }
							value={ rule.monthlyMode }
							options={ [
								{
									label: sprintf(
										/* translators: %d: day of the month. */
										__(
											'Day %d of the month',
											'rayetun-events-calendar'
										),
										parseInt( start.slice( 8, 10 ), 10 )
									),
									value: 'date',
								},
								{
									label: sprintf(
										/* translators: 1: ordinal such as "second", 2: weekday name. */
										__(
											'The %1$s %2$s',
											'rayetun-events-calendar'
										),
										ordinalNames[ nth.ordinal ],
										long[ DAY_CODES.indexOf( nth.code ) ]
									),
									value: 'weekday',
								},
							] }
							onChange={ ( monthlyMode ) =>
								setRule( { ...rule, monthlyMode } )
							}
						/>
					) }

					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Ends', 'rayetun-events-calendar' ) }
						value={ rule.ends }
						options={ [
							{
								label: __(
									'Never',
									'rayetun-events-calendar'
								),
								value: 'never',
							},
							{
								label: __(
									'On a date',
									'rayetun-events-calendar'
								),
								value: 'until',
							},
							{
								label: __(
									'After a number of times',
									'rayetun-events-calendar'
								),
								value: 'count',
							},
						] }
						onChange={ ( ends ) =>
							setRule( {
								...rule,
								ends,
								until:
									ends === 'until' && ! rule.until
										? addDays( start.slice( 0, 10 ), 90 )
										: rule.until,
							} )
						}
					/>
					{ rule.ends === 'until' && (
						<div className="rayec-field">
							<label htmlFor="rayec-until">
								{ __(
									'Last date',
									'rayetun-events-calendar'
								) }
							</label>
							<input
								id="rayec-until"
								className="rayec-input"
								type="date"
								min={ start.slice( 0, 10 ) }
								value={ rule.until }
								onChange={ ( event ) =>
									setRule( {
										...rule,
										until: event.target.value,
									} )
								}
							/>
						</div>
					) }
					{ rule.ends === 'count' && (
						<div className="rayec-field">
							<label htmlFor="rayec-count">
								{ __(
									'Number of times',
									'rayetun-events-calendar'
								) }
							</label>
							<input
								id="rayec-count"
								className="rayec-input rayec-input--num"
								type="number"
								min="1"
								max="999"
								value={ rule.count }
								onChange={ ( event ) =>
									setRule( {
										...rule,
										count: Math.max(
											1,
											parseInt( event.target.value, 10 ) ||
												1
										),
									} )
								}
							/>
						</div>
					) }
				</div>
			) }
		</>
	);
}

/**
 * Event image: choose from the media library, replace, remove, and describe.
 *
 * @param {Object}   props
 * @param {?Object}  props.image    Current image: { id, url, alt }.
 * @param {Function} props.onChange Receives the new image, or null.
 */
function ImageField( { image, onChange } ) {
	const choose = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Choose an event image', 'rayetun-events-calendar' ),
			button: {
				text: __( 'Use this image', 'rayetun-events-calendar' ),
			},
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const picked = frame.state().get( 'selection' ).first().toJSON();
			const sizes = picked.sizes || {};
			onChange( {
				id: picked.id,
				url: ( sizes.medium_large || sizes.large || sizes.full || picked )
					.url,
				alt: picked.alt || '',
			} );
		} );
		frame.open();
	};

	if ( ! image ) {
		return (
			<div className="rayec-image rayec-image--empty">
				<p className="rayec-muted">
					{ __(
						'Shown in the list view, in previews and on the event page.',
						'rayetun-events-calendar'
					) }
				</p>
				<Button variant="secondary" onClick={ choose }>
					{ __( 'Choose image', 'rayetun-events-calendar' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="rayec-image">
			<img src={ image.url } alt="" />
			<div className="rayec-image__side">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Describe the image (alt text)',
						'rayetun-events-calendar'
					) }
					help={ __(
						'Read aloud by screen readers. Say what the picture shows; leave out “image of”.',
						'rayetun-events-calendar'
					) }
					value={ image.alt || '' }
					onChange={ ( alt ) =>
						onChange( { ...image, alt, altChanged: true } )
					}
				/>
				<div className="rayec-image__actions">
					<Button variant="secondary" onClick={ choose }>
						{ __( 'Replace', 'rayetun-events-calendar' ) }
					</Button>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => onChange( null ) }
					>
						{ __( 'Remove', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}

export default function EventDrawer( { target, onClose } ) {
	const { notify, refresh, openEvent } = useApp();
	// target is an event ID, an object of defaults for a new event, or
	// { id, draft } when unsaved changes are being brought back.
	const draft = ( typeof target === 'object' && target.draft ) || null;
	const editingId = typeof target === 'number' ? target : target.id || 0;

	const [ event, setEvent ] = useState( () => {
		if ( draft ) {
			return draft.event;
		}
		return editingId ? null : blank( target );
	} );
	const [ rule, setRuleState ] = useState( () =>
		draft ? draft.rule : parseRule( '' )
	);
	// "Dirty" means there is something to lose if the drawer closes.
	const dirty = useRef( !! draft );
	const setRule = ( next ) => {
		dirty.current = true;
		setRuleState( next );
	};
	const [ organizers, setOrganizers ] = useState( [] );
	const [ speakers, setSpeakers ] = useState( [] );
	const [ programs, setPrograms ] = useState( [] );
	const [ loadError, setLoadError ] = useState( '' );
	const [ venues, setVenues ] = useState( [] );
	const [ categories, setCategories ] = useState( [] );
	const [ sentence, setSentence ] = useState( '' );
	const [ parsing, setParsing ] = useState( false );
	const [ notes, setNotes ] = useState( [] );
	const [ preview, setPreview ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ showMore, setShowMore ] = useState( false );

	const set = ( changes ) => {
		dirty.current = true;
		setEvent( ( current ) => ( { ...current, ...changes } ) );
	};

	// Closing never asks "are you sure?". Unsaved changes can be brought back with Undo.
	const requestClose = () => {
		// Clicking inside the media library counts as "outside" the drawer; ignore it.
		const media = document.querySelector( '.media-modal' );
		if ( media && media.getClientRects().length > 0 ) {
			return;
		}
		if ( dirty.current && event ) {
			const kept = { event, rule };
			notify(
				__( 'Unsaved changes discarded.', 'rayetun-events-calendar' ),
				{ undo: () => openEvent( { id: event.id, draft: kept } ) }
			);
		}
		onClose();
	};

	// Load supporting lists and, when editing, the event.
	useEffect( () => {
		api.get( '/venues' ).then( setVenues ).catch( () => {} );
		api.get( '/speakers' ).then( setSpeakers ).catch( () => {} );
		api.get( '/programs' ).then( setPrograms ).catch( () => {} );
		api.get( '/categories' ).then( setCategories ).catch( () => {} );
		api.get( '/organizers' ).then( setOrganizers ).catch( () => {} );
		if ( editingId && ! draft ) {
			api.get( `/events/${ editingId }` )
				.then( ( data ) => {
					setEvent( data );
					setRuleState( parseRule( data.rrule ) );
				} )
				.catch( ( e ) => setLoadError( e.message ) );
		}
	}, [ editingId ] );

	// Plain-language scheduling: parse as the user types.
	const debouncedSentence = useDebounced( sentence, 450 );
	useEffect( () => {
		if ( debouncedSentence.trim().length < 4 ) {
			return undefined;
		}
		let cancelled = false;
		setParsing( true );
		api.post( '/parse', {
			text: debouncedSentence,
			timezone: event?.timezone || config.timezone,
		} )
			.then( ( parsed ) => {
				if ( cancelled ) {
					return;
				}
				setNotes( parsed.notes || [] );
				if ( ! parsed.understood ) {
					return;
				}
				set( {
					title: parsed.title || event?.title || '',
					start: parsed.start,
					end: parsed.end,
					all_day: parsed.all_day,
					skip_closed: !! parsed.skip_closed,
				} );
				setRule( parseRule( parsed.rrule ) );
			} )
			.catch( () => {} )
			.finally( () => ! cancelled && setParsing( false ) );
		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ debouncedSentence ] );

	const rooms =
		( event &&
			( venues.find( ( venue ) => venue.id === event.venue_id ) || {} )
				.rooms ) ||
		[];

	const speakerIds = ( event && event.speaker_ids ) || [];
	const extraTimes = ( event && event.extra_times ) || [];
	const rsvp = { ...RSVP_DEFAULTS, ...( ( event && event.rsvp ) || {} ) };
	const setRsvp = ( changes ) => set( { rsvp: { ...rsvp, ...changes } } );

	// Live preview of the next dates.
	const rrule = event ? buildRule( rule, event.start.slice( 0, 10 ) ) : '';
	const previewKey = useDebounced(
		event
			? [
					event.start,
					event.end,
					event.all_day,
					event.timezone,
					rrule,
					event.skip_closed,
					event.format === 'online' ? 0 : event.venue_id,
					event.room_id || '',
					speakerIds.join( ',' ),
					event.all_day ? '' : extraTimes.join( ',' ),
			  ].join( '|' )
			: '',
		350
	);
	useEffect( () => {
		if ( ! previewKey || ! event ) {
			return undefined;
		}
		let cancelled = false;
		api.post( '/preview', {
			start: event.start,
			end: event.end,
			all_day: event.all_day,
			timezone: event.timezone,
			rrule,
			skip_closed: !! event.skip_closed,
			venue_id: event.format === 'online' ? 0 : event.venue_id,
			room_id: event.room_id || '',
			speaker_ids: speakerIds,
			extra_times: event.all_day ? [] : extraTimes,
			event_id: event.id,
		} )
			.then( ( data ) => ! cancelled && setPreview( data ) )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ previewKey ] );

	const save = ( postStatus ) => {
		setSaving( true );
		setError( '' );
		const payload = {
			title: event.title,
			start: event.start,
			end: event.end,
			all_day: event.all_day,
			timezone: event.timezone,
			rrule,
			event_status: event.event_status,
			format: event.format,
			venue_id: event.venue_id,
			room_id: event.room_id || '',
			speaker_ids: speakerIds,
			program_id: event.program_id || 0,
			extra_times: event.all_day ? [] : extraTimes.filter( Boolean ),
			online_url: event.online_url,
			cost: event.cost,
			category_ids: event.categories.map( ( category ) => category.id ),
			post_status: postStatus,
			skip_closed: !! event.skip_closed,
			organizer_id: event.organizer_id || 0,
			thumbnail_id: event.thumbnail_id || 0,
			access: event.access || [],
			access_notes: event.access_notes || '',
			rsvp: {
				enabled: !! rsvp.enabled,
				capacity: Math.max( 0, parseInt( rsvp.capacity, 10 ) || 0 ),
				max: Math.max( 1, parseInt( rsvp.max, 10 ) || 1 ),
				access: !! rsvp.access,
			},
		};
		if ( ! event.id && event.description.trim() ) {
			payload.description = event.description
				.trim()
				.split( /\n{2,}/ )
				.map(
					( paragraph ) =>
						`<!-- wp:paragraph -->\n<p>${ paragraph
							.replace( /&/g, '&amp;' )
							.replace( /</g, '&lt;' )
							.replace( /\n/g, '<br>' ) }</p>\n<!-- /wp:paragraph -->`
				)
				.join( '\n\n' );
		}

		// Add-ons can add their own fields to what is saved.
		const finalPayload = applyFilters( 'rayec.eventPayload', payload, event );

		// Alt text belongs to the image itself, so it is saved to the media library.
		const altSaved =
			event.image && event.image.altChanged && event.thumbnail_id
				? apiFetch( {
						path: `/wp/v2/media/${ event.thumbnail_id }`,
						method: 'POST',
						data: { alt_text: event.image.alt },
				  } ).catch( () => {} )
				: Promise.resolve();

		altSaved
			.then( () =>
				api.post(
					event.id ? `/events/${ event.id }` : '/events',
					finalPayload
				)
			)
			.then( ( saved ) => {
				notify(
					postStatus === 'draft'
						? __( 'Draft saved.', 'rayetun-events-calendar' )
						: sprintf(
								/* translators: %s: event title. */
								__(
									'“%s” saved.',
									'rayetun-events-calendar'
								),
								saved.title
						  )
				);
				refresh();
				onClose();
			} )
			.catch( ( e ) => {
				setError( e.message );
				setSaving( false );
			} );
	};

	const remove = () => {
		const { id, title } = event;
		api.del( `/events/${ id }` )
			.then( () => {
				notify(
					sprintf(
						/* translators: %s: event title. */
						__(
							'“%s” moved to the bin.',
							'rayetun-events-calendar'
						),
						title
					),
					{
						undo: () =>
							api
								.post( `/events/${ id }/restore` )
								.then( refresh ),
					}
				);
				refresh();
				onClose();
			} )
			.catch( ( e ) => setError( e.message ) );
	};

	const heading = editingId
		? __( 'Edit event', 'rayetun-events-calendar' )
		: __( 'New event', 'rayetun-events-calendar' );

	return (
		<Modal
			title={ heading }
			onRequestClose={ requestClose }
			className="rayec-drawer"
			overlayClassName="rayec-drawer-overlay"
		>
			{ loadError && <ErrorNote message={ loadError } /> }
			{ ! event && ! loadError && <Loading /> }

			{ event && (
				<form
					className="rayec-form"
					onSubmit={ ( submitEvent ) => {
						submitEvent.preventDefault();
						save(
							event.post_status === 'draft' && event.id
								? 'draft'
								: 'publish'
						);
					} }
				>
					{ ! event.id && (
						<div className="rayec-nl">
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={
									<>
										<Icon name="sparkle" size={ 15 } />{ ' ' }
										{ __(
											'Describe it in a sentence',
											'rayetun-events-calendar'
										) }
									</>
								}
								placeholder={ __(
									'Yoga every Tue and Thu at 6pm until June',
									'rayetun-events-calendar'
								) }
								help={ __(
									'Optional. The fields below fill in as you type, and you can still change any of them.',
									'rayetun-events-calendar'
								) }
								value={ sentence }
								onChange={ setSentence }
							/>
							{ parsing && <Spinner /> }
							{ notes.map( ( note ) => (
								<p key={ note } className="rayec-nl__note">
									<Icon name="alert" size={ 14 } /> { note }
								</p>
							) ) }
						</div>
					) }

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Event name', 'rayetun-events-calendar' ) }
						value={ event.title }
						onChange={ ( title ) => set( { title } ) }
						required
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'All day', 'rayetun-events-calendar' ) }
						checked={ event.all_day }
						onChange={ ( allDay ) => set( { all_day: allDay } ) }
					/>

					<div className="rayec-form__row">
						<DateTimeField
							label={ __( 'Starts', 'rayetun-events-calendar' ) }
							value={ event.start }
							allDay={ event.all_day }
							onChange={ ( start ) => {
								// Keep the same length when the start moves.
								const length =
									new Date( event.end + 'Z' ) -
									new Date( event.start + 'Z' );
								const end = new Date(
									new Date( start + 'Z' ).getTime() +
										( length > 0 ? length : 3600000 )
								)
									.toISOString()
									.slice( 0, 16 );
								set( { start, end } );
							} }
						/>
						<DateTimeField
							label={ __( 'Ends', 'rayetun-events-calendar' ) }
							value={ event.end }
							allDay={ event.all_day }
							min={
								event.all_day
									? event.start.slice( 0, 10 )
									: event.start
							}
							onChange={ ( end ) => set( { end } ) }
						/>
					</div>

					{ ! event.all_day && (
						<fieldset className="rayec-times">
							<legend>
								{ __(
									'More start times on the same day',
									'rayetun-events-calendar'
								) }
							</legend>
							<p className="rayec-muted">
								{ __(
									'For something that runs more than once a day, such as a 10am and a 2pm tour. Each one lasts as long as the first.',
									'rayetun-events-calendar'
								) }
							</p>
							{ extraTimes.map( ( time, index ) => (
								<div className="rayec-times__row" key={ index }>
									<input
										className="rayec-input"
										type="time"
										value={ time }
										aria-label={ sprintf(
											/* translators: %d: position, counting the main start time as 1. */
											__(
												'Start time %d',
												'rayetun-events-calendar'
											),
											index + 2
										) }
										onChange={ ( change ) =>
											set( {
												extra_times: extraTimes.map(
													( item, i ) =>
														i === index
															? change.target.value
															: item
												),
											} )
										}
									/>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () =>
											set( {
												extra_times: extraTimes.filter(
													( item, i ) => i !== index
												),
											} )
										}
										aria-label={ sprintf(
											/* translators: %s: time of day. */
											__(
												'Remove the %s start time',
												'rayetun-events-calendar'
											),
											time
										) }
									>
										{ __( 'Remove', 'rayetun-events-calendar' ) }
									</Button>
								</div>
							) ) }
							{ extraTimes.length < 8 && (
								<Button
									variant="secondary"
									onClick={ () => {
										// Suggest two hours after the latest start so far.
										const latest = [
											event.start.slice( 11, 16 ),
											...extraTimes,
										]
											.filter( Boolean )
											.sort()
											.pop();
										const hour = Math.min(
											23,
											parseInt( latest.slice( 0, 2 ), 10 ) + 2
										);
										set( {
											extra_times: [
												...extraTimes,
												`${ String( hour ).padStart(
													2,
													'0'
												) }:${ latest.slice( 3, 5 ) }`,
											],
										} );
									} }
								>
									{ __(
										'Add a start time',
										'rayetun-events-calendar'
									) }
								</Button>
							) }
						</fieldset>
					) }

					<RepeatFields
						rule={ rule }
						setRule={ setRule }
						start={ event.start }
					/>

					{ rule.freq && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Skip closed dates',
								'rayetun-events-calendar'
							) }
							help={ __(
								'Leaves out the public holidays and closures listed in Settings.',
								'rayetun-events-calendar'
							) }
							checked={ !! event.skip_closed }
							onChange={ ( skipClosed ) =>
								set( { skip_closed: skipClosed } )
							}
						/>
					) }

					{ preview && preview.conflicts?.length > 0 && (
						<div className="rayec-warn" role="status">
							<p className="rayec-warn__title">
								<Icon name="alert" size={ 15 } />
								{ preview.conflicts.some(
									( clash ) => clash.speaker
								)
									? __(
											'Possible double booking',
											'rayetun-events-calendar'
									  )
									: __(
											'This venue is already booked',
											'rayetun-events-calendar'
									  ) }
							</p>
							<ul>
								{ preview.conflicts.map( ( clash, index ) => (
									<li key={ index }>
										{ clash.speaker
											? sprintf(
													/* translators: 1: speaker name, 2: other event's title, 3: date and time. */
													__(
														'%1$s is also speaking at “%2$s” on %3$s.',
														'rayetun-events-calendar'
													),
													clash.speaker,
													clash.title,
													clash.when
											  )
											: sprintf(
													/* translators: 1: other event's title, 2: date and time. */
													__(
														'“%1$s” is there on %2$s.',
														'rayetun-events-calendar'
													),
													clash.title,
													clash.when
											  ) }
									</li>
								) ) }
							</ul>
							<p className="rayec-muted">
								{ __(
									'You can still save. This is a warning, not a block.',
									'rayetun-events-calendar'
								) }
							</p>
						</div>
					) }

					{ preview && preview.skipped?.length > 0 && (
						<p className="rayec-muted" role="status">
							{ sprintf(
								/* translators: %s: list of closed dates. */
								__(
									'Skipping closed dates: %s.',
									'rayetun-events-calendar'
								),
								preview.skipped.join( ', ' )
							) }
						</p>
					) }

					{ preview && preview.dates.length > 0 && (
						<div className="rayec-preview" aria-live="polite">
							<p className="rayec-preview__title">
								<Icon
									name={ rrule ? 'repeat' : 'clock' }
									size={ 15 }
								/>
								{ rrule
									? preview.text
									: __(
											'Happens once',
											'rayetun-events-calendar'
									  ) }
							</p>
							<ol>
								{ preview.dates.map( ( date ) => (
									<li key={ date }>{ date }</li>
								) ) }
							</ol>
							{ preview.more && (
								<p className="rayec-muted">
									{ __(
										'…and it carries on.',
										'rayetun-events-calendar'
									) }
								</p>
							) }
						</div>
					) }

					<h3 className="rayec-form__section">
						{ __( 'Where', 'rayetun-events-calendar' ) }
					</h3>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Format', 'rayetun-events-calendar' ) }
						value={ event.format }
						options={ [
							{
								label: __(
									'In person',
									'rayetun-events-calendar'
								),
								value: 'in_person',
							},
							{
								label: __(
									'Online',
									'rayetun-events-calendar'
								),
								value: 'online',
							},
							{
								label: __(
									'Hybrid (in person and online)',
									'rayetun-events-calendar'
								),
								value: 'hybrid',
							},
						] }
						onChange={ ( format ) => set( { format } ) }
					/>
					{ event.format !== 'online' && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Venue', 'rayetun-events-calendar' ) }
							help={
								venues.length === 0
									? __(
											'No venues yet. Add one on the Venues screen and it will appear here.',
											'rayetun-events-calendar'
									  )
									: undefined
							}
							value={ String( event.venue_id ) }
							options={ [
								{
									label: __(
										'No venue',
										'rayetun-events-calendar'
									),
									value: '0',
								},
								...venues.map( ( venue ) => ( {
									label: venue.city
										? `${ venue.name }, ${ venue.city }`
										: venue.name,
									value: String( venue.id ),
								} ) ),
							] }
							onChange={ ( venueId ) =>
								set( {
									venue_id: parseInt( venueId, 10 ),
									room_id: '',
								} )
							}
						/>
					) }
					{ event.format !== 'online' && rooms.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Room', 'rayetun-events-calendar' ) }
							value={ event.room_id || '' }
							options={ [
								{
									label: __(
										'Whole venue',
										'rayetun-events-calendar'
									),
									value: '',
								},
								...rooms.map( ( room ) => ( {
									label: room.name,
									value: room.id,
								} ) ),
							] }
							onChange={ ( roomId ) => set( { room_id: roomId } ) }
						/>
					) }
					{ ( event.format !== 'in_person' ||
						event.event_status === 'moved_online' ) && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="url"
							label={ __(
								'Joining link',
								'rayetun-events-calendar'
							) }
							placeholder="https://"
							value={ event.online_url }
							onChange={ ( onlineUrl ) =>
								set( { online_url: onlineUrl } )
							}
						/>
					) }

					{ organizers.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Organizer',
								'rayetun-events-calendar'
							) }
							value={ String( event.organizer_id || 0 ) }
							options={ [
								{
									label: __(
										'No organizer',
										'rayetun-events-calendar'
									),
									value: '0',
								},
								...organizers.map( ( organizer ) => ( {
									label: organizer.name,
									value: String( organizer.id ),
								} ) ),
							] }
							onChange={ ( organizerId ) =>
								set( {
									organizer_id: parseInt( organizerId, 10 ),
								} )
							}
						/>
					) }

					{ programs.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Program', 'rayetun-events-calendar' ) }
							help={ __(
								'A festival, course or season this event is part of.',
								'rayetun-events-calendar'
							) }
							value={ String( event.program_id || 0 ) }
							options={ [
								{
									label: __(
										'Not part of a program',
										'rayetun-events-calendar'
									),
									value: '0',
								},
								...programs.map( ( program ) => ( {
									label: program.name,
									value: String( program.id ),
								} ) ),
							] }
							onChange={ ( programId ) =>
								set( { program_id: parseInt( programId, 10 ) } )
							}
						/>
					) }

					{ speakers.length > 0 && (
						<fieldset className="rayec-checks rayec-checks--grid">
							<legend>
								{ __( 'Speakers', 'rayetun-events-calendar' ) }
							</legend>
							{ speakers.map( ( speaker ) => (
								<CheckboxControl
									__nextHasNoMarginBottom
									key={ speaker.id }
									label={
										speaker.role
											? `${ speaker.name } (${ speaker.role })`
											: speaker.name
									}
									checked={ speakerIds.includes( speaker.id ) }
									onChange={ ( checked ) =>
										set( {
											speaker_ids: checked
												? [ ...speakerIds, speaker.id ]
												: speakerIds.filter(
														( id ) => id !== speaker.id
												  ),
										} )
									}
								/>
							) ) }
						</fieldset>
					) }

					<fieldset className="rayec-checks rayec-checks--grid">
						<legend>
							{ __(
								'Access at this event',
								'rayetun-events-calendar'
							) }
						</legend>
						{ Object.entries( config.eventAccess || {} ).map(
							( [ key, label ] ) => {
								const access = event.access || [];
								return (
									<CheckboxControl
										__nextHasNoMarginBottom
										key={ key }
										label={ label }
										checked={ access.includes( key ) }
										onChange={ ( on ) =>
											set( {
												access: on
													? [ ...access, key ]
													: access.filter(
															( item ) =>
																item !== key
													  ),
											} )
										}
									/>
								);
							}
						) }
					</fieldset>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Anything else about access',
							'rayetun-events-calendar'
						) }
						help={ __(
							'Shown on the event page, beside the access details of the venue. For example: “The talk is recorded and captioned afterwards.”',
							'rayetun-events-calendar'
						) }
						rows={ 2 }
						value={ event.access_notes || '' }
						onChange={ ( notes ) => set( { access_notes: notes } ) }
					/>

					<h3 className="rayec-form__section">
						{ __( 'Image', 'rayetun-events-calendar' ) }
					</h3>
					<ImageField
						image={ event.image }
						onChange={ ( image ) =>
							set( {
								image,
								thumbnail_id: image ? image.id : 0,
							} )
						}
					/>

					<h3 className="rayec-form__section">
						{ __( 'Registration', 'rayetun-events-calendar' ) }
					</h3>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Take registrations',
							'rayetun-events-calendar'
						) }
						help={ __(
							'Adds a Register form to the event page. People book a free place and get a confirmation email with a calendar file.',
							'rayetun-events-calendar'
						) }
						checked={ rsvp.enabled }
						onChange={ ( enabled ) => setRsvp( { enabled } ) }
					/>
					{ rsvp.enabled && (
						<div className="rayec-rsvp-settings">
							<div className="rayec-form__row">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="number"
									min={ 0 }
									label={ __(
										'Places per date',
										'rayetun-events-calendar'
									) }
									help={
										event.rrule || rrule
											? __(
													'Every date in the series gets this many places. 0 means no limit.',
													'rayetun-events-calendar'
											  )
											: __(
													'0 means no limit.',
													'rayetun-events-calendar'
											  )
									}
									value={ String( rsvp.capacity ?? 0 ) }
									onChange={ ( capacity ) =>
										setRsvp( {
											capacity: Math.max(
												0,
												parseInt( capacity, 10 ) || 0
											),
										} )
									}
								/>
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Places one person can book',
										'rayetun-events-calendar'
									) }
									value={ String( rsvp.max ) }
									options={ Array.from(
										{ length: 10 },
										( unused, index ) => ( {
											label: String( index + 1 ),
											value: String( index + 1 ),
										} )
									) }
									onChange={ ( max ) =>
										setRsvp( { max: parseInt( max, 10 ) } )
									}
								/>
							</div>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Ask about access requirements',
									'rayetun-events-calendar'
								) }
								help={ __(
									'People can tell you what would help them take part. Only organisers see the answer.',
									'rayetun-events-calendar'
								) }
								checked={ rsvp.access }
								onChange={ ( access ) => setRsvp( { access } ) }
							/>
						</div>
					) }
					{ event.id > 0 && rsvp.enabled && (
						<Attendees eventId={ event.id } />
					) }

					{ /* Add-ons add their own sections, such as tickets. */ }
					{ applyFilters( 'rayec.drawerPanels', [], event ).map(
						( panel ) => (
							<div key={ panel.key } className="rayec-drawer-panel">
								<h3 className="rayec-form__section">
									{ panel.title }
								</h3>
								{ panel.render( { event, set } ) }
							</div>
						)
					) }

					{ ! event.id && (
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'Description',
								'rayetun-events-calendar'
							) }
							help={ __(
								'Plain text for now. After saving you can open the event in the block editor to add images and formatting.',
								'rayetun-events-calendar'
							) }
							rows={ 4 }
							value={ event.description }
							onChange={ ( description ) =>
								set( { description } )
							}
						/>
					) }

					<Button
						variant="link"
						className="rayec-form__more"
						aria-expanded={ showMore }
						onClick={ () => setShowMore( ! showMore ) }
					>
						{ showMore
							? __(
									'Hide extra options',
									'rayetun-events-calendar'
							  )
							: __(
									'Status, cost, categories and time zone',
									'rayetun-events-calendar'
							  ) }
					</Button>

					{ showMore && (
						<div className="rayec-form__extra">
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Event status',
									'rayetun-events-calendar'
								) }
								help={
									rsvp.enabled
										? __(
												'Shown as a banner on the event page and in calendar feeds. Everyone booked on a coming date is emailed about the change a minute after you save.',
												'rayetun-events-calendar'
										  )
										: __(
												'Shown as a banner on the event page and in calendar feeds.',
												'rayetun-events-calendar'
										  )
								}
								value={ event.event_status }
								options={ [
									{
										label: __(
											'Going ahead',
											'rayetun-events-calendar'
										),
										value: 'scheduled',
									},
									{
										label: __(
											'Cancelled',
											'rayetun-events-calendar'
										),
										value: 'cancelled',
									},
									{
										label: __(
											'Postponed',
											'rayetun-events-calendar'
										),
										value: 'postponed',
									},
									{
										label: __(
											'Moved online',
											'rayetun-events-calendar'
										),
										value: 'moved_online',
									},
								] }
								onChange={ ( eventStatus ) =>
									set( { event_status: eventStatus } )
								}
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Cost',
									'rayetun-events-calendar'
								) }
								placeholder={ __(
									'Free, £5, pay what you can…',
									'rayetun-events-calendar'
								) }
								value={ event.cost }
								onChange={ ( cost ) => set( { cost } ) }
							/>
							{ categories.length > 0 && (
								<fieldset className="rayec-checks">
									<legend>
										{ __(
											'Categories',
											'rayetun-events-calendar'
										) }
									</legend>
									{ categories.map( ( category ) => {
										const checked = event.categories.some(
											( item ) => item.id === category.id
										);
										return (
											<CheckboxControl
												__nextHasNoMarginBottom
												key={ category.id }
												label={ category.name }
												checked={ checked }
												onChange={ () =>
													set( {
														categories: checked
															? event.categories.filter(
																	( item ) =>
																		item.id !==
																		category.id
															  )
															: [
																	...event.categories,
																	category,
															  ],
													} )
												}
											/>
										);
									} ) }
								</fieldset>
							) }
							<ComboboxControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Time zone',
									'rayetun-events-calendar'
								) }
								help={ __(
									'Times above are in this zone. Repeating events keep the same clock time all year.',
									'rayetun-events-calendar'
								) }
								value={ event.timezone }
								options={ [
									...new Set( [
										event.timezone,
										...( config.timezones || [] ),
									] ),
								].map( ( zone ) => ( {
									label: zone.replace( /_/g, ' ' ),
									value: zone,
								} ) ) }
								onChange={ ( timezone ) =>
									timezone && set( { timezone } )
								}
								allowReset={ false }
							/>
						</div>
					) }

					{ event.id > 0 && event.guard && (
						<div className="rayec-guard">
							<h3 className="rayec-form__section">
								<Icon name="shield" size={ 16 } />
								{ __(
									'Accessibility Guard',
									'rayetun-events-calendar'
								) }
								<span className="rayec-guard__score">
									{ event.guard.score } / 100
								</span>
							</h3>
							{ event.guard.issues.length === 0 ? (
								<p className="rayec-allclear">
									<Icon name="check" />
									{ __(
										'No issues found.',
										'rayetun-events-calendar'
									) }
								</p>
							) : (
								<ul className="rayec-guard__list">
									{ event.guard.issues.map( ( issue ) => (
										<li
											key={ issue.code }
											className={ `is-${ issue.level }` }
										>
											<Icon name="alert" size={ 15 } />
											<span>
												<span className="screen-reader-text">
													{ guardLevel(
														issue.level
													) }
												</span>{ ' ' }
												{ issue.message }
												{ issue.fix && (
													<span className="rayec-guard__fix">
														{ issue.fix }
													</span>
												) }
											</span>
										</li>
									) ) }
								</ul>
							) }
							<p>
								<a href={ event.edit_link }>
									{ __(
										'Edit the description and image in the block editor',
										'rayetun-events-calendar'
									) }
								</a>
							</p>
						</div>
					) }

					{ error && <ErrorNote message={ error } /> }

					<div className="rayec-form__foot">
						<Button
							variant="primary"
							type="submit"
							__next40pxDefaultSize
							isBusy={ saving }
							disabled={ saving }
						>
							{ event.id && event.post_status === 'draft'
								? __( 'Save draft', 'rayetun-events-calendar' )
								: event.id
								? __( 'Save changes', 'rayetun-events-calendar' )
								: __( 'Publish event', 'rayetun-events-calendar' ) }
						</Button>
						{ ( ! event.id || event.post_status === 'draft' ) && (
							<Button
								variant="secondary"
								__next40pxDefaultSize
								disabled={ saving }
								onClick={ () =>
									save( event.id ? 'publish' : 'draft' )
								}
							>
								{ event.id
									? __( 'Publish', 'rayetun-events-calendar' )
									: __(
											'Save as draft',
											'rayetun-events-calendar'
									  ) }
							</Button>
						) }
						{ event.id > 0 && (
							<>
								<a
									className="rayec-form__view"
									href={ event.permalink }
									target="_blank"
									rel="noreferrer"
								>
									{ __( 'View', 'rayetun-events-calendar' ) }
									<span className="screen-reader-text">
										{ __(
											'(opens in a new tab)',
											'rayetun-events-calendar'
										) }
									</span>
								</a>
								<Button
									variant="tertiary"
									isDestructive
									__next40pxDefaultSize
									className="rayec-form__delete"
									onClick={ remove }
								>
									{ __(
										'Move to bin',
										'rayetun-events-calendar'
									) }
								</Button>
							</>
						) }
					</div>
				</form>
			) }
		</Modal>
	);
}
