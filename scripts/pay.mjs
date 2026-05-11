#!/usr/bin/env node
// Smoke-test client for x402press. Pays for a paywalled URL on Base Sepolia.
//
// Usage: PRIVATE_KEY=0x... node pay.mjs <url>
//
// Wallet must hold Base Sepolia USDC. No ETH needed — the x402.org facilitator
// pays gas via EIP-3009 transferWithAuthorization.

import { privateKeyToAccount } from 'viem/accounts';
import { randomBytes } from 'node:crypto';

const url = process.argv[2];
const privateKey = process.env.PRIVATE_KEY;

if ( ! url || ! privateKey ) {
	console.error( 'Usage: PRIVATE_KEY=0x... node pay.mjs <url>' );
	process.exit( 1 );
}

const USDC_BASE_SEPOLIA = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';

const account = privateKeyToAccount( privateKey );
console.log( `Wallet: ${ account.address }` );
console.log( `Target: ${ url }` );

const first = await fetch( url );
console.log( `\n[1] GET   → ${ first.status }` );

if ( first.status !== 402 ) {
	console.log( await first.text() );
	process.exit( first.ok ? 0 : 1 );
}

const challenge = first.headers.get( 'payment-required' );
if ( ! challenge ) {
	console.error( 'Missing PAYMENT-REQUIRED header on 402 response' );
	process.exit( 1 );
}

const requirements = JSON.parse( Buffer.from( challenge, 'base64' ).toString( 'utf8' ) );
console.log( '    requirements:', requirements );

const now = Math.floor( Date.now() / 1000 );
const authorization = {
	from: account.address,
	to: requirements.payTo,
	value: requirements.maxAmountRequired,
	validAfter: '0',
	validBefore: String( now + ( requirements.maxTimeoutSeconds ?? 120 ) ),
	nonce: '0x' + randomBytes( 32 ).toString( 'hex' ),
};

const signature = await account.signTypedData( {
	domain: {
		name: 'USDC',
		version: '2',
		chainId: 84532,
		verifyingContract: USDC_BASE_SEPOLIA,
	},
	types: {
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
	message: {
		from: authorization.from,
		to: authorization.to,
		value: BigInt( authorization.value ),
		validAfter: BigInt( authorization.validAfter ),
		validBefore: BigInt( authorization.validBefore ),
		nonce: authorization.nonce,
	},
} );

const paymentPayload = {
	x402Version: 1,
	scheme: requirements.scheme,
	network: requirements.network,
	payload: { signature, authorization },
};

const header = Buffer.from( JSON.stringify( paymentPayload ) ).toString( 'base64' );

console.log( '\n    signed authorization:', {
	from: authorization.from,
	to: authorization.to,
	value: authorization.value,
	validBefore: authorization.validBefore,
	nonce: authorization.nonce.slice( 0, 18 ) + '…',
} );
console.log( '    retry header: Payment-Signature (base64 length ' + header.length + ')' );

const second = await fetch( url, {
	headers: { 'Payment-Signature': header },
} );
console.log( `\n[2] RETRY → ${ second.status }` );
const body = await second.text();
console.log( body.length > 1200 ? body.slice( 0, 1200 ) + '\n...[truncated]' : body );
try {
	const j = JSON.parse( body );
	if ( j.error ) {
		console.log( '    parsed error:', j.error, j.reason != null ? '(' + j.reason + ')' : '' );
	}
} catch {
	// not JSON
}
process.exit( second.ok ? 0 : 1 );
