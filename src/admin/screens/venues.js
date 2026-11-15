import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	TextControl,
	TextareaControl,
	CheckboxControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { api, config, useApp } from '../lib';
import { Icon } from '../icons';
import {
	useLoad,
	PageHeader,
	Loading,
	ErrorNote,
	Empty,
} from '../components/ui';

const blank = {
	id: 0,
	name: '',
	address: '',
	city: '',
	access: [],
	access_notes: '',
	directions: '',
	lat: '',
	lng: '',
	rooms: [],
};

/** Rooms inside a venue: add, rename, remove. */
function RoomsField( { rooms, onChange } ) {
	const [ name, setName ] = useState( '' );
	const add = () => {
		const clean = name.trim();
		if (
			clean &&
			! rooms.some(
				( room ) => room.name.toLowerCase() === clean.toLowerCase()
			)
		) {
			onChange( [ ...rooms, { id: '', name: clean } ] );
		}
		setName( '' );
	};
	return (
		<fieldset className="rayec-rooms">
			<legend>{ __( 'Rooms', 'rayetun-events-calendar' ) }</legend>
			<p className="rayec-muted">
				{ __(
					'Optional. With rooms listed, an event can be placed in one of them, and two events in different rooms at the same time are not flagged as a clash.',
					'rayetun-events-calendar'
				) }
			</p>
			{ rooms.length > 0 && (
				<ul className="rayec-rooms__list">
					{ rooms.map( ( room, index ) => (
						<li key={ room.id || `new-${ index }` }>
							<input
								className="rayec-input"
								type="text"
								value={ room.name }
								aria-label={ sprintf(
									/* translators: %d: position in the list. */
									__(
										'Name of room %d',
										'rayetun-events-calendar'
									),
									index + 1
								) }
								onChange={ ( event ) =>
									onChange(
										rooms.map( ( item, i ) =>
											i === index
												? {
														...item,
														name: event.target.value,
												  }
												: item
										)
									)
								}
							/>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () =>
									onChange(
										rooms.filter( ( item, i ) => i !== index )
									)
								}
								aria-label={ sprintf(
									/* translators: %s: room name. */
									__( 'Remove %s', 'rayetun-events-calendar' ),
									room.name
								) }
							>
								{ __( 'Remove', 'rayetun-events-calendar' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }
			<div className="rayec-rooms__add">
				<input
					className="rayec-input"
					type="text"
					value={ name }
					placeholder={ __(
						'For example: Main hall',
						'rayetun-events-calendar'
					) }
					aria-label={ __(
						'New room name',
						'rayetun-events-calendar'
					) }
					onChange={ ( event ) => setName( event.target.value ) }
					onKeyDown={ ( event ) => {
						if ( event.key === 'Enter' ) {
							// Enter adds the room instead of saving the whole venue.
							event.preventDefault();
							add();
						}
					} }
				/>
				<Button
					variant="secondary"
					disabled={ ! name.trim() }
					onClick={ add }
				>
					{ __( 'Add room', 'rayetun-events-calendar' ) }
				</Button>
			</div>
		</fieldset>
	);
}

function VenueForm( { venue, onClose } ) {
	const { notify, refresh } = useApp();
	const [ form, setForm ] = useState( { rooms: [], ...venue } );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const set = ( changes ) => setForm( { ...form, ...changes } );
	const [ looking, setLooking ] = useState( false );
	const [ lookNote, setLookNote ] = useState( '' );

	// Runs only when the button is pressed; nothing is looked up automatically.
	const lookUp = () => {
		const query = [ form.address, form.city ].filter( Boolean ).join( ', ' ) || form.name;
		setLooking( true );
		setLookNote( '' );
		window
			.fetch(
				`https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${ encodeURIComponent(
					query
				) }`,
				{ headers: { Accept: 'application/json' } }
			)
			.then( ( response ) => response.json() )
			.then( ( results ) => {
				if ( ! results.length ) {
					setLookNote(
						__(
							'No match for that address. Enter the numbers by hand, or try a fuller address.',
							'rayetun-events-calendar'
						)
					);
					return;
				}
				setForm( ( current ) => ( {
					...current,
					lat: String( parseFloat( results[ 0 ].lat ).toFixed( 6 ) ),
					lng: String( parseFloat( results[ 0 ].lon ).toFixed( 6 ) ),
				} ) );
				setLookNote(
					sprintf(
						/* translators: %s: matched place name. */
						__( 'Found: %s', 'rayetun-events-calendar' ),
						results[ 0 ].display_name
					)
				);
			} )
			.catch( () =>
				setLookNote(
					__(
						'The lookup could not be reached. You can enter the numbers by hand.',
						'rayetun-events-calendar'
					)
				)
			)
			.finally( () => setLooking( false ) );
	};

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		api.post( form.id ? `/venues/${ form.id }` : '/venues', form )
			.then( ( saved ) => {
				notify(
					sprintf(
						/* translators: %s: venue name. */
						__( '“%s” saved.', 'rayetun-events-calendar' ),
						saved.name
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

	return (
		<Modal
			title={
				form.id
					? __( 'Edit venue', 'rayetun-events-calendar' )
					: __( 'Add venue', 'rayetun-events-calendar' )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<form className="rayec-form" onSubmit={ save }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Venue name', 'rayetun-events-calendar' ) }
					value={ form.name }
					onChange={ ( name ) => set( { name } ) }
					required
				/>
				<div className="rayec-form__row">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Address', 'rayetun-events-calendar' ) }
						value={ form.address }
						onChange={ ( address ) => set( { address } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Town or city', 'rayetun-events-calendar' ) }
						value={ form.city }
						onChange={ ( city ) => set( { city } ) }
					/>
				</div>
				<fieldset className="rayec-mapfields">
					<legend>
						{ __( 'Map pin', 'rayetun-events-calendar' ) }
					</legend>
					<div className="rayec-form__row">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Latitude', 'rayetun-events-calendar' ) }
							value={ form.lat }
							onChange={ ( lat ) => set( { lat } ) }
							inputMode="decimal"
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Longitude', 'rayetun-events-calendar' ) }
							value={ form.lng }
							onChange={ ( lng ) => set( { lng } ) }
							inputMode="decimal"
						/>
					</div>
					<Button
						variant="secondary"
						disabled={ looking || ! ( form.address || form.name ) }
						isBusy={ looking }
						onClick={ lookUp }
					>
						{ __(
							'Find from the address',
							'rayetun-events-calendar'
						) }
					</Button>
					<p className="rayec-muted" role="status">
						{ lookNote ||
							__(
								'Optional. Looking up sends the address above to OpenStreetMap (nominatim.openstreetmap.org). With a pin set, the event page offers visitors a map they can choose to load.',
								'rayetun-events-calendar'
							) }
					</p>
				</fieldset>
				<RoomsField
					rooms={ form.rooms }
					onChange={ ( rooms ) => set( { rooms } ) }
				/>
				<fieldset className="rayec-checks rayec-checks--grid">
					<legend>
						{ __( 'Access at this venue', 'rayetun-events-calendar' ) }
					</legend>
					{ Object.entries( config.accessFeatures || {} ).map(
						( [ key, label ] ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ key }
								label={ label }
								checked={ form.access.includes( key ) }
								onChange={ ( checked ) =>
									set( {
										access: checked
											? [ ...form.access, key ]
											: form.access.filter(
													( item ) => item !== key
											  ),
									} )
								}
							/>
						)
					) }
				</fieldset>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Access notes', 'rayetun-events-calendar' ) }
					help={ __(
						'Anything a visitor should know before arriving: which entrance is level, where to park, who to ask for help.',
						'rayetun-events-calendar'
					) }
					rows={ 3 }
					value={ form.access_notes }
					onChange={ ( notes ) => set( { access_notes: notes } ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Getting here', 'rayetun-events-calendar' ) }
					help={ __(
						'Buses, trains, parking, which door to use. This is what a visitor reads instead of the map, so write it for someone who has never been.',
						'rayetun-events-calendar'
					) }
					rows={ 3 }
					value={ form.directions }
					onChange={ ( directions ) => set( { directions } ) }
				/>
				{ error && <ErrorNote message={ error } /> }
				<div className="rayec-form__foot">
					<Button
						variant="primary"
						type="submit"
						__next40pxDefaultSize
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save venue', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function Venues() {
	const { notify, refresh } = useApp();
	const { data, loading, error } = useLoad( '/venues' );
	const [ editing, setEditing ] = useState( null );

	const remove = ( venue ) =>
		api
			.del( `/venues/${ venue.id }` )
			.then( () => {
				refresh();
				notify(
					sprintf(
						/* translators: %s: venue name. */
						__( '“%s” moved to the bin.', 'rayetun-events-calendar' ),
						venue.name
					)
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Venues', 'rayetun-events-calendar' ) }
				subtitle={ __(
					'Places you reuse across events, each with its own access details.',
					'rayetun-events-calendar'
				) }
			>
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ () => setEditing( blank ) }
				>
					{ __( 'Add venue', 'rayetun-events-calendar' ) }
				</Button>
			</PageHeader>

			{ error && <ErrorNote message={ error } /> }
			{ ! data && loading && <Loading /> }

			{ data && data.length === 0 && (
				<div className="rayec-panel">
					<Empty
						icon="pin"
						title={ __( 'No venues yet', 'rayetun-events-calendar' ) }
						action={
							<Button
								variant="primary"
								onClick={ () => setEditing( blank ) }
							>
								{ __( 'Add venue', 'rayetun-events-calendar' ) }
							</Button>
						}
					>
						{ __(
							'Add a venue once, describe how to get in, and every event held there shows the same access details.',
							'rayetun-events-calendar'
						) }
					</Empty>
				</div>
			) }

			{ data && data.length > 0 && (
				<ul className="rayec-grid">
					{ data.map( ( venue ) => (
						<li key={ venue.id } className="rayec-card">
							<h2 className="rayec-card__title">{ venue.name }</h2>
							<p className="rayec-card__desc">
								{ [ venue.address, venue.city ]
									.filter( Boolean )
									.join( ', ' ) ||
									__(
										'No address yet',
										'rayetun-events-calendar'
									) }
							</p>
							{ venue.rooms && venue.rooms.length > 0 && (
								<p className="rayec-card__desc">
									{ sprintf(
										/* translators: %s: list of room names. */
										__( 'Rooms: %s', 'rayetun-events-calendar' ),
										venue.rooms
											.map( ( room ) => room.name )
											.join( ', ' )
									) }
								</p>
							) }
							{ venue.access.length > 0 ? (
								<ul className="rayec-tags">
									{ venue.access.map( ( key ) => (
										<li key={ key }>
											<Icon name="check" size={ 12 } />
											{ config.accessFeatures[ key ] }
										</li>
									) ) }
								</ul>
							) : (
								<p className="rayec-card__warn">
									<Icon name="alert" size={ 14 } />
									{ __(
										'No access details yet',
										'rayetun-events-calendar'
									) }
								</p>
							) }
							<div className="rayec-card__foot">
								<Button
									variant="secondary"
									onClick={ () => setEditing( venue ) }
									aria-label={ sprintf(
										/* translators: %s: venue name. */
										__( 'Edit %s', 'rayetun-events-calendar' ),
										venue.name
									) }
								>
									{ __( 'Edit', 'rayetun-events-calendar' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => remove( venue ) }
									aria-label={ sprintf(
										/* translators: %s: venue name. */
										__( 'Delete %s', 'rayetun-events-calendar' ),
										venue.name
									) }
								>
									{ __( 'Delete', 'rayetun-events-calendar' ) }
								</Button>
							</div>
						</li>
					) ) }
				</ul>
			) }

			{ editing && (
				<VenueForm
					venue={ editing }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</>
	);
}
