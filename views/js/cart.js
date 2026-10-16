/**
 * QChat "Add to Cart" -> PrestaShop's real cart (Quissly for PrestaShop).
 *
 * Quissly's chat widget has no PrestaShop cart of its own: it counts quantities inside the
 * widget ("1 in Cart") and announces every change as a `quissly:generic-cart-sync` window event,
 * detail = { productId: quantity }. This script applies each change to the shopper's cart through
 * the module's cart endpoint (controllers/front/cart.php, PrestaShop's own Cart), then tells the
 * theme (`prestashop.emit('updateCart')`) so the mini-cart refreshes - and, for an add, shows
 * PrestaShop's usual "added to cart" window.
 *
 * Changes are applied as differences from the last event, one at a time and in order. The widget
 * names products by Quissly's id (a UUID), translated through the endpoint's op=resolve; an id
 * that cannot be translated is left alone (never guessed). A plain number is a PrestaShop
 * id_product; "1-2" (what op=resolve answers for a product with combinations) is product 1's
 * combination 2, the one the shopper picked in the chat. Config: the [data-quissly-cart] element's data-quissly-config JSON (src/Storefront.php).
 */
( function () {
	'use strict';

	var host = document.querySelector( '[data-quissly-cart]' );
	var CFG = null;
	try {
		CFG = host ? JSON.parse( host.getAttribute( 'data-quissly-config' ) || 'null' ) : null;
	} catch ( e ) {
		CFG = null;
	}
	if ( ! CFG ) {
		return;
	}

	var last = {};
	var queue = Promise.resolve();

	/** Change the cart by `delta` of a product, then let the theme refresh its mini-cart. */
	function change( productId, delta ) {
		return fetch( CFG.addUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams( { security_hash: CFG.securityHash || '', product_id: productId, delta: delta } ).toString()
		} ).then( function ( r ) { return r.json(); } ).then( function ( answer ) {
			if ( window.prestashop && typeof window.prestashop.emit === 'function' ) {
				window.prestashop.emit( 'updateCart', {
					reason: {
						idProduct: answer && answer.id_product,
						idProductAttribute: answer && answer.id_product_attribute,
						linkAction: delta > 0 ? 'add-to-cart' : 'delete-from-cart'
					},
					resp: { hasError: ! ( answer && answer.ok ), errors: [] }
				} );
			}
		}, function () {} );
	}

	var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
	var resolved = {};

	/** Translate Quissly ids to PrestaShop product ids (cached for the page). */
	function resolve( ids ) {
		var unknown = ids.filter( function ( id ) { return UUID.test( id ) && ! ( id.toLowerCase() in resolved ); } );
		if ( ! unknown.length || ! CFG.resolveUrl ) {
			return Promise.resolve();
		}
		var url = new URL( CFG.resolveUrl, window.location.href );
		url.searchParams.set( 'ids', unknown.join( ',' ) );
		return fetch( url.toString(), { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( map ) {
				unknown.forEach( function ( id ) { resolved[ id.toLowerCase() ] = ( map && map[ id.toLowerCase() ] ) || null; } );
			}, function () {} );
	}

	/** The PrestaShop id_product for a widget id, or null. */
	function productId( id ) {
		if ( /^[0-9]+$/.test( id ) ) {
			return id;
		}
		var found = resolved[ String( id ).toLowerCase() ];
		return found ? String( found ) : null;
	}

	window.addEventListener( 'quissly:generic-cart-sync', function ( event ) {
		var now = ( event && event.detail ) || {};
		var deltas = {};
		Object.keys( last ).concat( Object.keys( now ) ).forEach( function ( id ) {
			var delta = ( parseInt( now[ id ], 10 ) || 0 ) - ( parseInt( last[ id ], 10 ) || 0 );
			if ( delta !== 0 ) {
				deltas[ id ] = delta;
			}
		} );
		last = {};
		Object.keys( now ).forEach( function ( id ) { last[ id ] = parseInt( now[ id ], 10 ) || 0; } );

		// One change at a time, in order: the cart lives on the shopper's cookie.
		queue = queue.then( function () { return resolve( Object.keys( deltas ) ); } ).then( function () {
			return Object.keys( deltas ).reduce( function ( chain, id ) {
				var pid = productId( id );
				if ( ! pid ) {
					if ( window.console ) { console.warn( '[quissly] chat product ' + id + ' is not known to this store yet (sync it again from the Quissly Dashboard)' ); }
					return chain;
				}
				return chain.then( function () { return change( pid, deltas[ id ] ); } );
			}, Promise.resolve() );
		} );
	} );
} )();
