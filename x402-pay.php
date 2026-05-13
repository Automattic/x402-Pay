<?php
/**
 * Plugin Name:       x402 Pay
 * Description:       A USDC paywall for AI crawlers and bots: return HTTP 402, accept a signed micropayment, then serve the content.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       x402-pay
 *
 * @package X402Pay
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'X402_PAY_VERSION', '0.1.0' );
define( 'X402_PAY_FILE', __FILE__ );
define( 'X402_PAY_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';

add_action( 'plugins_loaded', array( \X402Pay\Plugin::class, 'boot' ) );
register_activation_hook( __FILE__, array( \X402Pay\Plugin::class, 'activate' ) );
