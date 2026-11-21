import { Controller } from '@hotwired/stimulus';

// What the agent connected over MCP does, received as server-sent events: the hints of a copilot,
// a click on one answers it (the player decides); its tips to the player who draws, the newest on
// top; the answers of the rival in a duel, and
// "rival_found" when it finds the word, which ends the round.
export default class extends Controller {
    static targets = ['hints', 'hintList', 'tips', 'tipList', 'answers', 'bubble'];
    static values = { streamUrl: String, over: Boolean };

    connect() {
        if (this.overValue) {
            return;
        }
        this.source = new EventSource(this.streamUrlValue);
        this.source.addEventListener('hint', (event) => this.addHint(JSON.parse(event.data)));
        this.source.addEventListener('tip', (event) => this.addTip(JSON.parse(event.data)));
        this.source.addEventListener('heard', (event) => this.heard(JSON.parse(event.data)));
        this.source.addEventListener('answer', (event) => this.addAnswer(JSON.parse(event.data)));
        this.source.addEventListener('rival_found', () => {
            this.source.close();
            this.dispatch('found');
        });
    }

    disconnect() {
        this.source?.close();
    }

    // The stream sends every hint of the round, again at each reconnection: the ones on screen stay once.
    addHint({ id, word }) {
        if (!this.hasHintListTarget || this.hintListTarget.querySelector(`[data-hint-id="${id}"]`)) {
            return;
        }
        const item = document.createElement('li');
        item.dataset.hintId = id;
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = word;
        button.dataset.action = 'agent#pick';
        item.append(button);
        this.hintListTarget.append(item);
        this.say(word);
        this.hintsTarget.hidden = false;
    }

    // Like the hints, a tip comes again at each reconnection: it stays once.
    addTip({ id, text }) {
        if (!this.hasTipListTarget || this.tipListTarget.querySelector(`[data-tip-id="${id}"]`)) {
            return;
        }
        const item = document.createElement('li');
        item.dataset.tipId = id;
        item.textContent = text;
        this.tipListTarget.prepend(item);
        this.tipsTarget.hidden = false;
        this.say(text);
    }

    // The agent's last word, in his terminal bubble at the top of his side.
    say(word) {
        if (!this.hasBubbleTarget) {
            return;
        }
        this.bubbleTarget.textContent = word;
        this.bubbleTarget.hidden = false;
        this.bubbleTarget.classList.remove('is-waiting');
        this.bubbleTarget.classList.remove('pop');
        void this.bubbleTarget.offsetWidth;
        this.bubbleTarget.classList.add('pop');
    }

    // The rival says its word at once; the referee thinks about it until its verdict comes ("answer").
    heard({ id, word }) {
        this.heardId = id;
        this.say(word);
        this.dispatch('thinking', { prefix: 'referee', target: window, detail: { word } });
    }

    addAnswer({ id, word, right, verdict }) {
        if (!this.hasAnswersTarget || this.answersTarget.querySelector(`[data-answer-id="${id}"]`)) {
            return;
        }
        const item = document.createElement('li');
        item.dataset.answerId = id;
        item.textContent = word;
        item.classList.toggle('accepted', right);
        item.classList.toggle('warm', verdict === 'warm');
        this.answersTarget.append(item);
        if (id !== this.heardId) {
            this.say(word);
        }
        this.dispatch('verdict', { prefix: 'referee', target: window, detail: { verdict: right ? 'accepted' : verdict } });
    }

    // A click answers the hint: once only.
    pick(event) {
        event.currentTarget.disabled = true;
        this.dispatch('pick', { detail: { word: event.currentTarget.textContent } });
    }
}
