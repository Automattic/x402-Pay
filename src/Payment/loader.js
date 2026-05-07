/**
 * x402 payment host loader.
 *
 * Loaded once per 402 page. Exposes window.simpleX402.registerProvider(id, cb)
 * so each enqueued provider script can attach its own button. After all
 * providers register, the host walks every <div data-sx402-provider="..."></div>
 * slot and invokes the matching callback with helpers shared across providers
 * (envelope construction, retry fetch, status line, eligibility-mismatch
 * warnings).
 */
(function () {
	if ( window.simpleX402 ) return;

	var contextEl = document.getElementById( 'sx402-payment-context' );
	if ( ! contextEl ) return;

	var ctx;
	try {
		ctx = JSON.parse( contextEl.textContent );
	} catch ( _ ) {
		return;
	}

	var registry = Object.create( null );
	var statusEl = document.getElementById( 'sx402-status' );

	function setStatus( msg ) {
		if ( statusEl ) statusEl.textContent = msg;
	}

	/**
	 * Wrap a provider's `{ scheme, payload }` in the x402 envelope, replay the
	 * paywalled URL with the resulting Payment-Signature header, and either
	 * swap the document on success or surface the facilitator's error.
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
			headers: { 'Payment-Signature': headerVal },
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
		setStatus( 'Settlement failed: ' + ( detail || resp.status ) );
		throw new Error( detail || 'settlement_failed' );
	}

	function dispatch() {
		var slots = document.querySelectorAll( '[data-sx402-provider]' );
		Array.prototype.forEach.call( slots, function ( slot ) {
			var id = slot.getAttribute( 'data-sx402-provider' );
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
				} )
			).catch( function ( err ) {
				console.error( '[sx402] provider "' + id + '" failed to mount:', err );
			} );
		} );
	}

	window.simpleX402 = {
		registerProvider: function ( id, callback ) {
			if ( registry[ id ] ) {
				console.warn( '[sx402] provider "' + id + '" registered twice; ignoring duplicate.' );
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
