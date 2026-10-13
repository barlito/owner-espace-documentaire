import { Controller } from '@hotwired/stimulus';

/*
 * Progressive enhancement of the upload form: the markup is a working multipart form (no-JS fallback).
 * Once connected, files are validated in the browser, then sent one request per file (XMLHttpRequest, to get
 * upload progress), two at a time. The server re-validates everything: the checks here are only feedback.
 */
export default class extends Controller {
    static targets = [
        'input', 'dropzone', 'form', 'fallback', 'enhanced',
        'listSection', 'actions', 'list', 'rowTemplate', 'sendButton', 'sendLabel', 'sendHint',
        'summary', 'successAlert', 'successText', 'partialAlert', 'partialText', 'dashboardLink',
    ];

    static values = {
        uploadUrl: String,
        token: String,
        maxSize: Number,
        maxFiles: Number,
        extensions: Array,
        unsupportedMessage: String,
        tooLargeMessage: String,
        emptyMessage: String,
        interruptedMessage: String,
        concurrency: { type: Number, default: 2 },
    };

    connect() {
        this.items = [];
        this.sequence = 0;
        this.started = false;
        this.dragDepth = 0;
        this.warnBeforeLeaving = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };

        this.enhancedTargets.forEach((element) => element.classList.remove('hidden'));
        this.fallbackTarget.classList.add('hidden');
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.warnBeforeLeaving);
    }

    preventNativeSubmit(event) {
        event.preventDefault();
    }

    // Selection -------------------------------------------------------------------------------------------

    select() {
        this.addFiles(Array.from(this.inputTarget.files));
        // Allows choosing the same file again after removing it.
        this.inputTarget.value = '';
    }

    dragEnter(event) {
        if (!this.hasFiles(event)) return;
        event.preventDefault();
        this.dragDepth += 1;
        this.dropzoneTarget.dataset.dragging = 'true';
    }

    dragOver(event) {
        if (!this.hasFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    }

    dragLeave() {
        this.dragDepth = Math.max(0, this.dragDepth - 1);
        if (0 === this.dragDepth) delete this.dropzoneTarget.dataset.dragging;
    }

    drop(event) {
        if (!this.hasFiles(event)) return;
        event.preventDefault();
        this.dragDepth = 0;
        delete this.dropzoneTarget.dataset.dragging;
        this.addFiles(Array.from(event.dataTransfer.files));
    }

    hasFiles(event) {
        return Array.from(event.dataTransfer?.types ?? []).includes('Files');
    }

    addFiles(files) {
        if (0 === files.length) return;

        this.hideSummary();
        let flagged = 0;

        files.forEach((file) => {
            const item = this.createItem(file);
            const problem = this.problemWith(file, item);

            if (problem) {
                flagged += 1;
                this.setState(item, 'invalid', problem);
            } else {
                this.setState(item, 'pending');
            }
        });

        const added = files.length - flagged;
        const parts = [];
        if (added > 0) parts.push(`${added} ${this.plural(added, 'fichier ajouté', 'fichiers ajoutés')}.`);
        if (flagged > 0) parts.push(`${flagged} ${this.plural(flagged, 'fichier refusé', 'fichiers refusés')} : voir le détail dans la liste.`);
        this.announce(parts.join(' '));
    }

    problemWith(file, current) {
        const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';

        if (!this.extensionsValue.includes(extension)) return this.unsupportedMessageValue;
        if (0 === file.size) return this.emptyMessageValue;
        if (file.size > this.maxSizeValue) return this.tooLargeMessageValue;

        const kept = this.items.filter((item) => item !== current && 'invalid' !== item.state).length;
        if (kept >= this.maxFilesValue) return `Au-delà de ${this.maxFilesValue} fichiers par envoi.`;

        return null;
    }

    createItem(file) {
        const id = ++this.sequence;
        const element = this.rowTemplateTarget.content.firstElementChild.cloneNode(true);
        const select = element.querySelector('[data-type-select]');
        const label = element.querySelector('[data-type-label]');

        select.id = `upload-type-${id}`;
        label.htmlFor = select.id;
        label.textContent = `Nature de la pièce : ${file.name}`;
        element.querySelector('[data-name]').textContent = file.name;
        element.querySelector('[data-name]').title = file.name;
        element.querySelector('[data-size]').textContent = this.formatSize(file.size);
        element.querySelector('[data-remove-label]').textContent = `Retirer ${file.name}`;

        const item = { id, file, element, state: 'pending', request: null };
        this.items.push(item);
        this.listTarget.append(element);

        return item;
    }

    // Sending ---------------------------------------------------------------------------------------------

    send() {
        if (0 === this.countOf('pending')) return;

        this.hideSummary();
        this.start();
        this.announce(`Envoi de ${this.countOf('pending')} ${this.plural(this.countOf('pending'), 'pièce', 'pièces')} en cours.`);
        this.pump();
    }

    retry(event) {
        const item = this.itemFor(event);
        if (!item || 'interrupted' !== item.state) return;

        this.hideSummary();
        this.setState(item, 'pending');
        this.start();
        this.pump();
    }

    start() {
        if (!this.started) window.addEventListener('beforeunload', this.warnBeforeLeaving);
        this.started = true;
        this.refresh();
    }

    pump() {
        if (!this.started) return;

        let slots = this.concurrencyValue - this.countOf('sending');
        for (const item of this.items) {
            if (slots <= 0) break;
            if ('pending' === item.state) {
                this.upload(item);
                slots -= 1;
            }
        }

        if (0 === this.countOf('sending') && 0 === this.countOf('pending')) this.finish();
    }

    upload(item) {
        const body = new FormData();
        body.append('file', item.file, item.file.name);
        body.append('type', item.element.querySelector('[data-type-select]').value);
        body.append('_token', this.tokenValue);

        const request = new XMLHttpRequest();
        item.request = request;
        this.setProgress(item, 0);
        this.setState(item, 'sending');

        request.open('POST', this.uploadUrlValue);
        request.setRequestHeader('Accept', 'application/json');

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) this.setProgress(item, Math.round((event.loaded / event.total) * 100));
        });
        request.addEventListener('load', () => this.handleResponse(item, request));
        const interrupted = () => this.settle(item, 'interrupted', this.interruptedMessageValue);
        request.addEventListener('error', interrupted);
        request.addEventListener('abort', interrupted);
        request.addEventListener('timeout', interrupted);

        request.send(body);
    }

    handleResponse(item, request) {
        let payload = null;
        try {
            payload = JSON.parse(request.responseText);
        } catch {
            // Not JSON: handled below from the status code.
        }

        if (201 === request.status) {
            // Markup rendered by our own server (no user data), whose element is then replaced by live updates.
            if (payload?.status_html) item.element.querySelector('[data-badge="done"]').innerHTML = payload.status_html;
            this.settle(item, 'done');
        } else if (422 === request.status && payload?.error) {
            this.settle(item, 'refused', payload.error);
        } else if (403 === request.status) {
            this.settle(item, 'interrupted', 'Votre session a expiré : rechargez la page, puis réessayez.');
        } else {
            this.settle(item, 'interrupted', 'Le serveur n\'a pas pu enregistrer ce fichier, réessayez.');
        }
    }

    settle(item, state, message) {
        item.request = null;
        this.setState(item, state, message);
        this.pump();
    }

    finish() {
        this.started = false;
        window.removeEventListener('beforeunload', this.warnBeforeLeaving);
        this.refresh();
        this.showSummary();
    }

    // Summary ---------------------------------------------------------------------------------------------

    showSummary() {
        const received = this.countOf('done');
        const total = received + this.countOf('refused') + this.countOf('interrupted') + this.countOf('invalid');
        const complete = received === total;

        let text;
        if (complete) {
            text = 1 === received
                ? '1 pièce reçue. Son analyse va démarrer.'
                : `${received} pièces reçues. Leur analyse va démarrer.`;
            this.successTextTarget.textContent = text;
        } else {
            text = `${received} ${this.plural(received, 'pièce reçue', 'pièces reçues')} sur ${total}. `
                + 'Les pièces refusées ou interrompues n\'ont pas été transmises : corrigez-les ou réessayez.';
            this.partialTextTarget.textContent = text;
        }

        this.successAlertTarget.classList.toggle('hidden', !complete);
        this.partialAlertTarget.classList.toggle('hidden', complete);
        this.dashboardLinkTarget.classList.toggle('hidden', 0 === received);
        this.summaryTarget.classList.remove('hidden');
        this.announce(text);
    }

    hideSummary() {
        this.summaryTarget.classList.add('hidden');
    }

    addMore() {
        this.items.filter((item) => 'done' === item.state).forEach((item) => this.discard(item));
        this.hideSummary();
        this.refresh();
        this.dropzoneTarget.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        this.inputTarget.focus();
    }

    remove(event) {
        const item = this.itemFor(event);
        if (!item || 'sending' === item.state) return;

        this.discard(item);
        this.announce(`${item.file.name} retiré de la liste.`);
        this.inputTarget.focus();
        this.pump();
        this.refresh();
    }

    discard(item) {
        item.element.remove();
        this.items = this.items.filter((candidate) => candidate !== item);
    }

    // Rendering -------------------------------------------------------------------------------------------

    setState(item, state, message = '') {
        item.state = state;
        const row = item.element;
        row.dataset.state = state;

        row.querySelectorAll('[data-badge]').forEach((badge) => badge.classList.toggle('hidden', badge.dataset.badge !== state));

        const reason = row.querySelector('[data-reason]');
        const reasonText = { invalid: `Refusé : ${message}`, refused: `Refusée : ${message}`, interrupted: message }[state] ?? '';
        reason.textContent = reasonText;
        reason.classList.toggle('hidden', '' === reasonText);
        reason.classList.toggle('text-warning-800', 'interrupted' === state);
        reason.classList.toggle('text-danger-700', 'interrupted' !== state);

        const editable = 'pending' === state || 'interrupted' === state;
        row.querySelector('[data-type-field]').classList.toggle('hidden', 'invalid' === state);
        row.querySelector('[data-type-select]').disabled = !editable;
        row.querySelector('[data-retry]').classList.toggle('hidden', 'interrupted' !== state);
        // invisible (not hidden) keeps the columns aligned between rows.
        row.querySelector('[data-remove]').classList.toggle('invisible', 'done' === state);
        row.querySelector('[data-remove]').disabled = 'sending' === state;
        row.querySelector('[data-progress-wrap]').classList.toggle('hidden', 'sending' !== state);

        const name = item.file.name;
        if ('done' === state) this.announce(`${name} : pièce reçue.`);
        if ('refused' === state) this.announce(`${name} : refusée. ${message}`);
        if ('interrupted' === state) this.announce(`${name} : envoi interrompu. ${message}`);

        this.refresh();
    }

    setProgress(item, percent) {
        const row = item.element;
        row.querySelector('[data-progress-bar]').style.width = `${percent}%`;
        row.querySelector('[data-progress]').setAttribute('aria-valuenow', String(percent));
        row.querySelector('[data-progress-text]').textContent = `${percent} %`;
    }

    refresh() {
        const pending = this.countOf('pending');
        const sending = this.countOf('sending');
        const busy = this.started || sending > 0;

        this.listSectionTarget.classList.toggle('hidden', 0 === this.items.length);
        this.actionsTarget.classList.toggle('hidden', 0 === pending && !busy);
        this.sendButtonTarget.disabled = busy || 0 === pending;
        this.sendLabelTarget.textContent = pending > 0
            ? `Envoyer ${pending} ${this.plural(pending, 'pièce', 'pièces')}`
            : 'Envoyer';

        if (busy) {
            this.sendHintTarget.textContent = 'Envoi en cours : ne quittez pas cette page.';
        } else if (this.countOf('invalid') > 0 && pending > 0) {
            this.sendHintTarget.textContent = 'Les fichiers refusés ne seront pas envoyés.';
        } else {
            this.sendHintTarget.textContent = '';
        }
    }

    // Helpers ---------------------------------------------------------------------------------------------

    countOf(state) {
        return this.items.filter((item) => state === item.state).length;
    }

    itemFor(event) {
        const row = event.currentTarget.closest('[data-state]');

        return this.items.find((item) => item.element === row);
    }

    plural(count, one, many) {
        return count < 2 ? one : many;
    }

    // Same rules as the Twig "file_size" filter.
    formatSize(bytes) {
        if (bytes < 1024) return `${bytes} ${bytes < 2 ? 'octet' : 'octets'}`;
        if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`;

        const [value, unit] = bytes < 1024 ** 3 ? [bytes / 1024 ** 2, 'Mo'] : [bytes / 1024 ** 3, 'Go'];

        return `${value.toFixed(1).replace(/\.0$/, '').replace('.', ',')} ${unit}`;
    }

    // The page's single live region (base layout), shared with the live document updates.
    announce(message) {
        const region = document.getElementById('live-status');
        if (!message || !region) return;
        region.textContent = '';
        window.setTimeout(() => { region.textContent = message; }, 50);
    }
}
