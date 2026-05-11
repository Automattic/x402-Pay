<?php
/**
 * Plugin Name:       x402press
 * Description:       Minimal x402 paywall for bots and API clients. Uses x402.org on Base Sepolia.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       x402press
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
