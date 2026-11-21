import { languages, nodes, edges } from './content.js?v=2';
import { installViewport } from './viewport.js';

const $ = (id) => document.getElementById(id);
const requested = new URLSearchParams(location.search).get('lang');
const lang = Object.hasOwn(languages, requested) ? requested : 'en';
const dir = lang === 'en' ? 'ltr' : 'rtl';
const copy = languages[lang];
const number = new Intl.NumberFormat(lang);
const label = (node) => node.label[lang];
const byId = new Map(nodes.map((node) => [node.id, { ...node }]));
const buttons = new Map();
const map = $('map');
const viewport = $('map-window');
const canvas = $('edges');
const context = canvas.getContext('2d');
const search = $('search');
const traveler = $('traveler');
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
const steps = [...document.querySelectorAll('[data-step]')];
let selected = 'packs';
let matches = [];
let step = 0;
let timer;
let frame;
let motion = null;
let hovered = null;

document.documentElement.lang = lang;
document.documentElement.dir = dir;
document.title = copy.title + ' · Website Intelligence Agent';
document.querySelectorAll('[data-i18n]').forEach((element) => {
  const value = copy[element.dataset.i18n];
  if (element.dataset.attr) {
    element.setAttribute(element.dataset.attr, value);
  } else {
    element.textContent = value;
  }
});
document.querySelectorAll('[data-lang]').forEach((link) => {
  if (link.dataset.lang === lang) link.setAttribute('aria-current', 'page');
  else link.removeAttribute('aria-current');
});
search.setAttribute('aria-label', copy.search);
steps.forEach((button, i) => {
  button.textContent = number.format(i + 1);
  button.setAttribute('aria-label', copy.step.replace('{n}', button.textContent));
  button.addEventListener('click', () => {
    clearInterval(timer);
    showStep(i + 1, true, true);
  });
});

function symbol(kind) {
  const icon = document.createElement('span');
  icon.className = 'symbol ' + kind;
  icon.setAttribute('aria-hidden', 'true');
  return icon;
}

for (const node of nodes) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'node';
  button.dataset.node = node.id;
  button.dataset.kind = node.kind;
  button.setAttribute('aria-label', label(node) + ' — ' + copy[node.kind]);
  const caption = document.createElement('span');
  caption.className = 'node-label';
  caption.textContent = label(node);
  caption.dir = dir;
  button.append(symbol(node.kind), caption);
  button.addEventListener('click', () => select(node.id));
  button.addEventListener('pointerenter', (event) => {
    if (event.pointerType !== 'mouse') return;
    hovered = node.id;
    paint();
  });
  button.addEventListener('pointerleave', () => {
    if (hovered !== node.id) return;
    hovered = null;
    paint();
  });
  $('nodes').append(button);
  buttons.set(node.id, button);
}

function connected(id) {
  return edges.flatMap(([from, to]) => {
    if (from === id) return [to];
    if (to === id) return [from];
    return [];
  }).filter(visible);
}

function visible(id) {
  if (id === 'weekender') return step === 3;
  if (id === 'weekender-gap') return step < 3;
  return true;
}

function select(id) {
  selected = id;
  updateDetails();
  paint();
}

function updateDetails() {
  const node = byId.get(selected);
  $('node-kind').replaceChildren(symbol(node.kind), document.createTextNode(copy[node.kind]));
  $('inspect-title').textContent = label(node);
  $('node-description').textContent = node.description[lang];
  const links = connected(selected);
  $('connection-title').hidden = !links.length;
  $('connections').replaceChildren(...links.map((id) => {
    const link = document.createElement('button');
    link.type = 'button';
    link.textContent = label(byId.get(id));
    link.addEventListener('click', () => {
      select(id);
      $('inspect-title').tabIndex = -1;
      $('inspect-title').focus({ preventScroll: true });
    });
    return link;
  }));
}

function paint() {
  const width = map.clientWidth;
  const height = map.clientHeight;
  const ratio = window.devicePixelRatio || 1;
  const focus = hovered || selected;
  const near = new Set([focus, ...connected(focus)]);
  if (canvas.width !== Math.round(width * ratio) || canvas.height !== Math.round(height * ratio)) {
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
  }
  context.setTransform(ratio, 0, 0, ratio, 0, 0);
  context.clearRect(0, 0, width, height);
  const colors = { related: '#a76624', learned: '#197448', page: '#267286' };
  for (const [from, to, type = 'page'] of edges) {
    if (!visible(from) || !visible(to) || (motion && type === 'learned')) continue;
    const a = byId.get(from);
    const b = byId.get(to);
    const active = from === focus || to === focus;
    context.strokeStyle = colors[type];
    context.globalAlpha = active ? .88 : hovered ? .12 : .34;
    context.lineWidth = active ? 2 : 1.3;
    context.beginPath();
    context.moveTo(width * a.x / 100, height * a.y / 100);
    context.lineTo(width * b.x / 100, height * b.y / 100);
    context.stroke();
  }
  drawReplay(width, height);
  for (const [id, button] of buttons) {
    const node = byId.get(id);
    button.style.left = node.x + '%';
    button.style.top = node.y + '%';
    button.hidden = !visible(id) || (id === 'weekender' && !!motion);
    button.classList.toggle('is-connected', id !== focus && near.has(id));
    button.setAttribute('aria-pressed', String(id === selected));
    button.classList.toggle('is-match', matches.includes(id));
    button.classList.toggle('is-muted', !near.has(id) && (hovered ? !matches.includes(id) : !!matches.length && !matches.includes(id)));
  }
}

