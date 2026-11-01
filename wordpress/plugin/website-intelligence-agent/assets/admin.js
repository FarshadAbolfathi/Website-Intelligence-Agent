/* Advances a running scan while the admin watches, so learning finishes even where WP-Cron is slow or disabled. */
(function () {
	'use strict';
	var state = document.getElementById('wia-scan-state');
	var counts = document.getElementById('wia-scan-counts');
	if (!state || state.getAttribute('data-state') !== 'running' || !window.WIA_ADMIN) return;

	function step() {
		var body = new URLSearchParams({ action: 'wia_scan_step', nonce: WIA_ADMIN.nonce });
		fetch(WIA_ADMIN.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) throw new Error('scan');
				counts.textContent = res.data.counts;
				if (res.data.state === 'running') setTimeout(step, 500);
				else state.textContent = WIA_ADMIN.done;
			})
			.catch(function () { state.textContent = WIA_ADMIN.error; });
	}
	step();
})();
