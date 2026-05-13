<?php
/**
 * Plugin Name:       x402 Pay
 * Description:       Minimal HTTP 402 paywall for bots, API clients, and browser wallets.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       x402-pay
 *
 * @package X402Press
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'X402PRESS_VERSION', '0.1.0' );
define( 'X402PRESS_FILE', __FILE__ );
define( 'X402PRESS_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';

add_action( 'plugins_loaded', array( \X402Press\Plugin::class, 'boot' ) );
register_activation_hook( __FILE__, array( \X402Press\Plugin::class, 'activate' ) );
