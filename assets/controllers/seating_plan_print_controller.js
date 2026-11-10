import { Controller } from '@hotwired/stimulus';

/** The printed seating plan's « Imprimer »: the browser's own print dialog, A4 landscape. */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    print() {
        window.print();
    }
}
