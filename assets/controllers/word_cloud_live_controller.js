import { Controller } from '@hotwired/stimulus';

// The live half of a word cloud: the pilot screen and the projection board both mount this, and
// both redraw from the same payload.
//
// The words arrive **already laid out** - size, colour rung and order computed by
// App\Service\WordCloud\WordCloudWeighting and published at all three scales. Nothing here decides
// what a word looks like, which is what makes the preview an honest rehearsal of the board: two
// screens reading one answer cannot disagree.
//
// Mercure over the bundle's cookie mechanism, like the live contest: a native EventSource cannot
// send an Authorization header, so the subscription is scoped by an httpOnly cookie the controller
// set, and no token ever reaches this file.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'cloud', 'meta', 'latest', 'top', 'status',
        'participants', 'participantsUnit',
        'pending', 'pendingItem', 'pendingEmpty',
    ];

    static values = {
        mercureUrl: String,
        topic: String,
        moderateUrl: String,
        csrfToken: String,
        // The two sentences that carry figures, translated server-side with their placeholders left
        // in. Rebuilding them from the payload beats patching the rendered text: the French and the
        // English put the numbers in different places, and a regex over the wording would break on
        // whichever language it was not written against.
        metaTemplate: String,
        participantsTemplate: String,
    };

    connect() {
        if (!this.mercureUrlValue || !this.topicValue) return;

        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', this.topicValue);

        this.source = new EventSource(url, { withCredentials: true });
        this.source.onmessage = (event) => this.#apply(JSON.parse(event.data));
    }

    disconnect() {
        if (this.source) this.source.close();
    }

    approve(event) {
        this.#moderate(event.currentTarget, 'approve');
    }

    reject(event) {
        this.#moderate(event.currentTarget, 'reject');
    }

    async #moderate(button, decision) {
        const item = button.closest('[data-submission]');
        if (!item) return;

        // Taken off the screen straight away: a teacher moderating in front of a class taps down a
        // queue, and a row that lingers for the length of a round trip gets tapped twice.
        item.remove();
        this.#refreshPendingEmptiness();

        const body = new FormData();
        body.append('decision', decision);

        const response = await fetch(this.moderateUrlValue.replace(/0$/, item.dataset.submission), {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.csrfTokenValue },
            body,
        });

        if (response.ok) this.#apply(await response.json());
    }

    #apply(payload) {
        for (const cloud of this.cloudTargets) {
            const words = payload.words[cloud.dataset.scale] || [];
            cloud.replaceChildren(...words.map((word) => {
                const span = document.createElement('span');
                span.className = `cm-wc-word cm-wc-word--${word.step}`;
                span.style.fontSize = `${word.size}px`;
                span.textContent = word.word;

                return span;
            }));
        }

        if (this.hasMetaTarget) {
            this.metaTarget.textContent = this.#fill(this.metaTemplateValue, payload);
        }

        if (this.hasParticipantsTarget) {
            this.participantsTarget.textContent = String(payload.participantCount);
        }

        if (this.hasParticipantsUnitTarget) {
            this.participantsUnitTarget.textContent = this.#fill(this.participantsTemplateValue, payload);
        }

        // The same five words, drawn two ways: chips carrying their author on the pilot screen,
        // bare words on the board - nobody at the back of a room reads a name in 12px, and putting
        // one on the wall would be naming people in front of the class.
        for (const list of this.latestTargets) {
            const plain = list.dataset.render === 'plain';
            list.replaceChildren(...payload.latest.map((word) => this.#latestEntry(word, plain)));
        }

        if (this.hasTopTarget) {
            this.topTarget.replaceChildren(...payload.top.map((entry) => this.#topRow(entry)));
        }
    }

    #latestEntry(word, plain) {
        if (plain) {
            const bare = document.createElement('b');
            bare.textContent = word.text;

            return bare;
        }

        const chip = document.createElement('span');
        chip.className = 'cm-wc-fluxchip';
        chip.append(document.createTextNode(word.text));
        const who = document.createElement('span');
        who.textContent = word.author;
        chip.append(who);

        return chip;
    }

    #fill(template, payload) {
        return template
            .replace('%words%', String(payload.distinctWords))
            .replace('%submissions%', String(payload.submissionCount))
            .replace('%participants%', String(payload.participantCount));
    }

    #topRow(entry) {
        const row = document.createElement('div');
        row.className = 'cm-wc-top__row';

        const line = document.createElement('div');
        line.className = 'cm-wc-top__line';
        const word = document.createElement('span');
        word.className = 'cm-wc-top__word';
        word.textContent = entry.word;
        const count = document.createElement('span');
        count.className = 'cm-wc-top__count';
        count.textContent = String(entry.count);
        line.append(word, count);

        const bar = document.createElement('div');
        bar.className = 'cm-wc-top__bar';
        const fill = document.createElement('span');
        fill.className = 'cm-wc-top__fill';
        fill.style.width = `${entry.share}%`;
        bar.append(fill);

        row.append(line, bar);

        return row;
    }

    #refreshPendingEmptiness() {
        if (!this.hasPendingTarget) return;

        // The card says so rather than standing empty, and the message is written by the template
        // once - this only puts it back when the last row goes.
        if (this.pendingItemTargets.length === 0 && !this.hasPendingEmptyTarget) {
            const empty = document.createElement('span');
            empty.className = 'cm-wc-list__cell';
            empty.dataset.wordCloudLiveTarget = 'pendingEmpty';
            empty.textContent = this.pendingTarget.dataset.emptyLabel || '';
            this.pendingTarget.append(empty);
        }
    }
}
