import { Controller } from '@hotwired/stimulus';

// The words of the AI, received as server-sent events while the player draws.
// "Yes!" accepts a near word; the exact word is recognised by the server. The stream ends with
// the round: "found", or "over" when the time is up.
export default class extends Controller {
    static targets = ['list', 'result', 'time'];
    static values = { streamUrl: String, acceptUrl: String, over: Boolean, yesLabel: String };

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
        const text = document.createElement('span');
        text.textContent = word;
        const yes = document.createElement('button');
        yes.type = 'button';
        yes.textContent = this.yesLabelValue;
        yes.dataset.action = 'guesses#accept';
        item.append(text, yes);
        this.listTarget.append(item);
    }

    async accept(event) {
        const item = event.target.closest('li');
        const url = this.acceptUrlValue.replace(/\/0\/accept$/, `/${item.dataset.guessId}/accept`);
        const response = await fetch(url, { method: 'POST' });
        if (response.ok) {
            this.finish((await response.json()).foundAfterMs, item);
        }
    }

    // The time is up: no more guesses, nor "Yes!".
    stop() {
        this.source?.close();
        this.overValue = true;
        this.listTarget.querySelectorAll('button').forEach((button) => button.remove());
    }

    finish(foundAfterMs, item = this.listTarget.lastElementChild) {
        this.stop();
        item?.classList.add('accepted');
        this.timeTarget.textContent = (foundAfterMs / 1000).toFixed(1);
        this.resultTarget.hidden = false;
        this.dispatch('found');
    }
}
