import { useState, useEffect } from '@wordpress/element';
import {
	Modal,
	Button,
	SelectControl,
	CheckboxControl,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';

import { api, useApp, config, weekdayNames } from '../lib';
import { Icon } from '../icons';

/**
 * The first five minutes, as a dialog.
 *
 * It opens itself once, after the plugin is switched on. Three questions, each
 * of which changes something real, then a way in. Every answer is in Settings
 * afterwards, and "Setup guide" in the header opens it again at any time.
 */
export default function SetupGuide( { open, onClose, onState } ) {
	const { notify, refresh, openEvent } = useApp();
	const [ state, setState ] = useState( null );
	const [ step, setStep ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	// Once it is up it stays up until it is closed, so that saving the
	// answers on the third step does not take the dialog away with it.
	const [ held, setHeld ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ madePage, setMadePage ] = useState( null );
	const [ answers, setAnswers ] = useState( {
		default_view: 'month',
		week_starts_on: 1,
		make_page: true,
	} );

	useEffect( () => {
		api.get( '/onboarding' )
			.then( ( result ) => {
				setState( result );
				setAnswers( ( previous ) => ( {
					...previous,
					default_view: result.settings.default_view,
					week_starts_on: result.settings.week_starts_on,
					make_page: ! result.page,
				} ) );
				if ( onState ) {
					onState( result );
				}
			} )
			.catch( () => setState( { done: true } ) );
	}, [] );

	useEffect( () => {
		if ( open ) {
			setHeld( true );
		}
	}, [ open ] );

	if ( ( ! open && ! held ) || ! state ) {
		return null;
	}

	const save = ( body ) => {
		setBusy( true );
		setError( '' );
		return api
			.post( '/onboarding', body )
			.then( ( result ) => {
				setState( ( previous ) => ( { ...previous, done: true } ) );
				if ( onState ) {
					onState( { ...state, done: true } );
				}
				refresh();
				return result;
			} )
			.catch( ( e ) => {
				setError( e.message );
				return null;
			} )
			.finally( () => setBusy( false ) );
	};

	const finish = () =>
		save( answers ).then( ( result ) => {
			if ( ! result ) {
				return;
			}
			setMadePage( result.page && result.page.url ? result.page : null );
			setStep( 3 );
		} );

	const close = ( quietly ) => {
		if ( ! state.done && ! quietly ) {
			save( { skip: true } );
		}
		setStep( 0 );
		setHeld( false );
		onClose();
	};

	const days = weekdayNames( 'long' );
	const found = state.found || [];

	const steps = [
		{
			name: __( 'View', 'rayetun-events-calendar' ),
			title: __( 'How should the calendar open?', 'rayetun-events-calendar' ),
			help: __(
				'Whatever you choose, visitors can switch between month, week, day and list themselves.',
				'rayetun-events-calendar'
			),
			control: (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Starting view', 'rayetun-events-calendar' ) }
					value={ answers.default_view }
					options={ [
						{
							label: __(
								'A month at a time',
								'rayetun-events-calendar'
							),
							value: 'month',
						},
						{
							label: __(
								'A week at a time',
								'rayetun-events-calendar'
							),
							value: 'week',
						},
						{
							label: __(
								'A day at a time',
								'rayetun-events-calendar'
							),
							value: 'day',
						},
						{
							label: __(
								'A list of what is coming up',
								'rayetun-events-calendar'
							),
							value: 'list',
						},
					] }
					onChange={ ( default_view ) =>
						setAnswers( { ...answers, default_view } )
					}
				/>
			),
		},
		{
			name: __( 'Week', 'rayetun-events-calendar' ),
			title: __(
				'Which day does your week start on?',
				'rayetun-events-calendar'
			),
			help: __(
				'The first column of the month grid. This starts from what WordPress already has.',
				'rayetun-events-calendar'
			),
			control: (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Week starts on', 'rayetun-events-calendar' ) }
					value={ String( answers.week_starts_on ) }
					options={ days.map( ( day, index ) => ( {
						label: day,
						value: String( index ),
					} ) ) }
					onChange={ ( value ) =>
						setAnswers( {
							...answers,
							week_starts_on: parseInt( value, 10 ),
						} )
					}
				/>
			),
		},
		{
			name: __( 'Page', 'rayetun-events-calendar' ),
			title: __(
				'Where should people find it?',
				'rayetun-events-calendar'
			),
			help: sprintf(
				/* translators: %s: the address of the events page. */
				__(
					'Your events already live at %s. A page gives you somewhere to put the calendar alongside your own words, and you can add the Events Calendar block to any page yourself.',
					'rayetun-events-calendar'
				),
				config.eventsUrl || '/events/'
			),
			control: (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Make me a Calendar page',
						'rayetun-events-calendar'
					) }
					checked={ answers.make_page }
					onChange={ ( make_page ) =>
						setAnswers( { ...answers, make_page } )
					}
				/>
			),
		},
		{
			name: __( 'Go live', 'rayetun-events-calendar' ),
			title: __( 'That is the setting-up done', 'rayetun-events-calendar' ),
			help: __(
				'Everything you just chose is in Settings, and this guide is in the header whenever you want it again.',
				'rayetun-events-calendar'
			),
			control: (
				<ul className="rayec-setup__ways">
					<li>
						<Button
							variant="secondary"
							__next40pxDefaultSize
							onClick={ () => {
								close( true );
								openEvent( {} );
							} }
						>
							{ __(
								'Add your first event',
								'rayetun-events-calendar'
							) }
						</Button>
						<span>
							{ __(
								'A name and a date is enough to start.',
								'rayetun-events-calendar'
							) }
						</span>
					</li>
					<li>
						<Button
							variant="secondary"
							__next40pxDefaultSize
							href="#/import"
							onClick={ () => close( true ) }
						>
							{ __(
								'Bring events in',
								'rayetun-events-calendar'
							) }
						</Button>
						<span>
							{ __(
								'From a calendar feed, a spreadsheet, or another events plugin.',
								'rayetun-events-calendar'
							) }
						</span>
					</li>
					{ madePage && (
						<li>
							<Button
								variant="secondary"
								__next40pxDefaultSize
								href={ madePage.url }
								target="_blank"
								rel="noreferrer"
							>
								{ __(
									'See your Calendar page',
									'rayetun-events-calendar'
								) }
							</Button>
							<span>
								{ __(
									'Just made, and waiting for its first event.',
									'rayetun-events-calendar'
								) }
							</span>
						</li>
					) }
				</ul>
			),
		},
	];

	const current = steps[ step ];
	const last = steps.length - 1;

	return (
		<Modal
			title={ __( 'Setting up your calendar', 'rayetun-events-calendar' ) }
			onRequestClose={ () => close( false ) }
			className="rayec-setup"
			overlayClassName="rayec-setup-over"
			size="medium"
		>
			<ol className="rayec-setup__steps">
				{ steps.map( ( one, index ) => (
					<li
						key={ one.name }
						className={
							index === step
								? 'is-current'
								: index < step
								? 'is-done'
								: ''
						}
						aria-current={ index === step ? 'step' : undefined }
					>
						<span className="rayec-setup__num" aria-hidden="true">
							{ index < step ? (
								<Icon name="check" size={ 14 } />
							) : (
								index + 1
							) }
						</span>
						{ one.name }
					</li>
				) ) }
			</ol>

			{ error && <p className="rayec-setup__error">{ error }</p> }

			{ 0 === step && found.length > 0 && (
				<div className="rayec-setup__found">
					<p>
						<strong>
							{ sprintf(
								/* translators: %s: the name of another events plugin. */
								__(
									'You already have events in %s.',
									'rayetun-events-calendar'
								),
								found[ 0 ].name
							) }
						</strong>{ ' ' }
						{ found[ 0 ].migrate
							? sprintf(
									/* translators: %d: number of events found. */
									_n(
										'%d of them. Import can bring it across and send its old links on to the new one.',
										'%d of them. Import can bring them across and send their old links on to the new ones.',
										found[ 0 ].events,
										'rayetun-events-calendar'
									),
									found[ 0 ].events
							  )
							: __(
									'Export a calendar file from it and Import will read it.',
									'rayetun-events-calendar'
							  ) }
					</p>
				</div>
			) }

			<h3 className="rayec-setup__title">{ current.title }</h3>
			<p className="rayec-setup__help">{ current.help }</p>
			<div className="rayec-setup__control">{ current.control }</div>

			<div className="rayec-setup__foot">
				{ step > 0 && step < last && (
					<Button
						variant="tertiary"
						__next40pxDefaultSize
						disabled={ busy }
						onClick={ () => setStep( step - 1 ) }
					>
						{ __( 'Back', 'rayetun-events-calendar' ) }
					</Button>
				) }

				{ step < 2 && (
					<Button
						variant="tertiary"
						__next40pxDefaultSize
						disabled={ busy }
						onClick={ () => setStep( step + 1 ) }
					>
						{ __( 'Skip this step', 'rayetun-events-calendar' ) }
					</Button>
				) }

				{ step < 2 && (
					<Button
						variant="primary"
						__next40pxDefaultSize
						className="rayec-setup__go"
						onClick={ () => setStep( step + 1 ) }
					>
						{ __( 'Continue', 'rayetun-events-calendar' ) }
						<Icon name="chevronRight" size={ 16 } />
					</Button>
				) }

				{ 2 === step && (
					<Button
						variant="primary"
						__next40pxDefaultSize
						className="rayec-setup__go"
						isBusy={ busy }
						disabled={ busy }
						onClick={ finish }
					>
						{ __( 'Finish', 'rayetun-events-calendar' ) }
					</Button>
				) }

				{ last === step && (
					<Button
						variant="primary"
						__next40pxDefaultSize
						className="rayec-setup__go"
						onClick={ () => {
							notify(
								__(
									'Set up. Everything is in Settings.',
									'rayetun-events-calendar'
								)
							);
							close( true );
						} }
					>
						{ __( 'Done', 'rayetun-events-calendar' ) }
					</Button>
				) }
			</div>
		</Modal>
	);
}
