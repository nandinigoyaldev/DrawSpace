const canvas = document.getElementById("drawingCanvas");
const ctx = canvas.getContext("2d");

canvas.width = canvas.offsetWidth;
canvas.height = canvas.offsetHeight;

let isDrawing = false;
let currentStroke = [];
let strokes = [];

canvas.addEventListener("mousedown", startDrawing);
canvas.addEventListener("mousemove", draw);
canvas.addEventListener("mouseup", stopDrawing);
canvas.addEventListener("mouseleave", stopDrawing);

function startDrawing(event) {
  isDrawing = true;
  currentStroke = [];
  ctx.beginPath();
  ctx.moveTo(event.offsetX, event.offsetY);
  currentStroke.push({ x: event.offsetX, y: event.offsetY });
}

function draw(event) {
  if (!isDrawing) return;
  ctx.lineTo(event.offsetX, event.offsetY);
  currentStroke.push({ x: event.offsetX, y: event.offsetY });
  ctx.strokeStyle = "black";
  ctx.lineWidth = 3;
  ctx.lineCap = "round";
  ctx.stroke();
}

function stopDrawing() {
  if (!isDrawing) return;
  isDrawing = false;
  strokes.push(currentStroke);
  ctx.closePath();
  saveDrawing();
}

async function saveDrawing() {
  try {
    await fetch("/api/save.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(strokes),
    });
  } catch (error) {
    console.log(error);
  }
}

async function loadDrawing() {
  try {
    const response = await fetch("/api/load.php");
    if (!response.ok) return;
    const savedStrokes = await response.json();
    if (!Array.isArray(savedStrokes) || isDrawing) return;
    if (JSON.stringify(savedStrokes) === JSON.stringify(strokes)) return;

    strokes = savedStrokes;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawSavedStrokes(savedStrokes);
  } catch (error) {
    console.log(error);
  }
}

function drawSavedStrokes(savedStrokes) {
  for (const stroke of savedStrokes) {
    ctx.beginPath();
    for (let i = 0; i < stroke.length; i++) {
      const point = stroke[i];
      if (i === 0) {
        ctx.moveTo(point.x, point.y);
      } else {
        ctx.lineTo(point.x, point.y);
      }
    }
    ctx.strokeStyle = "black";
    ctx.lineWidth = 3;
    ctx.lineCap = "round";
    ctx.stroke();
    ctx.closePath();
  }
}

loadDrawing();
setInterval(loadDrawing, 1000);
