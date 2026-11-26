<?php

namespace Irios\WooCommerce;

/**
 * The product webhooks to the IRIOS Media sync service, created and kept up to date by the plugin from the settings
 * (IRIOS Media sync service: webhook URL and secret), so that the store does not create them by hand.
 *
 * One webhook per topic: product created, updated and deleted ("updated" also carries the stock changes, see Stock).
 * They are named "IRIOS Media sync service: <topic>", which is how the plugin finds its own and never touches the
 * store's other webhooks. Each save of the settings creates the missing ones, updates the URL and the secret of the
 * existing ones and turns back on any that WooCommerce disabled after failed deliveries. An empty URL removes them.
 */
final class SyncWebhooks {

	public const TOPICS      = array( 'product.created', 'product.updated', 'product.deleted' );
	public const NAME_PREFIX = 'IRIOS Media sync service: ';

	/**
	 * Creates, updates or removes the webhooks.
	 *
	 * @param string $url     Delivery URL given by IRIOS Media; empty removes the webhooks.
	 * @param string $secret  Secret given by IRIOS Media (WooCommerce signs each delivery with it).
	 * @param int    $user_id User the deliveries are built as (the administrator saving the settings).
	 */
	public static function sync( string $url, string $secret, int $user_id ): void {
		$existing = self::existing();
		if ( '' === $url ) {
			foreach ( $existing as $webhook ) {
				$webhook->delete( true );
			}
			return;
		}
		foreach ( self::TOPICS as $topic ) {
			$webhook = $existing[ $topic ] ?? new \WC_Webhook();
			$webhook->set_name( self::NAME_PREFIX . $topic );
			$webhook->set_topic( $topic );
			$webhook->set_delivery_url( $url );
			$webhook->set_secret( $secret );
			$webhook->set_api_version( 'wp_api_v3' );
			$webhook->set_status( 'active' );
			if ( ! $webhook->get_user_id() ) {
				$webhook->set_user_id( $user_id );
			}
			$webhook->save();
		}
	}

	/**
	 * The plugin's webhooks, by topic. A second one with the same topic (created by hand with the same name, or left
	 * by an interrupted save) is removed: two would send every change twice.
	 *
	 * @return array<string,\WC_Webhook>
	 */
	public static function existing(): array {
		$found = array();
		foreach ( self::webhook_ids() as $id ) {
			$webhook = wc_get_webhook( (int) $id );
			if ( ! $webhook instanceof \WC_Webhook || 0 !== strpos( (string) $webhook->get_name(), self::NAME_PREFIX ) ) {
				continue;
			}
			$topic = (string) $webhook->get_topic();
			if ( isset( $found[ $topic ] ) || ! in_array( $topic, self::TOPICS, true ) ) {
				$webhook->delete( true );
				continue;
			}
			$found[ $topic ] = $webhook;
		}
		return $found;
	}

	/** One line for the settings page: how many of the plugin's webhooks are active, and which ones are not. */
	public static function status(): string {
		$existing = self::existing();
		if ( array() === $existing ) {
			return 'No webhooks yet: they are created when a webhook URL is saved.';
		}
		$active   = array();
		$inactive = array();
		foreach ( self::TOPICS as $topic ) {
			if ( ! isset( $existing[ $topic ] ) ) {
				$inactive[] = $topic . ' (missing)';
			} elseif ( 'active' === $existing[ $topic ]->get_status() ) {
				$active[] = $topic;
			} else {
				$inactive[] = $topic . ' (' . $existing[ $topic ]->get_status() . ')';
			}
		}
		if ( array() === $inactive ) {
			return 'Webhooks active: ' . implode( ', ', $active ) . '.';
		}
		return 'Webhooks not working: ' . implode( ', ', $inactive ) . '. WooCommerce disables a webhook after 5 failed deliveries; save the settings to turn them back on.';
	}

	/** Removes the plugin's webhooks (uninstall). */
	public static function remove(): void {
		foreach ( self::existing() as $webhook ) {
			$webhook->delete( true );
		}
	}

	/** @return int[] */
	private static function webhook_ids(): array {
		if ( ! class_exists( '\WC_Data_Store' ) ) {
			return array();
		}
		$ids = \WC_Data_Store::load( 'webhook' )->search_webhooks( array( 'limit' => -1 ) );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
