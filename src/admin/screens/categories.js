import { useState } from '@wordpress/element';
import { Button, Modal, TextControl } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';

import { api, config, useApp } from '../lib';
import { Icon } from '../icons';
import {
	useLoad,
	PageHeader,
	Loading,
	ErrorNote,
	Empty,
} from '../components/ui';

// Every swatch here passes 4.5:1 against white, so white text on it is readable.
const SWATCHES = [
	'#0F766E',
	'#B45309',
	'#4338CA',
	'#BE123C',
	'#1D4ED8',
	'#7E22CE',
	'#15803D',
	'#A16207',
	'#475569',
	'#C2410C',
];

const ICONS = config.categoryIcons || {};

/** One of the bundled category icons. Decorative: always paired with a name. */
function CatIcon( { name, size = 18 } ) {
	if ( ! ICONS[ name ] ) {
		return null;
	}
	return (
		<svg
			className="rayec-icon"
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			<path d={ ICONS[ name ].path } />
		</svg>
	);
}

const luminance = ( hex ) => {
	const value = hex.replace( '#', '' );
	if ( ! /^[0-9a-f]{6}$/i.test( value ) ) {
		return null;
	}
	const [ r, g, b ] = [ 0, 2, 4 ].map( ( i ) => {
		const channel = parseInt( value.slice( i, i + 2 ), 16 ) / 255;
		return channel <= 0.03928
			? channel / 12.92
			: Math.pow( ( channel + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

/** Contrast of a colour against white. */
export const contrastOnWhite = ( hex ) => {
	const value = luminance( hex || '' );
	return value === null ? null : 1.05 / ( value + 0.05 );
};

function ContrastNote( { color } ) {
	const ratio = contrastOnWhite( color );
	if ( ratio === null ) {
		return (
			<p className="rayec-muted">
				{ __(
					'Enter a colour as six hex digits, for example #0F766E.',
					'rayetun-events-calendar'
				) }
			</p>
		);
	}
	const rounded = ratio.toFixed( 2 );
	let tone = 'poor';
	let text = sprintf(
		/* translators: %s: contrast ratio. */
		__(
			'%s:1 against white. Too pale: people with low vision may not see it. Choose a darker colour.',
			'rayetun-events-calendar'
		),
		rounded
	);
	if ( ratio >= 4.5 ) {
		tone = 'good';
		text = sprintf(
			/* translators: %s: contrast ratio. */
			__(
				'%s:1 against white. Clear for text and markers.',
				'rayetun-events-calendar'
			),
			rounded
		);
	} else if ( ratio >= 3 ) {
		tone = 'fair';
		text = sprintf(
			/* translators: %s: contrast ratio. */
			__(
				'%s:1 against white. Fine for markers, but a little darker would be easier to read.',
				'rayetun-events-calendar'
			),
			rounded
		);
	}
	return (
		<p className={ `rayec-contrast is-${ tone }` } role="status">
			<Icon name={ tone === 'poor' ? 'alert' : 'check' } size={ 15 } />
			{ text }
		</p>
	);
}

function CategoryForm( { category, onClose } ) {
	const { notify, refresh } = useApp();
	const [ form, setForm ] = useState( category );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		api.post( form.id ? `/categories/${ form.id }` : '/categories', {
			name: form.name,
			color: form.color,
			icon: form.icon || '',
		} )
			.then( () => {
				notify(
					sprintf(
						/* translators: %s: category name. */
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
					? __( 'Edit category', 'rayetun-events-calendar' )
					: __( 'Add category', 'rayetun-events-calendar' )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<form className="rayec-form" onSubmit={ save }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'rayetun-events-calendar' ) }
					value={ form.name }
					onChange={ ( name ) => setForm( { ...form, name } ) }
					required
				/>
				<fieldset className="rayec-swatches">
					<legend>
						{ __( 'Colour', 'rayetun-events-calendar' ) }
					</legend>
					{ SWATCHES.map( ( swatch ) => (
						<label
							key={ swatch }
							style={ { background: swatch } }
							className={
								form.color.toUpperCase() === swatch
									? 'is-on'
									: ''
							}
						>
							<input
								type="radio"
								name="rayec-swatch"
								checked={ form.color.toUpperCase() === swatch }
								onChange={ () =>
									setForm( { ...form, color: swatch } )
								}
							/>
							<span className="screen-reader-text">
								{ swatch }
							</span>
						</label>
					) ) }
				</fieldset>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Or your own colour (hex)',
						'rayetun-events-calendar'
					) }
					value={ form.color }
					onChange={ ( color ) => setForm( { ...form, color } ) }
					placeholder="#0F766E"
				/>
				<ContrastNote color={ form.color } />
				<fieldset className="rayec-iconpick">
					<legend>{ __( 'Icon', 'rayetun-events-calendar' ) }</legend>
					<label className={ ! form.icon ? 'is-on' : '' }>
						<input
							type="radio"
							name="rayec-icon"
							checked={ ! form.icon }
							onChange={ () => setForm( { ...form, icon: '' } ) }
						/>
						<span>{ __( 'None', 'rayetun-events-calendar' ) }</span>
					</label>
					{ Object.entries( ICONS ).map( ( [ key, icon ] ) => (
						<label
							key={ key }
							className={ form.icon === key ? 'is-on' : '' }
						>
							<input
								type="radio"
								name="rayec-icon"
								checked={ form.icon === key }
								onChange={ () =>
									setForm( { ...form, icon: key } )
								}
							/>
							<CatIcon name={ key } />
							<span>{ icon.label }</span>
						</label>
					) ) }
					<p className="rayec-muted">
						{ __(
							'Shown beside the category name on your site. The name is always shown too, so nobody has to recognise a picture.',
							'rayetun-events-calendar'
						) }
					</p>
				</fieldset>
				{ error && <ErrorNote message={ error } /> }
				<div className="rayec-form__foot">
					<Button
						variant="primary"
						type="submit"
						__next40pxDefaultSize
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save category', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function Categories() {
	const { notify, refresh } = useApp();
	const { data, loading, error } = useLoad( '/categories' );
	const [ editing, setEditing ] = useState( null );
	const blank = { id: 0, name: '', color: SWATCHES[ 0 ], icon: '' };

	const remove = ( category ) =>
		api
			.del( `/categories/${ category.id }` )
			.then( () => {
				refresh();
				notify(
					sprintf(
						/* translators: %s: category name. */
						__( '“%s” deleted.', 'rayetun-events-calendar' ),
						category.name
					)
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Categories', 'rayetun-events-calendar' ) }
				subtitle={ __(
					'Group events and give each group a colour. Every colour is checked for contrast as you choose it.',
					'rayetun-events-calendar'
				) }
			>
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ () => setEditing( blank ) }
				>
					{ __( 'Add category', 'rayetun-events-calendar' ) }
				</Button>
			</PageHeader>

			{ error && <ErrorNote message={ error } /> }
			{ ! data && loading && <Loading /> }

			{ data && data.length === 0 && (
				<div className="rayec-panel">
					<Empty
						icon="tag"
						title={ __(
							'No categories yet',
							'rayetun-events-calendar'
						) }
						action={
							<Button
								variant="primary"
								onClick={ () => setEditing( blank ) }
							>
								{ __(
									'Add category',
									'rayetun-events-calendar'
								) }
							</Button>
						}
					>
						{ __(
							'Categories let visitors filter the calendar, and their colours make a busy month easier to scan.',
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
									{ __( 'Category', 'rayetun-events-calendar' ) }
								</th>
								<th scope="col">
									{ __( 'Events', 'rayetun-events-calendar' ) }
								</th>
								<th scope="col">
									{ __(
										'Colour contrast',
										'rayetun-events-calendar'
									) }
								</th>
								<th scope="col">
									<span className="screen-reader-text">
										{ __(
											'Actions',
											'rayetun-events-calendar'
										) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ data.map( ( category ) => {
								const ratio = category.contrast;
								let tone = 'poor';
								if ( ratio >= 4.5 ) {
									tone = 'good';
								} else if ( ratio >= 3 ) {
									tone = 'fair';
								}
								return (
									<tr key={ category.id }>
										<th scope="row">
											<span
												className="rayec-swatch"
												style={ {
													background:
														category.color ||
														'transparent',
												} }
												aria-hidden="true"
											/>
											<button
												type="button"
												className="rayec-table__title"
												onClick={ () =>
													setEditing( category )
												}
											>
												{ category.name }
											</button>{ ' ' }
											<CatIcon name={ category.icon } size={ 15 } />
										</th>
										<td>
											{ sprintf(
												/* translators: %d: number of events. */
												_n(
													'%d event',
													'%d events',
													category.count,
													'rayetun-events-calendar'
												),
												category.count
											) }
										</td>
										<td>
											{ ratio ? (
												<span
													className={ `rayec-pill is-${ tone }` }
												>
													{ `${ ratio }:1` }
													{ tone === 'poor' &&
														` · ${ __(
															'too pale',
															'rayetun-events-calendar'
														) }` }
												</span>
											) : (
												<span className="rayec-muted">
													{ __(
														'No colour',
														'rayetun-events-calendar'
													) }
												</span>
											) }
										</td>
										<td className="rayec-table__actions rayec-table__actions--wide">
											<Button
												variant="tertiary"
												onClick={ () =>
													setEditing( category )
												}
												aria-label={ sprintf(
													/* translators: %s: category name. */
													__(
														'Edit %s',
														'rayetun-events-calendar'
													),
													category.name
												) }
											>
												{ __(
													'Edit',
													'rayetun-events-calendar'
												) }
											</Button>
											<Button
												variant="tertiary"
												isDestructive
												onClick={ () =>
													remove( category )
												}
												aria-label={ sprintf(
													/* translators: %s: category name. */
													__(
														'Delete %s',
														'rayetun-events-calendar'
													),
													category.name
												) }
											>
												{ __(
													'Delete',
													'rayetun-events-calendar'
												) }
											</Button>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			{ editing && (
				<CategoryForm
					category={ editing }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</>
	);
}
