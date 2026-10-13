import { Controller } from '@hotwired/stimulus';

/*
 * Progressive enhancement of the "Mon dossier" filters: the markup is a working GET form (no-JS fallback).
 * Once connected, the search field submits itself after a short pause and the selects as soon as they change;
 * the form targets the results frame (Turbo), so only the list reloads.
 *
 * Moving through the results (pager, chips, status shortcuts) brings the new results into view, puts the focus on
 * their heading and reads the count aloud, whether the page was replaced (links leaving the frame) or only the frame.
 */

// Survives the page replacement of a Turbo visit, which creates a new controller instance.
let resultsAwaited = false;

export default class extends Controller {
    static targets = ['form', 'search'];

    static values = {
        delay: { type: Number, default: 300 },
    };

    connect() {
        this.timer = null;
        this.followedPagerLink = false;
        this.syncWithResults();
        this.rememberListUrl();

        // A preview from the Turbo cache is replaced right after: wait for the real page.
        if (resultsAwaited && !document.documentElement.hasAttribute('data-turbo-preview')) {
            resultsAwaited = false;
            this.showResults();
        }
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

    // Links of the results that change the page itself: a pager link only reloads the frame, the others leave it.
    followLink(event) {
        if (new URL(event.detail.url).pathname !== window.location.pathname) return;

        if ('_top' === event.target.closest('a')?.dataset.turboFrame) {
            resultsAwaited = true;
        } else {
            this.followedPagerLink = true;
        }
    }

    // Once the results frame has been replaced: reads the result count aloud in the page's single live region.
    announce(event) {
        this.announceResults(event.target);
        if (this.followedPagerLink) {
            this.followedPagerLink = false;
            this.focusResults();
        }
        // The frame advances the URL right after rendering.
        setTimeout(() => this.rememberListUrl(), 0);
    }

    announceResults(root) {
        const message = root.querySelector('[data-announcement]')?.dataset.announcement;
        const region = document.getElementById('live-status');
        if (message && region) {
            region.textContent = message;
        }
    }

    // After a visit that replaced the page: the live region is new, give it a moment before filling it.
    showResults() {
        this.focusResults();
        setTimeout(() => this.announceResults(this.element), 100);
    }

    focusResults() {
        const heading = this.element.querySelector('[data-results-heading]');
        if (!heading) return;

        heading.focus({ preventScroll: true });
        heading.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }

    // Read by the document page's "Retour à mon dossier" link, to come back to the same search, filters and page.
    rememberListUrl() {
        try {
            sessionStorage.setItem('document-list-url', window.location.pathname + window.location.search);
        } catch {
            // Storage unavailable (private mode…): the link keeps pointing to the unfiltered list.
        }
    }

    // After the back button the browser may restore stale field values: the values the server normalised for the
    // results on display (an invalid or cleaned-up URL parameter is not what was filtered) are the source of truth.
    syncWithResults() {
        const filters = this.element.querySelector('[data-filters]')?.dataset.filters;
        if (!this.hasFormTarget || !filters) {
            return;
        }

        const values = JSON.parse(filters);
        [...this.formTarget.elements].forEach((element) => {
            if (element.name && 'submit' !== element.type && document.activeElement !== element) {
                element.value = values[element.name] ?? '';
            }
        });
    }
}
