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

  currentStroke.push({
    x: event.offsetX,
    y: event.offsetY,
  });
}

function draw(event) {
  if (!isDrawing) {
    return;
  }

  ctx.lineTo(event.offsetX, event.offsetY);

  currentStroke.push({
    x: event.offsetX,
    y: event.offsetY,
  });

  ctx.strokeStyle = "black";
  ctx.lineWidth = 3;
  ctx.lineCap = "round";

  ctx.stroke();
}

function stopDrawing() {
  if (!isDrawing) {
    return;
  }

  isDrawing = false;

  strokes.push(currentStroke);

  ctx.closePath();

  console.log(strokes);

  saveDrawing();
}

async function saveDrawing() {
  try {
    const response = await fetch("/api/save.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(strokes),
    });

    if (!response.ok) {
      const errData = await response.json().catch(() => ({}));
      console.warn("Save failed:", response.status, errData.error || response.statusText);
    }
  } catch (error) {
    console.warn("Save failed:", error);
  }
}

async function loadDrawing() {
  let response;

  try {
    response = await fetch("/api/load.php");
  } catch (error) {
    console.warn("Load fetch failed:", error);
    return; // offline / transient — keep whatever is on screen
  }

  if (!response.ok) {
    const errData = await response.json().catch(() => ({}));
    console.warn("Load failed:", response.status, errData.error || response.statusText);
    return; // server hiccup — never wipe the local board over it
  }

  let savedStrokes;

  try {
    savedStrokes = await response.json();
  } catch (error) {
    return;
  }

  if (!Array.isArray(savedStrokes)) {
    return;
  }

  // Avoid overwriting canvas while the user is actively drawing a stroke
  if (isDrawing) {
    return;
  }

  if (JSON.stringify(savedStrokes) === JSON.stringify(strokes)) {
    return;
  }

  strokes = savedStrokes;

  ctx.clearRect(0, 0, canvas.width, canvas.height);

  drawSavedStrokes(savedStrokes);
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

setInterval(loadDrawing, 2000);
