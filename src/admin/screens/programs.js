import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';

import { api, useApp } from '../lib';
import {
	useLoad,
	PageHeader,
	Loading,
	ErrorNote,
	Empty,
} from '../components/ui';

const blank = { id: 0, name: '', description: '' };

function ProgramForm( { program, onClose } ) {
	const { notify, refresh } = useApp();
	const [ form, setForm ] = useState( program );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		api.post( form.id ? `/programs/${ form.id }` : '/programs', {
			name: form.name,
			description: form.description,
		} )
			.then( () => {
				notify(
					sprintf(
						/* translators: %s: program name. */
						__( '“%s” saved.', 'rayetun-events-calendar' ),
						form.name
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
					? __( 'Edit program', 'rayetun-events-calendar' )
					: __( 'Add program', 'rayetun-events-calendar' )
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
						'For example: Summer Festival 2027, or Beginners’ Pottery Course.',
						'rayetun-events-calendar'
					) }
					value={ form.name }
					onChange={ ( name ) => setForm( { ...form, name } ) }
					required
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Description', 'rayetun-events-calendar' ) }
					help={ __(
						'Shown at the top of the program’s own page, if your theme shows descriptions there.',
						'rayetun-events-calendar'
					) }
					rows={ 3 }
					value={ form.description }
					onChange={ ( description ) =>
						setForm( { ...form, description } )
					}
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
						{ __( 'Save program', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function Programs() {
	const { notify, refresh } = useApp();
	const { data, loading, error } = useLoad( '/programs' );
	const [ editing, setEditing ] = useState( null );

	const remove = ( program ) =>
		api
			.del( `/programs/${ program.id }` )
			.then( () => {
				refresh();
				notify(
					sprintf(
						/* translators: %s: program name. */
						__(
							'“%s” deleted. Its events are still there.',
							'rayetun-events-calendar'
						),
						program.name
					)
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Programs', 'rayetun-events-calendar' ) }
				subtitle={ __(
					'Group events that belong together, such as a festival, a course or a season. Each event page then links to the others.',
					'rayetun-events-calendar'
				) }
			>
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ () => setEditing( blank ) }
				>
					{ __( 'Add program', 'rayetun-events-calendar' ) }
				</Button>
			</PageHeader>

			{ error && <ErrorNote message={ error } /> }
			{ ! data && loading && <Loading /> }

			{ data && data.length === 0 && (
				<div className="rayec-panel">
					<Empty
						icon="layers"
						title={ __( 'No programs yet', 'rayetun-events-calendar' ) }
						action={
							<Button
								variant="primary"
								onClick={ () => setEditing( blank ) }
							>
								{ __( 'Add program', 'rayetun-events-calendar' ) }
							</Button>
						}
					>
						{ __(
							'Add a program, then choose it on each event that is part of it.',
							'rayetun-events-calendar'
						) }
					</Empty>
				</div>
			) }

			{ data && data.length > 0 && (
				<div className="rayec-panel rayec-table-wrap">
					<table className="rayec-table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Program', 'rayetun-events-calendar' ) }
								</th>
								<th scope="col">
									{ __( 'Events', 'rayetun-events-calendar' ) }
								</th>
								<th scope="col">
									<span className="screen-reader-text">
										{ __( 'Actions', 'rayetun-events-calendar' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ data.map( ( program ) => (
								<tr key={ program.id }>
									<th scope="row">
										<button
											type="button"
											className="rayec-table__title"
											onClick={ () => setEditing( program ) }
										>
											{ program.name }
										</button>
										{ program.description && (
											<span className="rayec-table__sub">
												{ program.description }
											</span>
										) }
									</th>
									<td>
										{ sprintf(
											/* translators: %d: number of events. */
											_n(
												'%d event',
												'%d events',
												program.count,
												'rayetun-events-calendar'
											),
											program.count
										) }
									</td>
									<td className="rayec-table__actions rayec-table__actions--wide">
										{ program.url && program.count > 0 && (
											<Button
												variant="tertiary"
												href={ program.url }
												target="_blank"
												aria-label={ sprintf(
													/* translators: %s: program name. */
													__(
														'View %s on the site (opens in a new tab)',
														'rayetun-events-calendar'
													),
													program.name
												) }
											>
												{ __(
													'View',
													'rayetun-events-calendar'
												) }
											</Button>
										) }
										<Button
											variant="tertiary"
											onClick={ () => setEditing( program ) }
											aria-label={ sprintf(
												/* translators: %s: program name. */
												__(
													'Edit %s',
													'rayetun-events-calendar'
												),
												program.name
											) }
										>
											{ __( 'Edit', 'rayetun-events-calendar' ) }
										</Button>
										<Button
											variant="tertiary"
											isDestructive
											onClick={ () => remove( program ) }
											aria-label={ sprintf(
												/* translators: %s: program name. */
												__(
													'Delete %s',
													'rayetun-events-calendar'
												),
												program.name
											) }
										>
											{ __(
												'Delete',
												'rayetun-events-calendar'
											) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ editing && (
				<ProgramForm
					program={ editing }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</>
	);
}
