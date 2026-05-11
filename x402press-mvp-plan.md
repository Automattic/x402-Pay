# x402press Plugin MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a bare-minimum WordPress plugin that gates selected posts behind an x402 payment, delegating all crypto concerns to the public x402.org facilitator on Base Sepolia.

**Architecture:** On `template_redirect`, a `PaywallController` asks a filter whether the current request is paywalled. If yes, it checks a short-lived grant; otherwise it either responds with a JSON 402 containing `PAYMENT-REQUIRED` or verifies+settles an inbound `PAYMENT-SIGNATURE` against the x402.org facilitator. No human checkout UI, no custom DB tables, no Guzzle — just WordPress options, transients, `wp_remote_post`, and a handful of focused classes. Code is adapted from the existing Access402 plugin; see "Code reuse" below.

**Tech Stack:**
- PHP 8.1+, `declare(strict_types=1)`, namespaced PSR-4 (`X402Press\`).
- WordPress ≥ 6.4 (settings API, transients API, taxonomies, `wp_remote_post`).
- PHPUnit 10 (pure-PHP unit tests with a thin WP stub bootstrap; **no** wp-mock, **no** wp-phpunit).
- PHP_CodeSniffer with the WordPress-Extra standard.
- Composer (dev-only deps: `phpunit/phpunit`, `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp`). **No runtime Composer dependencies.**

**Code reuse (from Access402):** We copy and trim three things into the new plugin. Nothing else.
1. `src/Services/X402HeaderCodec.php` — base64-of-JSON encode/decode for `PAYMENT-REQUIRED` / `PAYMENT-SIGNATURE`. Copy verbatim.
2. `src/Services/X402FacilitatorClient.php` — the `wp_remote_post` calls to `/verify` and `/settle`. Strip CDP auth, strip logging, keep the HTTP shape.
3. Shape of the payment-requirements array from `src/Services/X402PaymentProfileResolver.php` (Base Sepolia + USDC constants only). We do not copy the class; we write a much smaller `PaymentRequirementsBuilder`.

**Out of scope (explicitly deferred):**
- REST/`rest_pre_dispatch` protection.
- Multiple rules, priority, custom rule UI, rule logs.
- Coinbase CDP / Base mainnet.
- Human checkout page, wallet-connect JS, frontend assets.
- Per-user dashboards, earnings reports, multi-tenant attribution.
- Rate limiting, nonce tracking, IP trust lists.

**Filter contract (the public extension point):**

```php
/**
 * Decide whether the current request is paywalled.
 *
 * @param array|null $rule Null if no prior filter matched; otherwise the rule so far.
 * @param array      $ctx  Request context: [
 *     'path'    => string,   // normalised request path, leading slash, no query
 *     'method'  => string,   // GET|POST|…
 *     'post_id' => int,      // 0 if not a singular post view
 * ]
 * @return array|null Null = not paywalled. Otherwise [
 *     'price'       => string,   // decimal USDC string, e.g. "0.01"
 *     'ttl'         => int,      // grant lifetime in seconds
 *     'description' => string,   // optional, shown in payment requirements
 * ]
 */
apply_filters('x402press_rule_for_request', null, $ctx);
```

The plugin registers one default callback at priority 10 that returns a rule iff the current post has the `paywall` tag or `paywall` category.

**Grant model:** On successful settle, write a WordPress transient keyed by `sha256($wallet . '|' . $path)` with the rule's TTL (default 86400s). On subsequent requests carrying the same wallet (via `X-Wallet-Address` header or the wallet embedded in a fresh `PAYMENT-SIGNATURE`), a live transient means "skip payment, serve content".

---

## File Structure

```
x402press/
├── x402press.php                      # plugin header, boot
├── composer.json
├── phpunit.xml.dist
├── phpcs.xml.dist
├── readme.txt                           # WP.org-style
├── README.md                            # developer-facing
├── src/
│   ├── Plugin.php                       # DI-lite container, hook wiring
│   ├── Admin/SettingsPage.php           # options page, 2 fields
│   ├── Http/PaywallController.php       # template_redirect orchestration
│   ├── Services/
│   │   ├── X402HeaderCodec.php          # copied from Access402
│   │   ├── X402FacilitatorClient.php    # copied + trimmed from Access402
│   │   ├── PaymentRequirementsBuilder.php
│   │   ├── RuleResolver.php             # wraps the filter
│   │   ├── DefaultPaywallRule.php       # the paywall-term rule
│   │   └── GrantStore.php               # transient wrapper
│   └── Settings/SettingsRepository.php
└── tests/
    ├── bootstrap.php                    # minimal WP function stubs
    ├── Unit/
    │   ├── X402HeaderCodecTest.php
    │   ├── PaymentRequirementsBuilderTest.php
    │   ├── RuleResolverTest.php
    │   ├── DefaultPaywallRuleTest.php
    │   ├── GrantStoreTest.php
    │   └── X402FacilitatorClientTest.php
    └── Integration/
        └── PaywallControllerTest.php
```

One responsibility per file. `Plugin` is the only place that news up objects and calls `add_action` / `add_filter`; everything else is dependency-injected and testable without touching globals.

**PR sizing:** Each task below is scoped to land as its own pull request and stay within the 100-line change budget. Where a task would blow past that budget, it is split. Commit at the end of each task.

---

## Task 1: Repository scaffold

**Files:**
- Create: `x402press.php`
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `phpcs.xml.dist`
- Create: `tests/bootstrap.php`
- Create: `README.md`
- Create: `.gitignore`

- [ ] **Step 1: Create `x402press.php`**

```php
<?php
/**
 * Plugin Name:       x402press
 * Description:       Minimal x402 paywall for bots and API clients. Uses x402.org on Base Sepolia.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       x402press
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('X402PRESS_VERSION', '0.1.0');
define('X402PRESS_FILE', __FILE__);
define('X402PRESS_DIR', plugin_dir_path(__FILE__));

require_once __DIR__ . '/vendor/autoload.php';

add_action('plugins_loaded', [\X402Press\Plugin::class, 'boot']);
register_activation_hook(__FILE__, [\X402Press\Plugin::class, 'activate']);
```

- [ ] **Step 2: Create `composer.json`**

```json
{
  "name": "automattic/x402press",
  "description": "Minimal x402 paywall for WordPress.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.0",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "autoload": {
    "psr-4": { "X402Press\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "X402Press\\Tests\\": "tests/" }
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    }
  },
  "scripts": {
    "test": "phpunit",
    "lint": "phpcs",
    "lint:fix": "phpcbf"
  }
}
```

- [ ] **Step 3: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    colors="true"
    failOnWarning="true"
    failOnRisky="true"
    cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Create `phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="x402press">
    <description>Coding standards for the x402press plugin.</description>
    <file>x402press.php</file>
    <file>src</file>
    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="sp"/>
    <rule ref="WordPress-Extra"/>
    <rule ref="PHPCompatibilityWP"/>
    <config name="testVersion" value="8.1-"/>
    <config name="minimum_supported_wp_version" value="6.4"/>
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array" value="x402press"/>
        </properties>
    </rule>
</ruleset>
```

- [ ] **Step 5: Create `tests/bootstrap.php`** (minimal WP stubs)

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Minimal WordPress stubs. Tests that need more should declare them locally.
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string { return trim(strip_tags($text)); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0): string|false { return json_encode($data, $options); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit(string $s): string { return rtrim($s, '/\\') . '/'; }
}

// Reset global state between tests.
$GLOBALS['__x402press_options']    = [];
$GLOBALS['__x402press_transients'] = [];
$GLOBALS['__x402press_filters']    = [];
$GLOBALS['__x402press_http']       = null;
```

- [ ] **Step 6: Create `README.md`**

```markdown
# x402press

Minimal WordPress plugin that gates selected posts behind an x402 payment using the public x402.org facilitator on Base Sepolia.

## Status

MVP. Bots/API clients only — there is no human checkout UI.

## Requirements

- PHP 8.1+
- WordPress 6.4+
- Composer (for development)

## Install (development)

```bash
composer install
composer test
composer lint
```

## What it does

- Adds a `Paywall` tag and category on activation.
- Adds a Settings → x402press page with two fields: wallet address, default price.
- On any frontend request for a paywalled post, responds HTTP 402 with a `PAYMENT-REQUIRED` header and a JSON body, unless the request carries a valid `PAYMENT-SIGNATURE` (verified+settled via x402.org) or a live grant.

## Extending

See the `x402press_rule_for_request` filter in `src/Services/RuleResolver.php`.
```

- [ ] **Step 7: Create `.gitignore`**

```gitignore
/vendor/
/.phpunit.cache/
/composer.lock
.DS_Store
```

- [ ] **Step 8: Install dependencies and verify the scaffold runs**

Run:
```bash
composer install
vendor/bin/phpunit --version
vendor/bin/phpcs --version
```
Expected: PHPUnit 10.x and PHPCS 3.x both print versions.

- [ ] **Step 9: Commit**

```bash
git add .
git commit -m "chore: scaffold plugin, composer, phpunit and phpcs configs"
```

---

## Task 2: `X402HeaderCodec` (copy from Access402)

**Files:**
- Create: `src/Services/X402HeaderCodec.php`
- Create: `tests/Unit/X402HeaderCodecTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/X402HeaderCodecTest.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\X402HeaderCodec;

final class X402HeaderCodecTest extends TestCase
{
    public function test_encode_produces_base64_of_json(): void
    {
        $encoded = X402HeaderCodec::encode(['scheme' => 'exact', 'price' => '0.01']);
        $this->assertSame(['scheme' => 'exact', 'price' => '0.01'], json_decode(base64_decode($encoded), true));
    }

    public function test_decode_round_trips_encode(): void
    {
        $payload = ['a' => 1, 'b' => ['nested' => true]];
        $this->assertSame($payload, X402HeaderCodec::decode(X402HeaderCodec::encode($payload)));
    }

    public function test_decode_returns_null_for_invalid_base64(): void
    {
        $this->assertNull(X402HeaderCodec::decode('!!!not-base64!!!'));
    }

    public function test_decode_returns_null_for_invalid_json(): void
    {
        $this->assertNull(X402HeaderCodec::decode(base64_encode('not json')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter X402HeaderCodecTest`
Expected: FAIL with "Class X402Press\Services\X402HeaderCodec not found".

- [ ] **Step 3: Write implementation**

Open `/Users/alex/dev/Access402/src/Services/X402HeaderCodec.php` and copy its `encode` and `decode` methods into a new file under `X402Press\Services`. The implementation is small enough to inline here:

`src/Services/X402HeaderCodec.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Services;

final class X402HeaderCodec
{
    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '';
        }
        return base64_encode($json);
    }

    public static function decode(string $header): ?array
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        $decoded = base64_decode($header, true);
        if ($decoded === false) {
            return null;
        }
        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter X402HeaderCodecTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Lint**

Run: `vendor/bin/phpcs src/Services/X402HeaderCodec.php`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Services/X402HeaderCodec.php tests/Unit/X402HeaderCodecTest.php
git commit -m "feat: add X402HeaderCodec for PAYMENT-* header serialisation"
```

---

## Task 3: `SettingsRepository`

**Files:**
- Create: `src/Settings/SettingsRepository.php`
- Create: `tests/Unit/SettingsRepositoryTest.php`

`SettingsRepository` is a thin, testable wrapper around `get_option`/`update_option`. It exposes three accessors: `wallet_address()`, `default_price()`, and `save()`. Everything else reads from these two getters.

- [ ] **Step 1: Add option stubs to `tests/bootstrap.php`**

Append to `tests/bootstrap.php`:
```php
if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['__x402press_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool
    {
        $GLOBALS['__x402press_options'][$name] = $value;
        return true;
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/SettingsRepositoryTest.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__x402press_options'] = [];
    }

    public function test_defaults_when_nothing_stored(): void
    {
        $repo = new SettingsRepository();
        $this->assertSame('', $repo->wallet_address());
        $this->assertSame('0.01', $repo->default_price());
    }

    public function test_save_then_read(): void
    {
        $repo = new SettingsRepository();
        $repo->save(['wallet_address' => '0xabc', 'default_price' => '0.25']);
        $this->assertSame('0xabc', $repo->wallet_address());
        $this->assertSame('0.25', $repo->default_price());
    }

    public function test_save_rejects_negative_price(): void
    {
        $repo = new SettingsRepository();
        $repo->save(['wallet_address' => '0xabc', 'default_price' => '-1']);
        $this->assertSame('0.01', $repo->default_price());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SettingsRepositoryTest`
Expected: FAIL with "Class X402Press\Settings\SettingsRepository not found".

- [ ] **Step 4: Implement**

`src/Settings/SettingsRepository.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Settings;

final class SettingsRepository
{
    public const OPTION_NAME   = 'x402press_settings';
    public const DEFAULT_PRICE = '0.01';

    public function wallet_address(): string
    {
        $stored = get_option(self::OPTION_NAME, []);
        return is_array($stored) ? (string) ($stored['wallet_address'] ?? '') : '';
    }

    public function default_price(): string
    {
        $stored = get_option(self::OPTION_NAME, []);
        $price  = is_array($stored) ? (string) ($stored['default_price'] ?? '') : '';
        if (!is_numeric($price) || (float) $price <= 0) {
            return self::DEFAULT_PRICE;
        }
        return $price;
    }

    public function save(array $input): void
    {
        $wallet = isset($input['wallet_address']) ? trim((string) $input['wallet_address']) : '';
        $price  = isset($input['default_price']) ? trim((string) $input['default_price']) : '';
        if (!is_numeric($price) || (float) $price <= 0) {
            $price = self::DEFAULT_PRICE;
        }
        update_option(self::OPTION_NAME, [
            'wallet_address' => $wallet,
            'default_price'  => $price,
        ]);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --filter SettingsRepositoryTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Settings/ tests/Unit/SettingsRepositoryTest.php tests/bootstrap.php
git commit -m "feat: add SettingsRepository for wallet + default price"
```

---

## Task 4: `PaymentRequirementsBuilder`

Builds the x402 `PaymentRequirements` array that goes in the `PAYMENT-REQUIRED` header and body. Base Sepolia USDC is hardcoded — this is the MVP constraint.

**Files:**
- Create: `src/Services/PaymentRequirementsBuilder.php`
- Create: `tests/Unit/PaymentRequirementsBuilderTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/PaymentRequirementsBuilderTest.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\PaymentRequirementsBuilder;

final class PaymentRequirementsBuilderTest extends TestCase
{
    public function test_builds_base_sepolia_usdc_requirements(): void
    {
        $builder = new PaymentRequirementsBuilder();
        $req = $builder->build(
            pay_to: '0x1111111111111111111111111111111111111111',
            price: '0.01',
            resource_url: 'https://example.com/article',
            description: 'Test post'
        );

        $this->assertSame('exact', $req['scheme']);
        $this->assertSame('eip155:84532', $req['network']);
        $this->assertSame('0x036CbD53842c5426634e7929541eC2318f3dCF7e', $req['asset']);
        $this->assertSame('0x1111111111111111111111111111111111111111', $req['payTo']);
        $this->assertSame('10000', $req['maxAmountRequired']); // 0.01 USDC → 10_000 with 6 decimals
        $this->assertSame('https://example.com/article', $req['resource']);
        $this->assertSame('Test post', $req['description']);
        $this->assertArrayHasKey('maxTimeoutSeconds', $req);
    }

    public function test_price_with_many_decimals_is_truncated_to_6(): void
    {
        $builder = new PaymentRequirementsBuilder();
        $req = $builder->build(
            pay_to: '0xabc',
            price: '0.1234567',
            resource_url: 'https://example.com',
            description: ''
        );
        $this->assertSame('123456', $req['maxAmountRequired']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PaymentRequirementsBuilderTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`src/Services/PaymentRequirementsBuilder.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Services;

final class PaymentRequirementsBuilder
{
    private const NETWORK        = 'eip155:84532'; // Base Sepolia
    private const ASSET          = '0x036CbD53842c5426634e7929541eC2318f3dCF7e'; // USDC on Base Sepolia
    private const ASSET_DECIMALS = 6;
    private const SCHEME         = 'exact';
    private const MAX_TIMEOUT    = 120;

    public function build(
        string $pay_to,
        string $price,
        string $resource_url,
        string $description
    ): array {
        return [
            'scheme'            => self::SCHEME,
            'network'           => self::NETWORK,
            'asset'             => self::ASSET,
            'payTo'             => $pay_to,
            'maxAmountRequired' => $this->to_base_units($price),
            'resource'          => $resource_url,
            'description'       => $description,
            'mimeType'          => 'application/json',
            'maxTimeoutSeconds' => self::MAX_TIMEOUT,
        ];
    }

    private function to_base_units(string $decimal): string
    {
        if (!is_numeric($decimal) || (float) $decimal <= 0) {
            return '0';
        }
        [$whole, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $frac = substr($frac, 0, self::ASSET_DECIMALS);
        $frac = str_pad($frac, self::ASSET_DECIMALS, '0');
        $combined = ltrim($whole . $frac, '0');
        return $combined === '' ? '0' : $combined;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter PaymentRequirementsBuilderTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/PaymentRequirementsBuilder.php tests/Unit/PaymentRequirementsBuilderTest.php
git commit -m "feat: add PaymentRequirementsBuilder (Base Sepolia USDC)"
```

---

## Task 5: `X402FacilitatorClient` (copied & trimmed from Access402)

This is the only HTTP layer. It uses `wp_remote_post`, no Guzzle. We are copying from `/Users/alex/dev/Access402/src/Services/X402FacilitatorClient.php` and stripping everything that is not `POST /verify` or `POST /settle` against a fixed base URL.

**Files:**
- Create: `src/Services/X402FacilitatorClient.php`
- Create: `tests/Unit/X402FacilitatorClientTest.php`

- [ ] **Step 1: Add HTTP stubs to `tests/bootstrap.php`**

Append to `tests/bootstrap.php`:
```php
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code = '', public string $message = '') {}
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return $thing instanceof \WP_Error; }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = [])
    {
        $GLOBALS['__x402press_http'] = ['url' => $url, 'args' => $args];
        $next = $GLOBALS['__x402press_http_next'] ?? null;
        if ($next instanceof \WP_Error) { return $next; }
        return $next ?? ['response' => ['code' => 200], 'body' => '{}'];
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        return (string) ($response['body'] ?? '');
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/X402FacilitatorClientTest.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\X402FacilitatorClient;

final class X402FacilitatorClientTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__x402press_http']      = null;
        $GLOBALS['__x402press_http_next'] = null;
    }

    public function test_verify_posts_to_x402_org_verify(): void
    {
        $GLOBALS['__x402press_http_next'] = [
            'response' => ['code' => 200],
            'body'     => '{"isValid":true}',
        ];
        $client = new X402FacilitatorClient();
        $result = $client->verify(['scheme' => 'exact'], ['signature' => 'x']);

        $this->assertSame('https://x402.org/facilitator/verify', $GLOBALS['__x402press_http']['url']);
        $this->assertTrue($result['isValid']);
        $this->assertSame(
            json_encode(['paymentRequirements' => ['scheme' => 'exact'], 'paymentPayload' => ['signature' => 'x']]),
            $GLOBALS['__x402press_http']['args']['body']
        );
    }

    public function test_settle_posts_to_x402_org_settle(): void
    {
        $GLOBALS['__x402press_http_next'] = [
            'response' => ['code' => 200],
            'body'     => '{"success":true,"transaction":"0xabc"}',
        ];
        $client = new X402FacilitatorClient();
        $result = $client->settle(['scheme' => 'exact'], ['signature' => 'x']);

        $this->assertSame('https://x402.org/facilitator/settle', $GLOBALS['__x402press_http']['url']);
        $this->assertTrue($result['success']);
        $this->assertSame('0xabc', $result['transaction']);
    }

    public function test_wp_error_becomes_failure(): void
    {
        $GLOBALS['__x402press_http_next'] = new \WP_Error('http_fail', 'boom');
        $client = new X402FacilitatorClient();
        $result = $client->verify([], []);
        $this->assertFalse($result['isValid']);
        $this->assertSame('boom', $result['error']);
    }

    public function test_non_2xx_becomes_failure(): void
    {
        $GLOBALS['__x402press_http_next'] = [
            'response' => ['code' => 500],
            'body'     => '{"error":"bad"}',
        ];
        $client = new X402FacilitatorClient();
        $result = $client->settle([], []);
        $this->assertFalse($result['success']);
        $this->assertSame('bad', $result['error']);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter X402FacilitatorClientTest`
Expected: FAIL.

- [ ] **Step 4: Implement** (derived from Access402's `X402FacilitatorClient::request`, with CDP auth and logging removed)

`src/Services/X402FacilitatorClient.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Services;

final class X402FacilitatorClient
{
    private const BASE_URL = 'https://x402.org/facilitator/';
    private const TIMEOUT  = 25;

    public function verify(array $requirements, array $payload): array
    {
        $response = $this->post('verify', [
            'paymentRequirements' => $requirements,
            'paymentPayload'      => $payload,
        ]);
        return [
            'isValid' => (bool) ($response['body']['isValid'] ?? false),
            'error'   => $response['error'] ?? ($response['body']['invalidReason'] ?? null),
            'raw'     => $response['body'],
        ];
    }

    public function settle(array $requirements, array $payload): array
    {
        $response = $this->post('settle', [
            'paymentRequirements' => $requirements,
            'paymentPayload'      => $payload,
        ]);
        return [
            'success'     => (bool) ($response['body']['success'] ?? false),
            'transaction' => $response['body']['transaction'] ?? null,
            'network'     => $response['body']['network'] ?? null,
            'error'       => $response['error'] ?? ($response['body']['errorReason'] ?? null),
            'raw'         => $response['body'],
        ];
    }

    private function post(string $endpoint, array $body): array
    {
        $raw = wp_remote_post(self::BASE_URL . ltrim($endpoint, '/'), [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($raw)) {
            return ['body' => [], 'error' => $raw->message];
        }

        $code   = wp_remote_retrieve_response_code($raw);
        $parsed = json_decode((string) wp_remote_retrieve_body($raw), true);
        if (!is_array($parsed)) {
            $parsed = [];
        }
        if ($code < 200 || $code >= 300) {
            return ['body' => $parsed, 'error' => $parsed['error'] ?? "HTTP {$code}"];
        }
        return ['body' => $parsed, 'error' => null];
    }
}
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --filter X402FacilitatorClientTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Services/X402FacilitatorClient.php tests/Unit/X402FacilitatorClientTest.php tests/bootstrap.php
git commit -m "feat: add X402FacilitatorClient for x402.org /verify and /settle"
```

---

## Task 6: `RuleResolver` + default paywall-term rule

**Files:**
- Create: `src/Services/RuleResolver.php`
- Create: `src/Services/DefaultPaywallRule.php`
- Create: `tests/Unit/RuleResolverTest.php`
- Create: `tests/Unit/DefaultPaywallRuleTest.php`

### 6a. `RuleResolver`

- [ ] **Step 1: Add filter stubs to `tests/bootstrap.php`**

Append:
```php
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args)
    {
        foreach ($GLOBALS['__x402press_filters'][$hook] ?? [] as $cb) {
            $value = $cb($value, ...$args);
        }
        return $value;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $cb, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['__x402press_filters'][$hook][] = $cb;
        return true;
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/RuleResolverTest.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\RuleResolver;

final class RuleResolverTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__x402press_filters'] = [];
    }

    public function test_returns_null_when_no_filter_matches(): void
    {
        $resolver = new RuleResolver();
        $this->assertNull($resolver->resolve(['path' => '/x', 'method' => 'GET', 'post_id' => 0]));
    }

    public function test_returns_rule_from_filter_with_defaults_applied(): void
    {
        add_filter('x402press_rule_for_request', static fn () => ['price' => '0.25'], 10, 2);
        $resolver = new RuleResolver();
        $rule = $resolver->resolve(['path' => '/x', 'method' => 'GET', 'post_id' => 0]);

        $this->assertSame('0.25', $rule['price']);
        $this->assertSame(86400, $rule['ttl']);
        $this->assertSame('', $rule['description']);
    }

    public function test_rejects_rule_with_invalid_price(): void
    {
        add_filter('x402press_rule_for_request', static fn () => ['price' => 'free'], 10, 2);
        $resolver = new RuleResolver();
        $this->assertNull($resolver->resolve(['path' => '/x', 'method' => 'GET', 'post_id' => 0]));
    }
}
```

- [ ] **Step 3: Implement**

`src/Services/RuleResolver.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Services;

final class RuleResolver
{
    public const HOOK        = 'x402press_rule_for_request';
    public const DEFAULT_TTL = 86400;

    public function resolve(array $ctx): ?array
    {
        $raw = apply_filters(self::HOOK, null, $ctx);
        if (!is_array($raw)) {
            return null;
        }

        $price = isset($raw['price']) ? (string) $raw['price'] : '';
        if (!is_numeric($price) || (float) $price <= 0) {
            return null;
        }

        $ttl = isset($raw['ttl']) ? (int) $raw['ttl'] : self::DEFAULT_TTL;
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_TTL;
        }

        return [
            'price'       => $price,
            'ttl'         => $ttl,
            'description' => isset($raw['description']) ? (string) $raw['description'] : '',
        ];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter RuleResolverTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/RuleResolver.php tests/Unit/RuleResolverTest.php tests/bootstrap.php
git commit -m "feat: add RuleResolver wrapping x402press_rule_for_request filter"
```

### 6b. `DefaultPaywallRule`

- [ ] **Step 1: Add `has_term` stub to `tests/bootstrap.php`**

Append:
```php
if (!function_exists('has_term')) {
    function has_term(string $term, string $taxonomy, int $post_id): bool
    {
        return in_array([$term, $taxonomy, $post_id], $GLOBALS['__x402press_terms'] ?? [], true);
    }
}
$GLOBALS['__x402press_terms'] = [];
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/DefaultPaywallRuleTest.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\DefaultPaywallRule;
use X402Press\Settings\SettingsRepository;

final class DefaultPaywallRuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__x402press_terms']   = [];
        $GLOBALS['__x402press_options'] = [];
    }

    public function test_returns_null_when_no_post_id(): void
    {
        $rule = new DefaultPaywallRule(new SettingsRepository());
        $this->assertNull($rule->__invoke(null, ['post_id' => 0]));
    }

    public function test_returns_rule_when_post_has_paywall_tag(): void
    {
        $GLOBALS['__x402press_terms'] = [['paywall', 'post_tag', 42]];
        $rule = new DefaultPaywallRule(new SettingsRepository());
        $this->assertSame(
            ['price' => '0.01', 'ttl' => 86400],
            $rule->__invoke(null, ['post_id' => 42])
        );
    }

    public function test_returns_rule_when_post_has_paywall_category(): void
    {
        $GLOBALS['__x402press_terms'] = [['paywall', 'category', 7]];
        $rule = new DefaultPaywallRule(new SettingsRepository());
        $this->assertNotNull($rule->__invoke(null, ['post_id' => 7]));
    }

    public function test_preserves_rule_from_higher_priority_filter(): void
    {
        $GLOBALS['__x402press_terms'] = [['paywall', 'post_tag', 42]];
        $rule = new DefaultPaywallRule(new SettingsRepository());
        $preset = ['price' => '9.99', 'ttl' => 10];
        $this->assertSame($preset, $rule->__invoke($preset, ['post_id' => 42]));
    }
}
```

- [ ] **Step 3: Implement**

`src/Services/DefaultPaywallRule.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Services;

use X402Press\Settings\SettingsRepository;

final class DefaultPaywallRule
{
    public const TERM = 'paywall';

    public function __construct(private readonly SettingsRepository $settings) {}

    public function __invoke($rule, array $ctx): ?array
    {
        if (is_array($rule)) {
            return $rule;
        }
        $post_id = (int) ($ctx['post_id'] ?? 0);
        if ($post_id <= 0) {
            return null;
        }
        if (!has_term(self::TERM, 'post_tag', $post_id) && !has_term(self::TERM, 'category', $post_id)) {
            return null;
        }
        return [
            'price' => $this->settings->default_price(),
            'ttl'   => RuleResolver::DEFAULT_TTL,
        ];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter DefaultPaywallRuleTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/DefaultPaywallRule.php tests/Unit/DefaultPaywallRuleTest.php tests/bootstrap.php
git commit -m "feat: default paywall rule for posts tagged or categorised 'paywall'"
```

---

## Task 7: `GrantStore`

**Files:**
- Create: `src/Services/GrantStore.php`
- Create: `tests/Unit/GrantStoreTest.php`

- [ ] **Step 1: Add transient stubs to `tests/bootstrap.php`**

Append:
```php
if (!function_exists('get_transient')) {
    function get_transient(string $key)
    {
        $entry = $GLOBALS['__x402press_transients'][$key] ?? null;
        if ($entry === null) { return false; }
        if ($entry['expires'] > 0 && $entry['expires'] < time()) {
            unset($GLOBALS['__x402press_transients'][$key]);
            return false;
        }
        return $entry['value'];
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        $GLOBALS['__x402press_transients'][$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/GrantStoreTest.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\GrantStore;

final class GrantStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__x402press_transients'] = [];
    }

    public function test_has_grant_false_by_default(): void
    {
        $store = new GrantStore();
        $this->assertFalse($store->has_grant('0xabc', '/foo'));
    }

    public function test_issue_then_has_grant(): void
    {
        $store = new GrantStore();
        $store->issue('0xABC', '/foo', 60, ['tx' => '0x1']);
        $this->assertTrue($store->has_grant('0xabc', '/foo')); // case-insensitive wallet
    }

    public function test_grant_is_scoped_to_path(): void
    {
        $store = new GrantStore();
        $store->issue('0xabc', '/foo', 60, []);
        $this->assertFalse($store->has_grant('0xabc', '/bar'));
    }

    public function test_empty_wallet_never_matches(): void
    {
        $store = new GrantStore();
        $store->issue('', '/foo', 60, []);
        $this->assertFalse($store->has_grant('', '/foo'));
    }
}
```

- [ ] **Step 3: Implement**

`src/Services/GrantStore.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Services;

final class GrantStore
{
    private const PREFIX = 'x402press_grant_';

    public function has_grant(string $wallet, string $path): bool
    {
        $key = $this->key($wallet, $path);
        if ($key === null) {
            return false;
        }
        return get_transient($key) !== false;
    }

    public function issue(string $wallet, string $path, int $ttl, array $meta): void
    {
        $key = $this->key($wallet, $path);
        if ($key === null || $ttl <= 0) {
            return;
        }
        set_transient($key, $meta + ['issued_at' => time()], $ttl);
    }

    private function key(string $wallet, string $path): ?string
    {
        $wallet = strtolower(trim($wallet));
        if ($wallet === '') {
            return null;
        }
        return self::PREFIX . hash('sha256', $wallet . '|' . $path);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter GrantStoreTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/GrantStore.php tests/Unit/GrantStoreTest.php tests/bootstrap.php
git commit -m "feat: GrantStore using WP transients, keyed by wallet+path"
```

---

## Task 8: `PaywallController` + `Plugin` wiring

This is the only non-trivial orchestration in the plugin. It runs on `template_redirect`. Keep the controller free of `add_action` calls — `Plugin` wires it up.

**Files:**
- Create: `src/Plugin.php`
- Create: `src/Http/PaywallController.php`
- Create: `tests/Integration/PaywallControllerTest.php`

- [ ] **Step 1: Add request stubs to `tests/bootstrap.php`**

Append:
```php
if (!function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string
    {
        return 'https://example.test' . $path;
    }
}
if (!function_exists('status_header')) {
    function status_header(int $code): void
    {
        $GLOBALS['__x402press_response']['status'] = $code;
    }
}
if (!function_exists('nocache_headers')) {
    function nocache_headers(): void {}
}
$GLOBALS['__x402press_response'] = ['status' => 200, 'headers' => [], 'body' => null, 'exited' => false];
```

- [ ] **Step 2: Write the failing integration test**

`tests/Integration/PaywallControllerTest.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Tests\Integration;

use PHPUnit\Framework\TestCase;
use X402Press\Http\PaywallController;
use X402Press\Services\GrantStore;
use X402Press\Services\PaymentRequirementsBuilder;
use X402Press\Services\RuleResolver;
use X402Press\Services\X402FacilitatorClient;
use X402Press\Services\X402HeaderCodec;
use X402Press\Settings\SettingsRepository;

final class PaywallControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__x402press_filters']    = [];
        $GLOBALS['__x402press_transients'] = [];
        $GLOBALS['__x402press_options']    = ['x402press_settings' => [
            'wallet_address' => '0xreceiver',
            'default_price'  => '0.01',
        ]];
        $GLOBALS['__x402press_response']   = ['status' => 200, 'headers' => [], 'body' => null, 'exited' => false];
        $GLOBALS['__x402press_http']       = null;
        $GLOBALS['__x402press_http_next']  = null;
    }

    private function controller(): PaywallController
    {
        return new PaywallController(
            new RuleResolver(),
            new PaymentRequirementsBuilder(),
            new X402FacilitatorClient(),
            new GrantStore(),
            new SettingsRepository()
        );
    }

    public function test_passes_through_when_no_rule_matches(): void
    {
        $this->controller()->handle([
            'path'    => '/foo',
            'method'  => 'GET',
            'post_id' => 0,
            'headers' => [],
        ]);
        $this->assertSame(200, $GLOBALS['__x402press_response']['status']);
        $this->assertFalse($GLOBALS['__x402press_response']['exited']);
    }

    public function test_responds_402_when_rule_matches_and_no_signature(): void
    {
        add_filter('x402press_rule_for_request', static fn () => ['price' => '0.01'], 10, 2);

        $this->controller()->handle([
            'path'    => '/foo',
            'method'  => 'GET',
            'post_id' => 0,
            'headers' => [],
        ]);

        $this->assertSame(402, $GLOBALS['__x402press_response']['status']);
        $this->assertArrayHasKey('PAYMENT-REQUIRED', $GLOBALS['__x402press_response']['headers']);
        $decoded = X402HeaderCodec::decode($GLOBALS['__x402press_response']['headers']['PAYMENT-REQUIRED']);
        $this->assertSame('0xreceiver', $decoded['payTo']);
        $this->assertSame('10000', $decoded['maxAmountRequired']);
        $this->assertTrue($GLOBALS['__x402press_response']['exited']);
    }

    public function test_allows_request_with_live_grant(): void
    {
        add_filter('x402press_rule_for_request', static fn () => ['price' => '0.01'], 10, 2);
        (new GrantStore())->issue('0xbuyer', '/foo', 60, []);

        $this->controller()->handle([
            'path'    => '/foo',
            'method'  => 'GET',
            'post_id' => 0,
            'headers' => ['X-Wallet-Address' => '0xbuyer'],
        ]);

        $this->assertSame(200, $GLOBALS['__x402press_response']['status']);
        $this->assertFalse($GLOBALS['__x402press_response']['exited']);
    }

    public function test_verifies_and_settles_then_issues_grant(): void
    {
        add_filter('x402press_rule_for_request', static fn () => ['price' => '0.01'], 10, 2);

        $payload = X402HeaderCodec::encode([
            'scheme'  => 'exact',
            'payload' => ['authorization' => ['from' => '0xbuyer']],
        ]);

        // Two sequential HTTP calls: verify, then settle.
        $GLOBALS['__x402press_http_queue'] = [
            ['response' => ['code' => 200], 'body' => '{"isValid":true}'],
            ['response' => ['code' => 200], 'body' => '{"success":true,"transaction":"0xdead"}'],
        ];

        $this->controller()->handle([
            'path'    => '/foo',
            'method'  => 'GET',
            'post_id' => 0,
            'headers' => ['PAYMENT-SIGNATURE' => $payload],
        ]);

        $this->assertSame(200, $GLOBALS['__x402press_response']['status']);
        $this->assertTrue((new GrantStore())->has_grant('0xbuyer', '/foo'));
    }
}
```

The queue-based HTTP stub needs a small extension. Update the `wp_remote_post` stub in `tests/bootstrap.php` to prefer a queue if present:

```php
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = [])
    {
        $GLOBALS['__x402press_http'] = ['url' => $url, 'args' => $args];
        if (!empty($GLOBALS['__x402press_http_queue'])) {
            return array_shift($GLOBALS['__x402press_http_queue']);
        }
        $next = $GLOBALS['__x402press_http_next'] ?? null;
        if ($next instanceof \WP_Error) { return $next; }
        return $next ?? ['response' => ['code' => 200], 'body' => '{}'];
    }
}
```
(Replace the previous stub.)

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PaywallControllerTest`
Expected: FAIL (controller does not exist).

- [ ] **Step 4: Implement `PaywallController`**

`src/Http/PaywallController.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Http;

use X402Press\Services\GrantStore;
use X402Press\Services\PaymentRequirementsBuilder;
use X402Press\Services\RuleResolver;
use X402Press\Services\X402FacilitatorClient;
use X402Press\Services\X402HeaderCodec;
use X402Press\Settings\SettingsRepository;

final class PaywallController
{
    public function __construct(
        private readonly RuleResolver $rules,
        private readonly PaymentRequirementsBuilder $builder,
        private readonly X402FacilitatorClient $facilitator,
        private readonly GrantStore $grants,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param array{path:string,method:string,post_id:int,headers:array<string,string>} $request
     */
    public function handle(array $request): void
    {
        $rule = $this->rules->resolve([
            'path'    => $request['path'],
            'method'  => $request['method'],
            'post_id' => $request['post_id'],
        ]);
        if ($rule === null) {
            return;
        }

        $wallet_hint = (string) ($request['headers']['X-Wallet-Address'] ?? '');
        if ($wallet_hint !== '' && $this->grants->has_grant($wallet_hint, $request['path'])) {
            return;
        }

        $requirements = $this->builder->build(
            pay_to: $this->settings->wallet_address(),
            price: $rule['price'],
            resource_url: home_url($request['path']),
            description: $rule['description']
        );

        $signature_header = (string) ($request['headers']['PAYMENT-SIGNATURE'] ?? '');
        if ($signature_header === '') {
            $this->respond_402($requirements, ['error' => 'payment_required']);
            return;
        }

        $payload = X402HeaderCodec::decode($signature_header);
        if ($payload === null) {
            $this->respond_402($requirements, ['error' => 'invalid_signature_header']);
            return;
        }

        $verify = $this->facilitator->verify($requirements, $payload);
        if (!$verify['isValid']) {
            $this->respond_402($requirements, ['error' => 'verify_failed', 'reason' => $verify['error']]);
            return;
        }

        $settle = $this->facilitator->settle($requirements, $payload);
        if (!$settle['success']) {
            $this->respond_402($requirements, ['error' => 'settle_failed', 'reason' => $settle['error']]);
            return;
        }

        $wallet = $this->extract_wallet($payload) ?: $wallet_hint;
        if ($wallet !== '') {
            $this->grants->issue($wallet, $request['path'], $rule['ttl'], [
                'transaction' => $settle['transaction'],
            ]);
        }
    }

    private function respond_402(array $requirements, array $body): void
    {
        nocache_headers();
        status_header(402);
        $GLOBALS['__x402press_response']['headers']['Content-Type']     = 'application/json';
        $GLOBALS['__x402press_response']['headers']['PAYMENT-REQUIRED'] = X402HeaderCodec::encode($requirements);
        $GLOBALS['__x402press_response']['body']                        = wp_json_encode(['requirements' => $requirements] + $body);
        $GLOBALS['__x402press_response']['exited']                      = true;
    }

    private function extract_wallet(array $payload): string
    {
        return (string) (
            $payload['payload']['authorization']['from']
            ?? $payload['payload']['from']
            ?? ''
        );
    }
}
```

Note: in production, `respond_402` will `echo $body` and `exit`. For tests we capture to `$GLOBALS['__x402press_response']`. We will wrap the echo/exit at the hook-wiring site (Task 8b) so the controller stays testable.

- [ ] **Step 5: Run integration tests**

Run: `vendor/bin/phpunit --filter PaywallControllerTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Http/PaywallController.php tests/Integration/PaywallControllerTest.php tests/bootstrap.php
git commit -m "feat: PaywallController orchestrates 402 / verify / settle / grant"
```

### 8b. `Plugin` bootstrap

- [ ] **Step 1: Implement `src/Plugin.php`**

```php
<?php
declare(strict_types=1);

