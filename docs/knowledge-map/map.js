import { languages, nodes, edges } from './content.js';

const $ = (id) => document.getElementById(id);
const requested = new URLSearchParams(location.search).get('lang');
const lang = Object.hasOwn(languages, requested) ? requested : 'en';
const dir = lang === 'en' ? 'ltr' : 'rtl';
const copy = languages[lang];
const number = new Intl.NumberFormat(lang);
const label = (node) => node.label[lang];
const byId = new Map(nodes.map((node) => [node.id, node]));
const buttons = new Map();
const map = $('map');
const viewport = $('map-window');
const canvas = $('edges');
const context = canvas.getContext('2d');
const search = $('search');
const steps = [...document.querySelectorAll('[data-step]')];
let selected = 'packs';
let matches = [];
let step = 3;
let zoom = 1;
let timer;

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
    showStep(i + 1, true);
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
  button.style.left = node.x + '%';
  button.style.top = node.y + '%';
  button.setAttribute('aria-label', label(node) + ' — ' + copy[node.kind]);
  const caption = document.createElement('span');
  caption.className = 'node-label';
  caption.textContent = label(node);
  caption.dir = dir;
  button.append(symbol(node.kind), caption);
  button.addEventListener('click', () => select(node.id));
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
  const near = new Set([selected, ...connected(selected)]);
  if (canvas.width !== Math.round(width * ratio) || canvas.height !== Math.round(height * ratio)) {
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
  }
  context.setTransform(ratio, 0, 0, ratio, 0, 0);
  context.clearRect(0, 0, width, height);
  const colors = { related: '#a76624', learned: '#197448', page: '#267286' };
  for (const [from, to, type = 'page'] of edges) {
    if (!visible(from) || !visible(to)) continue;
    const a = byId.get(from);
    const b = byId.get(to);
    const active = from === selected || to === selected;
    context.strokeStyle = colors[type];
    context.globalAlpha = active ? .8 : .23;
    context.lineWidth = active ? 2 : 1.3;
    context.beginPath();
    context.moveTo(width * a.x / 100, height * a.y / 100);
    context.lineTo(width * b.x / 100, height * b.y / 100);
    context.stroke();
  }
  for (const [id, button] of buttons) {
    button.hidden = !visible(id);
    button.setAttribute('aria-pressed', String(id === selected));
    button.classList.toggle('is-match', matches.includes(id));
    button.classList.toggle('is-muted', !!matches.length && !matches.includes(id) && !near.has(id));
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

function changeZoom(size) {
  const x = (viewport.scrollLeft + viewport.clientWidth / 2) / zoom;
  const y = (viewport.scrollTop + viewport.clientHeight / 2) / zoom;
  zoom = size;
  map.style.setProperty('--map-size', zoom * 100 + '%');
  viewport.scrollLeft = x * zoom - viewport.clientWidth / 2;
  viewport.scrollTop = y * zoom - viewport.clientHeight / 2;
  $('zoom-out').disabled = zoom === 1;
  $('fit').disabled = zoom === 1;
  $('zoom-in').disabled = zoom === 2;
  paint();
}

function showStep(value, focusExample = false) {
  step = value;
  updateSearch();
  if (focusExample) selected = ['weekender-gap', 'travel-page', 'travel-page', 'weekender'][step];
  $('replay-status').textContent = copy.replaySteps[value];
  steps.forEach((button, i) => {
    button.classList.toggle('is-complete', i < value);
    if (i === value - 1) button.setAttribute('aria-current', 'step');
    else button.removeAttribute('aria-current');
  });
  updateDetails();
  paint();
}

$('replay').addEventListener('click', () => {
  clearInterval(timer);
  changeZoom(1);
  search.value = '';
  showStep(0, true);
  timer = setInterval(() => {
    showStep(step + 1, true);
    if (step === 3) clearInterval(timer);
  }, 1600);
});
$('zoom-out').addEventListener('click', () => changeZoom(Math.max(1, zoom - .25)));
$('zoom-in').addEventListener('click', () => changeZoom(Math.min(2, zoom + .25)));
$('fit').addEventListener('click', () => changeZoom(1));
new ResizeObserver(paint).observe(map);
changeZoom(1);
showStep(3);
