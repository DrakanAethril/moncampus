<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EvaluationPeriod;
use App\Entity\EvaluationPeriodGroup;
use App\Entity\FeatureRoleSetting;
use App\Entity\GuestAccount;
use App\Entity\IpRange;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProxmoxHost;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizTemplate;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Entity\UserFeatureAccess;
use App\Entity\VmBatch;
use App\Enum\Feature;
use App\Enum\FeatureAccessState;
use App\Enum\GameTrack;
use App\Enum\QuestionType;
use App\Enum\QuizMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One HTTP request per (role, main screen), asserting what the app answers.
 *
 * This is the cheap safety net this repository was missing: with 663 routes, twelve Voters and no
 * other functional test, most regressions show up as a screen that stops rendering (500) or as one
 * that quietly starts letting the wrong role in. Both are caught here.
 *
 * The expected codes are pinned deliberately, not asserted loosely as "not a 500":
 *
 *   200 - the screen renders for that role
 *   403 - the screen exists but that role must not reach it (access_control or a Voter)
 *   302 - the screen hands over to a program-scoped URL (the role has exactly one program here)
 *
 * A 403 turning into a 200 is a security regression; a 200 turning into a 403 is a broken screen.
 * Neither is caught by asserting "< 500", which is why the table below is explicit.
 *
 * Everything runs against the empty `_test` schema plus the minimal fixture built in
 * FunctionalTestCase, so a screen that only renders because the developer's database happens to
 * hold the right data fails here.
 */
