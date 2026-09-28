jQuery( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.dicex-connect-wrap .nav-tab', function ( event ) {
		event.preventDefault();
		var $tab = $( this );
		var tab = $tab.data( 'tab' );
		$tab.siblings().removeClass( 'nav-tab-active' );
		$tab.addClass( 'nav-tab-active' );
		$( '#dicex-connect-tab-content' ).addClass( 'is-loading' ).html( '<div class="dicex-connect-loader"><span class="dashicons dashicons-update"></span></div>' );
		call( 'dicex_connect_load_tab', { tab: tab }, function ( html ) {
			$( '#dicex-connect-tab-content' ).removeClass( 'is-loading' ).html( html );
			initChannelLists();
			// A tab with a script of its own sets itself up on this.
			$( document ).trigger( 'dicex-connect-tab-loaded', [ tab ] );
		}, function ( message ) {
			var $notice = $( '<div class="notice notice-error"><p></p></div>' );

			$notice.find( 'p' ).text( message );
			$( '#dicex-connect-tab-content' ).removeClass( 'is-loading' ).empty().append( $notice );
		} );
		window.history.replaceState( {}, '', $tab.attr( 'href' ) );
	} );

	// A button anywhere in the page can hand the user to another tab — the credit
	// figure on the Connection tab uses it to reach the top-up form.
	$( document ).on( 'click', '.dicex-connect-goto-tab', function () {
		var target = $( this ).data( 'goto' );
		var $tab = $( '.dicex-connect-wrap .nav-tab[data-tab="' + target + '"]' );

		// The button goes away with the tab it sits in; the new tab takes its focus.
		$tab.trigger( 'click' ).trigger( 'focus' );
	} );

	function call( action, data, done, fail ) {
		data = $.extend( { action: action, nonce: DiceXAdmin.nonce }, data );
		$.post( DiceXAdmin.ajaxUrl, data )
			.done( function ( res ) {
				if ( res && res.success ) {
					done( res.data );
				} else {
					fail( res && res.data ? res.data : DiceXAdmin.i18n.error );
				}
			} )
			.fail( function () {
				fail( DiceXAdmin.i18n.error );
			} );
	}

	// --- Connection tab: verify API key ---
	$( document ).on( 'click', '#dicex-connect-verify-key', function () {
		var $btn = $( this );
		var $status = $( '#dicex-connect-verify-status' );
		var apiKey = $( '#dicex-connect-api-key' ).val();

		$btn.prop( 'disabled', true ).text( DiceXAdmin.i18n.checking );
		$status.removeClass( 'dashicons-yes-alt dashicons-warning dicex-connect-status-ok dicex-connect-status-error' );

		call(
			'dicex_connect_verify_key',
			{ api_key: apiKey },
			function ( data ) {
				$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.checkConn );
				$status.addClass( 'dashicons dashicons-yes-alt dicex-connect-status-ok' );
				$( '#dicex-connect-account-info' ).addClass( 'is-visible' );
				$( '#dicex-connect-info-credit' ).text( data.credit );
			},
			function ( message ) {
				$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.checkConn );
				$status.addClass( 'dashicons dashicons-warning dicex-connect-status-error' );
				window.alert( message );
			}
		);
	} );

	// --- Connection tab: the name used in outgoing messages ---
	$( document ).on( 'change blur', '#dicex-connect-message-name', function () {
		var $field = $( this );

		call(
			'dicex_connect_save_message_name',
			{ name: $field.val() },
			function ( data ) {
				$field.val( data.name );
				$( '#dicex-connect-message-name-saved' ).stop( true, true ).show().delay( 1200 ).fadeOut();
			},
			function ( message ) {
				window.alert( message );
			}
		);
	} );
	// --- Connection tab: where the account operates ---
	// The region decides what every other tab offers, so the page is loaded again
	// rather than half of it patched in place.
	/*
	 * The three send options save the moment they change, like the region above.
	 * Each sends only its own field, so the handler can tell which one moved.
	 */
	$( document ).on(
		'change',
		'#dicex-connect-otp-length, #dicex-connect-voice-lang, #dicex-connect-message-lang, #dicex-connect-rich-messages',
		function () {
			var $field = $( this );
			var id = $field.attr( 'id' );
			var key = {
				'dicex-connect-otp-length': 'otp_length',
				'dicex-connect-voice-lang': 'voice_lang',
				'dicex-connect-message-lang': 'message_lang',
				'dicex-connect-rich-messages': 'rich_messages'
			}[ id ];
			var payload = {};

			payload[ key ] = $field.is( ':checkbox' )
				? ( $field.is( ':checked' ) ? '1' : '0' )
				: $field.val();

			$field.prop( 'disabled', true );

			call(
				'dicex_connect_save_send_options',
				payload,
				function () {
					$field.prop( 'disabled', false );
					$( '#' + id + '-saved' ).show().delay( 1500 ).fadeOut();
				},
				function ( message ) {
					$field.prop( 'disabled', false );
					window.alert( message );
				}
			);
		}
	);

	$( document ).on( 'change', '#dicex-connect-region', function () {
		var $select = $( this );

		$select.prop( 'disabled', true );

		call(
			'dicex_connect_save_region',
			{ region: $select.val() },
			function () {
				$( '#dicex-connect-region-saved' ).show();
				window.location.reload();
			},
			function ( message ) {
				$select.prop( 'disabled', false );
				window.alert( message );
			}
		);
	} );
	// --- Connection tab: manual language override ---
	// The page is already rendered in the old language, so the only honest way to
	// show the new one is to load it again.
	// --- Lines tab: keep the "Lines in use" row in step with what was just saved ---
	// One table now covers SMS and the social networks, so both save paths land here.
	function updateSummary( channel, line, label ) {
		/*
		 * The test box names the same states as the table, so it has to move with
		 * it — saving a line and then finding the test box still offering the old
		 * wording is what sent somebody to the reload button. Both wordings were
		 * rendered onto the option in PHP, which is what keeps translated text out
		 * of this file.
		 *
		 * Done before the table, and on its own, so a channel that has an option
		 * but no row still gets its label put right.
		 */
		var $option = $( '#dicex-connect-test-channel option' ).filter( function () {
			return this.value === channel;
		} );

		if ( $option.length ) {
			var swapped = $option.attr( line ? 'data-label-set' : 'data-label-unset' );

			if ( swapped ) {
				$option.text( swapped );
			}
		}

		var $row = $( '.dicex-connect-social-summary tr[data-channel="' + channel + '"]' );
		var $cell = $row.find( '.dicex-connect-summary-line' );

		if ( ! $cell.length ) {
			return;
		}

		$cell.empty();

		var shared = ( DiceXAdmin.sharedLineChannels || [] ).indexOf( channel ) !== -1;

		if ( line ) {
			$cell.append( $( '<span>' ).addClass( 'dicex-connect-summary-number' ).attr( 'dir', 'ltr' ).text( line ) );

			if ( label ) {
				$cell.append( ' ' ).append( $( '<em>' ).addClass( 'dicex-connect-summary-name' ).text( label ) );
			}
		} else if ( shared ) {
			$cell.append( $( '<span>' ).addClass( 'dicex-connect-summary-default' ).text( DiceXAdmin.i18n.sharedLine ) );
		} else {
			$cell.append( $( '<span>' ).addClass( 'dicex-connect-summary-missing' ).text( DiceXAdmin.i18n.noLineYet ) );
		}

		/*
		 * The tick means the channel will send. Clearing a line off SMS, voice or
		 * Safir leaves the DiceX shared line behind it, so the row is still fine;
		 * doing the same to a messenger channel leaves it with nothing, and the
		 * row has to stop claiming otherwise.
		 */
		var ready = !! line || shared;

		$row.toggleClass( 'is-configured', ready );
		$row.toggleClass( 'is-unset', ! ready );
		$row.find( '.dicex-connect-summary-state .dashicons' )
			.attr( 'class', 'dashicons ' + ( ready ? 'dashicons-yes-alt' : 'dashicons-warning' ) );
	}

	// --- Lines tab: persist the chosen line for a channel ---
	function saveLine( $input, done, fail ) {
		var channel = $input.data( 'channel' );

		if ( ! channel ) {
			if ( done ) {
				done();
			}
			return;
		}

		call(
			'dicex_connect_save_line',
			{ channel: channel, line: $input.val() },
			function () {
				var $icon = $( '.dicex-connect-lines-table tr[data-channel="' + channel + '"] .dicex-connect-line-saved' );
				$icon.stop( true, true ).show().delay( 1200 ).fadeOut();
				updateSummary( channel, $input.val(), '' );
				if ( done ) {
					done();
				}
			},
			function ( message ) {
				if ( fail ) {
					fail( message );
				} else {
					window.alert( message );
				}
			}
		);
	}

	$( document ).on( 'blur', 'input.dicex-connect-line-input', function () {
		saveLine( $( this ) );
	} );

	$( document ).on( 'change', 'select.dicex-connect-line-input', function () {
		saveLine( $( this ) );
	} );

	// --- Test send ---
	$( document ).on( 'click', '#dicex-connect-test-send', function () {
		var $btn = $( this );
		var channel = $( '#dicex-connect-test-channel' ).val();
		var target = $( '#dicex-connect-test-target' ).val();
		var $result = $( '#dicex-connect-test-result' );
		var $line = $( '.dicex-connect-lines-table tr[data-channel="' + channel + '"] .dicex-connect-line-input' );

		$btn.prop( 'disabled', true ).text( DiceXAdmin.i18n.sending );
		$result.removeClass( 'notice notice-success notice-error' ).text( '' );

		function fail( message ) {
			$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.sendTest );
			$result.addClass( 'notice notice-error' ).text( message );
		}

		function send() {
			call(
				'dicex_connect_test_send',
				{ channel: channel, target: target },
				function ( message ) {
					$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.sendTest );
					$result.addClass( 'notice notice-success' ).text( message );
				},
				fail
			);
		}

		// The test must use the line that is on screen right now. Saving it first
		// avoids racing the blur/change-triggered save when the user picks a line
		// and clicks straight through to this button.
		if ( $line.length ) {
			saveLine( $line, send, fail );
		} else {
			send();
		}
	} );

	// --- Credit tab: what the amount is worth elsewhere ---
	// The rates come from PHP and are units of another currency per one of this
	// region's, so this is a multiplication and nothing more. The sentence is
	// translated server-side and arrives with its placeholders intact.
	function updateWorth() {
		var $text = $( '#dicex-connect-credit-worth-text' );
		var rates = DiceXAdmin.rates || {};

		if ( ! $text.length || ! rates.USD ) {
			return;
		}

		var amount = parseFloat( ( $( '#dicex-connect-charge-amount' ).val() || '' ).toString().replace( /[^0-9.]/g, '' ) );

		// Nothing typed yet is not nothing to say: fall back to the smallest
		// top-up, which is what the line showed when the page arrived.
		if ( ! amount || amount <= 0 ) {
			amount = parseFloat( DiceXAdmin.minCharge );
		}

		var here = amount + ' ' + ( DiceXAdmin.currency || '' );
		var usd = ( DiceXAdmin.i18n.worthUsd || '' ).replace( '%s', ( amount * rates.USD ).toFixed( 2 ) );
		var eur = ( DiceXAdmin.i18n.worthEur || '' ).replace( '%s', ( amount * rates.EUR ).toFixed( 2 ) );

		/*
		 * Built as nodes rather than assembled into a string of markup: the two
		 * converted figures are emphasised, and text() on each piece is what
		 * keeps a translation from ever being read as HTML.
		 */
		$text.empty();

		( DiceXAdmin.i18n.worth || '' ).split( /(%[123]\$s)/ ).forEach( function ( piece ) {
			if ( '%1$s' === piece ) {
				$text.append( document.createTextNode( here ) );
			} else if ( '%2$s' === piece ) {
				$text.append( $( '<strong>' ).text( usd ) );
			} else if ( '%3$s' === piece ) {
				$text.append( $( '<strong>' ).text( eur ) );
			} else if ( piece ) {
				$text.append( document.createTextNode( piece ) );
			}
		} );
	}

	$( document ).on( 'input', '#dicex-connect-charge-amount', updateWorth );

	// --- Credit tab: quick-amount buttons + online charge request ---
	$( document ).on( 'click', '.dicex-connect-credit-quick', function () {
		$( '.dicex-connect-credit-quick' ).removeClass( 'is-chosen' );
		$( this ).addClass( 'is-chosen' );
		$( '#dicex-connect-charge-amount' ).val( $( this ).data( 'amount' ) );
		updateWorth();
	} );

	$( document ).on( 'click', '#dicex-connect-credit-custom', function () {
		$( '.dicex-connect-credit-quick' ).removeClass( 'is-chosen' );
		$( '#dicex-connect-charge-amount' ).val( '' ).trigger( 'focus' );
		updateWorth();
	} );

	$( document ).on( 'click', '#dicex-connect-charge-submit', function () {
		var $btn = $( this );
		var amount = ( $( '#dicex-connect-charge-amount' ).val() || '' ).toString().replace( /[^0-9]/g, '' );
		var $result = $( '#dicex-connect-charge-result' );

		$result.removeClass( 'notice notice-success notice-error' ).text( '' );

		// Say what is wrong here rather than sending a doomed request and showing
		// whatever the gateway happens to answer back with.
		if ( ! amount || parseInt( amount, 10 ) < DiceXAdmin.minCharge ) {
			$result.addClass( 'notice notice-error' ).text( DiceXAdmin.i18n.minAmount );
			return;
		}

		$btn.prop( 'disabled', true ).text( DiceXAdmin.i18n.sending );

		// One radio is checked from the start, so this is only ever empty if the
		// markup changed; the server falls back to the default either way.
		var provider = $( '.dicex-connect-gateway-input:checked' ).val() || '';

		call(
			'dicex_connect_request_charge',
			{ amount: amount, provider: provider },
			function ( data ) {
				if ( ! data || ! data.url ) {
					$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.payOnline );
					$result.addClass( 'notice notice-error' ).text( DiceXAdmin.i18n.error );
					return;
				}

				var method = ( data.method || 'GET' ).toUpperCase();

				if ( 'POST' === method && data.formData ) {
					var $form = $( '#dicex-connect-charge-redirect-form' ).attr( 'action', data.url ).attr( 'method', 'POST' ).empty();
					$.each( data.formData, function ( key, value ) {
						$form.append( $( '<input>' ).attr( 'type', 'hidden' ).attr( 'name', key ).val( value ) );
					} );
					$form.trigger( 'submit' );
				} else {
					window.location.href = data.url;
				}
			},
			function ( message ) {
				$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.payOnline );
				$result.addClass( 'notice notice-error' ).text( message );
			}
		);
	} );

	// --- Social lines: provider -> its lines -> the line for that channel ---
	$( document ).on( 'change', '#dicex-connect-social-provider', function () {
		var $provider = $( this );
		var provider = $provider.val();
		var channel = $provider.find( 'option:selected' ).data( 'channel' );
		var $lines = $( '#dicex-connect-social-lines' );

		$lines.prop( 'disabled', true ).empty().append( $( '<option>' ).text( DiceXAdmin.i18n.loading ) );

		if ( ! provider ) {
			$lines.empty().append( $( '<option>' ).text( DiceXAdmin.i18n.chooseProvider ) );
			return;
		}

		// Preselect whatever is already saved for this channel, read off the summary row.
		var current = $( '.dicex-connect-social-summary tr[data-channel="' + channel + '"] .dicex-connect-summary-number' ).text();

		call(
			'dicex_connect_social_lines',
			{ provider: provider },
			function ( lines ) {
				$lines.empty().append( $( '<option>' ).val( '' ).text( DiceXAdmin.i18n.chooseLine ) );

				$.each( lines, function ( index, line ) {
					var text = line.name ? line.name + ' — ' + line.lineNumber : line.lineNumber;
					var $option = $( '<option>' ).val( line.lineNumber ).attr( 'data-label', line.name ).text( text );

					if ( line.lineNumber === current ) {
						$option.prop( 'selected', true );
					}

					$lines.append( $option );
				} );

				$lines.prop( 'disabled', false );
			},
			function ( message ) {
				$lines.empty().append( $( '<option>' ).text( message ) );
			}
		);
	} );

	$( document ).on( 'change', '#dicex-connect-social-lines', function () {
		var $lines = $( this );
		var provider = $( '#dicex-connect-social-provider' ).val();
		var label = $lines.find( 'option:selected' ).attr( 'data-label' ) || '';

		if ( ! provider ) {
			return;
		}

		call(
			'dicex_connect_save_social_line',
			{ provider: provider, line: $lines.val(), label: label },
			function ( data ) {
				updateSummary( data.channel, data.line, data.label );

				$( '#dicex-connect-social-saved' ).stop( true, true ).show().delay( 1200 ).fadeOut();
			},
			function ( message ) {
				window.alert( message );
			}
		);
	} );

	// --- Integrations tab: a switch that cannot be turned on yet ---
	// It is aria-disabled rather than disabled, so pressing it — with the mouse,
	// or Space from the keyboard — can say why, on the card that was pressed. The
	// reason is the one at the top of the tab, copied as text, never as markup.
	$( document ).on( 'click', '.dicex-connect-integration-toggle[aria-disabled="true"]', function ( event ) {
		var $card = $( this ).closest( '.dicex-connect-integration-card' );
		var $link = $( '#dicex-connect-enable-block a' ).first();
		var text = String( $( '#dicex-connect-enable-block-text' ).text() || '' ).trim();

		// The box stays unticked, and no change event is sent to the server.
		event.preventDefault();

		if ( '' === text ) {
			return;
		}

		if ( ! $card.find( '.dicex-connect-integration-blocked' ).length ) {
			// Text and link in one span, so they wrap as one sentence beside the icon.
			var $words = $( '<span>' ).text( text );

			if ( $link.length ) {
				$words.append( ' ' ).append( $( '<a>' ).attr( 'href', $link.attr( 'href' ) ).text( $link.text() ) );
			}

			$card.find( '.dicex-connect-integration-head' ).after(
				$( '<p>' ).addClass( 'dicex-connect-integration-warning dicex-connect-integration-blocked' )
					.append( $( '<span>' ).addClass( 'dashicons dashicons-warning' ).attr( 'aria-hidden', 'true' ) )
					.append( $words )
			);
		}

		if ( window.wp && wp.a11y ) {
			wp.a11y.speak( text, 'assertive' );
		}
	} );

	// --- Integrations tab: card switches ---
	$( document ).on( 'change', '.dicex-connect-integration-toggle', function () {
		var $toggle = $( this );
		var $card = $toggle.closest( '.dicex-connect-integration-card' );
		var enabled = $toggle.is( ':checked' );

		// The click handler above keeps a blocked switch from changing; this is
		// only for a browser that sends the change anyway.
		if ( 'true' === $toggle.attr( 'aria-disabled' ) ) {
			$toggle.prop( 'checked', false );
			return;
		}

		call(
			'dicex_connect_toggle_integration',
			{ slug: $toggle.data( 'slug' ), enabled: enabled ? '1' : '0' },
			function ( data ) {
				$card.toggleClass( 'is-enabled', !! data.enabled );
				$card.find( '.dicex-connect-integration-settings' ).prop( 'hidden', ! data.enabled );

				// Say straight away when a card was switched on but cannot do anything
				// yet — an on switch with no effect is the worst thing to leave silent.
				var $status = $card.find( '.dicex-connect-integration-status' ).empty();

				$.each( data.notes || [], function ( index, note ) {
					var $note = $( '<p>' ).addClass( 'dicex-connect-integration-warning' )
						.append( $( '<span>' ).addClass( 'dashicons dashicons-warning' ) )
						.append( document.createTextNode( note.text || '' ) );

					// Built as a node with text(), never as a string of markup: the
					// words come from a translation and must never be read as HTML.
					if ( note.url ) {
						$note.append( ' ' ).append(
							$( '<a>' ).attr( 'href', note.url ).text( note.label || note.url )
						);
					}

					$status.append( $note );
				} );
			},
			function ( message ) {
				// Put the switch back where it was: the server did not accept the change.
				$toggle.prop( 'checked', ! enabled );
				window.alert( message );
			}
		);
	} );

	// --- Integrations tab: one card's settings ---
	$( document ).on( 'click', '.dicex-connect-save-integration', function () {
		var $btn = $( this );
		var $card = $btn.closest( '.dicex-connect-integration-card' );
		// The list is the priority: ticked channels, in the order they appear.
		var channels = [];

		$card.find( '.dicex-connect-channel-list .dicex-connect-integration-channel:checked' ).each( function () {
			channels.push( $( this ).val() );
		} );
		var recipients = [];

		$card.find( '.dicex-connect-integration-recipient:checked' ).each( function () {
			recipients.push( $( this ).val() );
		} );

		// Extra fields the card declared for itself, keyed by fieldset — the server
		// keeps only values that are in that field's own option list.
		var fields = {};

		$card.find( '.dicex-connect-field-set' ).each( function () {
			var $set = $( this );
			var values = [];

			$set.find( '.dicex-connect-integration-field:checked' ).each( function () {
				values.push( $( this ).val() );
			} );

			fields[ $set.data( 'field' ) ] = values;
		} );

		$card.find( '.dicex-connect-integration-field-select' ).each( function () {
			var $select = $( this );

			fields[ $select.data( 'field' ) ] = $select.val();
		} );

		$btn.prop( 'disabled', true ).text( DiceXAdmin.i18n.saving );

		call(
			'dicex_connect_save_integration',
			{
				slug: $card.data( 'slug' ),
				channels: channels,
				recipients: recipients,
				template: $card.find( '.dicex-connect-integration-template' ).val(),
				fields: fields
			},
			function () {
				$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.saveCard );
				$card.find( '.dicex-connect-line-saved' ).stop( true, true ).show().delay( 1200 ).fadeOut();
			},
			function ( message ) {
				$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.saveCard );
				window.alert( message );
			}
		);
	} );

	// --- Integrations tab: the shared admin number list ---
	$( document ).on( 'click', '#dicex-connect-save-recipients', function () {
		var $btn = $( this );
		var $field = $( '#dicex-connect-admin-recipients' );
		var $status = $( '#dicex-connect-recipients-status' );

		$btn.prop( 'disabled', true ).text( DiceXAdmin.i18n.saving );

		call(
			'dicex_connect_save_admin_recipients',
			{ numbers: $field.val() },
			function ( data ) {
				$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.saveNumbers );
				// Show back exactly what was stored, so an invalid number visibly drops out.
				$field.val( data.numbers.join( String.fromCharCode( 10 ) ) );
				$status.text( data.message );
			},
			function ( message ) {
				$btn.prop( 'disabled', false ).text( DiceXAdmin.i18n.saveNumbers );
				$status.text( message );
			}
		);
	} );

	// Channel priority lists become drag-to-reorder. jQuery UI sortable ships with
	// WordPress, so nothing extra is loaded for it.
	function initChannelLists() {
		$( '.dicex-connect-channel-list' ).each( function () {
			var $list = $( this );

			markChannelEnds( $list );

			if ( ! $.fn.sortable || $list.data( 'dicex-sortable' ) ) {
				return;
			}

			$list.sortable( {
				handle: '.dicex-connect-channel-handle',
				axis: 'y',
				containment: 'parent',
				tolerance: 'pointer',
				update: function () {
					markChannelEnds( $list );
				}
			} );

			$list.data( 'dicex-sortable', true );
		} );
	}

	/*
	 * The arrows beside each channel do what dragging does, for anybody not using
	 * a mouse. The first row cannot go up nor the last down; they say so rather
	 * than being disabled, which would drop the focus they hold.
	 */
	function markChannelEnds( $list ) {
		var $rows = $list.children( 'li' );

		$rows.find( '.dicex-connect-channel-up, .dicex-connect-channel-down' ).removeAttr( 'aria-disabled' );
		$rows.first().find( '.dicex-connect-channel-up' ).attr( 'aria-disabled', 'true' );
		$rows.last().find( '.dicex-connect-channel-down' ).attr( 'aria-disabled', 'true' );
	}

	$( document ).on( 'click', '.dicex-connect-channel-up, .dicex-connect-channel-down', function () {
		var $button = $( this );
		var $row = $button.closest( 'li' );
		var $list = $row.parent();

		if ( 'true' === $button.attr( 'aria-disabled' ) ) {
			return;
		}

		if ( $button.hasClass( 'dicex-connect-channel-up' ) ) {
			$row.prev( 'li' ).before( $row );
		} else {
			$row.next( 'li' ).after( $row );
		}

		markChannelEnds( $list );
		$button.trigger( 'focus' );

		if ( window.wp && wp.a11y && DiceXAdmin.i18n.channelPosition ) {
			wp.a11y.speak(
				DiceXAdmin.i18n.channelPosition
					.replace( '%1$s', $.trim( $row.find( 'label' ).text() ) )
					.replace( '%2$s', String( $row.index() + 1 ) )
					.replace( '%3$s', String( $list.children( 'li' ).length ) )
			);
		}
	} );

	// --- Support tab: a message to DiceX support ---
	// Where it goes, how often, and what travels with it are the server's rules.
	// This only stops a request that cannot succeed because a field is empty.
	$( document ).on( 'click', '#dicex-connect-support-send', function () {
		var $btn = $( this );
		var $result = $( '#dicex-connect-support-result' );
		var fields = [
			$( '#dicex-connect-support-email' ),
			$( '#dicex-connect-support-subject' ),
			$( '#dicex-connect-support-message' )
		];
		var $empty = null;

		// aria-disabled rather than disabled, so the button keeps its focus.
		if ( 'true' === $btn.attr( 'aria-disabled' ) ) {
			return;
		}

		function show( type, text ) {
			$result.attr( 'class', 'notice notice-' + type ).empty().append( $( '<p>' ).text( text ) );

			if ( window.wp && wp.a11y ) {
				wp.a11y.speak( String( text ), 'error' === type ? 'assertive' : 'polite' );
			}
		}

		function done() {
			$btn.removeAttr( 'aria-disabled' ).text( DiceXAdmin.i18n.supportSend );
		}

		$.each( fields, function ( i, $field ) {
			var blank = '' === String( $field.val() || '' ).trim();

			$field.attr( 'aria-invalid', blank ? 'true' : null );

			if ( blank && ! $empty ) {
				$empty = $field;
			}
		} );

		if ( $empty ) {
			show( 'error', DiceXAdmin.i18n.supportFill );
			$empty.trigger( 'focus' );
			return;
		}

		$btn.attr( 'aria-disabled', 'true' ).text( DiceXAdmin.i18n.sending );

		call(
			'dicex_connect_support_send',
			{
				email: fields[0].val(),
				subject: fields[1].val(),
				message: fields[2].val(),
				attach_log: $( '#dicex-connect-support-log' ).is( ':checked' ) ? '1' : '0'
			},
			function ( data ) {
				done();
				// Emptied, so a second click cannot send the same message twice.
				fields[1].val( '' );
				fields[2].val( '' );
				show( 'success', data.text );
			},
			function ( message ) {
				done();
				show( 'error', message );
			}
		);
	} );

	$( initChannelLists );
} );