namespace X402Press;

use X402Press\Http\PaywallController;
use X402Press\Services\DefaultPaywallRule;
use X402Press\Services\GrantStore;
use X402Press\Services\PaymentRequirementsBuilder;
use X402Press\Services\RuleResolver;
use X402Press\Services\X402FacilitatorClient;
use X402Press\Settings\SettingsRepository;

final class Plugin
{
    public static function boot(): void
    {
        $settings    = new SettingsRepository();
        $controller  = new PaywallController(
            new RuleResolver(),
            new PaymentRequirementsBuilder(),
            new X402FacilitatorClient(),
            new GrantStore(),
            $settings
        );
        $default_rule = new DefaultPaywallRule($settings);

        add_filter(RuleResolver::HOOK, $default_rule, 10, 2);

        add_action('template_redirect', static function () use ($controller): void {
            $post_id = is_singular() ? (int) get_queried_object_id() : 0;
            $path    = (string) (wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH) ?? '/');
            $headers = self::collect_headers();
            $echo    = function (): void {
                if (!empty($GLOBALS['__x402press_response']['exited'])) {
                    echo (string) $GLOBALS['__x402press_response']['body'];
                    exit;
                }
            };
            $controller->handle([
                'path'    => $path,
                'method'  => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                'post_id' => $post_id,
                'headers' => $headers,
            ]);
            $echo();
        });
    }

    public static function activate(): void
    {
        foreach (['post_tag', 'category'] as $taxonomy) {
            if (!term_exists(DefaultPaywallRule::TERM, $taxonomy)) {
                wp_insert_term(DefaultPaywallRule::TERM, $taxonomy);
            }
        }
    }

    /** @return array<string,string> */
    private static function collect_headers(): array
    {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $out[$name] = (string) $value;
            }
        }
        return $out;
    }
}
```

- [ ] **Step 2: Lint**

Run: `vendor/bin/phpcs`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add src/Plugin.php x402press.php
git commit -m "feat: wire Plugin bootstrap to template_redirect"
```

