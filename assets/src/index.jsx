import { createInterpolateElement, createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	TextControl,
	TextareaControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';

import './style.scss';

const config = window.simpleX402Settings;

const boltIcon = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
		<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z" fill="currentColor" />
	</svg>
);

const spinnerIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="16"
		height="16"
		aria-hidden="true"
		focusable="false"
		className="simple-x402-spinner-icon"
	>
		<circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="2" fill="none" strokeOpacity="0.25" />
		<path d="M12 3 A9 9 0 0 1 21 12" stroke="currentColor" strokeWidth="2" fill="none" strokeLinecap="round" />
	</svg>
);

const clockIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="16"
		height="16"
		aria-hidden="true"
		focusable="false"
	>
		<circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="2" fill="none" />
		<path d="M12 7 v5 l3 2" stroke="currentColor" strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round" />
	</svg>
);

async function saveFields( partial ) {
	const body = new FormData();
	body.append( 'action', config.saveSettings.action );
	body.append( 'nonce', config.saveSettings.nonce );
	body.append( 'fields', JSON.stringify( partial ) );
	const resp = await fetch( config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
	} );
	// A reverse-proxy or PHP fatal returns an HTML page, not JSON. Parse
	// defensively so the UI surfaces a clean "save_failed_http_502" instead
	// of a raw SyntaxError.
	let json = null;
	try {
		json = await resp.json();
	} catch ( _ ) {
		throw new Error( `save_failed_http_${ resp.status }` );
	}
	if ( ! resp.ok || ! json?.success ) {
		throw new Error( json?.data?.error || `save_failed_http_${ resp.status }` );
	}
	return json.data;
}

const PROBE_HEADER = 'X-Simple-X402-Probe';

/**
 * @param {{ url: string, nonce: string }} probe
 * @returns {Promise<string|null>} null if the response looks like a healthy paywall 402 (JSON or HTML body).
 */
async function runPaywallProbe( probe ) {
	const resp = await fetch( probe.url, {
		method: 'GET',
		credentials: 'same-origin',
		cache: 'no-store',
		headers: { [ PROBE_HEADER ]: probe.nonce },
	} );
	if ( resp.status !== 402 ) {
		return sprintf(
			/* translators: %s: HTTP status code. */
			__( 'Paywall probe: expected HTTP 402, got status %s.', 'simple-x402' ),
			String( resp.status )
		);
	}
	const ct = ( resp.headers.get( 'content-type' ) || '' ).toLowerCase();
	const isJson = ct.includes( 'application/json' );
	const isHtml = ct.includes( 'text/html' );
	if ( ! isJson && ! isHtml ) {
		return sprintf(
			/* translators: %s: Content-Type header value or placeholder. */
			__( 'Paywall probe: expected HTTP 402 with JSON or HTML body, got Content-Type %s.', 'simple-x402' ),
			resp.headers.get( 'content-type' ) || '(missing)'
		);
	}
	if ( isJson ) {
		try {
			await resp.json();
		} catch ( _ ) {
			return __( 'Paywall probe: response was not valid JSON.', 'simple-x402' );
		}
	}
	return null;
}

async function fetchPaywallProbeDescriptorFromServer() {
	const body = new FormData();
	body.append( 'action', config.paywallProbe.action );
	body.append( 'nonce', config.paywallProbe.nonce );
	const resp = await fetch( config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
	} );
	let json = null;
	try {
		json = await resp.json();
	} catch ( _ ) {
		throw new Error( `probe_descriptor_failed_http_${ resp.status }` );
	}
	if ( ! resp.ok || ! json?.success ) {
		throw new Error( json?.data?.error || `probe_descriptor_failed_http_${ resp.status }` );
	}
	return json.data;
}

/**
 * @param {string} connectorId
 * @returns {Promise<{ ok: boolean, duration_ms?: number, error?: string }>}
 */
async function runFacilitatorConnectivityAjax( connectorId ) {
	const body = new FormData();
	body.append( 'action', config.testConnection.action );
	body.append( 'nonce', config.testConnection.nonce );
	body.append( 'connector_id', connectorId );
	const resp = await fetch( config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
	} );
	let json = null;
	try {
		json = await resp.json();
	} catch ( _ ) {
		return { ok: false, error: `request_failed_http_${ resp.status }` };
	}
	if ( json?.success && json.data ) {
		return {
			ok: json.data.ok === true,
			duration_ms: json.data.duration_ms,
			error: json.data.error,
		};
	}
	return {
		ok: false,
		error: json?.data?.error || 'request_failed',
	};
}

