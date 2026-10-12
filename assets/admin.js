/* AI Infos Image Optimizer – bulk runner. Small batches, live progress, stop anytime. */
( function () {
	document.querySelectorAll( '[data-aiio-run]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var tool = btn.closest( '.aiio-tool' );
			var box = tool.querySelector( '.aiio-progress' );
			var bar = box.querySelector( 'i' );
			var status = box.querySelector( '.aiio-status' );
			var type = btn.getAttribute( 'data-aiio-run' );
			var total = parseInt( btn.getAttribute( 'data-aiio-total' ), 10 ) || 0;
			var done = 0, saved = 0, errors = 0, after = 0, running = true;
			var label = btn.textContent;
			box.hidden = false;
			tool.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = true; } );
			var stop = document.createElement( 'button' );
			stop.type = 'button';
			stop.className = 'button-link';
			stop.textContent = 'Stop';
			stop.addEventListener( 'click', function () { running = false; stop.remove(); } );
			btn.after( stop );

			function fmt( b ) { return b > 1048576 ? ( b / 1048576 ).toFixed( 1 ) + ' MB' : Math.round( b / 1024 ) + ' KB'; }
			function step() {
				var body = new URLSearchParams( { action: 'aiio_bulk', _ajax_nonce: aiio.nonce, type: type, after: after } );
				fetch( aiio.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( ! res.success ) { throw new Error( res.data || 'Request failed' ); }
						var d = res.data;
						done += d.done; saved += d.saved; errors += d.errors; after = d.after;
						total = Math.max( total, done + d.left );
						bar.style.width = ( total ? Math.round( done / total * 100 ) : 100 ) + '%';
						status.textContent = done + ' of ' + total + ' processed' + ( saved ? ' · ' + fmt( saved ) + ' saved' : '' ) + ( errors ? ' · ' + errors + ' failed' : '' );
						var na = document.querySelector( '[data-aiio-noalt]' );
						if ( na ) { na.textContent = d.noalt; }
						if ( running && d.left > 0 && d.done > 0 ) { step(); } else { finish(); }
					} )
					.catch( function ( e ) { status.textContent = 'Stopped: ' + e.message + '. You can run it again; finished images are skipped.'; finish(); } );
			}
			function finish() {
				stop.remove();
				tool.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = false; } );
				btn.textContent = label;
				if ( running ) { status.textContent += ' · Done.'; bar.style.width = '100%'; }
			}
			step();
		} );
	} );
} )();
