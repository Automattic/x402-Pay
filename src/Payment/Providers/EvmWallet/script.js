/**
 * EIP-6963 wallet discovery + EIP-712 signing.
 *
 * Detects browser-extension wallets via the EIP-6963 "Multi Injected Provider
 * Discovery" protocol and renders a row per detected wallet using the
 * announced icon + name. On click, builds the EIP-3009
 * `TransferWithAuthorization` typed data, asks the announced provider to
 * sign it, and hands the signature to the host's `retry()` so the original
 * request is replayed with `X-PAYMENT`. Mirrors what
 * `scripts/pay.mjs` does in Node, but uses raw `provider.request` calls
 * instead of viem.
 *
 * Spec: https://eips.ethereum.org/EIPS/eip-6963
 */
( function () {
	if ( ! window.x402press || typeof window.x402press.registerProvider !== 'function' ) {
		console.error( '[x402press] evm-wallet provider loaded before host; skipping.' );
		return;
	}

	// EVM networks supported by the x402 facilitator profiles. Kept tight:
	// adding a network on the PHP side without adding it here surfaces as a
	// clear "Unsupported network" error rather than a silently-wrong signature.
	var NETWORKS = {
		'base': {
			chainId: 8453,
			chainName: 'Base',
			rpcUrls: [ 'https://mainnet.base.org' ],
			nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
			blockExplorerUrls: [ 'https://basescan.org' ],
		},
		'base-sepolia': {
			chainId: 84532,
			chainName: 'Base Sepolia',
			rpcUrls: [ 'https://sepolia.base.org' ],
			nativeCurrency: { name: 'Sepolia Ether', symbol: 'ETH', decimals: 18 },
			blockExplorerUrls: [ 'https://sepolia.basescan.org' ],
		},
	};

	// Popular wallets we surface as install links when they're NOT
	// announced via EIP-6963. Match key is `rdns` (reverse-DNS, the
	// stable identifier each wallet emits).
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

	function networkConfigFor( network ) {
		var config = NETWORKS[ network ];
		if ( ! config ) {
			throw new Error( 'Unsupported network: ' + network );
		}
		return config;
	}

	function chainIdHex( chainId ) {
		return '0x' + Number( chainId ).toString( 16 );
	}

	function walletErrorCode( error ) {
		if ( ! error ) return null;
		if ( error.code ) return error.code;
		if ( error.data && error.data.originalError && error.data.originalError.code ) {
			return error.data.originalError.code;
		}
		return null;
	}

	async function ensureWalletChain( provider, requirements, setStatus, walletName ) {
		var config = networkConfigFor( requirements.network );
		var targetChainId = chainIdHex( config.chainId );

		try {
			var activeChainId = await provider.request( { method: 'eth_chainId' } );
			if (
				typeof activeChainId === 'string' &&
				activeChainId.toLowerCase() === targetChainId.toLowerCase()
			) {
				return;
			}
		} catch ( _ ) {}

		setStatus( 'Switch ' + walletName + ' to ' + config.chainName + '…' );
		try {
			await provider.request( {
				method: 'wallet_switchEthereumChain',
				params: [ { chainId: targetChainId } ],
			} );
		} catch ( e ) {
			if ( 4902 !== walletErrorCode( e ) ) {
				throw e;
			}
			setStatus( 'Add ' + config.chainName + ' to ' + walletName + '…' );
			await provider.request( {
				method: 'wallet_addEthereumChain',
				params: [
					{
						chainId: targetChainId,
						chainName: config.chainName,
						nativeCurrency: config.nativeCurrency,
						rpcUrls: config.rpcUrls,
						blockExplorerUrls: config.blockExplorerUrls,
					},
				],
			} );
			await provider.request( {
				method: 'wallet_switchEthereumChain',
				params: [ { chainId: targetChainId } ],
			} );
		}
	}

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
		var chainId = networkConfigFor( network ).chainId;
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

	function sanitizeIconSrc( src ) {
		if ( typeof src !== 'string' ) return '';
		var value = src.trim();
		if ( ! value ) return '';
		if ( /[\u0000-\u001f\u007f<>]/.test( value ) ) return '';
		try {
			var parsed = new URL( value, document.baseURI );
			if ( parsed.protocol === 'https:' || parsed.protocol === 'http:' ) {
				return parsed.href;
			}
		} catch ( _ ) {}
		if ( /^data:image\/(?:png|gif|jpe?g|webp|svg\+xml);/i.test( value ) ) {
			return value;
		}
		return '';
	}

	window.x402press.registerProvider( 'evm-wallet', function ( host ) {
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

				await ensureWalletChain(
					provider,
					host.requirements,
					host.setStatus,
					info.name || 'your wallet'
				);

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
				var code = walletErrorCode( e );
				var message = ( 4001 === code )
					? 'wallet request was rejected'
					: ( ( e && e.message ) || 'unknown error' );
				host.setStatus( 'Payment cancelled: ' + message );
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
			button.className = 'x402press-pay-button';
			button.setAttribute( 'data-wallet-rdns', key );

			// The wallet's own icon (typically a data URI). Rendering it
			// inside `.x402press-pay-icon` picks up the existing border-radius
			// so EIP-6963 wallets line up visually with built-in providers.
			var iconSpan = document.createElement( 'span' );
			iconSpan.className = 'x402press-pay-icon';
			iconSpan.setAttribute( 'aria-hidden', 'true' );
			var iconSrc = sanitizeIconSrc( info.icon );
			if ( iconSrc ) {
				var img = document.createElement( 'img' );
				img.src = iconSrc;
				img.alt = '';
				iconSpan.appendChild( img );
			}
			button.appendChild( iconSpan );

			var labelSpan = document.createElement( 'span' );
			labelSpan.className = 'x402press-pay-label';
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
			divider.className = 'x402press-section-divider';
			divider.textContent = ( wallets.size > 0 )
				? 'or get a wallet'
				: 'don’t have a wallet?';
			host.container.appendChild( divider );

			missing.forEach( function ( w ) {
				var link = document.createElement( 'a' );
				link.className = 'x402press-pay-button x402press-pay-button--install';
				link.href = w.installUrl;
				link.target = '_blank';
				link.rel = 'noopener noreferrer';

				var label = document.createElement( 'span' );
				label.className = 'x402press-pay-label';
				label.textContent = 'Install ' + w.name;
				link.appendChild( label );

				var meta = document.createElement( 'span' );
				meta.className = 'x402press-pay-meta';
				meta.setAttribute( 'aria-hidden', 'true' );
				meta.textContent = '↗';
				link.appendChild( meta );

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