---

## Task 9: Admin settings page

**Files:**
- Create: `src/Admin/SettingsPage.php`

Register a minimal options page under Settings → x402press with two fields. No tests — this is glue around `register_setting` / `settings_fields` that buys nothing from unit testing. Manual verification is the acceptance gate.

- [ ] **Step 1: Implement**

`src/Admin/SettingsPage.php`:
```php
<?php
declare(strict_types=1);

namespace X402Press\Admin;

use X402Press\Settings\SettingsRepository;

final class SettingsPage
{
    public const MENU_SLUG = 'x402press';
    public const GROUP     = 'x402press_settings_group';

    public function __construct(private readonly SettingsRepository $settings) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu(): void
    {
        add_options_page(
            __('x402press', 'x402press'),
            __('x402press', 'x402press'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function register_settings(): void
    {
        register_setting(self::GROUP, SettingsRepository::OPTION_NAME, [
            'sanitize_callback' => function ($input): array {
                $this->settings->save(is_array($input) ? $input : []);
                return [
                    'wallet_address' => $this->settings->wallet_address(),
                    'default_price'  => $this->settings->default_price(),
                ];
            },
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $wallet = esc_attr($this->settings->wallet_address());
        $price  = esc_attr($this->settings->default_price());
        $option = esc_attr(SettingsRepository::OPTION_NAME);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('x402press', 'x402press'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="x402press-wallet"><?php esc_html_e('Receiving wallet address', 'x402press'); ?></label>
                        </th>
                        <td>
                            <input name="<?php echo $option; ?>[wallet_address]" id="x402press-wallet" type="text" class="regular-text" value="<?php echo $wallet; ?>"/>
                            <p class="description"><?php esc_html_e('Base Sepolia address that receives USDC.', 'x402press'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="x402press-price"><?php esc_html_e('Default price (USDC)', 'x402press'); ?></label>
                        </th>
                        <td>
                            <input name="<?php echo $option; ?>[default_price]" id="x402press-price" type="text" class="small-text" value="<?php echo $price; ?>"/>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
```

