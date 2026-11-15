import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
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

/** A preview of a block that renders on the server. */
function Preview( { name, attributes } ) {
	return (
		<Disabled>
			<ServerSideRender block={ name } attributes={ attributes } />
		</Disabled>
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
				</InspectorControls>
				<Disabled>
					<ServerSideRender
						block="rayec/calendar"
						attributes={ attributes }
					/>
				</Disabled>
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
				</InspectorControls>
				<Preview name="rayec/countdown" attributes={ attributes } />
			</div>
		);
	},
	save: () => null,
} );
