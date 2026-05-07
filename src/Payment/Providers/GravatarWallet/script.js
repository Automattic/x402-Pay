/**
 * Gravatar Hosted Wallet payment provider.
 *
 * Opens https://gravatar.com/wallet/authorize in a popup, awaits the
 * EIP-3009 TransferWithAuthorization signature via postMessage, and hands
 * the result to the host's `retry()` helper to replay the original request.
 *
 * Strict checks: event.source === popup, event.origin === gravatarOrigin,
 * `type` is a string. Cleans up on settle, best-effort popup.close() on
 * reject. The wallet only signs USDC on Base mainnet — other networks will
 * sign but the resulting authorization won't settle.
 */
( function () {
	if ( ! window.simpleX402 || typeof window.simpleX402.registerProvider !== 'function' ) {
		console.error( '[sx402] gravatar-wallet provider loaded before host; skipping.' );
		return;
	}

	var TIMEOUT_MS = 300000;
	var MAX_VALUE = 100000000n;

	function randomNonce() {
		var arr = new Uint8Array( 32 );
		crypto.getRandomValues( arr );
		return '0x' + Array.from( arr ).map( function ( b ) { return b.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
	}

	function requestSignature( gravatarOrigin, params ) {
		var v;
		try { v = BigInt( params.value ); } catch ( _ ) {
			return Promise.reject( new Error( 'value is not an integer' ) );
		}
		if ( v <= 0n || v > MAX_VALUE ) {
			return Promise.reject( new Error( 'value out of range (0 < value <= 100000000)' ) );
		}
		var nonce = randomNonce();
		var qs = new URLSearchParams( {
			to: params.to,
			value: v.toString( 10 ),
			validAfter: String( params.validAfter ),
			validBefore: String( params.validBefore ),
			nonce: nonce,
			origin: window.location.origin,
		} );
		var popup = window.open(
			gravatarOrigin + '/wallet/authorize?' + qs.toString(),
			'gravatar-wallet-authorize',
			'width=480,height=720'
		);
		if ( ! popup ) return Promise.reject( new Error( 'popup blocked' ) );

		return new Promise( function ( resolve, reject ) {
			var settled = false;
			function cleanup() {
				if ( settled ) return;
				settled = true;
				window.removeEventListener( 'message', handler );
				clearInterval( watch );
				clearTimeout( timer );
			}
			function handler( ev ) {
				if ( ev.source !== popup ) return;
				if ( ev.origin !== gravatarOrigin ) return;
				var msg = ev.data;
				if ( ! msg || typeof msg !== 'object' || typeof msg.type !== 'string' ) return;
				if ( msg.type === 'gravatar-wallet:signed' ) {
					cleanup();
					resolve( msg );
				} else if ( msg.type === 'gravatar-wallet:cancelled' ) {
					cleanup();
					try { popup.close(); } catch ( _ ) {}
					reject( new Error( 'user cancelled' ) );
				}
			}
			var watch = setInterval( function () {
				if ( ! settled && popup.closed ) {
					cleanup();
					reject( new Error( 'wallet popup closed before responding' ) );
				}
			}, 500 );
			var timer = setTimeout( function () {
				cleanup();
				try { popup.close(); } catch ( _ ) {}
				reject( new Error( 'wallet popup timed out' ) );
			}, TIMEOUT_MS );
			window.addEventListener( 'message', handler );
		} );
	}

	window.simpleX402.registerProvider( 'gravatar-wallet', function ( host ) {
		var gravatarOrigin = host.config.gravatarOrigin;
		if ( host.requirements.network !== 'base' ) {
			console.warn(
				'[sx402] Gravatar Wallet only signs USDC on Base mainnet. ' +
				'Your facilitator is on "' + host.requirements.network +
				'" — the signature will not settle against this network.'
			);
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'sx402-pay-button';
		button.textContent = 'Pay with Gravatar Wallet';
		host.container.appendChild( button );

		button.addEventListener( 'click', async function () {
			button.disabled = true;
			host.setStatus( 'Opening Gravatar Wallet…' );

			var now = Math.floor( Date.now() / 1000 );
			var signed;
			try {
				signed = await requestSignature( gravatarOrigin, {
					to: host.requirements.payTo,
					value: host.requirements.maxAmountRequired,
					validAfter: now - 1,
					validBefore: now + 600,
				} );
			} catch ( e ) {
				host.setStatus( 'Payment cancelled: ' + ( ( e && e.message ) || 'unknown error' ) );
				button.disabled = false;
				return;
			}

			try {
				await host.retry( {
					scheme: 'exact',
					payload: {
						signature: signed.signature,
						authorization: {
							from: signed.from,
							to: signed.to,
							value: signed.value,
							validAfter: signed.validAfter,
							validBefore: signed.validBefore,
							nonce: signed.nonce,
						},
					},
				} );
			} catch ( _ ) {
				button.disabled = false;
			}
		} );
	} );
} )();
