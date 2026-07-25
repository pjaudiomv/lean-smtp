/**
 * Settings → Lean SMTP: show only the selected mailer's credentials section and
 * keep the header status pill in step with the picker.
 *
 * Configuration (option name and transport labels) is provided by PHP through
 * wp_localize_script() as window.leanSmtpSettings.
 */
( function () {
	var config = window.leanSmtpSettings || {};
	var labels = config.labels || {};

	if ( ! config.option ) {
		return;
	}

	var radios = document.getElementsByName( config.option );
	if ( ! radios.length ) {
		return;
	}

	var statusName = document.getElementById( 'lsmtp-status-name' );

	function sync() {
		var value = '';
		Array.prototype.forEach.call( radios, function ( radio ) {
			if ( radio.checked ) {
				value = radio.value;
			}
		} );

		document.querySelectorAll( '.lsmtp-section' ).forEach( function ( el ) {
			el.style.display = ( el.getAttribute( 'data-mailer' ) === value ) ? '' : 'none';
		} );

		document.querySelectorAll( '.lsmtp-transport' ).forEach( function ( el ) {
			var input = el.querySelector( 'input' );
			el.classList.toggle( 'is-active', !! ( input && input.checked ) );
		} );

		if ( statusName && labels[ value ] ) {
			statusName.textContent = labels[ value ];
		}
	}

	Array.prototype.forEach.call( radios, function ( radio ) {
		radio.addEventListener( 'change', sync );
	} );

	sync();
}() );
