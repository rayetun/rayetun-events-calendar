import { useState, useRef, useEffect } from '@wordpress/element';
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, useApp } from '../lib';
import { ErrorNote } from './ui';

/** Statuses whose holders are expecting to come; the server uses the same. */
const EXPECTING = [ 'confirmed', 'pending', 'waitlisted' ];

/**
 * How many people a message would reach, by date: one per email address,
 * however many places or dates they booked.
 *
 * @param {Object} data The registrations response.
 * @return {Object} { all: number, byDate: { [start]: number } }
 */
export function peopleToWrite( data ) {
	const upcoming = new Set(
		data.dates.filter( ( date ) => date.upcoming ).map( ( d ) => d.start )
	);
	const all = new Set();
	const byDate = {};
	data.registrations.forEach( ( row ) => {
		if ( ! upcoming.has( row.start ) || ! EXPECTING.includes( row.status ) ) {
			return;
		}
		const email = row.email.toLowerCase();
		all.add( email );
		( byDate[ row.start ] = byDate[ row.start ] || new Set() ).add( email );
	} );
	return {
		all: all.size,
		byDate: Object.fromEntries(
			Object.entries( byDate ).map( ( [ start, set ] ) => [
				start,
				set.size,
			] )
		),
	};
}

const people = ( count ) =>
	sprintf(
		/* translators: %d: number of people. */
		_n( '%d person', '%d people', count, 'rayetun-events-calendar' ),
		count
	);

/**
 * Write to the people booked: everyone on the coming dates, or one date.
 *
 * News about the event they booked (a new room, parking, what to bring).
 * Each copy is plain text, starts with the person's name and ends with what
 * they booked, so nobody wonders why it came.
 *
 * @param {Object}   props
 * @param {number}   props.eventId Event ID.
 * @param {Object}   props.data    The registrations response.
 * @param {Function} props.onDone  Called after sending or cancelling, with true when sent.
 */
export default function MessagePeople( { eventId, data, onDone } ) {
	const { notify } = useApp();
	const counts = peopleToWrite( data );
	const [ start, setStart ] = useState( '' );
	const [ subject, setSubject ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const first = useRef();

	useEffect( () => {
		const field = first.current && first.current.querySelector( 'select' );
		if ( field ) {
			field.focus();
		}
	}, [] );

	const reach = start ? counts.byDate[ start ] || 0 : counts.all;
	const options = [
		{
			label: sprintf(
				/* translators: %s: number of people, such as "8 people". */
				__(
					'Everyone booked on a coming date (%s)',
					'rayetun-events-calendar'
				),
				people( counts.all )
			),
			value: '',
		},
		...data.dates
			.filter( ( date ) => date.upcoming && counts.byDate[ date.start ] )
			.map( ( date ) => ( {
				label: sprintf(
					/* translators: 1: date and time, 2: number of people, such as "8 people". */
					__( '%1$s (%2$s)', 'rayetun-events-calendar' ),
					date.label,
					people( counts.byDate[ date.start ] )
				),
				value: date.start,
			} ) ),
	];

	const send = () => {
		if ( ! subject.trim() || ! message.trim() ) {
			setError(
				__(
					'Add a subject and a message before sending.',
					'rayetun-events-calendar'
				)
			);
			return;
		}
		setBusy( true );
		setError( '' );
		api.post( `/events/${ eventId }/message`, { start, subject, message } )
			.then( ( result ) => {
				notify(
					result.queued
						? sprintf(
								/* translators: 1: number of people, 2: emails sent so far. */
								__(
									'Sending to %1$s: %2$d have gone, and the rest go over the next few minutes.',
									'rayetun-events-calendar'
								),
								people( result.people ),
								result.sent
						  )
						: sprintf(
								/* translators: %s: number of people, such as "8 people". */
								__( 'Sent to %s.', 'rayetun-events-calendar' ),
								people( result.sent )
						  )
				);
				onDone( true );
			} )
			.catch( ( e ) => {
				setError( e.message );
				setBusy( false );
			} );
	};

	return (
		// A group, not a form: it sits inside the event drawer's own form.
		<div
			className="rayec-message"
			role="group"
			aria-labelledby={ `rayec-message-title-${ eventId }` }
		>
			<h5
				className="rayec-message__title"
				id={ `rayec-message-title-${ eventId }` }
			>
				{ __( 'Email the people booked', 'rayetun-events-calendar' ) }
			</h5>
			<p className="rayec-message__intro">
				{ __(
					'For news about this event, such as a new room, parking or what to bring. Each person gets their own copy, starting with their name and ending with the dates they booked, the event page and how to cancel.',
					'rayetun-events-calendar'
				) }
			</p>
			{ error && <ErrorNote message={ error } /> }
			<div ref={ first }>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Send to', 'rayetun-events-calendar' ) }
					value={ start }
					options={ options }
					onChange={ setStart }
				/>
			</div>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Subject', 'rayetun-events-calendar' ) }
				value={ subject }
				maxLength={ 150 }
				onChange={ setSubject }
				onKeyDown={ ( keyEvent ) => {
					// Enter here would save the event around it.
					if ( 'Enter' === keyEvent.key ) {
						keyEvent.preventDefault();
					}
				} }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Message', 'rayetun-events-calendar' ) }
				help={ __(
					'Plain text. Replies go to the event’s organiser when it has an email address.',
					'rayetun-events-calendar'
				) }
				value={ message }
				rows={ 6 }
				maxLength={ 5000 }
				onChange={ setMessage }
			/>
			<div className="rayec-message__actions">
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! reach }
					accessibleWhenDisabled
					onClick={ send }
				>
					{ sprintf(
						/* translators: %s: number of people, such as "8 people". */
						__( 'Send to %s', 'rayetun-events-calendar' ),
						people( reach )
					) }
				</Button>
				<Button
					variant="tertiary"
					disabled={ busy }
					onClick={ () => onDone( false ) }
				>
					{ __( 'Cancel', 'rayetun-events-calendar' ) }
				</Button>
			</div>
		</div>
	);
}
