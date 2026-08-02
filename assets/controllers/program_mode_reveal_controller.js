import { Controller } from '@hotwired/stimulus';

// Reused on the Program create/edit form for two independent 2-way selects that both share the
// same "file" case value: syllabusMode (App\Enum\ProgramSyllabusMode - topics|file) and
// alternanceCalendarMode (App\Enum\ProgramAlternanceCalendarMode - period|file). "source" is
// whichever fields are meaningful in the non-file mode (topics note / period-group picker),
// "file" is the upload field.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['select', 'source', 'file'];

    connect() {
        this.toggle();
    }

    toggle() {
        const isFile = this.selectTarget.value === 'file';
        this.sourceTargets.forEach((el) => el.classList.toggle('d-none', isFile));
        this.fileTargets.forEach((el) => el.classList.toggle('d-none', !isFile));
    }
}
