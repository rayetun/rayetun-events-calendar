import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	PluginPrePublishPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import './editor.css';
import { api, config, today, addDays, parseRule, buildRule } from '../admin/lib';
import { DateTimeField, RepeatFields } from '../admin/components/event-drawer';

const POST_TYPE = 'rayec_event';

/** Stored "Y-m-d H:i:s" to the "Y-m-dTH:i" the date inputs use. */
const toInput = ( stored, fallback ) =>
	stored ? stored.slice( 0, 16 ).replace( ' ', 'T' ) : fallback;
const toStored = ( value ) => value.replace( 'T', ' ' ) + ':00';

function useEventMeta() {
	const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta' );
	const tomorrow = addDays( today(), 1 );
	const values = {
		start: toInput( meta?._rayec_start, `${ tomorrow }T10:00` ),
		end: toInput( meta?._rayec_end, `${ tomorrow }T11:00` ),
		allDay: !! meta?._rayec_all_day,
		rrule: meta?._rayec_rrule || '',
		skipClosed: !! meta?._rayec_skip_closed,
		status: meta?._rayec_status || 'scheduled',
		format: meta?._rayec_format || 'in_person',
		venueId: meta?._rayec_venue_id || 0,
		roomId: meta?._rayec_room || '',
		extraTimes: meta?._rayec_extra_times || [],
		speakerIds: meta?._rayec_speaker || [],
		organizerId: meta?._rayec_organizer_id || 0,
		onlineUrl: meta?._rayec_online_url || '',
		cost: meta?._rayec_cost || '',
	};
	const set = ( changes ) => setMeta( { ...meta, ...changes } );
	return [ values, set, !! meta ];
}

