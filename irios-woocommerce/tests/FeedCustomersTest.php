<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Feed;

/**
 * The registered customers the sync service reads: those whose last answer is consent — their own (user meta), with or
 * without an order, or, without one, that of their most recent paid order. The list is asked of the database one page at a
 * time — loading every order of the store to read its customer would run out of memory on a store with a few years of
 * history.
 */
final class FeedCustomersTest extends WordPressTestCase {

	private \WPDB_Fake $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new \WPDB_Fake();
		$GLOBALS['wpdb'] = $this->wpdb;
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false; // store with the older order storage
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function sql(): string {
		return implode( "\n", $this->wpdb->queries );
	}

	public function test_only_the_page_asked_for_is_read_from_the_database(): void {
		$this->wpdb->var = 120;
		$this->wpdb->col = array( '7', '9' );

		$response = Feed::customers( new \WP_REST_Request( array( 'page' => 3, 'per_page' => 2 ) ) );

		$this->assertStringContainsString( 'LIMIT 2 OFFSET 4', $this->sql() );
		$this->assertSame( '120', $response->headers['X-WP-Total'] );
		$this->assertSame( '60', $response->headers['X-WP-TotalPages'] );
	}

	public function test_a_customer_whose_own_last_answer_is_yes_counts_with_or_without_an_order(): void {
		$this->wpdb->var = 0;
		$this->wpdb->col = array();

		Feed::customers( new \WP_REST_Request( array( 'page' => 1, 'per_page' => 50 ) ) );

		$sql = $this->sql();
		$this->assertStringContainsString(
			"SELECT a.user_id AS customer, t.meta_value AS changed FROM wp_usermeta a INNER JOIN wp_users w ON w.ID = a.user_id",
			$sql,
			'read from the customers themselves, not from their orders'
		);
		$this->assertStringContainsString( "WHERE a.meta_key = '_irios_consent' AND a.meta_value = 'yes' UNION ALL", $sql, 'a "no" is never listed' );
	}

	public function test_without_an_answer_of_their_own_their_most_recent_paid_order_counts(): void {
		$this->wpdb->var = 0;
		$this->wpdb->col = array();

		Feed::customers( new \WP_REST_Request( array( 'page' => 1, 'per_page' => 50 ) ) );

		$sql = $this->sql();
		$this->assertStringContainsString( "'wc-processing','wc-completed'", $sql, 'a cancelled order does not make a customer' );
		$this->assertStringContainsString( 'AS UNSIGNED) > 0', $sql, 'a guest has no customer to send' );
		$this->assertStringContainsString( "LEFT JOIN wp_usermeta n ON n.user_id = c.customer AND n.meta_key = '_irios_consent'", $sql );
		$this->assertStringContainsString( "WHERE n.umeta_id IS NULL AND SUBSTRING_INDEX(c.latest, '|', -1) = 'yes'", $sql, 'their own answer first, the order\'s only without one' );
		$this->assertStringContainsString( "MAX(CONCAT(p.post_date_gmt, '|', LPAD(p.ID, 20, '0'), '|'", $sql, 'the most recent order, not any order' );
		$this->assertStringContainsString( 'LEFT JOIN wp_postmeta m', $sql, 'an order without an answer is an answer too: not yes' );
		$this->assertStringNotContainsString( "m.meta_value = 'yes'", $sql, 'an older order with consent does not count' );
	}

	public function test_a_customer_without_consent_is_not_found(): void {
		$this->wpdb->var = null;

		$result = Feed::customer( new \WP_REST_Request( array( 'id' => 7 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( "a.meta_value = 'yes' AND a.user_id = 7 UNION ALL", $this->sql(), 'only their own answer is read' );
		$this->assertStringContainsString( 'AS UNSIGNED) = 7 GROUP BY', $this->sql(), 'only their own orders are read' );
		$this->assertStringContainsString( 'LIMIT 1', $this->sql(), 'one row is enough: the list is not built' );
	}

	public function test_a_customer_who_said_yes_without_ordering_is_found(): void {
		$this->wpdb->var = '7';

		$result = Feed::customer( new \WP_REST_Request( array( 'id' => 7 ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 7, $result->get_data()['id'] );
	}

	public function test_updated_since_keeps_those_whose_answer_or_orders_changed_after_it(): void {
		$this->wpdb->var = 1;
		$this->wpdb->col = array( '7' );

		$response = Feed::customers( new \WP_REST_Request( array( 'page' => 1, 'per_page' => 50, 'updated_since' => '2026-11-20T08:00:00' ) ) );

		$sql = $this->sql();
		$this->assertStringContainsString( "LEFT JOIN wp_usermeta t ON t.user_id = a.user_id AND t.meta_key = '_irios_consent_at'", $sql, 'when their own answer changed' );
		$this->assertStringContainsString( 'MAX(p.post_modified_gmt) AS changed', $sql, 'without one, when their paid orders changed' );
		$this->assertStringContainsString( "WHERE irios_consented.changed > '2026-11-20 08:00:00' ORDER BY customer ASC LIMIT 50 OFFSET 0", $sql );
		$this->assertSame( '1', $response->headers['X-WP-Total'], 'the total counts only the changed ones too' );
		$this->assertCount( 2, array_filter( $this->wpdb->queries, static fn ( $q ) => str_contains( $q, "changed > '2026-11-20 08:00:00'" ) ) );
	}

	public function test_without_updated_since_every_consenting_customer_is_listed(): void {
		$this->wpdb->var = 0;
		$this->wpdb->col = array();

		Feed::customers( new \WP_REST_Request( array( 'page' => 1, 'per_page' => 50 ) ) );

		$this->assertStringNotContainsString( 'irios_consented.changed >', $this->sql() );
	}

	public function test_an_id_that_is_not_a_customer_is_refused_without_touching_the_database(): void {
		$result = Feed::customer( new \WP_REST_Request( array( 'id' => 0 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_with_the_newer_order_storage_its_own_tables_are_read(): void {
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
		$this->wpdb->var = 0;
		$this->wpdb->col = array();

		Feed::customers( new \WP_REST_Request( array( 'page' => 1, 'per_page' => 10 ) ) );

		$sql = $this->sql();
		$this->assertStringContainsString( 'wp_wc_orders', $sql );
		$this->assertStringContainsString( 'MAX(CONCAT(COALESCE(o.date_created_gmt', $sql );
		$this->assertStringContainsString( 'MAX(COALESCE(o.date_updated_gmt, o.date_created_gmt)) AS changed', $sql );
		$this->assertStringNotContainsString( 'wp_posts', $sql );
	}
}
