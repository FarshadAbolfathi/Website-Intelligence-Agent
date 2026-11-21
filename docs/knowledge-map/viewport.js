const clamp = (value, min, max) => Math.max(min, Math.min(value, max));

export function installViewport({ viewport, map, controls, moveNode }) {
  const view = { x: 0, y: 0, scale: 1 };
  const pointers = new Map();
  let drag;
  let pinch;
  let suppressClick = false;

  function update() {
    view.x = clamp(view.x, viewport.clientWidth * (.25 - view.scale), viewport.clientWidth * .75);
    view.y = clamp(view.y, viewport.clientHeight * (.25 - view.scale), viewport.clientHeight * .75);
    map.style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.scale})`;
    controls.out.disabled = view.scale <= .7;
    controls.in.disabled = view.scale >= 2.7;
  }

  function zoom(next, anchor, previous = anchor) {
    const rect = viewport.getBoundingClientRect();
    const point = anchor || { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
    const old = previous || point;
    const scale = clamp(next, .7, 2.7);
    const ratio = scale / view.scale;
    view.x = point.x - rect.left - (old.x - rect.left - view.x) * ratio;
    view.y = point.y - rect.top - (old.y - rect.top - view.y) * ratio;
    view.scale = scale;
    update();
  }

  function fit() {
    Object.assign(view, { x: 0, y: 0, scale: 1 });
    update();
  }

  function pinchGeometry() {
    const [a, b] = pointers.values();
    return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, distance: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)) };
  }

  viewport.addEventListener('pointerdown', (event) => {
    if (event.button !== 0 || pointers.size >= 2) return;
    const node = event.target.closest('.node');
    const point = { x: event.clientX, y: event.clientY };
    pointers.set(event.pointerId, point);
    (node || viewport).setPointerCapture(event.pointerId);
    if (pointers.size === 2) {
      pinch = pinchGeometry();
      drag = null;
      suppressClick = true;
    } else {
      suppressClick = false;
      drag = { id: event.pointerId, point, node, moved: false };
    }
  });

  viewport.addEventListener('pointermove', (event) => {
    const before = pointers.get(event.pointerId);
    if (!before) return;
    const point = { x: event.clientX, y: event.clientY };
    pointers.set(event.pointerId, point);
    if (pinch && pointers.size === 2) {
      const next = pinchGeometry();
      zoom(view.scale * next.distance / pinch.distance, next, pinch);
      pinch = next;
      return;
    }
    if (!drag || drag.id !== event.pointerId) return;
    if (!drag.moved && Math.hypot(point.x - drag.point.x, point.y - drag.point.y) < 5) return;
    const previous = drag.moved ? before : drag.point;
    const dx = point.x - previous.x;
    const dy = point.y - previous.y;
    drag.moved = true;
    suppressClick = true;
    viewport.classList.add('is-dragging');
    if (drag.node) {
      moveNode(drag.node.dataset.node, dx / (map.clientWidth * view.scale) * 100, dy / (map.clientHeight * view.scale) * 100);
    } else {
      view.x += dx;
      view.y += dy;
      update();
    }
  });

  function release(event) {
    pointers.delete(event.pointerId);
    if (pointers.size < 2) pinch = null;
    if (drag?.id === event.pointerId) drag = null;
    if (!pointers.size) viewport.classList.remove('is-dragging');
  }
  viewport.addEventListener('pointerup', release);
  viewport.addEventListener('pointercancel', release);
  viewport.addEventListener('lostpointercapture', release);
  viewport.addEventListener('click', (event) => {
    if (suppressClick && event.detail) {
      event.preventDefault();
      event.stopPropagation();
      suppressClick = false;
    }
  }, true);

  viewport.addEventListener('wheel', (event) => {
    event.preventDefault();
    zoom(view.scale * Math.exp(-clamp(event.deltaY, -120, 120) * .002),
      { x: event.clientX, y: event.clientY });
  }, { passive: false });

  viewport.addEventListener('keydown', (event) => {
    if (event.target !== viewport) return;
    const shift = { ArrowLeft: [35, 0], ArrowRight: [-35, 0], ArrowUp: [0, 35], ArrowDown: [0, -35] }[event.key];
    if (shift) {
      view.x += shift[0];
      view.y += shift[1];
      update();
    } else if (event.key === '+' || event.key === '=') zoom(view.scale * 1.2);
    else if (event.key === '-') zoom(view.scale / 1.2);
    else if (event.key === 'Home' || event.key === '0') fit();
    else return;
    event.preventDefault();
  });

  controls.in.addEventListener('click', () => zoom(view.scale * 1.25));
  controls.out.addEventListener('click', () => zoom(view.scale / 1.25));
  fit();
  return { fit };
}
