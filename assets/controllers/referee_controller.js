import { Controller } from '@hotwired/stimulus';

// The walrus referee whistles the verdicts of the round, whoever gave the answer. The controllers that read
// a verdict dispatch "referee:verdict" on the window; he speaks for warm and accepted, never for refused.
// Warm: he goes back to rest after a moment. Accepted: he stays until the page changes.
const WARM_MS = 2500;

export default class extends Controller {
    static targets = ['warm', 'accepted'];

    disconnect() {
        clearTimeout(this.rest);
    }

    hear({ detail: { verdict } }) {
        if (this.element.classList.contains('is-accepted') || !['warm', 'accepted'].includes(verdict)) {
            return;
        }
        clearTimeout(this.rest);
        this.warmTarget.hidden = verdict !== 'warm';
        this.acceptedTarget.hidden = verdict !== 'accepted';
        // Remove then add, to restart the pop and the sway on a new verdict.
        this.element.classList.remove('is-speaking');
        void this.element.offsetWidth;
        this.element.classList.add('is-speaking');
        this.element.classList.toggle('is-accepted', verdict === 'accepted');
        if (verdict === 'warm') {
            this.rest = setTimeout(() => {
                this.element.classList.remove('is-speaking');
                this.warmTarget.hidden = true;
            }, WARM_MS);
        }
    }
}
