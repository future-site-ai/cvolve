import { Controller } from '@hotwired/stimulus';

/*
 * Editor chat: sends messages, shows proposals with their diff, and applies or
 * discards them. Every successful apply fires "cv:changed" so the preview and
 * the section list refresh.
 */
export default class extends Controller {
    static targets = ['log', 'input', 'send', 'empty'];
    static values = { url: String, token: String, locale: String, sectionsUrl: String };

    connect() {
        this.scrollDown();
    }

    keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.send();
        }
    }

    suggest(event) {
        this.inputTarget.value = event.currentTarget.dataset.text;
        this.send();
    }

    async send(event) {
        event?.preventDefault();
        const text = this.inputTarget.value.trim();
        if (!text || this.busy) return;
        this.busy = true;
        this.sendTarget.disabled = true;
        if (this.hasEmptyTarget) this.emptyTarget.remove();

        this.append(`<div class="chat-msg user">${this.escape(text)}</div>`);
        this.inputTarget.value = '';
        const thinking = this.append('<div class="chat-msg assistant thinking"><span class="spinner"></span> Thinking…</div>');

        try {
            const body = new FormData();
            body.set('message', text);
            body.set('locale', this.localeValue);
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body,
                headers: { 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' },
            });
            const data = await response.json();
            thinking.remove();
            if (!response.ok) {
                this.append(`<div class="chat-msg assistant">⚠️ ${this.escape(data.error || 'Something went wrong.')}</div>`);
            } else {
                this.append(data.message);
                (data.proposals || []).forEach((html) => this.append(html));
            }
        } catch (e) {
            thinking.remove();
            this.append('<div class="chat-msg assistant">⚠️ Network error. Please try again.</div>');
        } finally {
            this.busy = false;
            this.sendTarget.disabled = false;
            this.inputTarget.focus();
        }
    }

    async resolve(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const card = button.closest('.proposal');
        card.querySelectorAll('button').forEach((b) => (b.disabled = true));

        try {
            const response = await fetch(button.dataset.url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' },
            });
            const data = await response.json();
            card.outerHTML = data.html;
            if (data.ok && data.status === 'applied') {
                // A translation creates a new language version: switch to it
                if (data.locale && data.locale !== this.localeValue) {
                    window.location.href = `${window.location.pathname}?locale=${data.locale}`;
                    return;
                }
                this.refreshSections();
                window.dispatchEvent(new CustomEvent('cv:changed'));
            }
        } catch (e) {
            card.querySelectorAll('button').forEach((b) => (b.disabled = false));
        }
    }

    refreshSections() {
        const frame = document.getElementById('sections-panel');
        if (!frame) return;
        const url = new URL(this.sectionsUrlValue, window.location.origin);
        url.searchParams.set('_', Date.now());
        frame.src = url.toString();
    }

    append(html) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        const node = wrapper.firstElementChild;
        this.logTarget.appendChild(node);
        this.scrollDown();
        return node;
    }

    scrollDown() {
        this.logTarget.scrollTop = this.logTarget.scrollHeight;
    }

    escape(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML.replace(/\n/g, '<br>');
    }
}
