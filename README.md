# x402 Pay

Minimal WordPress plugin that gates selected posts behind an x402 payment using the public x402.org facilitator on Base Sepolia.

## Status

MVP. Bots/API clients and browser wallets are supported through HTTP 402 payment responses.

## Requirements

- PHP 8.1+
- WordPress 7.0+
- Composer (for development)

## Install (development)

```bash
composer install
composer test
composer lint

npm install
npm run build      # production bundle for the admin UI
npm start          # dev/watch
```

The admin UI lives in `assets/src/index.jsx` and builds to `assets/build/`. The output is gitignored — run `npm run build` before packaging the plugin.

For an end-to-end local sandbox (wp-now), see [LOCAL_DEV.md](LOCAL_DEV.md).

## Test client

A small Node script under `scripts/` walks the full `402` → sign → retry flow against a paywalled URL on Base Sepolia.

```bash
cd scripts
npm install                                       # one time
PRIVATE_KEY=0x... node pay.mjs <paywalled-url>
```

The wallet must hold Base Sepolia USDC. No ETH needed — the x402.org facilitator pays gas via EIP-3009 `transferWithAuthorization`.

## What it does

- Adds an `x402paywall` category on activation (distinctive so it won't collide with existing editorial categories).
- Adds a Settings → x402 Pay page with: wallet address, default price, paywall audience, paywall mode, paywall category (picked from existing categories).
- **Mode** decides which posts qualify:
  - **No posts** (default): paywall disabled; pick another option to turn it on.
  - **All posts**: gate every published post of type `post`.
  - **Category**: gate only posts assigned to the configured category.
- **Audience** decides who sees the paywall once a post qualifies:
  - **Everyone**: humans and detected bots.
  - **Only bots/crawlers** (default): bots only, via `jaybizzle/crawler-detect`.
- On any frontend request that matches both audience and mode, responds HTTP 402 with a `PAYMENT-REQUIRED` header and a JSON body, unless the request carries a valid `PAYMENT-SIGNATURE` (verified + settled via x402.org) or a live grant.

Renaming the category in settings does not relabel existing posts — reassign them yourself if you rename.

## Extending

See the `x402_pay_rule_for_request` filter in `src/Services/RuleResolver.php`.

## Facilitator connectors (WP 7.0+)

x402 Pay discovers facilitator backends through the [WordPress 7.0 Connectors API](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/). A facilitator is any external service that can `verify` and `settle` x402 payments — x402.org, a site's own Coinbase CDP account, etc.

Publishing a facilitator is a two-step contract:

1. **Register the connector** on `wp_connectors_init`. The plugin claims the type string `x402_facilitator`. Core keeps a fixed whitelist of fields (`name`, `description`, `type`, `authentication`, `plugin`) and drops anything else, so the registration is credentials-and-metadata only.

    ```php
    add_action( 'wp_connectors_init', function ( WP_Connector_Registry $registry ) {
        $registry->register( 'my_facilitator', array(
            'name'           => 'My x402 facilitator',
            'description'    => 'One-line marketing blurb.',
            'type'           => 'x402_facilitator',
            'authentication' => array( 'method' => 'api_key', 'setting_name' => 'my_key' ),
            'plugin'         => array( 'file' => 'my-plugin/my-plugin.php' ),
        ) );
    } );
    ```

2. **Provide the client** through the `x402_pay_facilitator_for_connector` filter. Since core strips unknown fields from the registration payload, x402-specific capabilities (endpoint URL, supported networks, fee-split support) are delivered here, not in the registration array. Returning a `Facilitator` instance for your connector ID is how the plugin learns how to call your backend.

### Built-in connectors

x402 Pay ships with two connectors out of the box: `x402_pay_test`, which routes through the public x402.org facilitator on Base Sepolia for testnet trials, and `coinbase_cdp`, which routes through Coinbase Developer Platform on Base mainnet (requires a CDP Secret API Key). Site owners pick one from the Facilitator dropdown in Settings → x402 Pay and enter a receiving wallet + price.

**Managed receiving address:** Extensions may filter `x402_pay_managed_pool_pay_to` so `payTo` bypasses the per-site wallet field. **Settlement reporting:** after a successful settle, the plugin fires `x402_pay_payment_settled` and may POST to a URL from the `x402_pay_ledger_report_url` filter (see `X402Pay\Services\FacilitatorHooks`). The ledger (or any hook subscriber that persists externally) should de-duplicate on `transaction`; the plugin may deliver the same settlement more than once under retries or concurrency.

## External services

The plugin talks to two external facilitator endpoints, and only when a request hits a paywalled URL with a `Payment-Signature` header (or when an admin clicks **Test connection**). Installing the plugin without picking a paywall mode triggers no outbound calls.

- **x402.org (Test network)** — `https://x402.org/facilitator/`. Default for new installs. Sends PaymentRequirements (receiving wallet, amount, asset, network, resource URL) and the paying client's PaymentPayload. Public testnet only — not for production. [Terms](https://lfprojects.org/policies/terms-of-use/) · [Privacy](https://lfprojects.org/policies/privacy-policy/).
- **Coinbase Developer Platform** — `https://api.cdp.coinbase.com/platform/v2/x402/`. Active only when an admin selects the Coinbase CDP connector. Sends the same payload plus a CDP-signed JWT. [Terms](https://www.coinbase.com/legal/developer-platform/terms-of-service/) · [Privacy](https://www.coinbase.com/legal/privacy).

## Changelog

### 0.1.1

- Paywall page swaps the wallet buttons for a single live status message during a payment, and surfaces wallet rejections / settlement failures in a dismissible modal.

### 0.1.0

- Initial MVP: paywall posts by category (configurable) or gate all posts for humans; gate singular views for detected bots/crawlers; pay with x402 on Base Sepolia via x402.org.
