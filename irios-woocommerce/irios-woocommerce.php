<?php
/**
 * Plugin Name:          IRIOS Media for WooCommerce
 * Description:          IRIOS Media browsing and product events, visitor identity, Sponsored Products, display ads (image and video) and push notifications for WooCommerce stores.
 * Version:              0.0.0-dev
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.5
 * Author:               IRIOS Media
 * Update URI:           https://github.com/IRIOSMedia/iriosmedia-woocommerce-plugin
 * Text Domain:          irios-woocommerce
 * Domain Path:          /languages
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// Another copy of the plugin is already loaded: the previous version ("Irios for WooCommerce", folder
// irios-for-woocommerce) or this one in another folder. Both declare the same classes, so running both would mix them:
// this copy stays off and asks to deactivate the other one (the settings are shared, nothing is lost).
if ( defined( 'IRIOS_WC_FILE' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						'IRIOS Media for WooCommerce is not running: another version of the plugin is active (%s). Deactivate it in Plugins; the settings are kept.',
						plugin_basename( IRIOS_WC_FILE )
					)
				)
			);
		}
	);
	return;
}

const IRIOS_WC_VERSION = '0.0.0-dev'; // bin/package.sh sets the repo version (git tags)
const IRIOS_WC_FILE    = __FILE__;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Irios\\WooCommerce\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Consent withdrawals table (Revocations): created on activation, upgraded after an update by Plugin::boot. Its daily
// purge of rows older than 90 days is scheduled on activation (and on boot if missing) and unscheduled on deactivation.
register_activation_hook( __FILE__, array( \Irios\WooCommerce\Revocations::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Irios\WooCommerce\Revocations::class, 'deactivate' ) );

// Production dependencies (libphonenumber to normalise phone numbers), bundled in the zip by bin/package.sh.
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Declared compatibility: HPOS order storage (we store a meta on the order) and block cart/checkout.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', IRIOS_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', IRIOS_WC_FILE, true );
		}
	}
);

// Storefront strings only (the admin is always in English); translations bundled in languages/ (es_ES)
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'irios-woocommerce', false, dirname( plugin_basename( IRIOS_WC_FILE ) ) . '/languages' );
	}
);
add_action( 'plugins_loaded', array( \Irios\WooCommerce\Plugin::class, 'boot' ) );
