<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Consent;
use Irios\WooCommerce\Feed;
use Irios\WooCommerce\Identity;
use Irios\WooCommerce\Revocations;

/**
 * Consent withdrawals: recorded for the browser of the request's own cookie and, if logged in, for the customer with
 * hashed identifiers, so the sync service can ask IRIOS Media to erase them; then read by the sync service from the
 * feed, oldest first.
 */
final class RevocationsTest extends WordPressTestCase {

	private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private \WPDB_Fake $wpdb;
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new \WPDB_Fake();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = array();
		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** @return array<int,array> rows written to the withdrawals table */
	private function recorded(): array {
		return array_column( $this->wpdb->inserted, 'data' );
	}

	public function test_a_withdrawal_in_an_anonymous_browser_is_recorded_for_its_cookie(): void {
		$_COOKIE[ Identity::COOKIE ] = 'fc:' . strtoupper( self::UUID );

		$response = Revocations::revoke();

		$this->assertSame( array( 'recorded' => true ), $response->get_data() );
		$this->assertSame( 'no-store, private, max-age=0', $response->headers['Cache-Control'] );
		$this->assertSame( 'wp_irios_revocations', $this->wpdb->inserted[0]['table'] );
		$row = $this->recorded()[0];
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['revoked_at'] );
		unset( $row['revoked_at'] );
		$this->assertSame(
			array( 'user_id' => null, 'crm' => null, 'email_sha256' => null, 'phone_sha256' => null, 'browser_id' => self::UUID ),
			$row
		);
	}

	public function test_a_logged_in_customer_is_recorded_with_hashed_identifiers_and_leaves_the_customers(): void {
		$this->user_id                            = 7;
		$this->user_meta[7][ Consent::USER_META ] = 'yes';
		$_COOKIE[ Identity::COOKIE ]              = 'fc:' . self::UUID;

		Revocations::revoke();

		$row = $this->recorded()[0];
		$this->assertSame( 7, $row['user_id'] );
		$this->assertSame( '7', $row['crm'] );
		$this->assertSame( hash( 'sha256', 'customer7@example.com' ), $row['email_sha256'] );
		$this->assertSame( self::UUID, $row['browser_id'] );
		$this->assertStringNotContainsString( '@', (string) json_encode( $this->wpdb->inserted ), 'no email in clear' );
		$this->assertSame( 'no', $this->user_meta[7][ Consent::USER_META ] );
	}

	public function test_the_browser_id_comes_only_from_the_cookie_and_a_tampered_one_is_ignored(): void {
		$_COOKIE[ Identity::COOKIE ] = 'fc:not-a-uuid';

		$response = Revocations::revoke();

		$this->assertSame( array( 'recorded' => false ), $response->get_data(), 'nobody to erase' );
		$this->assertSame( array(), $this->wpdb->inserted );
		$this->assertFalse( Revocations::record( 0, 'anything' ) );
	}

	public function test_a_repeated_withdrawal_without_a_new_consent_is_recorded_once(): void {
		$this->user_meta[7][ Consent::USER_META ] = 'yes';
		$this->assertTrue( Revocations::record( 7, self::UUID ) );

		$this->assertFalse( Revocations::record( 7, self::UUID ), 'the customer remembers their last withdrawal' );
		$this->assertCount( 1, $this->wpdb->inserted );

		unset( $this->user_meta[7][ Revocations::LAST_META ] ); // without it, the table tells
		$this->wpdb->var = '1'; // the same withdrawal is already in the table
		$this->assertFalse( Revocations::record( 7, self::UUID ) );
		$this->assertCount( 1, $this->wpdb->inserted );
		$this->assertStringContainsString( "user_id = 7 AND crm = '7'", end( $this->wpdb->queries ) );
		$this->assertStringContainsString( "browser_id = '" . self::UUID . "'", end( $this->wpdb->queries ) );
	}

	public function test_a_repeated_withdrawal_is_still_recorded_once_after_its_row_was_purged(): void {
		$this->user_meta[7][ Consent::USER_META ] = 'yes';
		$this->assertTrue( Revocations::record( 7, self::UUID ) );

		$this->wpdb->var = null; // older than 90 days: no longer in the table
		$this->assertFalse( Revocations::record( 7, self::UUID ), 'the customer remembers their last withdrawal' );
		$this->assertCount( 1, $this->wpdb->inserted );
	}

	public function test_after_a_purge_a_withdrawal_with_other_identifiers_is_recorded(): void {
		$this->user_meta[7][ Consent::USER_META ] = 'yes';
		Revocations::record( 7, self::UUID );

		$this->wpdb->var = null;
		$this->assertTrue( Revocations::record( 7, '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed' ), 'another browser to erase' );
		$this->assertCount( 2, $this->wpdb->inserted );
	}

	public function test_after_a_purge_a_new_consent_then_a_withdrawal_is_recorded_again(): void {
		$this->user_meta[7][ Consent::USER_META ] = 'yes';
		Revocations::record( 7, self::UUID );
		Consent::remember( 7, true ); // seen with consent again

		$this->wpdb->var = null;
		$this->assertTrue( Revocations::record( 7, self::UUID ) );
		$this->assertCount( 2, $this->wpdb->inserted );
	}

	public function test_withdrawals_older_than_90_days_are_deleted(): void {
		Revocations::purge();

		$sql = end( $this->wpdb->queries );
		$this->assertStringStartsWith( 'DELETE FROM wp_irios_revocations WHERE revoked_at < ', $sql );
		preg_match( "/'([^']+)'/", $sql, $match );
		$this->assertEqualsWithDelta( time() - 90 * 86400, strtotime( $match[1] . ' UTC' ), 5 );
	}

	public function test_the_daily_purge_is_scheduled_once_and_runs_on_its_hook(): void {
		$scheduled = array();
		Functions\expect( 'add_action' )->once()->with( Revocations::PURGE_HOOK, array( Revocations::class, 'purge' ) );
		Functions\when( 'wp_next_scheduled' )->alias( fn ( $hook ) => isset( $scheduled[ $hook ] ) ? $scheduled[ $hook ] : false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $time, $recurrence, $hook ) use ( &$scheduled ) {
				$scheduled[ $hook ] = $recurrence;
				return true;
			}
		);

		Revocations::register();
		Revocations::schedule();

		$this->assertSame( array( Revocations::PURGE_HOOK => 'daily' ), $scheduled );
	}

	public function test_activation_creates_the_table_and_schedules_the_purge_and_deactivation_unschedules_it(): void {
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', Revocations::PURGE_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Revocations::PURGE_HOOK );

		Revocations::activate();
		Revocations::deactivate();

		$this->assertSame( Revocations::DB_VERSION, $this->options[ Revocations::DB_VERSION_OPTION ] );
	}

	public function test_after_a_new_consent_a_withdrawal_is_recorded_again(): void {
		$this->user_meta[7][ Consent::USER_META ] = 'yes'; // seen with consent since the last one
		$this->wpdb->var                          = '1';

		$this->assertTrue( Revocations::record( 7, self::UUID ) );
		$this->assertCount( 1, $this->wpdb->inserted );
	}

	public function test_an_anonymous_browser_already_recorded_is_not_recorded_again(): void {
		$this->wpdb->var = '1';

		$this->assertFalse( Revocations::record( 0, self::UUID ) );
		$this->assertStringContainsString( 'user_id IS NULL AND crm IS NULL', end( $this->wpdb->queries ) );
	}

	public function test_the_table_is_created_on_activation_and_upgraded_after_an_update(): void {
		$sql = array();
		Functions\when( 'dbDelta' )->alias(
			function ( $query ) use ( &$sql ) {
				$sql[] = $query;
				return array();
			}
		);

		Revocations::maybe_upgrade();
		Revocations::maybe_upgrade();

		$this->assertCount( 1, $sql, 'once per table version' );
		$this->assertStringContainsString( 'CREATE TABLE wp_irios_revocations', $sql[0] );
		$this->assertStringContainsString( 'email_sha256 char(64)', $sql[0] );
		$this->assertStringNotContainsString( 'email varchar', $sql[0] );
		$this->assertSame( Revocations::DB_VERSION, $this->options[ Revocations::DB_VERSION_OPTION ] );
	}

	public function test_the_feed_lists_them_oldest_first_with_only_the_identifiers_each_has(): void {
		$this->wpdb->var     = 3;
		$this->wpdb->results = array(
			array( 'id' => '4', 'revoked_at' => '2026-11-19 10:00:00', 'crm' => null, 'email_sha256' => null, 'phone_sha256' => null, 'browser_id' => self::UUID ),
			array( 'id' => '5', 'revoked_at' => '2026-11-19 10:05:00', 'crm' => '7', 'email_sha256' => str_repeat( 'a', 64 ), 'phone_sha256' => null, 'browser_id' => null ),
		);

		$response = Feed::revocations(
			new \WP_REST_Request( array( 'page' => 1, 'per_page' => 2, 'revoked_after' => '2026-11-18T00:00:00', 'after_id' => 3 ) )
		);

		$data = json_decode( (string) json_encode( $response->get_data() ), true );
		$this->assertSame(
			array(
				array( 'id' => 4, 'revoked_at' => '2026-11-19T10:00:00', 'uids' => array( 'browser_id' => self::UUID ) ),
				array( 'id' => 5, 'revoked_at' => '2026-11-19T10:05:00', 'uids' => array( 'crm' => '7', 'email_sha256' => str_repeat( 'a', 64 ) ) ),
			),
			$data
		);
		$this->assertSame( '3', $response->headers['X-WP-Total'] );
		$this->assertSame( '2', $response->headers['X-WP-TotalPages'] );
		$sql = end( $this->wpdb->queries );
		$this->assertStringContainsString( "revoked_at > '2026-11-18 00:00:00' AND id > 3", $sql );
		$this->assertStringContainsString( 'ORDER BY revoked_at ASC, id ASC LIMIT 2 OFFSET 0', $sql );
	}

	public function test_uninstall_drops_the_table(): void {
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Revocations::PURGE_HOOK );

		Revocations::uninstall();

		$this->assertSame( array( 'DROP TABLE IF EXISTS wp_irios_revocations' ), $this->wpdb->queries );
	}
}

