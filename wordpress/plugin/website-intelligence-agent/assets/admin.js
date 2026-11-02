/* Advances background work while the admin watches, so it finishes even where WP-Cron is slow or disabled. */
(function () {
	'use strict';
	if (!window.WIA_ADMIN) return;

	function stepper(el, action, nonce, onStep, onDone, onError) {
		function step() {
			var body = new URLSearchParams({ action: action, nonce: nonce });
			fetch(WIA_ADMIN.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.success) throw new Error(action);
					if (onStep) onStep(res.data);
					if (res.data.state === 'running' || res.data.state === 'locked') setTimeout(step, res.data.state === 'locked' ? 3000 : 500);
					else onDone(res.data);
				})
				.catch(onError);
		}
		step();
	}

	var state = document.getElementById('wia-scan-state');
	var counts = document.getElementById('wia-scan-counts');
	if (state && state.getAttribute('data-state') === 'running') {
		stepper(state, 'wia_scan_step', WIA_ADMIN.nonce,
			function (d) { counts.textContent = d.counts; },
			function () { state.textContent = WIA_ADMIN.done; },
			function () { state.textContent = WIA_ADMIN.error; });
	}

	var ref = document.getElementById('wia-ref-state');
	if (ref && ref.getAttribute('data-state') === 'running') {
		stepper(ref, 'wia_ref_step', WIA_ADMIN.ref, null,
			function () { ref.textContent = WIA_ADMIN.read; },
			function () { ref.textContent = WIA_ADMIN.error; });
	}
})();
