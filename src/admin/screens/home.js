import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __, sprintf, _n } from '@wordpress/i18n';

import { api, config, useApp } from '../lib';
import { Icon } from '../icons';
import {
	useLoad,
	Panel,
	Loading,
	ErrorNote,
	Empty,
	OccurrenceRow,
} from '../components/ui';

function Stat( { icon, tone, value, label, href } ) {
	return (
		<a className="rayec-stat" href={ href }>
			<span className={ `rayec-stat__icon rayec-stat__icon--${ tone }` }>
				<Icon name={ icon } size={ 20 } />
			</span>
			<span>
				<span className="rayec-stat__num">{ value }</span>
				<span className="rayec-stat__label">{ label }</span>
			</span>
		</a>
	);
}

function scoreTone( score ) {
	if ( score >= 90 ) {
		return 'good';
	}
	return score >= 70 ? 'fair' : 'poor';
}

function GuardPanel( { a11y } ) {
	const { openEvent } = useApp();

	if ( a11y.score === null ) {
		return (
			<Panel
				title={ __( 'Accessibility Guard', 'rayetun-events-calendar' ) }
				icon="shield"
			>
				<p className="rayec-muted">
					{ __(
						'Once you have upcoming events, each one is checked for missing alt text, vague links, access details and more.',
						'rayetun-events-calendar'
					) }
				</p>
			</Panel>
		);
	}

	const tone = scoreTone( a11y.score );

	return (
		<Panel
			title={ __( 'Accessibility Guard', 'rayetun-events-calendar' ) }
			icon="shield"
		>
			<div className="rayec-score">
				<span className={ `rayec-score__num is-${ tone }` }>
					{ a11y.score }
				</span>
				<span className="rayec-score__of">/ 100</span>
				<span className="rayec-score__label">
					{ sprintf(
						/* translators: 1: events with no issues, 2: events checked. */
						__(
							'%1$d of %2$d upcoming events have no issues',
							'rayetun-events-calendar'
						),
						a11y.clean,
						a11y.checked
					) }
				</span>
			</div>
			<div
				className="rayec-meter"
				role="img"
				aria-label={ sprintf(
					/* translators: %d: score out of 100. */
					__(
						'Accessibility score: %d out of 100',
						'rayetun-events-calendar'
					),
					a11y.score
				) }
			>
				<span
					className={ `rayec-meter__fill is-${ tone }` }
					style={ { width: `${ a11y.score }%` } }
				/>
			</div>

			{ a11y.flagged.length > 0 ? (
				<ul className="rayec-flagged">
					{ a11y.flagged.map( ( event ) => (
						<li key={ event.id }>
							<button
								type="button"
								onClick={ () => openEvent( event.id ) }
							>
								<span className="rayec-flagged__title">
									{ event.title }
								</span>
								<span className="rayec-flagged__issue">
									{ event.issues[ 0 ].message }
									{ event.issues.length > 1 &&
										` ${ sprintf(
											/* translators: %d: number of further issues. */
											_n(
												'+%d more',
												'+%d more',
												event.issues.length - 1,
												'rayetun-events-calendar'
											),
											event.issues.length - 1
										) }` }
								</span>
							</button>
						</li>
					) ) }
				</ul>
			) : (
				<p className="rayec-allclear">
					<Icon name="check" />
					{ __(
						'Every upcoming event passes the checks.',
						'rayetun-events-calendar'
					) }
				</p>
			) }
		</Panel>
	);
}

