import { Controller } from '@hotwired/stimulus';

/*
 * Keeps "Mon dossier" truthful while documents are prepended to the list by live updates (Turbo Streams):
 * the list never grows beyond one page, and the counts follow. Rows already on the page, or re-inserted
 * (the streams remove a row before prepending it), do not count twice.
 */
export default class extends Controller {
    static targets = ['list', 'count', 'total'];

    static values = {
        max: Number,
        total: Number,
    };

    connect() {
        this.known = new Set(this.rows().map((row) => row.id));
        this.observer = new MutationObserver((mutations) => this.onChange(mutations));
        this.observer.observe(this.listTarget, { childList: true });
    }

    disconnect() {
        this.observer.disconnect();
    }

    onChange(mutations) {
        const added = mutations
            .flatMap((mutation) => [...mutation.addedNodes])
            .filter((node) => node instanceof HTMLElement && node.id && !this.known.has(node.id));

        added.forEach((node) => this.known.add(node.id));
        if (added.length > 0) {
            this.totalValue += added.length;
            this.renderTotal();
        }

        // The oldest rows are at the bottom: they move to the next page.
        this.rows().slice(this.maxValue).forEach((row) => row.remove());
    }

    renderTotal() {
        const total = this.totalValue;
        this.totalTargets.forEach((element) => { element.textContent = String(total); });
        if (this.hasCountTarget) {
            this.countTarget.textContent = `${total} ${total > 1 ? 'pièces' : 'pièce'}`;
        }
    }

    rows() {
        return [...this.listTarget.children];
    }
}
