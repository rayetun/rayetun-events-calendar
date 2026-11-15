import { useState, useRef } from '@wordpress/element';
import {
	Button,
	TextControl,
	TextareaControl,
	SelectControl,
	CheckboxControl,
	Notice,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';

import { api, useApp } from '../lib';
import { useLoad, PageHeader, Panel, ErrorNote } from '../components/ui';
import Migrate from './migrate';
import Feeds from './feeds';

const LABELS = {
	new: __( 'New', 'rayetun-events-calendar' ),
	update: __( 'Updates an event', 'rayetun-events-calendar' ),
	skipped: __( 'Skipped', 'rayetun-events-calendar' ),
};

/** Which column is which. Only shown for a spreadsheet. */
function Columns( { columns, mapping, fields, sample, dayFirst, onChange, busy } ) {
	return (
		<Panel title={ __( 'Which column is which', 'rayetun-events-calendar' ) }>
			<p className="rayec-import__counts">
				{ __(
					'Read from the header row. Change anything that came out wrong — the plan below follows along.',
					'rayetun-events-calendar'
				) }
			</p>

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Dates like 3/4/2026 mean', 'rayetun-events-calendar' ) }
				help={ __(
					'Only the order of day and month is ever guessed. Check the dates in the plan below before importing.',
					'rayetun-events-calendar'
				) }
				value={ dayFirst ? 'day' : 'month' }
				options={ [
					{
						label: __( '3 April 2026', 'rayetun-events-calendar' ),
						value: 'day',
					},
					{
						label: __( '4 March 2026', 'rayetun-events-calendar' ),
						value: 'month',
					},
				] }
				disabled={ busy }
				onChange={ ( value ) =>
					onChange( mapping, 'day' === value )
				}
			/>

			<table className="rayec-table rayec-import__table">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Column', 'rayetun-events-calendar' ) }
						</th>
						<th scope="col">
							{ __( 'First rows', 'rayetun-events-calendar' ) }
						</th>
						<th scope="col">
							{ __( 'Read as', 'rayetun-events-calendar' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ columns.map( ( column, index ) => (
						<tr key={ index }>
							<th scope="row">{ column || '—' }</th>
							<td className="rayec-import__sample">
								{ ( sample || [] )
									.map( ( row ) => row[ index ] )
									.filter( Boolean )
									.slice( 0, 2 )
									.join( ' · ' ) || '—' }
							</td>
							<td>
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ sprintf(
										/* translators: %s: a column name from the file. */
										__(
											'Read the column “%s” as',
											'rayetun-events-calendar'
										),
										column
									) }
									hideLabelFromVision
									value={ mapping[ index ] || '' }
									options={ Object.keys( fields ).map(
										( key ) => ( {
											label: fields[ key ],
											value: key,
										} )
									) }
									disabled={ busy }
									onChange={ ( value ) =>
										onChange(
											{ ...mapping, [ index ]: value },
											dayFirst
										)
									}
								/>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</Panel>
	);
}

/** The plan: what the import would do, before anything is written. */
function Plan( { plan, options, setOptions, categories, onImport, busy } ) {
	const { counts, rows } = plan;

	return (
		<>
			<Panel title={ __( 'What this would do', 'rayetun-events-calendar' ) }>
				<p className="rayec-import__counts">
					{ sprintf(
						/* translators: 1: number of new events, 2: number of events that would be updated, 3: number skipped. */
						__(
							'%1$d to add, %2$d to update, %3$d skipped.',
							'rayetun-events-calendar'
						),
						counts.new,
						counts.update,
						counts.skipped
					) }
				</p>

				<div className="rayec-import__options">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Add them as',
							'rayetun-events-calendar'
						) }
						help={ __(
							'Drafts let you read them over before anyone else sees them.',
							'rayetun-events-calendar'
						) }
						value={ options.post_status }
						options={ [
							{
								label: __(
									'Drafts',
									'rayetun-events-calendar'
								),
								value: 'draft',
							},
							{
								label: __(
									'Published',
									'rayetun-events-calendar'
								),
								value: 'publish',
							},
							{
								label: __(
									'Pending review',
									'rayetun-events-calendar'
								),
								value: 'pending',
							},
						] }
						onChange={ ( post_status ) =>
							setOptions( { ...options, post_status } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Put them all in',
							'rayetun-events-calendar'
						) }
						value={ String( options.category ) }
						options={ [
							{
								label: __(
									'No category',
									'rayetun-events-calendar'
								),
								value: '0',
							},
							...( categories || [] ).map( ( term ) => ( {
								label: term.name,
								value: String( term.id ),
							} ) ),
						] }
						onChange={ ( value ) =>
							setOptions( {
								...options,
								category: parseInt( value, 10 ),
							} )
						}
					/>
				</div>

				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Also use the categories named in the file',
						'rayetun-events-calendar'
					) }
					help={ __(
						'Any category the file names and this site does not have will be created.',
						'rayetun-events-calendar'
					) }
					checked={ options.use_categories }
					onChange={ ( use_categories ) =>
						setOptions( { ...options, use_categories } )
					}
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Create venues from the places in the file',
						'rayetun-events-calendar'
					) }
					help={ __(
						'The part before the first comma becomes the venue name, the rest its address. Venues you already have are matched by name.',
						'rayetun-events-calendar'
					) }
					checked={ options.create_venues }
					onChange={ ( create_venues ) =>
						setOptions( { ...options, create_venues } )
					}
				/>

				<div className="rayec-form__foot">
					<Button
						variant="primary"
						__next40pxDefaultSize
						isBusy={ busy }
						disabled={ busy || counts.new + counts.update === 0 }
						onClick={ onImport }
					>
						{ __( 'Import these events', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			</Panel>

			<Panel title={ __( 'Event by event', 'rayetun-events-calendar' ) }>
				<table className="rayec-table rayec-import__table">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Event', 'rayetun-events-calendar' ) }
							</th>
							<th scope="col">
								{ __( 'Starts', 'rayetun-events-calendar' ) }
							</th>
							<th scope="col">
								{ __( 'Place', 'rayetun-events-calendar' ) }
							</th>
							<th scope="col">
								{ __(
									'What happens',
									'rayetun-events-calendar'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, index ) => (
							<tr key={ row.uid || index }>
								<th scope="row">
									{ row.title }
									{ row.repeats && (
										<span className="rayec-badge">
											{ __(
												'Repeats',
												'rayetun-events-calendar'
											) }
										</span>
									) }
								</th>
								<td>
									{ row.all_day
										? row.start.slice( 0, 10 )
										: row.start.replace( 'T', ' ' ) }
								</td>
								<td>{ row.location || '—' }</td>
								<td>
									<span
										className={ `rayec-import__action is-${ row.action }` }
									>
										{ LABELS[ row.action ] }
									</span>
									{ row.note && (
										<span className="rayec-import__note">
											{ row.note }
										</span>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</Panel>
		</>
	);
}

export default function ImportScreen() {
	const { notify, refresh } = useApp();
	const categories = useLoad( '/categories' );
	const fileInput = useRef( null );

	const [ url, setUrl ] = useState( '' );
	const [ text, setText ] = useState( '' );
	const [ plan, setPlan ] = useState( null );
	const [ items, setItems ] = useState( [] );
	const [ source, setSource ] = useState( '' );
	const [ csv, setCsv ] = useState( null );
	const [ lastBody, setLastBody ] = useState( null );
	const [ reading, setReading ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( null );
	const [ offer, setOffer ] = useState( null );
	const [ options, setOptions ] = useState( {
		post_status: 'draft',
		category: 0,
		use_categories: false,
		create_venues: true,
	} );

	const read = ( body ) => {
		setReading( true );
		setError( '' );
		setDone( null );
		setLastBody( body );
		api.post( '/import/read', body )
			.then( ( result ) => {
				setPlan( { counts: result.counts, rows: result.rows } );
				setItems( result.items );
				setSource( result.source || '' );
				setCsv(
					'csv' === result.format
						? {
								columns: result.columns,
								mapping: result.mapping,
								fields: result.fields,
								sample: result.sample,
								dayFirst: result.day_first,
						  }
						: null
				);
			} )
			.catch( ( e ) => {
				setPlan( null );
				setError( e.message );
			} )
			.finally( () => setReading( false ) );
	};

	// Changing a column, or the date order, asks for the plan again.
	const remap = ( mapping, dayFirst ) =>
		read( { ...( lastBody || {} ), mapping, day_first: dayFirst } );

	// The file never leaves the browser until it has been read here as text.
	const chooseFile = ( event ) => {
		const file = event.target.files && event.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		const reader = new window.FileReader();
		reader.onload = () => {
			setText( String( reader.result || '' ) );
			setUrl( '' );
			read( { text: String( reader.result || '' ) } );
		};
		reader.onerror = () =>
			setError( __( 'That file could not be read.', 'rayetun-events-calendar' ) );
		reader.readAsText( file );
		event.target.value = '';
	};

	const runImport = () => {
		setBusy( true );
		setError( '' );
		api.post( '/import/run', { ...options, source, items } )
			.then( ( result ) => {
				setDone( result );
				setPlan( null );
				setItems( [] );
				setCsv( null );
				// Only an address can be read again later; a paste cannot.
				setOffer(
					source
						? {
								url: source,
								post_status: options.post_status,
								category: options.category,
								create_venues: options.create_venues,
								use_categories: options.use_categories,
						  }
						: null
				);
				notify(
					sprintf(
						/* translators: 1: number of events added, 2: number updated. */
						__(
							'%1$d added, %2$d updated.',
							'rayetun-events-calendar'
						),
						result.added,
						result.updated
					)
				);
				refresh();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="rayec-screen">
			<PageHeader
				title={ __( 'Import', 'rayetun-events-calendar' ) }
				subtitle={ __(
					'Bring in events from any calendar that publishes an iCalendar feed or file, or from a spreadsheet saved as CSV. You will see exactly what would happen before anything is written.',
					'rayetun-events-calendar'
				) }
			/>

			<Migrate />

			{ error && <ErrorNote message={ error } /> }

			{ done && (
				<Notice status="success" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: 1: number of events added, 2: number updated. */
							__(
								'Finished: %1$d added and %2$d updated.',
								'rayetun-events-calendar'
							),
							done.added,
							done.updated
						) }
					</p>
					{ done.failed && done.failed.length > 0 && (
						<ul>
							{ done.failed.map( ( row, index ) => (
								<li key={ index }>
									{ sprintf(
										/* translators: 1: event name, 2: the reason it could not be imported. */
										__(
											'%1$s — %2$s',
											'rayetun-events-calendar'
										),
										row.title,
										row.reason
									) }
								</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }

			<Panel
				title={ __(
					'Where are the events?',
					'rayetun-events-calendar'
				) }
			>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="url"
					label={ __(
						'A calendar address',
						'rayetun-events-calendar'
					) }
					help={ __(
						'The "subscribe" or .ics link from the other calendar. webcal:// addresses work too.',
						'rayetun-events-calendar'
					) }
					placeholder="https://example.org/events.ics"
					value={ url }
					onChange={ setUrl }
				/>
				<div className="rayec-form__foot">
					<Button
						variant="secondary"
						__next40pxDefaultSize
						isBusy={ reading }
						disabled={ reading || ! url.trim() }
						onClick={ () => read( { url: url.trim() } ) }
					>
						{ __( 'Read this calendar', 'rayetun-events-calendar' ) }
					</Button>
					<Button
						variant="tertiary"
						__next40pxDefaultSize
						disabled={ reading }
						onClick={ () =>
							fileInput.current && fileInput.current.click()
						}
					>
						{ __(
							'Choose a file instead',
							'rayetun-events-calendar'
						) }
					</Button>
					<input
						ref={ fileInput }
						type="file"
						accept=".ics,.csv,text/calendar,text/csv"
						className="rayec-import__file"
						tabIndex={ -1 }
						aria-hidden="true"
						onChange={ chooseFile }
					/>
				</div>

				<TextareaControl
					__nextHasNoMarginBottom
					label={ __(
						'Or paste the calendar itself',
						'rayetun-events-calendar'
					) }
					help={ __(
						'An iCalendar file, from BEGIN:VCALENDAR to END:VCALENDAR, or a spreadsheet with a header row.',
						'rayetun-events-calendar'
					) }
					rows={ 4 }
					value={ text }
					onChange={ setText }
				/>
				<div className="rayec-form__foot">
					<Button
						variant="secondary"
						__next40pxDefaultSize
						isBusy={ reading }
						disabled={ reading || ! text.trim() }
						onClick={ () => read( { text } ) }
					>
						{ __( 'Read this', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			</Panel>

			{ csv && (
				<Columns
					columns={ csv.columns }
					mapping={ csv.mapping }
					fields={ csv.fields }
					sample={ csv.sample }
					dayFirst={ csv.dayFirst }
					busy={ reading || busy }
					onChange={ remap }
				/>
			) }

			<Feeds offer={ offer } onFollowed={ () => setOffer( null ) } />

			{ plan && (
				<Plan
					plan={ plan }
					options={ options }
					setOptions={ setOptions }
					categories={ categories.data }
					busy={ busy }
					onImport={ runImport }
				/>
			) }
		</div>
	);
}
