/*
 * The entrypoint of a live contest's phone pages - /live, the guest page, the game, the closed
 * contest (templates/quiz_live/_phone_page.html.twig) - in place of `app`.
 *
 * A class scans the QR code within the same minute, on the same Wi-Fi: `app` would hand each phone
 * the whole platform first - its design system, Tabler, Turbo and some forty controllers, the
 * virtual machines' terminal among them: 93 requests and 690 kB compressed (measured on
 * 2026-11-23), twenty megabytes for a class before the first question. These pages draw nothing but
 * the contest's own screens (assets/styles/quiz_live/), so they load the contest's controllers and
 * nothing else.
 */
import { Application } from '@hotwired/stimulus';
import QuizLivePlayController from './controllers/quiz_live_play_controller.js';
import QuizLiveGuestController from './controllers/quiz_live_guest_controller.js';
import DeploymentNoticeController from './controllers/deployment_notice_controller.js';
// What every page of the site does for the retired /campus-app/ (see the header of that module).
import './web_app/retired_campus_app.js';

const application = Application.start();
application.register('quiz-live-play', QuizLivePlayController);
application.register('quiz-live-guest', QuizLiveGuestController);
application.register('deployment-notice', DeploymentNoticeController);
