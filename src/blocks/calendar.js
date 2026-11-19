import { registerBlockType } from '@wordpress/blocks';
import {
	InspectorControls,
	useBlockProps,
	useSettings,
} from '@wordpress/block-editor';
import {
	BaseControl,
	ColorPalette,
	PanelBody,
	SelectControl,
	ToggleControl,
	RangeControl,
	Disabled,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

/** Categories to choose from, as select options. */
function useCategoryOptions() {
	const terms = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'rayec_category', {
				per_page: 100,
			} ),
		[]
	);
	return [
		{ label: __( 'All categories', 'rayetun-events-calendar' ), value: '0' },
		...( terms || [] ).map( ( term ) => ( {
			label: term.name,
			value: String( term.id ),
		} ) ),
	];
}

/**
 * A preview of a block that renders on the server.
 *
 * The editor's own style settings (colours, text size, spacing) are already
 * on the block's outer element here, so they are left out of the preview
 * rather than applied twice.
 */
function Preview( { name, attributes } ) {
	// eslint-disable-next-line no-unused-vars
	const { style, backgroundColor, textColor, fontSize, ...rest } = attributes;
	return (
		<Disabled>
			<ServerSideRender block={ name } attributes={ rest } />
		</Disabled>
	);
}

/**
 * The look every block shares: an accent colour and the corners. The text and
 * background colours, text size and spacing are in the editor's own Styles
 * panel.
 */
function LookPanel( { attributes, setAttributes } ) {
	const [ palette ] = useSettings( 'color.palette' );
	return (
		<PanelBody
			title={ __( 'Accent and corners', 'rayetun-events-calendar' ) }
			initialOpen={ false }
		>
			<BaseControl
				__nextHasNoMarginBottom
				help={ __(
					'For buttons, icons and the places bar. Empty uses the colour from Settings. Choose one dark enough to read on your background.',
					'rayetun-events-calendar'
				) }
			>
				<BaseControl.VisualLabel>
					{ __( 'Accent colour', 'rayetun-events-calendar' ) }
				</BaseControl.VisualLabel>
				<ColorPalette
					colors={ palette || [] }
					value={ attributes.accent || undefined }
					enableAlpha={ false }
					onChange={ ( accent ) =>
						setAttributes( { accent: accent || '' } )
					}
				/>
			</BaseControl>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Corners', 'rayetun-events-calendar' ) }
				value={ attributes.corners || '' }
				options={ [
					{
						label: __( 'Soft (default)', 'rayetun-events-calendar' ),
						value: '',
					},
					{
						label: __( 'Square', 'rayetun-events-calendar' ),
						value: 'square',
					},
					{
						label: __( 'Round', 'rayetun-events-calendar' ),
						value: 'round',
					},
				] }
				onChange={ ( corners ) => setAttributes( { corners } ) }
			/>
		</PanelBody>
	);
}

registerBlockType( 'rayec/calendar', {
	edit( { attributes, setAttributes } ) {
		return (
			<div { ...useBlockProps() }>
				<InspectorControls>
					<PanelBody
						title={ __( 'Calendar', 'rayetun-events-calendar' ) }
					>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Starting view',
								'rayetun-events-calendar'
							) }
							help={ __(
								'Visitors can switch between views themselves.',
								'rayetun-events-calendar'
							) }
							value={ attributes.view }
							options={ [
								{
									label: __(
										'Use the default from Settings',
										'rayetun-events-calendar'
									),
									value: '',
								},
								{
									label: __(
										'Month',
										'rayetun-events-calendar'
									),
									value: 'month',
								},
								{
									label: __(
										'Week',
										'rayetun-events-calendar'
									),
									value: 'week',
								},
								{
									label: __(
										'Day',
										'rayetun-events-calendar'
									),
									value: 'day',
								},
								{
									label: __(
										'List',
										'rayetun-events-calendar'
									),
									value: 'list',
								},
							] }
							onChange={ ( view ) => setAttributes( { view } ) }
						/>
					</PanelBody>
					<LookPanel
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				</InspectorControls>
				<Preview name="rayec/calendar" attributes={ attributes } />
			</div>
		);
	},
	save: () => null,
} );

registerBlockType( 'rayec/mini-calendar', {
	edit( { attributes, setAttributes } ) {
		const categories = useCategoryOptions();
		return (
			<div { ...useBlockProps() }>
				<InspectorControls>
					<PanelBody
						title={ __( 'Mini calendar', 'rayetun-events-calendar' ) }
					>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Category', 'rayetun-events-calendar' ) }
							help={ __(
								'Mark only the days with events in this category.',
								'rayetun-events-calendar'
							) }
							value={ String( attributes.category ) }
							options={ categories }
							onChange={ ( value ) =>
								setAttributes( { category: parseInt( value, 10 ) } )
							}
						/>
					</PanelBody>
					<LookPanel
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				</InspectorControls>
				<Preview
					name="rayec/mini-calendar"
					attributes={ attributes }
				/>
			</div>
		);
	},
	save: () => null,
} );