/**
 * Shared result line for unified Run checks (facilitator connectivity + paywall probe).
 *
 * @param {object} props
 * @param {boolean} props.pending
 * @param {boolean} [props.awaiting] Run checks is in progress but this
 *                                   particular step hasn't started — render
 *                                   a placeholder so the row holds its space.
 * @param {boolean} [props.success]
 * @param {number} [props.durationMs]
 * @param {string} [props.failureMessage]
 * @param {string} [props.infoMessage] Skipped / informational (no ✓/✗).
 */
function DiagnosticProbeLine( { pending, awaiting, success, durationMs, failureMessage, infoMessage } ) {
	if ( pending ) {
		return (
			<HStack spacing={ 1 } alignment="left" justify="flex-start">
				<span className="simple-x402-page__inline-spinner" aria-hidden="true">
					{ spinnerIcon }
				</span>
				<Text size={ 13 } variant="muted">
					{ __( 'Running check…', 'simple-x402' ) }
				</Text>
			</HStack>
		);
	}
	if ( awaiting ) {
		return (
			<HStack spacing={ 1 } alignment="left" justify="flex-start">
				<span className="simple-x402-page__inline-spinner" aria-hidden="true">
					{ clockIcon }
				</span>
				<Text size={ 13 } variant="muted">
					{ __( 'Waiting for the previous step to finish…', 'simple-x402' ) }
				</Text>
			</HStack>
		);
	}
	if ( infoMessage ) {
		return (
			<Text size={ 13 } variant="muted">
				{ infoMessage }
			</Text>
		);
	}
	if ( success ) {
		return (
			<Text size={ 13 } variant="muted">
				{ durationMs != null
					? sprintf(
						/* translators: %d: round-trip time in milliseconds. */
						__( '✓ Succeeded in %dms', 'simple-x402' ),
						durationMs
					)
					: __( '✓ Succeeded', 'simple-x402' ) }
			</Text>
		);
	}
	if ( failureMessage ) {
		return (
			<Text size={ 13 } variant="muted">
				{ `✗ ${ failureMessage }` }
			</Text>
		);
	}
	return null;
}

function SaveFooter( { disabled, saving, error, onSave } ) {
	return (
		<CardFooter className="simple-x402-page__card-footer">
			<HStack spacing={ 3 } justify="flex-start">
				<Button
					variant="primary"
					type="button"
					onClick={ onSave }
					disabled={ disabled || saving }
					accessibleWhenDisabled
				>
					{ saving ? __( 'Saving…', 'simple-x402' ) : __( 'Save', 'simple-x402' ) }
				</Button>
				{ error && (
					<Text size={ 13 } variant="muted">
						{ `✗ ${ error }` }
					</Text>
				) }
			</HStack>
		</CardFooter>
	);
}

const isShallowEqual = ( a, b ) => {
	if ( a === b ) return true;
	if ( ! a || ! b ) return false;
	const keys = new Set( [ ...Object.keys( a ), ...Object.keys( b ) ] );
	for ( const k of keys ) {
		if ( String( a[ k ] ?? '' ) !== String( b[ k ] ?? '' ) ) return false;
	}
	return true;
};

// Per-card save loop: wraps an async call with `saving`/`error` tracking.
// The caller passes a function that returns a Promise; this hook runs it and
// surfaces the status. Doesn't touch local state — the caller handles syncing
// its own edits to the server response inside the callback.
function useSave() {
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const run = async ( fn ) => {
		setSaving( true );
		setError( null );
		try {
			await fn();
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setSaving( false );
		}
	};
	return { saving, error, run };
}

function CardTitle( { title, subtitle } ) {
	return (
		<VStack spacing={ 1 }>
			<Text size={ 14 } weight={ 600 }>
				{ title }
			</Text>
			{ subtitle && (
				<Text size={ 13 } variant="muted">
					{ subtitle }
				</Text>
			) }
		</VStack>
	);
}

const PAYWALL_MODE_FIELDS = [
	{
		id: 'paywallMode',
		label: '',
		type: 'text',
		Edit: 'radio',
		elements: [
			{ value: config.modes.paywall.none, label: __( 'No posts (paywall disabled)', 'simple-x402' ) },
			{ value: config.modes.paywall.allPosts, label: __( 'Every published post', 'simple-x402' ) },
			{ value: config.modes.paywall.category, label: __( 'Only posts in a specific category', 'simple-x402' ) },
		],
	},
	{
		id: 'termId',
		label: __( 'Category', 'simple-x402' ),
		type: 'text',
		Edit: 'select',
		elements: config.categories.map( ( c ) => ( {
			value: String( c.term_id ),
			label: c.name,
		} ) ),
		isDisabled: ( { item } ) => item.paywallMode !== config.modes.paywall.category,
	},
];

/**
 * @param {object} props
 * @param {boolean} props.paywallDirty
 * @param {boolean} props.facilitatorDirty
 * @param {boolean} props.runChecksPending
 * @param {() => void} props.onRunChecks
 * @param {object|null} props.facilitatorCheck
 * @param {object|null} props.paywallCheck
 */
