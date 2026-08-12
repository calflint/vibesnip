/* VibeSnip AI Compose: prompt -> plan -> approve -> build -> save.
 *
 * The conversation lives here, not on the server: every request is stateless and
 * carries what it needs. Nothing reaches the database until the Save button in the
 * built card is clicked, which is the whole point of the screen.
 *
 * Everything is built with textContent, never innerHTML — model output is treated
 * as untrusted text throughout.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.VibeSnipCompose || {};
	var i18n = cfg.i18n || {};

	// Every prompt the user has sent this session, oldest first. Sent back on a
	// rephrase so the model corrects itself instead of starting over.
	var history = [];
	var busy = false;

	// The "still working" card currently in the thread, with its clock: { node, timer }.
	var pending = null;

	function t( key ) {
		return i18n[ key ] || key;
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text != null ) { n.textContent = text; }
		return n;
	}

	function button( cls, text, onClick ) {
		var b = el( 'button', cls, text );
		b.type = 'button';
		b.addEventListener( 'click', onClick );
		return b;
	}

	function thread() {
		return document.getElementById( 'vs-compose-thread' );
	}

	function append( node ) {
		thread().appendChild( node );
		node.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		return node;
	}

	// The wait is tens of seconds long, so it gets a card of its own at the bottom
	// of the thread — spinner, what is being done, and a running clock, because an
	// empty screen and a broken screen look identical.
	function setBusy( on, label ) {
		busy = on;
		$( '#vs-compose-send' ).prop( 'disabled', on );
		$( '#vs-compose-prompt' ).prop( 'disabled', on );

		if ( pending ) {
			window.clearInterval( pending.timer );
			$( pending.node ).remove();
			pending = null;
		}
		if ( ! on ) { return; }

		var node = el( 'div', 'vibesnip-turn vibesnip-turn-ai vibesnip-pending' );
		node.appendChild( el( 'span', 'vs-spinner' ) );
		node.appendChild( el( 'span', 'vibesnip-pending-label', label ) );
		var clock = el( 'span', 'vibesnip-pending-clock', '0s' );
		node.appendChild( clock );

		var started = Date.now();
		pending = {
			node: append( node ),
			timer: window.setInterval( function () {
				clock.textContent = Math.round( ( Date.now() - started ) / 1000 ) + 's';
			}, 1000 )
		};
	}

	function post( action, data ) {
		return $.post( window.ajaxurl, $.extend( { action: action, nonce: cfg.nonce }, data ) );
	}

	function failure( xhr ) {
		var msg = t( 'failed' );
		if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
			msg = xhr.responseJSON.data.message;
		}
		append( el( 'div', 'vibesnip-compose-error', msg ) );
	}

	/* -------------------------------------------------- turns */

	function addSection( host, heading, body ) {
		if ( ! body || ! $.trim( body ) ) { return; }
		host.appendChild( el( 'h3', 'vibesnip-review-h', heading ) );
		host.appendChild( el( 'p', null, body ) );
	}

	function addList( host, heading, items ) {
		if ( ! items || ! items.length ) { return; }
		host.appendChild( el( 'h3', 'vibesnip-review-h', heading ) );
		var ul = el( 'ul', 'vibesnip-review-risks' );
		items.forEach( function ( item ) { ul.appendChild( el( 'li', null, item ) ); } );
		host.appendChild( ul );
	}

	function renderYou( prompt ) {
		var turn = el( 'div', 'vibesnip-turn vibesnip-turn-you' );
		turn.appendChild( el( 'span', 'vibesnip-turn-who', t( 'you' ) ) );
		turn.appendChild( el( 'p', 'vibesnip-turn-body', prompt ) );
		append( turn );
	}

	// Step 1 result: what it understood and what it intends to write. No code yet —
	// this is the point at which a misread request is cheap to correct.
	function renderPlan( plan, prompt ) {
		var turn = el( 'div', 'vibesnip-turn vibesnip-turn-ai vibesnip-plan' );

		addSection( turn, t( 'understanding' ), plan.understanding );
		addSection( turn, t( 'approach' ), plan.approach );

		if ( plan.snippets && plan.snippets.length ) {
			turn.appendChild( el( 'h3', 'vibesnip-review-h', t( 'willWrite' ) ) );
			var list = el( 'ul', 'vibesnip-plan-list' );
			plan.snippets.forEach( function ( s ) {
				var li = el( 'li' );
				li.appendChild( el( 'span', 'vs-lang vs-lang-' + s.type, ( s.type || '' ).toUpperCase() ) );
				li.appendChild( el( 'strong', 'vibesnip-plan-title', s.title || '' ) );
				if ( s.summary ) { li.appendChild( el( 'span', 'vibesnip-plan-summary', s.summary ) ); }
				list.appendChild( li );
			} );
			turn.appendChild( list );
		}

		addList( turn, t( 'questions' ), plan.assumptions );
		addList( turn, t( 'caveats' ), plan.caveats );

		var actions = el( 'div', 'vibesnip-turn-actions' );
		var approve = button( 'button button-primary', t( 'approve' ), function () {
			$( actions ).remove();
			build( prompt, plan );
		} );
		var rephrase = button( 'button button-secondary', t( 'rephrase' ), function () {
			$( actions ).remove();
			append( el( 'p', 'vibesnip-compose-hint', t( 'rephraseHint' ) ) );
			$( '#vs-compose-prompt' ).val( prompt ).trigger( 'focus' );
		} );
		actions.appendChild( approve );
		actions.appendChild( rephrase );
		turn.appendChild( actions );

		append( turn );
	}

	// The reviewer's report on code that does not exist on the site yet. Same shape
	// as the editor's Ask AI panel, so the two read identically.
	function renderReview( host, review ) {
		if ( ! review ) {
			host.appendChild( el( 'p', 'vibesnip-compose-hint', t( 'noReview' ) ) );
			return;
		}
		var verdict = review.verdict || 'caution';
		var head = el( 'div', 'vibesnip-review-head' );
		head.appendChild( el( 'span', 'vs-verdict-chip vs-verdict-' + verdict, t( verdict ) ) );
		head.appendChild( el( 'span', 'vibesnip-review-advisory', t( 'advisory' ) ) );
		host.appendChild( head );

		if ( review.summary ) {
			host.appendChild( el( 'p', 'vibesnip-review-summary', review.summary ) );
		}
		addSection( host, t( 'whatItDoes' ), review.what_it_does );
		addList( host, t( 'risks' ), review.risks );
		addSection( host, t( 'notes' ), review.notes );
	}

	// Step 2 result: name, language, description, code and review — everything you
	// need to decide, before anything is written anywhere.
	function renderBuilt( snippet ) {
		var card = el( 'div', 'vibesnip-turn vibesnip-turn-ai vibesnip-built' );

		var head = el( 'div', 'vibesnip-built-head' );
		head.appendChild( el( 'span', 'vs-lang vs-lang-' + snippet.type, ( snippet.type || '' ).toUpperCase() ) );
		head.appendChild( el( 'h3', 'vibesnip-built-title', snippet.title || '' ) );
		card.appendChild( head );

		var meta = el( 'p', 'vibesnip-built-meta' );
		meta.textContent = snippet.typeLabel + ' · ' + snippet.locationLabel;
		card.appendChild( meta );

		if ( snippet.description ) {
			card.appendChild( el( 'p', 'vibesnip-built-desc', snippet.description ) );
		}
		if ( snippet.explanation && snippet.explanation !== snippet.description ) {
			card.appendChild( el( 'p', 'vibesnip-built-desc', snippet.explanation ) );
		}

		card.appendChild( el( 'pre', 'vibesnip-proposal-code', snippet.code || '' ) );

		renderReview( card, snippet.review );

		var actions = el( 'div', 'vibesnip-turn-actions' );
		var save = button( 'button button-primary', t( 'save' ), function () {
			save.disabled = true;
			save.textContent = t( 'saving' );
			persist( snippet, card, actions );
		} );
		actions.appendChild( save );
		actions.appendChild( button( 'button button-secondary', t( 'discard' ), function () {
			$( card ).remove();
		} ) );
		card.appendChild( actions );

		append( card );
	}

	/* -------------------------------------------------- requests */

	function plan( prompt ) {
		history.push( prompt );
		renderYou( prompt );
		setBusy( true, t( 'thinking' ) );

		post( 'vibesnip_compose_plan', { prompt: prompt, history: history.slice( 0, -1 ) } )
			.done( function ( r ) {
				if ( r && r.success && r.data && r.data.plan ) {
					renderPlan( r.data.plan, prompt );
				} else {
					failure( null );
				}
			} )
			.fail( failure )
			.always( function () { setBusy( false ); } );
	}

	function build( prompt, approved ) {
		setBusy( true, t( 'writing' ) );

		post( 'vibesnip_compose_build', { prompt: prompt, plan: JSON.stringify( approved ) } )
			.done( function ( r ) {
				if ( r && r.success && r.data && r.data.snippets ) {
					r.data.snippets.forEach( renderBuilt );
				} else {
					failure( null );
				}
			} )
			.fail( failure )
			.always( function () { setBusy( false ); } );
	}

	function persist( snippet, card, actions ) {
		post( 'vibesnip_compose_save', {
			title: snippet.title,
			description: snippet.description || snippet.explanation || '',
			code: snippet.code,
			type: snippet.type,
			location: snippet.location,
			prompt: history[ history.length - 1 ] || '',
			review: snippet.review ? JSON.stringify( snippet.review ) : ''
		} )
			.done( function ( r ) {
				if ( ! r || ! r.success || ! r.data ) {
					failure( null );
					return;
				}
				$( actions ).remove();
				var done = el( 'p', 'vibesnip-built-saved' );
				done.appendChild( el( 'span', null, t( 'saved' ) + ' ' ) );
				var link = el( 'a', null, t( 'openEditor' ) );
				link.href = r.data.editUrl;
				done.appendChild( link );
				card.appendChild( done );
			} )
			.fail( function ( xhr ) {
				failure( xhr );
				$( actions ).find( 'button' ).first().prop( 'disabled', false ).text( t( 'save' ) );
			} );
	}

	/* -------------------------------------------------- boot */

	$( function () {
		var $form = $( '#vs-compose-form' );
		if ( ! $form.length ) { return; }

		$form.on( 'submit', function ( e ) {
			e.preventDefault();
			if ( busy ) { return; }
			var $input = $( '#vs-compose-prompt' );
			var prompt = $.trim( $input.val() );
			if ( ! prompt ) { return; }
			$input.val( '' );
			plan( prompt );
		} );

		// Ctrl/Cmd+Enter sends, because the box is a textarea and Enter has to
		// keep making new lines in a multi-line description.
		$( '#vs-compose-prompt' ).on( 'keydown', function ( e ) {
			if ( ( e.metaKey || e.ctrlKey ) && ( e.key === 'Enter' || e.which === 13 ) ) {
				e.preventDefault();
				$form.trigger( 'submit' );
			}
		} );
	} );
} )( jQuery );
