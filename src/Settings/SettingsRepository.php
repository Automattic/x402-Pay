<?php
/**
 * Plugin-wide settings accessor backed by the WordPress options API.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Settings;

use X402Pay\Http\PaywallController;
use X402Pay\Services\FacilitatorHooks;
use X402Pay\Services\PriceSanitizer;

/**
 * Thin wrapper around a single wp_options row.
 *
 * Schema:
 *   - default_price:            Decimal USDC price per paywalled request.
 *                               Global (not per-facilitator): the price you
 *                               charge is a policy choice, independent of
 *                               which network you're settling on.
 *   - selected_facilitator_id:  Connector ID dispatching verify/settle. '' means
 *                               no facilitator selected (paywall inert).
 *   - facilitators:             Map of connector_id → { wallet_address,
 *                               api_key_id }. Each registered facilitator
 *                               remembers its own wallet and (for connectors
 *                               that need an API key) the public ID half of
 *                               the credential pair, so swapping the picker
 *                               recalls the values last configured for that
 *                               network. The matching secret is handled by
 *                               {@see \X402Pay\Services\ConnectorCredentialStore}
 *                               — it never lives in this slot.
 *   - paywall_mode:             'none' | 'category' | 'all-posts'.
 *   - paywall_audience:         'everyone' | 'bots'.
 *   - paywall_category_term_id: term_id used in `category` mode.
 *
 * Getters trust `sanitize()` as the only writer — they do not re-validate
 * stored values.
 */
final class SettingsRepository {

	public const OPTION_NAME      = 'x402_pay_settings';
	public const DEFAULT_PRICE    = '0.01';
	public const DEFAULT_CATEGORY = 'x402paywall';

	/**
	 * Hard caps on the facilitators map. The slot count cap stops a
	 * compromised admin session from bloating the autoloaded option row;
	 * the field cap is generous enough for any real wallet/key value
	 * but rejects pathological MB-sized strings.
	 */
	public const MAX_FACILITATOR_SLOTS = 50;
	public const MAX_SLOT_FIELD_BYTES  = 200;
	private const EVM_ADDRESS_PATTERN  = '/^0x[0-9a-fA-F]{40}$/';

	public const PAYWALL_MODE_NONE      = 'none';
	public const PAYWALL_MODE_CATEGORY  = 'category';
	public const PAYWALL_MODE_ALL_POSTS = 'all-posts';
	public const VALID_PAYWALL_MODES    = array(
		self::PAYWALL_MODE_NONE,
		self::PAYWALL_MODE_CATEGORY,
		self::PAYWALL_MODE_ALL_POSTS,
	);
	public const DEFAULT_PAYWALL_MODE   = self::PAYWALL_MODE_NONE;

	public const AUDIENCE_EVERYONE = 'everyone';
	public const AUDIENCE_BOTS     = 'bots';
	public const VALID_AUDIENCES   = array(
		self::AUDIENCE_EVERYONE,
		self::AUDIENCE_BOTS,
	);
	public const DEFAULT_AUDIENCE  = self::AUDIENCE_BOTS;

	public function __construct() {}

	/**
	 * Wallet address for the active facilitator, or '' if unset / no facilitator.
	 */
	public function wallet_address(): string {
		return $this->wallet_address_for( $this->selected_facilitator_id() );
	}

	/**
	 * Receiving address for PaymentRequirements: managed pool (filter) wins over the stored slot.
	 */
	public function resolved_pay_to_address(): string {
		$id      = $this->selected_facilitator_id();
		$managed = (string) apply_filters( FacilitatorHooks::MANAGED_POOL_PAY_TO, '', $id );
		if ( self::is_valid_evm_address( $managed ) ) {
			return $managed;
		}
		return $this->wallet_address();
	}

	public static function is_valid_evm_address( mixed $raw ): bool {
		return 1 === preg_match( self::EVM_ADDRESS_PATTERN, trim( (string) $raw ) );
	}

	/**
	 * Configured default price, falling back to DEFAULT_PRICE.
	 */
	public function default_price(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		$price  = isset( $stored['default_price'] ) ? (string) $stored['default_price'] : '';
		return $this->sanitize_price( $price );
	}

	/**
	 * Wallet address stored for a specific connector ID.
	 */
	public function wallet_address_for( string $facilitator_id ): string {
		$slot = $this->slot_for( $facilitator_id );
		return (string) ( $slot['wallet_address'] ?? '' );
	}

