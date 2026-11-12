import { Controller } from '@hotwired/stimulus';

// The lobby of a game with a copilot: asks the game every second whether the agent has called it
// over MCP, and says so as soon as it has.
const POLL_MS = 1000;

export default class extends Controller {
    static targets = ['status'];
    static values = { url: String, connected: Boolean, waitingLabel: String, connectedLabel: String };

    connect() {
        if (!this.connectedValue) {
            this.timer = setInterval(() => this.poll(), POLL_MS);
        }
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async poll() {
        const response = await fetch(this.urlValue);
        if (!response.ok) {
            return;
        }
        const { connected, name } = await response.json();
        if (connected) {
            clearInterval(this.timer);
            this.connectedValue = true;
            this.statusTarget.textContent = this.connectedLabelValue.replace('%name%', name);
            this.statusTarget.classList.add('connected');
        }
    }
}
