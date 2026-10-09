import { Controller } from '@hotwired/stimulus';

/*
 * Polls a JSON status URL until a background job (export, import) finishes, then reloads the page.
 */
export default class extends Controller {
    static values = { url: String, interval: { type: Number, default: 1500 }, done: String };

    connect() {
        this.timer = setInterval(() => this.check(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async check() {
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (data.finished || data.busy === false) {
                clearInterval(this.timer);
                window.location.reload();
            }
        } catch (e) { /* keep polling */ }
    }
}
