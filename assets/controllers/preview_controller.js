import { Controller } from '@hotwired/stimulus';

/*
 * The live A4 preview iframe. Reloads (keeping the scroll position) whenever the
 * CV changes: a section form saved, a proposal applied, the header edited.
 */
export default class extends Controller {
    static targets = ['frame', 'spinner', 'zoom'];
    static values = { url: String };

    connect() {
        this.reload = this.reload.bind(this);
        window.addEventListener('cv:changed', this.reload);
        document.addEventListener('turbo:frame-load', this.onFrameLoad);
        this.frameTarget.addEventListener('load', () => this.restore());
    }

    disconnect() {
        window.removeEventListener('cv:changed', this.reload);
        document.removeEventListener('turbo:frame-load', this.onFrameLoad);
    }

    onFrameLoad = (event) => {
        if (event.target.id === 'sections-panel') this.reload();
    };

    reload() {
        try {
            this.scroll = this.frameTarget.contentWindow?.scrollY ?? 0;
        } catch (e) {
            this.scroll = 0;
        }
        this.spinnerTarget.hidden = false;
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('_', Date.now());
        this.frameTarget.src = url.toString();
    }

    restore() {
        this.spinnerTarget.hidden = true;
        this.applyZoom();
        try {
            if (this.scroll) this.frameTarget.contentWindow.scrollTo(0, this.scroll);
        } catch (e) { /* sandboxed */ }
    }

    zoom() {
        this.applyZoom();
    }

    applyZoom() {
        if (!this.hasZoomTarget) return;
        try {
            const doc = this.frameTarget.contentDocument;
            if (doc?.body) doc.body.style.zoom = this.zoomTarget.value;
        } catch (e) { /* cross-origin */ }
    }
}
