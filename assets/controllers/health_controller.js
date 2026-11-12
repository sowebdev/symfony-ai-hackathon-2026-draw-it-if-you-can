import { Controller } from '@hotwired/stimulus';

// The home page asks the game whether each role can play (GET /health), and says so: a role that
// fails comes with what is wrong, and the help tells where to configure the models.
export default class extends Controller {
    static targets = ['checking', 'list', 'help'];
    static values = { url: String, labels: Object };

    connect() {
        this.check(false);
    }

    retry() {
        this.check(true);
    }

    async check(fresh) {
        this.checkingTarget.hidden = false;
        this.helpTarget.hidden = true;
        this.listTarget.replaceChildren();
        let report;
        try {
            const response = await fetch(`${this.urlValue}${fresh ? '?fresh=1' : ''}`);
            report = await response.json();
        } catch {
            report = { ok: false, roles: [] };
        }
        this.checkingTarget.hidden = true;

        for (const { role, model, ok, problem } of report.roles) {
            const item = document.createElement('li');
            item.classList.add(ok ? 'ok' : 'failed');
            const name = document.createElement('strong');
            name.textContent = this.labelsValue[role] ?? role;
            const detail = document.createElement('span');
            detail.textContent = ok ? model : `${model}: ${problem}`;
            item.append(name, ' ', detail);
            this.listTarget.append(item);
        }
        this.helpTarget.hidden = report.ok;
        this.element.classList.toggle('failed', !report.ok);
    }
}
