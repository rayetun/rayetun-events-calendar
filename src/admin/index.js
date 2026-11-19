import {
	createRoot,
	useState,
	useEffect,
	useCallback,
	useMemo,
} from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

import './admin.css';
import { AppContext, config, api, useApp } from './lib';
import { Icon } from './icons';
import Home from './screens/home';
import Planner from './screens/planner';
import Events from './screens/events';
import Venues from './screens/venues';
import Settings from './screens/settings';
import Categories from './screens/categories';
import Organizers from './screens/organizers';
import Speakers from './screens/speakers';
import Programs from './screens/programs';
import ImportScreen from './screens/import';
import { applyFilters } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';
import * as ui from './components/ui';
import EventDrawer from './components/event-drawer';
import SetupGuide from './components/setup-guide';
import CommandPalette from './components/command-palette';

const CORE_SCREENS = [
	{
		path: '/',
		label: __( 'Home', 'rayetun-events-calendar' ),
		icon: 'home',
		component: Home,
	},
	{
		path: '/planner',
		label: __( 'Planner', 'rayetun-events-calendar' ),
		icon: 'planner',
		component: Planner,
	},
	{
		path: '/events',
		label: __( 'Events', 'rayetun-events-calendar' ),
		icon: 'list',
		component: Events,
	},
	{
		path: '/venues',
		label: __( 'Venues', 'rayetun-events-calendar' ),
		icon: 'pin',
		component: Venues,
	},
	{
		path: '/organizers',
		label: __( 'Organizers', 'rayetun-events-calendar' ),
		icon: 'users',
		component: Organizers,
	},
	{
		path: '/speakers',
		label: __( 'Speakers', 'rayetun-events-calendar' ),
		icon: 'mic',
		component: Speakers,
	},
	{
		path: '/programs',
		label: __( 'Programs', 'rayetun-events-calendar' ),
		icon: 'layers',
		component: Programs,
		manage: true,
	},
	{
		path: '/categories',
		label: __( 'Categories', 'rayetun-events-calendar' ),
		icon: 'tag',
		component: Categories,
		manage: true,
	},
	{
		path: '/import',
		label: __( 'Import', 'rayetun-events-calendar' ),
		icon: 'download',
		component: ImportScreen,
	},
	{
		path: '/settings',
		label: __( 'Settings', 'rayetun-events-calendar' ),
		icon: 'settings',
		component: Settings,
		manage: true,
	},
];

// Add-ons (such as the Pro plugin) add their own screens through this filter.
// Read once every script on the page has run (see the bottom of this file),
// so an add-on works whether its script loads before this one or after it.
let SCREENS = CORE_SCREENS;

