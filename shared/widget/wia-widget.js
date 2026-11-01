/*!
 * Website Intelligence Agent - chat widget. No dependencies. Used by both editions.
 *
 * Self-hosted: <script src="https://YOUR-ENGINE/widget/wia-widget.js" data-endpoint="https://YOUR-ENGINE/public/v1" data-site-key="KEY" defer></script>
 * WordPress:   the plugin sets window.WIA_WIDGET = { endpoint, siteKey }.
 *
 * Runs inside a Shadow DOM so site styles cannot break it and it cannot break the site. All server text is
 * inserted with textContent, and only http(s) links are rendered, so a crawled page cannot inject markup.
 */
(function () {
	'use strict';
	if (window.__wiaWidgetLoaded) return;
	window.__wiaWidgetLoaded = true;

	var script = document.currentScript;
	var boot = window.WIA_WIDGET || {};
	var endpoint = String(boot.endpoint || (script && script.getAttribute('data-endpoint')) || '').replace(/\/+$/, '');
	var siteKey = boot.siteKey || (script && script.getAttribute('data-site-key')) || '';
	if (!endpoint) return;

	var UI = {
		fa: { title: 'دستیار سایت', placeholder: 'پیام خود را بنویسید…', send: 'ارسال', open: 'گفتگو با دستیار', close: 'بستن', sources: 'منابع:', error: 'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.', limited: 'پیام‌ها زیاد شد. لطفاً کمی بعد دوباره تلاش کنید.', welcome: 'سلام! درباره این سایت چه سؤالی دارید؟', typing: 'در حال نوشتن…', outdated: 'این پاسخ ممکن است قدیمی باشد.' },
		ar: { title: 'مساعد الموقع', placeholder: 'اكتب رسالتك…', send: 'إرسال', open: 'تحدث مع المساعد', close: 'إغلاق', sources: 'المصادر:', error: 'تعذر الاتصال. يرجى المحاولة مرة أخرى.', limited: 'رسائل كثيرة. يرجى المحاولة بعد قليل.', welcome: 'مرحبا! ما سؤالك عن هذا الموقع؟', typing: 'يكتب…', outdated: 'قد تكون هذه الإجابة قديمة.' },
		en: { title: 'Site assistant', placeholder: 'Type your message…', send: 'Send', open: 'Chat with the assistant', close: 'Close', sources: 'Sources:', error: 'Could not connect. Please try again.', limited: 'Too many messages. Please try again shortly.', welcome: 'Hello! What would you like to know about this site?', typing: 'Typing…', outdated: 'This answer may be out of date.' }
	};
	var STORE = 'wia_chat_' + siteKey;
	var MAX_AGE = 2 * 3600 * 1000;

	function read() { try { var d = JSON.parse(localStorage.getItem(STORE) || 'null'); return d && Date.now() - d.at < MAX_AGE ? d : null; } catch (e) { return null; } }
	function write(state) { try { state.at = Date.now(); localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* private mode */ } }

	function isRtl(lang) { return lang === 'fa' || lang === 'ar'; }
	function safeUrl(url) { return /^https?:\/\//i.test(String(url || '')) ? String(url) : ''; }
	function pick(map, lang, fallback) { return (map && map[lang]) || fallback; }

	function start(config) {
		if (config.enabled === false) return;
		var lang = config.language && UI[config.language] ? config.language : 'fa';
		var text = UI[lang];
		var saved = read() || { items: [], conversationId: null };
		var busy = false;
		var color = /^#[0-9a-f]{3,6}$/i.test(config.color || '') ? config.color : '#2563eb';
		var side = config.position === 'left' ? 'left' : 'right';

		var host = document.createElement('div');
		host.id = 'wia-widget';
		var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
		var style = document.createElement('style');
		style.textContent =
			':host{all:initial}*{box-sizing:border-box;font-family:Vazirmatn,Tahoma,system-ui,-apple-system,"Segoe UI",sans-serif}' +
			'.fab{position:fixed;bottom:20px;' + side + ':20px;width:56px;height:56px;border-radius:50%;border:0;background:' + color + ';color:#fff;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);z-index:2147483000;display:flex;align-items:center;justify-content:center}' +
			'.fab:focus-visible,button:focus-visible,textarea:focus-visible,a:focus-visible{outline:3px solid #111;outline-offset:2px}' +
			'.panel{position:fixed;bottom:88px;' + side + ':20px;width:370px;max-width:calc(100vw - 24px);height:540px;max-height:calc(100vh - 110px);background:#fff;color:#1f2937;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.28);display:none;flex-direction:column;overflow:hidden;z-index:2147483000}' +
			'.panel.open{display:flex}.head{background:' + color + ';color:#fff;padding:12px 14px;display:flex;align-items:center;gap:10px}.head img{width:30px;height:30px;border-radius:50%;object-fit:cover;background:#fff}.head strong{flex:1;font-size:15px;font-weight:600}' +
			'.x{background:none;border:0;color:#fff;font-size:20px;cursor:pointer;line-height:1;padding:4px 8px}' +
			'.log{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;background:#f8fafc}' +
			'.m{max-width:86%;padding:9px 12px;border-radius:12px;font-size:14px;line-height:1.75;white-space:pre-wrap;overflow-wrap:anywhere}' +
			'.bot{background:#fff;border:1px solid #e5e7eb;align-self:flex-start}.me{background:' + color + ';color:#fff;align-self:flex-end}' +
			'.src{margin-top:6px;padding-top:6px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280}.src a{display:block;color:' + color + ';text-decoration:underline;overflow-wrap:anywhere}' +
			'.old{margin-top:4px;font-size:12px;color:#92400e}.typing{color:#6b7280;font-size:13px}' +
			'form{display:flex;gap:8px;padding:10px;border-top:1px solid #e5e7eb;background:#fff}textarea{flex:1;resize:none;height:42px;max-height:100px;padding:9px 10px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;color:#111;background:#fff}' +
			'.send{border:0;border-radius:10px;background:' + color + ';color:#fff;padding:0 14px;cursor:pointer;font-size:14px}.send:disabled{opacity:.55;cursor:default}' +
			'@media (max-width:480px){.panel{bottom:0;left:0;right:0;width:100vw;max-width:100vw;height:100dvh;max-height:100dvh;border-radius:0}}' +
			'@media (prefers-reduced-motion:no-preference){.panel.open{animation:wia .16s ease-out}@keyframes wia{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}}';
		root.appendChild(style);

		var fab = document.createElement('button');
		fab.className = 'fab'; fab.type = 'button'; fab.setAttribute('aria-label', text.open); fab.setAttribute('aria-expanded', 'false');
		fab.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

		var panel = document.createElement('div');
		panel.className = 'panel'; panel.dir = isRtl(lang) ? 'rtl' : 'ltr'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', pick(config.title, lang, text.title));
		var head = document.createElement('div'); head.className = 'head';
		var avatar = safeUrl(config.avatar_url);
		if (avatar) { var img = document.createElement('img'); img.src = avatar; img.alt = ''; head.appendChild(img); }
		var title = document.createElement('strong'); title.textContent = pick(config.title, lang, text.title); head.appendChild(title);
		var closeBtn = document.createElement('button'); closeBtn.className = 'x'; closeBtn.type = 'button'; closeBtn.setAttribute('aria-label', text.close); closeBtn.textContent = '×'; head.appendChild(closeBtn);
		var log = document.createElement('div'); log.className = 'log'; log.setAttribute('aria-live', 'polite');
		var form = document.createElement('form');
		var input = document.createElement('textarea'); input.placeholder = text.placeholder; input.maxLength = 1000; input.rows = 1; input.setAttribute('aria-label', text.placeholder);
		var send = document.createElement('button'); send.className = 'send'; send.type = 'submit'; send.textContent = text.send;
		form.appendChild(input); form.appendChild(send);
		panel.appendChild(head); panel.appendChild(log); panel.appendChild(form);
		root.appendChild(fab); root.appendChild(panel);
		document.body.appendChild(host);

		function draw(item) {
			var bubble = document.createElement('div');
			bubble.className = 'm ' + (item.role === 'me' ? 'me' : 'bot');
			bubble.dir = 'auto';
			bubble.textContent = item.text;
			if (item.stale) { var old = document.createElement('div'); old.className = 'old'; old.textContent = text.outdated; bubble.appendChild(old); }
			var links = (item.sources || []).filter(function (s) { return safeUrl(s.url); });
			if (links.length && config.show_sources !== false) {
				var src = document.createElement('div'); src.className = 'src'; src.textContent = text.sources;
				links.slice(0, 3).forEach(function (s) {
					var a = document.createElement('a'); a.href = safeUrl(s.url); a.textContent = s.title || s.url; a.target = '_blank'; a.rel = 'noopener nofollow';
					src.appendChild(a);
				});
				bubble.appendChild(src);
			}
			log.appendChild(bubble);
			log.scrollTop = log.scrollHeight;
		}
		function add(item) { saved.items.push(item); saved.items = saved.items.slice(-40); write(saved); draw(item); }

		function setOpen(open) {
			panel.classList.toggle('open', open);
			fab.setAttribute('aria-expanded', String(open));
			if (open) {
				if (!log.childNodes.length) {
					if (saved.items.length) saved.items.forEach(draw);
					else draw({ role: 'bot', text: pick(config.welcome, lang, text.welcome) });
				}
				input.focus();
			} else fab.focus();
		}
		fab.addEventListener('click', function () { setOpen(!panel.classList.contains('open')); });
		closeBtn.addEventListener('click', function () { setOpen(false); });
		root.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : submit(); } });
		form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
		document.addEventListener('click', function (e) { var t = e.target; if (t && t.closest && t.closest('.js-wia-open')) { e.preventDefault(); setOpen(true); } });

		function submit() {
			var message = input.value.trim();
			if (!message || busy) return;
			busy = true; send.disabled = true; input.value = '';
			add({ role: 'me', text: message });
			var typing = document.createElement('div'); typing.className = 'm bot typing'; typing.textContent = text.typing; log.appendChild(typing); log.scrollTop = log.scrollHeight;
			fetch(endpoint + '/chat', {
				method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'omit',
				body: JSON.stringify({ site_key: siteKey, message: message, conversation_id: saved.conversationId })
			}).then(function (r) {
				if (r.status === 429) throw new Error('limited');
				if (!r.ok) throw new Error('http');
				return r.json();
			}).then(function (data) {
				saved.conversationId = data.conversation_id || saved.conversationId;
				typing.remove();
				add({ role: 'bot', text: String(data.answer || ''), sources: data.sources || [], stale: !!data.stale });
			}).catch(function (err) {
				typing.remove();
				draw({ role: 'bot', text: err.message === 'limited' ? text.limited : text.error });
			}).then(function () { busy = false; send.disabled = false; });
		}
	}

	function init() {
		fetch(endpoint + '/widget' + (siteKey ? '?site_key=' + encodeURIComponent(siteKey) : ''), { credentials: 'omit' })
			.then(function (r) { if (!r.ok) throw new Error('config'); return r.json(); })
			.then(start)
			.catch(function () { /* the host site must keep working if the assistant is unavailable */ });
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
