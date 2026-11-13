import { Controller } from '@hotwired/stimulus';
import { seconds } from '../seconds.js';

// The words of the AI, received as server-sent events while the player draws.
// The exact word is recognised by the server. The stream ends with the round: "found", or "over" when the time is up.
export default class extends Controller {
    static targets = ['list', 'result', 'time'];
    static values = { streamUrl: String, over: Boolean };

    connect() {
        if (this.overValue) {
            return;
        }
        this.source = new EventSource(this.streamUrlValue);
        this.source.addEventListener('guess', (event) => this.add(JSON.parse(event.data)));
        this.source.addEventListener('found', (event) => this.finish(JSON.parse(event.data).foundAfterMs));
        this.source.addEventListener('over', () => this.stop());
    }

    disconnect() {
        this.source?.close();
    }

    add({ id, word }) {
        const item = document.createElement('li');
        item.dataset.guessId = id;
        item.textContent = word;
        this.listTarget.append(item);
    }

    // The time is up: no more guesses.
    stop() {
        this.source?.close();
        this.overValue = true;
    }

    finish(foundAfterMs, item = this.listTarget.lastElementChild) {
        this.stop();
        item?.classList.add('accepted');
        this.timeTarget.textContent = seconds(foundAfterMs);
        this.resultTarget.hidden = false;
        this.dispatch('found');
    }
}
