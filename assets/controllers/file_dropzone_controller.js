import { Controller } from '@hotwired/stimulus';

// Drag-and-drop + removable file chips on top of a plain multiple <input type="file"> (design/
// design_handoff_messagerie's compose attachments zone) - no new upload mechanism, this only
// rebuilds the input's FileList via the DataTransfer API so both dropping files and removing a
// chip keep working with the same hidden input Symfony's FileType constraints validate server-side.
export default class extends Controller {
    static targets = ['zone', 'input', 'list'];
    static values = {
        maxSize: Number,
        tooLargeText: String,
    };

    connect() {
        this.zoneTarget.addEventListener('dragover', this._onDragOver);
        this.zoneTarget.addEventListener('dragleave', this._onDragLeave);
        this.zoneTarget.addEventListener('drop', this._onDrop);
        this.update();
    }

    disconnect() {
        this.zoneTarget.removeEventListener('dragover', this._onDragOver);
        this.zoneTarget.removeEventListener('dragleave', this._onDragLeave);
        this.zoneTarget.removeEventListener('drop', this._onDrop);
    }

    _onDragOver = (event) => {
        event.preventDefault();
        this.zoneTarget.classList.add('is-dragover');
    };

    _onDragLeave = () => {
        this.zoneTarget.classList.remove('is-dragover');
    };

    _onDrop = (event) => {
        event.preventDefault();
        this.zoneTarget.classList.remove('is-dragover');
        this._setFiles([...this.inputTarget.files, ...event.dataTransfer.files]);
    };

    update() {
        this._render(this.inputTarget.files);
    }

    remove(event) {
        const index = Number(event.currentTarget.dataset.index);
        const remaining = Array.from(this.inputTarget.files).filter((_, i) => i !== index);
        this._setFiles(remaining);
    }

    _setFiles(files) {
        const transfer = new DataTransfer();
        files.forEach((file) => transfer.items.add(file));
        this.inputTarget.files = transfer.files;
        this._render(this.inputTarget.files);
    }

    _render(files) {
        this.listTarget.innerHTML = '';
        Array.from(files).forEach((file, index) => {
            const chip = document.createElement('div');
            chip.className = 'cm-mail-file-chip';

            const extension = (file.name.split('.').pop() || '').slice(0, 4).toUpperCase();
            const tooLarge = this.hasMaxSizeValue && this.maxSizeValue > 0 && file.size > this.maxSizeValue;

            chip.innerHTML = `
                <span class="cm-mail-file-chip__type">${extension}</span>
                <span style="min-width:0">
                    <span class="cm-mail-file-chip__name">${file.name}</span>
                    <span class="cm-mail-file-chip__size">${this._formatSize(file.size)}</span>
                </span>
            `;

            if (tooLarge) {
                const warning = document.createElement('div');
                warning.className = 'cm-mail-alert-danger';
                warning.style.marginTop = '4px';
                warning.textContent = this.tooLargeTextValue;
                chip.appendChild(warning);
            }

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'cm-mail-file-chip__remove';
            remove.dataset.index = String(index);
            remove.dataset.action = `${this.identifier}#remove`;
            remove.textContent = '×';
            chip.appendChild(remove);

            this.listTarget.appendChild(chip);
        });
    }

    _formatSize(bytes) {
        if (bytes < 1024) {
            return `${bytes} o`;
        }
        if (bytes < 1024 * 1024) {
            return `${Math.round(bytes / 1024)} Ko`;
        }

        return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
    }
}