registerBlockType( 'rayec/upcoming', {
	edit( { attributes, setAttributes } ) {
		const categories = useCategoryOptions();
		return (
			<div { ...useBlockProps() }>
				<InspectorControls>
					<PanelBody
						title={ __( 'Upcoming events', 'rayetun-events-calendar' ) }
					>
						<RangeControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'How many events',
								'rayetun-events-calendar'
							) }
							value={ attributes.count }
							min={ 1 }
							max={ 20 }
							onChange={ ( count ) =>
								setAttributes( { count: count || 1 } )
							}
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Category', 'rayetun-events-calendar' ) }
							value={ String( attributes.category ) }
							options={ categories }
							onChange={ ( value ) =>
								setAttributes( { category: parseInt( value, 10 ) } )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Show images',
								'rayetun-events-calendar'
							) }
							help={ __(
								'Events without an image simply show none.',
								'rayetun-events-calendar'
							) }
							checked={ attributes.images }
							onChange={ ( images ) => setAttributes( { images } ) }
						/>
					</PanelBody>
					<LookPanel
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				</InspectorControls>
				<Preview name="rayec/upcoming" attributes={ attributes } />
			</div>
		);
	},
	save: () => null,
} );

registerBlockType( 'rayec/countdown', {
	edit( { attributes, setAttributes } ) {
		const categories = useCategoryOptions();
		const events = useSelect(
			( select ) =>
				select( coreStore ).getEntityRecords( 'postType', 'rayec_event', {
					per_page: 50,
					status: 'publish',
					orderby: 'title',
					order: 'asc',
				} ),
			[]
		);
		return (
			<div { ...useBlockProps() }>
				<InspectorControls>
					<PanelBody
						title={ __( 'Countdown', 'rayetun-events-calendar' ) }
					>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Count down to',
								'rayetun-events-calendar'
							) }
							help={ __(
								'Whichever event is next, or one you choose. A repeating event counts down to its next date.',
								'rayetun-events-calendar'
							) }
							value={ String( attributes.eventId ) }
							options={ [
								{
									label: __(
										'Whichever event is next',
										'rayetun-events-calendar'
									),
									value: '0',
								},
								...( events || [] ).map( ( event ) => ( {
									label:
										event.title.rendered ||
										__(
											'(no title)',
											'rayetun-events-calendar'
										),
									value: String( event.id ),
								} ) ),
							] }
							onChange={ ( value ) =>
								setAttributes( { eventId: parseInt( value, 10 ) } )
							}
						/>
						{ ! attributes.eventId && (
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Category',
									'rayetun-events-calendar'
								) }
								value={ String( attributes.category ) }
								options={ categories }
								onChange={ ( value ) =>
									setAttributes( {
										category: parseInt( value, 10 ),
									} )
								}
							/>
						) }
					</PanelBody>
					<LookPanel
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				</InspectorControls>
				<Preview name="rayec/countdown" attributes={ attributes } />
			</div>
		);
	},
	save: () => null,
} );

registerBlockType( 'rayec/register', {
	edit( { attributes, setAttributes } ) {
		const events = useSelect(
			( select ) =>
				select( coreStore ).getEntityRecords( 'postType', 'rayec_event', {
					per_page: 50,
					status: 'publish',
					orderby: 'title',
					order: 'asc',
				} ),
			[]
		);
		return (
			<div { ...useBlockProps() }>
				<InspectorControls>
					<PanelBody
						title={ __( 'Registration', 'rayetun-events-calendar' ) }
					>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Event', 'rayetun-events-calendar' ) }
							help={ __(
								'The form only appears for an event that takes registrations. Turn them on in the Registration section of the event.',
								'rayetun-events-calendar'
							) }
							value={ String( attributes.eventId ) }
							options={ [
								{
									label: __(
										'Choose an event',
										'rayetun-events-calendar'
									),
									value: '0',
								},
								...( events || [] ).map( ( event ) => ( {
									label:
										event.title.rendered ||
										__(
											'(no title)',
											'rayetun-events-calendar'
										),
									value: String( event.id ),
								} ) ),
							] }
							onChange={ ( value ) =>
								setAttributes( { eventId: parseInt( value, 10 ) } )
							}
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Form style', 'rayetun-events-calendar' ) }
							value={ attributes.layout }
							options={ [
								{
									label: __(
										'The site’s choice (Settings)',
										'rayetun-events-calendar'
									),
									value: '',
								},
								{
									label: __( 'Card', 'rayetun-events-calendar' ),
									value: 'card',
								},
								{
									label: __(
										'Compact (one button)',
										'rayetun-events-calendar'
									),
									value: 'compact',
								},
								{
									label: __(
										'Split (details beside the form)',
										'rayetun-events-calendar'
									),
									value: 'split',
								},
							] }
							onChange={ ( value ) => setAttributes( { layout: value } ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Show the event at the top',
								'rayetun-events-calendar'
							) }
							help={ __(
								'Its image, title, date and place, so the form makes sense on a page that does not describe the event.',
								'rayetun-events-calendar'
							) }
							checked={ attributes.showEvent !== false }
							onChange={ ( value ) =>
								setAttributes( { showEvent: value } )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Help under the fields',
								'rayetun-events-calendar'
							) }
							help={ __(
								'Turned off, the help is hidden from view but still read out by screen readers.',
								'rayetun-events-calendar'
							) }
							checked={ attributes.hints !== false }
							onChange={ ( value ) =>
								setAttributes( { hints: value } )
							}
						/>
					</PanelBody>
					<LookPanel
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				</InspectorControls>
				<Preview name="rayec/register" attributes={ attributes } />
			</div>
		);
	},
	save: () => null,
} );
