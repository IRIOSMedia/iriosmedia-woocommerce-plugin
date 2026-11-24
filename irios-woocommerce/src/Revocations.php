<?php

namespace Irios\WooCommerce;

/**
 * Consent withdrawals, so IRIOS Media erases what it holds about the shopper.
 *
 * When the shopper withdraws consent, the storefront JS calls `POST /wp-json/irios/v1/revocations` (only from a page of
 * the store, like /context) before it removes the IRIOS cookies. The withdrawal is recorded here for:
 *
 * - the browser: the UUID of the request's own `irs1P` cookie, never anything the request body says;
 * - the logged-in customer, if any: customer ID, hashed account email and hashed billing phone (Identity), the same
 *   identifiers the customers feed sends. Their last answer becomes "no" (Consent::USER_META), so they leave the feed.
 *
 * The sync service reads them from `GET /wp-json/irios/v1/revocations` (Feed) and asks IRIOS Media to erase those
 * identifiers. Emails and phones are never stored in clear.
 *
 * Stored in a table of its own (`{prefix}irios_revocations`), created on activation and upgraded on the first request
 * after an update (dbDelta), and dropped on uninstall. A withdrawal repeated without a new consent in between is not
 * recorded twice.
 *
 * The table is only a mailbox for the sync service, which reads it every few minutes: rows older than 90 days are
 * deleted once a day (WP-Cron, scheduled on activation and on boot, unscheduled on deactivation and uninstall). Telling
 * a repeat apart does not need the old rows: for a customer it is their last answer plus the fingerprint of their last
 * recorded withdrawal (user meta); for a browser alone, its cookie is removed on withdrawal, so the storefront has
 * nothing to report again until a new consent creates a new one.
 */
final class Revocations {

	public const TABLE             = 'irios_revocations';
	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'irios_wc_db_version';