function RunChecksCard( {
	paywallDirty,
	facilitatorDirty,
	runChecksPending,
	onRunChecks,
	facilitatorCheck,
	paywallCheck,
} ) {
	const showSteps =
		runChecksPending ||
		facilitatorCheck != null ||
		paywallCheck != null;

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Connection & paywall checks', 'simple-x402' ) }
					subtitle={ __(
						'Verify facilitator reachability, then probe the live paywall on a matching post.',
						'simple-x402'
					) }
				/>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 3 }>
					{ facilitatorDirty && (
						<Text size={ 13 } variant="muted">
							{ __(
								'Save your facilitator settings before running checks.',
								'simple-x402'
							) }
						</Text>
					) }
					{ paywallDirty && (
						<Text size={ 13 } variant="muted">
							{ __(
								'Save your paywall scope changes before running checks.',
								'simple-x402'
							) }
						</Text>
					) }
					{ showSteps && (
						<VStack spacing={ 3 }>
							<VStack spacing={ 0 } className="simple-x402-page__run-checks-step">
								<Text size={ 12 } weight={ 600 }>
									{ __( '1. Facilitator connectivity', 'simple-x402' ) }
								</Text>
								<DiagnosticProbeLine
									pending={ facilitatorCheck?.pending === true }
									awaiting={ runChecksPending && facilitatorCheck == null }
									success={ facilitatorCheck?.success === true }
									durationMs={ facilitatorCheck?.durationMs }
									failureMessage={ facilitatorCheck?.failureMessage }
									infoMessage={ facilitatorCheck?.infoMessage }
								/>
							</VStack>
							<VStack spacing={ 0 } className="simple-x402-page__run-checks-step">
								<Text size={ 12 } weight={ 600 }>
									{ __( '2. Paywall live probe', 'simple-x402' ) }
								</Text>
								<DiagnosticProbeLine
									pending={ paywallCheck?.pending === true }
									awaiting={ runChecksPending && paywallCheck == null }
									success={ paywallCheck?.success === true }
									durationMs={ paywallCheck?.durationMs }
									failureMessage={ paywallCheck?.failureMessage }
									infoMessage={ paywallCheck?.infoMessage }
								/>
							</VStack>
						</VStack>
					) }
					<HStack spacing={ 3 } justify="flex-start" className="simple-x402-page__probe-row">
						<Button
							variant="primary"
							size="compact"
							type="button"
							icon={ boltIcon }
							iconSize={ 16 }
							onClick={ onRunChecks }
							disabled={
								paywallDirty || facilitatorDirty || runChecksPending
							}
							accessibleWhenDisabled
							aria-busy={ runChecksPending }
						>
							{ runChecksPending
								? __( 'Running checks…', 'simple-x402' )
								: __( 'Run checks', 'simple-x402' ) }
						</Button>
					</HStack>
				</VStack>
			</CardBody>
		</Card>
	);
}

function PaywallScopeCard( {
	saved,
	save,
	paywallMode,
	setPaywallMode,
	termId,
	setTermId,
	onPaywallFieldsChange,
	beginPaywallSaveProbeSession,
	runPaywallProbeFollowThrough,
} ) {
	const { saving, error, run } = useSave();

	const isDirty =
		paywallMode !== saved.paywall_mode ||
		Number( termId ) !== Number( saved.paywall_category_term_id );

	const onSave = () =>
		run( async () => {
			const rid = beginPaywallSaveProbeSession();

			const { values: merged, ajaxData: data } = await save( {
				paywall_mode: paywallMode,
				paywall_category_term_id: termId,
			} );
			setPaywallMode( merged.paywall_mode );
			setTermId( merged.paywall_category_term_id );

			if ( ! Object.prototype.hasOwnProperty.call( data, 'probe' ) ) {
				return;
			}
			await runPaywallProbeFollowThrough( data.probe, rid, merged );
		} );

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Posts', 'simple-x402' ) }
					subtitle={ __( 'Which posts should be paywalled?', 'simple-x402' ) }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { paywallMode, termId: String( termId ) } }
					fields={ PAYWALL_MODE_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'none' },
						fields: [
							'paywallMode',
							{ id: 'termId', layout: { type: 'regular', labelPosition: 'top' } },
						],
					} }
					onChange={ ( edits ) => {
						onPaywallFieldsChange();
						if ( 'paywallMode' in edits ) setPaywallMode( edits.paywallMode );
						if ( 'termId' in edits ) setTermId( Number( edits.termId ) );
					} }
				/>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } saving={ saving } error={ error } onSave={ onSave } />
		</Card>
	);
}

