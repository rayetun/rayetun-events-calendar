import { useState, useEffect } from '@wordpress/element';
import { Button, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { api, useApp } from '../lib';
import { Panel, ErrorNote } from '../components/ui';

/**
 * Calendars this site follows and re-reads on its own.
 *
 * Shown once there is at least one, and whenever a calendar has just been
 * imported from an address, which is the moment it makes sense to offer.
 */
export default function Feeds( { offer, onFollowed } ) {
	const { notify, refresh } = useApp();
	const [ feeds, setFeeds ] = useState( [] );
	const [ schedules, setSchedules ] = useState( {} );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ every, setEvery ] = useState( 'daily' );

	const load = () =>
		api
			.get( '/feeds' )
			.then( ( result ) => {
				setFeeds( result.feeds || [] );
				setSchedules( result.schedules || {} );
			} )
			.catch( () => {} );

	useEffect( () => {
		load();
	}, [] );

	const follow = () => {
		setBusy( 'add' );
		setError( '' );
		api.post( '/feeds', { ...offer, every } )
			.then( () => {
				notify(
					__(
						'This calendar will be checked from now on.',
						'rayetun-events-calendar'
					)
				);
				if ( onFollowed ) {
					onFollowed();
				}
				return load();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const syncNow = ( feed ) => {
		setBusy( feed.id );
		setError( '' );
		api.post( `/feeds/${ feed.id }/sync`, {} )
			.then( ( result ) => {
				notify(
					sprintf(
						/* translators: 1: number added, 2: number updated. */
						__(
							'%1$d added, %2$d updated.',
							'rayetun-events-calendar'
						),
						result.added,
						result.updated
					)
				);
				refresh();
				return load();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const stop = ( feed ) => {
		setBusy( feed.id );
		api.del( `/feeds/${ feed.id }` )
			.then( () => {
				notify(
					__(
						'Stopped following that calendar. Its events are still here.',
						'rayetun-events-calendar'
					)
				);
				return load();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const following =
		offer && feeds.some( ( feed ) => feed.url === offer.url );

	if ( ! feeds.length && ! offer ) {
		return null;
	}

	return (
		<Panel
			title={ __( 'Calendars this site follows', 'rayetun-events-calendar' ) }
		>
			<p className="rayec-import__note">
				{ __(
					'A followed calendar is read again on its own and its events are updated here. Events that vanish from it are left alone, so a broken feed can never empty your calendar.',
					'rayetun-events-calendar'
				) }
			</p>

			{ error && <ErrorNote message={ error } /> }

			{ offer && ! following && (
				<div className="rayec-import__options">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Check this calendar',
							'rayetun-events-calendar'
						) }
						help={ offer.url }
						value={ every }
						options={ Object.keys( schedules ).map( ( key ) => ( {
							label: schedules[ key ],
							value: key,
						} ) ) }
						onChange={ setEvery }
					/>
					<div className="rayec-form__foot">
						<Button
							variant="secondary"
							__next40pxDefaultSize
							isBusy={ 'add' === busy }
							disabled={ !! busy }
							onClick={ follow }
						>
							{ __(
								'Follow this calendar',
								'rayetun-events-calendar'
							) }
						</Button>
					</div>
				</div>
			) }

			{ feeds.length > 0 && (
				<table className="rayec-table rayec-import__table">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Calendar', 'rayetun-events-calendar' ) }
							</th>
							<th scope="col">
								{ __( 'How often', 'rayetun-events-calendar' ) }
							</th>
							<th scope="col">
								{ __(
									'Last check',
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
						{ feeds.map( ( feed ) => (
							<tr key={ feed.id }>
								<th scope="row">
									<span className="rayec-import__sample">
										{ feed.name || feed.url }
									</span>
								</th>
								<td>
									{ schedules[ feed.every ] || feed.every }
								</td>
								<td>
									{ feed.last_run
										? feed.last_result
										: __(
												'Not yet',
												'rayetun-events-calendar'
										  ) }
								</td>
								<td>
									<Button
										variant="tertiary"
										isBusy={ busy === feed.id }
										disabled={ !! busy }
										onClick={ () => syncNow( feed ) }
									>
										{ __(
											'Check now',
											'rayetun-events-calendar'
										) }
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										disabled={ !! busy }
										onClick={ () => stop( feed ) }
									>
										{ __(
											'Stop',
											'rayetun-events-calendar'
										) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</Panel>
	);
}
