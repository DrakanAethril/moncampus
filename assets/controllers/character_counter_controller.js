import { Controller } from '@hotwired/stimulus';

/**
 * « 143 / 2 000 » under a bounded textarea.
 *
 * It exists because a silent truncation is a bug: this repository has already paid for one on the
 * wiki diagram input, where the text simply stopped at the limit without anybody being told. The
 * maxlength attribute stops the typing; this says why.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'output'];
    static values = { max: Number };

    connect() {
        this.count();
    }

    count() {
        const length = this.inputTarget.value.length;
        const template = this.outputTarget.dataset.template || '%count% / %max%';
        this.outputTarget.textContent = template
            .replace('%count%', String(length))
            .replace('%max%', String(this.maxValue));
        this.outputTarget.classList.toggle('text-danger', length >= this.maxValue);
    }
}
