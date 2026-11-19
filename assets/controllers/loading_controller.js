import { Controller } from '@hotwired/stimulus';

// The time the card of the theme takes to turn over, before the form leaves: as at a board game,
// the theme is drawn, then the round starts (assets/styles/app.css, .theme-card).
const DEAL_MS = 1400;

// On a form that waits for the AI (the next round, a retry): the loading screen shows as soon as
// it is sent, until the next page comes. On a form that starts a round, the card of its theme
// turns over first. Coming back with the browser's history hides it all.
export default class extends Controller {
    static values = { theme: String, color: String, round: String, auto: Boolean };

    connect() {
        // pageshow also comes with the first load: only a page restored from the history is reset.
        this.hide = (event) => {
            if (!event.persisted) {
                return;
            }
            this.sent = false;
            this.overlay()?.setAttribute('hidden', '');
            this.card()?.classList.remove('dealt');
            if (this.colorValue) {
                this.card()?.classList.remove(this.colorValue);
            }
        };
        window.addEventListener('pageshow', this.hide);
        if (this.autoValue) {
            this.show();
        }
    }

    disconnect() {
        window.removeEventListener('pageshow', this.hide);
    }

    show(event) {
        // A button that only goes on to another question (data-plain) sends the form as it is, without the screen.
        if (event?.submitter?.hasAttribute('data-plain')) {
            return;
        }
        event?.preventDefault();
        if (this.sent) {
            return;
        }
        this.sent = true;
        this.overlay()?.removeAttribute('hidden');

        const card = this.card();
        if (!card || !this.themeValue) {
            this.element.submit();

            return;
        }
        card.querySelector('.theme-card-round').textContent = this.roundValue;
        card.querySelector('.theme-card-theme').textContent = this.themeValue;
        if (this.colorValue) {
            card.classList.add(this.colorValue);
        }
        card.hidden = false;
        // A new frame, so that the card is drawn face down before it turns over.
        requestAnimationFrame(() => requestAnimationFrame(() => card.classList.add('dealt')));
        setTimeout(() => this.element.submit(), DEAL_MS);
    }

    overlay() {
        return document.getElementById('loading');
    }

    card() {
        return this.overlay()?.querySelector('.theme-card');
    }
}
