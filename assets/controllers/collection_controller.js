import { Controller } from '@hotwired/stimulus';

/*
 * Sortable Symfony form collections (master profile, section editors).
 * New entries come from the <template> prototype; hidden "position" inputs record the order.
 */
export default class extends Controller {
    static targets = ['items', 'prototype'];
    static values = { prototypeName: String };

    connect() {
        this.counter = Date.now();
        this.renumber();
    }

    add(event) {
        event.preventDefault();
        const html = this.prototypeTarget.innerHTML.replaceAll(this.prototypeNameValue, String(this.counter++));
        this.itemsTarget.insertAdjacentHTML('beforeend', html);
        this.renumber();
        const added = this.itemsTarget.lastElementChild;
        added?.querySelector('input:not([type=hidden]), textarea')?.focus();
    }

    remove(event) {
        event.preventDefault();
        this.item(event)?.remove();
        this.renumber();
    }

    up(event) {
        event.preventDefault();
        const item = this.item(event);
        if (item?.previousElementSibling) {
            item.parentNode.insertBefore(item, item.previousElementSibling);
            this.renumber();
        }
    }

    down(event) {
        event.preventDefault();
        const item = this.item(event);
        if (item?.nextElementSibling) {
            item.parentNode.insertBefore(item.nextElementSibling, item);
            this.renumber();
        }
    }

    // Only this collection's direct items: nested collections number themselves
    item(event) {
        const item = event.target.closest('[data-collection-item]');
        return item && item.parentElement === this.itemsTarget ? item : null;
    }

    renumber() {
        [...this.itemsTarget.children].forEach((item, index) => {
            const input = [...item.querySelectorAll('[data-collection-position]')]
                .find((el) => el.closest('[data-controller~="collection"]') === this.element && el.closest('[data-collection-item]') === item);
            if (input) input.value = index;
        });
    }
}
