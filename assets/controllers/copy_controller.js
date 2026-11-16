import { Controller } from '@hotwired/stimulus';

// A command of the lobby, copied with one click instead of being selected by hand.
// 127.0.0.1 counts as a secure context, so the clipboard API is there; the old way stays as a fallback.
export default class extends Controller {
    static targets = ['source', 'button'];
    static values = { copiedLabel: String };

    async copy() {
        const text = this.sourceTarget.textContent.trim();
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const range = document.createRange();
            range.selectNodeContents(this.sourceTarget);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            document.execCommand('copy');
            selection.removeAllRanges();
        }
        this.confirm();
    }

    confirm() {
        const button = this.buttonTarget;
        const label = button.getAttribute('aria-label');
        button.classList.add('copied');
        button.setAttribute('aria-label', this.copiedLabelValue);
        button.title = this.copiedLabelValue;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            button.classList.remove('copied');
            button.setAttribute('aria-label', label);
            button.title = label;
        }, 1500);
    }
}
