/* VibeSnip editor: CodeMirror, language colors, Ask AI, save shortcut. */
( function ( $ ) {
	'use strict';

	var editor = null;

	var hints = {
		php:  'PHP runs like code in functions.php. It is syntax-checked before it can go active.',
		css:  'CSS is printed inside a <style> tag at the location you choose.',
		js:   'JavaScript is printed inside a <script> tag at the location you choose.',
		html: 'HTML is printed verbatim at the location you choose.',
		text: 'Text is printed verbatim at the location you choose.'
	};

	var ext = { php: 'php', css: 'css', js: 'js', html: 'html', text: 'txt' };

	function currentType() {
		return $( '#vibesnip-type' ).val() || 'php';
	}

	function code() {
		return editor ? editor.getValue() : ( $( '#vibesnip-code' ).val() || '' );
	}

	/* -------------------------------------------------- type -> everything */

	function applyType() {
		var type = currentType();

		$( '#vibesnip-hint' ).text( hints[ type ] || '' );
		$( '#vs-editor-name' ).text( 'snippet.' + ( ext[ type ] || 'txt' ) );
		$( '#vs-lang-pill' ).text( ( type || '' ).toUpperCase() );

		// Recolor the editor heading by swapping its language class. Each
		// language's colour is defined in admin.css (.vibesnip-editor-bar.lang-*).
		var bar = document.getElementById( 'vs-editor-bar' );
		if ( bar ) {
			bar.className = 'vibesnip-editor-bar lang-' + type;
		}

		if ( editor && window.VibeSnip && VibeSnip.modes ) {
			editor.setOption( 'mode', VibeSnip.modes[ type ] || 'text/plain' );
		}

		// Show only the locations valid for this type; keep the selection valid.
		var $loc = $( '#vibesnip-location' );
		var firstValid = null;
		var stillValid = false;
		$loc.find( 'option' ).each( function () {
			var types = ( $( this ).data( 'types' ) || '' ).toString().split( ' ' );
			var ok = types.indexOf( type ) !== -1;
			$( this ).prop( 'disabled', ! ok ).toggle( ok );
			if ( ok ) {
				if ( firstValid === null ) { firstValid = this.value; }
				if ( this.value === $loc.val() ) { stillValid = true; }
			}
		} );
		if ( ! stillValid && firstValid !== null ) {
			$loc.val( firstValid );
		}
	}

	/* -------------------------------------------------- editor init */

	function initEditor() {
		var $ta = $( '#vibesnip-code' );
		if ( ! $ta.length ) { return; }

		if ( window.VibeSnip && VibeSnip.cm && window.wp && wp.codeEditor ) {
			var settings = $.extend( true, {}, VibeSnip.cm );
			var result = wp.codeEditor.initialize( $ta[ 0 ], settings );
			editor = result.codemirror;

			editor.on( 'change', function () {
				editor.save();
			} );

			editor.setOption( 'extraKeys', { 'Ctrl-S': saveForm, 'Cmd-S': saveForm } );
		}
		applyType();
	}

	function saveForm() {
		if ( editor ) { editor.save(); }
		$( '#vibesnip-form' ).find( 'button[name="save"]' ).trigger( 'click' );
		return false;
	}

	/* -------------------------------------------------- ask ai */

	function t( key, fallback ) {
		var i18n = ( window.VibeSnip && VibeSnip.i18n ) || {};
		return i18n[ key ] || fallback;
	}

	// The Ask AI button only makes sense once there is code to review, and only
	// works once the snippet has a row to store the report against. It stays
	// visible in every case so the feature is discoverable — the wrapper's
	// tooltip is what tells you which of those is currently in the way. With no
	// API key nothing you type can unblock it, so leave that state alone.
	function syncAskBtn() {
		var $btn = $( '#vs-ask-btn' );
		if ( ! $btn.length || $btn.attr( 'data-nokey' ) ) { return; }

		var empty = $.trim( code() ) === '';
		var saved = ( parseInt( $btn.attr( 'data-id' ), 10 ) || 0 ) > 0;
		var tips  = ( window.VibeSnip && VibeSnip.askTips ) || {};

		$btn.prop( 'disabled', empty );
		$( '#vs-ask-wrap' ).attr( 'title', empty ? tips.empty : ( saved ? tips.ready : tips.unsaved ) );
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text != null ) { n.textContent = text; }
		return n;
	}

	// Built with textContent throughout — never innerHTML. The report is model
	// output, so it is treated as untrusted text.
	function renderReview( d ) {
		var verdict = d.verdict || 'caution';

		var panel = el( 'div', 'vibesnip-panel vibesnip-review-panel vs-verdict-' + verdict );

		var head = el( 'div', 'vibesnip-review-head' );
		head.appendChild( el( 'span', 'vs-verdict-chip vs-verdict-' + verdict, t( verdict, verdict ) ) );
		head.appendChild( el( 'span', 'vibesnip-review-advisory', t( 'advisory', '' ) ) );
		panel.appendChild( head );

		panel.appendChild( el( 'p', 'vibesnip-review-summary', d.summary || '' ) );

		if ( d.risks && d.risks.length ) {
			panel.appendChild( el( 'h3', 'vibesnip-review-h', t( 'risks', 'Risks' ) ) );
			var ul = el( 'ul', 'vibesnip-review-risks' );
			d.risks.forEach( function ( r ) { ul.appendChild( el( 'li', null, r ) ); } );
			panel.appendChild( ul );
		}

		[
			[ 'does', d.what_it_does ],
			[ 'impact', d.site_impact ],
			[ 'when', d.recommended_conditions ],
			[ 'notes', d.notes ]
		].forEach( function ( pair ) {
			if ( ! pair[ 1 ] || ! $.trim( pair[ 1 ] ) ) { return; }
			panel.appendChild( el( 'h3', 'vibesnip-review-h', t( pair[ 0 ], '' ) ) );
			panel.appendChild( el( 'p', null, pair[ 1 ] ) );
		} );

		var chips = [];
		if ( d.suggested_title && d.suggested_title !== $( '#vibesnip-title' ).val() ) {
			chips.push( [ 'title', d.suggested_title, 'Name: ' + d.suggested_title ] );
		}
		if ( d.recommended_type && d.recommended_type !== currentType() ) {
			chips.push( [ 'type', d.recommended_type, 'Type: ' + d.recommended_type ] );
		}
		if ( d.recommended_location && d.recommended_location !== $( '#vibesnip-location' ).val() ) {
			chips.push( [ 'location', d.recommended_location, 'Where: ' + d.recommended_location ] );
		}
		if ( d.suggested_tags && d.suggested_tags.length ) {
			var tags = d.suggested_tags.join( ', ' );
			if ( tags !== $( '#vibesnip-tags' ).val() ) {
				chips.push( [ 'tags', tags, 'Tags: ' + tags ] );
			}
		}
		if ( chips.length ) {
			var wrap = el( 'div', 'vibesnip-review-chips' );
			chips.forEach( function ( c ) {
				var b = el( 'button', 'vs-apply-chip', c[ 2 ] );
				b.type = 'button';
				b.setAttribute( 'data-field', c[ 0 ] );
				b.setAttribute( 'data-value', c[ 1 ] );
				wrap.appendChild( b );
			} );
			panel.appendChild( wrap );
		}

		var $box = $( '#vs-review' );
		$box.empty().append( panel ).prop( 'hidden', false );
		$( '.vibesnip-nudge' ).prop( 'hidden', true );
	}

	function askAI() {
		var $btn = $( '#vs-ask-btn' );
		var $err = $( '#vs-review-error' );
		if ( ! window.VibeSnip || ! VibeSnip.askNonce || ! window.ajaxurl ) { return; }

		var id = parseInt( $btn.attr( 'data-id' ), 10 ) || 0;
		if ( ! id ) {
			$err.prop( 'hidden', false ).text( t( 'saveFirst', '' ) );
			return;
		}

		if ( editor ) { editor.save(); }
		$err.prop( 'hidden', true ).text( '' );

		// A spinner in the button, because the review takes tens of seconds and a
		// button that has merely gone grey reads as one that did not respond.
		$btn.prop( 'disabled', true ).empty()
			.append( el( 'span', 'vs-spinner' ) )
			.append( document.createTextNode( ' ' + t( 'asking', 'Asking AI…' ) ) );

		$.post( window.ajaxurl, {
			action: 'vibesnip_ask_ai',
			nonce:  VibeSnip.askNonce,
			id:     id,
			type:   currentType(),
			code:   code()
		} ).done( function ( r ) {
			if ( r && r.success && r.data ) {
				renderReview( r.data );
			} else {
				var msg = ( r && r.data && r.data.message ) || t( 'failed', '' );
				$err.prop( 'hidden', false ).text( msg );
			}
		} ).fail( function ( xhr ) {
			var msg = t( 'failed', '' );
			if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				msg = xhr.responseJSON.data.message;
			}
			$err.prop( 'hidden', false ).text( msg );
		} ).always( function () {
			$btn.text( '✨ ' + t( 'ask', 'Ask AI' ) );
			syncAskBtn();
		} );
	}

	// Apply a suggestion to the form. Advisory fields only — never the code, and
	// never the status.
	function applyChip() {
		var $chip = $( this );
		var field = $chip.attr( 'data-field' );
		var value = $chip.attr( 'data-value' );
		var map = {
			title:    '#vibesnip-title',
			type:     '#vibesnip-type',
			location: '#vibesnip-location',
			tags:     '#vibesnip-tags'
		};
		if ( ! map[ field ] ) { return; }

		$( map[ field ] ).val( value ).trigger( 'change' );
		$chip.prop( 'disabled', true ).addClass( 'is-applied' ).text( t( 'applied', 'Applied' ) );
	}

	/* -------------------------------------------------- conditions builder */

	function condCfg() {
		return ( window.VibeSnip && VibeSnip.conditions ) || null;
	}

	function buildSelect( name, options, selected ) {
		var s = document.createElement( 'select' );
		s.name = name;
		( options || [] ).forEach( function ( o ) {
			var opt = document.createElement( 'option' );
			opt.value = o[ 0 ];
			opt.textContent = o[ 1 ];
			if ( selected != null && String( selected ) === String( o[ 0 ] ) ) {
				opt.selected = true;
			}
			s.appendChild( opt );
		} );
		return s;
	}

	function operatorsFor( type ) {
		var cfg = condCfg();
		if ( ! cfg ) { return []; }
		return cfg.operators[ type ] || cfg.operators[ 'default' ] || [];
	}

	function valueControl( type, value ) {
		var cfg = condCfg();
		var vals = cfg && cfg.values ? cfg.values[ type ] : null;
		if ( vals ) {
			return buildSelect( 'condition_value[]', vals, value );
		}
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.name = 'condition_value[]';
		input.className = 'vs-cond-value-text';
		input.placeholder = '/path/';
		if ( value != null ) { input.value = value; }
		return input;
	}

	function typeOptions() {
		var cfg = condCfg();
		var out = [];
		if ( cfg ) {
			Object.keys( cfg.types ).forEach( function ( k ) { out.push( [ k, cfg.types[ k ] ] ); } );
		}
		return out;
	}

	function addRuleRow( rule ) {
		rule = rule || {};
		var host = document.getElementById( 'vs-conditions-rules' );
		var cfg = condCfg();
		if ( ! host || ! cfg ) { return; }

		var row = document.createElement( 'div' );
		row.className = 'vibesnip-cond-row';

		var types = typeOptions();
		var type = rule.type || ( types[ 0 ] && types[ 0 ][ 0 ] ) || 'user_status';
		var typeSel = buildSelect( 'condition_type[]', types, type );

		var opCell = document.createElement( 'span' );
		opCell.className = 'vs-cond-op';
		var valCell = document.createElement( 'span' );
		valCell.className = 'vs-cond-val';

		function rebuild( t, op, val ) {
			opCell.innerHTML = '';
			valCell.innerHTML = '';
			opCell.appendChild( buildSelect( 'condition_operator[]', operatorsFor( t ), op ) );
			valCell.appendChild( valueControl( t, val ) );
		}
		rebuild( type, rule.operator, rule.value );

		typeSel.addEventListener( 'change', function () { rebuild( typeSel.value, null, null ); } );

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link vs-cond-remove';
		remove.setAttribute( 'aria-label', 'Remove rule' );
		remove.textContent = '×';
		remove.addEventListener( 'click', function () { row.parentNode.removeChild( row ); } );

		row.appendChild( typeSel );
		row.appendChild( opCell );
		row.appendChild( valCell );
		row.appendChild( remove );
		host.appendChild( row );
	}

	function initConditions() {
		if ( ! condCfg() ) { return; }

		var enable = document.getElementById( 'conditions_enabled' );
		var body = document.getElementById( 'vibesnip-cond-body' );
		if ( enable && body ) {
			enable.addEventListener( 'change', function () { body.hidden = ! enable.checked; } );
		}
		var add = document.getElementById( 'vs-add-condition' );
		if ( add ) {
			add.addEventListener( 'click', function () { addRuleRow( {} ); } );
		}

		var data = ( window.VibeSnip && VibeSnip.conditionsData ) || null;
		if ( data && data.rules && data.rules.length ) {
			data.rules.forEach( function ( r ) { addRuleRow( r ); } );
		}
	}

	/* -------------------------------------------------- boot */

	$( function () {
		initEditor();
		initConditions();

		$( '#vibesnip-type' ).on( 'change', applyType );
		$( '#vs-ask-btn' ).on( 'click', askAI );
		// Delegated: chips exist both server-rendered and freshly built.
		$( document ).on( 'click', '.vs-apply-chip', applyChip );

		syncAskBtn();
		$( '#vibesnip-code' ).on( 'input', syncAskBtn );
		if ( editor ) { editor.on( 'change', syncAskBtn ); }

		$( document ).on( 'keydown', function ( e ) {
			if ( ( e.metaKey || e.ctrlKey ) && ( e.key === 's' || e.which === 83 ) ) {
				if ( $( '#vibesnip-form' ).length ) {
					e.preventDefault();
					saveForm();
				}
			}
		} );
	} );
} )( jQuery );
