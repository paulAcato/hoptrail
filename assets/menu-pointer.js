/**
 * Hoptrail: pointer on the Tools menu that leads to the walkthrough.
 *
 * Plain JavaScript (no jQuery), styled by assets/menu-pointer.css. Data comes from
 * Admin\Tour::menu_pointer() in window.hoptrailPointer.
 */
( function () {
	var data = window.hoptrailPointer;
	var target = document.getElementById( 'menu-tools' );
	if ( ! data || ! target ) {
		return;
	}

	var rtl = 'rtl' === document.documentElement.dir;

	var el = function ( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	};

	var box = el( 'div', 'rdi-menu-pointer' );
	var body = el( 'div', 'rdi-menu-pointer__body' );
	var head = el( 'div', 'rdi-menu-pointer__head' );
	var wapuu = el( 'img', 'rdi-menu-pointer__wapuu' );
	var title = el( 'h2', 'rdi-menu-pointer__title', data.title );
	var text = el( 'p', 'rdi-menu-pointer__text', data.text );
	var actions = el( 'div', 'rdi-menu-pointer__actions' );
	var go = el( 'a', 'rdi-menu-pointer__go', data.linkText );
	var dismiss = el( 'button', 'rdi-menu-pointer__dismiss', data.dismiss );

	title.id = 'rdi-menu-pointer-title';
	text.id = 'rdi-menu-pointer-text';
	box.setAttribute( 'role', 'dialog' );
	box.setAttribute( 'aria-labelledby', title.id );
	box.setAttribute( 'aria-describedby', text.id );
	wapuu.src = data.wapuu;
	wapuu.alt = '';
	wapuu.width = 44;
	wapuu.height = 44;
	go.href = data.link;
	dismiss.type = 'button';

	head.append( wapuu, title );
	actions.append( go, dismiss );
	body.append( head, text, actions );
	box.append( body );

	// CSS anchor positioning ties the pointer to the Tools item (assets/menu-pointer.css). Without
	// it, a fixed pointer is moved along on every scroll and resize.
	var anchored = window.CSS && CSS.supports && CSS.supports( 'anchor-name: --rdi' );
	if ( anchored ) {
		target.style.setProperty( 'anchor-name', '--rdi-menu-tools' );
		box.classList.add( 'is-anchored' );
	}

	var frame = 0;
	var place = function () {
		if ( anchored || frame ) {
			return;
		}
		frame = window.requestAnimationFrame( function () {
			var rect = target.getBoundingClientRect();
			frame = 0;
			box.style.top = rect.top + rect.height / 2 - 39 + 'px';
			if ( rtl ) {
				box.style.right = window.innerWidth - rect.left + 12 + 'px';
			} else {
				box.style.left = rect.right + 12 + 'px';
			}
		} );
	};

	var onKey = function ( event ) {
		if ( 'Escape' === event.key && box.contains( document.activeElement ) ) {
			close();
		}
	};

	var close = function () {
		box.remove();
		target.style.removeProperty( 'anchor-name' );
		window.removeEventListener( 'resize', place );
		window.removeEventListener( 'scroll', place );
		document.removeEventListener( 'keydown', onKey );
		var form = new FormData();
		form.append( 'action', 'dismiss-wp-pointer' );
		form.append( 'pointer', data.name );
		window.fetch( window.ajaxurl, { method: 'POST', body: form, credentials: 'same-origin' } );
	};

	dismiss.addEventListener( 'click', close );

	// Other plugins point at the admin menu too: wait until their pointers are closed.
	var open = function () {
		var others = Array.prototype.some.call( document.querySelectorAll( '.wp-pointer' ), function ( node ) {
			return node.getClientRects().length > 0;
		} );
		if ( others ) {
			window.setTimeout( open, 1000 );
			return;
		}
		document.body.append( box );
		place();
		window.addEventListener( 'resize', place, { passive: true } );
		window.addEventListener( 'scroll', place, { passive: true } );
		document.addEventListener( 'keydown', onKey );
	};

	window.setTimeout( open, 500 );
}() );