function WhenPanel() {
	const [ event, set, ready ] = useEventMeta();
	// The repeat form keeps its own state so half-finished choices are not lost.
	const [ rule, setRuleState ] = useState( () => parseRule( event.rrule ) );
	const loaded = useRef( false );

	useEffect( () => {
		if ( ready && ! loaded.current ) {
			loaded.current = true;
			setRuleState( parseRule( event.rrule ) );
		}
	}, [ ready, event.rrule ] );

	if ( ! ready ) {
		return null;
	}

	const setRule = ( next ) => {
		setRuleState( next );
		set( { _rayec_rrule: buildRule( next, event.start.slice( 0, 10 ) ) } );
	};

	return (
		<PluginDocumentSettingPanel
			name="rayec-when"
			title={ __( 'When', 'rayetun-events-calendar' ) }
			className="rayec-editor-panel"
		>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'All day', 'rayetun-events-calendar' ) }
				checked={ event.allDay }
				onChange={ ( value ) => set( { _rayec_all_day: value } ) }
			/>
			<DateTimeField
				label={ __( 'Starts', 'rayetun-events-calendar' ) }
				value={ event.start }
				allDay={ event.allDay }
				onChange={ ( start ) => {
					const length =
						new Date( event.end + 'Z' ) -
						new Date( event.start + 'Z' );
					const end = new Date(
						new Date( start + 'Z' ).getTime() +
							( length > 0 ? length : 3600000 )
					)
						.toISOString()
						.slice( 0, 16 );
					set( {
						_rayec_start: toStored( start ),
						_rayec_end: toStored( end ),
						_rayec_rrule: buildRule( rule, start.slice( 0, 10 ) ),
					} );
				} }
			/>
			<DateTimeField
				label={ __( 'Ends', 'rayetun-events-calendar' ) }
				value={ event.end }
				allDay={ event.allDay }
				min={ event.allDay ? event.start.slice( 0, 10 ) : event.start }
				onChange={ ( end ) =>
					set( {
						_rayec_start: toStored( event.start ),
						_rayec_end: toStored( end ),
					} )
				}
			/>
			{ ! event.allDay && (
				<fieldset className="rayec-editor-times">
					<legend>
						{ __(
							'More start times on the same day',
							'rayetun-events-calendar'
						) }
					</legend>
					{ event.extraTimes.map( ( time, index ) => (
						<div key={ index } className="rayec-editor-times__row">
							<input
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
										_rayec_extra_times: event.extraTimes.map(
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
								size="small"
								onClick={ () =>
									set( {
										_rayec_extra_times:
											event.extraTimes.filter(
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
					{ event.extraTimes.length < 8 && (
						<Button
							variant="secondary"
							size="small"
							onClick={ () => {
								const latest = [
									event.start.slice( 11, 16 ),
									...event.extraTimes,
								]
									.filter( Boolean )
									.sort()
									.pop();
								const hour = Math.min(
									23,
									parseInt( latest.slice( 0, 2 ), 10 ) + 2
								);
								set( {
									_rayec_extra_times: [
										...event.extraTimes,
										`${ String( hour ).padStart(
											2,
											'0'
										) }:${ latest.slice( 3, 5 ) }`,
									],
								} );
							} }
						>
							{ __( 'Add a start time', 'rayetun-events-calendar' ) }
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
						'Leaves out the public holidays and closures listed in Events → Settings.',
						'rayetun-events-calendar'
					) }
					checked={ event.skipClosed }
					onChange={ ( value ) =>
						set( { _rayec_skip_closed: value } )
					}
				/>
			) }
			<p className="rayec-editor-note">
				{ sprintf(
					/* translators: %s: time zone name. */
					__( 'Times are in %s.', 'rayetun-events-calendar' ),
					config.timezone
				) }
			</p>
		</PluginDocumentSettingPanel>
	);
}

function WherePanel() {
	const [ event, set, ready ] = useEventMeta();
	const [ venues, setVenues ] = useState( [] );

	const [ organizers, setOrganizers ] = useState( [] );
	const [ speakers, setSpeakers ] = useState( [] );
	const rooms =
		( venues.find( ( venue ) => venue.id === event.venueId ) || {} ).rooms ||
		[];

	useEffect( () => {
		api.get( '/venues' )
			.then( setVenues )
			.catch( () => {} );
		api.get( '/organizers' )
			.then( setOrganizers )
			.catch( () => {} );
		api.get( '/speakers' )
			.then( setSpeakers )
			.catch( () => {} );
	}, [] );

	if ( ! ready ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="rayec-where"
			title={ __( 'Where and status', 'rayetun-events-calendar' ) }
			className="rayec-editor-panel"
		>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Format', 'rayetun-events-calendar' ) }
				value={ event.format }
				options={ [
					{
						label: __( 'In person', 'rayetun-events-calendar' ),
						value: 'in_person',
					},
					{
						label: __( 'Online', 'rayetun-events-calendar' ),
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
				onChange={ ( value ) => set( { _rayec_format: value } ) }
			/>
			{ event.format !== 'online' && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Venue', 'rayetun-events-calendar' ) }
					help={
						<a href={ `${ config.dashboardUrl }#/venues` }>
							{ __(
								'Manage venues and their access details',
								'rayetun-events-calendar'
							) }
						</a>
					}
					value={ String( event.venueId ) }
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
					onChange={ ( value ) =>
						set( {
							_rayec_venue_id: parseInt( value, 10 ),
							_rayec_room: '',
						} )
					}
				/>
			) }
			{ event.format !== 'online' && rooms.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Room', 'rayetun-events-calendar' ) }
					value={ event.roomId }
					options={ [
						{
							label: __( 'Whole venue', 'rayetun-events-calendar' ),
							value: '',
						},
						...rooms.map( ( room ) => ( {
							label: room.name,
							value: room.id,
						} ) ),
					] }
					onChange={ ( value ) => set( { _rayec_room: value } ) }
				/>
			) }
			{ ( event.format !== 'in_person' ||
				event.status === 'moved_online' ) && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="url"
					label={ __( 'Joining link', 'rayetun-events-calendar' ) }
					placeholder="https://"
					value={ event.onlineUrl }
					onChange={ ( value ) =>
						set( { _rayec_online_url: value } )
					}
				/>
			) }
			{ organizers.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Organizer', 'rayetun-events-calendar' ) }
					value={ String( event.organizerId ) }
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
					onChange={ ( value ) =>
						set( { _rayec_organizer_id: parseInt( value, 10 ) } )
					}
				/>
			) }
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Event status', 'rayetun-events-calendar' ) }
				value={ event.status }
				options={ [
					{
						label: __( 'Going ahead', 'rayetun-events-calendar' ),
						value: 'scheduled',
					},
					{
						label: __( 'Cancelled', 'rayetun-events-calendar' ),
						value: 'cancelled',
					},
					{
						label: __( 'Postponed', 'rayetun-events-calendar' ),
						value: 'postponed',
					},
					{
						label: __( 'Moved online', 'rayetun-events-calendar' ),
						value: 'moved_online',
					},
				] }
				onChange={ ( value ) => set( { _rayec_status: value } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Cost', 'rayetun-events-calendar' ) }
				value={ event.cost }
				onChange={ ( value ) => set( { _rayec_cost: value } ) }
			/>
		{ speakers.length > 0 && (
				<fieldset className="rayec-editor-times">
					<legend>{ __( 'Speakers', 'rayetun-events-calendar' ) }</legend>
					{ speakers.map( ( speaker ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ speaker.id }
							label={ speaker.name }
							checked={ event.speakerIds.includes( speaker.id ) }
							onChange={ ( checked ) =>
								set( {
									_rayec_speaker: checked
										? [ ...event.speakerIds, speaker.id ]
										: event.speakerIds.filter(
												( id ) => id !== speaker.id
										  ),
								} )
							}
						/>
					) ) }
				</fieldset>
			) }
		</PluginDocumentSettingPanel>
	);
}

/**
 * Guard result for what is on screen, saved or not.
 *
 * A pre-publish check that only knows the last saved version is no check at
 * all on the thing about to be published, so the title, the content and the
 * featured image are sent as the editor holds them and the rest is read from
 * the saved event. It settles for a second before asking, so typing a
 * paragraph is one request rather than two hundred.
 */
function useGuard() {
	const { postId, title, content, thumbnailId, isSaving } = useSelect(
		( select ) => {
			const editor = select( editorStore );
			return {
				postId: editor.getCurrentPostId(),
				title: editor.getEditedPostAttribute( 'title' ) || '',
				content: editor.getEditedPostAttribute( 'content' ) || '',
				thumbnailId:
					editor.getEditedPostAttribute( 'featured_media' ) || 0,
				isSaving:
					editor.isSavingPost() && ! editor.isAutosavingPost(),
			};
		},
		[]
	);
	const [ guard, setGuard ] = useState( null );
	const latest = useRef( 0 );

	useEffect( () => {
		if ( isSaving ) {
			return undefined;
		}
		const timer = setTimeout( () => {
			const mine = ++latest.current;
			api.post( '/guard', {
				id: postId,
				title,
				content,
				thumbnail_id: thumbnailId,
			} )
				.then( ( result ) => {
					// An older answer must never overwrite a newer one.
					if ( mine === latest.current ) {
						setGuard( result );
					}
				} )
				.catch( () => {} );
		}, 900 );
		return () => clearTimeout( timer );
	}, [ postId, title, content, thumbnailId, isSaving ] );

	return guard;
}

/** What each level is called where someone has to read it. */
const levelName = ( level ) => {
	if ( 'error' === level ) {
		return __( 'Problem:', 'rayetun-events-calendar' );
	}
	if ( 'note' === level ) {
		return __( 'Worth a look:', 'rayetun-events-calendar' );
	}
	return __( 'Suggestion:', 'rayetun-events-calendar' );
};

function GuardList( { guard } ) {
	if ( ! guard ) {
		return (
			<p className="rayec-editor-note">
				{ __( 'Checking…', 'rayetun-events-calendar' ) }
			</p>
		);
	}
	if ( guard.issues.length === 0 ) {
		return (
			<p className="rayec-editor-guard is-good">
				{ __(
					'Nothing to flag. Checked as you type, not only on save.',
					'rayetun-events-calendar'
				) }
			</p>
		);
	}
	return (
		<>
			<ul className="rayec-editor-guard">
				{ guard.issues.map( ( issue ) => (
					<li key={ issue.code } className={ `is-${ issue.level }` }>
						<strong>{ levelName( issue.level ) }</strong>{ ' ' }
						{ issue.message }
						{ issue.fix && (
							<span className="rayec-editor-guard__fix">
								{ issue.fix }
							</span>
						) }
					</li>
				) ) }
			</ul>
			<p className="rayec-editor-note">
				{ __(
					'Nothing here stops you publishing.',
					'rayetun-events-calendar'
				) }
			</p>
		</>
	);
}

/** Access laid on at the event itself, beside the venue's own. */
function AccessPanel() {
	const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta' );
	const access = Array.isArray( meta?._rayec_event_access )
		? meta._rayec_event_access
		: [];

	return (
		<PluginDocumentSettingPanel
			name="rayec-access"
			title={ __( 'Access at this event', 'rayetun-events-calendar' ) }
			className="rayec-editor-panel"
		>
			{ Object.entries( config.eventAccess || {} ).map(
				( [ key, label ] ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ key }
						label={ label }
						checked={ access.includes( key ) }
						onChange={ ( on ) =>
							setMeta( {
								...meta,
								_rayec_event_access: on
									? [ ...access, key ]
									: access.filter( ( item ) => item !== key ),
							} )
						}
					/>
				)
			) }
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __(
					'Anything else about access',
					'rayetun-events-calendar'
				) }
				rows={ 2 }
				value={ meta?._rayec_event_access_notes || '' }
				onChange={ ( notes ) =>
					setMeta( { ...meta, _rayec_event_access_notes: notes } )
				}
			/>
		</PluginDocumentSettingPanel>
	);
}

function GuardPanels() {
	const guard = useGuard();
	const title = guard
		? sprintf(
				/* translators: %d: score out of 100. */
				__( 'Accessibility Guard: %d / 100', 'rayetun-events-calendar' ),
				guard.score
		  )
		: __( 'Accessibility Guard', 'rayetun-events-calendar' );

	return (
		<>
			<PluginDocumentSettingPanel
				name="rayec-guard"
				title={ title }
				className="rayec-editor-panel"
			>
				<GuardList guard={ guard } />
			</PluginDocumentSettingPanel>
			<PluginPrePublishPanel
				title={ title }
				initialOpen={ !! guard && guard.issues.length > 0 }
			>
				<GuardList guard={ guard } />
			</PluginPrePublishPanel>
		</>
	);
}

registerPlugin( 'rayec-event-details', {
	render: () => {
		const postType = useSelect(
			( select ) => select( editorStore ).getCurrentPostType(),
			[]
		);
		if ( postType !== POST_TYPE ) {
			return null;
		}
		return (
			<>
				<WhenPanel />
				<WherePanel />
				<AccessPanel />
				<GuardPanels />
			</>
		);
	},
} );