const AUDIENCE_FIELDS = [
	{
		id: 'audience',
		label: '',
		type: 'text',
		Edit: 'radio',
		elements: [
			{ value: config.modes.audience.everyone, label: __( 'Everyone (humans and bots)', 'simple-x402' ) },
			{ value: config.modes.audience.bots, label: __( 'Only detected bots and crawlers', 'simple-x402' ) },
		],
	},
];

function AudienceCard( { saved, save } ) {
	const [ audience, setAudience ] = useState( saved.paywall_audience );
	const { saving, error, run } = useSave();
	const isDirty = audience !== saved.paywall_audience;

	const onSave = () =>
		run( async () => {
			const { values: merged } = await save( { paywall_audience: audience } );
			setAudience( merged.paywall_audience );
		} );

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Audience', 'simple-x402' ) }
					subtitle={ __( 'Which visitors should see the paywall?', 'simple-x402' ) }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { audience } }
					fields={ AUDIENCE_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'none' },
						fields: [ 'audience' ],
					} }
					onChange={ ( edits ) => setAudience( edits.audience ) }
				/>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } saving={ saving } error={ error } onSave={ onSave } />
		</Card>
	);
}

const PRICING_FIELDS = [
	{
		id: 'default_price',
		// USDC has 6 on-chain decimals, so prices down to 0.000001 are valid.
		// A type='number' input would need a matching step attribute and
		// would still reject pasted values that don't land on the grid; a
		// plain text input lets site owners type any decimal and leans on
		// the server sanitizer to reject non-numeric or non-positive input.
		label: __( 'Price per request (USDC)', 'simple-x402' ),
		type: 'text',
		placeholder: '0.01',
	},
];

function PricingCard( { saved, save } ) {
	const [ price, setPrice ] = useState( saved.default_price || '' );
	const { saving, error, run } = useSave();
	const isDirty = String( price ?? '' ) !== String( saved.default_price ?? '' );

	const onSave = () =>
		run( async () => {
			const { values: merged } = await save( { default_price: price } );
			setPrice( merged.default_price );
		} );

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Pricing', 'simple-x402' ) }
					subtitle={ __( 'How much each paywalled request costs, in USDC.', 'simple-x402' ) }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { default_price: price } }
					fields={ PRICING_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'top' },
						fields: [ 'default_price' ],
					} }
					onChange={ ( edits ) => setPrice( edits.default_price ) }
				/>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } saving={ saving } error={ error } onSave={ onSave } />
		</Card>
	);
}

function facilitatorOptions() {
	const entries = ( config.facilitators || [] ).map( ( f ) => ( {
		value: f.id,
		label: f.name || f.id,
	} ) );
	return [
		{ value: '', label: __( '— Select a facilitator —', 'simple-x402' ) },
		...entries,
	];
}

const FACILITATOR_FIELDS = [
	{
		id: 'facilitator',
		label: __( 'Facilitator', 'simple-x402' ),
		type: 'text',
		Edit: 'select',
		elements: facilitatorOptions(),
	},
];

// EVM address: 0x followed by exactly 40 hex characters. Checksum (EIP-55)
// is intentionally not enforced — the facilitator's /verify call rejects
// addresses the chain doesn't recognise, which is a stricter guarantee than
// any client-side check. This regex catches typos, wrong length, and bad
// characters, which is what local validation is for.
const WALLET_RE = /^0x[0-9a-fA-F]{40}$/;

const METAMASK_URL = 'https://metamask.io/';

const emptySlot = () => ( { wallet_address: '', api_key_id: '' } );

// Compile a regex from a connector-supplied pattern string. Returns null
// when the pattern is missing or unparseable so the caller can fall back
// to "no client-side validation" rather than throwing on bad config.
function compileConnectorPattern( pattern ) {
	if ( ! pattern || typeof pattern !== 'string' ) return null;
	try {
		return new RegExp( pattern );
	} catch ( _ ) {
		return null;
	}
}

const EMPTY_CREDENTIAL_STATE = {
	has_secret: false,
	source: 'none',
	is_writable: true,
};

