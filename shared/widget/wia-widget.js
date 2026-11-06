/*!
 * Website Intelligence Agent - chat widget. No dependencies. Used by both editions.
 *
 * Self-hosted: <script src="https://YOUR-ENGINE/widget/wia-widget.js" data-endpoint="https://YOUR-ENGINE/public/v1" data-site-key="KEY" defer></script>
 * WordPress:   the plugin sets window.WIA_WIDGET = { endpoint, siteKey, config }.
 *
 * Runs inside a Shadow DOM so site styles cannot break it and it cannot break the site. All server text is
 * inserted with textContent, and only http(s), tel: and mailto: links are rendered, so a crawled page cannot
 * inject markup.
 *
 * Performance: at page load only the launcher button exists. The chat panel, its styles and any custom font
 * are created on first open. The WordPress edition passes its settings inline, so no request is made until
 * the visitor sends a message.
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
		fa: { title: 'دستیار سایت', status: 'پاسخ‌گویی فوری', placeholder: 'سؤال خود را بنویسید…', send: 'ارسال', open: 'گفتگو با دستیار', close: 'بستن', restart: 'گفتگوی تازه', sources: 'منبع', error: 'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.', limited: 'پیام‌ها زیاد شد. لطفاً کمی بعد دوباره تلاش کنید.', welcome: 'سلام! درباره این سایت چه سؤالی دارید؟', typing: 'در حال نوشتن', outdated: 'این پاسخ ممکن است قدیمی باشد.', faq: 'پرسش‌های پرتکرار', teaser: 'سلام 👋 سؤالی دارید؟ همین‌جا بپرسید.', dismiss: 'بستن پیام' },
		ar: { title: 'مساعد الموقع', status: 'رد فوري', placeholder: 'اكتب سؤالك…', send: 'إرسال', open: 'تحدث مع المساعد', close: 'إغلاق', restart: 'محادثة جديدة', sources: 'المصدر', error: 'تعذر الاتصال. يرجى المحاولة مرة أخرى.', limited: 'رسائل كثيرة. يرجى المحاولة بعد قليل.', welcome: 'مرحبا! ما سؤالك عن هذا الموقع؟', typing: 'يكتب', outdated: 'قد تكون هذه الإجابة قديمة.', faq: 'أسئلة شائعة', teaser: 'مرحبا 👋 لديك سؤال؟ اسألني هنا.', dismiss: 'إغلاق الرسالة' },
		en: { title: 'Site assistant', status: 'Replies instantly', placeholder: 'Type your question…', send: 'Send', open: 'Chat with the assistant', close: 'Close', restart: 'New conversation', sources: 'Source', error: 'Could not connect. Please try again.', limited: 'Too many messages. Please try again shortly.', welcome: 'Hello! What would you like to know about this site?', typing: 'Typing', outdated: 'This answer may be out of date.', faq: 'Common questions', teaser: 'Hi 👋 Have a question? Ask me here.', dismiss: 'Dismiss' }
	};
	var STORE = 'wia_chat_' + siteKey;
	var TEASED = 'wia_teased_' + siteKey;
	var MAX_AGE = 2 * 3600 * 1000;
	// Persian fonts: Yekan Bakh when the site has it (or the owner gave its file), then Vazirmatn, then system fonts.
	var FONTS = '"Yekan Bakh","YekanBakh","YekanBakhFaNum","Yekan Bakh FaNum",Vazirmatn,Vazir,Tahoma,system-ui,-apple-system,"Segoe UI",sans-serif';
	var ICON_AI = '<svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor" aria-hidden="true"><path d="M10 2.5l1.6 4.7a3 3 0 0 0 1.9 1.9L18.2 10.7l-4.7 1.6a3 3 0 0 0-1.9 1.9L10 18.9l-1.6-4.7a3 3 0 0 0-1.9-1.9L1.8 10.7l4.7-1.6a3 3 0 0 0 1.9-1.9z"/><path d="M18.5 13.5l.8 2.2a1.6 1.6 0 0 0 1 1l2.2.8-2.2.8a1.6 1.6 0 0 0-1 1l-.8 2.2-.8-2.2a1.6 1.6 0 0 0-1-1l-2.2-.8 2.2-.8a1.6 1.6 0 0 0 1-1z" opacity=".85"/><path d="M19 1.5l.5 1.4a1 1 0 0 0 .6.6l1.4.5-1.4.5a1 1 0 0 0-.6.6L19 6.5l-.5-1.4a1 1 0 0 0-.6-.6l-1.4-.5 1.4-.5a1 1 0 0 0 .6-.6z" opacity=".7"/></svg>';
	var ICON_SEND = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>';
	var ICON_CLOSE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
	var ICON_RESTART = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>';

	function read() { try { var d = JSON.parse(localStorage.getItem(STORE) || 'null'); return d && Date.now() - d.at < MAX_AGE ? d : null; } catch (e) { return null; } }
	function write(state) { try { state.at = Date.now(); localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* private mode */ } }
	function flag(key, set) { try { if (set) sessionStorage.setItem(key, '1'); return sessionStorage.getItem(key) === '1'; } catch (e) { return false; } }
	function isRtl(lang) { return lang === 'fa' || lang === 'ar'; }
	function safeUrl(url) { return /^https?:\/\//i.test(String(url || '')) ? String(url) : ''; }
	// Contact buttons may also call or write: tel: digits only, mailto: without markup characters.
	function safeAction(url) { url = String(url || ''); return safeUrl(url) || (/^tel:\+?[0-9]+$/i.test(url) || /^mailto:[^\s<>"']+$/i.test(url) ? url : ''); }
	function pick(map, lang, fallback) { return (map && typeof map[lang] === 'string' && map[lang].trim()) ? map[lang] : fallback; }
	function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
	function reduced() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

	function start(config) {
		if (!config || config.enabled === false) return;
		var lang = config.language && UI[config.language] ? config.language : 'fa';
		var text = UI[lang];
		var color = /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(config.color || '') ? config.color : '#2563eb';
		var side = config.position === 'left' ? 'left' : 'right';
		var dir = isRtl(lang) ? 'rtl' : 'ltr';
		var saved = read() || { items: [], conversationId: null };
		var busy = false;
		var panel = null;
		var ui = {};

		var host = document.createElement('div');
		host.id = 'wia-widget';
		host.style.cssText = 'all:initial';
		var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
		var base = document.createElement('style');
		// Only what the launcher and the teaser need. The panel's styles are added on first open.
		base.textContent =
			':host{all:initial}*{box-sizing:border-box;font-family:' + FONTS + '}' +
			'.fab{position:fixed;bottom:20px;' + side + ':20px;width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;z-index:2147483000;display:flex;align-items:center;justify-content:center;color:#fff;' +
			'background:' + color + ';background:linear-gradient(135deg,' + color + ',color-mix(in srgb,' + color + ' 65%,#000));box-shadow:0 8px 24px color-mix(in srgb,' + color + ' 45%,transparent),0 2px 6px rgba(0,0,0,.18);transition:transform .2s ease,box-shadow .2s ease}' +
			'.fab:hover{transform:translateY(-2px) scale(1.04)}.fab:active{transform:scale(.96)}.fab:focus-visible,button:focus-visible,textarea:focus-visible,a:focus-visible{outline:3px solid #111;outline-offset:2px}' +
			'.fab .ic{display:flex;transition:transform .25s ease,opacity .2s}.fab.on .ai{transform:rotate(90deg) scale(.4);opacity:0;position:absolute}.fab .x{position:absolute;opacity:0;transform:rotate(-90deg) scale(.4)}.fab.on .x{opacity:1;transform:none}' +
			'.fab:before{content:"";position:absolute;inset:0;border-radius:50%;background:' + color + ';opacity:0;z-index:-1}.fab.pulse:before{animation:ring 2.4s ease-out 3}' +
			'@keyframes ring{0%{transform:scale(1);opacity:.45}100%{transform:scale(1.7);opacity:0}}' +
			'.teaser{position:fixed;bottom:92px;' + side + ':20px;max-width:min(280px,calc(100vw - 40px));background:#fff;color:#1f2937;border-radius:16px;padding:12px 34px 12px 14px;box-shadow:0 10px 30px rgba(0,0,0,.16),0 1px 3px rgba(0,0,0,.08);font-size:14px;line-height:1.7;cursor:pointer;z-index:2147483000;opacity:0;transform:translateY(8px) scale(.98);transition:opacity .25s ease,transform .25s ease}' +
			'.teaser[dir=rtl]{padding:12px 14px 12px 34px}.teaser.show{opacity:1;transform:none}.teaser:after{content:"";position:absolute;bottom:-6px;' + side + ':24px;width:12px;height:12px;background:#fff;transform:rotate(45deg);box-shadow:3px 3px 4px rgba(0,0,0,.05)}' +
			'.teaser .tx{position:absolute;top:6px;' + (dir === 'rtl' ? 'left' : 'right') + ':6px;width:22px;height:22px;border:0;border-radius:50%;background:#f3f4f6;color:#6b7280;cursor:pointer;font-size:14px;line-height:22px;padding:0}' +
			'@media (prefers-reduced-motion:reduce){.fab,.fab .ic,.teaser{transition:none}.fab.pulse:before{animation:none}}';
		root.appendChild(base);

		var fab = el('button', 'fab');
		fab.type = 'button';
		fab.setAttribute('aria-label', text.open);
		fab.setAttribute('aria-expanded', 'false');
		fab.innerHTML = '<span class="ic ai">' + ICON_AI + '</span><span class="ic x">' + ICON_CLOSE + '</span>';
		root.appendChild(fab);
		document.body.appendChild(host);

		/* ---------- teaser bubble above the launcher ---------- */
		var teaser = null;
		var delay = Math.max(0, Math.min(120, parseInt(config.teaser_delay, 10)));
		if (isNaN(delay)) delay = 4;
		function hideTeaser(remember) {
			if (!teaser) return;
			var t = teaser; teaser = null;
			t.classList.remove('show');
			setTimeout(function () { t.remove(); }, 260);
			if (remember) flag(TEASED, true);
		}
		if (delay > 0 && !flag(TEASED) && !saved.items.length) {
			setTimeout(function () {
				if (panel && panel.classList.contains('open')) return;
				teaser = el('div', 'teaser');
				teaser.dir = dir;
				teaser.setAttribute('role', 'status');
				teaser.appendChild(document.createTextNode(pick(config.teaser, lang, text.teaser)));
				var tx = el('button', 'tx', '×'); tx.type = 'button'; tx.setAttribute('aria-label', text.dismiss);
				tx.addEventListener('click', function (e) { e.stopPropagation(); hideTeaser(true); });
				teaser.appendChild(tx);
				teaser.addEventListener('click', function () { setOpen(true); });
				root.appendChild(teaser);
				requestAnimationFrame(function () { requestAnimationFrame(function () { if (teaser) teaser.classList.add('show'); }); });
				if (!reduced()) fab.classList.add('pulse');
			}, delay * 1000);
		}

		/* ---------- the panel, built on first open ---------- */
		function loadFont() {
			var url = safeUrl(config.font_url);
			if (!url || document.getElementById('wia-font')) return;
			// @font-face does not work inside a shadow root, so the face is declared on the page itself.
			var face = document.createElement('style');
			face.id = 'wia-font';
			face.textContent = '@font-face{font-family:"Yekan Bakh";src:url("' + url.replace(/["\\\n]/g, '') + '");font-display:swap}';
			document.head.appendChild(face);
		}

		function build() {
			loadFont();
			var css = document.createElement('style');
			css.textContent =
				'.panel{position:fixed;bottom:92px;' + side + ':20px;width:380px;max-width:calc(100vw - 24px);height:600px;max-height:calc(100vh - 120px);background:#fff;color:#1f2937;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.22),0 2px 8px rgba(0,0,0,.08);display:none;flex-direction:column;overflow:hidden;z-index:2147483000}' +
				'.panel.open{display:flex}' +
				'.head{background:' + color + ';background:linear-gradient(135deg,' + color + ',color-mix(in srgb,' + color + ' 70%,#000));color:#fff;padding:14px 14px 14px 16px;display:flex;align-items:center;gap:11px}' +
				'.av{width:40px;height:40px;flex:none;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;overflow:hidden}.av img{width:100%;height:100%;object-fit:cover}.av svg{width:22px;height:22px}' +
				'.who{flex:1;min-width:0}.who strong{display:block;font-size:15.5px;font-weight:700;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.who small{display:flex;align-items:center;gap:6px;font-size:12px;opacity:.9;line-height:1.5}.who small:before{content:"";width:7px;height:7px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 2px rgba(74,222,128,.3)}' +
				'.hb{width:34px;height:34px;flex:none;border:0;border-radius:10px;background:transparent;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center}.hb:hover{background:rgba(255,255,255,.16)}' +
				'.log{flex:1;overflow-y:auto;overscroll-behavior:contain;padding:16px 14px 8px;display:flex;flex-direction:column;gap:10px;background:#f7f8fa}' +
				'.m{max-width:85%;padding:10px 13px;border-radius:16px;font-size:14.5px;line-height:1.85;white-space:pre-wrap;overflow-wrap:anywhere}' +
				'.bot{background:#fff;color:#1f2937;align-self:flex-start;border-' + (dir === 'rtl' ? 'top-right' : 'top-left') + '-radius:5px;box-shadow:0 1px 2px rgba(0,0,0,.06)}' +
				'.me{background:' + color + ';color:#fff;align-self:flex-end;border-' + (dir === 'rtl' ? 'top-left' : 'top-right') + '-radius:5px}' +
				'.in{animation:up .22s ease-out}@keyframes up{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}' +
				'.src{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.src a{display:inline-flex;align-items:center;gap:4px;max-width:100%;font-size:12px;color:' + color + ';background:color-mix(in srgb,' + color + ' 9%,#fff);border-radius:8px;padding:3px 8px;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.src a:hover{text-decoration:underline}' +
				'.old{margin-top:6px;font-size:12px;color:#92400e}' +
				'.dots{display:inline-flex;gap:5px;align-items:center;height:22px;padding:0 2px}.dots i{width:7px;height:7px;border-radius:50%;background:#9ca3af;animation:dot 1.2s infinite ease-in-out}.dots i:nth-child(2){animation-delay:.15s}.dots i:nth-child(3){animation-delay:.3s}' +
				'@keyframes dot{0%,60%,100%{transform:translateY(0);opacity:.45}30%{transform:translateY(-5px);opacity:1}}' +
				'.chips{display:flex;flex-wrap:wrap;gap:7px;align-self:stretch}.cap{width:100%;font-size:12px;color:#6b7280;margin:2px 2px 0}' +
				'.chip{border:1px solid color-mix(in srgb,' + color + ' 35%,#fff);background:#fff;color:' + color + ';border-radius:999px;padding:6px 13px;font-size:13.5px;cursor:pointer;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;transition:background .15s,color .15s}.chip:hover{background:' + color + ';color:#fff;border-color:' + color + '}' +
				'.acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}.act{display:inline-block;background:' + color + ';color:#fff;text-decoration:none;border-radius:10px;padding:6px 12px;font-size:13px}' +
				'form{display:flex;align-items:flex-end;gap:8px;padding:10px 12px 12px;border-top:1px solid #eef0f3;background:#fff}' +
				'textarea{flex:1;resize:none;min-height:44px;max-height:120px;padding:11px 14px;border:1px solid #e5e7eb;border-radius:14px;font-size:14.5px;line-height:1.5;color:#111;background:#f9fafb;outline:none;transition:border-color .15s,background .15s}textarea:focus,textarea:focus-visible{outline:none;border-color:' + color + ';background:#fff;box-shadow:0 0 0 3px color-mix(in srgb,' + color + ' 18%,transparent)}' +
				'.send{width:44px;height:44px;flex:none;border:0;border-radius:14px;background:' + color + ';color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:opacity .15s,transform .15s}.send:disabled{opacity:.4;cursor:default}.send:not(:disabled):active{transform:scale(.94)}.send svg{transform:' + (dir === 'rtl' ? 'scaleX(-1)' : 'none') + '}' +
				'@media (max-width:480px){.panel{inset:0;bottom:0;width:100vw;max-width:100vw;height:100dvh;max-height:100dvh;border-radius:0}.head{padding-top:max(14px,env(safe-area-inset-top))}form{padding-bottom:max(12px,env(safe-area-inset-bottom))}textarea{font-size:16px}.fab.on{display:none}}' +
				'@media (prefers-reduced-motion:no-preference){.panel.open{animation:pop .2s ease-out}@keyframes pop{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}}' +
				'@media (prefers-reduced-motion:reduce){.in,.dots i{animation:none}}';
			root.insertBefore(css, fab);

			panel = el('div', 'panel');
			panel.dir = dir;
			panel.setAttribute('role', 'dialog');
			panel.setAttribute('aria-label', pick(config.title, lang, text.title));
			var head = el('div', 'head');
			var av = el('div', 'av');
			var avatar = safeUrl(config.avatar_url);
			if (avatar) { var img = document.createElement('img'); img.src = avatar; img.alt = ''; av.appendChild(img); } else av.innerHTML = ICON_AI;
			var who = el('div', 'who');
			who.appendChild(el('strong', '', pick(config.title, lang, text.title)));
			who.appendChild(el('small', '', text.status));
			var restart = el('button', 'hb'); restart.type = 'button'; restart.innerHTML = ICON_RESTART; restart.setAttribute('aria-label', text.restart); restart.title = text.restart;
			var close = el('button', 'hb'); close.type = 'button'; close.innerHTML = ICON_CLOSE; close.setAttribute('aria-label', text.close);
			head.appendChild(av); head.appendChild(who); head.appendChild(restart); head.appendChild(close);
			ui.log = el('div', 'log'); ui.log.setAttribute('aria-live', 'polite');
			var form = document.createElement('form');
			ui.input = document.createElement('textarea');
			ui.input.placeholder = text.placeholder; ui.input.maxLength = 1000; ui.input.rows = 1; ui.input.dir = 'auto';
			ui.input.setAttribute('aria-label', text.placeholder);
			ui.send = el('button', 'send'); ui.send.type = 'submit'; ui.send.innerHTML = ICON_SEND; ui.send.setAttribute('aria-label', text.send); ui.send.disabled = true;
			form.appendChild(ui.input); form.appendChild(ui.send);
			panel.appendChild(head); panel.appendChild(ui.log); panel.appendChild(form);
			root.appendChild(panel);

			close.addEventListener('click', function () { setOpen(false); });
			restart.addEventListener('click', function () {
				if (busy) return;
				saved = { items: [], conversationId: null }; write(saved);
				while (ui.log.firstChild) ui.log.removeChild(ui.log.firstChild);
				welcome();
				ui.input.focus();
			});
			ui.input.addEventListener('input', function () {
				ui.send.disabled = busy || !ui.input.value.trim();
				ui.input.style.height = 'auto';
				ui.input.style.height = Math.min(120, ui.input.scrollHeight + 2) + 'px';
			});
			ui.input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(); } });
			form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
			panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });

			if (saved.items.length) saved.items.forEach(function (item) { draw(item, false); });
			else welcome();
		}

		function welcome() {
			draw({ role: 'bot', text: pick(config.welcome, lang, text.welcome), suggestions: config.starters || [], caption: text.faq }, true);
		}

		var chipRow = null;
		// Suggested replies under the latest answer. Choosing one sends it as the visitor's message.
		function chips(list, caption) {
			if (chipRow) { chipRow.remove(); chipRow = null; }
			list = (list || []).filter(function (x) { return typeof x === 'string' && x.trim(); }).slice(0, 6);
			if (!list.length) return;
			chipRow = el('div', 'chips in');
			if (caption) chipRow.appendChild(el('div', 'cap', caption));
			list.forEach(function (label) {
				var b = el('button', 'chip', label); b.type = 'button'; b.title = label;
				b.addEventListener('click', function () { if (!busy) { ui.input.value = label; submit(); } });
				chipRow.appendChild(b);
			});
			ui.log.appendChild(chipRow);
		}

		function draw(item, animate) {
			var bubble = el('div', 'm ' + (item.role === 'me' ? 'me' : 'bot') + (animate ? ' in' : ''), item.text);
			bubble.dir = 'auto';
			if (item.stale) bubble.appendChild(el('div', 'old', text.outdated));
			var links = (item.sources || []).filter(function (s) { return s && safeUrl(s.url); });
			if (links.length && config.show_sources !== false) {
				var src = el('div', 'src');
				links.slice(0, 3).forEach(function (s) {
					var a = el('a', '', '↗ ' + (s.title || text.sources)); a.href = safeUrl(s.url); a.target = '_blank'; a.rel = 'noopener nofollow'; a.title = s.title || s.url;
					src.appendChild(a);
				});
				bubble.appendChild(src);
			}
			var acts = (item.actions || []).filter(function (a) { return a && safeAction(a.url); });
			if (acts.length) {
				var box = el('div', 'acts');
				acts.slice(0, 5).forEach(function (a) {
					var link = el('a', 'act', String(a.label || a.url)); link.href = safeAction(a.url); link.rel = 'noopener nofollow';
					if (safeUrl(a.url)) link.target = '_blank';
					box.appendChild(link);
				});
				bubble.appendChild(box);
			}
			ui.log.appendChild(bubble);
			if (item.role !== 'me') chips(item.suggestions, item.caption);
			ui.log.scrollTop = ui.log.scrollHeight;
		}
		function add(item) { saved.items.push(item); saved.items = saved.items.slice(-40); write(saved); draw(item, true); }

		var scrollLock = '';
		function setOpen(open) {
			if (open && !panel) build();
			hideTeaser(true);
			fab.classList.remove('pulse');
			panel.classList.toggle('open', open);
			fab.classList.toggle('on', open);
			fab.setAttribute('aria-expanded', String(open));
			fab.setAttribute('aria-label', open ? text.close : text.open);
			// On a phone the panel covers the screen; the page behind it must not scroll.
			var phone = window.matchMedia && window.matchMedia('(max-width:480px)').matches;
			if (open && phone) { scrollLock = document.documentElement.style.overflow; document.documentElement.style.overflow = 'hidden'; }
			else if (!open) document.documentElement.style.overflow = scrollLock;
			if (open) { ui.log.scrollTop = ui.log.scrollHeight; ui.input.focus(); } else fab.focus();
		}
		fab.addEventListener('click', function () { setOpen(!(panel && panel.classList.contains('open'))); });
		document.addEventListener('click', function (e) { var t = e.target; if (t && t.closest && t.closest('.js-wia-open')) { e.preventDefault(); setOpen(true); } });

		function submit() {
			var message = ui.input.value.trim();
			if (!message || busy) return;
			busy = true; ui.send.disabled = true; ui.input.value = ''; ui.input.style.height = '';
			if (chipRow) { chipRow.remove(); chipRow = null; }
			add({ role: 'me', text: message });
			var typing = el('div', 'm bot in typing');
			typing.setAttribute('aria-label', text.typing);
			typing.innerHTML = '<span class="dots"><i></i><i></i><i></i></span>';
			ui.log.appendChild(typing); ui.log.scrollTop = ui.log.scrollHeight;
			var started = Date.now();
			fetch(endpoint + '/chat', {
				method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'omit',
				body: JSON.stringify({ site_key: siteKey, message: message, conversation_id: saved.conversationId })
			}).then(function (r) {
				if (r.status === 429) throw new Error('limited');
				if (!r.ok) throw new Error('http');
				return r.json();
			}).then(function (data) {
				saved.conversationId = data.conversation_id || saved.conversationId;
				var answer = String(data.answer || '');
				// The Lite edition answers in milliseconds. A short pause, longer for longer answers, reads as a reply
				// rather than a page flicker.
				var wait = Math.max(0, Math.min(1300, 450 + answer.length * 4) - (Date.now() - started));
				setTimeout(function () {
					typing.remove();
					add({ role: 'bot', text: answer, sources: data.sources || [], stale: !!data.stale, suggestions: data.suggestions || [], actions: data.actions || [] });
					done();
				}, reduced() ? Math.min(wait, 250) : wait);
			}).catch(function (err) {
				typing.remove();
				draw({ role: 'bot', text: err.message === 'limited' ? text.limited : text.error }, true);
				done();
			});
		}
		function done() { busy = false; ui.send.disabled = !ui.input.value.trim(); }
	}

	function init() {
		if (boot.config) { start(boot.config); return; }
		fetch(endpoint + '/widget' + (siteKey ? '?site_key=' + encodeURIComponent(siteKey) : ''), { credentials: 'omit' })
			.then(function (r) { if (!r.ok) throw new Error('config'); return r.json(); })
			.then(start)
			.catch(function () { /* the host site must keep working if the assistant is unavailable */ });
	}
	// Nothing competes with the page's own loading: the widget starts once the browser is idle.
	function later() { (window.requestIdleCallback || function (f) { setTimeout(f, 200); })(init, { timeout: 2500 }); }
	if (document.readyState === 'complete') later(); else window.addEventListener('load', later);
})();
