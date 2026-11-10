import { Controller } from '@hotwired/stimulus';

// The canvas where the player draws. Each stroke is captured in the canonical format,
// [x, y, t] points with x and y from 0 to 1000 and t in milliseconds since the round started,
// then sent to the server, which stores it. The drawing is redrawn from the stored strokes.
const SIZE = 1000;

export default class extends Controller {
    static targets = ['canvas'];
    static values = { strokesUrl: String, addUrl: String, undoUrl: String };

    async connect() {
        this.pen = this.canvasTarget.getContext('2d');
        this.strokes = [];
        this.current = null;

        const response = await fetch(this.strokesUrlValue);
        this.strokes = (await response.json()).map((stroke) => stroke.points);

        // After a reload, the clock picks up where the last stroke ended.
        const lastStroke = this.strokes.at(-1);
        this.startedAt = performance.now() - (lastStroke ? lastStroke.at(-1)[2] : 0);
        this.redraw();

        this.canvasTarget.addEventListener('pointerdown', this.start);
        this.canvasTarget.addEventListener('pointermove', this.move);
        this.canvasTarget.addEventListener('pointerup', this.end);
        this.canvasTarget.addEventListener('pointercancel', this.end);
    }

    disconnect() {
        this.canvasTarget.removeEventListener('pointerdown', this.start);
        this.canvasTarget.removeEventListener('pointermove', this.move);
        this.canvasTarget.removeEventListener('pointerup', this.end);
        this.canvasTarget.removeEventListener('pointercancel', this.end);
    }

    start = (event) => {
        this.canvasTarget.setPointerCapture(event.pointerId);
        this.current = [this.toPoint(event)];
        this.redraw();
    };

    move = (event) => {
        if (!this.current) {
            return;
        }
        const point = this.toPoint(event);
        const last = this.current.at(-1);
        if (point[0] === last[0] && point[1] === last[1]) {
            return;
        }
        this.current.push(point);
        this.redraw();
    };

    end = async () => {
        if (!this.current) {
            return;
        }
        const points = this.current;
        this.current = null;

        const response = await fetch(this.addUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ points }),
        });
        if (response.ok) {
            this.strokes.push(points);
        }
        this.redraw();
    };

    async undo() {
        const response = await fetch(this.undoUrlValue, { method: 'DELETE' });
        if (response.ok) {
            this.strokes.pop();
            this.redraw();
        }
    }

    // Converts a pointer position to a canonical point, whatever the size the canvas is shown at.
    toPoint(event) {
        const box = this.canvasTarget.getBoundingClientRect();
        const clamp = (value) => Math.min(SIZE, Math.max(0, Math.round(value)));

        return [
            clamp(((event.clientX - box.left) / box.width) * SIZE),
            clamp(((event.clientY - box.top) / box.height) * SIZE),
            Math.round(performance.now() - this.startedAt),
        ];
    }

    redraw() {
        const pen = this.pen;
        pen.clearRect(0, 0, SIZE, SIZE);
        pen.lineWidth = 6;
        pen.lineCap = 'round';
        pen.lineJoin = 'round';
        pen.strokeStyle = '#111';

        for (const points of this.current ? [...this.strokes, this.current] : this.strokes) {
            pen.beginPath();
            pen.moveTo(points[0][0], points[0][1]);
            // A single point is drawn as a dot.
            for (const [x, y] of points.length > 1 ? points.slice(1) : points) {
                pen.lineTo(x, y);
            }
            pen.stroke();
        }
    }
}