function FacilitatorCard( {
	saved,
	save,
	facilitator,
	setFacilitator,
	slots,
	setSlots,
	onFacilitatorFormChange,
	onFacilitatorSaveComplete,
} ) {
	const { saving, error, run } = useSave();

	const slot = '' === facilitator ? emptySlot() : ( slots[ facilitator ] ?? emptySlot() );
	const savedSlot = '' === facilitator
		? emptySlot()
		: ( ( saved.facilitators || {} )[ facilitator ] ?? emptySlot() );
	const savedId = saved.selected_facilitator_id || '';

	const managedWalletIds = config.managedWalletFacilitators || [];
	const walletInputVisible = '' === facilitator || ! managedWalletIds.includes( facilitator );
	const apiKeyIds = config.apiKeyFacilitators || [];
	const apiKeyInputsVisible = '' !== facilitator && apiKeyIds.includes( facilitator );

	const [ credentials, setCredentials ] = useState( config.connectorCredentials || {} );
	const [ pendingSecret, setPendingSecret ] = useState( '' );
	const [ replaceOpen, setReplaceOpen ] = useState( false );

	useEffect( () => {
		setPendingSecret( '' );
		setReplaceOpen( false );
	}, [ facilitator ] );

	const credentialState =
		'' !== facilitator && credentials[ facilitator ]
			? credentials[ facilitator ]
			: EMPTY_CREDENTIAL_STATE;
	const secretEditable =
		apiKeyInputsVisible && credentialState.is_writable;
	const secretInputShown =
		secretEditable && ( ! credentialState.has_secret || replaceOpen );

	const adminMeta =
		'' !== facilitator
			? ( config.connectorAdminMeta || {} )[ facilitator ] ?? null
			: null;
	const keyIdRe = compileConnectorPattern( adminMeta?.keyIdPattern );
	const keySecretRe = compileConnectorPattern( adminMeta?.keySecretPattern );

	const trimmedKeyId = ( slot.api_key_id || '' ).trim();
	const keyIdInvalid =
		apiKeyInputsVisible &&
		null !== keyIdRe &&
		'' !== trimmedKeyId &&
		! keyIdRe.test( trimmedKeyId );
	const trimmedSecret = pendingSecret.trim();
	const secretInvalid =
		secretInputShown &&
		null !== keySecretRe &&
		'' !== trimmedSecret &&
		! keySecretRe.test( trimmedSecret );

	const walletValue = slot.wallet_address || '';
	const trimmedWallet = walletValue.trim();
	const walletHasInvalidFormat =
		walletInputVisible && '' !== facilitator && '' !== trimmedWallet && ! WALLET_RE.test( trimmedWallet );
	const walletError = walletHasInvalidFormat
		? __( 'Enter a valid address — 0x followed by 40 hex characters.', 'simple-x402' )
		: null;
	const walletHelp =
		'' !== facilitator && walletInputVisible
			? createInterpolateElement(
					__(
						'Have a wallet? Paste its public 0x address. New to crypto? Create one with <a>MetaMask</a>.',
						'simple-x402'
					),
					{
						a: (
							<a
								href={ METAMASK_URL }
								target="_blank"
								rel="noopener noreferrer"
							/>
						),
					}
				)
			: null;

	const isDirty =
		facilitator !== savedId ||
		( '' !== facilitator && ! isShallowEqual( slot, savedSlot ) ) ||
		'' !== pendingSecret;

	const onWalletChange = ( edits ) => {
		setSlots( {
			...slots,
			[ facilitator ]: { ...slot, ...edits },
		} );
	};

	const onSave = () =>
		run( async () => {
			const partial = { selected_facilitator_id: facilitator };
			if ( '' !== facilitator ) {
				partial.facilitators = { [ facilitator ]: slot };
			}
			if ( '' !== facilitator && '' !== pendingSecret ) {
				partial.connector_secrets = { [ facilitator ]: pendingSecret };
			}
			const { values: merged, ajaxData } = await save( partial );
			setFacilitator( merged.selected_facilitator_id || '' );
			setSlots( merged.facilitators || {} );
			if ( ajaxData?.connectorCredentials ) {
				setCredentials( ( prev ) => ( {
					...prev,
					...ajaxData.connectorCredentials,
				} ) );
			}
			setPendingSecret( '' );
			setReplaceOpen( false );
			await onFacilitatorSaveComplete( merged );
		} );

	const facilitatorSubtitle =
		'' !== facilitator && ! walletInputVisible
			? __(
					'WordPress.com handles verify and settle. Payments are pooled for your account — no receiving wallet to configure here.',
					'simple-x402'
				)
			: __(
					'Where verify and settle requests are sent, and where payments land. The paywall stays inert until a receiving wallet is set.',
					'simple-x402'
				);

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Facilitator', 'simple-x402' ) }
					subtitle={ facilitatorSubtitle }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { facilitator } }
					fields={ FACILITATOR_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'top' },
						fields: [ 'facilitator' ],
					} }
					onChange={ ( edits ) => {
						onFacilitatorFormChange();
						setFacilitator( edits.facilitator );
					} }
				/>
				{ '' !== facilitator && (
					<>
						<div className="simple-x402-page__divider" />
						{ walletInputVisible ? (
							<div
								className={
									walletError
										? 'simple-x402-page__wallet simple-x402-page__wallet--error'
										: 'simple-x402-page__wallet'
								}
							>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Receiving wallet', 'simple-x402' ) }
									placeholder={ __( 'Add a valid EVM address 0x...', 'simple-x402' ) }
									help={
										walletError ? (
											<span
												className="simple-x402-page__field-error"
												role="alert"
											>
												{ walletError }
											</span>
										) : (
											walletHelp
										)
									}
									value={ walletValue }
									onChange={ ( value ) => onWalletChange( { wallet_address: value } ) }
									aria-invalid={ walletError ? 'true' : 'false' }
								/>
							</div>
						) : (
							<Text size={ 13 } variant="muted">
								{ __(
									'Receiving address is managed by WordPress.com for this facilitator.',
									'simple-x402'
								) }
							</Text>
						) }
						{ apiKeyInputsVisible && (
							<>
								<div className="simple-x402-page__divider" />
								<div className="simple-x402-page__api-keys">
									{ adminMeta && (
										<div className="simple-x402-page__api-keys-intro">
											{ adminMeta.introHeadline && (
												<Text size={ 13 }>{ adminMeta.introHeadline }</Text>
											) }
											{ adminMeta.introBody && (
												<Text size={ 13 } variant="muted">
													{ createInterpolateElement( adminMeta.introBody, {
														docs: adminMeta.docsUrl ? (
															<a
																href={ adminMeta.docsUrl }
																target="_blank"
																rel="noopener noreferrer"
															>
																{ adminMeta.docsLinkText || adminMeta.docsUrl }
															</a>
														) : (
															<span>{ adminMeta.docsLinkText || '' }</span>
														),
													} ) }
												</Text>
											) }
										</div>
									) }
									<TextControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __( 'API key ID', 'simple-x402' ) }
										placeholder={ adminMeta?.keyIdPlaceholder || '' }
										help={
											keyIdInvalid && adminMeta?.keyIdInvalidMessage ? (
												<span
													className="simple-x402-page__field-error"
													role="alert"
												>
													{ adminMeta.keyIdInvalidMessage }
												</span>
											) : null
										}
										value={ slot.api_key_id || '' }
										onChange={ ( value ) => onWalletChange( { api_key_id: value } ) }
										aria-invalid={ keyIdInvalid ? 'true' : 'false' }
									/>
									<div className="simple-x402-page__api-key-secret">
										<div className="components-base-control__label simple-x402-page__api-key-secret-label">
											{ __( 'API key secret', 'simple-x402' ) }
										</div>
										{ ! secretEditable && credentialState.has_secret && (
											<Text size={ 13 } variant="muted">
												{ createInterpolateElement(
													'env' === credentialState.source
														? __(
																'Set via the <code/> environment variable. Edit it on the server to change.',
																'simple-x402'
															)
														: __(
																'Set via the <code/> constant in wp-config.php. Edit it there to change.',
																'simple-x402'
															),
													{
														code: <code>{ credentialState.constant_name || '' }</code>,
													}
												) }
											</Text>
										) }
										{ secretEditable && credentialState.has_secret && ! replaceOpen && (
											<HStack spacing={ 2 } alignment="left" justify="flex-start">
												<Text size={ 13 } variant="muted">
													{ credentialState.saved_at_label
														? sprintf(
																/* translators: %s: human-readable save date. */
																__( 'Key saved on %s.', 'simple-x402' ),
																credentialState.saved_at_label
															)
														: __( 'Key saved.', 'simple-x402' ) }
												</Text>
												<Button
													variant="link"
													type="button"
													onClick={ () => setReplaceOpen( true ) }
												>
													{ __( 'Replace key', 'simple-x402' ) }
												</Button>
											</HStack>
										) }
										{ secretInputShown && (
											<TextareaControl
												__nextHasNoMarginBottom
												label={ __( 'API key secret', 'simple-x402' ) }
												hideLabelFromVision
												placeholder={ adminMeta?.keySecretPlaceholder || '' }
												help={
													secretInvalid && adminMeta?.keySecretInvalidMessage ? (
														<span
															className="simple-x402-page__field-error"
															role="alert"
														>
															{ adminMeta.keySecretInvalidMessage }
														</span>
													) : (
														createInterpolateElement(
															__(
																'For production sites, define <code/> in wp-config.php instead — the plugin reads that constant first and the secret never lands in the database.',
																'simple-x402'
															),
															{
																code: <code>{ credentialState.constant_name || '' }</code>,
															}
														)
													)
												}
												rows={ 4 }
												value={ pendingSecret }
												onChange={ ( value ) => setPendingSecret( value ) }
												aria-invalid={ secretInvalid ? 'true' : 'false' }
											/>
										) }
									</div>
								</div>
							</>
						) }
					</>
				) }
			</CardBody>
			<SaveFooter
				disabled={ ! isDirty || walletHasInvalidFormat || keyIdInvalid || secretInvalid }
				saving={ saving }
				error={ error }
				onSave={ onSave }
			/>
		</Card>
	);
}

