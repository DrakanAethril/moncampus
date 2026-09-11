<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Assignment;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\QuizMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Convertir en note » on a quiz's results screen: where it leads, and when it is not there.
 *
 * The conversion itself belongs to the travail, so this screen only leads to it - which leaves it
 * with one thing to get right and two states to tell apart: the quiz nobody has made a travail of
 * yet, and the one a travail already carries. Getting that wrong is not a crash, it is a teacher
 * creating a second travail for a quiz that already had one.
 */
class QuizResultsGradeConversionLinkTest extends FunctionalTestCase
{
    public function testAQuizNoTravailCarriesLeadsToTheWizardOpenedOnIt(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'quiz.conversion.teacher');
        $program = $this->createProgram([], [$teacher]);
        $this->topicHeldBy($program, $teacher);
        $instance = $this->quizInstance($program, $teacher);

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/programs/'.$program->getId().'/quiz/'.$instance->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('/assignments/new?quiz='.$instance->getId(), (string) $this->client->getResponse()->getContent());
    }

    public function testAQuizAlreadyGivenAsATravailLeadsToThatTravail(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'quiz.conversion.teacher');
        $program = $this->createProgram([], [$teacher]);
        $this->topicHeldBy($program, $teacher);
        $instance = $this->quizInstance($program, $teacher);
        $assignment = $this->travailCarrying($program, $instance, $teacher);

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/programs/'.$program->getId().'/quiz/'.$instance->getId());

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/assignments/'.$assignment->getId().'"', $content);
        self::assertStringNotContainsString('/assignments/new?quiz=', $content);
    }

    /**
     * A travail announced « Non noté » is left alone, exactly as Assignment::feedsGradebookFromQuiz()
     * leaves it alone on the travail's own screen - and the quiz screen does not offer a second
     * travail as a way around it either.
     */
    public function testATravailMarkedNotGradedOffersNothing(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'quiz.conversion.teacher');
        $program = $this->createProgram([], [$teacher]);
        $this->topicHeldBy($program, $teacher);
        $instance = $this->quizInstance($program, $teacher);
        $this->travailCarrying($program, $instance, $teacher)->setGraded(false);
        $this->em()->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/programs/'.$program->getId().'/quiz/'.$instance->getId());

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Convertir en note', $content);
        self::assertStringNotContainsString('/assignments/new?quiz=', $content);
    }

    /**
     * The carnet is one teacher's. Whoever holds no matière of the class - staff included, who reach
     * this screen all the same - is offered nothing, which is the very condition the button on the
     * travail answers to.
     */
    public function testSomebodyHoldingNoMatiereOfTheClassIsOfferedNothing(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'quiz.conversion.teacher');
        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF', 'ROLE_CAMPUS'], 'quiz.conversion.staff');
        $program = $this->createProgram([], [$teacher]);
        $this->topicHeldBy($program, $teacher);
        $instance = $this->quizInstance($program, $teacher);

        $this->client->loginUser($staff);
        $this->client->request('GET', '/programs/'.$program->getId().'/quiz/'.$instance->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Convertir en note', (string) $this->client->getResponse()->getContent());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function topicHeldBy(Program $program, User $teacher): Topic
    {
        $group = new TopicGroup('Groupe de test', $program);
        $group->setCreatedBy($teacher);
        $topic = new Topic('Matière de test', $program, $group);
        $topic->setCreatedBy($teacher);
        $topic->setTeacher($teacher);

        $this->em()->persist($group);
        $this->em()->persist($topic);
        $this->em()->flush();

        return $topic;
    }

    private function quizInstance(Program $program, User $teacher): QuizInstance
    {
        $instance = new QuizInstance($program, $teacher);
        $instance->setName('Réseaux — contrôle du chapitre 4');
        $instance->setMode(QuizMode::Evaluation);

        $this->em()->persist($instance);
        $this->em()->flush();

        return $instance;
    }

    private function travailCarrying(Program $program, QuizInstance $instance, User $author): Assignment
    {
        $assignment = new Assignment($program);
        $assignment->setTitle('Réseaux — contrôle du chapitre 4');
        $assignment->setNature(AssignmentNature::Quiz);
        $assignment->setAudienceType(AssignmentAudienceType::Program);
        $assignment->setQuizInstance($instance);
        $assignment->setDueDate(new \DateTimeImmutable('tomorrow 08:00'));
        $assignment->setVisibleAt(new \DateTimeImmutable('-1 hour'));
        $assignment->setCreatedBy($author);

        $this->em()->persist($assignment);
        $this->em()->flush();

        return $assignment;
    }
}