const currentPath = () => {
	// "#/events/123" opens the events screen with that event in the drawer.
	const hash = window.location.hash
		.replace( /^#/, '' )
		.replace( /^(\/events)\/\d+$/, '$1' );
	return SCREENS.some( ( screen ) => screen.path === hash ) ? hash : '/';
};

const THEMES = [ 'auto', 'light', 'dark' ];

function App() {
	const [ path, setPath ] = useState( currentPath );
	const [ theme, setTheme ] = useState( () => {
		try {
			const saved = window.localStorage.getItem( 'rayec-theme' );
			return THEMES.includes( saved ) ? saved : 'auto';
		} catch ( e ) {
			return 'auto';
		}
	} );
	const [ notices, setNotices ] = useState( [] );
	const [ editing, setEditing ] = useState( () => {
		const linked = window.location.hash.match( /^#\/events\/(\d+)$/ );
		return linked ? parseInt( linked[ 1 ], 10 ) : null;
	} );
	// The guide opens itself the first time; the header button brings it back.
	const [ guide, setGuide ] = useState( false );
	const [ palette, setPalette ] = useState( false );

	// Ctrl or Cmd + K opens the palette from anywhere on the dashboard.
	useEffect( () => {
		const onKey = ( event ) => {
			if (
				( event.ctrlKey || event.metaKey ) &&
				! event.altKey &&
				event.key.toLowerCase() === 'k'
			) {
				event.preventDefault();
				setPalette( true );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [] );
	const [ setup, setSetup ] = useState( null );
	const [ version, setVersion ] = useState( 0 );

	useEffect( () => {
		const onHash = () => setPath( currentPath() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	// The theme lives on <body> so modals and popovers rendered in portals pick it up.
	useEffect( () => {
		document.body.setAttribute( 'data-rayec-theme', theme );
		try {
			window.localStorage.setItem( 'rayec-theme', theme );
		} catch ( e ) {
			// Private mode: the choice simply lasts for this visit.
		}
	}, [ theme ] );

	useEffect( () => {
		const screen = SCREENS.find( ( item ) => item.path === path );
		document.title = `${ screen.label } ‹ ${ __(
			'Events',
			'rayetun-events-calendar'
		) } — ${ config.siteName }`;
	}, [ path ] );

	const dismiss = useCallback(
		( id ) =>
			setNotices( ( list ) =>
				list.filter( ( notice ) => notice.id !== id )
			),
		[]
	);

	const notify = useCallback( ( content, options = {} ) => {
		const id = `${ Date.now() }-${ Math.random() }`;
		const notice = {
			id,
			content,
			status: options.error ? 'error' : 'success',
			explicitDismiss: !! options.error,
			actions: options.undo
				? [
						{
							label: __( 'Undo', 'rayetun-events-calendar' ),
							onClick: options.undo,
						},
				  ]
				: [],
		};
		setNotices( ( list ) => [ ...list.slice( -2 ), notice ] );
		speak( content, options.error ? 'assertive' : 'polite' );
	}, [] );

	const refresh = useCallback( () => setVersion( ( v ) => v + 1 ), [] );

	const app = useMemo(
		() => ( {
			notify,
			refresh,
			version,
			// Pass an event ID to edit, or an object of defaults to create.
			openEvent: ( target = {} ) => setEditing( target ),
			go: ( to ) => {
				window.location.hash = to;
			},
		} ),
		[ notify, refresh, version ]
	);

	const screen = SCREENS.find( ( item ) => item.path === path );
	const Screen = screen.component;
	const nextTheme = THEMES[ ( THEMES.indexOf( theme ) + 1 ) % THEMES.length ];
	const themeLabels = {
		auto: __( 'Theme: match system', 'rayetun-events-calendar' ),
		light: __( 'Theme: light', 'rayetun-events-calendar' ),
		dark: __( 'Theme: dark', 'rayetun-events-calendar' ),
	};

	return (
		<AppContext.Provider value={ app }>
			<div className="rayec-shell">
				<aside className="rayec-side">
					<div className="rayec-side__brand">
						<span className="rayec-side__logo" aria-hidden="true">
							<Icon name="logo" size={ 20 } />
						</span>
						<span className="rayec-side__name">
							{ __( 'Events', 'rayetun-events-calendar' ) }
						</span>
						<span className="rayec-side__badge">
							{ config.version }
						</span>
					</div>
					<nav
						aria-label={ __(
							'Events dashboard',
							'rayetun-events-calendar'
						) }
					>
						<ul className="rayec-side__nav">
							{ SCREENS.filter(
								( item ) => ! item.manage || config.canManage
							).map( ( item ) => (
								<li key={ item.path }>
									<a
										href={ `#${ item.path }` }
										className={
											item.path === path
												? 'is-active'
												: ''
										}
										aria-current={
											item.path === path
												? 'page'
												: undefined
										}
									>
										<Icon name={ item.icon } />
										{ item.label }
									</a>
								</li>
							) ) }
						</ul>
					</nav>
					<div className="rayec-side__foot">
						<a
							href={ config.supportUrl }
							target="_blank"
							rel="noreferrer"
						>
							<Icon name="help" />
							{ __( 'Help and support', 'rayetun-events-calendar' ) }
							<span className="screen-reader-text">
								{ __(
									'(opens in a new tab)',
									'rayetun-events-calendar'
								) }
							</span>
						</a>
					</div>
				</aside>

				<main className="rayec-main">
					<div className="rayec-topbar">
						<Button
							className="rayec-theme-toggle"
							variant="tertiary"
							onClick={ () => setTheme( nextTheme ) }
							aria-label={ `${ themeLabels[ theme ] }. ${ __(
								'Change theme',
								'rayetun-events-calendar'
							) }` }
						>
							<Icon
								name={
									theme === 'dark'
										? 'moon'
										: theme === 'light'
										? 'sun'
										: 'auto'
								}
							/>
							<span aria-hidden="true">
								{ themeLabels[ theme ] }
							</span>
						</Button>
						<Button
							variant="tertiary"
							__next40pxDefaultSize
							className="rayec-top__find"
							onClick={ () => setPalette( true ) }
							aria-keyshortcuts="Control+K Meta+K"
						>
							<Icon name="search" />
							{ __( 'Find', 'rayetun-events-calendar' ) }
							<kbd className="rayec-top__kbd" aria-hidden="true">
								{ /Mac|iPhone|iPad/.test( window.navigator.platform || '' )
									? '⌘K'
									: 'Ctrl K' }
							</kbd>
						</Button>
						<Button
							variant="primary"
							__next40pxDefaultSize
							onClick={ () => setEditing( {} ) }
						>
							<Icon name="plus" />
							{ __( 'New event', 'rayetun-events-calendar' ) }
						</Button>
						<Button
							variant="secondary"
							__next40pxDefaultSize
							className="rayec-top__guide"
							onClick={ () => setGuide( true ) }
						>
							<Icon name="help" />
							{ __( 'Setup guide', 'rayetun-events-calendar' ) }
						</Button>
					</div>
					<Screen key={ path } />
				</main>
			</div>

			<SetupGuide
				open={ guide || ( null !== setup && ! setup.done ) }
				onClose={ () => setGuide( false ) }
				onState={ ( state ) => setSetup( state ) }
			/>

			{ palette && (
				<CommandPalette
					screens={ SCREENS.filter(
						( item ) => ! item.manage || config.canManage
					) }
					onClose={ () => setPalette( false ) }
					onSetup={ () => setGuide( true ) }
				/>
			) }

			{ editing !== null && (
				<EventDrawer
					target={ editing }
					onClose={ () => setEditing( null ) }
				/>
			) }

			<SnackbarList
				className="rayec-toasts"
				notices={ notices }
				onRemove={ dismiss }
			/>
		</AppContext.Provider>
	);
}

/*
 * The dashboard's own building blocks, for add-ons whose screens should look
 * like the rest of it. Read them when rendering, not when a script loads.
 */
window.rayec = {
	...( window.rayec || {} ),
	ui: { ...ui, Icon, api, useApp, config },
};

domReady( () => {
	const mount = document.getElementById( 'rayec-app' );
	if ( mount ) {
		SCREENS = applyFilters( 'rayec.screens', CORE_SCREENS );
		createRoot( mount ).render( <App /> );
	}
} );