	/**
	 * Every stored facilitator slot, keyed by connector ID. Used by the
	 * SettingsPage bootstrap so the React picker can swap values locally
	 * without refetching.
	 *
	 * @return array<string,array{wallet_address:string,api_key_id:string}>
	 */
	public function facilitator_slots(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		$slots  = is_array( $stored['facilitators'] ?? null ) ? $stored['facilitators'] : array();
		return $this->sanitize_facilitators( $slots );
	}

	public function api_key_id_for( string $facilitator_id ): string {
		$slot = $this->slot_for( $facilitator_id );
		return (string) ( $slot['api_key_id'] ?? '' );
	}

	/**
	 * ID of the x402 facilitator connector to dispatch through. Empty string
	 * (default) means no facilitator is selected.
	 */
	public function selected_facilitator_id(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		return $this->sanitize_connector_id( $stored['selected_facilitator_id'] ?? '' );
	}

	public function paywall_mode(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		$mode   = isset( $stored['paywall_mode'] ) ? (string) $stored['paywall_mode'] : '';
		return in_array( $mode, self::VALID_PAYWALL_MODES, true )
			? $mode
			: self::DEFAULT_PAYWALL_MODE;
	}

	public function paywall_audience(): string {
		$stored   = get_option( self::OPTION_NAME, array() );
		$audience = isset( $stored['paywall_audience'] ) ? (string) $stored['paywall_audience'] : '';
		return in_array( $audience, self::VALID_AUDIENCES, true )
			? $audience
			: self::DEFAULT_AUDIENCE;
	}

	public function paywall_category_term_id(): int {
		$stored = get_option( self::OPTION_NAME, array() );
		return max( 0, (int) ( $stored['paywall_category_term_id'] ?? 0 ) );
	}

	/**
	 * Sanitise raw input into the canonical storage shape. Safe to call from a
	 * `register_setting` sanitize_callback: it reads stored state but must not
	 * write (calling `update_option` here recurses).
	 *
	 * @param array $input Raw input.
	 */
	public function sanitize( array $input ): array {
		$paywall_mode = isset( $input['paywall_mode'] ) ? (string) $input['paywall_mode'] : '';
		if ( ! in_array( $paywall_mode, self::VALID_PAYWALL_MODES, true ) ) {
			$paywall_mode = self::DEFAULT_PAYWALL_MODE;
		}

		$audience = isset( $input['paywall_audience'] ) ? (string) $input['paywall_audience'] : '';
		if ( ! in_array( $audience, self::VALID_AUDIENCES, true ) ) {
			$audience = self::DEFAULT_AUDIENCE;
		}

		$term_id = (int) ( $input['paywall_category_term_id'] ?? 0 );
		if ( $term_id <= 0 || ! term_exists( $term_id, 'category' ) ) {
			$term_id = $this->paywall_category_term_id();
		}

		$selected_facilitator_id = $this->sanitize_connector_id( $input['selected_facilitator_id'] ?? '' );
		$facilitators            = $this->sanitize_facilitators( $input['facilitators'] ?? array() );
		$price                   = $this->sanitize_price( $input['default_price'] ?? '' );

		return array(
			'default_price'            => $price,
			'selected_facilitator_id'  => $selected_facilitator_id,
			'facilitators'             => $facilitators,
			'paywall_mode'             => $paywall_mode,
			'paywall_audience'         => $audience,
			'paywall_category_term_id' => $term_id,
		);
	}

	/**
	 * Persist settings from raw input. For programmatic use; the Settings API
	 * must use `sanitize()` instead (it handles persistence itself).
	 *
	 * @param array $input Raw input.
	 */
	public function save( array $input ): void {
		update_option( self::OPTION_NAME, $this->sanitize( $input ) );
	}

