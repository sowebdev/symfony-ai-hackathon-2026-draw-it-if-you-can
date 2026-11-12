import { Controller } from '@hotwired/stimulus';

// The lobby of a game with an agent, copilot or rival: asks the game every second whether the
// agent has called it over MCP, and by which name. In the lobby, another agent may take its place.
const POLL_MS = 1000;

export default class extends Controller {
    static targets = ['status'];
    static values = { url: String, connected: Boolean, connectedLabel: String };

    connect() {
        this.timer = setInterval(() => this.poll(), POLL_MS);
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
            this.connectedValue = true;
            this.statusTarget.textContent = this.connectedLabelValue.replace('%name%', name);
            this.statusTarget.classList.add('connected');
        }
    }
}
