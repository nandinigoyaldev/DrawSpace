const canvas = document.getElementById('demo-canvas');
const context = canvas.getContext('2d');
const color = document.getElementById('demo-color');
const size = document.getElementById('demo-size');
let tool = 'pen';
let drawing = false;
let last = null;

function resizeCanvas() {
  const ratio = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  const rect = canvas.getBoundingClientRect();
  const snapshot = canvas.width ? canvas.toDataURL() : null;
  canvas.width = Math.max(1, Math.floor(rect.width * ratio));
  canvas.height = Math.max(1, Math.floor(rect.height * ratio));
  context.setTransform(ratio, 0, 0, ratio, 0, 0);
  context.lineCap = 'round';
  context.lineJoin = 'round';
  if (snapshot) {
    const image = new Image();
    image.onload = () => context.drawImage(image, 0, 0, rect.width, rect.height);
    image.src = snapshot;
  }
}

function point(event) {
  const rect = canvas.getBoundingClientRect();
  return { x: event.clientX - rect.left, y: event.clientY - rect.top };
}

canvas.addEventListener('pointerdown', (event) => {
  drawing = true;
  last = point(event);
  canvas.setPointerCapture(event.pointerId);
});

canvas.addEventListener('pointermove', (event) => {
  if (!drawing || !last) return;
  const next = point(event);
  context.strokeStyle = tool === 'eraser' ? '#ffffff' : color.value;
  context.lineWidth = tool === 'eraser' ? Number(size.value) * 2.5 : Number(size.value);
  context.beginPath();
  context.moveTo(last.x, last.y);
  context.lineTo(next.x, next.y);
  context.stroke();
  last = next;
});

function stopDrawing() {
  drawing = false;
  last = null;
}

canvas.addEventListener('pointerup', stopDrawing);
canvas.addEventListener('pointercancel', stopDrawing);
document.querySelectorAll('[data-tool]').forEach((button) => {
  button.addEventListener('click', () => {
    tool = button.dataset.tool;
    document.querySelectorAll('[data-tool]').forEach((item) => item.classList.toggle('is-active', item === button));
  });
});
document.getElementById('demo-clear').addEventListener('click', () => {
  context.clearRect(0, 0, canvas.clientWidth, canvas.clientHeight);
});
document.getElementById('demo-download').addEventListener('click', () => {
  const link = document.createElement('a');
  link.download = 'drawspace-demo.png';
  link.href = canvas.toDataURL('image/png');
  link.click();
});
window.addEventListener('resize', resizeCanvas);
resizeCanvas();
