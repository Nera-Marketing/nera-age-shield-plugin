/**
 * Reposition the registration DOB block below Confirm Password.
 *
 * The theme renders woocommerce_register_form after the checkboxes; this script
 * moves #nera-register-dob to sit directly under the Confirm Password field.
 */
(function () {
	'use strict';

	function repositionRegisterDob() {
		var dob = document.getElementById('nera-register-dob');
		var password2 = document.getElementById('reg_password2');

		if (!dob || !password2) {
			return;
		}

		var confirmBlock = password2.closest('.mb-5');
		if (!confirmBlock) {
			return;
		}

		if (confirmBlock.nextElementSibling === dob) {
			return;
		}

		confirmBlock.insertAdjacentElement('afterend', dob);
	}

	function init() {
		repositionRegisterDob();

		var tabRegister = document.getElementById('tab-register');
		if (tabRegister) {
			tabRegister.addEventListener('click', repositionRegisterDob);
		}
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init);
	}
})();
