/**
 * Nera DCMS Age Gate — dialog-based DOB verification.
 *
 * Intercepts "Enter Now" (form.cart submit) and "Place Order" (#place_order click)
 * using capture-phase listeners so the handlers run before Alpine.js and WooCommerce.
 *
 * Flow:
 *  1. User clicks Enter Now or Place Order.
 *  2. If neraAgeGate.needsVerification is true → stop propagation, open dialog.
 *  3. User selects Day / Month / Year → Continue.
 *  4. Client validates date; AJAX POSTs to nera_dcms_verify_dob.
 *  5a. success=true  → close dialog, set needsVerification=false, re-trigger action.
 *  5b. success=false → show inline error, keep dialog open (allow retry).
 */
(function () {
	'use strict';

	/** Reference to a pending Alpine component root (Enter Now form). */
	var pendingAlpineRoot = null;

	/** Reference to a pending button (#place_order). */
	var pendingButton = null;

	/** Localised config injected by wp_localize_script. */
	var cfg = window.neraAgeGate || {};

	// ------------------------------------------------------------------
	// Early exit if nothing to do.
	// ------------------------------------------------------------------
	function isActive() {
		return !!(cfg && cfg.needsVerification);
	}

	// ------------------------------------------------------------------
	// Dialog helpers.
	// ------------------------------------------------------------------
	function getDialog() {
		return document.getElementById('nera-age-dialog');
	}

	function getErrorEl() {
		return document.getElementById('nera-age-dialog-error');
	}

	function getSubmitBtn() {
		return document.getElementById('nera-age-dialog-submit');
	}

	function getCloseBtn() {
		return document.getElementById('nera-age-dialog-close');
	}

	function getSubmitLabel() {
		return document.getElementById('nera-age-dialog-submit-label');
	}

	function showError(msg) {
		var el = getErrorEl();
		if (!el) return;
		el.textContent = msg;
		el.classList.remove('hidden');
	}

	function clearError() {
		var el = getErrorEl();
		if (!el) return;
		el.textContent = '';
		el.classList.add('hidden');
	}

	function getSubmitIcon() {
		var btn = getSubmitBtn();
		return btn ? btn.querySelector('.nera-age-dialog-submit-icon') : null;
	}

	function setSubmitBusy(busy) {
		var btn = getSubmitBtn();
		var closeBtn = getCloseBtn();
		var lbl = getSubmitLabel();
		var icon = getSubmitIcon();
		if (!btn) return;
		btn.disabled = busy;
		btn.classList.toggle('is-loading', busy);
		btn.setAttribute('aria-busy', busy ? 'true' : 'false');
		if (closeBtn) {
			closeBtn.disabled = busy;
		}
		if (icon) {
			icon.textContent = busy ? 'progress_activity' : 'check_circle';
			icon.classList.toggle('is-spinning', busy);
		}
		if (lbl) {
			lbl.textContent = busy
				? (cfg.i18n && cfg.i18n.btnVerifying ? cfg.i18n.btnVerifying : 'Verifying\u2026')
				: (cfg.i18n && cfg.i18n.btnContinue  ? cfg.i18n.btnContinue  : 'Continue');
		}
	}

	function openDialog() {
		var dialog = getDialog();
		if (!dialog) return;
		setSubmitBusy(false);
		clearError();
		resetDropdowns();
		dialog.style.display = '';
		dialog.setAttribute('aria-hidden', 'false');
		// Move focus inside for accessibility.
		var firstSelect = dialog.querySelector('select');
		if (firstSelect) {
			setTimeout(function () { firstSelect.focus(); }, 50);
		}
		// Prevent body scroll.
		document.body.style.overflow = 'hidden';
	}

	function closeDialog() {
		var dialog = getDialog();
		if (!dialog) return;
		dialog.style.display = 'none';
		dialog.setAttribute('aria-hidden', 'true');
		document.body.style.overflow = '';
	}

	function dismissDialog() {
		closeDialog();
		pendingAlpineRoot = null;
		pendingButton     = null;
	}

	function resetDropdowns() {
		var dialog = getDialog();
		if (!dialog) return;
		dialog.querySelectorAll('select[name^="nera_dob_"]').forEach(function (sel) {
			sel.value = '';
		});
	}

	// ------------------------------------------------------------------
	// Date validation (client-side, mirrors PHP).
	// ------------------------------------------------------------------
	function dobParts() {
		var dialog = getDialog();
		if (!dialog) return null;
		return {
			d: parseInt(dialog.querySelector('select[name="nera_dob_day"]').value, 10) || 0,
			m: parseInt(dialog.querySelector('select[name="nera_dob_month"]').value, 10) || 0,
			y: parseInt(dialog.querySelector('select[name="nera_dob_year"]').value, 10) || 0,
		};
	}

	function isRealDate(p) {
		if (!p.d || !p.m || !p.y) return false;
		var dt = new Date(p.y, p.m - 1, p.d);
		if (
			dt.getFullYear() !== p.y ||
			dt.getMonth()    !== p.m - 1 ||
			dt.getDate()     !== p.d
		) {
			return false;
		}
		var today = new Date();
		today.setHours(0, 0, 0, 0);
		return dt <= today;
	}

	// ------------------------------------------------------------------
	// AJAX verify.
	// ------------------------------------------------------------------
	function verifyDob() {
		var p = dobParts();
		if (!isRealDate(p)) {
			showError(cfg.i18n && cfg.i18n.errInvalid ? cfg.i18n.errInvalid : 'Please select a valid date.');
			return;
		}

		clearError();
		setSubmitBusy(true);

		var body = new FormData();
		body.append('action', 'nera_dcms_verify_dob');
		body.append('nonce',  cfg.nonce || '');
		body.append('nera_dob_day',   p.d);
		body.append('nera_dob_month', p.m);
		body.append('nera_dob_year',  p.y);

		fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method:      'POST',
			credentials: 'same-origin',
			body:        body,
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				setSubmitBusy(false);
				if (data.success) {
					onVerifySuccess();
				} else {
					var msg = data.message || (cfg.i18n && cfg.i18n.errGeneral) || 'Something went wrong.';
					showError(msg);
				}
			})
			.catch(function () {
				setSubmitBusy(false);
				showError(cfg.i18n && cfg.i18n.errGeneral ? cfg.i18n.errGeneral : 'Something went wrong. Please try again.');
			});
	}

	// ------------------------------------------------------------------
	// On successful verification.
	// ------------------------------------------------------------------
	function onVerifySuccess() {
		cfg.needsVerification = false;
		closeDialog();

		// Re-trigger Enter Now (via Alpine component method).
		if (pendingAlpineRoot) {
			var root = pendingAlpineRoot;
			pendingAlpineRoot = null;
			try {
				if (window.Alpine && typeof Alpine.$data === 'function') {
					var data = Alpine.$data(root);
					if (data && typeof data.submitForm === 'function') {
						data.submitForm(new Event('submit'));
						return;
					}
				}
			} catch (e) {
				// fallback below
			}
			// Fallback: dispatch a native submit on the form.cart inside root.
			var cartForm = root.querySelector('form.cart');
			if (cartForm) {
				cartForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
			}
			return;
		}

		// Re-trigger Place Order click.
		if (pendingButton) {
			var btn = pendingButton;
			pendingButton = null;
			btn.click();
		}
	}

	// ------------------------------------------------------------------
	// Checkout terms guard — disable #place_order until #terms is checked.
	// Plugin-owned; theme initTermsGuard is unreliable when checkout.js loads after Alpine.
	// ------------------------------------------------------------------
	function isCheckoutTermsRequired() {
		var terms = document.getElementById('terms');
		var marker = document.querySelector('form.checkout input[name="terms-field"]');
		return !!(terms && marker);
	}

	function updatePlaceOrderTermsState() {
		var btn = document.getElementById('place_order');
		if (!btn) return;

		var terms = document.getElementById('terms');
		var accepted = !isCheckoutTermsRequired() || (terms && terms.checked);

		btn.disabled = !accepted;
		btn.setAttribute('aria-disabled', accepted ? 'false' : 'true');
	}

	function initCheckoutTermsGuard() {
		if (!document.getElementById('place_order')) return;

		updatePlaceOrderTermsState();

		document.addEventListener('change', function (e) {
			if (e.target && e.target.id === 'terms') {
				updatePlaceOrderTermsState();
			}
		});

		if (typeof jQuery !== 'undefined') {
			jQuery(document.body).on('updated_checkout', function () {
				updatePlaceOrderTermsState();
			});
		}
	}

	// ------------------------------------------------------------------
	// Intercept Enter Now — form.cart submit (capture phase).
	// ------------------------------------------------------------------
	document.addEventListener('submit', function (e) {
		if (!e.target || !e.target.matches('form.cart')) return;
		if (!isActive()) return;

		e.stopImmediatePropagation();
		e.preventDefault();

		pendingAlpineRoot = e.target.closest('[x-data]');
		pendingButton     = null;
		openDialog();
	}, true);

	// ------------------------------------------------------------------
	// Intercept Place Order — #place_order click (capture phase).
	// ------------------------------------------------------------------
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest('#place_order');
		if (!btn) return;
		if (!isActive()) return;
		if (btn.disabled) return;

		var terms = document.getElementById('terms');
		if (terms && !terms.checked) return;

		e.stopImmediatePropagation();
		e.preventDefault();

		pendingButton     = btn;
		pendingAlpineRoot = null;
		openDialog();
	}, true);

	// ------------------------------------------------------------------
	// Dialog Continue button.
	// ------------------------------------------------------------------
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest('#nera-age-dialog-submit');
		if (!btn || btn.disabled) return;
		verifyDob();
	});

	// ------------------------------------------------------------------
	// Close dialog via X button.
	// ------------------------------------------------------------------
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest('#nera-age-dialog-close');
		if (!btn || btn.disabled) return;
		var dialog = getDialog();
		if (!dialog || dialog.style.display === 'none') return;
		dismissDialog();
	});

	// ------------------------------------------------------------------
	// Close dialog on Escape key.
	// ------------------------------------------------------------------
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') return;
		var dialog = getDialog();
		if (!dialog || dialog.style.display === 'none') return;
		var closeBtn = getCloseBtn();
		if (closeBtn && closeBtn.disabled) return;
		dismissDialog();
	});

	// ------------------------------------------------------------------
	// Enable/disable Continue button based on dropdown state.
	// ------------------------------------------------------------------
	document.addEventListener('change', function (e) {
		if (!e.target || !e.target.matches('#nera-age-dialog select')) return;
		var p   = dobParts();
		var btn = getSubmitBtn();
		if (btn) {
			btn.disabled = !(p.d && p.m && p.y);
		}
		if (p.d && p.m && p.y) {
			clearError();
		}
	});

	// ------------------------------------------------------------------
	// Init: disable Continue until dropdowns are filled.
	// ------------------------------------------------------------------
	(function init() {
		function onReady() {
			var btn = getSubmitBtn();
			if (btn) btn.disabled = true;
			initCheckoutTermsGuard();
		}
		if (document.readyState !== 'loading') {
			onReady();
		} else {
			document.addEventListener('DOMContentLoaded', onReady);
		}
	}());
})();
