/*!
 * Knowledge map: a force-directed graph on a canvas. No dependencies.
 * All labels come from the site's own index and are drawn with fillText or inserted with textContent.
 */
(function () {
	'use strict';
	var box = document.getElementById('wia-graph');
	var dataEl = document.getElementById('wia-graph-data');
	if (!box || !dataEl) return;
	var data;
	try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
	var labels = {};
	try { labels = JSON.parse(box.getAttribute('data-labels') || '{}'); } catch (e) { /* defaults below */ }
	var kinds = labels.kinds || {};

	var COLORS = { topic: '#3fb37f', doc: '#4a90e2', learned: '#a66bd3', gap: '#e5534b', reference: '#f0a030' };
	var EDGE = { related: 'rgba(214,160,230,0.38)', covers: 'rgba(255,255,255,0.13)', learned: 'rgba(166,107,211,0.8)' };
	var canvas = box.querySelector('canvas');
	var info = box.querySelector('.wia-graph-info');
	var ctx = canvas.getContext('2d');
	var dpr = window.devicePixelRatio || 1;
	var W = 0, H = 0;

	var nodes = data.nodes.map(function (n, i) {
		var a = i * 2.399963, r = 40 + 9 * Math.sqrt(i); // golden-angle spiral: a stable, even start
		var w = Math.max(1, +n.weight || 1);
		var radius = n.kind === 'topic' ? Math.min(24, 6 + 2.2 * Math.sqrt(w)) : n.kind === 'doc' ? Math.min(14, 5 + 1.6 * Math.sqrt(w)) : n.kind === 'gap' ? Math.min(18, 6 + 2 * Math.sqrt(w)) : n.kind === 'reference' ? Math.min(16, 6 + 2 * w) : 8;
		return { id: n.id, label: String(n.label || ''), kind: n.kind, url: n.url || '', type: n.type || '', weight: w, r: radius, x: Math.cos(a) * r, y: Math.sin(a) * r, vx: 0, vy: 0, links: [] };
	});
	var byId = {};
	nodes.forEach(function (n) { byId[n.id] = n; });
	var edges = data.edges.filter(function (e) { return byId[e.s] && byId[e.t]; }).map(function (e) {
		var s = byId[e.s], t = byId[e.t];
		s.links.push(t); t.links.push(s);
		return { s: s, t: t, kind: e.kind, w: +e.w || 1 };
	});

	var view = { x: 0, y: 0, k: 1 };
	var alpha = 1;
	var hover = null, selected = null, dragging = null, panning = null, query = '';

	function resize() {
		var rect = box.getBoundingClientRect();
		W = Math.max(280, rect.width);
		H = Math.max(360, Math.min(680, window.innerHeight * 0.7));
		canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
		canvas.width = W * dpr; canvas.height = H * dpr;
		draw();
	}

	function tick() {
		var i, j, a, b, dx, dy, d2, d, f;
		for (i = 0; i < nodes.length; i++) {
			a = nodes[i];
			for (j = i + 1; j < nodes.length; j++) {
				b = nodes[j];
				dx = b.x - a.x; dy = b.y - a.y; d2 = dx * dx + dy * dy + 0.01;
				if (d2 > 90000) continue;
				f = 900 * alpha / d2;
				a.vx -= dx * f; a.vy -= dy * f; b.vx += dx * f; b.vy += dy * f;
			}
		}
		edges.forEach(function (e) {
			dx = e.t.x - e.s.x; dy = e.t.y - e.s.y; d = Math.sqrt(dx * dx + dy * dy) || 1;
			var len = e.kind === 'covers' ? 55 : e.kind === 'learned' ? 45 : 85;
			f = (d - len) / d * 0.04 * alpha * (e.kind === 'related' ? Math.min(1.5, 0.5 + e.w) : 1);
			e.s.vx += dx * f; e.s.vy += dy * f; e.t.vx -= dx * f; e.t.vy -= dy * f;
		});
		nodes.forEach(function (n) {
			// Unlinked gap and reference words would drift away; a stronger pull keeps them in view.
			var g = n.links.length ? 0.012 : 0.03;
			n.vx -= n.x * g * alpha; n.vy -= n.y * g * alpha;
			if (n === dragging) { n.vx = n.vy = 0; return; }
			n.vx *= 0.82; n.vy *= 0.82;
			n.x += n.vx; n.y += n.vy;
		});
		alpha *= 0.985;
	}

	// Fit the whole map in the frame, leaving room for labels.
	function fit() {
		if (!nodes.length) return;
		var x0 = Infinity, x1 = -Infinity, y0 = Infinity, y1 = -Infinity;
		nodes.forEach(function (n) { x0 = Math.min(x0, n.x); x1 = Math.max(x1, n.x); y0 = Math.min(y0, n.y); y1 = Math.max(y1, n.y); });
		view.k = Math.max(0.3, Math.min(2, (W - 120) / Math.max(1, x1 - x0), (H - 90) / Math.max(1, y1 - y0)));
		view.x = -(x0 + x1) / 2; view.y = -(y0 + y1) / 2 + 8 / view.k;
	}
	var fitted = false;

	function screen(n) { return { x: W / 2 + (n.x + view.x) * view.k, y: H / 2 + (n.y + view.y) * view.k }; }
	function world(px, py) { return { x: (px - W / 2) / view.k - view.x, y: (py - H / 2) / view.k - view.y }; }
	function rtl(s) { return /[؀-ۿ]/.test(s); }
	function short(s, n) { return s.length > n ? s.slice(0, n - 1) + '…' : s; }
	function matches(n) { return query && n.label.toLowerCase().indexOf(query) !== -1; }

	function draw() {
		ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		ctx.fillStyle = '#1c1f26';
		ctx.fillRect(0, 0, W, H);
		var focus = selected || hover;
		var near = {};
		if (focus) { near[focus.id] = 1; focus.links.forEach(function (n) { near[n.id] = 1; }); }
		ctx.lineWidth = 1;
		edges.forEach(function (e) {
			var on = focus && (e.s === focus || e.t === focus);
			if (focus && !on) ctx.globalAlpha = 0.25;
			ctx.strokeStyle = on ? 'rgba(255,255,255,0.85)' : EDGE[e.kind] || EDGE.covers;
			var a = screen(e.s), b = screen(e.t);
			ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
			ctx.globalAlpha = 1;
		});
		nodes.forEach(function (n) {
			var p = screen(n), r = n.r * Math.sqrt(view.k);
			ctx.globalAlpha = focus && !near[n.id] ? 0.3 : 1;
			ctx.beginPath(); ctx.arc(p.x, p.y, r, 0, 6.2832);
			ctx.fillStyle = COLORS[n.kind] || '#999'; ctx.fill();
			if (n === focus || matches(n)) { ctx.lineWidth = 3; ctx.strokeStyle = '#fff'; ctx.stroke(); ctx.lineWidth = 1; }
			// On a small map every topic is named; on a large one only the bigger topics, until zoomed in.
			var show = n === focus || near[n.id] || matches(n) || n.kind !== 'doc' && (n.kind !== 'topic' || nodes.length <= 120 || n.r > 11 || view.k > 1.3) || view.k > 1.9;
			if (show) {
				ctx.font = (n.kind === 'topic' ? '600 ' : '') + Math.round(11 + Math.min(4, view.k)) + 'px Tahoma, Vazirmatn, sans-serif';
				ctx.direction = rtl(n.label) ? 'rtl' : 'ltr';
				ctx.textAlign = 'center'; ctx.textBaseline = 'top';
				ctx.lineWidth = 3; ctx.strokeStyle = 'rgba(20,22,28,0.85)';
				var text = short(n.label, n.kind === 'doc' ? 26 : 32);
				ctx.strokeText(text, p.x, p.y + r + 3);
				ctx.fillStyle = '#f1f1f1'; ctx.fillText(text, p.x, p.y + r + 3);
				ctx.lineWidth = 1;
			}
			ctx.globalAlpha = 1;
		});
	}

	function loop() {
		if (alpha > 0.02 || dragging) tick();
		else if (!fitted) { fitted = true; fit(); }
		draw();
		requestAnimationFrame(loop);
	}

	function pick(px, py) {
		var best = null, bd = Infinity;
		nodes.forEach(function (n) {
			var p = screen(n), r = n.r * Math.sqrt(view.k) + 4, dx = p.x - px, dy = p.y - py, d = dx * dx + dy * dy;
			if (d < r * r && d < bd) { best = n; bd = d; }
		});
		return best;
	}
	function pos(e) { var r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }

	function link(text, href) {
		var a = document.createElement('a'); a.textContent = text; a.href = href; a.className = 'button button-small';
		if (/^https?:/i.test(href) && href.indexOf(location.host) === -1) { a.target = '_blank'; a.rel = 'noopener'; }
		return a;
	}
	function showInfo(n) {
		while (info.firstChild) info.removeChild(info.firstChild);
		if (!n) { info.hidden = true; return; }
		var h = document.createElement('strong'); h.textContent = n.label; h.dir = 'auto'; info.appendChild(h);
		var k = document.createElement('div'); k.className = 'description'; k.textContent = (kinds[n.kind] || n.kind) + (n.type ? ' · ' + n.type : ''); info.appendChild(k);
		if (n.links.length) {
			var t = document.createElement('div'); t.className = 'wia-graph-links'; t.dir = 'auto';
			t.textContent = (labels.links || 'Links') + ': ' + n.links.slice(0, 12).map(function (m) { return short(m.label, 30); }).join('، ');
			info.appendChild(t);
		}
		var row = document.createElement('p');
		if (n.kind === 'doc' && /^https?:\/\//i.test(n.url)) row.appendChild(link(labels.open || 'Open', n.url));
		if (n.kind !== 'doc') row.appendChild(link(labels.test || 'Ask', data.test + '&q=' + encodeURIComponent(n.label)));
		if (n.kind === 'gap' || n.kind === 'reference') { row.appendChild(document.createTextNode(' ')); row.appendChild(link(labels.faq || 'Answer', data.faq + '&prefill=' + encodeURIComponent(n.label))); }
		info.appendChild(row);
		info.hidden = false;
	}

	canvas.addEventListener('mousedown', function (e) {
		var p = pos(e), n = pick(p.x, p.y);
		if (n) { dragging = n; alpha = Math.max(alpha, 0.3); } else panning = { x: p.x, y: p.y, vx: view.x, vy: view.y, moved: false };
	});
	window.addEventListener('mousemove', function (e) {
		var p = pos(e);
		if (dragging) { var w = world(p.x, p.y); dragging.x = w.x; dragging.y = w.y; dragging.moved = true; return; }
		if (panning) {
			view.x = panning.vx + (p.x - panning.x) / view.k; view.y = panning.vy + (p.y - panning.y) / view.k;
			panning.moved = panning.moved || Math.abs(p.x - panning.x) + Math.abs(p.y - panning.y) > 3; return;
		}
		if (e.target === canvas) { hover = pick(p.x, p.y); canvas.style.cursor = hover ? 'pointer' : 'grab'; }
	});
	window.addEventListener('mouseup', function (e) {
		if (dragging) { if (!dragging.moved) { selected = dragging === selected ? null : dragging; showInfo(selected); } dragging.moved = false; dragging = null; }
		else if (panning) { if (!panning.moved && e.target === canvas) { selected = null; showInfo(null); } panning = null; }
	});
	canvas.addEventListener('wheel', function (e) {
		e.preventDefault();
		var p = pos(e), before = world(p.x, p.y);
		view.k = Math.max(0.3, Math.min(4, view.k * (e.deltaY < 0 ? 1.12 : 1 / 1.12)));
		var after = world(p.x, p.y);
		view.x += after.x - before.x; view.y += after.y - before.y;
	}, { passive: false });
	// Touch: one finger pans or drags a node, a tap selects.
	canvas.addEventListener('touchstart', function (e) { if (e.touches.length === 1) { var t = e.touches[0]; canvas.dispatchEvent(new MouseEvent('mousedown', { clientX: t.clientX, clientY: t.clientY })); e.preventDefault(); } }, { passive: false });
	canvas.addEventListener('touchmove', function (e) { if (e.touches.length === 1) { var t = e.touches[0]; window.dispatchEvent(new MouseEvent('mousemove', { clientX: t.clientX, clientY: t.clientY })); e.preventDefault(); } }, { passive: false });
	canvas.addEventListener('touchend', function (e) { var t = e.changedTouches[0]; canvas.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, clientX: t.clientX, clientY: t.clientY })); });

	var search = document.createElement('input');
	search.type = 'search'; search.className = 'wia-graph-search'; search.placeholder = labels.search || '…'; search.dir = 'auto';
	search.setAttribute('aria-label', search.placeholder);
	search.addEventListener('input', function () {
		query = search.value.trim().toLowerCase();
		var hit = query && nodes.filter(matches)[0];
		if (hit) { view.x = -hit.x; view.y = -hit.y; selected = hit; showInfo(hit); }
	});
	box.insertBefore(search, canvas);

	for (var i = 0; i < 220; i++) tick(); // settle before the first frame, so the map does not explode on screen
	window.addEventListener('resize', resize);
	resize();
	fit();
	loop();
})();
