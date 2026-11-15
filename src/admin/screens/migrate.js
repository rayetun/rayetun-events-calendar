import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	SelectControl,
	CheckboxControl,
	Notice,
	Modal,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';

import { api, useApp } from '../lib';
import { Panel, ErrorNote } from '../components/ui';

const LABELS = {
	new: __( 'New', 'rayetun-events-calendar' ),
	update: __( 'Updates an event', 'rayetun-events-calendar' ),
	skipped: __( 'Skipped', 'rayetun-events-calendar' ),
};

/**
 * Moving a site over from The Events Calendar.
 *
 * Shown only when there is something of theirs to move. Their content is read
 * and left alone, so this panel keeps offering to undo what it made.
 */
export default function Migrate() {
	const { notify, refresh } = useApp();
	const [ scan, setScan ] = useState( null );
	const [ plan, setPlan ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ progress, setProgress ] = useState( null );
	const [ confirmUndo, setConfirmUndo ] = useState( false );
	const [ options, setOptions ] = useState( {
		post_status: 'draft',
		keep_status: true,
	} );

	useEffect( () => {
		api.get( '/migrate' )
			.then( setScan )
			.catch( () => setScan( { found: false } ) );
	}, [] );

	if ( ! scan || ! scan.found ) {
		return null;
	}

	const readPlan = () => {
		setBusy( 'plan' );
		setError( '' );
		api.post( '/migrate/plan', {} )
			.then( setPlan )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	// One batch at a time, so a big calendar never runs into a timeout.
	const runBatch = ( offset, carried ) =>
		api.post( '/migrate/run', { ...options, offset } ).then( ( result ) => {
			const total = {
				added: carried.added + result.added,
				updated: carried.updated + result.updated,
				skipped: carried.skipped + result.skipped,
				failed: [ ...carried.failed, ...( result.failed || [] ) ],
			};
			setProgress( { ...total, done: result.offset, more: result.more } );
			return result.more ? runBatch( result.offset, total ) : total;
		} );

	const migrate = () => {
		setBusy( 'run' );
		setError( '' );
		setProgress( { added: 0, updated: 0, skipped: 0, failed: [], done: 0, more: true } );
		runBatch( 0, { added: 0, updated: 0, skipped: 0, failed: [] } )
			.then( ( total ) => {
				setPlan( null );
				notify(
					sprintf(
						/* translators: %d: number of events brought across. */
						_n(
							'%d event brought across.',
							'%d events brought across.',
							total.added + total.updated,
							'rayetun-events-calendar'
						),
						total.added + total.updated
					)
				);
				refresh();
				return api.get( '/migrate' ).then( setScan );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const undo = () => {
		setBusy( 'undo' );
		setError( '' );
		setConfirmUndo( false );
		api.post( '/migrate/undo', { confirm: 'undo' } )
			.then( ( result ) => {
				setProgress( null );
				setPlan( null );
				notify(
					sprintf(
						/* translators: %d: number of things removed. */
						_n(
							'%d thing removed. Nothing of The Events Calendar’s was touched.',
							'%d things removed. Nothing of The Events Calendar’s was touched.',
							result.removed,
							'rayetun-events-calendar'
						),
						result.removed
					)
				);
				refresh();
				return api.get( '/migrate' ).then( setScan );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const done = scan.last_run && scan.migrated > 0;

	return (
		<Panel
			title={ __(
				'Coming from The Events Calendar?',
				'rayetun-events-calendar'
			) }
		>
			<p className="rayec-import__counts">
				{ sprintf(
					/* translators: 1: "5 events", 2: "1 venue", 3: "2 organizers". */
					__(
						'Found %1$s, %2$s and %3$s.',
						'rayetun-events-calendar'
					),
					sprintf(
						/* translators: %d: number of events. */
						_n(
							'%d event',
							'%d events',
							scan.events,
							'rayetun-events-calendar'
						),
						scan.events
					),
					sprintf(
						/* translators: %d: number of venues. */
						_n(
							'%d venue',
							'%d venues',
							scan.venues,
							'rayetun-events-calendar'
						),
						scan.venues
					),
					sprintf(
						/* translators: %d: number of organizers. */
						_n(
							'%d organizer',
							'%d organizers',
							scan.organizers,
							'rayetun-events-calendar'
						),
						scan.organizers
					)
				) }
				{ scan.migrated > 0 &&
					' ' +
						sprintf(
							/* translators: %d: number of events already brought across. */
							__(
								'%d already brought across.',
								'rayetun-events-calendar'
							),
							scan.migrated
						) }
			</p>

			<p className="rayec-import__note">
				{ __(
					'Their events, venues and organizers are read and left exactly as they are, so their plugin keeps working. Old links are sent on to the new events, and everything made here can be undone.',
					'rayetun-events-calendar'
				) }
			</p>

			{ error && <ErrorNote message={ error } /> }

			<div className="rayec-import__options">
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Keep each event as it is over there',
						'rayetun-events-calendar'
					) }
					help={ __(
						'Published stays published, drafts stay drafts.',
						'rayetun-events-calendar'
					) }
					checked={ options.keep_status }
					onChange={ ( keep_status ) =>
						setOptions( { ...options, keep_status } )
					}
				/>
				{ ! options.keep_status && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Bring them all in as',
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
				) }
			</div>

			{ progress && (
				<Notice status={ progress.more ? 'info' : 'success' } isDismissible={ false }>
					<p>
						{ progress.more
							? sprintf(
									/* translators: %d: how many events have been read so far. */
									__(
										'Working through them: %d read so far.',
										'rayetun-events-calendar'
									),
									progress.done
							  )
							: sprintf(
									/* translators: 1: number added, 2: number updated, 3: number skipped. */
									__(
										'Finished: %1$d added, %2$d updated, %3$d skipped.',
										'rayetun-events-calendar'
									),
									progress.added,
									progress.updated,
									progress.skipped
							  ) }
					</p>
					{ progress.failed && progress.failed.length > 0 && (
						<ul>
							{ progress.failed.map( ( row, index ) => (
								<li key={ index }>
									{ row.title } — { row.reason }
								</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }

			<div className="rayec-form__foot">
				<Button
					variant="secondary"
					__next40pxDefaultSize
					isBusy={ 'plan' === busy }
					disabled={ !! busy }
					onClick={ readPlan }
				>
					{ __( 'Show me what would happen', 'rayetun-events-calendar' ) }
				</Button>
				<Button
					variant="primary"
					__next40pxDefaultSize
					isBusy={ 'run' === busy }
					disabled={ !! busy || scan.events === 0 }
					onClick={ migrate }
				>
					{ __( 'Bring them across', 'rayetun-events-calendar' ) }
				</Button>
				{ done && (
					<Button
						variant="tertiary"
						isDestructive
						__next40pxDefaultSize
						isBusy={ 'undo' === busy }
						disabled={ !! busy }
						onClick={ () => setConfirmUndo( true ) }
					>
						{ __( 'Undo the migration', 'rayetun-events-calendar' ) }
					</Button>
				) }
			</div>

			{ plan && (
				<table className="rayec-table rayec-import__table">
					<caption>
						{ sprintf(
							/* translators: 1: number to add, 2: number to update, 3: number skipped. */
							__(
								'%1$d to add, %2$d to update, %3$d skipped.',
								'rayetun-events-calendar'
							),
							plan.counts.new,
							plan.counts.update,
							plan.counts.skipped
						) }
					</caption>
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
						{ plan.rows.map( ( row ) => (
							<tr key={ row.uid }>
								<th scope="row">{ row.title }</th>
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
			) }

			{ confirmUndo && (
				<Modal
					title={ __(
						'Undo the migration?',
						'rayetun-events-calendar'
					) }
					onRequestClose={ () => setConfirmUndo( false ) }
					size="small"
				>
					<p>
						{ __(
							'This deletes the events, venues and organizers this migration made here, and nothing else. Anything you have written since will go with them. The Events Calendar’s own content is not touched.',
							'rayetun-events-calendar'
						) }
					</p>
					<div className="rayec-form__foot">
						<Button
							variant="secondary"
							__next40pxDefaultSize
							onClick={ () => setConfirmUndo( false ) }
						>
							{ __( 'Keep them', 'rayetun-events-calendar' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							__next40pxDefaultSize
							onClick={ undo }
						>
							{ __(
								'Yes, undo it',
								'rayetun-events-calendar'
							) }
						</Button>
					</div>
				</Modal>
			) }
		</Panel>
	);
}
