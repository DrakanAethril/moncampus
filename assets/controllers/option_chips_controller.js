import { Controller } from '@hotwired/stimulus';

// The "Options ciblées" chips of a step-1 settings form - the audio tool's screen 2
// (design_handoff_enregistrements_audio) and the video tool's, which is its port.
//
// One thing only moves on those screens: the targeted options follow the chosen class. Every option of
// every candidate class is in the DOM, each carrying its own classes as an attribute, and only those
// of the selected class stay visible - the same convention as the assignment wizard, which loads
// quizzes, group batches and evaluations the same way.
//
// The whole block disappears when the class has no option at all: "visible si la classe a des
// options", says the handoff.
//
// Named after what it does rather than after the first screen that needed it: the second one
// arrived, and a video screen carrying `data-controller="audio-recording-settings"` would have been
// a lie in the DOM.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['programSelect', 'optionsField', 'optionChip'];

    static values = { program: Number };

    connect() {
        this.refresh();
    }

    programChanged() {
        this.refresh();
    }

    refresh() {
        const programId = String(this.hasProgramSelectTarget ? this.programSelectTarget.value : this.programValue);
        let visible = 0;

        for (const chip of this.optionChipTargets) {
            const matches = programId !== '' && chip.dataset.programs.split(' ').includes(programId);
            chip.hidden = !matches;
            // A hidden option stays in the DOM: unticking keeps it from going back to the server after
            // a change of class. The server would drop it anyway, but the screen would lie for the
            // length of the round trip.
            if (!matches) chip.querySelector('input').checked = false;
            if (matches) visible += 1;
        }

        if (this.hasOptionsFieldTarget) this.optionsFieldTarget.hidden = visible === 0;
    }
}
