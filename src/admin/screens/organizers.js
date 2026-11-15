import { useState } from '@wordpress/element';
import { Button, Modal, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { api, useApp } from '../lib';
import {
	useLoad,
	PageHeader,
	Loading,
	ErrorNote,
	Empty,
} from '../components/ui';

const blank = { id: 0, name: '', email: '', phone: '', website: '' };

function OrganizerForm( { organizer, onClose } ) {
	const { notify, refresh } = useApp();
	const [ form, setForm ] = useState( organizer );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const set = ( changes ) => setForm( { ...form, ...changes } );

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		api.post( form.id ? `/organizers/${ form.id }` : '/organizers', form )
			.then( ( saved ) => {
				notify(
					sprintf(
						/* translators: %s: organizer name. */
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
					? __( 'Edit organizer', 'rayetun-events-calendar' )
					: __( 'Add organizer', 'rayetun-events-calendar' )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<form className="rayec-form" onSubmit={ save }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'rayetun-events-calendar' ) }
					help={ __(
						'A person, a team or an organisation.',
						'rayetun-events-calendar'
					) }
					value={ form.name }
					onChange={ ( name ) => set( { name } ) }
					required
				/>
				<div className="rayec-form__row">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="email"
						label={ __( 'Email', 'rayetun-events-calendar' ) }
						value={ form.email }
						onChange={ ( email ) => set( { email } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="tel"
						label={ __( 'Phone', 'rayetun-events-calendar' ) }
						value={ form.phone }
						onChange={ ( phone ) => set( { phone } ) }
					/>
				</div>
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
						'Anything you fill in here is shown publicly on the event page.',
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
						{ __( 'Save organizer', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function Organizers() {
	const { notify, refresh } = useApp();
	const { data, loading, error } = useLoad( '/organizers' );
	const [ editing, setEditing ] = useState( null );

	const remove = ( organizer ) =>
		api
			.del( `/organizers/${ organizer.id }` )
			.then( () => {
				refresh();
				notify(
					sprintf(
						/* translators: %s: organizer name. */
						__(
							'“%s” moved to the bin.',
							'rayetun-events-calendar'
						),
						organizer.name
					)
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Organizers', 'rayetun-events-calendar' ) }
				subtitle={ __(
					'The people and groups who run your events, with contact details visitors can use.',
					'rayetun-events-calendar'
				) }
			>
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ () => setEditing( blank ) }
				>
					{ __( 'Add organizer', 'rayetun-events-calendar' ) }
				</Button>
			</PageHeader>

			{ error && <ErrorNote message={ error } /> }
			{ ! data && loading && <Loading /> }

			{ data && data.length === 0 && (
				<div className="rayec-panel">
					<Empty
						icon="users"
						title={ __(
							'No organizers yet',
							'rayetun-events-calendar'
						) }
						action={
							<Button
								variant="primary"
								onClick={ () => setEditing( blank ) }
							>
								{ __(
									'Add organizer',
									'rayetun-events-calendar'
								) }
							</Button>
						}
					>
						{ __(
							'Add an organizer once and pick them on any event, so visitors always know who to contact.',
							'rayetun-events-calendar'
						) }
					</Empty>
				</div>
			) }

			{ data && data.length > 0 && (
				<ul className="rayec-grid">
					{ data.map( ( organizer ) => (
						<li key={ organizer.id } className="rayec-card">
							<h2 className="rayec-card__title">
								{ organizer.name }
							</h2>
							<p className="rayec-card__desc">
								{ [
									organizer.email,
									organizer.phone,
									organizer.website,
								]
									.filter( Boolean )
									.join( ' · ' ) ||
									__(
										'No contact details yet',
										'rayetun-events-calendar'
									) }
							</p>
							<div className="rayec-card__foot">
								<Button
									variant="secondary"
									onClick={ () => setEditing( organizer ) }
									aria-label={ sprintf(
										/* translators: %s: organizer name. */
										__(
											'Edit %s',
											'rayetun-events-calendar'
										),
										organizer.name
									) }
								>
									{ __( 'Edit', 'rayetun-events-calendar' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => remove( organizer ) }
									aria-label={ sprintf(
										/* translators: %s: organizer name. */
										__(
											'Delete %s',
											'rayetun-events-calendar'
										),
										organizer.name
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
				<OrganizerForm
					organizer={ editing }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</>
	);
}
