import { Controller } from '@hotwired/stimulus';

// The list pane (design/design_handoff_messagerie #1) - client-side search/filter over whatever
// rows are currently loaded (search across subject/body server-side is still v2, see
// design/validated/internal-messaging.md), plus "Charger plus" incremental loading against
// App\Controller\MessageController::rows(), which returns a rendered HTML fragment (same
// messages/_thread_rows.html.twig partial the initial page uses) rather than JSON.
export default class extends Controller {
    static targets = ['body', 'search', 'filterAll', 'filterUnread', 'noResults', 'footerCount', 'loadMore'];
    static values = {
        folder: String,
        offset: Number,
        hasMore: Boolean,
        selected: String,
        rowsUrl: String,
        noResultsText: String,
    };

    connect() {
        this.unreadOnly = false;
    }

    search() {
        this._applyFilters();
    }

    filterAll() {
        this.unreadOnly = false;
        this.filterAllTarget.classList.add('is-active');
        this.filterUnreadTarget.classList.remove('is-active');
        this._applyFilters();
    }

    filterUnread() {
        this.unreadOnly = true;
        this.filterUnreadTarget.classList.add('is-active');
        this.filterAllTarget.classList.remove('is-active');
        this._applyFilters();
    }

    _applyFilters() {
        const term = this.searchTarget.value.trim().toLowerCase();
        let visibleCount = 0;

        this.bodyTarget.querySelectorAll('[data-message-inbox-target="row"]').forEach((row) => {
            const matchesSearch = '' === term || row.dataset.messageInboxSearchValue.includes(term);
            const matchesFilter = !this.unreadOnly || row.classList.contains('is-unread');
            const visible = matchesSearch && matchesFilter;
            row.classList.toggle('d-none', !visible);
            if (visible) {
                visibleCount += 1;
            }
        });

        // Day headers with every row underneath them hidden would otherwise float above an empty
        // gap - hide those too rather than teaching the search filter to understand day grouping.
        this.bodyTarget.querySelectorAll('[data-message-inbox-target="dayHeader"]').forEach((header) => {
            let sibling = header.nextElementSibling;
            let hasVisibleRow = false;
            while (sibling && !sibling.matches('[data-message-inbox-target="dayHeader"]')) {
                if (!sibling.classList.contains('d-none')) {
                    hasVisibleRow = true;
                }
                sibling = sibling.nextElementSibling;
            }
            header.classList.toggle('d-none', !hasVisibleRow);
        });

        this.noResultsTarget.classList.toggle('d-none', 0 !== visibleCount);
        // "Charger plus" only makes sense against the unfiltered set server-side - hide it while
        // a search/filter is active rather than mixing a locally-filtered view with a freshly
        // fetched, unfiltered batch.
        if (this.hasLoadMoreTarget) {
            this.loadMoreTarget.classList.toggle('d-none', '' !== term || this.unreadOnly);
        }
    }

    async loadMore() {
        this.loadMoreTarget.disabled = true;
        const previousLabel = this.loadMoreTarget.textContent;
        this.loadMoreTarget.textContent = '…';

        try {
            const url = new URL(this.rowsUrlValue, window.location.origin);
            url.searchParams.set('folder', this.folderValue);
            url.searchParams.set('offset', String(this.offsetValue));
            if (this.selectedValue) {
                url.searchParams.set('selected', this.selectedValue);
            }

            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const { html, hasMore, total } = await response.json();

            const fragment = document.createElement('div');
            fragment.innerHTML = html;

            // Avoid a day header immediately repeating the one already at the bottom of the
            // currently-rendered list, for the (common) case where a batch boundary falls mid-day.
            const existingHeaders = this.bodyTarget.querySelectorAll('[data-message-inbox-target="dayHeader"]');
            const lastHeader = existingHeaders[existingHeaders.length - 1];
            const firstNewHeader = fragment.querySelector('[data-message-inbox-target="dayHeader"]');
            if (lastHeader && firstNewHeader && lastHeader.textContent === firstNewHeader.textContent) {
                firstNewHeader.remove();
            }

            const addedRows = fragment.querySelectorAll('[data-message-inbox-target="row"]').length;
            while (fragment.firstChild) {
                this.bodyTarget.insertBefore(fragment.firstChild, this.noResultsTarget);
            }

            this.offsetValue += addedRows;
            this.hasMoreValue = hasMore;
            this.loadMoreTarget.classList.toggle('d-none', !hasMore);
            this.footerCountTarget.textContent = this.footerCountTarget.textContent.replace(/^\d+/, String(this.offsetValue)).replace(/\d+$/, String(total));
        } finally {
            this.loadMoreTarget.disabled = false;
            this.loadMoreTarget.textContent = previousLabel;
        }
    }
}
