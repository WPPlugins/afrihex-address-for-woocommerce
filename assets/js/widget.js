/**
 * AfriHex delivery-location field. Plain JavaScript, no build step.
 *
 * The lookup logic is a port of sdk/react/src/api.ts (the <AddressInput />
 * component): GhanaPostGPS codes go to /v2/lookup, Ghanaian directions
 * ("opposite Goil, Madina") go through /v2/address/parse + /v2/landmarks/geocode,
 * everything else to /v2/search/autocomplete, and the chosen point is completed
 * with /v2/reverse?descriptors=true. Only the five lookups a publishable key may call.
 *
 * window.AfriHexAddressWidget.mount( element, config, onChange ) → { destroy, value }
 * onChange receives the location object, or null when the field is empty.
 */
( function ( window, document ) {
	'use strict';

	// ---- API (port of sdk/react/src/api.ts) --------------------------------

	function createClient( publishableKey, baseURL ) {
		var base = ( baseURL || 'https://api.afrihex.com' ).replace( /\/$/, '' );

		function get( path, params, signal ) {
			var qs = [];
			Object.keys( params ).forEach( function ( k ) {
				var v = params[ k ];
				if ( v !== undefined && v !== null && v !== '' ) {
					qs.push( encodeURIComponent( k ) + '=' + encodeURIComponent( String( v ) ) );
				}
			} );
			return fetch( base + path + '?' + qs.join( '&' ), { headers: { 'X-API-Key': publishableKey }, signal: signal } )
				.then( function ( res ) {
					return res.json().catch( function () { return null; } ).then( function ( body ) {
						if ( ! res.ok ) {
							var code = ( body && body.error && body.error.code ) || 'HTTP_' + res.status;
							var msg = ( body && body.error && body.error.message ) || 'HTTP ' + res.status;
							if ( code === 'ORIGIN_NOT_ALLOWED' || code === 'INVALID_API_KEY' || code === 'PUBLISHABLE_KEY_SCOPE' ) {
								window.console && console.warn( '[AfriHex Address] ' + msg + ' (maps.afrihex.com/dashboard → Publishable keys)' );
							}
							var err = new Error( msg );
							err.code = code;
							err.status = res.status;
							throw err;
						}
						return body && Object.prototype.hasOwnProperty.call( body, 'data' ) ? body.data : body;
					} );
				} );
		}

		return {
			autocomplete: function ( q, limit, signal ) { return get( '/v2/search/autocomplete', { q: q, limit: limit }, signal ); },
			lookup: function ( code, signal ) { return get( '/v2/lookup', { address: code }, signal ); },
			reverse: function ( lat, lng, signal ) { return get( '/v2/reverse', { lat: lat, lng: lng, descriptors: true }, signal ); },
			parse: function ( q, signal ) { return get( '/v2/address/parse', { q: q }, signal ); },
			landmarks: function ( q, anchor, signal ) { return get( '/v2/landmarks/geocode', { q: q, anchor: anchor, limit: 5 }, signal ); },
		};
	}

	function formatGPS( code ) {
		if ( ! code ) { return undefined; }
		var c = String( code ).toUpperCase().replace( /[^A-Z0-9]/g, '' );
		var m = /^([A-Z]{2})(\d{3,4})(\d{4})$/.exec( c );
		return m ? m[ 1 ] + '-' + m[ 2 ] + '-' + m[ 3 ] : String( code ).toUpperCase();
	}

	function isGPSCode( q ) {
		return /^[a-z]{2}\s*-?\s*\d{3,4}\s*-?\s*\d{4}$/i.test( q.trim() );
	}

	function hasDirections( q ) {
		return /\b(opposite|opp\.?|adjacent|adj\.?|behind|beside|near|next to|close to|in front of|infront of|after|before|off|around)\b/i.test( q );
	}

	function place( r ) {
		return {
			gps_code: formatGPS( r.gps_name || r.address ),
			street: r.street || undefined,
			area: r.area || undefined,
			district: r.district || undefined,
			region: r.region || undefined,
		};
	}

	var GENERIC = {};
	( 'filling fuel station stations petrol gas the a of and junction bus stop church school market hospital clinic mall shop store ' +
		'house office ltd limited company ghana branch road street avenue area near opposite behind adjacent' ).split( ' ' ).forEach( function ( w ) { GENERIC[ w ] = true; } );

	function distinctiveWords( name ) {
		return String( name ).toLowerCase().split( /[^a-z0-9]+/ ).filter( function ( w ) { return w.length > 1 && ! GENERIC[ w ]; } );
	}

	function assign( a, b ) {
		var o = {};
		[ a, b ].forEach( function ( s ) { Object.keys( s || {} ).forEach( function ( k ) { if ( s[ k ] !== undefined ) { o[ k ] = s[ k ]; } } ); } );
		return o;
	}

	function suggest( client, q, limit, signal ) {
		var input = q.trim();
		if ( isGPSCode( input ) ) {
			return client.lookup( formatGPS( input ), signal ).then( function ( r ) {
				var p = place( r );
				var line = [ p.street, p.area ].filter( Boolean ).join( ', ' );
				return [ {
					id: 'gps:' + p.gps_code,
					title: p.gps_code || input.toUpperCase(),
					subtitle: [ line, p.region ].filter( Boolean ).join( ' · ' ),
					resolved: true,
					address: assign( p, { label: [ p.gps_code, line ].filter( Boolean ).join( ', ' ), lat: r.center_latitude, lng: r.center_longitude, source: 'ghanapostgps', input: input } ),
				} ];
			} );
		}

		var out = [];
		var searchFor = input;
		var landmarks = Promise.resolve();
		if ( hasDirections( input ) ) {
			landmarks = client.parse( input, signal ).then( function ( parsed ) {
				var anchor = parsed && parsed.parsed && parsed.parsed.anchors && parsed.parsed.anchors[ 0 ];
				var around = parsed && parsed.parsed && parsed.parsed.geocode_query;
				if ( around ) { searchFor = around; }
				if ( ! anchor || ! anchor.name || ! around ) { return; }
				return client.landmarks( anchor.name, around, signal ).then( function ( lm ) {
					var from = lm.anchor && lm.anchor.name;
					var want = distinctiveWords( anchor.name );
					var all = lm.matches || [];
					var named = want.length ? all.filter( function ( m ) { return distinctiveWords( m.name ).some( function ( w ) { return want.indexOf( w ) >= 0; } ); } ) : [];
					( named.length ? named : all ).forEach( function ( m ) {
						out.push( {
							id: 'lm:' + m.id,
							title: m.name,
							subtitle: [ m.distance_m != null && from ? Math.round( m.distance_m ) + ' m from ' + from : undefined, m.street ].filter( Boolean ).join( ' · ' ),
							address: { label: m.name, lat: m.centroid.lat, lng: m.centroid.lng, street: m.street, source: 'landmark', input: input },
						} );
					} );
				} );
			} ).catch( function ( e ) {
				if ( e && e.name === 'AbortError' ) { throw e; }
				// Fall back to plain search.
			} );
		}

		return landmarks.then( function () {
			if ( out.length >= limit ) { return out.slice( 0, limit ); }
			return client.autocomplete( searchFor, limit, signal ).then( function ( ac ) {
				( ( ac && ac.results ) || [] ).forEach( function ( r ) {
					var parts = ( r.display_name || r.name ).split( ', ' );
					out.push( {
						id: 's:' + r.latitude + ',' + r.longitude,
						title: r.name || parts[ 0 ],
						subtitle: parts.slice( 1 ).join( ', ' ) || undefined,
						address: { label: r.display_name || r.name, lat: r.latitude, lng: r.longitude, source: 'search', input: input },
					} );
				} );
				return out.slice( 0, limit );
			} );
		} );
	}

	/** Adds the GhanaPostGPS code, area and rider directions for the chosen point. */
	function complete( client, a, signal, overwrite ) {
		return client.reverse( a.lat, a.lng, signal ).then( function ( r ) {
			var p = place( r );
			var keep = overwrite ? {} : a;
			return assign( a, {
				gps_code: keep.gps_code || p.gps_code,
				street: keep.street || p.street,
				area: keep.area || p.area,
				district: keep.district || p.district,
				region: keep.region || p.region,
				directions: ( r.descriptor && r.descriptor.text ) || a.directions,
			} );
		} ).catch( function ( e ) {
			if ( e && e.name === 'AbortError' ) { throw e; }
			return a; // The point itself is still good.
		} );
	}

	// ---- UI -------------------------------------------------------------------

	var uid = 0;

	function h( tag, attrs, text ) {
		var el = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'className' ) { el.className = attrs[ k ]; } else { el.setAttribute( k, attrs[ k ] ); }
		} );
		if ( text !== undefined ) { el.textContent = text; }
		return el;
	}

	function mount( root, cfg, onChange ) {
		var L = cfg.labels || {};
		var client = createClient( cfg.publishableKey, cfg.baseURL );
		var id = 'afx-wc-' + ( ++uid );
		var limit = 6;
		var timer = null;
		var ctrl = null;
		var items = [];
		var active = -1;
		var chosen = null;
		var lookupsFailing = false;
		var map = null;
		var marker = null;

		root.classList.add( 'afx-wc' );
		root.innerHTML = '';

		var label = h( 'label', { for: id, className: 'afx-wc-label' }, L.label );
		if ( cfg.required ) {
			var req = h( 'abbr', { className: 'required', title: L.required || 'required' }, '*' );
			label.appendChild( document.createTextNode( ' ' ) );
			label.appendChild( req );
		}
		var field = h( 'div', { className: 'afx-wc-field' } );
		var input = h( 'input', {
			id: id, type: 'text', className: 'input-text afx-wc-input', role: 'combobox', autocomplete: 'off', spellcheck: 'false',
			'aria-expanded': 'false', 'aria-controls': id + '-list', 'aria-autocomplete': 'list', 'aria-describedby': id + '-help',
			placeholder: L.placeholder || '',
		} );
		var list = h( 'ul', { id: id + '-list', role: 'listbox', className: 'afx-wc-list', hidden: '' } );
		field.appendChild( input );
		field.appendChild( list );

		var help = h( 'p', { id: id + '-help', className: 'afx-wc-help' }, L.help );
		var locate = h( 'button', { type: 'button', className: 'afx-wc-locate' }, L.locate );
		var notice = h( 'p', { className: 'afx-wc-notice', 'aria-live': 'polite' } );
		var summary = h( 'div', { className: 'afx-wc-summary', hidden: '' } );
		var sumLine = h( 'p', { className: 'afx-wc-sum-line' } );
		var sumDir = h( 'p', { className: 'afx-wc-sum-dir' } );
		var mapEl = h( 'div', { className: 'afx-wc-map', 'aria-label': L.mapLabel || 'Map' } );
		var mapHint = h( 'p', { className: 'afx-wc-map-hint' }, L.dragHint );
		summary.appendChild( sumLine );
		summary.appendChild( sumDir );
		var showMap = cfg.showMap && window.L && window.L.map;
		if ( showMap ) {
			summary.appendChild( mapEl );
			summary.appendChild( mapHint );
		}

		[ label, field, help, locate, notice, summary ].forEach( function ( el ) { root.appendChild( el ); } );

		function fresh() {
			if ( ctrl ) { ctrl.abort(); }
			ctrl = window.AbortController ? new AbortController() : null;
			return ctrl ? ctrl.signal : undefined;
		}

		function setNotice( text, kind ) {
			notice.textContent = text || '';
			notice.className = 'afx-wc-notice' + ( kind ? ' is-' + kind : '' );
		}

		function emit() {
			var typed = input.value.trim();
			if ( chosen ) {
				onChange( {
					input: chosen.input || typed,
					label: chosen.label || '',
					lat: chosen.lat,
					lng: chosen.lng,
					gps_code: chosen.gps_code || '',
					street: chosen.street || '',
					area: chosen.area || '',
					district: chosen.district || '',
					region: chosen.region || '',
					directions: chosen.directions || '',
					source: chosen.source,
					adjusted: !! chosen.adjusted,
				} );
			} else if ( typed ) {
				// Not picked from the list: keep what they typed so the shop can still deliver and retry.
				onChange( { input: typed, source: 'typed' } );
			} else {
				onChange( null );
			}
		}

		function closeList() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}

		function renderList( empty ) {
			list.innerHTML = '';
			items.forEach( function ( s, i ) {
				var li = h( 'li', { id: id + '-list-' + i, role: 'option', className: 'afx-wc-option' + ( i === active ? ' is-active' : '' ), 'aria-selected': i === active ? 'true' : 'false' } );
				li.appendChild( h( 'span', { className: 'afx-wc-opt-title' }, s.title ) );
				if ( s.subtitle ) { li.appendChild( h( 'span', { className: 'afx-wc-opt-sub' }, s.subtitle ) ); }
				li.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); pick( s ); } );
				list.appendChild( li );
			} );
			if ( empty ) {
				list.appendChild( h( 'li', { className: 'afx-wc-empty', role: 'presentation' }, L.noResults ) );
			}
			list.hidden = ! items.length && ! empty;
			input.setAttribute( 'aria-expanded', items.length ? 'true' : 'false' );
			if ( active >= 0 ) { input.setAttribute( 'aria-activedescendant', id + '-list-' + active ); }
		}

		function lookupFailed( e ) {
			if ( e && e.name === 'AbortError' ) { return; }
			lookupsFailing = true;
			items = [];
			closeList();
			setNotice( L.offline, 'warn' );
		}

		function search() {
			var q = input.value;
			clearTimeout( timer );
			if ( q.trim().length < 2 ) {
				if ( ctrl ) { ctrl.abort(); }
				items = [];
				closeList();
				return;
			}
			timer = setTimeout( function () {
				var signal = fresh();
				root.classList.add( 'is-busy' );
				suggest( client, q, limit, signal ).then( function ( res ) {
					if ( signal && signal.aborted ) { return; }
					if ( lookupsFailing ) { lookupsFailing = false; setNotice( '' ); }
					items = res;
					active = res.length ? 0 : -1;
					renderList( ! res.length );
				} ).catch( lookupFailed ).then( function () {
					if ( ! signal || ! signal.aborted ) { root.classList.remove( 'is-busy' ); }
				} );
			}, 220 );
		}

		function showSummary() {
			if ( ! chosen ) {
				summary.hidden = true;
				return;
			}
			var line = [ chosen.gps_code, chosen.street, chosen.area || chosen.district, chosen.region ].filter( Boolean ).join( ' · ' );
			sumLine.textContent = '';
			sumLine.appendChild( h( 'span', { className: 'afx-wc-check', 'aria-hidden': 'true' }, '✓' ) );
			sumLine.appendChild( document.createTextNode( ' ' + ( line || chosen.label ) ) );
			sumDir.textContent = chosen.directions ? ( L.directions + ': ' + chosen.directions ) : '';
			sumDir.hidden = ! chosen.directions;
			summary.hidden = false;
			if ( showMap ) { drawMap(); }
		}

		function drawMap() {
			var ll = [ chosen.lat, chosen.lng ];
			if ( ! map ) {
				map = window.L.map( mapEl, { zoomControl: true, attributionControl: true, scrollWheelZoom: false } ).setView( ll, 17 );
				window.L.tileLayer( cfg.tileUrl, { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' } ).addTo( map );
				marker = window.L.marker( ll, { draggable: true, keyboard: true, title: L.dragHint } ).addTo( map );
				marker.on( 'dragend', moved );
				map.on( 'click', function ( e ) { marker.setLatLng( e.latlng ); moved(); } );
			} else {
				marker.setLatLng( ll );
				map.setView( ll, Math.max( map.getZoom(), 16 ) );
			}
			// The map may have been hidden while laid out.
			setTimeout( function () { map.invalidateSize(); }, 50 );
		}

		function moved() {
			var p = marker.getLatLng();
			var signal = fresh();
			chosen = assign( chosen, { lat: Math.round( p.lat * 1e7 ) / 1e7, lng: Math.round( p.lng * 1e7 ) / 1e7, adjusted: true } );
			setNotice( L.pinMoved );
			emit();
			// The code and directions belong to the new spot now.
			var spot = assign( chosen, {} );
			[ 'gps_code', 'directions', 'street', 'area', 'district', 'region' ].forEach( function ( k ) { delete spot[ k ]; } );
			complete( client, spot, signal, true ).then( function ( full ) {
				if ( signal && signal.aborted ) { return; }
				chosen = full;
				showSummary();
				emit();
			} ).catch( function () {} );
		}

		function pick( s ) {
			closeList();
			input.value = s.address.label;
			chosen = s.address;
			setNotice( '' );
			showSummary();
			emit();
			var signal = fresh();
			root.classList.add( 'is-busy' );
			complete( client, s.address, signal ).then( function ( full ) {
				if ( signal && signal.aborted ) { return; }
				chosen = s.resolved ? assign( s.address, { directions: full.directions } ) : full;
				showSummary();
				emit();
			} ).catch( function () {} ).then( function () { root.classList.remove( 'is-busy' ); } );
		}

		locate.addEventListener( 'click', function () {
			if ( ! navigator.geolocation ) { setNotice( L.locationDenied, 'warn' ); return; }
			setNotice( L.locating );
			navigator.geolocation.getCurrentPosition( function ( pos ) {
				setNotice( '' );
				pick( { address: { label: L.currentLocation, lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy_m: Math.round( pos.coords.accuracy ), source: 'current_location', input: '' } } );
			}, function () {
				setNotice( L.locationDenied, 'warn' );
			}, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 } );
		} );

		input.addEventListener( 'input', function () {
			if ( chosen ) {
				chosen = null;
				showSummary();
			}
			emit();
			search();
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowDown' && items.length ) { e.preventDefault(); active = ( active + 1 ) % items.length; renderList(); }
			else if ( e.key === 'ArrowUp' && items.length ) { e.preventDefault(); active = active <= 0 ? items.length - 1 : active - 1; renderList(); }
			else if ( e.key === 'Enter' && ! list.hidden && active >= 0 && items[ active ] ) { e.preventDefault(); pick( items[ active ] ); }
			else if ( e.key === 'Escape' ) { closeList(); }
		} );
		input.addEventListener( 'blur', function () { setTimeout( closeList, 150 ); } );

		// Restore a previous choice (classic checkout re-renders after an error).
		if ( cfg.initial && typeof cfg.initial === 'object' ) {
			var init = cfg.initial;
			if ( init.lat != null && init.lng != null ) {
				chosen = init;
				input.value = init.label || init.input || '';
				showSummary();
			} else if ( init.input ) {
				input.value = init.input;
			}
			emit();
		}

		return {
			destroy: function () {
				clearTimeout( timer );
				if ( ctrl ) { ctrl.abort(); }
				if ( map ) { map.remove(); }
				root.innerHTML = '';
			},
			focus: function () { input.focus(); },
		};
	}

	window.AfriHexAddressWidget = { mount: mount, _internal: { formatGPS: formatGPS, isGPSCode: isGPSCode, hasDirections: hasDirections, suggest: suggest, createClient: createClient } };
} )( window, document );