// Normalize common Persian/Arabic spelling variants for the demo's label search.
function normalize(text) {
  return text.normalize('NFKC').toLowerCase()
    .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[ً-ٟـ]/g, '')
    .replace(/[۰-۹٠-٩]/g, (digit) => String(digit.charCodeAt(0) & 15))
    .replace(/[\s‌-]+/g, '').trim();
}

function updateSearch() {
  const value = normalize(search.value);
  matches = value ? nodes.filter((node) => visible(node.id) && normalize(label(node)).includes(value)).map((node) => node.id) : [];
  $('search-status').textContent = value
    ? (matches.length ? copy.matches.replace('{n}', number.format(matches.length)) : copy.noMatches)
    : '';
}

search.addEventListener('input', () => {
  updateSearch();
  paint();
});
search.addEventListener('keydown', (event) => {
  if (event.key === 'Enter' && matches.length) {
    select(matches[0]);
    buttons.get(matches[0]).focus();
  }
});

function drawReplay(width, height) {
  traveler.hidden = !motion;
  if (!motion) return;
  const { progress } = motion;
  const learned = step === 3;
  const start = byId.get('weekender-gap');
  const end = byId.get(learned ? 'weekender' : 'travel-page');
  const middle = { x: Math.min(92, start.x + 18), y: (start.y + end.y) / 2 };
  const t = 1 - (1 - progress) ** 2;
  const x = (1 - t) ** 2 * start.x + 2 * (1 - t) * t * middle.x + t ** 2 * end.x;
  const y = (1 - t) ** 2 * start.y + 2 * (1 - t) * t * middle.y + t ** 2 * end.y;
  traveler.style.left = x + '%';
  traveler.style.top = y + '%';
  traveler.classList.toggle('is-learned', learned);
  traveler.dataset.label = label(byId.get('weekender'));
  traveler.dir = dir;
  context.globalAlpha = .7;
  context.strokeStyle = learned ? '#168359' : '#a65b06';
  context.lineWidth = 2.4;
  context.setLineDash([5, 5]);
  context.beginPath();
  context.moveTo(start.x * width / 100, start.y * height / 100);
  context.quadraticCurveTo(
    ((1 - t) * start.x + t * middle.x) * width / 100,
    ((1 - t) * start.y + t * middle.y) * height / 100,
    x * width / 100, y * height / 100,
  );
  context.stroke();
  context.setLineDash([]);
  if (learned && progress > .72) {
    const target = byId.get('travel-page');
    const link = Math.min(1, (progress - .72) / .28);
    context.beginPath();
    context.moveTo(target.x * width / 100, target.y * height / 100);
    context.lineTo((target.x + (end.x - target.x) * link) * width / 100,
      (target.y + (end.y - target.y) * link) * height / 100);
    context.stroke();
  }
  context.globalAlpha = 1;
}

function animateStep() {
  if (reducedMotion.matches) return;
  motion = { progress: 0 };
  const start = performance.now();
  const duration = step === 3 ? 1300 : 1000;
  function tick(now) {
    motion.progress = Math.min(1, (now - start) / duration);
    if (motion.progress >= 1) motion = null;
    paint();
    if (motion) frame = requestAnimationFrame(tick);
  }
  frame = requestAnimationFrame(tick);
}

function showStep(value, focusExample = false, animate = false) {
  cancelAnimationFrame(frame);
  motion = null;
  hovered = null;
  step = value;
  updateSearch();
  if (focusExample) selected = ['weekender-gap', 'travel-page', 'travel-page', 'weekender'][step];
  $('replay-status').textContent = copy.replaySteps[value];
  steps.forEach((button, i) => {
    button.classList.toggle('is-complete', i < value);
    if (i === value - 1) button.setAttribute('aria-current', 'step');
    else button.removeAttribute('aria-current');
  });
  if (animate && value) animateStep();
  updateDetails();
  paint();
}

const camera = installViewport({
  viewport, map,
  controls: { in: $('zoom-in'), out: $('zoom-out') },
  moveNode(id, dx, dy) {
    cancelAnimationFrame(frame);
    motion = null;
    const node = byId.get(id);
    node.x = Math.max(6, Math.min(94, node.x + dx));
    node.y = Math.max(6, Math.min(92, node.y + dy));
    paint();
  },
});

function resetMap() {
  nodes.forEach(({ id, x, y }) => Object.assign(byId.get(id), { x, y }));
  camera.fit();
  paint();
}
$('fit').addEventListener('click', resetMap);
$('replay').addEventListener('click', () => {
  clearInterval(timer);
  resetMap();
  search.value = '';
  showStep(0, true);
  timer = setInterval(() => {
    showStep(step + 1, true, true);
    if (step === 3) clearInterval(timer);
  }, 1800);
});

const workspace = $('workspace');
const fullscreen = $('fullscreen');
function fullscreenChanged() {
  const active = document.fullscreenElement === workspace || workspace.classList.contains('is-expanded');
  fullscreen.textContent = active ? copy.exitFullscreen : copy.fullscreen;
  fullscreen.setAttribute('aria-pressed', String(active));
  resetMap();
}
fullscreen.addEventListener('click', async () => {
  if (document.fullscreenElement === workspace) {
    await document.exitFullscreen();
    return;
  }
  if (workspace.classList.contains('is-expanded')) {
    workspace.classList.remove('is-expanded');
  } else {
    try {
      await workspace.requestFullscreen();
      return;
    } catch {
      workspace.classList.add('is-expanded');
    }
  }
  fullscreenChanged();
});
document.addEventListener('fullscreenchange', fullscreenChanged);
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && workspace.classList.contains('is-expanded')) {
    workspace.classList.remove('is-expanded');
    fullscreenChanged();
  }
});

new ResizeObserver(paint).observe(map);
showStep(0);
