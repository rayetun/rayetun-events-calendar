import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	SelectControl,
	TextControl,
	ToggleControl,
	SearchControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { api, useApp, weekdayNames } from '../lib';
import {
	useLoad,
	PageHeader,
	Panel,
	Loading,
	ErrorNote,
} from '../components/ui';

export default function Settings() {
	const { notify, refresh } = useApp();
	const { data, error } = useLoad( '/settings' );
	const dashboard = useLoad( '/dashboard' );
	const [ form, setForm ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ filter, setFilter ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const holidays = useLoad( '/holidays' );
	const [ country, setCountry ] = useState( '' );
	const [ year, setYear ] = useState( new Date().getFullYear() );

	useEffect( () => {
		if ( data && ! form ) {
			setForm( data );
		}
	}, [ data, form ] );

	if ( error ) {
		return <ErrorNote message={ error } />;
	}
	if ( ! form ) {
		return <Loading />;
	}

	const set = ( changes ) => setForm( { ...form, ...changes } );
	const setClosed = ( index, changes ) =>
		set( {
			closed_dates: form.closed_dates.map( ( row, i ) =>
				i === index ? { ...row, ...changes } : row
			),
		} );
	const days = weekdayNames( 'long' );

	// Bundled rules, worked out on this site. The dates are added to the list
	// for checking; nothing is saved until the owner presses Save.
	const addHolidays = () =>
		api
			.get( `/holidays?country=${ country }&year=${ year }` )
			.then( ( result ) => {
				const have = ( form.closed_dates || [] ).map( ( row ) => row.date );
				const fresh = result.items.filter(
					( item ) => ! have.includes( item.date )
				);
				set( {
					closed_dates: [ ...( form.closed_dates || [] ), ...fresh ].sort(
						( a, b ) => ( a.date > b.date ? 1 : -1 )
					),
				} );
				notify(
					fresh.length
						? sprintf(
								/* translators: %d: number of dates. */
								__(
									'%d dates added to the list. Check them, then save.',
									'rayetun-events-calendar'
								),
								fresh.length
						  )
						: __(
								'Those dates are already in the list.',
								'rayetun-events-calendar'
						  )
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	const fields = [
		{
			key: 'default_view',
			label: __( 'Starting view', 'rayetun-events-calendar' ),
			help: __(
				'What visitors see first. They can switch views themselves.',
				'rayetun-events-calendar'
			),
			render: ( props ) => (
				<SelectControl
					{ ...props }
					value={ form.default_view }
					options={ [
						{
							label: __( 'Month', 'rayetun-events-calendar' ),
							value: 'month',
						},
						{
							label: __( 'Week', 'rayetun-events-calendar' ),
							value: 'week',
						},
						{
							label: __( 'Day', 'rayetun-events-calendar' ),
							value: 'day',
						},
						{
							label: __( 'List', 'rayetun-events-calendar' ),
							value: 'list',
						},
					] }
					onChange={ ( value ) => set( { default_view: value } ) }
				/>
			),
		},
		{
			key: 'week_starts_on',
			label: __( 'Week starts on', 'rayetun-events-calendar' ),
			help: __(
				'The first column of the month grid.',
				'rayetun-events-calendar'
			),
			render: ( props ) => (
				<SelectControl
					{ ...props }
					value={ String( form.week_starts_on ) }
					options={ days.map( ( day, index ) => ( {
						label: day,
						value: String( index ),
					} ) ) }
					onChange={ ( value ) =>
						set( { week_starts_on: parseInt( value, 10 ) } )
					}
				/>
			),
		},
		{
			key: 'welcome',
			label: __( 'The setup guide', 'rayetun-events-calendar' ),
			help: __(
				'The three questions you answered when you first opened this. It is in the header at any time; this makes it open by itself again. Nothing is undone by looking at it.',
				'rayetun-events-calendar'
			),
			render: () => (
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ () =>
						api
							.post( '/onboarding', { reset: true } )
							.then( () => {
								notify(
									__(
										'The setup guide will open by itself next time.',
										'rayetun-events-calendar'
									)
								);
								refresh();
							} )
							.catch( ( e ) =>
								notify( e.message, { error: true } )
							)
					}
				>
					{ __( 'Show it again', 'rayetun-events-calendar' ) }
				</Button>
			),
		},
		{
			key: 'theme',
			label: __( 'Colours', 'rayetun-events-calendar' ),
			help: __(
				'The calendar normally takes its colours from your theme. Choose another only if that does not come out well.',
				'rayetun-events-calendar'
			),
			render: ( props ) => (
				<SelectControl
					{ ...props }
					value={ form.theme }
					options={ [
						{
							label: __(
								'Follow my theme',
								'rayetun-events-calendar'
							),
							value: 'inherit',
						},
						{
							label: __( 'Light', 'rayetun-events-calendar' ),
							value: 'light',
						},
						{
							label: __( 'Dark', 'rayetun-events-calendar' ),
							value: 'dark',
						},
						{
							label: __(
								"Follow each visitor's own setting",
								'rayetun-events-calendar'
							),
							value: 'system',
						},
						{
							label: __(
								'High contrast',
								'rayetun-events-calendar'
							),
							value: 'contrast',
						},
					] }
					onChange={ ( value ) => set( { theme: value } ) }
				/>
			),
		},
		{
			key: 'map_mode',
			label: __( 'Venue maps', 'rayetun-events-calendar' ),
			help: __(
				'A map loaded from OpenStreetMap tells it who is reading the page. Ask first, or let your own server fetch the map once and serve a picture of it.',
				'rayetun-events-calendar'
			),
			render: ( props ) => (
				<SelectControl
					{ ...props }
					value={ form.map_mode }
					options={ [
						{
							label: __(
								'Ask the visitor first',
								'rayetun-events-calendar'
							),
							value: 'ask',
						},
						{
							label: __(
								'A still picture from my own site',
								'rayetun-events-calendar'
							),
							value: 'static',
						},
						{
							label: __(
								'No map, written directions only',
								'rayetun-events-calendar'
							),
							value: 'text',
						},
					] }
					onChange={ ( value ) => set( { map_mode: value } ) }
				/>
			),
		},
		{
			key: 'list_per_page',
			label: __( 'Events per page in the list view', 'rayetun-events-calendar' ),
			help: __(
				'Between 1 and 100.',
				'rayetun-events-calendar'
			),
			render: ( props ) => (
				<TextControl
					{ ...props }
					type="number"
					min={ 1 }
					max={ 100 }
					value={ form.list_per_page }
					onChange={ ( value ) =>
						set( { list_per_page: parseInt( value, 10 ) || 10 } )
					}
				/>
			),
		},
		{
			key: 'events_slug',
			label: __( 'Event address', 'rayetun-events-calendar' ),
			help: __(
				'The word used in event links, for example yoursite.com/events/gentle-yoga.',
				'rayetun-events-calendar'
			),
			render: ( props ) => (
				<TextControl
					{ ...props }
					value={ form.events_slug }
					onChange={ ( value ) => set( { events_slug: value } ) }
				/>
			),
		},
		{
			key: 'rsvp_notify',
			label: __(
				'Email me when someone registers or cancels',
				'rayetun-events-calendar'
			),
			help: __(
				'Each email names the person, the date and any access requirements they mentioned.',
				'rayetun-events-calendar'
			),
			toggle: true,
			render: ( props ) => (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ props.label }
					help={ props.help }
					checked={ !! form.rsvp_notify }
					onChange={ ( value ) => set( { rsvp_notify: value } ) }
				/>
			),
		},
		{
			key: 'rsvp_notify_email',
			label: __( 'Send registration emails to', 'rayetun-events-calendar' ),
			help: __(
				'Leave empty to use the site’s admin email address.',
				'rayetun-events-calendar'
			),
			render: ( props ) => (
				<TextControl
					{ ...props }
					type="email"
					disabled={ ! form.rsvp_notify }
					value={ form.rsvp_notify_email || '' }
					onChange={ ( value ) =>
						set( { rsvp_notify_email: value } )
					}
				/>
			),
		},
		{
			key: 'delete_data',
			label: __(
				'Delete all events and settings when the plugin is deleted',
				'rayetun-events-calendar'
			),
			help: __(
				'Off by default, so nothing is lost if you reinstall.',
				'rayetun-events-calendar'
			),
			toggle: true,
			render: ( props ) => (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ props.label }
					help={ props.help }
					checked={ !! form.delete_data }
					onChange={ ( value ) => set( { delete_data: value } ) }
				/>
			),
		},
	];

	const needle = filter.trim().toLowerCase();
	const visible = fields.filter(
		( field ) =>
			! needle ||
			`${ field.label } ${ field.help }`.toLowerCase().includes( needle )
	);

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		api.post( '/settings', form )
			.then( ( saved ) => {
				setForm( saved );
				notify( __( 'Settings saved.', 'rayetun-events-calendar' ) );
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) )
			.finally( () => setSaving( false ) );
	};

	const samples = ( request, message ) => {
		setBusy( true );
		request
			.then( () => {
				notify( message );
				refresh();
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			<PageHeader
				title={ __( 'Settings', 'rayetun-events-calendar' ) }
				subtitle={ __(
					'Sensible defaults are already in place. Nothing here is required before your first event.',
					'rayetun-events-calendar'
				) }
			/>

			<SearchControl
				__nextHasNoMarginBottom
				className="rayec-search rayec-search--settings"
				label={ __( 'Search settings', 'rayetun-events-calendar' ) }
				placeholder={ __( 'Search settings', 'rayetun-events-calendar' ) }
				value={ filter }
				onChange={ setFilter }
			/>

			<Panel title={ __( 'Calendar', 'rayetun-events-calendar' ) }>
				<form className="rayec-form rayec-form--settings" onSubmit={ save }>
					{ visible.length === 0 && (
						<p className="rayec-muted" role="status">
							{ __(
								'No settings match that search.',
								'rayetun-events-calendar'
							) }
						</p>
					) }
					{ visible.map( ( field ) => (
						<div key={ field.key }>
							{ field.render( {
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
								label: field.label,
								help: field.help,
							} ) }
						</div>
					) ) }
					<div className="rayec-form__foot">
						<Button
							variant="primary"
							type="submit"
							__next40pxDefaultSize
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Save settings', 'rayetun-events-calendar' ) }
						</Button>
					</div>
				</form>
			</Panel>

			{ ! needle && (
				<Panel
					title={ __( 'Closed dates', 'rayetun-events-calendar' ) }
				>
					<p className="rayec-muted">
						{ __(
							'Public holidays and other days you are closed. Repeating events set to “skip closed dates” leave these days out automatically.',
							'rayetun-events-calendar'
						) }
					</p>
					{ holidays.data && (
						<div className="rayec-holidays">
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Add public holidays for',
									'rayetun-events-calendar'
								) }
								value={ country }
								options={ [
									{
										label: __(
											'Choose a country',
											'rayetun-events-calendar'
										),
										value: '',
									},
									...Object.entries(
										holidays.data.countries
									).map( ( [ value, label ] ) => ( {
										value,
										label,
									} ) ),
								] }
								onChange={ setCountry }
							/>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Year', 'rayetun-events-calendar' ) }
								value={ String( year ) }
								options={ [ 0, 1, 2 ].map( ( add ) => {
									const value = String(
										new Date().getFullYear() + add
									);
									return { value, label: value };
								} ) }
								onChange={ ( value ) =>
									setYear( parseInt( value, 10 ) )
								}
							/>
							<Button
								variant="secondary"
								__next40pxDefaultSize
								disabled={ ! country }
								onClick={ addHolidays }
							>
								{ __( 'Add to the list', 'rayetun-events-calendar' ) }
							</Button>
							<p className="rayec-muted">
								{ __(
									'Nationwide holidays only, worked out on your own site. Regional and one-off holidays are not included, so check the list and add or remove dates before saving.',
									'rayetun-events-calendar'
								) }
							</p>
						</div>
					) }
					<ul className="rayec-closed">
						{ ( form.closed_dates || [] ).map( ( row, index ) => (
							<li key={ index }>
								<div className="rayec-field">
									<label htmlFor={ `rayec-closed-${ index }` }>
										{ __( 'Date', 'rayetun-events-calendar' ) }
									</label>
									<input
										id={ `rayec-closed-${ index }` }
										className="rayec-input"
										type="date"
										value={ row.date }
										onChange={ ( event ) =>
											setClosed( index, {
												date: event.target.value,
											} )
										}
									/>
								</div>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'What is it?',
										'rayetun-events-calendar'
									) }
									value={ row.label }
									onChange={ ( label ) =>
										setClosed( index, { label } )
									}
								/>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										set( {
											closed_dates:
												form.closed_dates.filter(
													( _, i ) => i !== index
												),
										} )
									}
									aria-label={ sprintf(
										/* translators: %s: date or label. */
										__(
											'Remove closed date %s',
											'rayetun-events-calendar'
										),
										row.label || row.date
									) }
								>
									{ __( 'Remove', 'rayetun-events-calendar' ) }
								</Button>
							</li>
						) ) }
					</ul>
					<div className="rayec-form__foot">
						<Button
							variant="secondary"
							onClick={ () =>
								set( {
									closed_dates: [
										...( form.closed_dates || [] ),
										{ date: '', label: '' },
									],
								} )
							}
						>
							{ __( 'Add a date', 'rayetun-events-calendar' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ saving }
							disabled={ saving }
							onClick={ save }
						>
							{ __(
								'Save closed dates',
								'rayetun-events-calendar'
							) }
						</Button>
					</div>
				</Panel>
			) }

			{ ! needle && dashboard.data && (
				<Panel title={ __( 'Sample events', 'rayetun-events-calendar' ) }>
					<p className="rayec-muted">
						{ dashboard.data.has_samples
							? __(
									'Sample venues and events are installed. Removing them deletes only the samples, never your own events.',
									'rayetun-events-calendar'
							  )
							: __(
									'Add two venues and five events to see how everything looks. You can remove them here at any time.',
									'rayetun-events-calendar'
							  ) }
					</p>
					<Button
						variant="secondary"
						isDestructive={ dashboard.data.has_samples }
						disabled={ busy }
						onClick={ () =>
							dashboard.data.has_samples
								? samples(
										api.del( '/sample-data' ),
										__(
											'Sample events removed.',
											'rayetun-events-calendar'
										)
								  )
								: samples(
										api.post( '/sample-data' ),
										__(
											'Sample events added.',
											'rayetun-events-calendar'
										)
								  )
						}
					>
						{ dashboard.data.has_samples
							? __( 'Remove sample events', 'rayetun-events-calendar' )
							: __( 'Add sample events', 'rayetun-events-calendar' ) }
					</Button>
				</Panel>
			) }
		</>
	);
}
