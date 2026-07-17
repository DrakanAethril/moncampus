import { Controller } from '@hotwired/stimulus';

// Screen 1e, "ordre" question type - move-up/move-down reordering (same lightweight pattern as
// the answer editor's own reordering, assets/controllers/quiz_question_editor_controller.js: no
// drag library, just DOM position swaps). Submission order of the hidden answers[] inputs *is*
// the student's proposed sequence - App\Controller\ProgramQuizAttemptController::answer() and
// App\Service\QuizAttemptGrader compare it against each answer's true orderIndex.
export default class extends Controller {
    static targets = ['row'];

    moveUp(event) {
        const row = event.currentTarget.closest('[data-quiz-reorder-target="row"]');
        const previous = row.previousElementSibling;
        if (previous) {
            row.parentElement.insertBefore(row, previous);
        }
    }

    moveDown(event) {
        const row = event.currentTarget.closest('[data-quiz-reorder-target="row"]');
        const next = row.nextElementSibling;
        if (next) {
            row.parentElement.insertBefore(next, row);
        }
    }
}
