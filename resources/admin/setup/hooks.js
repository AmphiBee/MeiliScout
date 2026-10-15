import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Whether the visitor asked for less motion: the animations then jump to their end.
 *
 * @return {boolean} True when motion should be minimal.
 */
export const useReducedMotion = () => {
	const [ reduced, setReduced ] = useState(
		() =>
			window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ??
			false
	);

	useEffect( () => {
		const query = window.matchMedia?.( '(prefers-reduced-motion: reduce)' );
		if ( ! query ) {
			return undefined;
		}
		const onChange = () => setReduced( query.matches );
		query.addEventListener( 'change', onChange );

		return () => query.removeEventListener( 'change', onChange );
	}, [] );

	return reduced;
};

/**
 * A number counting up to its target, so that a figure that changes feels alive.
 *
 * @param {number} target   Where it ends.
 * @param {number} duration Milliseconds.
 * @return {number} The number to show now.
 */
export const useCountUp = ( target, duration = 900 ) => {
	const reduced = useReducedMotion();
	const [ value, setValue ] = useState( reduced ? target : 0 );
	const from = useRef( 0 );

	useEffect( () => {
		if ( reduced ) {
			setValue( target );
			return undefined;
		}

		const start = performance.now();
		const origin = from.current;
		let frame;

		const tick = ( now ) => {
			const progress = Math.min( 1, ( now - start ) / duration );
			// Ease out: fast at first, then settling
			const eased = 1 - Math.pow( 1 - progress, 3 );
			const current = Math.round( origin + ( target - origin ) * eased );
			from.current = current;
			setValue( current );
			if ( progress < 1 ) {
				frame = window.requestAnimationFrame( tick );
			}
		};
		frame = window.requestAnimationFrame( tick );

		return () => window.cancelAnimationFrame( frame );
	}, [ target, duration, reduced ] );

	return value;
};

/**
 * Text typed one letter at a time, over and over through a list of phrases.
 *
 * @param {Array<string>} phrases
 * @param {Object}        options
 * @param {number}        options.speed Milliseconds per letter.
 * @param {number}        options.hold  Milliseconds a finished phrase stays.
 * @return {Object} { text, index, done } The text so far, the phrase it belongs to, whether it is complete.
 */
export const useTyping = ( phrases, { speed = 85, hold = 2200 } = {} ) => {
	const reduced = useReducedMotion();
	const [ state, setState ] = useState( {
		index: 0,
		length: reduced ? phrases[ 0 ].length : 0,
	} );

	useEffect( () => {
		const phrase = phrases[ state.index ];
		const done = state.length >= phrase.length;
		const timer = window.setTimeout(
			() =>
				setState(
					done
						? {
								index: ( state.index + 1 ) % phrases.length,
								length: 0,
						  }
						: { ...state, length: state.length + 1 }
				),
			done ? hold : speed
		);

		return () => window.clearTimeout( timer );
	}, [ state, phrases, speed, hold ] );

	const phrase = phrases[ state.index ];

	return {
		text: phrase.slice( 0, state.length ),
		index: state.index,
		done: state.length >= phrase.length,
	};
};
