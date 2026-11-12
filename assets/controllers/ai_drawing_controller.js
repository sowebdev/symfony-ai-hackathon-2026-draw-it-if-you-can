import { Controller } from '@hotwired/stimulus';
import { seconds } from '../seconds.js';

// The AI draws, the player guesses. The strokes come as server-sent events, in the canonical
// format; a new stroke is drawn point by point, following its t, like a pen. Answers are typed
// by the player and judged by the server. "over" tells the round timer the round is won or lost.
const SIZE = 1000;
const PEN = '#2b2b2b';
const PEN_NOW = '#e8590c';

export default class extends Controller {
    static targets = ['canvas', 'status', 'form', 'word', 'list', 'result', 'time', 'reveal', 'targetWord', 'giveUp'];
    static values = { streamUrl: String, answerUrl: String, giveUpUrl: String, over: Boolean, drawingLabel: String, finishedLabel: String, failedLabel: String };

    connect() {
        this.pen = this.canvasTarget.getContext('2d');
        this.strokes = [];
        this.queue = Promise.resolve();
        this.source = new EventSource(this.streamUrlValue);
        this.source.addEventListener('stroke', (event) => this.receive(JSON.parse(event.data)));
        this.source.addEventListener('finished', () => this.end(this.finishedLabelValue));
        this.source.addEventListener('failed', () => this.end(this.failedLabelValue));
        this.overValueChanged();
    }

    disconnect() {
        this.source?.close();
    }

    overValueChanged() {
        if (this.hasFormTarget) {
            this.formTarget.hidden = this.overValue;
        }
        if (this.hasGiveUpTarget) {
            this.giveUpTarget.hidden = this.overValue;
        }
    }

    // The time is up: no more answers.
    stop() {
        this.overValue = true;
    }

    end(label) {
        this.source.close();
        // The status changes once the last stroke is on the canvas.
        this.queue = this.queue.then(() => {
            this.statusTarget.textContent = label;
        });
    }

    // Strokes are drawn one after the other, even if the next one arrives during the animation.
    receive({ points, animate }) {
        this.queue = this.queue.then(() => (animate ? this.animate(points) : this.add(points)));
    }

    add(points) {
        this.strokes.push(points);
        this.redraw();
    }

    animate(points) {
        const start = points[0][2];
        const startedAt = performance.now();
        return new Promise((resolve) => {
            const step = (now) => {
                const elapsed = now - startedAt + start;
                const shown = points.filter((point) => point[2] <= elapsed);
                this.redraw(shown.length > 0 ? shown : [points[0]]);
                if (shown.length < points.length) {
                    requestAnimationFrame(step);
                } else {
                    this.add(points);
                    resolve();
                }
            };
            requestAnimationFrame(step);
        });
    }

    redraw(current = null) {
        const pen = this.pen;
        pen.clearRect(0, 0, SIZE, SIZE);
        pen.lineWidth = 6;
        pen.lineCap = 'round';
        pen.lineJoin = 'round';
        for (const points of current ? [...this.strokes, current] : this.strokes) {
            // The stroke being drawn is in orange, like the pencil of the sketchbook.
            pen.strokeStyle = points === current ? PEN_NOW : PEN;
            pen.beginPath();
            pen.moveTo(points[0][0], points[0][1]);
            for (const [x, y] of points.length > 1 ? points.slice(1) : points) {
                pen.lineTo(x, y);
            }
            pen.stroke();
        }
    }

    async answer(event) {
        event.preventDefault();
        const word = this.wordTarget.value.trim();
        if (word === '' || this.overValue) {
            return;
        }
        this.wordTarget.value = '';
        const response = await fetch(this.answerUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ word }),
        });
        if (response.status === 409) {
            this.stop();
            return;
        }
        if (!response.ok) {
            return;
        }
        const result = await response.json();
        const item = document.createElement('li');
        item.textContent = result.word;
        item.classList.toggle('accepted', result.found);
        this.listTarget.append(item);
        if (result.found) {
            this.timeTarget.textContent = seconds(result.foundAfterMs);
            this.resultTarget.hidden = false;
            this.overValue = true;
            this.dispatch('over');
        } else {
            this.wordTarget.focus();
        }
    }

    async giveUp() {
        const response = await fetch(this.giveUpUrlValue, { method: 'POST' });
        if (!response.ok) {
            return;
        }
        const { targetWord, found } = await response.json();
        if (!found) {
            this.targetWordTarget.textContent = targetWord;
            this.revealTarget.hidden = false;
        }
        this.overValue = true;
        this.dispatch('over');
    }
}