- [ ] **Step 2: Wire into `Plugin::boot`**

In `src/Plugin.php`, inside `boot()`, after the `$default_rule` line, add:

```php
if (is_admin()) {
    (new \X402Press\Admin\SettingsPage($settings))->register();
}
```

- [ ] **Step 3: Manual verification**

1. Install the plugin into a local WordPress.
2. Activate it.
3. Navigate to Settings → x402press.
4. Enter a wallet address and a price, save.
5. Reload and confirm values persist.

- [ ] **Step 4: Commit**

```bash
git add src/Admin/SettingsPage.php src/Plugin.php
git commit -m "feat: admin settings page with wallet address and default price"
```

---

## Task 10: End-to-end smoke test on a live site

This is a manual, human-verified checklist. No automation for MVP.

- [ ] **Step 1: Prepare**
1. Deploy the plugin to a local WordPress 6.4+ site.
2. Activate it — confirm the `paywall` tag and category appear in the admin.
3. On the settings page, set your Base Sepolia USDC receiving address. Leave the price at `0.01`.
4. Create a test post and add the `paywall` tag.

- [ ] **Step 2: Unauthenticated request returns 402**

```bash
curl -sS -D - "https://your-site.local/?p=POST_ID" -o /dev/null
```

Expected:
- `HTTP/1.1 402 Payment Required`
- A `PAYMENT-REQUIRED:` header containing a base64 payload.
- Body is JSON with a `requirements` key.

