import { Controller } from '@hotwired/stimulus';

// The hints of the copilot, an agent connected over MCP, received as server-sent events while the
// player guesses. A click on a hint answers it: the player decides.
export default class extends Controller {
    static targets = ['list'];
    static values = { streamUrl: String, over: Boolean };

    connect() {
        if (this.overValue) {
            return;
        }
        this.source = new EventSource(this.streamUrlValue);
        this.source.addEventListener('hint', (event) => this.add(JSON.parse(event.data)));
    }

    disconnect() {
        this.source?.close();
    }

    // The stream sends every hint of the round, again at each reconnection: the ones on screen stay once.
    add({ id, word }) {
        if (this.listTarget.querySelector(`[data-hint-id="${id}"]`)) {
            return;
        }
        const item = document.createElement('li');
        item.dataset.hintId = id;
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = word;
        button.dataset.action = 'hints#pick';
        item.append(button);
        this.listTarget.append(item);
        this.element.hidden = false;
    }

    // A click answers the hint: once only.
    pick(event) {
        event.currentTarget.disabled = true;
        this.dispatch('pick', { detail: { word: event.currentTarget.textContent } });
    }
}
