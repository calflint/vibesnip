/* VibeSnip settings: show the selected provider's fields, and test the connection.
 *
 * Every provider's key, model and endpoint fields are rendered server-side and all
 * but the selected one are hidden. That way switching provider is instant, and a
 * key you typed for one vendor is still in its box if you switch back before saving.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.VibeSnipSettings || {};

	// The row belonging to the provider currently chosen in the dropdown.
	function currentRow() {
		return $( '.vibesnip-provider-row[data-provider="' + $( '#vibesnip-provider' ).val() + '"]' );
	}

	function showProvider( id ) {
		$( '.vibesnip-provider-row' ).each( function () {
			this.hidden = this.getAttribute( 'data-provider' ) !== id;
		} );
		syncTestBtn();
	}

	// Testable when this provider has a key — either one already saved, or one
	// typed into the box a moment ago and not saved yet.
	function syncTestBtn() {
		var $row = currentRow();
		var typed = $.trim( $row.find( '.vibesnip-key-input' ).val() || '' );
		$( '#vibesnip-test-connection' ).prop( 'disabled', ! typed && $row.attr( 'data-has-key' ) !== '1' );
	}

	function testConnection() {
		var $btn = $( '#vibesnip-test-connection' );
		var $out = $( '#vibesnip-test-result' );
		var $row = currentRow();

		$btn.prop( 'disabled', true );
		$out.removeClass( 'is-ok is-bad' ).text( cfg.i18n.testing );

		// What is on screen, not what was saved — otherwise picking a provider,
		// pasting its key and pressing the button reports on the previous one.
		// A blank key box means "use the saved key", which the server fills in.
		$.post( window.ajaxurl, {
			action:   'vibesnip_test_connection',
			nonce:    cfg.nonce,
			provider: $( '#vibesnip-provider' ).val(),
			api_key:  $.trim( $row.find( '.vibesnip-key-input' ).val() || '' ),
			model:    $.trim( $row.find( '.vibesnip-model-input' ).val() || '' ),
			endpoint: $.trim( $row.find( '.vibesnip-endpoint-input' ).val() || '' )
		} )
			.done( function ( r ) {
				if ( r && r.success && r.data ) {
					$out.addClass( 'is-ok' ).text( r.data.message );
				} else {
					$out.addClass( 'is-bad' ).text( ( r && r.data && r.data.message ) || cfg.i18n.failed );
				}
			} )
			.fail( function ( xhr ) {
				var msg = cfg.i18n.failed;
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					msg = xhr.responseJSON.data.message;
				}
				$out.addClass( 'is-bad' ).text( msg );
			} )
			.always( syncTestBtn );
	}

	/* Ask the provider which models this key can use, and rebuild the dropdown from
	 * the answer. A list shipped in a release is out of date the moment a vendor
	 * renames something, which is how you end up picking a model your account has
	 * never had. Keeps the current selection if the provider still offers it. */
	function refreshModels() {
		var $row = currentRow();
		var $btn = $row.find( '.vibesnip-refresh-models' );
		var $out = $row.find( '.vibesnip-models-result' );
		var $sel = $row.find( 'select.vibesnip-model-input' );
		if ( ! $sel.length ) { return; }

		$btn.prop( 'disabled', true );
		$out.removeClass( 'is-ok is-bad' ).empty()
			.append( $( '<span class="vs-spinner"></span>' ) )
			.append( document.createTextNode( ' ' + cfg.i18n.loadingModels ) );

		$.post( window.ajaxurl, {
			action:   'vibesnip_fetch_models',
			nonce:    cfg.nonce,
			provider: $( '#vibesnip-provider' ).val(),
			api_key:  $.trim( $row.find( '.vibesnip-key-input' ).val() || '' ),
			endpoint: $.trim( $row.find( '.vibesnip-endpoint-input' ).val() || '' )
		} )
			.done( function ( r ) {
				if ( ! r || ! r.success || ! r.data || ! r.data.models ) {
					$out.addClass( 'is-bad' ).text( ( r && r.data && r.data.message ) || cfg.i18n.failed );
					return;
				}
				var chosen = $sel.val();
				$sel.empty();
				$.each( r.data.models, function ( id, label ) {
					$sel.append( $( '<option></option>' ).attr( 'value', id ).text( label ) );
				} );
				// Only keep the old selection if it survived; otherwise the first
				// option wins, which is the provider's newest.
				if ( r.data.models[ chosen ] ) { $sel.val( chosen ); }
				$out.addClass( 'is-ok' ).text( r.data.message );
			} )
			.fail( function ( xhr ) {
				var msg = cfg.i18n.failed;
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					msg = xhr.responseJSON.data.message;
				}
				$out.addClass( 'is-bad' ).text( msg );
			} )
			.always( function () { $btn.prop( 'disabled', false ); } );
	}

	/* The AI switch. Turning it on reveals the acknowledgement and the provider
	 * settings; turning it off hides both again. The server enforces the same rule
	 * — this only spares people submitting a form that was going to be refused. */
	function syncAiGate() {
		var on = $( '#vibesnip-ai-enabled' ).prop( 'checked' );
		var alreadyOn = $( '.vibesnip-ai-gate' ).hasClass( 'is-on' );

		$( '.vibesnip-ai-settings' ).prop( 'hidden', ! on );
		// Nobody re-consents to something already switched on, and nobody consents
		// to switching it off.
		$( '#vibesnip-ai-consent-row' ).prop( 'hidden', ! on || alreadyOn );
		$( '#vibesnip-ai-consent' ).prop( 'required', on && ! alreadyOn );
	}

	$( function () {
		$( '#vibesnip-ai-enabled' ).on( 'change', syncAiGate );

		var $provider = $( '#vibesnip-provider' );
		if ( ! $provider.length ) { return; }

		$provider.on( 'change', function () { showProvider( this.value ); } );
		$( '.vibesnip-key-input' ).on( 'input', syncTestBtn );
		$( '#vibesnip-test-connection' ).on( 'click', testConnection );
		$( '.vibesnip-refresh-models' ).on( 'click', refreshModels );
	} );
} )( jQuery );
