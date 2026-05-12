=== x402 Pay ===
Contributors: automattic
Tags: paywall, x402, usdc, micropayments, http-402
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Minimal HTTP 402 paywall for bots, API clients, and browser wallets. Clients pay in USDC and retry.

== Description ==

x402 Pay gates selected WordPress posts behind an x402 payment. When a paywalled URL is requested without a valid `Payment-Signature` header, the plugin responds with HTTP 402 and a `PAYMENT-REQUIRED` payload describing how to pay. Bots, API clients, and browser-wallet users can sign a USDC transfer, retry the request, and get the response.

Use it to:

* Charge automated agents per article view.
* Offer pay-per-request access to a small set of premium posts.
* Test the x402 payment flow on Base Sepolia without setting up your own facilitator.

The plugin is inert until you pick a paywall mode in **Settings → x402 Pay**. The default mode is "No posts," so installing the plugin alone does not gate anything or contact any external service.

= Audience and modes =

* **Audience** decides who gets paywalled. "Only bots" (default) uses crawler detection so human readers still see your content. "Everyone" gates both humans and bots.
* **Mode** decides which posts qualify. Choose "No posts" (off), "All posts," or restrict the paywall to a chosen category.

= Built-in facilitators =

* **x402.org (Test network)** — routes verify and settle calls through the public x402.org facilitator on Base Sepolia. Default for new installs. No real funds move.
* **Coinbase CDP** — routes through Coinbase Developer Platform on Base mainnet (real USDC). Requires a CDP API key.

== External services ==

This plugin connects to external x402 facilitators to verify and settle payments. A facilitator is **only contacted when a request hits a paywalled URL** carrying a `Payment-Signature` header, or when an admin clicks **Test connection** on the settings page. Installing the plugin without selecting a paywall mode triggers no outbound calls.

= x402.org (Test network) =

Used by the default `x402.org (Test network)` connector.

* Endpoint: `https://x402.org/facilitator/`
* What is sent: x402 PaymentRequirements (your receiving wallet address, amount, asset, network, resource URL) and the paying client's PaymentPayload (a signed USDC `transferWithAuthorization` authorization).
* Why: to verify and settle the USDC payment on Base Sepolia.
* Site: https://www.x402.org/
* This is a public testnet facilitator; do not use it for production paywalls.

= Coinbase Developer Platform =

Used only when an admin selects the **Coinbase CDP** connector and saves an API key.

* Endpoint: `https://api.cdp.coinbase.com/platform/v2/x402/`
* What is sent: the same x402 PaymentRequirements and PaymentPayload, plus a CDP-signed JWT proving the API key.
* Why: to verify and settle the USDC payment on Base mainnet.
* Terms of service: https://www.coinbase.com/legal/cloud
* Privacy policy: https://www.coinbase.com/legal/privacy

== Installation ==

1. Install and activate the plugin.
2. Visit **Settings → x402 Pay**.
3. Enter the wallet address that should receive payments.
4. Pick a paywall mode and audience.
5. Pick a facilitator. For Coinbase, paste your CDP API Key ID and secret.
6. Save.

== Frequently Asked Questions ==

= What does a paywalled request look like? =

If the request does not carry a valid `Payment-Signature` header, the plugin returns HTTP 402 with a `PAYMENT-REQUIRED` response header containing the encoded x402 PaymentRequirements. Clients sign the requirements and retry the request.

= Does this charge human readers? =

Only if you set Audience to "Everyone." The default is "Only bots/crawlers" so humans see posts as normal and only detected bot/agent traffic gets a 402.

= Do I need ETH to receive payments? =

No. x402 uses EIP-3009 `transferWithAuthorization`; the facilitator pays gas. You only need USDC inbound.

= Where are API keys stored? =

Coinbase CDP secrets are stored in their own `wp_options` row, or can be supplied via a `wp-config.php` constant or environment variable so they stay out of the database entirely.

== Screenshots ==

1. Choose paywall scope, audience, and price from a single Settings page.
2. Switch to Coinbase CDP to accept USDC on Base mainnet using your CDP API key.
3. The paywall page a human reader sees, with pay buttons for popular wallets.

== Changelog ==

= 0.1.0 =
* Initial release: paywall posts by category or all posts; gate humans, bots, or both; verify and settle USDC payments via x402.org on Base Sepolia or Coinbase CDP on Base mainnet.
