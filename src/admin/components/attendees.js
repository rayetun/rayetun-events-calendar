import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';

import { api, useApp } from '../lib';
import { ErrorNote } from './ui';
import MessagePeople, { peopleToWrite } from './message-people';

const statusLabel = ( status ) =>
	( {
		confirmed: __( 'Confirmed', 'rayetun-events-calendar' ),
		pending: __( 'Pending', 'rayetun-events-calendar' ),
		waitlisted: __( 'Waiting list', 'rayetun-events-calendar' ),
		cancelled: __( 'Cancelled', 'rayetun-events-calendar' ),
		attended: __( 'Attended', 'rayetun-events-calendar' ),
	}[ status ] || status );

/** Columns every list has; anything an add-on adds is shown under the person. */
const CORE = [ 'name', 'email', 'places', 'access', 'status' ];

/**
 * A spreadsheet cell, quoted, and made safe to open: a value starting with
 * = + - or @ is read by Excel as a formula, so it gets an apostrophe first.
 *
 * @param {*} value Cell value.
 * @return {string} CSV cell.
 */
const cell = ( value ) => {
	let text = value === null || value === undefined ? '' : String( value );
	if ( /^[=+\-@\t\r]/.test( text ) ) {
		text = `'${ text }`;
	}
	return `"${ text.replace( /"/g, '""' ) }"`;
};

/**
 * Everyone registered for an event, by date, with a CSV download.
 *
 * A list rather than a table: the drawer is narrow, and five columns of
 * names and addresses in it wrap a letter at a time.
 *
 * @param {Object} props
 * @param {number} props.eventId Event ID.
 */
