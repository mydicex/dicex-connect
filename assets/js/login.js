/**
 * The countdown on the two-step login screen.
 *
 * Everything here is display only. The server decides when a code has expired
 * and when a new one may be sent; this just stops people staring at a form with
 * no idea how long they have.
 *
 * Times arrive as server timestamps together with the server's own clock, so a
 * visitor whose device clock is wrong still sees the right number of seconds.
 */
( function () {
	'use strict';

	if ( typeof window.DiceXLogin === 'undefined' ) {
		return;
	}

	var data = window.DiceXLogin;
	var timerEl = document.getElementById( 'dicex-connect-otp-timer' );
	var resendBtn = document.getElementById( 'dicex-connect-resend' );
	var resendEl = document.getElementById( 'dicex-connect-resend-status' );

	if ( ! timerEl && ! resendBtn ) {
		return;
	}

	/*
	 * Writes a sentence with its %1$s and %2$s filled in, each inside its own
	 * <strong> so the numbers and the channel name stand out from the words
	 * around them.
	 *
	 * Built as text nodes rather than innerHTML. The values here are a clock
	 * string this file generates and a channel name from the plugin's own list,
	 * so neither is dangerous today — but a template that renders as markup is
	 * one careless change away from being an injection, and appendChild simply
	 * cannot be.
	 */
	function fill( el, template, values ) {
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}

		// Split on the placeholders while keeping them, so each can be replaced.
		var parts = String( template ).split( /(%[12]\$s|%s)/ );

		parts.forEach( function ( part ) {
			if ( '' === part ) {
				return;
			}

			var index = { '%s': 0, '%1$s': 0, '%2$s': 1 }[ part ];

			if ( undefined === index ) {
				el.appendChild( document.createTextNode( part ) );
				return;
			}

			var value = values[ index ];

			if ( undefined === value || '' === value ) {
				return;
			}

			var strong = document.createElement( 'strong' );
			strong.textContent = value;
			el.appendChild( strong );
		} );
	}

	// Offset between the server's clock and this browser's, fixed at load.
	var offset = data.now - Math.floor( Date.now() / 1000 );

	function serverNow() {
		return Math.floor( Date.now() / 1000 ) + offset;
	}

	function clock( seconds ) {
		var m = Math.floor( seconds / 60 );
		var s = seconds % 60;

		return m + ':' + ( s < 10 ? '0' + s : s );
	}

	function tick() {
		var now = serverNow();
		var codeLeft = data.expires - now;
		var resendLeft = data.resendAt - now;

		if ( timerEl ) {
			if ( data.expires > 0 && codeLeft > 0 ) {
				fill( timerEl, data.i18n.validFor, [ clock( codeLeft ) ] );
				timerEl.classList.remove( 'is-expired' );
			} else {
				timerEl.textContent = data.i18n.expired;
				timerEl.classList.add( 'is-expired' );
			}
		}

		/*
		 * The countdown goes in its own line, not into the button. A button sizes
		 * itself to its label and does not wrap, so a whole sentence in there
		 * pushed it straight out of the box it sits in.
		 */
		if ( resendBtn ) {
			resendBtn.disabled = resendLeft > 0;
			resendBtn.textContent = data.i18n.resendReady;
		}

		if ( resendEl ) {
			if ( resendLeft > 0 ) {
				fill( resendEl, data.i18n.resendIn, [ clock( resendLeft ), data.channel ] );
			} else {
				resendEl.textContent = '';
			}
		}

		// Nothing left to count once the code is gone and a resend is allowed.
		if ( codeLeft <= 0 && resendLeft <= 0 ) {
			window.clearInterval( handle );
		}
	}

	var handle = window.setInterval( tick, 1000 );
	tick();
} )();