	/** Days a withdrawal is kept for the sync service to read it. */
	public const RETENTION_DAYS = 90;
	/** Daily WP-Cron event that deletes the older ones. */
	public const PURGE_HOOK = 'irios_wc_purge_revocations';
	/** Customer's last recorded withdrawal (user meta): a hash of its identifiers, so a repeat is told apart after a purge. */
	public const LAST_META = '_irios_revoked';

	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Creates the table or brings it up to date (dbDelta only adds what is missing). */
	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		// dbDelta's format: two spaces after PRIMARY KEY, one column or key per line
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  revoked_at datetime NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  crm varchar(20) DEFAULT NULL,
  email_sha256 char(64) DEFAULT NULL,
  phone_sha256 char(64) DEFAULT NULL,
  browser_id char(36) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY revoked_at (revoked_at,id),
  KEY user_id (user_id),
  KEY browser_id (browser_id)
) {$charset};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** An update replaces the files without running the activation: the table is created or upgraded here instead. */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	/** Activation: the table and the daily purge. */
	public static function activate(): void {
		self::install();
		self::schedule();
	}

	/** Deactivation: no purge left scheduled for a plugin that is not running (activation schedules it again). */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}

	/** On boot: the purge runs on its hook and is scheduled if it is not (an update does not run the activation). */
	public static function register(): void {
		add_action( self::PURGE_HOOK, array( self::class, 'purge' ) );
		self::schedule();
	}

	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/** Deletes the withdrawals older than RETENTION_DAYS: by then the sync service has long read them. */
	public static function purge(): void {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE revoked_at < %s', $before ) );
	}

	/** `POST /wp-json/irios/v1/revocations`: the visitor making the request withdrew consent. Takes no parameters. */
	public static function revoke(): \WP_REST_Response {
		$cookie   = isset( $_COOKIE[ Identity::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Identity::COOKIE ] ) ) : null;
		$user_id  = is_user_logged_in() ? (int) get_current_user_id() : 0;
		$response = new \WP_REST_Response( array( 'recorded' => self::record( $user_id, Identity::browser_uid( $cookie ) ) ) );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}

	/**
	 * Records a withdrawal for a customer (0: none) and a browser (null: none). False when there is nobody to erase or
	 * it repeats the last one with no consent given in between: for a customer, their last answer is still "no"; for
	 * a browser alone, the same browser ID was already recorded (on withdrawal its cookie is removed, and a new
	 * consent gets a new one). A customer's last withdrawal is also remembered on the customer (LAST_META), so this
	 * still holds once its row has been purged.
	 */
	public static function record( int $user_id, ?string $browser_id ): bool {
		global $wpdb;
		if ( null !== $browser_id && 1 !== preg_match( self::UUID, $browser_id ) ) {
			$browser_id = null;
		}
		if ( $user_id <= 0 && null === $browser_id ) {
			return false;
		}
		$row = array_merge(
			array( 'user_id' => $user_id > 0 ? $user_id : null ),
			$user_id > 0 ? self::customer_hashes( $user_id ) : array(
				'crm'          => null,
				'email_sha256' => null,
				'phone_sha256' => null,
			),
			array( 'browser_id' => $browser_id )
		);
		$fingerprint = hash( 'sha256', (string) wp_json_encode( $row ) );
		if ( $user_id > 0 ) {
			$repeated = 'no' === Consent::last_answer( $user_id )
				&& ( get_user_meta( $user_id, self::LAST_META, true ) === $fingerprint || self::recorded( $row ) );
		} else {
			$repeated = self::recorded( $row );
		}
		Consent::remember( $user_id, false );
		if ( $repeated ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = false !== $wpdb->insert(
			self::table(),
			array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ) + $row,
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( $inserted && $user_id > 0 ) {
			update_user_meta( $user_id, self::LAST_META, $fingerprint );
		}
		return $inserted;
	}

	/**
	 * Hashed identifiers of a registered customer, as the customers feed sends them: customer ID, account email (or
	 * billing email) and billing phone (E.164 with their country, else the store's).
	 *
	 * @return array{crm:string, email_sha256:?string, phone_sha256:?string}
	 */
	public static function customer_hashes( int $user_id ): array {
		$customer = class_exists( \WC_Customer::class ) ? new \WC_Customer( $user_id ) : null;
		$email    = $customer ? $customer->get_email() : '';
		if ( ! $email && $customer ) {
			$email = $customer->get_billing_email();
		}
		if ( ! $email ) {
			$user  = get_userdata( $user_id );
			$email = $user ? $user->user_email : null;
		}
		$store = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_base_country() : null;
		return array(
			'crm'          => (string) $user_id,
			'email_sha256' => Identity::email_hash( $email ? (string) $email : null ),
			'phone_sha256' => $customer ? Identity::phone_hash( $customer->get_billing_phone(), $customer->get_billing_country(), $store ) : null,
		);
	}

	/** Is this exact withdrawal (same customer, identifiers and browser) already recorded? */
	private static function recorded( array $row ): bool {
		global $wpdb;
		$where = array();
		$args  = array();
		foreach ( $row as $column => $value ) {
			if ( null === $value ) {
				$where[] = "{$column} IS NULL";
			} else {
				$where[] = is_int( $value ) ? "{$column} = %d" : "{$column} = %s";
				$args[]  = $value;
			}
		}
		$sql = 'SELECT id FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 1';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return null !== $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * A page of withdrawals for the sync service, oldest first (`revoked_at`, then `id`), after a date or an id.
	 *
	 * @return array{0: array<int,array<string,mixed>>, 1: int} The page and how many there are in all.
	 */
	public static function page( int $page, int $per_page, ?int $revoked_after, int $after_id ): array {
		global $wpdb;
		$where = array( '1 = 1' );
		$args  = array();
		if ( null !== $revoked_after ) {
			$where[] = 'revoked_at > %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', $revoked_after );
		}
		if ( $after_id > 0 ) {
			$where[] = 'id > %d';
			$args[]  = $after_id;
		}
		$from  = ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		$count = 'SELECT COUNT(*)' . $from;
		$list  = 'SELECT id, revoked_at, crm, email_sha256, phone_sha256, browser_id' . $from . ' ORDER BY revoked_at ASC, id ASC LIMIT %d OFFSET %d';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count, ...$args ) : $count );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list, ...array_merge( $args, array( $per_page, max( 0, ( $page - 1 ) * $per_page ) ) ) ), ARRAY_A );
		// phpcs:enable
		return array( array_map( array( self::class, 'item' ), is_array( $rows ) ? $rows : array() ), $total );
	}

	/**
	 * One withdrawal as the feed sends it: `{id, revoked_at, uids}`, with only the identifiers it has in `uids` (crm,
	 * email_sha256, phone_sha256, browser_id).
	 */
	public static function item( array $row ): array {
		$time = strtotime( (string) $row['revoked_at'] . ' UTC' );
		$uids = array();
		foreach ( array( 'crm', 'email_sha256', 'phone_sha256', 'browser_id' ) as $key ) {
			if ( isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
				$uids[ $key ] = (string) $row[ $key ];
			}
		}
		return array(
			'id'         => (int) $row['id'],
			'revoked_at' => false === $time ? null : gmdate( 'Y-m-d\TH:i:s', $time ),
			'uids'       => (object) $uids,
		);
	}

	/** Uninstall: the daily purge, the table and its version. */
	public static function uninstall(): void {
		global $wpdb;
		wp_clear_scheduled_hook( self::PURGE_HOOK );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() );
		delete_option( self::DB_VERSION_OPTION );
	}
}