function SettingsApp() {
	const [ saved, setSaved ] = useState( config.values );
	const [ facilitator, setFacilitator ] = useState( saved.selected_facilitator_id || '' );
	const [ slots, setSlots ] = useState( saved.facilitators || {} );
	const [ paywallMode, setPaywallMode ] = useState( saved.paywall_mode );
	const [ termId, setTermId ] = useState( saved.paywall_category_term_id );

	const adminChecksRequestId = useRef( 0 );
	const [ facilitatorCheck, setFacilitatorCheck ] = useState( null );
	const [ paywallCheck, setPaywallCheck ] = useState( null );
	const [ runChecksPending, setRunChecksPending ] = useState( false );

	const paywallDirty =
		paywallMode !== saved.paywall_mode ||
		Number( termId ) !== Number( saved.paywall_category_term_id );

	const savedFacilitatorId = saved.selected_facilitator_id || '';
	const facilitatorSlot =
		'' === facilitator ? emptySlot() : ( slots[ facilitator ] ?? emptySlot() );
	const savedSlotForPicker =
		'' === facilitator
			? emptySlot()
			: ( ( saved.facilitators || {} )[ facilitator ] ?? emptySlot() );
	const facilitatorDirty =
		facilitator !== savedFacilitatorId ||
		( '' !== facilitator && ! isShallowEqual( facilitatorSlot, savedSlotForPicker ) );

	const invalidateChecksFromFormEdit = () => {
		adminChecksRequestId.current++;
		// Cancel any in-flight "Run checks": its `finally` will not clear
		// `runChecksPending` once the generation no longer matches.
		setRunChecksPending( false );
		setFacilitatorCheck( null );
		setPaywallCheck( null );
	};

	const beginPaywallSaveProbeSession = () => {
		const rid = ++adminChecksRequestId.current;
		setRunChecksPending( false );
		setPaywallCheck( null );
		return rid;
	};

	const runFacilitatorStepForRid = async ( rid, connectorId ) => {
		setFacilitatorCheck( { pending: true } );
		try {
			if ( ! String( connectorId ?? '' ).trim() ) {
				if ( rid !== adminChecksRequestId.current ) {
					return;
				}
				setFacilitatorCheck( {
					infoMessage: __(
						'Facilitator connectivity skipped: no facilitator selected.',
						'simple-x402'
					),
				} );
				return;
			}
			const probe = await runFacilitatorConnectivityAjax( connectorId );
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			if ( probe.ok ) {
				setFacilitatorCheck( {
					success: true,
					durationMs:
						probe.duration_ms != null ? Math.round( probe.duration_ms ) : undefined,
				} );
			} else {
				setFacilitatorCheck( {
					failureMessage:
						probe.error || __( 'Unreachable', 'simple-x402' ),
				} );
			}
		} catch ( e ) {
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			setFacilitatorCheck( {
				failureMessage: e instanceof Error ? e.message : String( e ),
			} );
		}
	};

	const runPaywallProbeFollowThrough = async ( probeBlock, rid, mergedSnapshot ) => {
		if ( probeBlock == null ) {
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			setPaywallCheck( {
				infoMessage: __( 'Paywall mode is off — no live probe run.', 'simple-x402' ),
			} );
			return;
		}
		if ( probeBlock?.reason === 'no_matching_post' ) {
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			setPaywallCheck( {
				infoMessage: __(
					'Paywall probe skipped: no published post matches the current scope.',
					'simple-x402'
				),
			} );
			return;
		}
		if ( ! probeBlock?.url || ! probeBlock?.nonce ) {
			return;
		}
		const connectorId = String( mergedSnapshot.selected_facilitator_id ?? '' ).trim();
		if ( '' === connectorId ) {
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			setPaywallCheck( {
				infoMessage: __(
					'Paywall probe skipped: choose a facilitator so the paywall can respond.',
					'simple-x402'
				),
			} );
			return;
		}
		const managedWalletIds = config.managedWalletFacilitators || [];
		const slotForConnector = ( mergedSnapshot.facilitators || {} )[ connectorId ];
		const walletConfigured =
			managedWalletIds.includes( connectorId ) ||
			'' !== String( slotForConnector?.wallet_address ?? '' ).trim();
		if ( ! walletConfigured ) {
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			setPaywallCheck( {
				infoMessage: __(
					'Paywall probe skipped: add a receiving wallet so the paywall has somewhere to send payments.',
					'simple-x402'
				),
			} );
			return;
		}

		setPaywallCheck( { pending: true } );
		const t0 = performance.now();
		try {
			const err = await runPaywallProbe( {
				url: probeBlock.url,
				nonce: probeBlock.nonce,
			} );
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			const durationMs = Math.round( performance.now() - t0 );
			if ( err ) {
				setPaywallCheck( { failureMessage: err } );
			} else {
				setPaywallCheck( { success: true, durationMs } );
			}
		} catch ( e ) {
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			const detail = e instanceof Error ? e.message : String( e );
			setPaywallCheck( {
				failureMessage: sprintf(
					/* translators: %s: Error detail (e.g. network failure). */
					__( 'Paywall probe failed: %s', 'simple-x402' ),
					detail
				),
			} );
		}
	};

	const onRunChecks = async () => {
		const rid = ++adminChecksRequestId.current;
		setFacilitatorCheck( null );
		setPaywallCheck( null );
		setRunChecksPending( true );
		try {
			await runFacilitatorStepForRid( rid, savedFacilitatorId );
			if ( rid !== adminChecksRequestId.current ) {
				return;
			}
			setPaywallCheck( { pending: true } );
			try {
				const wrap = await fetchPaywallProbeDescriptorFromServer();
				if ( rid !== adminChecksRequestId.current ) {
					return;
				}
				await runPaywallProbeFollowThrough( wrap.probe, rid, saved );
			} catch ( e ) {
				if ( rid !== adminChecksRequestId.current ) {
					return;
				}
				setPaywallCheck( {
					failureMessage:
						e instanceof Error ? e.message : String( e ),
				} );
			}
		} finally {
			if ( rid === adminChecksRequestId.current ) {
				setRunChecksPending( false );
			}
		}
	};

	const onFacilitatorSaveComplete = async ( merged ) => {
		const rid = ++adminChecksRequestId.current;
		setRunChecksPending( false );
		setFacilitatorCheck( null );
		const id = merged.selected_facilitator_id || '';
		if ( ! id ) {
			return;
		}
		await runFacilitatorStepForRid( rid, id );
	};

	// Shared save engine. Fires one AJAX call, merges the server-canonical
	// response into `saved` so every card's isDirty check is evaluated against
	// whatever the sanitizer actually stored (which may differ from what we
	// sent, e.g. bad prices → 0.01). Returns `{ values, ajaxData }` so cards
	// can run follow-up checks (e.g. paywall probe) without a second request.
	const save = async ( partial ) => {
		const data = await saveFields( partial );
		let mergedValues;
		setSaved( ( prev ) => {
			mergedValues = { ...prev, ...data.values };
			return mergedValues;
		} );
		return { values: mergedValues, ajaxData: data };
	};

	const noticesRef = useRef( null );
	useEffect( () => {
		const slot = noticesRef.current;
		if ( ! slot ) return;

		const moveNotices = () => {
			const found = document.querySelectorAll(
				'#wpbody-content .notice, #wpbody-content .updated, #wpbody-content .error'
			);
			found.forEach( ( n ) => {
				if ( slot.contains( n ) ) return;
				// `.below-h2` exempts the notice from WP common.js's auto-relocate.
				n.classList.add( 'below-h2' );
				slot.appendChild( n );
			} );
		};

		moveNotices();
		const t = setTimeout( moveNotices, 50 );
		return () => clearTimeout( t );
	}, [] );

	return (
		<div className="simple-x402-page__content">
			<div className="simple-x402-page__notices" ref={ noticesRef } />
			<VStack spacing={ 6 }>
				<PaywallScopeCard
					saved={ saved }
					save={ save }
					paywallMode={ paywallMode }
					setPaywallMode={ setPaywallMode }
					termId={ termId }
					setTermId={ setTermId }
					onPaywallFieldsChange={ invalidateChecksFromFormEdit }
					beginPaywallSaveProbeSession={ beginPaywallSaveProbeSession }
					runPaywallProbeFollowThrough={ runPaywallProbeFollowThrough }
				/>
				<AudienceCard saved={ saved } save={ save } />
				<PricingCard saved={ saved } save={ save } />
				<FacilitatorCard
					saved={ saved }
					save={ save }
					facilitator={ facilitator }
					setFacilitator={ setFacilitator }
					slots={ slots }
					setSlots={ setSlots }
					onFacilitatorFormChange={ invalidateChecksFromFormEdit }
					onFacilitatorSaveComplete={ onFacilitatorSaveComplete }
				/>
				<RunChecksCard
					paywallDirty={ paywallDirty }
					facilitatorDirty={ facilitatorDirty }
					runChecksPending={ runChecksPending }
					onRunChecks={ onRunChecks }
					facilitatorCheck={ facilitatorCheck }
					paywallCheck={ paywallCheck }
				/>
			</VStack>
		</div>
	);
}

const mount = document.getElementById( 'simple-x402-app' );
if ( mount ) {
	createRoot( mount ).render( <SettingsApp /> );
}
