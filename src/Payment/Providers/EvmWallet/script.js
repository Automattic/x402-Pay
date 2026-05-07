/**
 * EIP-6963 wallet discovery + button rendering.
 *
 * The dApp side of the "Multi Injected Provider Discovery" protocol —
 * dispatches `eip6963:requestProvider` to ask installed wallet extensions
 * to announce themselves, and listens for `eip6963:announceProvider` events
 * each wallet emits. One row per unique wallet (deduped by `info.rdns`)
 * is rendered using the announced icon + name.
 *
 * Spec: https://eips.ethereum.org/EIPS/eip-6963
 *
 * Phase 2 stub: clicking a row surfaces "Signing not yet wired up" via the
 * host status line. The actual EIP-712 typed-data signing comes in Phase 3.
 */
( function () {
	if ( ! window.simpleX402 || typeof window.simpleX402.registerProvider !== 'function' ) {
		console.error( '[sx402] evm-wallet provider loaded before host; skipping.' );
		return;
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
			labelSpan.textContent = ( typeof info.name === 'string' && info.name )
				? info.name
				: 'Browser wallet';
			button.appendChild( labelSpan );

			button.addEventListener( 'click', function () {
				// Phase 3 will replace this with a real signTypedData_v4 call
				// against `announce.provider`, building EIP-712 payment
				// requirements from `host.requirements`.
				host.setStatus(
					'Signing with ' + ( info.name || 'this wallet' )
					+ ' is not wired up yet (coming next iteration).'
				);
			} );

			host.container.appendChild( button );
		}

		function onAnnounce( ev ) {
			if ( ! ev || ! ev.detail ) return;
			renderRow( ev.detail );
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
	} );
} )();
