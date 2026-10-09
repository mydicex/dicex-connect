/* global jQuery, DiceXAdmin, DiceXClub */
/**
 * The Customer Club tab.
 *
 * Kept apart from admin.js so nothing here can disturb the tabs that already
 * work. Every handler is delegated, because a tab arrives by AJAX; the tab is set
 * up again each time admin.js reports it loaded.
 *
 * Text from translations and from the server only ever reaches the page through
 * text() and val(), never as markup.
 */
jQuery( function ( $ ) {
	'use strict';

	var club = window.DiceXClub || {};
	var i18n = club.i18n || {};
	var lang = document.documentElement.lang || undefined;
	var pollTimer = null;
	var working = false;
	var polls = 0;
	var searchTimer = null;
	var productTimer = null;
	var levels = [];
	var members = { page: 1, pages: 0 };
	var previewRun = 0;
	// What the status said last time, so only a change is announced, never a poll.
	var lastStatus = null;
	// Something on the screen differs from what is saved. Leaving then asks first.
	var dirty = false;
	var revision = 0;
	// The setup step the last refusal named; putting that step right takes the message away.
	var setupErrorStep = '';

	/*
	 * What a level's lists are chosen from. Asked for once per tab load; until it
	 * arrives a list button still works, and says so.
	 */
	var choices = null;
	var choicesError = '';
	var choicesWaiting = [];

	function root() {
		return $( '#dicex-connect-club' );
	}

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

	function number( value ) {
		return Number( value ).toLocaleString( lang );
	}

	/*
	 * A number as somebody typed it into a condition, read the way the server's
	 * parse_amount() reads it: Persian and Arabic-Indic digits, and the separators
	 * either keyboard puts in. NaN when it is still not a number.
	 */
	function typedNumber( value ) {
		var ascii = String( value )
			.replace( /[۰-۹]/g, function ( d ) {
				return String( d.charCodeAt( 0 ) - 0x06F0 );
			} )
			.replace( /[٠-٩]/g, function ( d ) {
				return String( d.charCodeAt( 0 ) - 0x0660 );
			} )
			.replace( /[,٬،\s  ]/g, '' )
			.replace( /٫/g, '.' );

		return '' === ascii ? NaN : Number( ascii );
	}

	// WordPress's own announcer, for a change a screen reader would otherwise miss.
	function speak( message ) {
		if ( message && window.wp && window.wp.a11y ) {
			window.wp.a11y.speak( String( message ) );
		}
	}

	/*
	 * A button at work says so with aria-disabled rather than disabled: disabling
	 * the button somebody has just pressed throws their keyboard focus back to the
	 * top of the page. Every handler checks isBusy() first.
	 */
	function setBusy( $button, on ) {
		$button.attr( 'aria-disabled', on ? 'true' : null );
	}

	function isBusy( $button ) {
		return 'true' === $button.attr( 'aria-disabled' );
	}

	// A refusal arrives as words, or as { step, message } when switching on was refused.
	function errorText( error ) {
		return error && 'object' === typeof error ? String( error.message || DiceXAdmin.i18n.error ) : String( error || DiceXAdmin.i18n.error );
	}

	/* ---- Unsaved changes ------------------------------------------------- */

	/*
	 * The Save button is far from most of what it saves, so the bar it sits in says
	 * when there is something to save, and leaving the tab or the page asks first.
	 */
	function markDirty() {
		if ( ! root().length ) {
			return;
		}

		dirty = true;
		// Counts every change, so a save can tell whether anything moved while it ran.
		revision++;
		$( '#dicex-connect-club-unsaved' ).prop( 'hidden', false );
		$( '#dicex-connect-club-savebar' ).addClass( 'is-dirty' );
		// The bar now sticks to the bottom; a field the keyboard reaches must not land under it.
		$( document.documentElement ).addClass( 'dicex-connect-club-dirty' );
	}

	function markClean() {
		dirty = false;
		$( '#dicex-connect-club-unsaved' ).prop( 'hidden', true );
		$( '#dicex-connect-club-savebar' ).removeClass( 'is-dirty' );
		$( document.documentElement ).removeClass( 'dicex-connect-club-dirty' );
	}

	/* ---- Choices for the lists ------------------------------------------- */

	function loadChoices() {
		if ( ! club.woo || ! root().length ) {
			return;
		}

		function ready( data ) {
			choices = {
				categories: data.categories || [],
				brands: data.brands || [],
				countries: data.countries || [],
				products: data.products || {}
			};

			$( '.dicex-connect-club-entry' ).each( function () {
				updateSummaries( $( this ) );
			} );

			var waiting = choicesWaiting;

			choicesWaiting = [];
			waiting.forEach( function ( callback ) {
				callback();
			} );
		}

		// A failed request still settles everything: what is chosen shows by id, and an
		// open picker says what went wrong instead of loading for ever.
		call( 'dicex_connect_club_lists', {}, ready, function ( message ) {
			choicesError = message;
			ready( {} );
		} );
	}

	function whenChoices( callback ) {
		if ( choices ) {
			callback();
		} else {
			choicesWaiting.push( callback );
		}
	}

	function choiceName( key, id ) {
		if ( ! choices ) {
			return String( id );
		}

		if ( 'products' === key ) {
			return choices.products[ id ] || ( '#' + id );
		}

		if ( 'cities' === key ) {
			return String( id );
		}

		var found = ( choices[ key ] || [] ).filter( function ( item ) {
			return String( item.id ) === String( id );
		} );

		if ( found.length ) {
			return found[ 0 ].name;
		}

		// A country code still says something; a term id that is gone does not.
		return 'countries' === key ? String( id ) : '#' + id;
	}

	/* ---- Levels and groups ----------------------------------------------- */

	/*
	 * A level and a group are edited the same way: a name, the same conditions, a
	 * description. What differs is that levels are a list in order and hold a
	 * message, and that a customer is in every group they match.
	 */
	function listId( kind ) {
		return 'group' === kind ? '#dicex-connect-club-groups' : '#dicex-connect-club-levels';
	}

	function rowsOf( kind ) {
		return $( listId( kind ) ).children( '.dicex-connect-club-' + kind );
	}

	function levelRows() {
		return rowsOf( 'level' );
	}

	function kindOf( $row ) {
		return $row.hasClass( 'dicex-connect-club-group' ) ? 'group' : 'level';
	}

	function newId() {
		return 'lvl_' + Math.random().toString( 36 ).slice( 2, 12 );
	}

	function listInput( $row, key ) {
		return $row.find( '.dicex-connect-club-input[data-list="1"][data-key="' + key + '"]' );
	}

	function readList( $input ) {
		try {
			var value = JSON.parse( $input.val() || '[]' );

			return Array.isArray( value ) ? value : [];
		} catch ( e ) {
			return [];
		}
	}

	function writeList( $row, key, values ) {
		listInput( $row, key ).val( JSON.stringify( values ) );
		updateSummaries( $row );
		updateTags( kindOf( $row ) );
		clearPreview();
		markDirty();
	}

	function readMap( $input ) {
		try {
			var value = JSON.parse( $input.val() || '{}' );

			return value && 'object' === typeof value && ! Array.isArray( value ) ? value : {};
		} catch ( e ) {
			return {};
		}
	}

	/*
	 * @param {string}  kind  'level' or 'group'.
	 * @param {Object}  entry What the row holds; empty for a new one.
	 * @param {boolean} open  Show its conditions: a new row is opened to be filled
	 *                        in, a saved one arrives closed, as its summary.
	 */
	function addRow( kind, entry, open ) {
		var template = document.getElementById( 'dicex-connect-club-' + kind + '-template' );

		if ( ! template || ! template.content ) {
			return null;
		}

		var $row = $( document.importNode( template.content, true ) ).children( '.dicex-connect-club-' + kind ).first();

		entry = entry || {};
		$row.attr( 'data-id', entry.id || newId() );
		$row.find( '.dicex-connect-club-input' ).each( function () {
			var $input = $( this );
			var key = String( $input.attr( 'data-key' ) );
			var value = entry[ key ];

			if ( '1' === $input.attr( 'data-list' ) ) {
				$input.val( JSON.stringify( Array.isArray( value ) ? value : [] ) );
			} else if ( '1' === $input.attr( 'data-map' ) ) {
				$input.val( JSON.stringify( value && 'object' === typeof value ? value : {} ) );
			} else {
				$input.val( undefined !== value && null !== value ? value : '' );
			}
		} );

		$( listId( kind ) ).append( $row );
		updateSummaries( $row );
		drawMessages( $row );
		setOpen( $row, !! open );

		return $row;
	}

	function addLevel( level, open ) {
		return addRow( 'level', level, open );
	}

	/* ---- A row closed to one line, or open for editing ------------------ */

	function isOpen( $row ) {
		return ! $row.children( '.dicex-connect-club-level-body' ).prop( 'hidden' );
	}

	function setOpen( $row, open ) {
		var $body = $row.children( '.dicex-connect-club-level-body' );
		var $edit = $row.find( '.dicex-connect-club-edit' ).first();

		// Its id follows the row's, so the button can say which part of the page it opens.
		$body.attr( 'id', 'dicex-connect-club-body-' + kindOf( $row ) + '-' + $row.attr( 'data-id' ) ).prop( 'hidden', ! open );
		$edit.attr( { 'aria-expanded': open ? 'true' : 'false', 'aria-controls': $body.attr( 'id' ) } );
		$edit.find( '.dicex-connect-club-edit-text' ).text( open ? i18n.close : i18n.edit );
		$edit.find( '.dashicons' ).attr( 'class', 'dashicons ' + ( open ? 'dashicons-arrow-up-alt2' : 'dashicons-edit' ) );
		$row.toggleClass( 'is-open', open );
		labelEdit( $row );

		if ( ! open ) {
			closePanel( $row );
		}
	}

	/*
	 * The button's name starts with the word it shows — "Edit Gold", "Close Gold" —
	 * so somebody who says what they see by voice reaches it.
	 */
	function labelEdit( $row ) {
		var name = String( $row.find( '.dicex-connect-club-input[data-key="name"]' ).val() || '' ).trim() || i18n.unnamedLevel;

		$row.find( '.dicex-connect-club-edit' ).first().attr(
			'aria-label',
			( ( isOpen( $row ) ? i18n.closeNamed : i18n.editNamed ) || '%s' ).replace( '%s', name )
		);
	}

	function openIds( kind ) {
		return rowsOf( kind ).filter( function () {
			return isOpen( $( this ) );
		} ).map( function () {
			return String( $( this ).attr( 'data-id' ) );
		} ).get();
	}

	$( document ).on( 'click', '.dicex-connect-club-edit', function () {
		var $row = $( this ).closest( '.dicex-connect-club-entry' );
		var open = ! isOpen( $row );

		setOpen( $row, open );

		// Opening takes the keyboard to the first condition; closing leaves it on the button.
		if ( open ) {
			$row.children( '.dicex-connect-club-level-body' ).find( 'input:not([type="hidden"]), textarea, button' ).first().trigger( 'focus' );
		}
	} );

	/*
	 * What a row's conditions add up to, in one line, so a closed row still says
	 * what it does: "Orders: 10 or more · Buyer's country: Oman". The labels are the
	 * ones already on the fields, so nothing is worded twice.
	 */
	function rangeText( label, min, max, unit ) {
		var withUnit = function ( value ) {
			var parsed = typedNumber( value );

			// Kept whole and in its own direction: "۲۰۰ OMR" must not split in a Persian line.
			// Something that is not a number yet is shown as typed; saving will say why.
			return '⁨' + ( isNaN( parsed ) ? String( value ) : number( parsed ) ) + ( unit ? ' ' + unit : '' ) + '⁩';
		};

		if ( '' !== min && '' !== max ) {
			return typedNumber( min ) === typedNumber( max )
				? ( i18n.rangeExact || '%1$s: %2$s' ).replace( '%1$s', label ).replace( '%2$s', withUnit( min ) )
				: ( i18n.rangeBoth || '%1$s: %2$s – %3$s' ).replace( '%1$s', label ).replace( '%2$s', withUnit( min ) ).replace( '%3$s', withUnit( max ) );
		}

		if ( '' !== min ) {
			return ( i18n.rangeMin || '%1$s: %2$s+' ).replace( '%1$s', label ).replace( '%2$s', withUnit( min ) );
		}

		return ( i18n.rangeMax || '%1$s: ≤ %2$s' ).replace( '%1$s', label ).replace( '%2$s', withUnit( max ) );
	}

	function summarize( $row ) {
		var parts = [];
		var $summary = $row.children( '.dicex-connect-club-level-summary' );

		// The level for people who leave has no conditions to sum up; its hint says why.
		if ( 'level' === kindOf( $row ) && $row.attr( 'data-id' ) === ( $( '#dicex-connect-club-removed' ).val() || null ) ) {
			$summary.addClass( 'is-empty' ).text( '' ).prop( 'hidden', true );
			return;
		}

		$summary.prop( 'hidden', false );

		$row.find( '.dicex-connect-club-bound' ).each( function () {
			var $bound = $( this );
			var $inputs = $bound.find( '.dicex-connect-club-input' );
			var min = String( $inputs.eq( 0 ).val() || '' ).trim();
			var max = String( $inputs.eq( 1 ).val() || '' ).trim();
			// Only money carries its unit here: days and years are in the label already.
			var unit = 'spent_min' === $inputs.eq( 0 ).attr( 'data-key' ) ? String( club.currency || '' ) : '';

			if ( '' === min && '' === max ) {
				return;
			}

			parts.push( rangeText( $bound.find( '.dicex-connect-club-bound-label' ).first().text().trim(), min, max, unit ) );
		} );

		$row.find( '.dicex-connect-club-list-button[data-list]' ).each( function () {
			var $button = $( this );
			var text = $button.find( '.dicex-connect-club-list-summary' ).text();

			if ( ! $button.hasClass( 'is-set' ) || '' === text ) {
				return;
			}

			parts.push( ( i18n.listPart || '%1$s: %2$s' ).replace( '%1$s', $button.find( '.dicex-connect-club-list-name' ).text().trim() ).replace( '%2$s', text ) );
		} );

		$summary.toggleClass( 'is-empty', ! parts.length ).text( parts.length ? parts.join( ' · ' ) : i18n.noConditions );

		// A message for moving up is worth knowing about without opening the row.
		if ( '' !== String( $row.find( '.dicex-connect-club-input[data-key="message"]' ).val() || '' ).trim() ) {
			$summary.append( ' ' ).append(
				$( '<span>' ).addClass( 'dicex-connect-club-summary-message' )
					.append( $( '<span>' ).addClass( 'dashicons dashicons-email-alt' ).attr( 'aria-hidden', 'true' ) )
					.append( document.createTextNode( i18n.sendsMessage ) )
			);
		}
	}

	function summarizeAll() {
		$( '.dicex-connect-club-entry' ).each( function () {
			summarize( $( this ) );
		} );
	}

	function collect( kind ) {
		return rowsOf( kind ).map( function () {
			var $row = $( this );
			var entry = { id: String( $row.attr( 'data-id' ) || '' ) };

			$row.find( '.dicex-connect-club-input' ).each( function () {
				var $input = $( this );
				var key = String( $input.attr( 'data-key' ) );

				if ( '1' === $input.attr( 'data-list' ) ) {
					entry[ key ] = readList( $input );
				} else if ( '1' === $input.attr( 'data-map' ) ) {
					entry[ key ] = readMap( $input );
				} else {
					entry[ key ] = $input.val();
				}
			} );

			return entry;
		} ).get();
	}

	function collectLevels() {
		return collect( 'level' );
	}

	function fillSelect( $select, list, emptyLabel, fallbackToLast ) {
		if ( ! $select.length ) {
			return;
		}

		var current = $select.data( 'ready' ) ? ( $select.val() || '' ) : ( $select.attr( 'data-selected' ) || '' );
		var found = false;

		$select.empty();

		if ( null !== emptyLabel ) {
			$select.append( $( '<option>' ).val( '' ).text( emptyLabel ) );
		}

		list.forEach( function ( level ) {
			$select.append( $( '<option>' ).val( level.id ).text( level.name || i18n.unnamedLevel ) );
			found = found || level.id === current;
		} );

		if ( found ) {
			$select.val( current );
		} else if ( fallbackToLast && list.length ) {
			$select.val( list[ list.length - 1 ].id );
		} else {
			$select.val( '' );
		}

		$select.data( 'ready', true );
	}

	function refreshLevelSelects() {
		var list = collectLevels();

		levels = list.map( function ( level ) {
			return { id: level.id, name: level.name };
		} );

		fillSelect( $( '#dicex-connect-club-default' ), list, list.length ? null : i18n.addLevelFirst, true );
		fillSelect( $( '#dicex-connect-club-removed' ), list, i18n.noRemovedLevel, false );

		$( '#dicex-connect-club-starter' ).prop( 'hidden', list.length > 0 );

		updateLevelTags();
		summarizeAll();
	}

	/*
	 * The tags say which level is the default and which holds removed numbers.
	 * The hint says out loud what the order implies: a level with no conditions
	 * takes everybody who reaches it, so nothing below it is reached by rules.
	 */
	function updateTags( kind ) {
		if ( 'group' === kind ) {
			updateGroupTags();
			return;
		}

		updateLevelTags();
	}

	/*
	 * A group with no conditions would hold every customer, which is what the
	 * levels are for, so saving refuses it. The row says so before that.
	 */
	/*
	 * A row's buttons say which level or group they act on, and the hint under a
	 * row is tied to its name, so it is heard where the name is. Both follow the
	 * name as it is typed.
	 */
	function describeRow( $row ) {
		var $name = $row.find( '.dicex-connect-club-input[data-key="name"]' );
		var name = String( $name.val() || '' ).trim() || i18n.unnamedLevel;
		var hintId = 'dicex-connect-club-hint-' + kindOf( $row ) + '-' + $row.attr( 'data-id' );

		$row.find( '.dicex-connect-club-up' ).attr( 'aria-label', ( i18n.moveUpNamed || '%s' ).replace( '%s', name ) );
		$row.find( '.dicex-connect-club-down' ).attr( 'aria-label', ( i18n.moveDownNamed || '%s' ).replace( '%s', name ) );
		$row.find( '.dicex-connect-club-remove' ).attr( 'aria-label', ( i18n.removeItem || '%s' ).replace( '%s', name ) );
		labelEdit( $row );
		$row.find( '.dicex-connect-club-level-hint' ).attr( 'id', hintId );
		$name.attr( 'aria-describedby', hintId );
	}

	function updateGroupTags() {
		rowsOf( 'group' ).each( function () {
			var $row = $( this );
			var hint = '';

			describeRow( $row );

			if ( ! hasConditions( $row ) ) {
				hint = i18n.hintGroupEmpty;
			} else if ( asksAge( $row ) && ! knowsBirthdays() ) {
				hint = i18n.hintAgeUnknown;
			}

			$row.find( '.dicex-connect-club-level-hint' ).text( hint ).prop( 'hidden', '' === hint );
		} );
	}

	function asksAge( $row ) {
		return $row.find( '.dicex-connect-club-input[data-key="age_min"], .dicex-connect-club-input[data-key="age_max"]' ).filter( function () {
			return '' !== String( $( this ).val() ).trim();
		} ).length > 0;
	}

	/*
	 * Whether the club can know anybody's date of birth: it asks for one, or reads
	 * one another plugin keeps. Without either, no age condition can be met.
	 */
	function knowsBirthdays() {
		return $( '#dicex-connect-club-birthday' ).is( ':checked' ) || '' !== String( $( '#dicex-connect-club-birthday-key' ).val() || '' ).trim();
	}

	function hasConditions( $row ) {
		return $row.find( '.dicex-connect-club-input' ).filter( function () {
			var $input = $( this );
			var key = $input.attr( 'data-key' );

			if ( '1' === $input.attr( 'data-list' ) ) {
				return readList( $input ).length > 0;
			}

			if ( '1' === $input.attr( 'data-map' ) || 'name' === key || 'description' === key || 'message' === key ) {
				return false;
			}

			return '' !== String( $input.val() ).trim();
		} ).length > 0;
	}

	function updateLevelTags() {
		var defaultId = $( '#dicex-connect-club-default' ).val();
		var removedId = $( '#dicex-connect-club-removed' ).val();
		var $rows = levelRows();
		var lastActive = -1;

		$rows.each( function ( index ) {
			if ( $( this ).attr( 'data-id' ) !== removedId ) {
				lastActive = index;
			}
		} );

		// The top level cannot go up, nor the bottom one down. They say so, and stay
		// focusable: a disabled button would drop the focus it holds.
		$rows.find( '.dicex-connect-club-up, .dicex-connect-club-down' ).removeAttr( 'aria-disabled' );
		$rows.first().find( '.dicex-connect-club-up' ).attr( 'aria-disabled', 'true' );
		$rows.last().find( '.dicex-connect-club-down' ).attr( 'aria-disabled', 'true' );

		$rows.each( function ( index ) {
			var $row = $( this );
			var id = $row.attr( 'data-id' );
			var $tags = $row.find( '.dicex-connect-club-level-tags' ).empty();
			var hint = '';
			var conditions = hasConditions( $row );

			describeRow( $row );

			if ( id === defaultId ) {
				$tags.append( $( '<span>' ).addClass( 'dicex-connect-club-tag' ).text( i18n.tagDefault ) );
			}

			if ( id === removedId ) {
				$tags.append( $( '<span>' ).addClass( 'dicex-connect-club-tag is-removed' ).text( i18n.tagRemoved ) );
				hint = i18n.hintRemoved;
			} else if ( ! conditions && index < lastActive ) {
				hint = i18n.hintNoConditions;
			} else if ( asksAge( $row ) && ! knowsBirthdays() ) {
				hint = i18n.hintAgeUnknown;
			}

			$row.toggleClass( 'is-removed', id === removedId );
			$row.find( '.dicex-connect-club-level-hint' ).text( hint ).prop( 'hidden', '' === hint );
		} );
	}

	/* ---- A level's lists ------------------------------------------------- */

	// [data-list]: the "In another language" buttons share the look of these, not their behaviour.
	function updateSummaries( $row ) {
		$row.find( '.dicex-connect-club-list-button[data-list]' ).each( function () {
			var $button = $( this );
			var key = $button.attr( 'data-list' );
			var values = readList( listInput( $row, key ) );
			var text = '';

			if ( ! choices && 'cities' !== key && values.length ) {
				// The names are still on their way, and bare ids mean nothing to anyone.
				text = '…';
			} else {
				// Each name keeps its own direction, between FIRST STRONG ISOLATE and POP
				// DIRECTIONAL ISOLATE: a Persian city among English words would otherwise
				// carry the separator and the "+1 more" across to its side.
				text = values.slice( 0, 2 ).map( function ( id ) {
					return '\u2068' + choiceName( key, id ) + '\u2069';
				} ).join( i18n.listSeparator || ', ' );

				if ( values.length > 2 ) {
					text += ' ' + ( i18n.more || '+%s' ).replace( '%s', number( values.length - 2 ) );
				}
			}

			$button.toggleClass( 'is-set', values.length > 0 );
			$button.find( '.dashicons' ).attr( 'class', 'dashicons ' + ( values.length ? 'dashicons-edit' : 'dashicons-plus-alt2' ) );
			$button.find( '.dicex-connect-club-list-summary' ).text( text );
		} );

		summarize( $row );
	}

	function closePanel( $row ) {
		$row.find( '.dicex-connect-club-list-panel' ).prop( 'hidden', true ).empty().removeAttr( 'data-open' );
		$row.find( '.dicex-connect-club-list-button[data-list]' ).attr( 'aria-expanded', 'false' );
	}

	/*
	 * After a chip is taken away, the keyboard stays where it was: on the chip that
	 * slid into its place, the one before it, or the box to add another.
	 */
	function focusAfterChip( $chips, index, $fallback ) {
		var $buttons = $chips.find( '.dicex-connect-club-chip-remove' );

		if ( $buttons.length ) {
			$buttons.eq( Math.min( index, $buttons.length - 1 ) ).trigger( 'focus' );
		} else {
			$fallback.trigger( 'focus' );
		}
	}

	function chip( label, onRemove ) {
		return $( '<span>' ).addClass( 'dicex-connect-club-chip' )
			.append( $( '<span>' ).text( label ) )
			.append(
				$( '<button>' ).attr( 'type', 'button' ).addClass( 'button-link dicex-connect-club-chip-remove' )
					.attr( 'aria-label', ( i18n.removeItem || '%s' ).replace( '%s', label ) )
					.text( '×' )
					.on( 'click', onRemove )
			);
	}

	/*
	 * Categories, brands and countries: a filter box over a list of checkboxes.
	 * Terms keep their tree, indented by depth.
	 */
	function buildChecklist( $panel, $row, key, title ) {
		var items = choices[ key ] || [];
		var $filter = $( '<input>' ).attr( {
			type: 'search',
			placeholder: i18n.filter,
			'aria-label': ( i18n.filterList || '%s' ).replace( '%s', title )
		} ).addClass( 'dicex-connect-club-filter' );
		var $list = $( '<ul>' ).addClass( 'dicex-connect-club-checklist' );
		var parents = [];

		if ( ! items.length ) {
			$panel.append( $( '<p>' ).addClass( 'description' ).text( choicesError || ( 'countries' === key ? i18n.noChoices : i18n.noTerms ) ) );
			return;
		}

		var chosen = readList( listInput( $row, key ) ).map( String );

		items.forEach( function ( item ) {
			var depth = item.depth || 0;
			var $box = $( '<input>' ).attr( 'type', 'checkbox' ).val( item.id ).prop( 'checked', chosen.indexOf( String( item.id ) ) !== -1 );
			var $label = $( '<label>' ).append( $box ).append( ' ' ).append( document.createTextNode( item.name ) );

			// The tree is drawn by indenting; a screen reader is told which category
			// a subcategory sits in. Terms arrive parent first.
			parents[ depth ] = item.name;

			if ( depth > 0 && parents[ depth - 1 ] ) {
				$label.append( $( '<span>' ).addClass( 'screen-reader-text' ).text( ' ' + ( i18n.inParent || '%s' ).replace( '%s', parents[ depth - 1 ] ) ) );
			}

			$list.append(
				$( '<li>' ).attr( 'data-name', String( item.name ).toLowerCase() )
					.css( 'padding-inline-start', ( depth * 16 ) + 'px' )
					.append( $label )
			);
		} );

		$filter.on( 'input', function () {
			var needle = String( $filter.val() ).toLowerCase().trim();

			$list.children( 'li' ).each( function () {
				$( this ).prop( 'hidden', '' !== needle && String( $( this ).attr( 'data-name' ) ).indexOf( needle ) === -1 );
			} );
		} );

		$list.on( 'change', 'input[type="checkbox"]', function () {
			var values = $list.find( 'input:checked' ).map( function () {
				return 'countries' === key ? this.value : parseInt( this.value, 10 );
			} ).get();

			writeList( $row, key, values );
		} );

		$panel.append( $filter ).append( $list );
		$filter.trigger( 'focus' );
	}

	/* Products: search as you type, and the chosen ones as chips. */
	function buildProductPicker( $panel, $row ) {
		var $search = $( '<input>' ).attr( { type: 'search', placeholder: i18n.searchProducts, 'aria-label': i18n.searchProducts } ).addClass( 'dicex-connect-club-filter' );
		var $results = $( '<ul>' ).addClass( 'dicex-connect-club-results' );
		var $chips = $( '<div>' ).addClass( 'dicex-connect-club-chips' );

		function drawChips() {
			var values = readList( listInput( $row, 'products' ) );

			$chips.empty();

			values.forEach( function ( id, index ) {
				$chips.append(
					chip( choiceName( 'products', id ), function () {
						writeList( $row, 'products', readList( listInput( $row, 'products' ) ).filter( function ( other ) {
							return String( other ) !== String( id );
						} ) );
						drawChips();
						focusAfterChip( $chips, index, $search );
					} )
				);
			} );
		}

		$search.on( 'input', function () {
			var term = String( $search.val() ).trim();

			window.clearTimeout( productTimer );

			if ( term.length < 2 ) {
				$results.empty();
				return;
			}

			productTimer = window.setTimeout( function () {
				$results.empty().append( $( '<li>' ).addClass( 'description' ).text( DiceXAdmin.i18n.loading ) );

				call(
					'dicex_connect_club_products',
					{ term: term },
					function ( found ) {
						$results.empty();

						if ( ! found.length ) {
							$results.append( $( '<li>' ).addClass( 'description' ).text( i18n.noMatch ) );
							speak( i18n.noMatch );
							return;
						}

						found.forEach( function ( product ) {
							$results.append(
								$( '<li>' ).append(
									$( '<button>' ).attr( 'type', 'button' ).addClass( 'button-link' ).text( product.name ).on( 'click', function () {
										var values = readList( listInput( $row, 'products' ) );

										choices.products[ product.id ] = product.name;

										if ( values.map( String ).indexOf( String( product.id ) ) === -1 ) {
											values.push( product.id );
											writeList( $row, 'products', values );
										}

										drawChips();
										$search.val( '' ).trigger( 'focus' );
										$results.empty();
									} )
								)
							);
						} );
					},
					function ( message ) {
						$results.empty().append( $( '<li>' ).addClass( 'description' ).text( message ) );
						speak( message );
					}
				);
			}, 350 );
		} );

		drawChips();
		$panel.append( $chips ).append( $search ).append( $results );
		$search.trigger( 'focus' );
	}

	/*
	 * The same reduction as Dicex_Connect_Club_Settings::normalize_city(): case, spaces,
	 * hyphens, the zero-width non-joiner and the Arabic yeh and kaf do not tell cities
	 * apart. Saving folds such spellings into one; the picker refuses them up front.
	 */
	function cityKey( city ) {
		return String( city )
			.replace( /[يى]/g, 'ی' )
			.replace( /ك/g, 'ک' )
			.replace( /[\s‌‍‐‑\-_.'’]+/g, '' )
			.toLowerCase();
	}

	/* Cities: typed, one at a time, as chips. Every spelling a customer might use. */
	function buildCityPicker( $panel, $row ) {
		var $input = $( '<input>' ).attr( { type: 'text', placeholder: i18n.typeCity, 'aria-label': i18n.typeCity } ).addClass( 'dicex-connect-club-filter' );
		var $add = $( '<button>' ).attr( 'type', 'button' ).addClass( 'button button-small' ).text( i18n.add );
		var $chips = $( '<div>' ).addClass( 'dicex-connect-club-chips' );

		function drawChips() {
			$chips.empty();

			readList( listInput( $row, 'cities' ) ).forEach( function ( city, index ) {
				$chips.append(
					chip( city, function () {
						writeList( $row, 'cities', readList( listInput( $row, 'cities' ) ).filter( function ( other ) {
							return other !== city;
						} ) );
						drawChips();
						focusAfterChip( $chips, index, $input );
					} )
				);
			} );
		}

		function addCity() {
			var city = String( $input.val() ).replace( /\s+/g, ' ' ).trim();
			var key = cityKey( city );
			var values = readList( listInput( $row, 'cities' ) );
			var same = values.map( cityKey ).indexOf( key );

			if ( ! key ) {
				$input.val( '' ).trigger( 'focus' );
				return;
			}

			if ( -1 !== same ) {
				// Already listed in another form: point at it rather than add it twice,
				// and say so, since the outline is only seen.
				var $chip = $chips.children().eq( same ).addClass( 'is-duplicate' );

				speak( ( i18n.alreadyListed || '%s' ).replace( '%s', values[ same ] ) );

				window.setTimeout( function () {
					$chip.removeClass( 'is-duplicate' );
				}, 1500 );
			} else {
				values.push( city );
				writeList( $row, 'cities', values );
				drawChips();
			}

			$input.val( '' ).trigger( 'focus' );
		}

		$input.on( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				addCity();
			}
		} );

		$add.on( 'click', addCity );

		drawChips();
		$panel.append( $chips )
			.append( $( '<div>' ).addClass( 'dicex-connect-club-city-add' ).append( $input ).append( $add ) )
			.append( $( '<p>' ).addClass( 'description' ).text( i18n.cityHint ) );
		$input.trigger( 'focus' );
	}

	$( document ).on( 'click', '.dicex-connect-club-list-button[data-list]', function () {
		var $button = $( this );
		var $row = $button.closest( '.dicex-connect-club-entry' );
		var $panel = $row.find( '.dicex-connect-club-list-panel' );
		var key = $button.attr( 'data-list' );
		var title = $button.find( '.dicex-connect-club-list-name' ).text();

		if ( ! $panel.prop( 'hidden' ) && key === $panel.attr( 'data-open' ) ) {
			closePanel( $row );
			$button.trigger( 'focus' );
			return;
		}

		closePanel( $row );
		$button.attr( 'aria-expanded', 'true' );
		// Named for its list, so the picker inside is heard as that list's.
		$panel.attr( { 'data-open': key, role: 'group', 'aria-label': title } ).prop( 'hidden', false )
			.append( $( '<p>' ).addClass( 'dicex-connect-club-panel-title' ).text( title ) )
			.append( $( '<p>' ).addClass( 'description dicex-connect-club-panel-wait' ).text( DiceXAdmin.i18n.loading ) );

		whenChoices( function () {
			if ( key !== $panel.attr( 'data-open' ) ) {
				return;
			}

			$panel.find( '.dicex-connect-club-panel-wait' ).remove();

			if ( 'products' === key ) {
				buildProductPicker( $panel, $row );
			} else if ( 'cities' === key ) {
				buildCityPicker( $panel, $row );
			} else {
				buildChecklist( $panel, $row, key, title );
			}

			$panel.append(
				$( '<p>' ).addClass( 'dicex-connect-club-panel-actions' ).append(
					$( '<button>' ).attr( 'type', 'button' ).addClass( 'button button-small' ).text( i18n.done ).on( 'click', function () {
						closePanel( $row );
						$button.trigger( 'focus' );
					} )
				)
			);
		} );
	} );

	// Escape closes an open list and hands the keyboard back to its button.
	$( document ).on( 'keydown', '.dicex-connect-club-list-panel', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}

		var $panel = $( this );
		var $row = $panel.closest( '.dicex-connect-club-entry' );
		var $button = $row.find( '.dicex-connect-club-list-button[data-list="' + $panel.attr( 'data-open' ) + '"]' );

		event.preventDefault();
		closePanel( $row );
		$button.trigger( 'focus' );
	} );

	/* ---- Adding, removing and ordering levels ---------------------------- */

	function rebuildLevels( list, defaultId, removedId ) {
		// A row somebody had open stays open after a save draws them all again.
		var open = openIds( 'level' );

		$( '#dicex-connect-club-levels' ).empty();

		( list || [] ).forEach( function ( level ) {
			addLevel( level, open.indexOf( String( level.id ) ) !== -1 );
		} );

		$( '#dicex-connect-club-default' ).data( 'ready', false ).attr( 'data-selected', defaultId || '' );
		$( '#dicex-connect-club-removed' ).data( 'ready', false ).attr( 'data-selected', removedId || '' );

		refreshLevelSelects();

		var $filter = $( '#dicex-connect-club-filter-level' );
		var chosen = $filter.val();

		$filter.find( 'option' ).not( ':first' ).remove();

		levels.forEach( function ( level ) {
			$filter.append( $( '<option>' ).val( level.id ).text( level.name ) );
		} );

		$filter.val( chosen && $filter.find( 'option[value="' + chosen + '"]' ).length ? chosen : '' );
	}

	$( document ).on( 'click', '#dicex-connect-club-add-level', function () {
		// A new level opens, ready to be filled in.
		var $row = addLevel( {}, true );

		refreshLevelSelects();
		markDirty();
		refreshSetup();

		if ( $row ) {
			$row.find( '[data-key="name"]' ).trigger( 'focus' );
		}
	} );

	/*
	 * The ready-made set, from the Levels card or from the setup steps. Its levels
	 * arrive closed: each already says what it asks for, so there is nothing to
	 * fill in before switching the club on.
	 */
	$( document ).on( 'click', '.dicex-connect-club-use-starter', function () {
		var set = club.starter || {};
		var fromSteps = $( this ).closest( '.dicex-connect-club-setup' ).length > 0;

		// Only ever onto an empty list; both buttons hide once there are levels.
		if ( levelRows().length ) {
			return;
		}

		( set.levels || [] ).forEach( function ( level ) {
			addLevel( level, false );
		} );

		$( '#dicex-connect-club-default' ).data( 'ready', false ).attr( 'data-selected', set.default_level || '' );
		$( '#dicex-connect-club-removed' ).data( 'ready', false ).attr( 'data-selected', set.removed_level || '' );

		refreshLevelSelects();
		markDirty();
		refreshSetup();

		speak( ( i18n.levelsAdded || '%s' ).replace( '%s', levels.map( function ( level ) {
			return level.name;
		} ).join( i18n.listSeparator || ', ' ) ) );

		// The button is gone now. From the steps the keyboard goes on to the next
		// step; in the Levels card, to the first of the new levels.
		if ( fromSteps ) {
			nextStepFocus();
		} else {
			levelRows().first().find( '[data-key="name"]' ).trigger( 'focus' );
		}
	} );

	// "Build my own": one empty level, open, under Levels.
	$( document ).on( 'click', '.dicex-connect-club-build-own', function () {
		$( '#dicex-connect-club-add-level' ).trigger( 'click' );
	} );

	$( document ).on( 'click', '.dicex-connect-club-goto-levels', function () {
		var $title = $( '#dicex-connect-club-levels-title' );

		$title[ 0 ].scrollIntoView( { block: 'start' } );
		$title.trigger( 'focus' );
	} );

	$( document ).on( 'click', '.dicex-connect-club-remove', function () {
		var $row = $( this ).closest( '.dicex-connect-club-entry' );
		var kind = kindOf( $row );
		var $next = $row.next( '.dicex-connect-club-entry' );
		var $focus = $next.length ? $next : $row.prev( '.dicex-connect-club-entry' );

		$row.remove();
		markDirty();

		// The keyboard goes to the row that took its place, or to the button that adds one.
		if ( $focus.length ) {
			$focus.find( '[data-key="name"]' ).trigger( 'focus' );
		} else {
			$( 'group' === kind ? '#dicex-connect-club-add-group' : '#dicex-connect-club-add-level' ).trigger( 'focus' );
		}

		if ( 'group' === kind ) {
			updateGroupTags();
			return;
		}

		refreshLevelSelects();
		refreshSetup();
	} );

	$( document ).on( 'click', '.dicex-connect-club-up, .dicex-connect-club-down', function () {
		var $button = $( this );
		var $row = $button.closest( '.dicex-connect-club-level' );

		if ( isBusy( $button ) ) {
			return;
		}

		if ( $button.hasClass( 'dicex-connect-club-up' ) ) {
			$row.prev( '.dicex-connect-club-level' ).before( $row );
		} else {
			$row.next( '.dicex-connect-club-level' ).after( $row );
		}

		refreshLevelSelects();
		markDirty();
		$button.trigger( 'focus' );

		speak(
			( i18n.position || '%1$s' )
				.replace( '%1$s', String( $row.find( '[data-key="name"]' ).val() || '' ).trim() || i18n.unnamedLevel )
				.replace( '%2$s', number( $row.index() + 1 ) )
				.replace( '%3$s', number( levelRows().length ) )
		);
	} );

	$( document ).on( 'input', '#dicex-connect-club-levels .dicex-connect-club-input', function () {
		var key = $( this ).attr( 'data-key' );

		if ( 'name' === key ) {
			refreshLevelSelects();
			refreshSetup();
		} else {
			updateLevelTags();
			summarize( $( this ).closest( '.dicex-connect-club-entry' ) );
		}

		if ( 'name' !== key && 'description' !== key ) {
			clearPreview();
		}
	} );

	$( document ).on( 'input', '#dicex-connect-club-groups .dicex-connect-club-input', function () {
		updateGroupTags();

		if ( 'name' !== $( this ).attr( 'data-key' ) ) {
			summarize( $( this ).closest( '.dicex-connect-club-entry' ) );
		}

		if ( 'name' !== $( this ).attr( 'data-key' ) && 'description' !== $( this ).attr( 'data-key' ) ) {
			clearPreview();
		}
	} );

	function rebuildGroups( list ) {
		if ( ! $( '#dicex-connect-club-groups' ).length ) {
			return;
		}

		var open = openIds( 'group' );

		$( '#dicex-connect-club-groups' ).empty();

		( list || [] ).forEach( function ( group ) {
			addRow( 'group', group, open.indexOf( String( group.id ) ) !== -1 );
		} );

		updateGroupTags();
	}

	$( document ).on( 'click', '#dicex-connect-club-add-group', function () {
		var $row = addRow( 'group', {}, true );

		updateGroupTags();
		clearPreview();
		markDirty();

		if ( $row ) {
			$row.find( '[data-key="name"]' ).trigger( 'focus' );
		}
	} );

	/* ---- Messages in another language ------------------------------------ */

	function messageInput( $row ) {
		return $row.find( '.dicex-connect-club-input[data-map="1"]' ).first();
	}

	function drawMessages( $row ) {
		var $button = $row.find( '.dicex-connect-club-languages' );

		if ( ! $button.length ) {
			return;
		}

		var written = 0;
		var texts = readMap( messageInput( $row ) );

		Object.keys( texts ).forEach( function ( locale ) {
			if ( '' !== String( texts[ locale ] ).trim() ) {
				written += 1;
			}
		} );

		$button.find( '.dicex-connect-club-list-summary' ).text(
			written > 0 ? i18n.languagesWritten.replace( '%s', String( written ) ) : ''
		);

		$row.find( '.dicex-connect-club-language-panel textarea' ).each( function () {
			var $area = $( this );
			var locale = String( $area.attr( 'data-locale' ) );

			$area.val( undefined !== texts[ locale ] ? texts[ locale ] : '' );
		} );
	}

	$( document ).on( 'click', '.dicex-connect-club-languages', function () {
		var $button = $( this );
		var $panel = $button.closest( '.dicex-connect-club-entry, .dicex-connect-club-welcome' ).find( '.dicex-connect-club-language-panel' ).first();
		var open = $panel.prop( 'hidden' );

		$panel.prop( 'hidden', ! open );
		$button.attr( 'aria-expanded', open ? 'true' : 'false' );
	} );

	$( document ).on( 'input', '.dicex-connect-club-language-panel textarea', function () {
		var $area = $( this );
		var $row = $area.closest( '.dicex-connect-club-entry, .dicex-connect-club-welcome' );
		var $input = $row.find( '.dicex-connect-club-input[data-map="1"]' ).first();
		var texts = readMap( $input );

		texts[ String( $area.attr( 'data-locale' ) ) ] = $area.val();
		$input.val( JSON.stringify( texts ) );

		drawMessages( $row );
	} );

	// Asking for the date of birth, or not, decides whether an age can be known.
	$( document ).on( 'change input', '#dicex-connect-club-birthday, #dicex-connect-club-birthday-key', function () {
		updateLevelTags();
		updateGroupTags();
	} );

	$( document ).on( 'change', '#dicex-connect-club-default, #dicex-connect-club-removed', function () {
		updateLevelTags();
		summarizeAll();
		clearPreview();
		refreshSetup();
	} );

	$( document ).on( 'change', '#dicex-connect-club-window', clearPreview );

	// Adding, removing and reordering levels changes who lands where.
	$( document ).on( 'click', '#dicex-connect-club-add-level, .dicex-connect-club-use-starter, .dicex-connect-club-remove, .dicex-connect-club-up, .dicex-connect-club-down', function () {
		clearPreview();
	} );

	/* ---- Saving ---------------------------------------------------------- */

	/*
	 * Saves everything on the tab: levels, groups and settings. The Save button
	 * calls it, and so does switching the club on, so nobody has to know that the
	 * one waits for the other.
	 *
	 * @param {Function} [done] After a save that went through.
	 * @param {Function} [fail] With the reason, after one that did not.
	 * @return {boolean} False when a save is already under way and this one was not sent.
	 */
	function saveSettings( done, fail ) {
		var $button = $( '#dicex-connect-club-save' );
		var $status = $( '#dicex-connect-club-save-status' );
		var channels = [];

		if ( isBusy( $button ) ) {
			return false;
		}

		// The list is the priority: ticked channels, in the order they appear.
		$( '#dicex-connect-club .dicex-connect-channel-list .dicex-connect-club-channel:checked' ).each( function () {
			channels.push( this.value );
		} );

		setBusy( $button, true );
		$status.removeClass( 'is-error' ).text( DiceXAdmin.i18n.saving );

		var $templates = $( '#dicex-connect-club-templates' );
		var request = {
			levels: JSON.stringify( collectLevels() ),
			groups: JSON.stringify( collect( 'group' ) ),
			default_level: $( '#dicex-connect-club-default' ).val() || '',
			removed_level: $( '#dicex-connect-club-removed' ).val() || '',
			roles: $( '.dicex-connect-club-role:checked' ).map( function () {
				return this.value;
			} ).get(),
			guests: $( '#dicex-connect-club-guests' ).is( ':checked' ) ? '1' : '0',
			window_months: $( '#dicex-connect-club-window' ).val() || '0',
			fields: $( '.dicex-connect-club-field:checked' ).map( function () {
				return this.value;
			} ).get(),
			sync_mode: $( '.dicex-connect-club-mode:checked' ).val() || 'both',
			daily_time: $( '#dicex-connect-club-time' ).val() || '',
			welcome_enabled: $( '#dicex-connect-club-welcome' ).is( ':checked' ) ? '1' : '0',
			welcome_channels: channels,
			welcome_template: $( '#dicex-connect-club-template' ).val() || '',
			join: $( '.dicex-connect-club-join:checked' ).val() || '',
			consent_label: $( '#dicex-connect-club-consent-label' ).val() || '',
			birthday_enabled: $( '#dicex-connect-club-birthday' ).is( ':checked' ) ? '1' : '0',
			birthday_required: $( '#dicex-connect-club-birthday-required' ).is( ':checked' ) ? '1' : '0',
			birthday_calendar: $( '#dicex-connect-club-calendar' ).val() || 'auto',
			birthday_meta_key: $( '#dicex-connect-club-birthday-key' ).val() || ''
		};

		// On a site read in one language the other texts are not on the screen at
		// all. Leaving the field out is what tells the server to keep them.
		if ( $templates.length ) {
			request.welcome_templates = $templates.val() || '{}';
		}

		// What the screen looked like when this save left; see below.
		var sent = revision;

		call(
			'dicex_connect_club_save',
			request,
			function ( data ) {
				setBusy( $button, false );
				$status.text( data.message );
				clearPreview();

				/*
				 * Anything typed while the request was out is not in what was saved.
				 * Then the rows stay as they are on screen, and so does the warning
				 * about unsaved changes; the next save sends them. Otherwise the server
				 * settles ids, spelling and what exists, and the rows show what it kept.
				 */
				if ( revision === sent ) {
					markClean();
					rebuildLevels( data.levels, data.default_level, data.removed_level );
					rebuildGroups( data.groups );
				}

				refreshSetup();

				if ( data.suggest_import ) {
					$( '#dicex-connect-club-import-prompt' ).prop( 'hidden', false );
				}

				if ( '1' === root().attr( 'data-enabled' ) ) {
					refreshStatus( true );
				}

				loadMembers( members.page );

				if ( done ) {
					done( data );
				}
			},
			function ( message ) {
				setBusy( $button, false );
				$status.addClass( 'is-error' ).text( errorText( message ) );

				if ( fail ) {
					fail( message );
				}
			}
		);

		return true;
	}

	$( document ).on( 'click', '#dicex-connect-club-save', function () {
		saveSettings();
	} );

	/* ---- Switching the club on and off ----------------------------------- */

	/*
	 * Why switching on did not go ahead, said beside the steps — and the step it
	 * belongs to marked, so it is plain where to look. Never an alert.
	 */
	function showSetupError( error ) {
		var step = error && 'object' === typeof error ? String( error.step || '' ) : '';
		var $box = $( '#dicex-connect-club-setup-error' );

		$( '.dicex-connect-club-step' ).removeClass( 'is-error' );
		setupErrorStep = step;

		if ( step ) {
			$( '.dicex-connect-club-step[data-step="' + step + '"]' ).addClass( 'is-error' );
		}

		$box.text( errorText( error ) ).prop( 'hidden', false );
	}

	function clearSetupError() {
		setupErrorStep = '';
		$( '#dicex-connect-club-setup-error' ).prop( 'hidden', true ).text( '' );
		$( '.dicex-connect-club-step' ).removeClass( 'is-error' );
	}

	function setSwitchBusy( on ) {
		setBusy( $( '#dicex-connect-club-toggle' ), on );
		setBusy( $( '#dicex-connect-club-switch-on' ), on );
	}

	function switchIsBusy() {
		return isBusy( $( '#dicex-connect-club-toggle' ) ) || isBusy( $( '#dicex-connect-club-switch-on' ) );
	}

	/*
	 * Switching on saves first, then asks the server, which checks the steps again
	 * and — the first time — starts reading the store's customers in.
	 */
	function switchOn( $focusAfter ) {
		var $toggle = $( '#dicex-connect-club-toggle' );

		clearSetupError();
		setSwitchBusy( true );
		$toggle.prop( 'checked', true );

		/*
		 * @param {*}       error
		 * @param {boolean} said  The save's own status line has already read it out.
		 */
		function refused( error, said ) {
			setSwitchBusy( false );
			$toggle.prop( 'checked', false );
			showSetupError( error );

			if ( ! said ) {
				speak( errorText( error ) );
			}
		}

		function ask() {
			call(
				'dicex_connect_club_toggle',
				{ enabled: '1' },
				function ( data ) {
					setSwitchBusy( false );
					root().attr( 'data-enabled', '1' );
					$( '#dicex-connect-club-status' ).prop( 'hidden', false );
					$( '#dicex-connect-club-members' ).prop( 'hidden', false );
					$( '#dicex-connect-club-import-prompt' ).prop( 'hidden', ! data.needs_import );

					if ( data.status ) {
						renderStatus( data.status );
					}

					refreshSetup();
					speak( data.status && data.status.importing ? i18n.switchedOnReading : i18n.switchedOn );

					if ( $focusAfter && $focusAfter.length ) {
						$focusAfter.trigger( 'focus' );
					}

					refreshStatus( true );
					loadMembers( 1 );
				},
				refused
			);
		}

		// Nothing unsaved: straight to the server. Otherwise the settings go first,
		// and a refusal there is the reason given.
		if ( ! dirty ) {
			ask();
			return;
		}

		// A save already on its way: let it land, say so, and let this be pressed again.
		if ( ! saveSettings( ask, function ( error ) {
			refused( error, true );
		} ) ) {
			setSwitchBusy( false );
			$toggle.prop( 'checked', '1' === root().attr( 'data-enabled' ) );
			speak( DiceXAdmin.i18n.saving );
		}
	}

	function switchOff() {
		var $toggle = $( '#dicex-connect-club-toggle' );

		setSwitchBusy( true );

		call(
			'dicex_connect_club_toggle',
			{ enabled: '0' },
			function () {
				setSwitchBusy( false );
				root().attr( 'data-enabled', '0' );
				$( '#dicex-connect-club-status' ).prop( 'hidden', true );
				stopPolling();
				refreshSetup();
				speak( i18n.switchedOff );
			},
			function ( error ) {
				// Put the switch back: the server did not accept the change.
				setSwitchBusy( false );
				$toggle.prop( 'checked', true );
				showSetupError( error );
				speak( errorText( error ) );
			}
		);
	}

	$( document ).on( 'change', '#dicex-connect-club-toggle', function () {
		var $toggle = $( this );
		var enabled = $toggle.is( ':checked' );

		// Still answering the last flip: this one does not count.
		if ( switchIsBusy() ) {
			$toggle.prop( 'checked', ! enabled );
			return;
		}

		if ( enabled ) {
			switchOn( $toggle );
		} else {
			switchOff();
		}
	} );

	$( document ).on( 'click', '#dicex-connect-club-switch-on', function () {
		if ( ! switchIsBusy() ) {
			// The step's button is about to go; the keyboard waits on the step that follows.
			switchOn( $( '.dicex-connect-club-step[data-step="send"] .dicex-connect-club-step-title' ) );
		}
	} );

	/* ---- The setup steps ------------------------------------------------- */

	function stepState( $step, state ) {
		$step.removeClass( 'is-done is-current is-todo' ).addClass( 'is-' + state );
		$step.find( '.dicex-connect-club-step-state' ).first().text(
			'done' === state ? ' ' + i18n.stepDone : ( 'current' === state ? ' ' + i18n.stepNext : '' )
		);
	}

	/*
	 * Where the club stands, as the five steps: done, the one to do now, and the
	 * ones after it. Read from the page itself — the radios, the levels, the switch
	 * and the last status — so it follows every change without asking the server.
	 */
	function refreshSetup() {
		var $setup = $( '#dicex-connect-club-setup' );

		if ( ! $setup.length ) {
			return;
		}

		var imported = '1' === $setup.attr( 'data-imported' );
		var hold = '1' === $setup.attr( 'data-hold' );
		var importing = '1' === $setup.attr( 'data-importing' );
		var enabled = '1' === root().attr( 'data-enabled' );
		var rows = levelRows();
		var defaultId = $( '#dicex-connect-club-default' ).val() || '';
		var removedId = $( '#dicex-connect-club-removed' ).val() || '';
		var missing = [];

		// The same things saving refuses, so the step is never Done when the save is not.
		var names = levels.map( function ( level ) {
			return String( level.name || '' ).trim().toLowerCase();
		} );

		if ( names.indexOf( '' ) !== -1 ) {
			missing.push( i18n.missingNames );
		} else if ( names.some( function ( name, index ) {
			return names.indexOf( name ) !== index;
		} ) ) {
			missing.push( i18n.sameNames );
		}

		if ( rows.length && ! defaultId ) {
			missing.push( i18n.missingDefault );
		}

		if ( rows.length && ! removedId ) {
			missing.push( i18n.missingRemoved );
		} else if ( rows.length && removedId === defaultId ) {
			missing.push( i18n.sameDefaultRemoved );
		}

		var done = {
			connect: '1' === root().attr( 'data-has-key' ),
			join: $( '.dicex-connect-club-join:checked' ).length > 0,
			levels: rows.length > 0 && ! missing.length,
			'switch': enabled,
			send: imported && ! hold
		};
		var current = '';

		if ( setupErrorStep && done[ setupErrorStep ] ) {
			clearSetupError();
		}

		[ 'connect', 'join', 'levels', 'switch', 'send' ].forEach( function ( key ) {
			var $step = $setup.find( '.dicex-connect-club-step[data-step="' + key + '"]' );

			if ( done[ key ] ) {
				stepState( $step, 'done' );
			} else if ( ! current ) {
				current = key;
				stepState( $step, 'current' );
			} else {
				stepState( $step, 'todo' );
			}
		} );

		// The levels step: a button for the ready-made set, or the levels by name.
		$setup.find( '.dicex-connect-club-step-levels-none' ).prop( 'hidden', rows.length > 0 );
		$setup.find( '.dicex-connect-club-step-levels-some' ).prop( 'hidden', ! rows.length );
		$setup.find( '.dicex-connect-club-step-levels-list' ).text(
			( i18n.levelsNamed || '%s' ).replace( '%s', levels.map( function ( level ) {
				return '⁨' + ( level.name || i18n.unnamedLevel ) + '⁩';
			} ).join( i18n.listSeparator || ', ' ) )
		);
		$setup.find( '.dicex-connect-club-step-levels-missing' ).text( missing.join( ' ' ) ).prop( 'hidden', ! missing.length );

		// Switching on is offered once the steps before it are done. The switch at
		// the top does the same thing, and says what is missing if pressed early.
		$( '#dicex-connect-club-switch-on' ).prop( 'hidden', 'switch' !== current );

		// The last step: reading, then the counts and the button that sends.
		$setup.find( '.dicex-connect-club-step-progress' )
			.text( importing && lastStatus ? lastStatus.import_text : '' )
			.prop( 'hidden', ! importing );
		$( '#dicex-connect-club-import-step' ).prop( 'hidden', ! enabled || imported || importing );
		$( '#dicex-connect-club-release-step' ).prop( 'hidden', ! enabled || ! hold || importing );

		// Done once the first import has gone to DiceX; after that the steps step aside.
		$setup.prop( 'hidden', done.send );
	}

	// Where the keyboard goes when the step button just pressed has gone away.
	function nextStepFocus() {
		var $current = $( '.dicex-connect-club-step.is-current' ).first();
		var $target = $current.find( 'button:visible, input:visible' ).first();

		( $target.length ? $target : $current.find( '.dicex-connect-club-step-title' ).first() ).trigger( 'focus' );
	}

	/*
	 * The choice in the steps and the one under Club settings are the same choice.
	 * "input." because the fieldset around the settings' radios carries their class
	 * too, and a change bubbling up to it has no value.
	 */
	$( document ).on( 'change', 'input.dicex-connect-club-join-step', function () {
		$( 'input.dicex-connect-club-join[value="' + this.value + '"]' ).prop( 'checked', true ).trigger( 'change' );
		markDirty();
	} );

	$( document ).on( 'change', 'input.dicex-connect-club-join', function () {
		$( 'input.dicex-connect-club-join-step[value="' + this.value + '"]' ).prop( 'checked', true );
		$( '.dicex-connect-club-consent-label' ).prop( 'hidden', 'consent' !== this.value );
		refreshSetup();
	} );

	$( document ).on( 'click', '#dicex-connect-club-release-step', function () {
		$( '#dicex-connect-club-release' ).trigger( 'click', [ 'step' ] );
	} );

	$( document ).on( 'click', '#dicex-connect-club-import-step', function () {
		$( '#dicex-connect-club-import' ).trigger( 'click', [ 'step' ] );
	} );

	/* ---- Showing only what applies ---------------------------------------- */

	// Each of these opens what belongs to it, and closes it again, without a save.
	$( document ).on( 'change', '#dicex-connect-club-birthday', function () {
		$( '.dicex-connect-club-birthday-settings' ).prop( 'hidden', ! this.checked );
	} );

	$( document ).on( 'change', '#dicex-connect-club-welcome', function () {
		var $channels = $( '#dicex-connect-club .dicex-connect-club-channel' );

		$( '.dicex-connect-club-welcome-settings' ).prop( 'hidden', ! this.checked );

		// A welcome needs a channel to go over, and saving refuses one without: the
		// first in the list is ticked for a start, rather than left to a refusal.
		if ( this.checked && $channels.length && ! $channels.filter( ':checked' ).length ) {
			$channels.first().prop( 'checked', true );
		}
	} );

	$( document ).on( 'change', '.dicex-connect-club-mode', function () {
		$( '.dicex-connect-club-daily-time' ).prop( 'hidden', 'realtime' === this.value );
	} );

	/* ---- Noticing changes ------------------------------------------------- */

	$( document ).on( 'input change', '#dicex-connect-club .dicex-connect-club-settings :input', function () {
		var $field = $( this );

		// A filter box only narrows a list; what a list holds changes through writeList().
		if ( $field.hasClass( 'dicex-connect-club-filter' ) || $field.closest( '.dicex-connect-club-list-panel' ).length ) {
			return;
		}

		markDirty();
	} );

	// The welcome's channel order is part of what is saved — when an arrow actually moved something.
	$( document ).on( 'click', '#dicex-connect-club .dicex-connect-channel-up, #dicex-connect-club .dicex-connect-channel-down', function () {
		if ( ! isBusy( $( this ) ) ) {
			markDirty();
		}
	} );
	$( document ).on( 'sortupdate', '#dicex-connect-club .dicex-connect-channel-list', markDirty );

	// Another tab replaces this one without a page load: ask before work is lost.
	$( document ).on( 'dicex-connect-before-tab', function () {
		if ( ! dirty || ! root().length ) {
			return true;
		}

		if ( ! window.confirm( i18n.leaveUnsaved ) ) {
			return false;
		}

		markClean();
		return true;
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty && root().length ) {
			event.preventDefault();
			// Older browsers want a value here before they will ask.
			event.returnValue = '';
		}
	} );

	/* ---- Status ---------------------------------------------------------- */

	function renderStatus( data ) {
		var $status = $( '#dicex-connect-club-status' );
		var $counts = $( '#dicex-connect-club-level-counts' ).empty();

		[ 'total', 'synced', 'waiting', 'refused' ].forEach( function ( key ) {
			$status.find( '[data-status="' + key + '"]' ).text( data[ key ] );
		} );

		( data.levels || [] ).forEach( function ( level ) {
			$counts.append(
				$( '<li>' ).append( $( '<span>' ).text( level.name ) ).append( ' ' ).append( $( '<strong>' ).text( level.members ) )
			);
		} );

		var $groups = $( '#dicex-connect-club-group-counts' ).empty();

		( data.groups || [] ).forEach( function ( group ) {
			var $item = $( '<li>' )
				.append( $( '<span>' ).text( group.name ) )
				.append( ' ' )
				.append( $( '<strong>' ).text( group.members ) );

			if ( group.waiting > 0 ) {
				$item.append( ' ' ).append(
					$( '<span>' ).addClass( 'dicex-connect-club-tag' ).text( i18n.groupWaiting.replace( '%s', number( group.waiting ) ) )
				);
			}

			// Sent once and no longer matching: DiceX cannot take a contact out.
			if ( group.stale > 0 ) {
				$item.append( ' ' ).append(
					$( '<span>' ).addClass( 'dicex-connect-club-tag is-removed' ).attr( 'title', i18n.groupStaleHint ).text( i18n.groupStale.replace( '%s', number( group.stale ) ) )
				);
			}

			$groups.append( $item );
		} );

		$( '#dicex-connect-club-groups-wrap' ).prop( 'hidden', ! ( data.groups || [] ).length );

		$( '#dicex-connect-club-progress' )
			.prop( 'hidden', ! data.importing )
			.attr( 'max', Math.max( 1, data.import_total ) )
			.val( data.import_done );

		$( '#dicex-connect-club-import-text' ).removeClass( 'is-error' ).text( data.import_text || '' );
		setBusy( $( '#dicex-connect-club-import, #dicex-connect-club-import-step' ), !! data.importing );

		if ( data.importing || data.imported_once ) {
			$( '#dicex-connect-club-import-prompt' ).prop( 'hidden', true );
		}

		// The first import, read and worked out, waiting for the owner to send it. While
		// the setup steps are showing, their last step is where that is said and done.
		$( '#dicex-connect-club-hold' ).prop( 'hidden', ! data.hold || !! data.importing || $( '#dicex-connect-club-setup' ).length > 0 );
		$( '#dicex-connect-club-preview' ).prop( 'hidden', ! data.member_count );

		$( '#dicex-connect-club-paused' ).prop( 'hidden', ! data.paused ).children( 'span' ).last().text( data.paused || '' );
		$( '#dicex-connect-club-retry' ).prop( 'hidden', ! data.refused_count );
		$( '#dicex-connect-club-last-sent' ).text( data.last_sent || '' );
		$( '#dicex-connect-club-next-daily' ).text( data.next_daily || '' );
		$( '#dicex-connect-club-next-daily-wrap' ).prop( 'hidden', ! data.next_daily );

		announceStatus( lastStatus, data );
		lastStatus = data;

		// The setup steps follow the same status.
		$( '#dicex-connect-club-setup' ).attr( {
			'data-imported': data.imported_once ? '1' : '0',
			'data-hold': data.hold ? '1' : '0',
			'data-importing': data.importing ? '1' : '0'
		} );
		refreshSetup();
	}

	/*
	 * A status button that could not do its job says so in the line under the
	 * counts, where somebody pressing it is looking — not in an alert.
	 */
	function showStatusError( error ) {
		var message = errorText( error );

		$( '#dicex-connect-club-import-text' ).addClass( 'is-error' ).text( message );
		speak( message );
	}

	/*
	 * The numbers change on every poll and are left alone. What is said out loud is
	 * a turn of events: an import finishing, the first send waiting for the owner,
	 * sending being paused.
	 */
	function announceStatus( before, now ) {
		if ( ! before ) {
			return;
		}

		if ( before.importing && ! now.importing ) {
			speak( now.import_text );
		}

		if ( ! before.hold && now.hold && ! now.importing ) {
			speak( $( '#dicex-connect-club-hold p' ).first().text() );
		}

		if ( ! before.paused && now.paused ) {
			speak( now.paused );
		}
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	/*
	 * While there is work, the open tab asks for a few seconds of it every few
	 * seconds. That is what finishes an import on a site whose scheduled tasks
	 * never fire; on any other site it simply gets there sooner.
	 */
	function schedulePoll( data, delay ) {
		stopPolling();

		if ( ! root().length || ! data || ! data.enabled || ! data.more ) {
			if ( working ) {
				working = false;
				loadMembers( members.page, true );
			}

			return;
		}

		working = true;
		polls++;

		if ( 0 === polls % 5 ) {
			loadMembers( members.page, true );
		}

		pollTimer = window.setTimeout( function () {
			refreshStatus( ! document.hidden );
		}, delay || 3000 );
	}

	function refreshStatus( run ) {
		if ( ! root().length ) {
			stopPolling();
			return;
		}

		call(
			'dicex_connect_club_status',
			{ run: run ? '1' : '0' },
			function ( data ) {
				renderStatus( data );
				schedulePoll( data );
			},
			function () {
				schedulePoll( { enabled: true, more: true }, 15000 );
			}
		);
	}

	/*
	 * The status card's button and its twin in the setup steps are one action,
	 * busy together. `from` is 'step' when the twin was pressed: its failures are
	 * said beside the steps, and the keyboard goes to the line that shows progress.
	 */
	$( document ).on( 'click', '#dicex-connect-club-import', function ( event, from ) {
		var $both = $( '#dicex-connect-club-import, #dicex-connect-club-import-step' );

		if ( isBusy( $( this ) ) ) {
			return;
		}

		setBusy( $both, true );

		call(
			'dicex_connect_club_import',
			{},
			function ( data ) {
				renderStatus( data );
				$( '#dicex-connect-club-import-prompt' ).prop( 'hidden', true );
				speak( data.import_text );

				if ( 'step' === from ) {
					$( '.dicex-connect-club-step-progress' ).trigger( 'focus' );
				}

				refreshStatus( true );
			},
			function ( message ) {
				setBusy( $both, false );

				if ( 'step' === from ) {
					showSetupError( message );
					speak( errorText( message ) );
				} else {
					showStatusError( message );
				}
			}
		);
	} );

	$( document ).on( 'click', '#dicex-connect-club-sync', function () {
		var $button = $( this );

		if ( isBusy( $button ) ) {
			return;
		}

		setBusy( $button, true );

		call(
			'dicex_connect_club_status',
			{ run: '1' },
			function ( data ) {
				setBusy( $button, false );
				renderStatus( data );
				schedulePoll( data );
				loadMembers( members.page );
			},
			function ( message ) {
				setBusy( $button, false );
				showStatusError( message );
			}
		);
	} );

	/*
	 * Where the keyboard goes when the button it was on has gone away: the line
	 * that says what just happened.
	 */
	function focusStatusLine() {
		$( '#dicex-connect-club-import-text' ).trigger( 'focus' );
	}

	// Like the import above: one action, two buttons, busy together.
	$( document ).on( 'click', '#dicex-connect-club-release', function ( event, from ) {
		var $both = $( '#dicex-connect-club-release, #dicex-connect-club-release-step' );

		if ( isBusy( $( this ) ) ) {
			return;
		}

		// The one step here that cannot be undone.
		if ( ! window.confirm( i18n.releaseConfirm ) ) {
			return;
		}

		setBusy( $both, true );

		call(
			'dicex_connect_club_release',
			{},
			function ( data ) {
				setBusy( $both, false );
				renderStatus( data );
				// The box or the step this button was in is gone now.
				focusStatusLine();
				refreshStatus( true );
			},
			function ( message ) {
				setBusy( $both, false );

				if ( 'step' === from ) {
					showSetupError( message );
					speak( errorText( message ) );
				} else {
					showStatusError( message );
				}
			}
		);
	} );

	/* ---- Counting customers for the levels on screen ---------------------- */

	/*
	 * Counts go stale the moment a level changes; an answer still on its way for
	 * the old levels is then dropped.
	 */
	function clearPreview() {
		previewRun++;
		$( '.dicex-connect-club-entry' ).find( '.dicex-connect-club-level-count' ).prop( 'hidden', true ).text( '' );
		$( '#dicex-connect-club-preview-status' ).prop( 'hidden', true ).removeClass( 'is-error' ).text( '' );
		setBusy( $( '#dicex-connect-club-preview' ), false );
	}

	/*
	 * The count's progress shows on screen and is not read out — a large store
	 * would queue a message for every page. The start and the result are.
	 */
	$( document ).on( 'click', '#dicex-connect-club-preview', function () {
		var $button = $( this );
		var $status = $( '#dicex-connect-club-preview-status' );

		if ( isBusy( $button ) ) {
			return;
		}

		setBusy( $button, true );
		speak( i18n.counting );
		var run = ++previewRun;
		var totals = {};
		var inGroup = {};
		var moves = 0;
		var checked = 0;
		var total = 0;
		var request = {
			levels: JSON.stringify( collectLevels() ),
			groups: JSON.stringify( collect( 'group' ) ),
			default_level: $( '#dicex-connect-club-default' ).val() || '',
			removed_level: $( '#dicex-connect-club-removed' ).val() || '',
			window_months: $( '#dicex-connect-club-window' ).val() || '0'
		};

		$( '.dicex-connect-club-entry' ).find( '.dicex-connect-club-level-count' ).prop( 'hidden', true ).text( '' );
		$status.removeClass( 'is-error' ).prop( 'hidden', false ).text( i18n.counting );

		function page( after ) {
			call(
				'dicex_connect_club_preview',
				$.extend( { after: after }, request ),
				function ( data ) {
					if ( run !== previewRun ) {
						return;
					}

					if ( 0 === after ) {
						total = data.total || 0;
					}

					checked += data.checked;
					moves += data.moves;

					Object.keys( data.counts || {} ).forEach( function ( id ) {
						totals[ id ] = ( totals[ id ] || 0 ) + data.counts[ id ];
					} );

					Object.keys( data.groups || {} ).forEach( function ( id ) {
						inGroup[ id ] = ( inGroup[ id ] || 0 ) + data.groups[ id ];
					} );

					if ( ! data.done ) {
						$status.text( i18n.countingProgress.replace( '%1$s', number( checked ) ).replace( '%2$s', number( Math.max( total, checked ) ) ) );
						page( data.next );
						return;
					}

					levelRows().each( function () {
						var $row = $( this );

						$row.find( '.dicex-connect-club-level-count' )
							.text( i18n.levelCount.replace( '%s', number( totals[ $row.attr( 'data-id' ) ] || 0 ) ) )
							.prop( 'hidden', false );
					} );

					rowsOf( 'group' ).each( function () {
						var $row = $( this );

						$row.find( '.dicex-connect-club-level-count' )
							.text( i18n.levelCount.replace( '%s', number( inGroup[ $row.attr( 'data-id' ) ] || 0 ) ) )
							.prop( 'hidden', false );
					} );

					$status.text( i18n.previewDone.replace( '%1$s', number( checked ) ).replace( '%2$s', number( moves ) ) );
					setBusy( $button, false );
					speak( $status.text() );
				},
				function ( message ) {
					if ( run !== previewRun ) {
						return;
					}

					setBusy( $button, false );
					$status.addClass( 'is-error' ).text( message );
					speak( message );
				}
			);
		}

		page( 0 );
	} );

	$( document ).on( 'click', '#dicex-connect-club-retry', function () {
		var $button = $( this );

		if ( isBusy( $button ) ) {
			return;
		}

		setBusy( $button, true );

		call(
			'dicex_connect_club_retry',
			{},
			function ( data ) {
				setBusy( $button, false );
				renderStatus( data );
				$( '#dicex-connect-club-import-text' ).text( data.message || '' );

				// Nothing refused any more hides this button: the message takes its focus.
				if ( $button.prop( 'hidden' ) ) {
					focusStatusLine();
				} else {
					speak( data.message );
				}

				refreshStatus( true );
			},
			function ( message ) {
				setBusy( $button, false );
				showStatusError( message );
			}
		);
	} );

	$( document ).on( 'click', '#dicex-connect-club-remote', function () {
		var $list = $( '#dicex-connect-club-remote-list' ).prop( 'hidden', false ).text( DiceXAdmin.i18n.loading );

		call(
			'dicex_connect_club_remote',
			{},
			function ( data ) {
				var $items = $( '<ul>' );

				$list.empty();

				if ( ! data.levels.length ) {
					$list.text( i18n.remoteEmpty );
					speak( i18n.remoteEmpty );
					return;
				}

				data.levels.forEach( function ( level ) {
					$items.append(
						$( '<li>' ).append( $( '<span>' ).text( level.name ) ).append( ' ' ).append( $( '<strong>' ).text( level.count ) )
					);
				} );

				$list.append( $( '<p>' ).addClass( 'description' ).text( i18n.remoteTitle ) ).append( $items );

				speak( i18n.remoteTitle + ' ' + data.levels.map( function ( level ) {
					return level.name + ' ' + level.count;
				} ).join( i18n.listSeparator || ', ' ) );
			},
			function ( message ) {
				$list.text( message );
				speak( message );
			}
		);
	} );

	/* ---- Members --------------------------------------------------------- */

	/*
	 * One member. The number heads the row, so every control in it is heard with
	 * whose it is. Choosing a level in the list moves nobody: arrowing through a
	 * closed list fires a change for every option it passes, so the move waits
	 * for Apply.
	 */
	function memberRow( row ) {
		var $row = $( '<tr>' ).attr( 'data-member', row.id );
		var $name = $( '<td>' );
		var $move = $( '<select>' ).addClass( 'dicex-connect-club-move' ).attr( 'aria-label', ( i18n.moveLabelFor || '%s' ).replace( '%s', row.mobile ) );
		var $apply = $( '<button>' ).attr( 'type', 'button' ).addClass( 'button button-small dicex-connect-club-move-apply' ).text( i18n.apply ).prop( 'hidden', true );
		var $level = $( '<td>' ).append( $move ).append( ' ' ).append( $apply );
		var $state = $( '<td>' ).append( $( '<span>' ).addClass( 'dicex-connect-club-state is-' + row.status ).text( row.status_label ) );
		var place = [ row.city, row.country ].filter( function ( part ) {
			return !! part;
		} ).join( i18n.listSeparator || ', ' );

		$row.append( $( '<th>' ).attr( 'scope', 'row' ).append( $( '<span>' ).attr( 'dir', 'ltr' ).text( row.mobile ) ) );

		if ( row.user_link ) {
			$name.append( $( '<a>' ).attr( 'href', row.user_link ).text( row.name || i18n.noName ) );
		} else {
			$name.text( row.name || i18n.guest );
		}

		// The groups this member is in, under their name: DiceX holds a contact in
		// every group they match, not in one.
		if ( ( row.groups || [] ).length ) {
			var $chips = $( '<span>' ).addClass( 'dicex-connect-club-chips' );

			// Each state has a mark of its own and words for a screen reader, not a colour alone.
			var marks = { 'in': 'dashicons-yes', waiting: 'dashicons-clock', left: 'dashicons-minus' };
			var words = { 'in': i18n.groupChipIn, waiting: i18n.groupChipWaiting, left: i18n.groupChipLeft };

			row.groups.forEach( function ( group ) {
				var state = marks[ group.state ] ? group.state : 'in';
				var $chip = $( '<span>' ).addClass( 'dicex-connect-club-chip is-' + state )
					.append( $( '<span>' ).addClass( 'dashicons ' + marks[ state ] ).attr( 'aria-hidden', 'true' ) )
					.append( document.createTextNode( group.name ) )
					.append( $( '<span>' ).addClass( 'screen-reader-text' ).text( ' (' + words[ state ] + ')' ) );

				if ( 'waiting' === state ) {
					$chip.attr( 'title', i18n.groupChipWaiting );
				} else if ( 'left' === state ) {
					$chip.attr( 'title', i18n.groupStaleHint );
				}

				$chips.append( $chip );
			} );

			$name.append( $chips );
		}

		$row.append( $name );
		$row.append( $( '<td>' ).text( row.orders ) );
		$row.append( $( '<td>' ).append( $( '<span>' ).attr( 'dir', 'ltr' ).text( row.spent ) ) );
		$row.append( $( '<td>' ).text( place ) );

		$move.append(
			$( '<option>' ).val( 'auto' ).text( row.locked ? i18n.automatic : ( i18n.automaticNow || '' ).replace( '%s', row.level || '—' ) )
		);

		levels.forEach( function ( level ) {
			$move.append( $( '<option>' ).val( level.id ).text( level.name ) );
		} );

		$move.val( row.locked ? row.level_id : 'auto' ).attr( 'data-current', $move.val() );

		if ( row.locked ) {
			$level.append( $( '<span>' ).addClass( 'dashicons dashicons-lock dicex-connect-club-lock' ).attr( { title: i18n.lockedTitle, 'aria-hidden': 'true' } ) )
				.append( $( '<span>' ).addClass( 'screen-reader-text' ).text( i18n.lockedTitle ) );
		}

		$row.append( $level );

		if ( row.error ) {
			$state.append( $( '<span>' ).addClass( 'dicex-connect-club-error' ).text( row.error ) );
		}

		if ( row.synced ) {
			$state.append( $( '<span>' ).addClass( 'dicex-connect-club-synced' ).text( row.synced ) );
		}

		$row.append( $state );

		return $row;
	}

	/*
	 * @param {number}  page
	 * @param {boolean} quiet    Asked for by the poll, not by anybody: skipped while
	 *                           somebody is working inside the table, whose rows
	 *                           it would pull out from under them.
	 * @param {boolean} announce Somebody searched, filtered or turned a page: say
	 *                           what came back.
	 */
	function loadMembers( page, quiet, announce ) {
		var $rows = $( '#dicex-connect-club-rows' );

		if ( ! $rows.length || $( '#dicex-connect-club-members' ).prop( 'hidden' ) ) {
			return;
		}

		if ( quiet && $.contains( $rows[ 0 ], document.activeElement ) ) {
			return;
		}

		var filters = {
			search: $( '#dicex-connect-club-search' ).val() || '',
			level: $( '#dicex-connect-club-filter-level' ).val() || '',
			status: $( '#dicex-connect-club-filter-status' ).val() || ''
		};
		var filtered = '' !== filters.search || '' !== filters.level || '' !== filters.status;

		call(
			'dicex_connect_club_members',
			$.extend( { page: page || 1 }, filters ),
			function ( data ) {
				var pageText = data.pages > 0 ? ( i18n.pageOf || '' ).replace( '%1$s', number( data.page ) ).replace( '%2$s', number( data.pages ) ) : '';

				members.page = data.page;
				members.pages = data.pages;

				$rows.empty();

				if ( ! data.rows.length ) {
					$rows.append( $( '<tr>' ).append( $( '<td>' ).attr( 'colspan', 7 ).text( filtered ? i18n.noMembersMatch : i18n.noMembers ) ) );
				}

				data.rows.forEach( function ( row ) {
					$rows.append( memberRow( row ) );
				} );

				setBusy( $( '#dicex-connect-club-prev' ), data.page <= 1 );
				setBusy( $( '#dicex-connect-club-next' ), data.page >= data.pages );
				$( '#dicex-connect-club-page-text' ).text( pageText );

				if ( announce ) {
					speak(
						data.rows.length
							? ( i18n.membersFound || '%s' ).replace( '%s', number( data.total ) ) + ( data.pages > 1 ? ' ' + pageText : '' )
							: ( filtered ? i18n.noMembersMatch : i18n.noMembers )
					);
				}
			},
			function ( message ) {
				$rows.empty().append( $( '<tr>' ).append( $( '<td>' ).attr( 'colspan', 7 ).text( message ) ) );

				if ( announce ) {
					speak( message );
				}
			}
		);
	}

	// Choosing a level only offers Apply; nobody moves until it is pressed.
	$( document ).on( 'change', '.dicex-connect-club-move', function () {
		var $select = $( this );

		$select.closest( 'td' ).find( '.dicex-connect-club-move-apply' ).prop( 'hidden', $select.val() === $select.attr( 'data-current' ) );
	} );

	$( document ).on( 'click', '.dicex-connect-club-move-apply', function () {
		var $button = $( this );
		var $row = $button.closest( 'tr' );
		var $select = $row.find( '.dicex-connect-club-move' );

		if ( isBusy( $button ) ) {
			return;
		}

		setBusy( $button, true );

		call(
			'dicex_connect_club_move',
			{ member: $row.attr( 'data-member' ), level: $select.val() },
			function ( data ) {
				var $fresh = memberRow( data.row );

				$row.replaceWith( $fresh );
				// The row is new; the keyboard goes back to its level.
				$fresh.find( '.dicex-connect-club-move' ).trigger( 'focus' );

				speak(
					( data.row.locked ? i18n.movedTo : i18n.movedAuto )
						.replace( '%1$s', data.row.mobile )
						.replace( '%2$s', data.row.level || '—' )
				);

				renderStatus( data.status );
				schedulePoll( data.status );
			},
			function ( message ) {
				setBusy( $button, false );
				// The row says what went wrong, under the level that did not change.
				$row.find( '.dicex-connect-club-move-error' ).remove();
				$select.closest( 'td' ).append( $( '<span>' ).addClass( 'dicex-connect-club-error dicex-connect-club-move-error' ).text( errorText( message ) ) );
				speak( errorText( message ) );
			}
		);
	} );

	$( document ).on( 'input', '#dicex-connect-club-search', function () {
		window.clearTimeout( searchTimer );
		searchTimer = window.setTimeout( function () {
			loadMembers( 1, false, true );
		}, 400 );
	} );

	$( document ).on( 'change', '#dicex-connect-club-filter-level, #dicex-connect-club-filter-status', function () {
		loadMembers( 1, false, true );
	} );

	// The first and last pages say so rather than disabling a button somebody is on.
	$( document ).on( 'click', '#dicex-connect-club-prev', function () {
		if ( ! isBusy( $( this ) ) ) {
			loadMembers( Math.max( 1, members.page - 1 ), false, true );
		}
	} );

	$( document ).on( 'click', '#dicex-connect-club-next', function () {
		if ( ! isBusy( $( this ) ) ) {
			loadMembers( members.page + 1, false, true );
		}
	} );

	/* ---- Start ----------------------------------------------------------- */

	function init() {
		if ( ! root().length ) {
			return;
		}

		stopPolling();
		working = false;
		polls = 0;
		members = { page: 1, pages: 0 };
		choices = null;
		choicesError = '';
		choicesWaiting = [];
		previewRun++;
		lastStatus = null;

		refreshLevelSelects();
		$( '.dicex-connect-club-entry, .dicex-connect-club-welcome' ).each( function () {
			updateSummaries( $( this ) );
			drawMessages( $( this ) );
		} );
		// Rows arrive from the server closed; this names their buttons and bodies.
		$( '.dicex-connect-club-entry' ).each( function () {
			setOpen( $( this ), false );
		} );
		updateGroupTags();
		summarizeAll();
		markClean();
		refreshSetup();
		loadChoices();
		loadMembers( 1 );

		if ( '1' === root().attr( 'data-enabled' ) ) {
			refreshStatus( true );
		}
	}

	$( document ).on( 'dicex-connect-tab-loaded', function ( event, tab ) {
		if ( 'club' === tab ) {
			init();
		} else {
			stopPolling();
		}
	} );

	init();
} );
