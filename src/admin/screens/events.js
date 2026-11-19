import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	SearchControl,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Modal,
	TextControl,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';

import { api, useApp } from '../lib';
import { Icon } from '../icons';
import {
	useLoad,
	PageHeader,
	Loading,
	ErrorNote,
	Empty,
	StatusBadges,
	bookedLabel,
} from '../components/ui';

const TABS = [
	{ key: 'upcoming', label: __( 'Upcoming', 'rayetun-events-calendar' ) },
	{ key: 'past', label: __( 'Past', 'rayetun-events-calendar' ) },
	{ key: 'draft', label: __( 'Drafts', 'rayetun-events-calendar' ) },
	{ key: 'all', label: __( 'All', 'rayetun-events-calendar' ) },
];

function where( event ) {
	if ( event.format === 'online' ) {
		return __( 'Online', 'rayetun-events-calendar' );
	}
	if ( ! event.venue ) {
		return '—';
	}
	return event.room
		? `${ event.venue.name } · ${ event.room }`
		: event.venue.name;
}

/** Columns that can be hidden. The event name and the row menu always show. */
const COLUMNS = [
	{ key: 'when', label: __( 'Date', 'rayetun-events-calendar' ) },
	{ key: 'where', label: __( 'Where', 'rayetun-events-calendar' ) },
	{ key: 'organizer', label: __( 'Organizer', 'rayetun-events-calendar' ) },
	{ key: 'categories', label: __( 'Categories', 'rayetun-events-calendar' ) },
	{ key: 'cost', label: __( 'Cost', 'rayetun-events-calendar' ) },
	{ key: 'booked', label: __( 'Booked', 'rayetun-events-calendar' ) },
	{
		key: 'access',
		label: __( 'Accessibility', 'rayetun-events-calendar' ),
	},
];
/**
 * The built-in columns, then any an add-on appends. An add-on column is
 * { key, label, value( event ) }, and value returns what the cell shows.
 * Add-on columns always come after the built-in ones, so the headings and
 * the cells line up.
 *
 * @return {Array<Object>} Columns.
 */
const allColumns = () => [
	...COLUMNS,
	...applyFilters( 'rayec.eventsColumns', [] ).filter(
		( column ) => column && column.key && typeof column.value === 'function'
	),
];
const DEFAULT_COLUMNS = [ 'when', 'where', 'booked', 'access' ];
const COLUMNS_KEY = 'rayecEventColumns';

const savedColumns = () => {
	try {
		const saved = JSON.parse( window.localStorage.getItem( COLUMNS_KEY ) );
		if ( Array.isArray( saved ) ) {
			return saved.filter( ( key ) =>
				allColumns().some( ( column ) => column.key === key )
			);
		}
	} catch ( e ) {
		// Nothing saved, or storage is not available.
	}
	return DEFAULT_COLUMNS;
};

const BULK = [
	{ action: 'publish', label: __( 'Publish', 'rayetun-events-calendar' ) },
	{ action: 'draft', label: __( 'Move to draft', 'rayetun-events-calendar' ) },
	{
		action: 'cancelled',
		label: __( 'Mark cancelled', 'rayetun-events-calendar' ),
	},
	{
		action: 'scheduled',
		label: __( 'Mark as going ahead', 'rayetun-events-calendar' ),
	},
];

