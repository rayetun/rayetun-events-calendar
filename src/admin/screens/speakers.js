import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { api, useApp } from '../lib';
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
	role: '',
	bio: '',
	website: '',
	photo_id: 0,
	photo: '',
};

function SpeakerForm( { speaker, onClose } ) {
	const { notify, refresh } = useApp();
	const [ form, setForm ] = useState( speaker );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const set = ( changes ) => setForm( { ...form, ...changes } );

	const choosePhoto = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Choose a photo', 'rayetun-events-calendar' ),
			button: { text: __( 'Use this photo', 'rayetun-events-calendar' ) },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const picked = frame.state().get( 'selection' ).first().toJSON();
			const sizes = picked.sizes || {};
			setForm( ( current ) => ( {
				...current,
				photo_id: picked.id,
				photo: ( sizes.thumbnail || sizes.medium || picked ).url,
			} ) );
		} );
		frame.open();
	};

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		api.post( form.id ? `/speakers/${ form.id }` : '/speakers', form )
			.then( ( saved ) => {
				notify(
					sprintf(
						/* translators: %s: speaker name. */
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
					? __( 'Edit speaker', 'rayetun-events-calendar' )
					: __( 'Add speaker', 'rayetun-events-calendar' )
			}
			onRequestClose={ () => {
				// The media library opens on top; closing it must not close this form.
				const media = document.querySelector( '.media-modal' );
				if ( media && media.getClientRects().length > 0 ) {
					return;
				}
				onClose();
			} }
			shouldCloseOnClickOutside={ false }
			size="medium"
		>
			<form className="rayec-form" onSubmit={ save }>
				<div className="rayec-photo">
					{ form.photo ? (
						<img src={ form.photo } alt="" />
					) : (
						<span className="rayec-photo__empty" aria-hidden="true" />
					) }
					<div className="rayec-photo__actions">
						<Button variant="secondary" onClick={ choosePhoto }>
							{ form.photo
								? __( 'Replace photo', 'rayetun-events-calendar' )
								: __( 'Choose photo', 'rayetun-events-calendar' ) }
						</Button>
						{ form.photo && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => set( { photo_id: 0, photo: '' } ) }
							>
								{ __( 'Remove photo', 'rayetun-events-calendar' ) }
							</Button>
						) }
					</div>
				</div>
				<div className="rayec-form__row">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name', 'rayetun-events-calendar' ) }
						value={ form.name }
						onChange={ ( name ) => set( { name } ) }
						required
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Role or title', 'rayetun-events-calendar' ) }
						help={ __(
							'For example: Yoga teacher, or Head of Design at Acme.',
							'rayetun-events-calendar'
						) }
						value={ form.role }
						onChange={ ( role ) => set( { role } ) }
					/>
				</div>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Short bio', 'rayetun-events-calendar' ) }
					rows={ 4 }
					value={ form.bio }
					onChange={ ( bio ) => set( { bio } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="url"
					label={ __( 'Website', 'rayetun-events-calendar' ) }
					placeholder="https://"
					value={ form.website }
					onChange={ ( website ) => set( { website } ) }
				/>
				<p className="rayec-muted">
					{ __(
						'Everything here is shown publicly on the pages of the events this person speaks at.',
						'rayetun-events-calendar'
					) }
				</p>
				{ error && <ErrorNote message={ error } /> }
				<div className="rayec-form__foot">
					<Button
						variant="primary"
						type="submit"
						__next40pxDefaultSize
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save speaker', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function Speakers() {
	const { notify, refresh } = useApp();
	const { data, loading, error } = useLoad( '/speakers' );
	const [ editing, setEditing ] = useState( null );

	const remove = ( speaker ) =>
		api
			.del( `/speakers/${ speaker.id }` )
			.then( () => {
				refresh();
				notify(
					sprintf(
						/* translators: %s: speaker name. */
						__( '“%s” moved to the bin.', 'rayetun-events-calendar' ),
						speaker.name
					)
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Speakers', 'rayetun-events-calendar' ) }
				subtitle={ __(
					'The people who present, teach or perform. Add someone once and pick them on any event.',
					'rayetun-events-calendar'
				) }
			>
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ () => setEditing( blank ) }
				>
					{ __( 'Add speaker', 'rayetun-events-calendar' ) }
				</Button>
			</PageHeader>

			{ error && <ErrorNote message={ error } /> }
			{ ! data && loading && <Loading /> }

			{ data && data.length === 0 && (
				<div className="rayec-panel">
					<Empty
						icon="mic"
						title={ __( 'No speakers yet', 'rayetun-events-calendar' ) }
						action={
							<Button
								variant="primary"
								onClick={ () => setEditing( blank ) }
							>
								{ __( 'Add speaker', 'rayetun-events-calendar' ) }
							</Button>
						}
					>
						{ __(
							'Speakers appear on the event page with their photo and bio, and the event form warns you if someone is booked twice at the same time.',
							'rayetun-events-calendar'
						) }
					</Empty>
				</div>
			) }

			{ data && data.length > 0 && (
				<ul className="rayec-grid">
					{ data.map( ( speaker ) => (
						<li key={ speaker.id } className="rayec-card">
							<div className="rayec-card__person">
								{ speaker.photo ? (
									<img src={ speaker.photo } alt="" />
								) : (
									<span
										className="rayec-photo__empty"
										aria-hidden="true"
									/>
								) }
								<div>
									<h2 className="rayec-card__title">
										{ speaker.name }
									</h2>
									<p className="rayec-card__desc">
										{ speaker.role ||
											__(
												'No role yet',
												'rayetun-events-calendar'
											) }
									</p>
								</div>
							</div>
							<div className="rayec-card__foot">
								<Button
									variant="secondary"
									onClick={ () => setEditing( speaker ) }
									aria-label={ sprintf(
										/* translators: %s: speaker name. */
										__( 'Edit %s', 'rayetun-events-calendar' ),
										speaker.name
									) }
								>
									{ __( 'Edit', 'rayetun-events-calendar' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => remove( speaker ) }
									aria-label={ sprintf(
										/* translators: %s: speaker name. */
										__( 'Delete %s', 'rayetun-events-calendar' ),
										speaker.name
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
				<SpeakerForm
					speaker={ editing }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</>
	);
}
