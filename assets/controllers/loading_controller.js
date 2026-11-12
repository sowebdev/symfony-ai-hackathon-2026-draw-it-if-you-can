import { Controller } from '@hotwired/stimulus';

// On a form that waits for the AI (a new game, the next round): the loading screen shows as soon
// as it is sent, until the next page comes. Coming back with the browser's history hides it.
export default class extends Controller {
    connect() {
        this.hide = () => this.overlay()?.setAttribute('hidden', '');
        window.addEventListener('pageshow', this.hide);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.hide);
    }

    show() {
        this.overlay()?.removeAttribute('hidden');
    }

    overlay() {
        return document.getElementById('loading');
    }
}