class RoleAccessSmokeTest extends FunctionalTestCase
{
    private User $student;
    private User $teacher;
    private User $admin;
    private User $tutor;
    private Program $program;
    private int $studentAccountId;
    private int $teacherAccountId;
    private int $batchId;
    private string $timelinePath;
    private string $statementsPath;
    private string $gesturesPath;
    private string $rewardsPath;
    private string $observationPath;
    private string $gameSettingsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'smoke.student');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'smoke.teacher');
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'smoke.admin');
        $this->tutor = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'smoke.tutor');

        $this->program = $this->createProgram([$this->student], [$this->teacher], $this->admin);
        $this->createMachineAccounts();
        $this->createSupervisedAttempt();
        $this->openTheGame();
        $this->statementsPath = '/programs/'.$this->program->getId().'/game/statements';
        $this->gesturesPath = '/programs/'.$this->program->getId().'/game/gestures';
        $this->rewardsPath = '/programs/'.$this->program->getId().'/game/rewards';
        $this->observationPath = '/programs/'.$this->program->getId().'/game/observation';
        $this->gameSettingsPath = '/programs/'.$this->program->getId().'/settings/game';
    }

    /**
     * The campus game switched on for the fixture formation, with a calendar it can actually score.
     *
     * Both switches are needed and the second one is the point: the feature being on for every role
     * (FunctionalTestCase opens the whole catalogue) still shows nobody a game until a formation has
     * declared itself. Without the period group the screens would answer « pas de période » - a 200,
     * but not the screen anybody is trying to pin.
     */
    private function openTheGame(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $today = new \DateTimeImmutable('today');

        $group = new EvaluationPeriodGroup('Semestres de test');
        $group->setCreatedBy($this->admin);
        $entityManager->persist($group);

        $period = new EvaluationPeriod();
        $period->setName('Semestre de test');
        $period->setStartDate($today->modify('-2 months'));
        $period->setEndDate($today->modify('+2 months'));
        $period->setEvaluationPeriodGroup($group);
        $entityManager->persist($period);

        $this->program->setEvaluationPeriodGroup($group);
        $this->program->setGameEnabled(true);
        $this->program->setGameTrack(GameTrack::Sisr);

        $entityManager->flush();
    }

    /**
     * One supervised évaluation with one attempt on it, so the frise has something to render.
     *
     * Built here rather than left out for the same reason as the machine accounts above: the
     * refusal cannot be proved against a timeline that does not exist - a missing attempt answers
     * 404, and the student line would then pass for the wrong reason. With a real supervised
     * attempt, the 403 is StructureAccessChecker::isProgramTeacher()'s, which is what is under test.
     */
    private function createSupervisedAttempt(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $template = new QuizTemplate($this->teacher);
        $template->setName('Contrôle de test');
        $template->setCreatedBy($this->teacher);
        $entityManager->persist($template);

        $instance = new QuizInstance($this->program, $this->teacher);
        $instance->setName('Contrôle de test');
        $instance->setMode(QuizMode::Evaluation);
        $instance->setSourceTemplate($template);
        $instance->setSupervised(true);
        $entityManager->persist($instance);

        $question = new QuizInstanceQuestion($instance);
        $question->setType(QuestionType::Qcm);
        $question->setLabel('Question de test');
        $instance->addQuestion($question);
        $entityManager->persist($question);

        $attempt = new QuizAttempt($instance, $this->student);
        $answer = new QuizAttemptAnswer($attempt, $question);
        $attempt->addAttemptAnswer($answer);
        $entityManager->persist($attempt);
        $entityManager->persist($answer);

        $entityManager->flush();

        $this->timelinePath = \sprintf(
            '/programs/%d/quiz/%d/attempt/%d/timeline',
            $this->program->getId() ?? 0,
            $instance->getId() ?? 0,
            $attempt->getId() ?? 0,
        );
    }

    /**
     * One machine of one batch, with an account for the student and one for the teacher.
     *
     * Built here rather than left out because the console's refusal cannot be proved against a
     * machine that does not exist: /console/{id} on a missing account answers 404, which would make
     * the line below pass for the wrong reason. With a real account owned by the student, the 403
     * is App\Security\Voter\GuestConsoleVoter's, which is the thing under test.
     */
    private function createMachineAccounts(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $host = new ProxmoxHost('Hôte de test', 'pve-test', 'svc');
        $range = new IpRange('Plage de test', $host, '10.42.7.0/24', '10.42.7.254', '10.42.7.10', '10.42.7.200');
        $batch = new VmBatch('Lot de test', $this->program, $host, $range, 9000, 'pve');

        foreach ([$host, $range, $batch] as $row) {
            // AuditableTrait: created_by is NOT NULL on all three.
            $row->setCreatedBy($this->admin);
            $entityManager->persist($row);
        }

        foreach ([[$this->student, 'smoke-student'], [$this->teacher, 'smoke-teacher']] as [$owner, $login]) {
            $account = new GuestAccount($host, 'pve', 1203, $login);
            $account->setUser($owner);
            $account->setBatch($batch);
            $entityManager->persist($account);
        }

        $entityManager->flush();

        $this->batchId = $batch->getId() ?? 0;
        $accounts = $entityManager->getRepository(GuestAccount::class)->findBy(['batch' => $batch]);

        foreach ($accounts as $account) {
            if ($account->getUser() === $this->student) {
                $this->studentAccountId = $account->getId() ?? 0;
            } else {
                $this->teacherAccountId = $account->getId() ?? 0;
            }
        }
    }

    public function testStudentScreens(): void
    {
        $this->assertScreens($this->student, [
            '/' => 200,
            '/student-work' => 200,
            // The campus game. « Ma progression » exists for the student of a formation that plays;
            // the board of six levels is open to every role the feature is on for; the wording of
            // those levels is an administrator's screen; and the relevé is the teachers' - a class
            // stating its own attendance would not be a relevé.
            '/game' => 200,
            '/game/journal' => 200,
            '/game/levels' => 200,
            '/settings/game/levels' => 403,
            $this->statementsPath => 403,
            $this->gesturesPath => 403,
            // Declared engagement: the student files, a teacher of the class reviews.
            '/game/engagement/new' => 200,
            '/game/engagements' => 404,
            // « Leveling » - the student's own four-tab reading, from the profile menu.
            '/game/leveling' => 200,
            '/game/leveling/team' => 200,
            '/game/leveling/ranking' => 200,
            '/game/leveling/rules' => 200,
            '/settings/game/figures' => 403,
            // The catalogue is the teachers': a student reads their shelf, never the shelf's source.
            $this->rewardsPath => 403,
            // « Observation » is the administration's alone - it names every student beside their
            // index, which is the one thing the students' own ranking never does.
            $this->observationPath => 403,
            // Figures, ranking, teams. The ranking answers on the month running now; an earlier
            // month is the same screen with ?month=, and the year the same with ?scope=year.
            '/game/alias' => 200,
            // « Mon titre affiché » - the student's own choice among the titles of the levels they
            // have reached, in each filière they play in.
            '/game/title' => 200,
            '/game/ranking' => 200,
            '/game/ranking?scope=year' => 200,
            '/game/team' => 200,
            $this->gameSettingsPath => 403,
            // « Séquences de l'année » hands over to the single formation this student belongs to
            // rather than drawing a picker with one card in it (2026-08-17). The list still renders
            // for a student straddling two, and for the empty state.
            '/my/courses' => 302,
            // « Documents partagés » - open to the role, empty until a teacher shares something.
            '/my/shared-documents' => 200,
            '/my/applications' => 200,
            '/agenda' => 200,
            '/messages' => 200,
            '/tickets' => 200,
            // « Mes machines virtuelles » - open to every authenticated role and empty
            // until somebody holds an account on one; what may be *done* to a machine is the
            // GuestAccountVoter's answer, one machine at a time.
            '/my/machines' => 200,
            '/profile' => 200,
            '/about' => 200,
            '/help' => 200,
            '/changelog' => 200,
            // Open to every role on purpose: the source is public and the students are its readers.
            '/technical' => 200,
            '/technical/data-model' => 200,
            '/resources/mobile-app' => 200,
            // The documentation base is open to every account - what narrows it is the perimeter
            // and the visibility of each article, not the door.
            '/documentation' => 200,
            // Writing and the reading figures are not a reader's business.
            '/documentation/articles/new' => 403,
            '/documentation/manage/dashboard' => 403,
            '/documentation/manage/tags' => 403,
            // The wiki. /wiki/personal renders the invitation page rather than redirecting, since
            // nothing is created until its owner asks - that 200 is the consent rule holding.
            '/wiki' => 302,
            '/wiki/personal' => 200,
            '/wiki/shared' => 200,
            // Supervision and composing an audience are both teacher/staff business.
            '/wiki/students' => 403,
            '/wiki/new' => 403,
            // Both hand over to a screen scoped to the student's own program/mailbox.
            '/my/timetable' => 302,
            '/school-mail' => 302,
            // Teaching and back-office areas: a student must never get in.
            '/timetable' => 403,
            '/assignments' => 403,
            '/tools/lesson-log' => 403,
            '/tools/gradebook' => 403,
            '/tools/quiz-live' => 403,
            '/tools/job-search-tracking' => 403,
            '/tools/quiz' => 403,
            // The frise of a supervised copy: the teachers of the formation and the staff read it,
            // never the student it is about.
            $this->timelinePath => 403,
            '/tools/videos' => 403,
            // No student library, and no entry point: what a student sees is unchanged - the file
            // inside the assignment, exactly as today (design/validated/file-library.md).
            '/tools/file-library' => 403,
            '/tools/file-library/trash' => 403,
            '/tools/file-library/search' => 403,
            // The quiz library's classement: the same door as the library itself.
            '/library/quiz' => 403,
            '/library/quiz/search' => 403,
            '/library/quiz/import/assistant' => 403,
            // Outils > Sondages is the author's side: a student answers a survey, never writes one.
            '/surveys' => 403,
            '/surveys/templates' => 403,
            // The survey library's classement: the same door as the library itself.
            '/surveys/templates/search' => 403,
            '/surveys/campaigns' => 403,
            '/surveys/templates/new' => 403,
            // Their own door - open to every account, and empty until a campaign aims at them.
            '/my-surveys' => 200,
            '/progression' => 403,
            '/library/sequences' => 403,
            '/library/sequences/assistant' => 403,
            // Outils > Partages. A student is never a reader of a share, under any scope, and
            // neither is a tutor - the door says so once and ContentShareAccess says it again.
            '/shares' => 403,
            '/shares/mine' => 403,
            '/shares/catalog' => 403,
            '/help/manage' => 403,
            '/settings/configuration' => 403,
            '/settings/teaching' => 403,
            '/features' => 403,
            '/directory/users' => 403,
            '/ufa' => 403,
            '/ufa/configuration/contract-import' => 403,
            '/eco/parcours' => 403,
        ]);
    }

    public function testTeacherScreens(): void
    {
        $this->assertScreens($this->teacher, [
            '/' => 200,
            // A teacher plays no game: they have no formation *as a student*, so their own screens
            // do not exist. The board of levels is a poster and stays open, and the relevé is
            // theirs.
            '/game' => 404,
            '/game/journal' => 404,
            '/game/levels' => 200,
            '/settings/game/levels' => 403,
            $this->statementsPath => 200,
            $this->gesturesPath => 200,
            // The council is the professeur principal's, and isProgramReferentTeacher() is
            // deliberately NOT staff-bypassed - a teacher of the class who does not carry the
            // referent remit does not get in, which is exactly what this row pins.
            // A teacher files nothing - they are nobody's student - and reviews everything.
            '/game/engagement/new' => 404,
            '/game/engagements' => 200,
            '/game/leveling' => 404,
            // The catalogue and the barème are the administration's while the game is being
            // settled (2026-08-28): a teacher gives gestures and holds relevés, and settles
            // nothing. Reopening either to the referent teacher is one line in each controller.
            $this->rewardsPath => 403,
            $this->observationPath => 403,
            '/game/alias' => 404,
            '/game/title' => 404,
            '/game/ranking' => 404,
            '/game/team' => 404,
            $this->gameSettingsPath => 403,
            // The course-space index is the student's own list of programs; a teacher reaches the
            // same sequences from their program screens instead.
            '/my/courses' => 403,
            // A teacher shares *from* their file library and reads the result back through the
            // file's utilisations; the student list is not their screen.
            '/my/shared-documents' => 403,
            '/timetable' => 200,
            '/assignments' => 200,
            '/progression' => 200,
            '/library/sequences' => 200,
            '/library/sequences/assistant' => 200,
            '/shares' => 200,
            '/shares/mine' => 200,
            '/shares/catalog' => 200,
            // Outils > Sondages - one entry serving teachers and staff alike, since the menu it
            // sits in is already gated on exactly those two.
            '/surveys' => 302,
            '/surveys/templates' => 200,
            '/surveys/templates/search' => 200,
            '/surveys/campaigns' => 200,
            '/surveys/templates/new' => 200,
            // Also a respondent: a teacher aimed at by a satisfaction survey has no travail à
            // faire, so this screen and the home card are their only door (§7.9).
            '/my-surveys' => 200,
            // The results screens hang off a campaign id, so an empty database answers 404 rather
            // than 403 - the door itself is pinned by SurveyVoterTest. What matters here is that
            // they are *reachable*: a 403 would mean the role lost access.
            '/surveys/campaigns/999999' => 404,
            '/surveys/campaigns/999999/responses' => 404,
            '/surveys/campaigns/999999/pending' => 404,
            '/surveys/campaigns/999999/export' => 404,
            '/surveys/campaigns/999999/replay' => 404,
            // The series screens, including the individual comparison - reachable only from the
            // wave comparison, never from the menu, but a route all the same (§7.15).
            '/surveys/series/999999' => 404,
            '/surveys/series/999999/individual' => 404,
            '/agenda' => 200,
            '/messages' => 200,
            '/tickets' => 200,
            // « Mes machines virtuelles » - open to every authenticated role and empty
            // until somebody holds an account on one; what may be *done* to a machine is the
            // GuestAccountVoter's answer, one machine at a time.
            '/my/machines' => 200,
            '/profile' => 200,
            '/about' => 200,
            '/help' => 200,
            '/changelog' => 200,
            // Open to every role on purpose: the source is public and the students are its readers.
            '/technical' => 200,
            '/technical/data-model' => 200,
            '/resources/mobile-app' => 200,
            // A teacher reads the base and writes in it, on their own perimeter; the reading
            // figures and the tag referential stay staff/admin business.
            '/documentation' => 200,
            '/documentation/articles/new' => 200,
            '/documentation/manage/dashboard' => 403,
            '/documentation/manage/tags' => 403,
            // The wiki, supervision side included: a teacher reads every student wiki, and
            // composes extra ones.
            '/wiki' => 302,
            '/wiki/personal' => 200,
            '/wiki/shared' => 200,
            '/wiki/students' => 200,
            '/wiki/new' => 200,
            // Student-only screens.
            '/my/timetable' => 403,
            '/student-work' => 403,
            '/school-mail' => 403,
            '/my/applications' => 403,
            // The three class pickers of the Outils menu. They render rather than redirect here
            // because Program::$visibility defaults to StaffAdmin, which puts the fixture's own
            // class out of findAllForTeacher's reach: the picker has nothing to offer and says so
            // (toolsNoVisibleClassMessage). A 403 would be the regression - having no class to
            // work on is a setting on the class, not a permission the teacher lacks.
            '/tools/lesson-log' => 200,
            '/tools/gradebook' => 200,
            '/tools/quiz-live' => 200,
            '/tools/job-search-tracking' => 200,
            // Not a picker: the cross-class quiz list renders whatever the viewer teaches, empty
            // included, so it answers 200 rather than handing over to a class.
            '/tools/quiz' => 200,
            // The frise of a supervised copy - a teacher of the formation reads it.
            $this->timelinePath => 200,
            // Same reading: the video list shows what the viewer owns, empty included.
            '/tools/videos' => 200,
            // The library is personal and lazily created: an account with no file has an empty
            // library rather than a missing one, so these render rather than hand over.
            '/tools/file-library' => 200,
            '/tools/file-library/trash' => 200,
            '/tools/file-library/search' => 200,
            '/library/quiz' => 200,
            '/library/quiz/search' => 200,
            '/library/quiz/import/assistant' => 200,
            '/help/manage' => 403,
            '/settings/configuration' => 403,
            '/settings/teaching' => 403,
            '/features' => 403,
            '/settings/groups' => 403,
            '/settings/groups/hierarchy' => 403,
            '/directory/users' => 403,
            '/ufa' => 403,
            '/ufa/configuration/contract-import' => 403,
            '/eco/parcours' => 403,
        ]);
    }

    public function testAdminScreens(): void
    {
        $this->assertScreens($this->admin, [
            '/' => 200,
            // The wording of the six levels is the administrator's, and the thresholds are nobody's:
            // they live in code, common to the whole establishment. Staff reach every formation's
            // teaching screens - isProgramTeacher() bypasses for them.
            '/game' => 404,
            '/game/levels' => 200,
            '/settings/game/levels' => 200,
            '/settings/game/figures' => 200,
            $this->statementsPath => 200,
            $this->gesturesPath => 200,
            '/game/engagement/new' => 404,
            '/game/engagements' => 200,
            '/game/leveling' => 404,
            $this->rewardsPath => 200,
            $this->observationPath => 200,
            '/game/alias' => 404,
            '/game/title' => 404,
            '/game/ranking' => 404,
            '/game/team' => 404,
            $this->gameSettingsPath => 200,
            '/settings/configuration' => 200,
            '/settings/teaching' => 200,
            // Groups are admin-only, deliberately stricter than the rest of Settings - see
            // App\Controller\SettingsGroupsController's own note.
            '/settings/groups' => 200,
            '/settings/groups/hierarchy' => 200,
            // Paramètres > Fonctionnalités, and the derogation list one of its counters leads to.
            // Admin-only and carrying no feature guard of their own on purpose: no setting made
            // here may close the screen the settings are made on
            // (design/validated/feature-access.md §8.8).
            '/features' => 200,
            '/features/agenda/overrides' => 200,
            '/directory/users' => 200,
            '/ufa' => 200,
            '/ufa/reminders' => 200,
            '/ufa/configuration/contract-import' => 200,
            '/eco/parcours' => 200,
            '/assignments' => 200,
            '/progression' => 200,
            '/library/sequences' => 200,
            '/library/sequences/assistant' => 200,
            '/shares' => 200,
            '/shares/mine' => 200,
            '/shares/catalog' => 200,
            // Outils > Sondages - one entry serving teachers and staff alike, since the menu it
            // sits in is already gated on exactly those two.
            '/surveys' => 302,
            '/surveys/templates' => 200,
            '/surveys/templates/search' => 200,
            '/surveys/campaigns' => 200,
            '/surveys/templates/new' => 200,
            // Also a respondent: a teacher aimed at by a satisfaction survey has no travail à
            // faire, so this screen and the home card are their only door (§7.9).
            '/my-surveys' => 200,
            // The results screens hang off a campaign id, so an empty database answers 404 rather
            // than 403 - the door itself is pinned by SurveyVoterTest. What matters here is that
            // they are *reachable*: a 403 would mean the role lost access.
            '/surveys/campaigns/999999' => 404,
            '/surveys/campaigns/999999/responses' => 404,
            '/surveys/campaigns/999999/pending' => 404,
            '/surveys/campaigns/999999/export' => 404,
            '/surveys/campaigns/999999/replay' => 404,
            // The series screens, including the individual comparison - reachable only from the
            // wave comparison, never from the menu, but a route all the same (§7.15).
            '/surveys/series/999999' => 404,
            '/surveys/series/999999/individual' => 404,
            '/agenda' => 200,
            '/messages' => 200,
            '/tickets' => 200,
            // « Mes machines virtuelles » - open to every authenticated role and empty
            // until somebody holds an account on one; what may be *done* to a machine is the
            // GuestAccountVoter's answer, one machine at a time.
            '/my/machines' => 200,
            '/profile' => 200,
            '/about' => 200,
            '/help' => 200,
            '/changelog' => 200,
            // Open to every role on purpose: the source is public and the students are its readers.
            '/technical' => 200,
            '/technical/data-model' => 200,
            // Writing the help is an admin's job, and only an admin's.
            '/help/manage' => 200,
            // The whole documentation base, its figures and its vocabulary.
            '/documentation' => 200,
            '/documentation/articles/new' => 200,
            '/documentation/manage/dashboard' => 200,
            '/documentation/manage/reads' => 200,
            '/documentation/manage/never-read' => 200,
            '/documentation/manage/tags' => 200,
            '/wiki' => 302,
            '/wiki/personal' => 200,
            '/wiki/shared' => 200,
            '/wiki/students' => 200,
            '/wiki/new' => 200,
            // Staff pick a class first, so these hand over to the program-scoped screen.
            '/tools/lesson-log' => 302,
            '/tools/gradebook' => 302,
            '/tools/quiz-live' => 302,
            '/tools/job-search-tracking' => 302,
            '/tools/quiz' => 200,
            // Staff read the frise too: StructureAccessChecker::isProgramTeacher() bypasses for them.
            $this->timelinePath => 200,
            '/tools/videos' => 200,
            // An admin holding ROLE_ADMIN also *owns* a library - that is the first row of the
            // access table, not the narrow "somebody else's quota" one.
            '/tools/file-library' => 200,
            '/tools/file-library/trash' => 200,
            '/library/quiz' => 200,
            '/library/quiz/search' => 200,
            '/library/quiz/import/assistant' => 200,
            // An admin is neither enrolled nor teaching, so the two personal timetables stay shut.
            '/my/timetable' => 403,
            '/timetable' => 403,
            '/student-work' => 403,
        ]);
    }

    /**
     * The screens that edit the training referential (TSF). Program-scoped, unlike the table above,
     * so they are asserted here rather than folded into it - and they are the whole point of
     * pinning a role: a teacher must not reach a program's referential settings.
     *
     * Every tab of the UFA formation area is listed, not just the one being worked on: they share
     * one shell and their content partials get edited together, which is exactly how the
     * certification zone once shipped breaking a tab no test knew about.
     */
    public function testReferentialScreens(): void
    {
        $programId = $this->program->getId();

        $screens = [
            sprintf('/programs/%d/settings/skill-groups', $programId),
            sprintf('/ufa/programs/%d', $programId),
            sprintf('/ufa/programs/%d/tutors', $programId),
            // The certification rides the denomination tab rather than one of its own.
            sprintf('/ufa/programs/%d/denomination', $programId),
            sprintf('/ufa/programs/%d/contract-modalities', $programId),
            sprintf('/ufa/programs/%d/exam-modalities', $programId),
            // The two tabs the UFA team was given so it would stop having to walk through
            // Paramétrage: the calendar upload, and the referential under its own name.
            sprintf('/ufa/programs/%d/documents', $programId),
            sprintf('/ufa/programs/%d/skills', $programId),
            '/ufa/configuration/training-center',
        ];

        $this->assertScreens($this->admin, array_fill_keys($screens, 200));
        $this->assertScreens($this->teacher, array_fill_keys($screens, 403));
        $this->assertScreens($this->student, array_fill_keys($screens, 403));
    }

    public function testTutorScreens(): void
    {
        // An external apprenticeship tutor sees almost nothing: their own alternance area, and the
        // few screens open to every account. Anything else must answer 403 - see
        // project_livret_alternant_tutor_access.
        $this->assertScreens($this->tutor, [
            '/' => 302,
            // The game stays between the student, their class and their teachers - a tutor reads
            // none of it (§1, « aucune vue tuteur ni famille »).
            '/game' => 404,
            '/game/levels' => 200,
            '/settings/game/levels' => 403,
            $this->statementsPath => 403,
            $this->gesturesPath => 403,
            '/game/engagement/new' => 404,
            '/game/engagements' => 404,
            '/game/leveling' => 404,
            $this->rewardsPath => 403,
            $this->observationPath => 403,
            '/game/alias' => 404,
            '/game/title' => 404,
            '/game/ranking' => 404,
            '/game/team' => 404,
            $this->gameSettingsPath => 403,
            '/agenda' => 200,
            '/messages' => 200,
            '/tickets' => 200,
            // « Mes machines virtuelles » - open to every authenticated role and empty
            // until somebody holds an account on one; what may be *done* to a machine is the
            // GuestAccountVoter's answer, one machine at a time.
            '/my/machines' => 200,
            '/profile' => 200,
            '/about' => 200,
            // Open to every account, and empty for anyone no article is addressed to: there is
            // nothing to protect in the help, only content written for someone else.
            '/help' => 200,
            '/changelog' => 200,
            // Open to every role on purpose: the source is public and the students are its readers.
            '/technical' => 200,
            '/technical/data-model' => 200,
            '/resources/mobile-app' => 200,
            // Same door as everyone else: a tutor reaches the base and finds it empty for as
            // long as the annuaire gives them no perimeter group (see DocumentationAccess).
            '/documentation' => 200,
            '/documentation/articles/new' => 403,
            '/documentation/manage/dashboard' => 403,
            // The wiki excludes outside accounts entirely - no wiki of their own, never a member,
            // never a reader. Same posture as messaging, and the reason the menu hides it too.
            '/wiki' => 403,
            '/wiki/personal' => 403,
            '/wiki/shared' => 403,
            '/wiki/students' => 403,
            '/wiki/new' => 403,
            '/student-work' => 403,
            '/timetable' => 403,
            '/my/timetable' => 403,
            '/assignments' => 403,
            '/progression' => 403,
            '/library/sequences' => 403,
            '/library/sequences/assistant' => 403,
            // A tutor may be *targeted* by a survey - « Mes sondages » is their door, and their
            // only one (design/validated/surveys.md §11). The author's side is shut.
            '/my-surveys' => 200,
            '/surveys' => 403,
            '/surveys/templates' => 403,
            // The survey library's classement: the same door as the library itself.
            '/surveys/templates/search' => 403,
            '/surveys/campaigns' => 403,
            '/surveys/templates/new' => 403,
            '/shares' => 403,
            '/shares/mine' => 403,
            '/shares/catalog' => 403,
            '/tools/quiz-live' => 403,
            '/tools/job-search-tracking' => 403,
            '/tools/quiz' => 403,
            // An external tutor is not a teacher of the formation: the frise is closed to them too.
            $this->timelinePath => 403,
            '/tools/videos' => 403,
            // ROLE_TUTOR is excluded from the library entirely, as it is from the wiki and from
            // messaging (design/validated/file-library.md, "Who has a library").
            '/tools/file-library' => 403,
            '/tools/file-library/trash' => 403,
            '/tools/file-library/search' => 403,
            // The quiz library's classement: the same door as the library itself.
            '/library/quiz' => 403,
            '/library/quiz/search' => 403,
            '/library/quiz/import/assistant' => 403,
            '/help/manage' => 403,
            '/settings/configuration' => 403,
            '/settings/teaching' => 403,
            '/features' => 403,
            '/directory/users' => 403,
            '/ufa' => 403,
            '/ufa/configuration/contract-import' => 403,
            '/eco/parcours' => 403,
        ]);
    }

    /**
     * One route per feature that §4 of design/validated/feature-access.md switches **off** by
     * default, asserted for each of the four roles.
     *
     * This table is the cheapest place in the repository to see that a role gained or lost an
     * access by accident, and it is the counterpart to FeatureCoverageTest: that one proves every
     * route belongs to a feature, this one proves the guard actually answers.
     *
     * The expected code is **404**, not 403, everywhere - an extinguished screen does not exist, it
     * is not forbidden (§7.1). Where a role would already have been refused on its own merits, the
     * 403 comes first (access_control runs before the controller is even resolved) and the line
     * says so; the ones that read 404 are the ones this system is answering.
     *
     * An admin is asserted separately, below: they must keep **everything**, whatever the matrix
     * says, and that is what makes switching a feature off a safe gesture.
     */
    public function testExtinguishedFeaturesAnswerNotFound(): void
    {
        $this->switchOffEveryRole(
            Feature::Agenda,
            Feature::Announcements,
            Feature::Documentation,
            Feature::Help,
            Feature::Timetable,
            Feature::FileLibrary,
            Feature::ContentSharing,
            Feature::CourseSpace,
            Feature::SharedDocuments,
            Feature::LessonLog,
            Feature::GradebookEntry,
            Feature::GradebookStudent,
            Feature::Directory,
            Feature::Messaging,
            Feature::SignupLists,
            Feature::Eco,
        );

        // A 403 here would mean the role was refused before the feature was ever read, which is a
        // different answer to a different question - and the line would then prove nothing.
        $this->assertScreens($this->student, [
            '/agenda' => 404,
            '/documentation' => 404,
            '/help' => 404,
            '/my/courses' => 404,
            '/my/shared-documents' => 404,
            '/messages' => 404,
            '/signup-lists' => 404,
        ]);

        $this->assertScreens($this->teacher, [
            '/agenda' => 404,
            '/documentation' => 404,
            '/help' => 404,
            '/timetable' => 404,
            '/tools/file-library' => 404,
            '/tools/lesson-log' => 404,
            '/tools/gradebook' => 404,
            '/shares' => 404,
            '/messages' => 404,
        ]);

        // The tutor keeps their own doors - none of the features above is theirs - and loses the
        // ones that were switched off for everybody.
        $this->assertScreens($this->tutor, [
            '/agenda' => 404,
            '/documentation' => 404,
            '/messages' => 404,
            '/my-surveys' => 200,
        ]);
    }

    /**
     * The first thing to check, and the reason the whole design is safe to deploy: with every
     * feature switched off on every role, an admin still reads everything.
     */
    public function testAnAdminKeepsEverythingWhateverTheMatrixSays(): void
    {
        $this->switchOffEveryRole(...Feature::cases());

        $this->assertScreens($this->admin, [
            '/agenda' => 200,
            '/documentation' => 200,
            '/help' => 200,
            '/messages' => 200,
            '/directory/users' => 200,
            '/tools/file-library' => 200,
            '/features' => 200,
            '/eco/parcours' => 200,
            '/ufa' => 200,
            '/shares' => 200,
        ]);
    }

    /**
     * A derogation reopens a screen **for that person alone** - the other half of « une
     * fonctionnalité s'éteint pour un rôle, et se rallume pour une personne ».
     */
    public function testADerogationReopensAScreenForOnePersonOnly(): void
    {
        $this->switchOffEveryRole(Feature::Agenda);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserFeatureAccess($this->student, Feature::Agenda, FeatureAccessState::Enabled));
        $entityManager->flush();

        $this->assertScreens($this->student, ['/agenda' => 200]);
        // Same role, same matrix, no derogation: nothing changed for them.
        $this->assertScreens($this->teacher, ['/agenda' => 404]);
    }

    /**
     * Switches these features off for every managed role.
     *
     * Updates the row when there already is one rather than inserting blindly: the matrix is seeded
     * by the migration the empty `_test` schema replays, so every pair already exists here - which
     * is also what production looks like.
     */
    private function switchOffEveryRole(Feature ...$features): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = $entityManager->getRepository(FeatureRoleSetting::class);

        foreach ($features as $feature) {
            foreach (Feature::managedRoles() as $role) {
                $existing = $repository->findOneBy(['feature' => $feature, 'role' => $role]);

                if (null === $existing) {
                    $entityManager->persist(new FeatureRoleSetting($feature, $role, false));

                    continue;
                }

                $existing->setEnabled(false);
            }
        }

        $entityManager->flush();
    }

    /**
     * The Proxmox console. Asserted on its own rather than folded into the tables above, and it is
     * the one place in this file that is genuinely load-bearing rather than merely useful.
     *
     * /infrastructure appears in no menu at all - that is a frozen decision of its design, so no
     * screen anywhere links to it and nobody would notice by clicking that a role had gained
     * access. This table is the only thing that would. It also pins **staff**, who are not in the
     * fixtures above: they reach /settings and /directory, and the natural mistake when widening
     * this area later is to let them in here too. They must not - starting and stopping a
     * hypervisor's machines is not administrative work.
     */
    /**
     * Co-animation: the second formateur named on a progression reaches the same screens as its
     * owner, the PDF export included (design/validated/co-animation.md).
     *
     * The paths are built rather than listed in the tables above because they carry the
     * progression's id, but they go through the same assertScreens() helper - a co-animator whose
     * right stops one screen short of the export is precisely the half-shipped state this design
     * warns about, and only an HTTP request says whether it happened.
     *
     * The export's expected code is read off the OWNER rather than hard-coded, so the assertion
     * stays about co-animation: whether Gotenberg answers in this environment is not what is being
     * pinned here - that the two teachers get the same answer is.
     */
    public function testCoAnimatorReachesTheSharedProgression(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $coTeacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'smoke.coteacher');
        $this->program->addTeacher($coTeacher);

        // A Topic needs its group: topic_group_id is NOT NULL, which is the timetable import's own
        // shape (a matière belongs to a "groupe de matières" of the class).
        $topicGroup = new TopicGroup('Groupe de matières de test', $this->program);
        $topicGroup->setCreatedBy($this->admin);
        $entityManager->persist($topicGroup);

        $topic = new Topic('Matière co-animée', $this->program, $topicGroup);
        $topic->setTeacher($this->teacher);
        $topic->setCreatedBy($this->admin);
        $entityManager->persist($topic);

        $progression = new Progression($topic, $this->teacher);
        $progression->addCoTeacher($coTeacher);
        $entityManager->persist($progression);
        $entityManager->flush();

        $show = '/progression/'.$progression->getId();
        $export = $show.'/export.pdf';

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $export);
        $ownerExportCode = $this->client->getResponse()->getStatusCode();

        $this->assertScreens($this->teacher, [
            '/progression/management' => 200,
            $show => 200,
            $export => $ownerExportCode,
        ]);

        $this->assertScreens($coTeacher, [
            '/progression/management' => 200,
            $show => 200,
            $export => $ownerExportCode,
        ]);

        // The door is a link on this progression, never a property of the class: a colleague who
        // teaches the same class and was named on nothing still gets nothing.
        $stranger = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'smoke.otherteacher');
        $this->program->addTeacher($stranger);
        $entityManager->flush();

        $this->assertScreens($stranger, [
            '/progression/management' => 200,
            $show => 403,
            $export => 403,
        ]);
    }

    public function testInfrastructureIsAdminOnly(): void
    {
        $screens = [
            '/infrastructure',
            '/infrastructure/hosts',
            '/infrastructure/hosts/new',
            // Machines et Images ne sont plus les écrans d'un hôte : ils lisent tous les
            // hôtes déclarés, donc ils ont une URL fixe et ont leur place ici.
            '/infrastructure/guests',
            '/infrastructure/images',
            '/infrastructure/operations',
            '/infrastructure/ip-ranges',
            '/infrastructure/ip-ranges/new',
            '/infrastructure/batches',
            '/infrastructure/batches/new',
            // Le journal des consoles : la trace d'une porte qui ouvre un shell root-capable, donc
            // aussi fermée que le reste de l'espace - et pas même ouverte au personnel.
            '/infrastructure/console-sessions',
        ];

        $this->assertScreens($this->admin, array_fill_keys($screens, 200));

        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'smoke.staff');
        $staffLead = $this->createUser(['ROLE_USER', 'ROLE_STAFF-LEAD'], 'smoke.stafflead');

        foreach ([$this->student, $this->teacher, $this->tutor, $staff, $staffLead] as $user) {
            $this->assertScreens($user, array_fill_keys($screens, 403));
        }
    }

    /**
     * The class import. Asserted on its own for the same reason as the Proxmox console above: the
     * rest of Annuaire is open to staff, and this one screen is not.
     *
     * That asymmetry is the whole point and the easiest thing to undo by accident - widening the
     * directory area later, or folding this controller's #[IsGranted] into the one the other
     * directory screens share, would hand thirty accounts on a click to a role that is meant to
     * go on creating them one at a time. Nothing in the interface would show it: the button is
     * rendered under the same condition, so it would simply appear.
     *
     * The batch route is pinned with an id nobody holds: an admin must get a 404 (the route exists
     * and its \d+ requirement keeps `check`, `confirm` and `template.csv` out of it), and everybody
     * else a 403 - refused before the controller ever looks for the row.
     */
    public function testClassImportIsAdminOnly(): void
    {
        $this->assertScreens($this->admin, [
            '/directory/users/class-import' => 200,
            '/directory/users/class-import/template.csv' => 200,
            // Nothing parked in the session yet: back to step ① rather than an empty analysis.
            '/directory/users/class-import/check' => 302,
            '/directory/users/class-import/999999' => 404,
        ]);

        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'smoke.import.staff');
        $staffLead = $this->createUser(['ROLE_USER', 'ROLE_STAFF-LEAD'], 'smoke.import.stafflead');

        $refused = [
            '/directory/users/class-import' => 403,
            '/directory/users/class-import/template.csv' => 403,
            '/directory/users/class-import/check' => 403,
            '/directory/users/class-import/999999' => 403,
        ];

        foreach ([$this->student, $this->teacher, $this->tutor, $staff, $staffLead] as $user) {
            $this->assertScreens($user, $refused);
        }
    }

    /**
     * The console, one line per role - and the student's line is the one this table exists for.
     *
     * `/console/{id}` is reached *through an account*, so the id here is a real GuestAccount owned
     * by the person in question: a 403 on a missing row would prove nothing. The four verdicts are
     * the whole access rule of the feature:
     *
     *   - a **student** who owns the account is refused, because they do not teach the class. They
     *     already have a shell on that machine - their own - and a console opens on `moncampus`,
     *     which has sudo.
     *   - an **administrator** is refused *here*, and that is deliberate: their door is
     *     /infrastructure, guarded by access_control. The voter has no ROLE_STAFF bypass and must
     *     never gain one.
     *   - a **tutor** has no account at all, so there is nothing to be refused about - 403 too.
     *   - a **teacher** of the class who owns the account gets in.
     */
    public function testOnlyATeacherOfTheClassOpensAConsoleOnItsMachines(): void
    {
        $this->assertScreens($this->student, ['/console/'.$this->studentAccountId => 403]);
        $this->assertScreens($this->tutor, ['/console/'.$this->studentAccountId => 403]);
        $this->assertScreens($this->admin, ['/console/'.$this->studentAccountId => 403]);
        $this->assertScreens($this->admin, ['/console/'.$this->teacherAccountId => 403]);
        // The teacher's own account on the same machine: the door opens. The screen it lands on
        // says the machine has no known address, because this fixture allocates none - which is a
        // console refusing for the right reason, not a door refusing.
        $this->assertScreens($this->teacher, ['/console/'.$this->teacherAccountId => 200]);

        // « Mes extraits de commande » suit la même porte : c'est la moitié personnelle de la
        // palette d'une console, et un étudiant n'en ouvre aucune.
        $this->assertScreens($this->teacher, ['/console/snippets' => 200]);
        $this->assertScreens($this->admin, ['/console/snippets' => 200]);
        $this->assertScreens($this->student, ['/console/snippets' => 403]);
        $this->assertScreens($this->tutor, ['/console/snippets' => 403]);
    }

    /**
     * Le mur de consoles : la classe entière, en lecture seule.
     *
     * Il suit les deux portes de la console sans en inventer une troisième — enseignant de la
     * formation du lot, ou administrateur — et rien d'autre. Un étudiant du lot n'y entre pas : ce
     * qu'il verrait, c'est l'écran de ses camarades.
     */
    public function testTheConsoleWallFollowsTheSameTwoDoors(): void
    {
        $wall = '/console/batch/'.$this->batchId;

        $this->assertScreens($this->teacher, [$wall => 200]);
        $this->assertScreens($this->admin, [$wall => 200]);
        $this->assertScreens($this->student, [$wall => 403]);
        $this->assertScreens($this->tutor, [$wall => 403]);
    }

    /**
     * Désactiver et réactiver un compte : `ROLE_ADMIN` et personne d'autre.
     *
     * Une ligne par rôle, et les deux lignes qui comptent sont celles du personnel : tout le reste
     * de l'Annuaire leur est ouvert (`access_control` sur `^/directory`), et le contrôleur porte le
     * même `#[IsGranted]` à trois rôles que ses voisins. Ce qui tient la porte, ce sont les deux
     * `isGranted('ROLE_ADMIN')` écrits à l'intérieur des deux actions — exactement le genre de
     * garde qu'une harmonisation de contrôleur fait disparaître sans que rien ne se voie.
     *
     * En POST, parce que les deux routes n'existent qu'en POST : un GET répondrait 405 à
     * l'administrateur et masquerait la question. Le sondage du bandeau et le « Réessayer » suivent
     * la même porte — le premier dit d'un compte s'il est fermé et par qui, le second dépose une
     * demande dans la file de l'annuaire.
     */
    public function testDeactivatingAnAccountIsAdminOnly(): void
    {
        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'smoke.deact.staff');
        $staffLead = $this->createUser(['ROLE_USER', 'ROLE_STAFF-LEAD'], 'smoke.deact.stafflead');
        $target = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'smoke.deact.target');
        $targetId = $target->getId();

        foreach ([$this->student, $this->teacher, $this->tutor, $staff, $staffLead] as $user) {
            foreach (['deactivate', 'reactivate', 'change-login'] as $action) {
                // No CSRF token on purpose: the role check runs first, so a 403 here is the role's.
                $this->client->loginUser($user);
                $this->client->request('POST', '/directory/users/'.$targetId.'/'.$action);

                self::assertSame(403, $this->client->getResponse()->getStatusCode(), \sprintf(
                    'POST %s as %s must be refused.',
                    $action,
                    implode('/', $user->getRoles()),
                ));
            }
        }

        self::assertNull(
            static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->find($targetId)?->getInactiveDate(),
            'Not one of those requests may have gone through.',
        );

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/directory/users');
        $this->client->request('POST', '/directory/users/'.$targetId.'/deactivate', [
            '_token' => $this->csrfToken('directory_user_deactivate'),
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        // Le sondage du bandeau et la disponibilité d'un login : 200 pour l'administrateur, 403
        // pour tous les autres. La seconde répond « ce login est-il pris ? », ce qui est une
        // question sur l'annuaire entier, pas sur la fiche ouverte.
        $polling = [
            '/directory/users/'.$targetId.'/account-status' => 200,
            '/directory/users/'.$targetId.'/login-availability?login=quelquun' => 200,
            // Le journal, et son point d'alimentation. Il est dans l'Annuaire, ouvert au personnel,
            // et lui ne l'est pas : c'est toute la raison d'un contrôleur à part.
            '/directory/accounts' => 200,
            '/directory/accounts/data' => 200,
            // Les filtres du bandeau soumettent « Toutes » en chaîne vide, ce qui répondait 400
            // partout où un getInt() lisait la valeur. QueryValue est ce qui l'en empêche ici.
            '/directory/accounts/data?action=&state=' => 200,
        ];
        $this->assertScreens($this->admin, $polling);
        foreach ([$this->student, $this->teacher, $this->tutor, $staff, $staffLead] as $user) {
            $this->assertScreens($user, array_fill_keys(array_keys($polling), 403));
        }

        // Re-read rather than trusted: a request in between may well have cleared the manager, so
        // the object this test still holds is not necessarily the row the controller wrote.
        $reloaded = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->find($targetId);
        self::assertNotNull($reloaded?->getInactiveDate(), 'The administrator, and only the administrator, closes the account.');
    }
}