	/**
	 * Merge a partial input into the stored option. Only the keys present in
	 * $partial are touched; everything else is preserved verbatim. Used by the
	 * per-card AJAX save path so changes to one section don't wipe dirty or
	 * clean values in another.
	 *
	 * For the nested `facilitators` map, slots are merged by connector ID —
	 * submitting { x402_pay_test: {...} } leaves coinbase_cdp's slot
	 * untouched.
	 *
	 * @param array $partial Raw input (subset of the full shape).
	 * @return array The merged, persisted option row.
	 */
	public function update( array $partial ): array {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = $stored;

		if ( array_key_exists( 'default_price', $partial ) ) {
			$merged['default_price'] = $this->sanitize_price( $partial['default_price'] );
		}
		if ( array_key_exists( 'selected_facilitator_id', $partial ) ) {
			$merged['selected_facilitator_id'] = $this->sanitize_connector_id( $partial['selected_facilitator_id'] );
		}
		if ( array_key_exists( 'paywall_mode', $partial ) ) {
			$mode                   = (string) $partial['paywall_mode'];
			$merged['paywall_mode'] = in_array( $mode, self::VALID_PAYWALL_MODES, true )
				? $mode
				: self::DEFAULT_PAYWALL_MODE;
		}
		if ( array_key_exists( 'paywall_audience', $partial ) ) {
			$audience                   = (string) $partial['paywall_audience'];
			$merged['paywall_audience'] = in_array( $audience, self::VALID_AUDIENCES, true )
				? $audience
				: self::DEFAULT_AUDIENCE;
		}
		if ( array_key_exists( 'paywall_category_term_id', $partial ) ) {
			$term_id = (int) $partial['paywall_category_term_id'];
			if ( $term_id > 0 && term_exists( $term_id, 'category' ) ) {
				$merged['paywall_category_term_id'] = $term_id;
			}
			// Invalid term: leave the stored value as-is.
		}
		if ( array_key_exists( 'facilitators', $partial ) && is_array( $partial['facilitators'] ) ) {
			// Re-normalise already-stored slots through the same sanitizer so
			// historical writes with extra keys (schema drift from older
			// builds, bad external writes) don't leak into the merged result.
			$existing_slots = $this->sanitize_facilitators( $merged['facilitators'] ?? array() );
			foreach ( $this->sanitize_facilitators( $partial['facilitators'] ) as $id => $slot ) {
				$existing_slots[ $id ] = $slot;
			}
			$merged['facilitators'] = $existing_slots;
		}

		$merged = $this->sanitize_stored_row( $merged );
		update_option( self::OPTION_NAME, $merged );
		return $merged;
	}

	/**
	 * Ensure every canonical key is present in API / merge results (partial
	 * updates must not drop keys the admin UI still relies on).
	 *
	 * @param array<string,mixed> $merged
	 * @return array<string,mixed>
	 */
	private function with_settings_defaults( array $merged ): array {
		return array_merge(
			array(
				'default_price'            => self::DEFAULT_PRICE,
				'selected_facilitator_id'  => '',
				'facilitators'             => array(),
				'paywall_mode'             => self::DEFAULT_PAYWALL_MODE,
				'paywall_audience'         => self::DEFAULT_AUDIENCE,
				'paywall_category_term_id' => 0,
			),
			$merged
		);
	}

	/**
	 * Re-normalise a stored row after partial updates. This keeps historical
	 * values or direct option writes from leaking unsanitised data through the
	 * AJAX response while still preserving the partial-update semantics.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function sanitize_stored_row( array $row ): array {
		$row = $this->with_settings_defaults( $row );

		$mode = isset( $row['paywall_mode'] ) ? (string) $row['paywall_mode'] : '';
		if ( ! in_array( $mode, self::VALID_PAYWALL_MODES, true ) ) {
			$mode = self::DEFAULT_PAYWALL_MODE;
		}

		$audience = isset( $row['paywall_audience'] ) ? (string) $row['paywall_audience'] : '';
		if ( ! in_array( $audience, self::VALID_AUDIENCES, true ) ) {
			$audience = self::DEFAULT_AUDIENCE;
		}

		return array(
			'default_price'            => $this->sanitize_price( $row['default_price'] ?? '' ),
			'selected_facilitator_id'  => $this->sanitize_connector_id( $row['selected_facilitator_id'] ?? '' ),
			'facilitators'             => $this->sanitize_facilitators( $row['facilitators'] ?? array() ),
			'paywall_mode'             => $mode,
			'paywall_audience'         => $audience,
			'paywall_category_term_id' => max( 0, (int) ( $row['paywall_category_term_id'] ?? 0 ) ),
		);
	}

	/**
	 * Replace just the paywall_category_term_id, preserving every other field.
	 */
	public function set_paywall_category_term_id( int $term_id ): void {
		$stored                             = get_option( self::OPTION_NAME, array() );
		$stored['paywall_category_term_id'] = $term_id;
		update_option( self::OPTION_NAME, $stored );
	}

