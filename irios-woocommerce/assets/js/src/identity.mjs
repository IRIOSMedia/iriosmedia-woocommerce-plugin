import { SESSION_KEY, cfg, state } from './config.mjs';

// ---- Identifiers ------------------------------------------------------------------------------------------------

export function uuid() {
	if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
		return window.crypto.randomUUID();
	}
	var bytes = new Uint8Array( 16 );
	window.crypto.getRandomValues( bytes );
	bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
	bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
	var hex = Array.prototype.map.call( bytes, function ( b ) {
		return ( '0' + b.toString( 16 ) ).slice( -2 );
	} ).join( '' );
	return hex.slice( 0, 8 ) + '-' + hex.slice( 8, 12 ) + '-' + hex.slice( 12, 16 ) + '-' + hex.slice( 16, 20 ) + '-' + hex.slice( 20 );
}

export function readCookie( name ) {
	var match = document.cookie.match( new RegExp( '(?:^|; )' + name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '=([^;]*)' ) );
	return match ? decodeURIComponent( match[ 1 ] ) : null;
}

export function sessionId() {
	try {
		var value = window.sessionStorage.getItem( SESSION_KEY );
		if ( ! value ) {
			value = uuid();
			window.sessionStorage.setItem( SESSION_KEY, value );
		}
		return value;
	} catch ( e ) {
		return state.sessionId || uuid(); // sessionStorage blocked: page-scoped session
	}
}

export function fetchContext() {
	// The first answer: the request the <head> already started, if there is one; a failed one (an expired nonce on a
	// cached page…) is asked again, as before
	var early = state.earlyContext;
	state.earlyContext = null;
	if ( early ) {
		return early.then( function ( body ) {
			return body ? fromBody( body ) : fetchContext();
		}, function () {
			return fetchContext();
		} );
	}
	var request = function ( withNonce ) {
		var headers = { Accept: 'application/json' };
		if ( withNonce && cfg.restNonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.restNonce;
		}
		return window.fetch( cfg.contextUrl, { credentials: 'same-origin', cache: 'no-store', headers: headers } );
	};
	// An expired nonce (cached page) returns 403: retry without nonce and treat the visitor as anonymous.
	return request( true )
		.then( function ( r ) {
			return r.status === 403 ? request( false ) : r;
		} )
		.then( function ( r ) {
			return r.ok ? r.json() : {};
		} )
		.then( fromBody )
		.catch( function () {
			return { uids: [], events: [] };
		} );
}

function fromBody( body ) {
	return {
		uids: Array.isArray( body.uids ) ? body.uids : [],
		events: Array.isArray( body.events ) ? body.events : [],
	};
}

/**
 * Consent withdrawn: tells the store, so IRIOS Media erases what it holds about this browser and, if logged in, the
 * customer (Revocations.php). The store takes the browser from the irs1P cookie the request itself carries, never
 * from the body, so this must be sent before that cookie is removed. keepalive: it still arrives if the visitor leaves
 * the page. Resolves when the request is over, whatever the answer.
 */
export function reportRevocation() {
	if ( ! cfg.revocationsUrl || typeof window.fetch !== 'function' ) {
		return Promise.resolve();
	}
	var request = function ( withNonce ) {
		var headers = { Accept: 'application/json' };
		if ( withNonce && cfg.restNonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.restNonce;
		}
		return window.fetch( cfg.revocationsUrl, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			keepalive: true,
			headers: headers,
		} );
	};
	var done = function () {};
	// An expired nonce (cached page) returns 403: retry without it, so at least the browser is reported
	return request( true )
		.then( function ( r ) {
			return r.status === 403 ? request( false ) : r;
		} )
		.then( done, done );
}
