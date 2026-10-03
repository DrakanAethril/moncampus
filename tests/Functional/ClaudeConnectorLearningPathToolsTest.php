<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LearningPath;
use App\Entity\LearningPathEnrollment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The learning-path tools of the Claude connector (design/validated/cours-en-ligne.md, §12): a path
 * is created as a draft, its steps are set as a whole list refused whole at its first bad step, and
 * a list that would drop a step somebody already worked on is refused.
 */
class ClaudeConnectorLearningPathToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    public function testAPathIsComposedOfOwnCoursesWithOptionalQuizzes(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $token = $this->accessTokenFor($teacher);

        $first = $this->courseWithAPage($token, 'Le modèle relationnel', 'path_only');
        $second = $this->courseWithAPage($token, 'SELECT et filtres', 'public');

        $created = $this->callTool($token, 'path_create', ['title' => 'Bases de données', 'summary' => 'De la table à la jointure.']);
        self::assertFalse($created['isError'], $created['text']);
        self::assertSame('draft', $created['data']['status']);
        $pathId = $created['data']['pathId'];

        // Two courses with nothing between them: a quiz is never required.
        $set = $this->callTool($token, 'path_set_steps', ['pathId' => $pathId, 'steps' => [['courseId' => $first], ['courseId' => $second]]]);
        self::assertFalse($set['isError'], $set['text']);
        self::assertIsArray($set['data']['steps']);
        self::assertSame(['course', 'course'], array_column($set['data']['steps'], 'type'));

        // Refused whole at its first bad step, naming it.
        $twice = $this->callTool($token, 'path_set_steps', ['pathId' => $pathId, 'steps' => [['courseId' => $first], ['courseId' => $first]]]);
        self::assertTrue($twice['isError']);
        self::assertStringContainsString('étape 2', $twice['text']);

        $published = $this->callTool($token, 'path_publish', ['pathId' => $pathId]);
        self::assertFalse($published['isError'], $published['text']);
        self::assertSame('published', $published['data']['status']);

        // Somebody starts it: dropping a step would erase their trace, so the list is refused.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $path = $em->find(LearningPath::class, $pathId);
        self::assertInstanceOf(LearningPath::class, $path);
        $em->persist(new LearningPathEnrollment($path, $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'eleve.suivi')));
        $em->flush();

        $dropping = $this->callTool($token, 'path_set_steps', ['pathId' => $pathId, 'steps' => [['courseId' => $second]]]);
        self::assertTrue($dropping['isError']);
        $reordering = $this->callTool($token, 'path_set_steps', ['pathId' => $pathId, 'steps' => [['courseId' => $second], ['courseId' => $first]]]);
        self::assertFalse($reordering['isError'], $reordering['text']);
        self::assertSame(1, $reordering['data']['followerCount']);

        // A colleague reads nothing of it.
        $other = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.other'));
        self::assertTrue($this->callTool($other, 'path_get', ['pathId' => $pathId])['isError']);
        self::assertSame([], $this->callTool($other, 'path_list')['data']['paths']);
    }

    private function courseWithAPage(string $token, string $title, string $visibility): int
    {
        $created = $this->callTool($token, 'course_create', ['title' => $title, 'summary' => 'Un résumé.']);
        $courseId = $created['data']['courseId'];
        self::assertIsInt($courseId);
        $this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'interactive', 'html' => '<h1>'.$title.'</h1>']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $teacher = $em->getRepository(User::class)->findOneBy(['username' => 'prof.claude']);
        self::assertInstanceOf(User::class, $teacher);
        if (null === static::getContainer()->get(\App\Repository\OnlineCoursePageRepository::class)->findOneByOwner($teacher)) {
            static::getContainer()->get(\App\Service\OnlineCourse\OnlineCoursePageHandles::class)->create($teacher, 'prof-claude', 'Cours');
            $em->flush();
        }

        $published = $this->callTool($token, 'course_publish', ['courseId' => $courseId, 'visibility' => $visibility]);
        self::assertFalse($published['isError'], $published['text']);
        self::assertSame($visibility, $published['data']['status']);

        return $courseId;
    }
}