export default function Events() {
	const { openEvent, notify, refresh } = useApp();
	const [ tab, setTab ] = useState( 'upcoming' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ selected, setSelected ] = useState( [] );
	const [ columns, setColumns ] = useState( savedColumns );
	const [ working, setWorking ] = useState( false );
	const [ views, setViews ] = useState( [] );
	// null: the "name this view" box is closed; a string: it is open.
	const [ naming, setNaming ] = useState( null );

	useEffect( () => {
		api.get( '/views' )
			.then( setViews )
			.catch( () => {} );
	}, [] );

	const storeViews = ( next, message ) =>
		api
			.post( '/views', { views: next } )
			.then( ( saved ) => {
				setViews( saved );
				notify( message );
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );
	const shows = ( key ) => columns.includes( key );

	const toggleColumn = ( key ) => {
		const next = shows( key )
			? columns.filter( ( item ) => item !== key )
			: [ ...columns, key ];
		setColumns( next );
		try {
			window.localStorage.setItem( COLUMNS_KEY, JSON.stringify( next ) );
		} catch ( e ) {
			// The choice simply is not remembered.
		}
	};

	useEffect( () => {
		const timer = setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ search ] );

	const params = new URLSearchParams( { page, per_page: 20 } );
	if ( query ) {
		params.set( 'search', query );
	}
	if ( tab === 'draft' ) {
		params.set( 'status', 'draft' );
	} else if ( tab !== 'all' ) {
		params.set( 'when', tab );
	}
	const { data, loading, error } = useLoad( `/events?${ params }` );

	const applyView = ( view ) => {
		const next = view.columns.filter( ( key ) =>
			allColumns().some( ( column ) => column.key === key )
		);
		setTab( view.tab );
		setSearch( view.search );
		setQuery( view.search );
		setPage( 1 );
		setColumns( next );
		try {
			window.localStorage.setItem( COLUMNS_KEY, JSON.stringify( next ) );
		} catch ( e ) {
			// The columns still apply for this visit.
		}
	};

	// A selection only ever covers rows that are on screen.
	const pageIds = data ? data.items.map( ( event ) => event.id ) : [];
	const pageKey = pageIds.join( ',' );
	useEffect( () => {
		setSelected( ( current ) =>
			current.filter( ( id ) => pageIds.includes( id ) )
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ pageKey ] );
	const allSelected =
		pageIds.length > 0 && selected.length === pageIds.length;

	const bulk = ( action ) => {
		setWorking( true );
		api.post( '/events/bulk', { ids: selected, action } )
			.then( ( result ) => {
				const count = result.done.length;
				setSelected( [] );
				refresh();
				let message =
					action === 'trash'
						? sprintf(
								/* translators: %d: number of events. */
								_n(
									'%d event moved to the bin.',
									'%d events moved to the bin.',
									count,
									'rayetun-events-calendar'
								),
								count
						  )
						: sprintf(
								/* translators: %d: number of events. */
								_n(
									'%d event updated.',
									'%d events updated.',
									count,
									'rayetun-events-calendar'
								),
								count
						  );
				if ( result.skipped ) {
					message +=
						' ' +
						sprintf(
							/* translators: %d: number of events. */
							_n(
								'%d was left alone because you are not allowed to change it.',
								'%d were left alone because you are not allowed to change them.',
								result.skipped,
								'rayetun-events-calendar'
							),
							result.skipped
						);
				}
				notify(
					message,
					action === 'trash' && count
						? {
								undo: () =>
									api
										.post( '/events/bulk', {
											ids: result.done,
											action: 'restore',
										} )
										.then( refresh ),
						  }
						: {}
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) )
			.finally( () => setWorking( false ) );
	};

	// An add-on's bulk action does its own work and says what it did.
	const runAddonBulk = ( item ) => {
		setWorking( true );
		Promise.resolve( item.run( selected ) )
			.then( ( message ) => {
				setSelected( [] );
				refresh();
				if ( message ) {
					notify( message );
				}
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) )
			.finally( () => setWorking( false ) );
	};

	const remove = ( event ) =>
		api
			.del( `/events/${ event.id }` )
			.then( () => {
				refresh();
				notify(
					sprintf(
						/* translators: %s: event title. */
						__(
							'“%s” moved to the bin.',
							'rayetun-events-calendar'
						),
						event.title
					),
					{
						undo: () =>
							api
								.post( `/events/${ event.id }/restore` )
								.then( refresh ),
					}
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	const duplicate = ( event ) =>
		api
			.post( `/events/${ event.id }/duplicate` )
			.then( () => {
				refresh();
				notify(
					__( 'Copy saved as a draft.', 'rayetun-events-calendar' )
				);
			} )
			.catch( ( e ) => notify( e.message, { error: true } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Events', 'rayetun-events-calendar' ) }
				subtitle={
					data
						? sprintf(
								/* translators: %d: number of events. */
								_n(
									'%d event',
									'%d events',
									data.total,
									'rayetun-events-calendar'
								),
								data.total
						  )
						: ''
				}
			/>

			<div className="rayec-toolbar">
				<div
					className="rayec-tabs"
					role="group"
					aria-label={ __(
						'Filter events',
						'rayetun-events-calendar'
					) }
				>
					{ TABS.map( ( item ) => (
						<button
							key={ item.key }
							type="button"
							className={ item.key === tab ? 'is-active' : '' }
							aria-pressed={ item.key === tab }
							onClick={ () => {
								setTab( item.key );
								setPage( 1 );
							} }
						>
							{ item.label }
						</button>
					) ) }
				</div>
				<SearchControl
					__nextHasNoMarginBottom
					className="rayec-search"
					label={ __( 'Search events', 'rayetun-events-calendar' ) }
					placeholder={ __(
						'Search events',
						'rayetun-events-calendar'
					) }
					value={ search }
					onChange={ setSearch }
				/>
				<DropdownMenu
					className="rayec-columns"
					icon={ <Icon name="list" size={ 16 } /> }
					text={ __( 'Views', 'rayetun-events-calendar' ) }
					label={ __( 'Saved views', 'rayetun-events-calendar' ) }
					toggleProps={ {
						variant: 'secondary',
						__next40pxDefaultSize: true,
					} }
				>
					{ ( { onClose } ) => (
						<>
							<MenuGroup
								label={ __(
									'Your saved views',
									'rayetun-events-calendar'
								) }
							>
								{ views.length === 0 && (
									<MenuItem disabled>
										{ __(
											'None yet',
											'rayetun-events-calendar'
										) }
									</MenuItem>
								) }
								{ views.map( ( view ) => (
									<MenuItem
										key={ view.name }
										onClick={ () => {
											applyView( view );
											onClose();
										} }
									>
										{ view.name }
									</MenuItem>
								) ) }
							</MenuGroup>
							<MenuGroup>
								<MenuItem
									onClick={ () => {
										setNaming( '' );
										onClose();
									} }
								>
									{ __(
										'Save this view…',
										'rayetun-events-calendar'
									) }
								</MenuItem>
								{ views.map( ( view ) => (
									<MenuItem
										key={ view.name }
										isDestructive
										onClick={ () => {
											storeViews(
												views.filter(
													( item ) =>
														item.name !== view.name
												),
												sprintf(
													/* translators: %s: view name. */
													__(
														'View “%s” removed.',
														'rayetun-events-calendar'
													),
													view.name
												)
											);
											onClose();
										} }
									>
										{ sprintf(
											/* translators: %s: view name. */
											__(
												'Remove “%s”',
												'rayetun-events-calendar'
											),
											view.name
										) }
									</MenuItem>
								) ) }
							</MenuGroup>
						</>
					) }
				</DropdownMenu>
				<DropdownMenu
					className="rayec-columns"
					icon={ <Icon name="settings" size={ 16 } /> }
					text={ __( 'Columns', 'rayetun-events-calendar' ) }
					label={ __( 'Choose columns', 'rayetun-events-calendar' ) }
					toggleProps={ {
						variant: 'secondary',
						__next40pxDefaultSize: true,
					} }
				>
					{ () => (
						<MenuGroup
							label={ __(
								'Show these columns',
								'rayetun-events-calendar'
							) }
						>
							{ allColumns().map( ( column ) => (
								<MenuItem
									key={ column.key }
									role="menuitemcheckbox"
									isSelected={ shows( column.key ) }
									icon={
										shows( column.key ) ? (
											<Icon name="check" size={ 16 } />
										) : null
									}
									onClick={ () => toggleColumn( column.key ) }
								>
									{ column.label }
								</MenuItem>
							) ) }
						</MenuGroup>
					) }
				</DropdownMenu>
			</div>

			{ naming !== null && (
				<Modal
					title={ __( 'Save this view', 'rayetun-events-calendar' ) }
					onRequestClose={ () => setNaming( null ) }
					size="small"
				>
					<form
						className="rayec-form"
						onSubmit={ ( event ) => {
							event.preventDefault();
							const name = naming.trim();
							if ( ! name ) {
								return;
							}
							storeViews(
								[
									...views.filter(
										( view ) => view.name !== name
									),
									{ name, tab, search: query, columns },
								],
								sprintf(
									/* translators: %s: view name. */
									__(
										'View “%s” saved.',
										'rayetun-events-calendar'
									),
									name
								)
							);
							setNaming( null );
						} }
					>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Name', 'rayetun-events-calendar' ) }
							help={ __(
								'Remembers the tab, the search and the columns you have now. Only you see your saved views.',
								'rayetun-events-calendar'
							) }
							value={ naming }
							onChange={ setNaming }
							required
						/>
						<div className="rayec-form__foot">
							<Button
								variant="primary"
								type="submit"
								__next40pxDefaultSize
							>
								{ __( 'Save view', 'rayetun-events-calendar' ) }
							</Button>
						</div>
					</form>
				</Modal>
			) }

			{ selected.length > 0 && (
				<div
					className="rayec-bulk"
					role="region"
					aria-label={ __(
						'Actions for the selected events',
						'rayetun-events-calendar'
					) }
				>
					<strong className="rayec-bulk__count" aria-live="polite">
						{ sprintf(
							/* translators: %d: number of events. */
							_n(
								'%d selected',
								'%d selected',
								selected.length,
								'rayetun-events-calendar'
							),
							selected.length
						) }
					</strong>
					{ applyFilters( 'rayec.eventsBulkActions', BULK ).map( ( item ) => (
						<Button
							key={ item.action }
							variant="secondary"
							disabled={ working }
							onClick={ () =>
								item.run ? runAddonBulk( item ) : bulk( item.action )
							}
						>
							{ item.label }
						</Button>
					) ) }
					<Button
						variant="secondary"
						isDestructive
						disabled={ working }
						onClick={ () => bulk( 'trash' ) }
					>
						{ __( 'Move to bin', 'rayetun-events-calendar' ) }
					</Button>
					<Button
						variant="tertiary"
						className="rayec-bulk__clear"
						onClick={ () => setSelected( [] ) }
					>
						{ __( 'Clear selection', 'rayetun-events-calendar' ) }
					</Button>
				</div>
			) }

			{ error && <ErrorNote message={ error } /> }
			{ ! data && loading && <Loading /> }

			{ data && data.items.length === 0 && (
				<div className="rayec-panel">
					<Empty
						icon="list"
						title={
							query
								? __(
										'No events match that search',
										'rayetun-events-calendar'
								  )
								: __(
										'No events here yet',
										'rayetun-events-calendar'
								  )
						}
						action={
							<Button
								variant="primary"
								onClick={ () => openEvent( {} ) }
							>
								{ __( 'New event', 'rayetun-events-calendar' ) }
							</Button>
						}
					>
						{ __(
							'Every event you create appears in this list, whether it happens once or repeats for years.',
							'rayetun-events-calendar'
						) }
					</Empty>
				</div>
			) }

			{ data && data.items.length > 0 && (
				<div
					className={ `rayec-panel rayec-table-wrap ${
						loading ? 'is-loading' : ''
					}` }
				>
					<table className="rayec-table">
						<thead>
							<tr>
								<td className="rayec-table__check">
									<input
										type="checkbox"
										className="rayec-check"
										checked={ allSelected }
										ref={ ( box ) => {
											if ( box ) {
												box.indeterminate =
													selected.length > 0 &&
													! allSelected;
											}
										} }
										onChange={ () =>
											setSelected(
												allSelected ? [] : pageIds
											)
										}
										aria-label={ __(
											'Select every event on this page',
											'rayetun-events-calendar'
										) }
									/>
								</td>
								<th scope="col">
									{ __( 'Event', 'rayetun-events-calendar' ) }
								</th>
								{ shows( 'when' ) && (
								<th scope="col">
									{ tab === 'past'
										? __(
												'Last date',
												'rayetun-events-calendar'
										  )
										: __(
												'Next date',
												'rayetun-events-calendar'
										  ) }
								</th>
								) }
								{ allColumns().filter(
									( column ) =>
										column.key !== 'when' && shows( column.key )
								).map( ( column ) => (
									<th key={ column.key } scope="col">
										{ column.label }
									</th>
								) ) }
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
							{ data.items.map( ( event ) => (
								<tr
									key={ event.id }
									className={
										selected.includes( event.id )
											? 'is-selected'
											: ''
									}
								>
									<td className="rayec-table__check">
										<input
											type="checkbox"
											className="rayec-check"
											checked={ selected.includes(
												event.id
											) }
											onChange={ () =>
												setSelected(
													selected.includes( event.id )
														? selected.filter(
																( id ) =>
																	id !== event.id
														  )
														: [ ...selected, event.id ]
												)
											}
											aria-label={ sprintf(
												/* translators: %s: event title. */
												__(
													'Select %s',
													'rayetun-events-calendar'
												),
												event.title
											) }
										/>
									</td>
									<th scope="row">
										<button
											type="button"
											className="rayec-table__title"
											onClick={ () =>
												openEvent( event.id )
											}
										>
											{ event.title }
										</button>{ ' ' }
										<StatusBadges
											eventStatus={ event.event_status }
											postStatus={ event.post_status }
										/>
										{ event.rrule_text && (
											<span className="rayec-table__sub">
												<Icon
													name="repeat"
													size={ 12 }
												/>
												{ event.rrule_text }
											</span>
										) }
									</th>
									{ shows( 'when' ) && (
										<td>
											{ event.next
												? event.next.when_label
												: '—' }
										</td>
									) }
									{ shows( 'where' ) && (
										<td>{ where( event ) }</td>
									) }
									{ shows( 'organizer' ) && (
										<td>
											{ event.organizer
												? event.organizer.name
												: '—' }
										</td>
									) }
									{ shows( 'categories' ) && (
										<td>
											{ event.categories
												.map( ( category ) => category.name )
												.join( ', ' ) || '—' }
										</td>
									) }
									{ shows( 'cost' ) && (
										<td>{ event.cost || '—' }</td>
									) }
									{ shows( 'booked' ) && (
										<td>
											{ bookedLabel(
												event.next?.booked,
												event.next?.capacity
											) || '—' }
										</td>
									) }
									{ shows( 'access' ) && (
									<td>
										<span
											className={ `rayec-pill is-${
												event.guard.score >= 90
													? 'good'
													: event.guard.score >= 70
													? 'fair'
													: 'poor'
											}` }
										>
											{ event.guard.issues.length === 0
												? __(
														'No issues',
														'rayetun-events-calendar'
												  )
												: sprintf(
														/* translators: %d: number of issues. */
														_n(
															'%d issue',
															'%d issues',
															event.guard.issues
																.length,
															'rayetun-events-calendar'
														),
														event.guard.issues
															.length
												  ) }
										</span>
									</td>
									) }
									{ allColumns()
										.filter(
											( column ) =>
												column.value && shows( column.key )
										)
										.map( ( column ) => (
											<td key={ column.key }>
												{ column.value( event ) }
											</td>
										) ) }
									<td className="rayec-table__actions">
										<DropdownMenu
											icon={ <Icon name="more" /> }
											label={ sprintf(
												/* translators: %s: event title. */
												__(
													'Actions for %s',
													'rayetun-events-calendar'
												),
												event.title
											) }
										>
											{ ( { onClose } ) => (
												<MenuGroup>
													<MenuItem
														onClick={ () => {
															openEvent(
																event.id
															);
															onClose();
														} }
													>
														{ __(
															'Edit',
															'rayetun-events-calendar'
														) }
													</MenuItem>
													<MenuItem
														href={ event.edit_link }
													>
														{ __(
															'Open in block editor',
															'rayetun-events-calendar'
														) }
													</MenuItem>
													<MenuItem
														href={ event.permalink }
														target="_blank"
													>
														{ __(
															'View on site',
															'rayetun-events-calendar'
														) }
													</MenuItem>
													<MenuItem
														onClick={ () => {
															duplicate( event );
															onClose();
														} }
													>
														{ __(
															'Duplicate',
															'rayetun-events-calendar'
														) }
													</MenuItem>
													<MenuItem
														isDestructive
														onClick={ () => {
															remove( event );
															onClose();
														} }
													>
														{ __(
															'Move to bin',
															'rayetun-events-calendar'
														) }
													</MenuItem>
												</MenuGroup>
											) }
										</DropdownMenu>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ data && data.pages > 1 && (
				<nav
					className="rayec-pager"
					aria-label={ __(
						'Events pages',
						'rayetun-events-calendar'
					) }
				>
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'rayetun-events-calendar' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'rayetun-events-calendar' ),
							page,
							data.pages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= data.pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'rayetun-events-calendar' ) }
					</Button>
				</nav>
			) }
		</>
	);
}