	/**
	 * Permalink of one published post matching the paywall scope in $row, for admin diagnostics.
	 *
	 * @param array<string,mixed> $row Merged settings (paywall_mode, paywall_category_term_id).
	 */
	public function sample_paywalled_post_permalink( array $row ): ?string {
		$mode = isset( $row['paywall_mode'] ) ? (string) $row['paywall_mode'] : self::DEFAULT_PAYWALL_MODE;
		if ( self::PAYWALL_MODE_NONE === $mode ) {
			return null;
		}

		$query = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'orderby'                => 'date',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'suppress_filters'       => true,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( self::PAYWALL_MODE_CATEGORY === $mode ) {
			$term_id = (int) ( $row['paywall_category_term_id'] ?? 0 );
			if ( $term_id <= 0 ) {
				return null;
			}
			$query['tax_query'] = array(
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => array( $term_id ),
				),
			);
		} elseif ( self::PAYWALL_MODE_ALL_POSTS !== $mode ) {
			return null;
		}

		$ids = get_posts( $query );
		if ( ! is_array( $ids ) || array() === $ids ) {
			return null;
		}
		$post_id = (int) $ids[0];
		if ( $post_id <= 0 ) {
			return null;
		}
		$link = get_permalink( $post_id );
		return is_string( $link ) && '' !== $link ? $link : null;
	}

	/**
	 * Build the paywall self-check descriptor (nonce + URL, skip reasons, or off).
	 * Same shape as the `probe` field appended after a scope save.
	 *
	 * @param array<string,mixed> $merged Merged settings row (must include paywall_mode).
	 * @return array{probe: null|array<string,mixed>}
	 */
	public function build_paywall_probe_for_merged_row( array $merged ): array {
		$mode = isset( $merged['paywall_mode'] ) ? (string) $merged['paywall_mode'] : self::DEFAULT_PAYWALL_MODE;
		if ( self::PAYWALL_MODE_NONE === $mode ) {
			return array( 'probe' => null );
		}
		$url = $this->sample_paywalled_post_permalink( $merged );
		if ( null === $url || '' === $url ) {
			return array( 'probe' => array( 'reason' => 'no_matching_post' ) );
		}
		return array(
			'probe' => array(
				'url'   => $url,
				'nonce' => wp_create_nonce( PaywallController::PROBE_NONCE_ACTION ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function slot_for( string $facilitator_id ): array {
		if ( '' === $facilitator_id ) {
			return array();
		}
		$slots = $this->facilitator_slots();
		return $slots[ $facilitator_id ] ?? array();
	}

	/**
	 * Validate a connector ID against the Connectors API's a-z0-9_- rule.
	 * Invalid characters are stripped; an all-invalid input becomes ''.
	 */
	private function sanitize_connector_id( mixed $raw ): string {
		return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $raw ) );
	}

	/**
	 * Canonicalise the submitted facilitators map. Unknown keys are dropped;
	 * each slot is normalised to { wallet_address, api_key_id }. Invalid
	 * connector IDs are filtered out.
	 *
	 * Caps:
	 * - At most {@see self::MAX_FACILITATOR_SLOTS} slots — extras are ignored.
	 * - Each field is truncated to {@see self::MAX_SLOT_FIELD_BYTES} bytes.
	 * Both bound the autoloaded option row size so a compromised admin
	 * session can't inflate it to MB and slow every page load.
	 *
	 * @param mixed $raw Raw input (expected to be array<string,array>).
	 * @return array<string,array{wallet_address:string,api_key_id:string}>
	 */
	private function sanitize_facilitators( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id => $slot ) {
			if ( count( $out ) >= self::MAX_FACILITATOR_SLOTS ) {
				break;
			}
			$clean_id = $this->sanitize_connector_id( (string) $id );
			if ( '' === $clean_id || ! is_array( $slot ) ) {
				continue;
			}
			$out[ $clean_id ] = array(
				'wallet_address' => $this->sanitize_wallet_address( $slot['wallet_address'] ?? '' ),
				'api_key_id'     => $this->trim_slot_field( $slot['api_key_id'] ?? '' ),
			);
		}
		return $out;
	}

	private function sanitize_wallet_address( mixed $raw ): string {
		$value = trim( (string) $raw );
		return self::is_valid_evm_address( $value ) ? $value : '';
	}

	private function trim_slot_field( mixed $raw ): string {
		$value = trim( (string) $raw );
		return strlen( $value ) > self::MAX_SLOT_FIELD_BYTES
			? substr( $value, 0, self::MAX_SLOT_FIELD_BYTES )
			: $value;
	}

	private function sanitize_price( mixed $raw ): string {
		return PriceSanitizer::sanitize( $raw, self::DEFAULT_PRICE );
	}
}
