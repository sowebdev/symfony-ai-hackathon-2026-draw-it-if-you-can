import { Controller } from '@hotwired/stimulus';

// The clock of a round and the gauge of points. The server gives the time left and the corners
// of the gauge (ScoreGauge::steps()): the points fall in a straight line between two, as on the
// server. When the time is up, or when the word is found, the page reloads: the server shows
// the end of the round.
const TICK_MS = 100;
const RESULT_PAUSE_MS = 1500;

export default class extends Controller {
    static targets = ['seconds', 'gauge', 'fill', 'points'];
    static values = { remainingMs: Number, durationMs: Number, graceMs: Number, gauge: Array, over: Boolean };

    connect() {
        if (this.overValue) {
            return;
        }
        this.endsAt = performance.now() + this.remainingMsValue;
        this.tick();
        this.timer = setInterval(() => this.tick(), TICK_MS);
    }

    disconnect() {
        clearInterval(this.timer);
        clearTimeout(this.reload);
    }

    tick() {
        const left = Math.max(0, this.endsAt - performance.now());
        const points = this.pointsAt(this.durationMsValue - left);
        const max = this.gaugeValue[0][1];
        if (this.hasSecondsTarget) {
            this.secondsTarget.textContent = Math.ceil(left / 1000);
        }
        if (this.hasPointsTarget) {
            this.pointsTarget.textContent = points;
            this.fillTarget.style.width = `${(100 * points) / max}%`;
            this.gaugeTarget.setAttribute('aria-valuenow', points);
        }
        if (left === 0) {
            this.stop();
            // The server waits for the grace time before it calls the round over.
            this.dispatch('up');
            this.reloadAfter(this.graceMsValue + 500);
        }
    }

    // The word is found, or the player gave up: the result stays on screen a moment.
    finish() {
        this.stop();
        this.reloadAfter(RESULT_PAUSE_MS);
    }

    stop() {
        clearInterval(this.timer);
        this.element.classList.add('round-ending');
    }

    reloadAfter(ms) {
        clearTimeout(this.reload);
        this.reload = setTimeout(() => window.location.reload(), ms);
    }

    pointsAt(ms) {
        const steps = this.gaugeValue;
        for (let i = 1; i < steps.length; i++) {
            const [toMs, toPoints] = steps[i];
            if (ms <= toMs) {
                const [fromMs, fromPoints] = steps[i - 1];
                const share = toMs === fromMs ? 0 : (ms - fromMs) / (toMs - fromMs);
                return Math.round((fromPoints + (toPoints - fromPoints) * share) / 10) * 10;
            }
        }
        return steps.at(-1)[1];
    }
}
