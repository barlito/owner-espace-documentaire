import { Controller } from '@hotwired/stimulus';

/*
 * "Retour à mon dossier" goes back to the list as the client left it (search, filters, page), as remembered by
 * the document-filters controller. Without it (direct link, storage unavailable), the plain link to the list stays.
 */
export default class extends Controller {
    connect() {
        try {
            const url = sessionStorage.getItem('document-list-url');
            if (url && url.startsWith(new URL(this.element.href).pathname)) {
                this.element.href = url;
            }
        } catch {
            // Keep the default link.
        }
    }
}