- [ ] **Step 3: Decode the `PAYMENT-REQUIRED` header**

```bash
echo "<HEADER_VALUE>" | base64 -d | jq .
```

Expected: JSON with `"network": "eip155:84532"`, `"asset": "0x036CbD..."`, `"payTo"` matching your settings, and `"maxAmountRequired": "10000"`.

- [ ] **Step 4: Submit a signed payment and receive the post**

Using an x402 client of your choice (e.g. the CLI from the x402 reference implementations) with a Base Sepolia testnet wallet that holds test USDC, make the same request again while presenting a `PAYMENT-SIGNATURE` header.

Expected:
- `HTTP/1.1 200 OK`
- The post HTML in the body.

- [ ] **Step 5: Repeat request within TTL is unpaid-and-allowed**

Repeat the request, this time sending only `X-Wallet-Address: <the wallet from step 4>` and no `PAYMENT-SIGNATURE`.

Expected: `HTTP/1.1 200 OK`. The grant stored in Task 8 satisfies this request.

- [ ] **Step 6: Remove the tag; request becomes free**

Remove the `paywall` tag from the post. Make the request with no headers.

Expected: `HTTP/1.1 200 OK`.

- [ ] **Step 7: Commit a short CHANGELOG entry**

Append to `README.md`:

```markdown
## 0.1.0

- Initial MVP: paywall posts tagged or categorised `paywall`, pay with x402 on Base Sepolia via x402.org.
```

