import { Controller } from '@hotwired/stimulus';
import { seconds } from '../seconds.js';

// The words of the AI, received as server-sent events while the player draws.
// The exact word is recognised by the server. The stream ends with the round: "found", or "over" when the time is up.
export default class extends Controller {
    static targets = ['list', 'result', 'time', 'olaf', 'bubble'];
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

    add({ id, word, verdict }) {
        const item = document.createElement('li');
        item.dataset.guessId = id;
        item.textContent = word;
        // A warm word ("ça chauffe") is in orange: close, but not the word.
        item.classList.toggle('warm', verdict === 'warm');
        this.listTarget.append(item);
        this.say(word);
    }

    // Olaf says the word: it pops in his bubble, he hops and opens his beak (app.css, .olaf-wrap.is-saying).
    say(word) {
        if (!this.hasBubbleTarget || !this.hasOlafTarget) {
            return;
        }
        this.bubbleTarget.textContent = word;
        this.bubbleTarget.hidden = false;
        for (const [element, name] of [[this.bubbleTarget, 'pop'], [this.olafTarget, 'is-saying']]) {
            element.classList.remove(name);
            void element.offsetWidth; // restarts the animation
            element.classList.add(name);
        }
        clearTimeout(this.quiet);
        this.quiet = setTimeout(() => this.olafTarget.classList.remove('is-saying'), 900);
    }

    // The time is up: no more guesses.
    stop() {
        this.source?.close();
        this.overValue = true;
        if (this.hasOlafTarget) {
            this.olafTarget.classList.remove('is-thinking');
        }
    }

    finish(foundAfterMs, item = this.listTarget.lastElementChild) {
        this.stop();
        item?.classList.add('accepted');
        if (this.hasBubbleTarget && this.hasOlafTarget) {
            this.bubbleTarget.classList.add('found');
            this.olafTarget.classList.add('is-cheering');
        }
        this.timeTarget.textContent = seconds(foundAfterMs);
        this.resultTarget.hidden = false;
        this.dispatch('found');
    }
}
