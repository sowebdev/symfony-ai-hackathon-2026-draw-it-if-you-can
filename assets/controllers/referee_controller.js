import { Controller } from '@hotwired/stimulus';

// The walrus referee whistles the verdicts of the round, whoever gave the answer. The controllers that read
// a verdict dispatch "referee:verdict" on the window; he speaks for warm and accepted, never for refused.
// Warm: he goes back to rest after a moment. Accepted: he stays until the page changes.
// Before a verdict, "referee:thinking" says a word is with the judge: if the verdict is not there at once
// (the exact word is), he repeats the word with one of his phrases, in turn, until the verdict comes.
const WARM_MS = 2500;
const THINK_AFTER_MS = 300;
// Longer than the judge may take (Referee::JUDGING_LIMIT_MS): a verdict that never comes does not leave him thinking.
const THINK_AT_MOST_MS = 12000;

export default class extends Controller {
    static targets = ['thinking', 'warm', 'accepted'];
    static values = { phrases: Array };

    connect() {
        this.turn = 0;
    }

    disconnect() {
        clearTimeout(this.rest);
        this.stopThinking();
    }

    think({ detail: { word } }) {
        if (this.element.classList.contains('is-accepted') || this.phrasesValue.length === 0) {
            return;
        }
        this.stopThinking();
        this.pondering = setTimeout(() => {
            const phrase = this.phrasesValue[this.turn % this.phrasesValue.length];
            this.turn += 1;
            this.thinkingTarget.textContent = phrase.replace('%word%', word);
            this.thinkingTarget.hidden = false;
            this.warmTarget.hidden = true;
            this.element.classList.add('is-thinking');
            this.giveUp = setTimeout(() => this.stopThinking(), THINK_AT_MOST_MS);
        }, THINK_AFTER_MS);
    }

    stopThinking() {
        clearTimeout(this.pondering);
        clearTimeout(this.giveUp);
        this.element.classList.remove('is-thinking');
        if (this.hasThinkingTarget) {
            this.thinkingTarget.hidden = true;
        }
    }

    hear({ detail: { verdict } }) {
        this.stopThinking();
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
