/**
 * EIP-6963 wallet discovery + EIP-712 signing.
 *
 * Detects browser-extension wallets via the EIP-6963 "Multi Injected Provider
 * Discovery" protocol and renders a row per detected wallet using the
 * announced icon + name. On click, builds the EIP-3009
 * `TransferWithAuthorization` typed data, asks the announced provider to
 * sign it, and hands the signature to the host's `retry()` so the original
 * request is replayed with `Payment-Signature`. Mirrors what
 * `scripts/pay.mjs` does in Node, but uses raw `provider.request` calls
 * instead of viem.
 *
 * Spec: https://eips.ethereum.org/EIPS/eip-6963
 */
( function () {
	if ( ! window.simpleX402 || typeof window.simpleX402.registerProvider !== 'function' ) {
		console.error( '[sx402] evm-wallet provider loaded before host; skipping.' );
		return;
	}

	// Network → EVM chainId. Kept tight: only what the plugin actually
	// supports today. Adding a network on the PHP side without adding
	// it here surfaces as a clear "Unsupported network" error rather
	// than a silently-wrong signature.
	var CHAIN_IDS = {
		'base': 8453,
		'base-sepolia': 84532,
	};

	// Popular wallets we surface as install links when they're NOT
	// announced via EIP-6963. Match key is `rdns` (reverse-DNS, the
	// stable identifier each wallet emits). Intentionally text-only —
	// drawing a 24×24 approximation of a brand glyph reads as "fake
	// logo," and the row's job here is just "go install this," not
	// "be visually identifiable as the brand."
	var SUGGESTED_WALLETS = [
		{ rdns: 'io.metamask',         name: 'MetaMask',        installUrl: 'https://metamask.io/download/' },
		{ rdns: 'me.rainbow',          name: 'Rainbow',         installUrl: 'https://rainbow.me/download/' },
		{ rdns: 'com.coinbase.wallet', name: 'Coinbase Wallet', installUrl: 'https://www.coinbase.com/wallet/downloads' },
	];

	// How long after the EIP-6963 request to wait before deciding which
	// suggested wallets are missing. Most wallets respond synchronously,
	// but a few initialise after the dApp script and announce slightly
	// later. 500ms is comfortably above the 99th-percentile init time
	// for popular extensions and well below "the page feels slow."
	var SUGGESTION_DELAY_MS = 500;

	function randomNonce32() {
		var arr = new Uint8Array( 32 );
		crypto.getRandomValues( arr );
		return '0x' + Array.from( arr ).map( function ( b ) {
			return b.toString( 16 ).padStart( 2, '0' );
		} ).join( '' );
	}

	/**
	 * Build the EIP-712 typed-data object for a `TransferWithAuthorization`
	 * signing request, matching what the x402 facilitator expects on the
	 * /verify path. `host.requirements.extra.{name,version}` come from the
	 * facilitator profile via PaymentRequirementsBuilder; `chainId` is
	 * derived from the network string (we don't ship it on the server side
	 * because the network already implies it).
	 */
	function buildTypedData( requirements, fromAddress ) {
		var network = requirements.network;
		var chainId = CHAIN_IDS[ network ];
		if ( ! chainId ) {
			throw new Error( 'Unsupported network: ' + network );
		}
		var extra      = requirements.extra || {};
		var domainName = extra.name;
		var version    = extra.version;
		if ( ! domainName || ! version ) {
			throw new Error( 'EIP-712 domain (name/version) missing from PaymentRequirements.extra' );
		}

		var now         = Math.floor( Date.now() / 1000 );
		var validBefore = now + ( Number( requirements.maxTimeoutSeconds ) || 120 );

		var authorization = {
			from: fromAddress,
			to: requirements.payTo,
			value: String( requirements.maxAmountRequired ),
			validAfter: '0',
			validBefore: String( validBefore ),
			nonce: randomNonce32(),
		};

		var typedData = {
			types: {
				EIP712Domain: [
					{ name: 'name', type: 'string' },
					{ name: 'version', type: 'string' },
					{ name: 'chainId', type: 'uint256' },
					{ name: 'verifyingContract', type: 'address' },
				],
				TransferWithAuthorization: [
					{ name: 'from', type: 'address' },
					{ name: 'to', type: 'address' },
					{ name: 'value', type: 'uint256' },
					{ name: 'validAfter', type: 'uint256' },
					{ name: 'validBefore', type: 'uint256' },
					{ name: 'nonce', type: 'bytes32' },
				],
			},
			primaryType: 'TransferWithAuthorization',
			domain: {
				name: domainName,
				version: version,
				chainId: chainId,
				verifyingContract: requirements.asset,
			},
			message: authorization,
		};

		return { typedData: typedData, authorization: authorization };
	}

	window.simpleX402.registerProvider( 'evm-wallet', function ( host ) {
		// Wallets keyed by `rdns` (reverse-DNS identifier — stable across
		// versions, unique per extension). Lets multiple installs of the
		// same wallet — or wallets that announce twice — collapse to one row.
		var wallets = new Map();

		function rowKey( info ) {
			return ( info && typeof info.rdns === 'string' && info.rdns )
				? info.rdns
				: ( info && info.uuid ) || '';
		}

		async function payWith( announce, button ) {
			var info = announce.info || {};
			var provider = announce.provider;

			button.disabled = true;
			host.setStatus( 'Connecting to ' + ( info.name || 'wallet' ) + '…' );

			try {
				var accounts = await provider.request( { method: 'eth_requestAccounts' } );
				var from = Array.isArray( accounts ) ? accounts[ 0 ] : null;
				if ( ! from ) {
					throw new Error( 'no account returned' );
				}

				host.setStatus( 'Sign the payment in ' + ( info.name || 'your wallet' ) + '…' );
				var built = buildTypedData( host.requirements, from );

				var signature = await provider.request( {
					method: 'eth_signTypedData_v4',
					params: [ from, JSON.stringify( built.typedData ) ],
				} );

				await host.retry( {
					scheme: 'exact',
					payload: {
						signature: signature,
						authorization: built.authorization,
					},
				} );
			} catch ( e ) {
				host.setStatus(
					'Payment cancelled: ' + ( ( e && e.message ) || 'unknown error' )
				);
				button.disabled = false;
			}
		}

		function renderRow( announce ) {
			var info = announce.info || {};
			var key = rowKey( info );
			if ( ! key || wallets.has( key ) ) return;
			wallets.set( key, announce );

			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'sx402-pay-button';
			button.setAttribute( 'data-wallet-rdns', key );

			// The wallet's own icon (typically a data URI). Rendering it
			// inside `.sx402-pay-icon` picks up the existing border-radius
			// so EIP-6963 wallets line up visually with built-in providers.
			var iconSpan = document.createElement( 'span' );
			iconSpan.className = 'sx402-pay-icon';
			iconSpan.setAttribute( 'aria-hidden', 'true' );
			if ( typeof info.icon === 'string' && info.icon ) {
				var img = document.createElement( 'img' );
				img.src = info.icon;
				img.alt = '';
				iconSpan.appendChild( img );
			}
			button.appendChild( iconSpan );

			var labelSpan = document.createElement( 'span' );
			labelSpan.className = 'sx402-pay-label';
			// Match the "Pay with <provider>" pattern the built-in Gravatar
			// row uses, so detected wallets and built-in providers read the
			// same in the list.
			labelSpan.textContent = ( typeof info.name === 'string' && info.name )
				? 'Pay with ' + info.name
				: 'Pay with this wallet';
			button.appendChild( labelSpan );

			button.addEventListener( 'click', function () {
				payWith( announce, button );
			} );

			host.container.appendChild( button );
		}

		function onAnnounce( ev ) {
			if ( ! ev || ! ev.detail ) return;
			renderRow( ev.detail );
		}

		var suggestionsRendered = false;

		function renderSuggestions() {
			if ( suggestionsRendered ) return;
			suggestionsRendered = true;

			var missing = SUGGESTED_WALLETS.filter( function ( w ) {
				return ! wallets.has( w.rdns );
			} );
			if ( 0 === missing.length ) return;

			// Section divider — only rendered when we have something to
			// suggest. Empty state is a no-op so detected-wallet users
			// don't see a vestigial header.
			var divider = document.createElement( 'div' );
			divider.className = 'sx402-section-divider';
			divider.textContent = ( wallets.size > 0 )
				? 'or get a wallet'
				: 'don’t have a wallet?';
			host.container.appendChild( divider );

			missing.forEach( function ( w ) {
				var link = document.createElement( 'a' );
				link.className = 'sx402-pay-button sx402-pay-button--install';
				link.href = w.installUrl;
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				// Text + trailing arrow only. No icon — see SUGGESTED_WALLETS
				// comment.
				link.innerHTML = ''
					+ '<span class="sx402-pay-label">Install ' + w.name + '</span>'
					+ '<span class="sx402-pay-meta" aria-hidden="true">↗</span>';
				host.container.appendChild( link );
			} );
		}

		// Listen first, then ask. Wallets respond synchronously to the
		// request, so attaching the listener before dispatching avoids
		// missing the immediate replies.
		window.addEventListener( 'eip6963:announceProvider', onAnnounce );
		window.dispatchEvent( new Event( 'eip6963:requestProvider' ) );

		// Some wallets initialize after the dApp has loaded and announce
		// themselves spontaneously. Keep the listener attached for the
		// lifetime of the page — providers cleaned up when the page
		// unloads. No teardown logic needed.

		// Wait briefly for late announcements before deciding which
		// suggested wallets are missing. If a wallet announces after the
		// suggestions render it just won't be deduped from the install
		// list, which is a minor cosmetic glitch — not worth the
		// additional teardown logic.
		setTimeout( renderSuggestions, SUGGESTION_DELAY_MS );
	} );
} )();
