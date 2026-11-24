<?php

namespace Irios\WooCommerce;

/**
 * Server-side visitor consent. In WP Consent API mode, without the API installed or without a cookie banner that uses
 * it there is no consent: nothing is tracked by default.
 *
 * "Asesor de Cookies RGPD" mode: the banner stores the choice in the `cdp_cookie_consent` cookie as JSON
 * (`{"necessary":true,"analytics":false,"marketing":true,"personalization":false,…}`); only `true` in the configured
 * category counts as consent.
 */
final class Consent {

	public const ASESOR_COOKIE = 'cdp_cookie_consent';

	/**
	 * The registered customer's last answer (user meta, yes/no): what decides whether they are in the customers feed.
	 * Set when a logged-in customer is seen with consent, when they withdraw it and at checkout with the order's answer.
	 * The same key as the order's own answer (Orders::CONSENT_META), each on its own object.
	 */
	public const USER_META = '_irios_consent';

	/**
	 * When the customer's last answer last changed (user meta, GMT `Y-m-d H:i:s`): the customers feed's `updated_since`
	 * filters on it, so a customer who says yes (with or without an order) shows up in the next incremental read.
	 */
	public const USER_META_AT = '_irios_consent_at';

	/** Cookie category the visitor must accept: Irios does advertising and audiences. It exists under this name in
	 *  the WP Consent API and in Asesor de Cookies RGPD. */
	public const CATEGORY = 'marketing';

	/** "Asesor de Cookies RGPD" banner choice for a category. No cookie or unreadable: no consent. */
	public static function asesor_allows( ?string $raw, string $category ): bool {
		if ( null === $raw || '' === $raw ) {
			return false;
		}
		$consent = json_decode( $raw, true );
		if ( ! is_array( $consent ) ) {
			$consent = json_decode( rawurldecode( $raw ), true );
		}
		return is_array( $consent ) && true === ( $consent[ $category ] ?? null );
	}

	public static function api_available(): bool {
		return function_exists( 'wp_has_consent' );
	}

	/** A compatible cookie banner has registered its consent type (optin, optout…). */
	public static function banner_registered(): bool {
		return function_exists( 'wp_get_consent_type' ) && '' !== (string) wp_get_consent_type();
	}

	/**
	 * With no banner registered, the WP Consent API treats everything as consented ("no consent management"): not here.
	 * There is consent only if a banner manages it and grants it.
	 */
	public static function granted( Settings $settings ): bool {
		if ( Settings::CONSENT_ALWAYS === $settings->consent_mode() ) {
			return true;
		}
		if ( Settings::CONSENT_ASESOR_COOKIES === $settings->consent_mode() ) {
			$raw = isset( $_COOKIE[ self::ASESOR_COOKIE ] ) ? wp_unslash( (string) $_COOKIE[ self::ASESOR_COOKIE ] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated when decoded
			return self::asesor_allows( $raw, self::CATEGORY );
		}
		return self::api_available() && self::banner_registered() && (bool) wp_has_consent( self::CATEGORY );
	}

	/**
	 * Remembers a registered customer's last answer. Written only when it changes: a logged-in customer with consent
	 * is seen on every page, and the user meta is already in memory. Each change also stamps when it happened
	 * (USER_META_AT), which is what the customers feed's incremental reads look at.
	 */
	public static function remember( int $user_id, bool $granted ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$answer = $granted ? 'yes' : 'no';
		if ( get_user_meta( $user_id, self::USER_META, true ) !== $answer ) {
			update_user_meta( $user_id, self::USER_META, $answer );
			update_user_meta( $user_id, self::USER_META_AT, gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	/** The registered customer's last answer: 'yes', 'no', or '' when none was recorded yet. */
	public static function last_answer( int $user_id ): string {
		$answer = $user_id > 0 ? (string) get_user_meta( $user_id, self::USER_META, true ) : '';
		return in_array( $answer, array( 'yes', 'no' ), true ) ? $answer : '';
	}
}
