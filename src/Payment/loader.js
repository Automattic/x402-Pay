/**
 * x402 payment host loader.
 *
 * Loaded once per 402 page. Exposes window.x402Pay.registerProvider(id, cb)
 * so each enqueued provider script can attach its own button. After all
 * providers register, the host walks every <div data-x402-pay-provider="..."></div>
 * slot and invokes the matching callback with helpers shared across providers:
 * envelope construction + retry fetch (`retry`), inline status messaging
 * (`setStatus`), flow swap-in/out of the buttons block (`beginFlow` /
 * `endFlow`), and an error modal that returns to the buttons on dismiss
 * (`showError`).
 */
(function () {
	if ( window.x402Pay ) return;

	var ctx = window.x402PayPaymentContext || null;
	if ( ! ctx ) {
		var contextEl = document.getElementById( 'x402-pay-payment-context' );
		if ( ! contextEl ) return;
		try {
			ctx = JSON.parse( contextEl.textContent );
		} catch ( _ ) {
			return;
		}
	}

	var registry = Object.create( null );

	function $( selector ) {
		return document.querySelector( selector );
	}

	function statusEl()     { return document.getElementById( 'x402-pay-status' ); }
	function providersEl()  { return $( '[data-x402-pay-providers]' ); }
	function flowEl()       { return $( '[data-x402-pay-flow]' ); }
	function modalEl()      { return $( '[data-x402-pay-modal]' ); }
	function modalMsgEl()   { return $( '[data-x402-pay-modal-message]' ); }
	function fundHintEl()   { return $( '[data-x402-pay-fund-hint]' ); }

	function setStatus( msg ) {
		var el = statusEl();
		if ( el ) el.textContent = msg;
	}

	// Swap the buttons block for the status container. Called when a provider
	// begins a multi-step flow (wallet connect → switch chain → sign → settle)
	// so the visitor sees one live message at a time instead of buttons +
	// status side-by-side. The "buy USDC on …" funding hint goes with the
	// buttons — by this point the visitor is past that step.
	function beginFlow( msg ) {
		var p = providersEl();
		var f = flowEl();
		var h = fundHintEl();
		if ( p ) p.hidden = true;
		if ( f ) f.hidden = false;
		if ( h ) h.hidden = true;
		setStatus( msg || '' );
	}

	// Restore the buttons block. Called on modal dismiss after a failure so the
	// visitor can retry with the same wallet or pick a different one. Settled
	// success doesn't go through here — the host replaces the document body.
	function endFlow() {
		var p = providersEl();
		var f = flowEl();
		var h = fundHintEl();
		if ( f ) f.hidden = true;
		if ( p ) p.hidden = false;
		if ( h ) h.hidden = false;
		setStatus( '' );
	}

	var lastFocusedBeforeModal = null;

	function showError( msg ) {
		var modal = modalEl();
		var msgNode = modalMsgEl();
		if ( msgNode ) msgNode.textContent = msg || 'Something went wrong.';
		if ( ! modal ) {
			// Modal markup missing — fall back to inline status so the visitor
			// still sees the error.
			setStatus( msg || 'Payment failed.' );
			return;
		}
		lastFocusedBeforeModal = document.activeElement;
		modal.hidden = false;
		var closeBtn = modal.querySelector( '.x402-pay-modal__close' );
		if ( closeBtn && typeof closeBtn.focus === 'function' ) {
			closeBtn.focus();
		}
	}

	function dismissModal() {
		var modal = modalEl();
		if ( modal ) modal.hidden = true;
		endFlow();
		if ( lastFocusedBeforeModal && typeof lastFocusedBeforeModal.focus === 'function' ) {
			try { lastFocusedBeforeModal.focus(); } catch ( _ ) {}
		}
		lastFocusedBeforeModal = null;
	}

	function wireModal() {
		var modal = modalEl();
		if ( ! modal ) return;
		Array.prototype.forEach.call(
			modal.querySelectorAll( '[data-x402-pay-modal-close]' ),
			function ( el ) {
				el.addEventListener( 'click', dismissModal );
			}
		);
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! modal.hidden ) {
				dismissModal();
			}
		} );
	}

	/**
	 * Wrap a provider's `{ scheme, payload }` in the x402 envelope, replay the
	 * paywalled URL with the resulting X-PAYMENT header, and either swap the
	 * document on success or surface the facilitator's error via the modal.
	 */
	async function retry( signedFragment ) {
		setStatus( 'Settling payment…' );
		var envelope = {
			x402Version: 1,
			scheme: signedFragment.scheme,
			network: ctx.requirements.network,
			payload: signedFragment.payload,
		};
		var headerVal = btoa( JSON.stringify( envelope ) );
		var resp = await fetch( ctx.resourceUrl, {
			headers: { 'X-PAYMENT': headerVal },
			credentials: 'same-origin',
			cache: 'no-store',
		} );
		if ( resp.ok ) {
			setStatus( 'Paid. Loading…' );
			var html = await resp.text();
			document.open();
			document.write( html );
			document.close();
			return;
		}
		var detail = '';
		try { detail = ( await resp.json() ).error || ''; } catch ( _ ) {}
		showError( 'Settlement failed: ' + ( detail || resp.status ) );
		throw new Error( detail || 'settlement_failed' );
	}

	function dispatch() {
		var slots = document.querySelectorAll( '[data-x402-pay-provider]' );
		Array.prototype.forEach.call( slots, function ( slot ) {
			var id = slot.getAttribute( 'data-x402-pay-provider' );
			var entry = registry[ id ];
			if ( ! entry ) return;
			if ( entry.dispatched ) return;
			entry.dispatched = true;
			var providerCtx = ctx.providers[ id ] || {};
			Promise.resolve(
				entry.callback( {
					container: slot,
					requirements: ctx.requirements,
					resourceUrl: ctx.resourceUrl,
					config: providerCtx.config || {},
					retry: retry,
					setStatus: setStatus,
					beginFlow: beginFlow,
					endFlow: endFlow,
					showError: showError,
				} )
			).catch( function ( err ) {
				console.error( '[x402-pay] provider "' + id + '" failed to mount:', err );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', wireModal, { once: true } );
	} else {
		wireModal();
	}

	window.x402Pay = {
		registerProvider: function ( id, callback ) {
			if ( registry[ id ] ) {
				console.warn( '[x402-pay] provider "' + id + '" registered twice; ignoring duplicate.' );
				return;
			}
			registry[ id ] = { callback: callback, dispatched: false };
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', dispatch, { once: false } );
			} else {
				dispatch();
			}
		},
	};
})();