export default function Attendees( { eventId } ) {
	const { notify } = useApp();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( 0 );
	const [ showPast, setShowPast ] = useState( false );
	const [ writing, setWriting ] = useState( false );

	const load = () =>
		api
			.get( `/events/${ eventId }/registrations` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ eventId ] );

	if ( error ) {
		return <ErrorNote message={ error } />;
	}
	if ( ! data ) {
		return (
			<p className="rayec-attendees__loading">
				<Spinner />
				{ __( 'Loading registrations…', 'rayetun-events-calendar' ) }
			</p>
		);
	}

	/**
	 * What is known about each person. Add-ons add their own, such as the
	 * answer to a question; those appear under the person and in the CSV.
	 *
	 * Each is { key, label, value( registration ) }; value returns text.
	 */
	const columns = applyFilters(
		'rayec.attendeeColumns',
		[
			{
				key: 'name',
				label: __( 'Name', 'rayetun-events-calendar' ),
				value: ( row ) => row.name,
			},
			{
				key: 'email',
				label: __( 'Email', 'rayetun-events-calendar' ),
				value: ( row ) => row.email,
			},
			{
				key: 'places',
				label: __( 'Places', 'rayetun-events-calendar' ),
				value: ( row ) => row.places,
			},
			{
				key: 'access',
				label: __( 'Access requirements', 'rayetun-events-calendar' ),
				value: ( row ) => row.access,
			},
			{
				key: 'status',
				label: __( 'Status', 'rayetun-events-calendar' ),
				value: ( row ) => statusLabel( row.status ),
			},
		],
		eventId
	);
	const extra = columns.filter( ( column ) => ! CORE.includes( column.key ) );

	const change = ( row, status ) => {
		setBusy( row.id );
		api.post( `/registrations/${ row.id }`, { status } )
			.then( () => {
				notify(
					status === 'cancelled'
						? sprintf(
								/* translators: %s: person's name. */
								__(
									'%s’s place cancelled. They have been emailed.',
									'rayetun-events-calendar'
								),
								row.name
						  )
						: sprintf(
								/* translators: %s: person's name. */
								__(
									'%s’s place restored. They have been emailed.',
									'rayetun-events-calendar'
								),
								row.name
						  )
				);
				return load();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( 0 ) );
	};

	const download = () => {
		const labels = Object.fromEntries(
			data.dates.map( ( date ) => [ date.start, date.label ] )
		);
		const head = [
			__( 'Date', 'rayetun-events-calendar' ),
			...columns.map( ( column ) => column.label ),
			__( 'Registered', 'rayetun-events-calendar' ),
		];
		const lines = [ head.map( cell ).join( ',' ) ];
		data.registrations.forEach( ( row ) => {
			lines.push(
				[
					labels[ row.start ] || row.start,
					...columns.map( ( column ) => column.value( row ) ),
					row.created,
				]
					.map( cell )
					.join( ',' )
			);
		} );
		// The byte-order mark makes Excel read the file as UTF-8.
		const blob = new window.Blob( [ '﻿' + lines.join( '\r\n' ) ], {
			type: 'text/csv;charset=utf-8',
		} );
		const link = document.createElement( 'a' );
		link.href = window.URL.createObjectURL( blob );
		link.download = `${
			( data.title || 'event' )
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '-' )
				.replace( /^-|-$/g, '' ) || 'event'
		}-registrations.csv`;
		document.body.appendChild( link );
		link.click();
		link.remove();
		window.URL.revokeObjectURL( link.href );
	};

	const byDate = {};
	data.registrations.forEach( ( row ) => {
		( byDate[ row.start ] = byDate[ row.start ] || [] ).push( row );
	} );
	const past = data.dates.filter(
		( date ) => ! date.upcoming && byDate[ date.start ]
	);
	// Only dates someone has booked: a weekly series would otherwise list a
	// dozen empty dates. Places left on each date show on the event page.
	const shown = data.dates.filter(
		( date ) => byDate[ date.start ] && ( date.upcoming || showPast )
	);
	const total = data.registrations
		.filter( ( row ) => row.status !== 'cancelled' )
		.reduce( ( sum, row ) => sum + row.places, 0 );
	const reachable = peopleToWrite( data ).all;
	const messages = data.messages || [];

	return (
		<div className="rayec-attendees">
			<div className="rayec-attendees__head">
				<h4 className="rayec-attendees__title">
					{ __( 'Who is coming', 'rayetun-events-calendar' ) }
				</h4>
				<div className="rayec-attendees__tools">
					{ reachable > 0 && ! writing && (
						<Button
							variant="secondary"
							size="compact"
							onClick={ () => setWriting( true ) }
						>
							{ __( 'Email people', 'rayetun-events-calendar' ) }
						</Button>
					) }
					{ data.registrations.length > 0 && (
						<Button
							variant="secondary"
							size="compact"
							onClick={ download }
						>
							{ __( 'Download CSV', 'rayetun-events-calendar' ) }
						</Button>
					) }
				</div>
			</div>

			{ writing && (
				<MessagePeople
					eventId={ eventId }
					data={ data }
					onDone={ ( sent ) => {
						setWriting( false );
						if ( sent ) {
							load();
						}
					} }
				/>
			) }

			<p className="rayec-attendees__summary">
				{ data.registrations.length === 0
					? __(
							'Nobody has registered yet. Registrations appear here as they come in.',
							'rayetun-events-calendar'
					  )
					: sprintf(
							/* translators: %d: number of places booked. */
							_n(
								'%d place booked across all dates.',
								'%d places booked across all dates.',
								total,
								'rayetun-events-calendar'
							),
							total
					  ) }
			</p>

			{ shown.map( ( date ) => {
				const rows = byDate[ date.start ] || [];
				const full = data.capacity && date.taken >= data.capacity;
				return (
					<section
						key={ date.start }
						className="rayec-attendees__date"
						aria-label={ date.label }
					>
						<h5 className="rayec-attendees__when">
							<span>{ date.label }</span>
							<span
								className={ `rayec-badge${
									full ? ' rayec-badge--warn' : ''
								}` }
							>
								{ data.capacity
									? sprintf(
											/* translators: 1: places taken, 2: places in total. */
											__(
												'%1$d of %2$d places',
												'rayetun-events-calendar'
											),
											date.taken,
											data.capacity
									  )
									: sprintf(
											/* translators: %d: places taken. */
											_n(
												'%d place',
												'%d places',
												date.taken,
												'rayetun-events-calendar'
											),
											date.taken
									  ) }
							</span>
						</h5>
						<ul className="rayec-attendees__list">
							{ rows.map( ( row ) => {
								const cancelled = row.status === 'cancelled';
								return (
									<li
										key={ row.id }
										className={ `rayec-attendee${
											cancelled ? ' is-cancelled' : ''
										}` }
									>
										<div className="rayec-attendee__main">
											<div className="rayec-attendee__who">
												<span className="rayec-attendee__name">
													{ row.name }
												</span>
												<a
													className="rayec-attendee__email"
													href={ `mailto:${ row.email }` }
												>
													{ row.email }
												</a>
											</div>
											<div className="rayec-attendee__side">
												<span className="rayec-attendee__places">
													{ sprintf(
														/* translators: %d: number of places. */
														_n(
															'%d place',
															'%d places',
															row.places,
															'rayetun-events-calendar'
														),
														row.places
													) }
												</span>
												<span
													className={ `rayec-badge${
														cancelled
															? ' rayec-badge--muted'
															: ''
													}` }
												>
													{ statusLabel( row.status ) }
												</span>
												<Button
													variant="tertiary"
													size="compact"
													isDestructive={ ! cancelled }
													isBusy={ busy === row.id }
													disabled={ !! busy }
													onClick={ () =>
														change(
															row,
															cancelled
																? 'confirmed'
																: 'cancelled'
														)
													}
												>
													{ cancelled
														? __(
																'Restore',
																'rayetun-events-calendar'
														  )
														: __(
																'Cancel place',
																'rayetun-events-calendar'
														  ) }
													<span className="screen-reader-text">
														{ ' ' }
														{ row.name }
													</span>
												</Button>
											</div>
										</div>
										{ row.access && (
											<p className="rayec-attendee__note">
												<strong>
													{ __(
														'Access:',
														'rayetun-events-calendar'
													) }
												</strong>{ ' ' }
												{ row.access }
											</p>
										) }
										{ extra.map( ( column ) => {
											const value = column.value( row );
											return value ? (
												<p
													key={ column.key }
													className="rayec-attendee__note"
												>
													<strong>
														{ column.label }:
													</strong>{ ' ' }
													{ value }
												</p>
											) : null;
										} ) }
									</li>
								);
							} ) }
						</ul>
					</section>
				);
			} ) }

			{ messages.length > 0 && (
				<details className="rayec-attendees__sent">
					<summary>
						{ sprintf(
							/* translators: %d: number of emails written to the people booked. */
							_n(
								'%d message sent',
								'%d messages sent',
								messages.length,
								'rayetun-events-calendar'
							),
							messages.length
						) }
					</summary>
					<ul>
						{ messages.map( ( item, index ) => (
							<li key={ index }>
								<span className="rayec-attendees__sent-subject">
									{ item.subject }
								</span>
								<span className="rayec-attendees__sent-meta">
									{ sprintf(
										/* translators: 1: when it was sent, 2: which dates, 3: number of people. */
										_n(
											'%1$s · %2$s · %3$d person',
											'%1$s · %2$s · %3$d people',
											item.people,
											'rayetun-events-calendar'
										),
										item.time,
										item.to,
										item.people
									) }
								</span>
							</li>
						) ) }
					</ul>
				</details>
			) }

			{ past.length > 0 && (
				<Button
					variant="link"
					aria-expanded={ showPast }
					onClick={ () => setShowPast( ! showPast ) }
				>
					{ showPast
						? __( 'Hide past dates', 'rayetun-events-calendar' )
						: sprintf(
								/* translators: %d: number of past dates. */
								_n(
									'Show %d past date',
									'Show %d past dates',
									past.length,
									'rayetun-events-calendar'
								),
								past.length
						  ) }
				</Button>
			) }
		</div>
	);
}