function Checklist( { data } ) {
	const { openEvent, go, notify, refresh } = useApp();
	const [ busy, setBusy ] = useState( '' );

	const run = ( key, request, message ) => {
		setBusy( key );
		request
			.then( () => {
				notify( message );
				refresh();
			} )
			.catch( ( error ) => notify( error.message, { error: true } ) )
			.finally( () => setBusy( '' ) );
	};

	const steps = [
		{
			key: 'event',
			label: __( 'Create your first event', 'rayetun-events-calendar' ),
			action: __( 'New event', 'rayetun-events-calendar' ),
			onClick: () => openEvent( {} ),
		},
		{
			key: 'venue',
			label: __( 'Add a venue', 'rayetun-events-calendar' ),
			action: __( 'Add venue', 'rayetun-events-calendar' ),
			onClick: () => go( '/venues' ),
		},
		{
			key: 'access',
			label: __(
				'Describe access at a venue',
				'rayetun-events-calendar'
			),
			action: __( 'Open venues', 'rayetun-events-calendar' ),
			onClick: () => go( '/venues' ),
		},
		{
			key: 'page',
			label: __(
				'Put the calendar on a page',
				'rayetun-events-calendar'
			),
			action: __( 'Create page', 'rayetun-events-calendar' ),
			manage: true,
			onClick: () =>
				run(
					'page',
					api.post( '/calendar-page' ),
					__(
						'Calendar page created.',
						'rayetun-events-calendar'
					)
				),
		},
	];
	const done = steps.filter( ( step ) => data.checklist[ step.key ] ).length;

	if ( done === steps.length ) {
		return null;
	}

	return (
		<Panel
			title={ __( 'Get set up', 'rayetun-events-calendar' ) }
			icon="sparkle"
			action={
				<span className="rayec-muted">
					{ sprintf(
						/* translators: 1: steps done, 2: total steps. */
						__( '%1$d of %2$d done', 'rayetun-events-calendar' ),
						done,
						steps.length
					) }
				</span>
			}
		>
			<ul className="rayec-checklist">
				{ steps.map( ( step ) => {
					const isDone = data.checklist[ step.key ];
					return (
						<li
							key={ step.key }
							className={ isDone ? 'is-done' : '' }
						>
							<span
								className="rayec-checklist__mark"
								aria-hidden="true"
							>
								{ isDone && <Icon name="check" size={ 14 } /> }
							</span>
							<span className="rayec-checklist__label">
								{ step.label }
								<span className="screen-reader-text">
									{ isDone
										? __(
												'(done)',
												'rayetun-events-calendar'
										  )
										: __(
												'(to do)',
												'rayetun-events-calendar'
										  ) }
								</span>
							</span>
							{ ! isDone &&
								( ! step.manage || config.canManage ) && (
									<Button
										variant="link"
										onClick={ step.onClick }
										disabled={ busy === step.key }
									>
										{ step.action }
									</Button>
								) }
						</li>
					);
				} ) }
			</ul>
			{ config.canManage && ! data.has_samples && ! data.checklist.event && (
				<p className="rayec-checklist__sample">
					{ __(
						'Want to look around first?',
						'rayetun-events-calendar'
					) }{ ' ' }
					<Button
						variant="link"
						disabled={ busy === 'sample' }
						onClick={ () =>
							run(
								'sample',
								api.post( '/sample-data' ),
								__(
									'Sample events added. Remove them any time in Settings.',
									'rayetun-events-calendar'
								)
							)
						}
					>
						{ __( 'Add sample events', 'rayetun-events-calendar' ) }
					</Button>
				</p>
			) }
		</Panel>
	);
}

