<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Integration;
use Irios\WooCommerce\Settings;
use Irios\WooCommerce\SyncWebhooks;

/**
 * The product webhooks to the IRIOS Media sync service are created by the plugin from two settings (URL and secret),
 * so the store does not create them by hand in WooCommerce → Settings → Advanced → Webhooks.
 */
final class SyncWebhooksTest extends WordPressTestCase {

	private const URL    = 'https://sync.example.com/v1/webhooks/woocommerce/shop';
	private const SECRET = 'a-secret-of-at-least-16-chars';

	protected function setUp(): void {
		parent::setUp();
		\WC_Webhook::reset();
		\WC_Admin_Settings::$errors = array();
		Functions\when( 'wc_get_webhook' )->alias( fn ( $id ) => \WC_Webhook::$saved[ (int) $id ] ?? false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( fn ( $url ) => parse_url( $url ) );
	}

	/** @return array<string,\WC_Webhook> */
	private function by_topic(): array {
		$out = array();
		foreach ( \WC_Webhook::$saved as $webhook ) {
			$out[ $webhook->topic ] = $webhook;
		}
		return $out;
	}

	public function test_saving_the_url_creates_one_active_webhook_per_product_topic(): void {
		SyncWebhooks::sync( self::URL, self::SECRET, 1 );

		$webhooks = $this->by_topic();
		$this->assertSame( array( 'product.created', 'product.updated', 'product.deleted' ), array_keys( $webhooks ) );
		foreach ( $webhooks as $topic => $webhook ) {
			$this->assertSame( 'IRIOS Media sync service: ' . $topic, $webhook->name );
			$this->assertSame( self::URL, $webhook->delivery_url );
			$this->assertSame( self::SECRET, $webhook->secret );
			$this->assertSame( 'wp_api_v3', $webhook->api_version );
			$this->assertSame( 'active', $webhook->status );
			$this->assertSame( 1, $webhook->user_id );
		}
	}

	public function test_saving_again_updates_them_and_turns_a_disabled_one_back_on(): void {
		SyncWebhooks::sync( self::URL, self::SECRET, 1 );
		$this->by_topic()['product.updated']->status = 'disabled'; // WooCommerce, after 5 failed deliveries

		SyncWebhooks::sync( 'https://new.example.com/v1/webhooks/woocommerce/shop', 'another-secret-16-chars', 2 );

		$this->assertCount( 3, \WC_Webhook::$saved );
		$updated = $this->by_topic()['product.updated'];
		$this->assertSame( 'active', $updated->status );
		$this->assertSame( 'https://new.example.com/v1/webhooks/woocommerce/shop', $updated->delivery_url );
		$this->assertSame( 'another-secret-16-chars', $updated->secret );
		$this->assertSame( 1, $updated->user_id ); // the user it was created with is kept
	}

	public function test_an_empty_url_removes_them_and_leaves_the_store_webhooks_alone(): void {
		$own = new \WC_Webhook();
		$own->set_name( 'Order export' );
		$own->set_topic( 'order.created' );
		$own->save();
		SyncWebhooks::sync( self::URL, self::SECRET, 1 );

		SyncWebhooks::sync( '', '', 1 );

		$this->assertSame( array( 'Order export' ), array_map( fn ( $w ) => $w->name, array_values( \WC_Webhook::$saved ) ) );
	}

	public function test_a_duplicate_of_its_own_webhook_is_removed(): void {
		SyncWebhooks::sync( self::URL, self::SECRET, 1 );
		$copy = new \WC_Webhook();
		$copy->set_name( 'IRIOS Media sync service: product.updated' );
		$copy->set_topic( 'product.updated' );
		$copy->save();

		SyncWebhooks::sync( self::URL, self::SECRET, 1 );

		$this->assertCount( 3, \WC_Webhook::$saved ); // otherwise every change would be sent twice
	}

	public function test_the_status_line_says_which_ones_are_not_working(): void {
		$this->assertStringContainsString( 'No webhooks yet', SyncWebhooks::status() );

		SyncWebhooks::sync( self::URL, self::SECRET, 1 );
		$this->assertStringContainsString( 'Webhooks active: product.created, product.updated, product.deleted', SyncWebhooks::status() );

		$this->by_topic()['product.deleted']->status = 'disabled';
		$this->assertStringContainsString( 'product.deleted (disabled)', SyncWebhooks::status() );
	}

	public function test_only_https_urls_are_accepted_or_http_for_a_local_test_host(): void {
		$this->assertTrue( Settings::valid_webhook_url( self::URL ) );
		$this->assertTrue( Settings::valid_webhook_url( 'http://host.docker.internal:8080/v1/webhooks/woocommerce/shop' ) );
		$this->assertTrue( Settings::valid_webhook_url( 'http://sync.test/v1/webhooks/woocommerce/shop' ) );
		$this->assertFalse( Settings::valid_webhook_url( 'http://sync.example.com/v1/webhooks/woocommerce/shop' ) );
		$this->assertFalse( Settings::valid_webhook_url( 'sync.example.com/v1/webhooks' ) );
	}

	public function test_the_settings_screen_refuses_a_wrong_url_or_a_short_secret_and_keeps_the_saved_value(): void {
		$integration           = ( new \ReflectionClass( Integration::class ) )->newInstanceWithoutConstructor();
		$integration->settings = array(
			'sync_webhook_url'    => self::URL,
			'sync_webhook_secret' => self::SECRET,
		);

		$this->assertSame( self::URL, $integration->validate_sync_webhook_url_field( 'sync_webhook_url', 'ftp://nope' ) );
		$this->assertSame( self::SECRET, $integration->validate_sync_webhook_secret_field( 'sync_webhook_secret', 'short' ) );
		$this->assertCount( 2, \WC_Admin_Settings::$errors );
		$this->assertSame( '', $integration->validate_sync_webhook_url_field( 'sync_webhook_url', '  ' ) );
	}
}
