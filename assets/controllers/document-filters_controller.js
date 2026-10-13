import { Controller } from '@hotwired/stimulus';

/*
 * Progressive enhancement of the "Mon dossier" filters: the markup is a working GET form (no-JS fallback).
 * Once connected, the search field submits itself after a short pause and the selects as soon as they change;
 * the form targets the results frame (Turbo), so only the list reloads.
 */
export default class extends Controller {
    static targets = ['form', 'search'];

    static values = {
        delay: { type: Number, default: 300 },
    };

    connect() {
        this.timer = null;
        this.syncWithUrl();
        this.rememberListUrl();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    debounce() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.submit(), this.delayValue);
    }

    submit() {
        clearTimeout(this.timer);
        this.formTarget.requestSubmit();
    }

    // Keeps empty fields out of the URL ("?statut=" is noise): disabled controls are not serialized.
    prune() {
        const empty = [...this.formTarget.elements].filter((element) => element.name && !element.disabled && '' === element.value.trim());
        empty.forEach((element) => { element.disabled = true; });
        setTimeout(() => empty.forEach((element) => { element.disabled = false; }), 0);
    }

    // Reads the result count aloud in the page's single live region once the results have been replaced.
    announce(event) {
        const message = event.target.querySelector('[data-announcement]')?.dataset.announcement;
        const region = document.getElementById('live-status');
        if (message && region) {
            region.textContent = message;
        }
        // The frame advances the URL right after rendering.
        setTimeout(() => this.rememberListUrl(), 0);
    }

    // Read by the document page's "Retour à mon dossier" link, to come back to the same search, filters and page.
    rememberListUrl() {
        try {
            sessionStorage.setItem('document-list-url', window.location.pathname + window.location.search);
        } catch {
            // Storage unavailable (private mode…): the link keeps pointing to the unfiltered list.
        }
    }

    // After the back button the restored page may hold stale field values: the URL is the source of truth.
    syncWithUrl() {
        if (!this.hasFormTarget) {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        [...this.formTarget.elements].forEach((element) => {
            if (element.name && 'submit' !== element.type && document.activeElement !== element) {
                element.value = params.get(element.name) ?? '';
            }
        });
    }
}