export default function Home() {
	const { openEvent } = useApp();
	const { data, loading, error } = useLoad( '/dashboard' );

	if ( error ) {
		return <ErrorNote message={ error } />;
	}
	if ( ! data ) {
		return loading ? <Loading /> : null;
	}

	const stats = data.stats;

	return (
		<>
			<div className="rayec-welcome">
				<div>
					<h1 className="rayec-welcome__title">
						{ sprintf(
							/* translators: %s: user's display name. */
							__( 'Hello, %s', 'rayetun-events-calendar' ),
							config.userName
						) }
					</h1>
					<p className="rayec-welcome__sub">
						{ data.today.length
							? sprintf(
									/* translators: %d: number of events today. */
									_n(
										'You have %d event today.',
										'You have %d events today.',
										data.today.length,
										'rayetun-events-calendar'
									),
									data.today.length
							  )
							: __(
									'Nothing is on today. Here is what is coming up.',
									'rayetun-events-calendar'
							  ) }
					</p>
				</div>
				<div className="rayec-welcome__actions">
					<a
						className="rayec-welcome__btn"
						href={ data.calendar }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'View calendar', 'rayetun-events-calendar' ) }
						<Icon name="external" size={ 14 } />
						<span className="screen-reader-text">
							{ __(
								'(opens in a new tab)',
								'rayetun-events-calendar'
							) }
						</span>
					</a>
				</div>
			</div>

			<div className="rayec-stats">
				<Stat
					icon="planner"
					tone="teal"
					value={ stats.upcoming }
					label={ __( 'Upcoming events', 'rayetun-events-calendar' ) }
					href="#/events"
				/>
				<Stat
					icon="clock"
					tone="amber"
					value={ stats.this_week }
					label={ __( 'In the next 7 days', 'rayetun-events-calendar' ) }
					href="#/planner"
				/>
				<Stat
					icon="repeat"
					tone="indigo"
					value={ stats.recurring }
					label={ __( 'Repeating series', 'rayetun-events-calendar' ) }
					href="#/events"
				/>
				<Stat
					icon="pin"
					tone="rose"
					value={ stats.venues }
					label={ __( 'Venues', 'rayetun-events-calendar' ) }
					href="#/venues"
				/>
				<Stat
					icon="draft"
					tone="slate"
					value={ stats.drafts }
					label={ __( 'Drafts', 'rayetun-events-calendar' ) }
					href="#/events"
				/>
				{ stats.rsvp && (
					<Stat
						icon="users"
						tone="teal"
						value={ stats.booked }
						label={ __(
							'Places booked, next 7 days',
							'rayetun-events-calendar'
						) }
						href="#/events"
					/>
				) }
			</div>

			<div className="rayec-cols">
				<div className="rayec-cols__main">
					<Panel
						title={ __( 'Today', 'rayetun-events-calendar' ) }
						icon="clock"
					>
						{ data.today.length ? (
							<ul className="rayec-occ-list">
								{ data.today.map( ( item ) => (
									<OccurrenceRow
										key={ item.id }
										item={ item }
									/>
								) ) }
							</ul>
						) : (
							<p className="rayec-muted">
								{ __(
									'No events today.',
									'rayetun-events-calendar'
								) }
							</p>
						) }
					</Panel>

					<Panel
						title={ __(
							'Next 7 days',
							'rayetun-events-calendar'
						) }
						icon="planner"
						action={
							<a href="#/planner">
								{ __(
									'Open planner',
									'rayetun-events-calendar'
								) }
							</a>
						}
					>
						{ data.week.length ? (
							<ul className="rayec-occ-list">
								{ data.week.map( ( item ) => (
									<OccurrenceRow
										key={ item.id }
										item={ item }
									/>
								) ) }
							</ul>
						) : (
							<Empty
								title={ __(
									'A clear week',
									'rayetun-events-calendar'
								) }
								action={
									<Button
										variant="secondary"
										onClick={ () => openEvent( {} ) }
									>
										{ __(
											'New event',
											'rayetun-events-calendar'
										) }
									</Button>
								}
							>
								{ __(
									'Events happening in the next seven days appear here.',
									'rayetun-events-calendar'
								) }
							</Empty>
						) }
					</Panel>
					{ applyFilters( 'rayec.homeCards', [], data ).map(
						( card ) => (
							<div key={ card.key }>{ card.render( data ) }</div>
						)
					) }
				</div>

				<div className="rayec-cols__side">
					<Checklist data={ data } />
					<GuardPanel a11y={ data.a11y } />
				</div>
			</div>
		</>
	);
}
