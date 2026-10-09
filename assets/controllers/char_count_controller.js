import { Controller } from '@hotwired/stimulus';

/* Shows "123 / 900" under a textarea with a template length limit. */
export default class extends Controller {
    static values = { max: Number };

    connect() {
        this.counter = document.createElement('div');
        this.counter.className = 'form-help';
        this.element.insertAdjacentElement('afterend', this.counter);
        this.update = this.update.bind(this);
        this.element.addEventListener('input', this.update);
        this.update();
    }

    disconnect() {
        this.element.removeEventListener('input', this.update);
        this.counter.remove();
    }

    update() {
        const length = this.element.value.length;
        this.counter.textContent = `${length} / ${this.maxValue} characters`;
        this.counter.style.color = length > this.maxValue ? 'var(--danger)' : '';
    }
}