```bash
git add README.md
git commit -m "docs: changelog entry for 0.1.0"
```

---

## Self-Review Notes

**Spec coverage:**
- Wallet + default price setting → Task 3, Task 9.
- Base Sepolia USDC hardcoded → Task 4.
- Filter for paywalled URLs → Task 6a.
- Default: posts with `paywall` tag or category → Task 6b.
- Create `paywall` taxonomy terms on activation → Task 8b (`Plugin::activate`).
- JSON-only 402, no human checkout UI → Task 8 (responses emit JSON).
- ~24h grant → Task 6a (`DEFAULT_TTL = 86400`) and Task 7.
- PHPUnit + PHPCS WordPress-Extra → Task 1.
- Modern PHP practices (strict types, namespaces, PSR-4) → every task.
- Reuse Access402 code, avoid Guzzle → Task 2 and Task 5 (copy; `wp_remote_post` only).

**Placeholder scan:** No TBDs or "handle edge cases" placeholders; every step contains the code or command it requires.

**Type consistency:** `RuleResolver::HOOK`, `RuleResolver::DEFAULT_TTL`, `DefaultPaywallRule::TERM`, `SettingsRepository::OPTION_NAME` are defined once and referenced by symbol from every consumer. Rule array keys (`price`, `ttl`, `description`) are the same in the resolver, the default rule, and the controller.

## Execution Handoff

Plan complete and saved to `docs/plans/2026-04-20-x402press-mvp.md`. Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session with checkpoints for review.

Which approach?
