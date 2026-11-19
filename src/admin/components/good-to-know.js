import { SelectControl, TextareaControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { config } from '../lib';

/**
 * The "Good to know" fields: who the event is for, when doors open, and the
 * cancellation policy. Shared by the event drawer and the block editor.
 *
 * @param {Object}   props
 * @param {string}   props.age      Age key, or ''.
 * @param {number}   props.doors    Minutes before the start, or 0.
 * @param {string}   props.policy   Cancellation policy, plain text.
 * @param {boolean}  props.bookings Whether the event takes bookings.
 * @param {Function} props.onChange Called with the changed fields.
 */
export default function GoodToKnow( {
	age,
	doors,
	policy,
	bookings,
	onChange,
} ) {
	const ages = config.ages || [];
	const minutes = ( config.doorMinutes || [] ).map( ( value ) => ( {
		value: String( value ),
		label:
			value >= 60 && value % 60 === 0
				? sprintf(
						/* translators: %d: number of hours. */
						_n(
							'%d hour before the start',
							'%d hours before the start',
							value / 60,
							'rayetun-events-calendar'
						),
						value / 60
				  )
				: sprintf(
						/* translators: %d: number of minutes. */
						__(
							'%d minutes before the start',
							'rayetun-events-calendar'
						),
						value
				  ),
	} ) );

	return (
		<div className="rayec-goodtoknow-fields">
			<div className="rayec-form__row">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Age', 'rayetun-events-calendar' ) }
					value={ age || '' }
					options={ [
						{
							value: '',
							label: __( 'Not stated', 'rayetun-events-calendar' ),
						},
						...ages,
					] }
					onChange={ ( value ) => onChange( { age: value } ) }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Doors open', 'rayetun-events-calendar' ) }
					value={ String( doors || '' ) }
					options={ [
						{
							value: '',
							label: __(
								'At the start',
								'rayetun-events-calendar'
							),
						},
						...minutes,
					] }
					onChange={ ( value ) =>
						onChange( { doors: parseInt( value, 10 ) || 0 } )
					}
				/>
			</div>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Cancellation policy', 'rayetun-events-calendar' ) }
				help={
					bookings
						? __(
								'Plain text. Left empty, the page says people can cancel their place with the link in their confirmation email.',
								'rayetun-events-calendar'
						  )
						: __(
								'Plain text, such as “Refunds up to 7 days before the event.”',
								'rayetun-events-calendar'
						  )
				}
				rows={ 2 }
				value={ policy || '' }
				onChange={ ( value ) => onChange( { policy: value } ) }
			/>
		</div>
	);
}
