<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OnlineCourse;
use App\Entity\User;
use App\Enum\OnlineCourseStatus;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The online-course tools of the Claude connector (design/validated/cours-en-ligne.md, §12): a
 * course is created as a draft, given its materials, and put online by a second call - through the
 * same writers, the same gates and the same refusals as the screens.
 */
class ClaudeConnectorOnlineCourseToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    public function testTheToolsAreListed(): void
    {
        $names = $this->toolNames($this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude')));

        foreach (['course_list', 'course_get', 'course_tag_list', 'course_create', 'course_update', 'course_material_add', 'course_material_replace', 'course_publish', 'course_unpublish'] as $tool) {
            self::assertContains($tool, $names);
        }
    }

    public function testACourseIsCreatedAsADraftGivenAPageAndPublished(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $token = $this->accessTokenFor($teacher);

        $created = $this->callTool($token, 'course_create', [
            'title' => 'Les jointures SQL',
            'summary' => 'Relier deux tables.',
            'description' => "## Objectifs\n\n- écrire un **INNER JOIN**\n\n<script>alert(1)</script>",
            'tags' => ['SQL', 'sql', 'SLAM'],
            'estimatedMinutes' => 45,
        ]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertSame('draft', $created['data']['status']);
        self::assertSame('les-jointures-sql', $created['data']['slug']);
        self::assertSame(['SLAM', 'SQL'], $created['data']['tags']);
        $courseId = $created['data']['courseId'];
        self::assertIsInt($courseId);

        $course = $this->em()->find(OnlineCourse::class, $courseId);
        self::assertInstanceOf(OnlineCourse::class, $course);
        self::assertStringContainsString('<strong>INNER JOIN</strong>', (string) $course->getDescription());
        self::assertStringNotContainsString('<script', (string) $course->getDescription());

        // Nothing to publish yet: everything missing is named at once.
        $refused = $this->callTool($token, 'course_publish', ['courseId' => $courseId]);
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('au moins un support', $refused['text']);
        self::assertStringContainsString('Ma page', $refused['text']);

        $page = $this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'interactive', 'html' => '<!doctype html><title>Cours</title><h1>Jointures</h1>']);
        self::assertFalse($page['isError'], $page['text']);

        // The request has cleared the unit of work: the teacher is fetched again rather than re-attached.
        static::getContainer()->get(OnlineCoursePageHandles::class)->create($this->em()->getReference(User::class, $teacher->getId()), 'claude-teacher', 'Cours de test');
        $this->em()->flush();

        $published = $this->callTool($token, 'course_publish', ['courseId' => $courseId]);
        self::assertFalse($published['isError'], $published['text']);
        self::assertIsString($published['data']['publicUrl']);
        self::assertStringEndsWith('/courses/claude-teacher/les-jointures-sql', $published['data']['publicUrl']);
        $this->em()->clear();
        self::assertSame(OnlineCourseStatus::PublicCourse, $this->em()->find(OnlineCourse::class, $courseId)?->getStatus());

        // Frozen from now on.
        $renamed = $this->callTool($token, 'course_update', ['courseId' => $courseId, 'slug' => 'autre']);
        self::assertTrue($renamed['isError']);

        $offline = $this->callTool($token, 'course_unpublish', ['courseId' => $courseId]);
        self::assertFalse($offline['isError'], $offline['text']);
        self::assertSame('draft', $offline['data']['status']);
    }

    public function testAnUpdateWritesOnlyTheFieldsItNames(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $courseId = $this->callTool($token, 'course_create', ['title' => 'OSI', 'summary' => 'Les sept couches.', 'tags' => ['Réseau']])['data']['courseId'];

        $updated = $this->callTool($token, 'course_update', ['courseId' => $courseId, 'title' => 'Le modèle OSI']);
        self::assertFalse($updated['isError'], $updated['text']);
        self::assertSame('Le modèle OSI', $updated['data']['title']);
        self::assertSame('Les sept couches.', $updated['data']['summary']);
        self::assertSame(['Réseau'], $updated['data']['tags']);

        $tooLong = $this->callTool($token, 'course_update', ['courseId' => $courseId, 'summary' => str_repeat('a', 301)]);
        self::assertTrue($tooLong['isError']);
        self::assertTrue($this->callTool($token, 'course_update', ['courseId' => $courseId])['isError']);
    }

    public function testAMaterialTakesExactlyOneSourceFitForItsNature(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $courseId = $this->callTool($token, 'course_create', ['title' => 'OSI'])['data']['courseId'];

        self::assertTrue($this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'pdf', 'html' => '<h1>x</h1>'])['isError']);
        self::assertTrue($this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'interactive', 'markdown' => '# x'])['isError']);
        self::assertTrue($this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'interactive'])['isError']);
        self::assertTrue($this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'interactive', 'html' => '<h1>x</h1>', 'markdown' => '# x'])['isError']);
        self::assertTrue($this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'podcast', 'html' => '<h1>x</h1>'])['isError']);

        // A library file of the wrong nature is refused in the screen's own words.
        $csv = $this->callTool($token, 'file_upload', ['name' => 'plan.csv', 'base64' => base64_encode("a;b\n1;2\n")]);
        $wrong = $this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'video', 'fileId' => $csv['data']['fileId']]);
        self::assertTrue($wrong['isError']);
        self::assertStringContainsString('ne convient pas', $wrong['text']);

        $added = $this->callTool($token, 'course_material_add', ['courseId' => $courseId, 'kind' => 'interactive', 'html' => '<h1>Version 1</h1>']);
        self::assertIsArray($added['data']['material']);
        $materialId = $added['data']['material']['materialId'] ?? null;
        self::assertIsInt($materialId);

        $replaced = $this->callTool($token, 'course_material_replace', ['materialId' => $materialId, 'html' => '<h1>Version 2</h1>']);
        self::assertFalse($replaced['isError'], $replaced['text']);
        self::assertIsArray($replaced['data']['material']);
        self::assertSame(2, $replaced['data']['material']['revision'] ?? null);
        self::assertTrue($replaced['data']['material']['previousRevisionKept'] ?? null);
    }

    public function testASummarySheetWrittenInMarkdownBecomesAPdfOfTheCourse(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $courseId = $this->callTool($token, 'course_create', ['title' => 'Les jointures SQL'])['data']['courseId'];

        $sheet = $this->callTool($token, 'course_material_add', [
            'courseId' => $courseId,
            'kind' => 'summary',
            'markdown' => "## L'essentiel\n\n- **INNER** : les lignes qui se correspondent\n- **LEFT** : toute la table de gauche",
        ]);
        self::assertFalse($sheet['isError'], $sheet['text']);
        self::assertIsArray($sheet['data']['material']);
        self::assertSame('summary', $sheet['data']['material']['kind'] ?? null);
        self::assertSame('Les jointures SQL.pdf', $sheet['data']['material']['fileName'] ?? null);

        $read = $this->callTool($token, 'course_get', ['courseId' => $courseId]);
        self::assertIsArray($read['data']['materials']);
        self::assertCount(1, $read['data']['materials']);
        self::assertIsArray($read['data']['missingToPublish']);
        self::assertNotContains('au moins un support', $read['data']['missingToPublish']);
        self::assertContains('un résumé (summary)', $read['data']['missingToPublish']);
    }

    public function testSomebodyElsesCourseIsNotFoundAndAStudentWritesNoCourse(): void
    {
        $owner = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.owner'));
        $courseId = $this->callTool($owner, 'course_create', ['title' => 'Privé'])['data']['courseId'];

        $other = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        foreach (['course_get', 'course_publish', 'course_update'] as $tool) {
            $answer = $this->callTool($other, $tool, ['courseId' => $courseId, 'title' => 'Volé']);
            self::assertTrue($answer['isError'], $tool);
            self::assertStringContainsString('introuvable', $answer['text'], $tool);
        }
        self::assertSame([], $this->callTool($other, 'course_list')['data']['courses']);

        $student = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'eleve.claude'));
        $refused = $this->callTool($student, 'course_create', ['title' => 'Mon cours']);
        self::assertTrue($refused['isError']);
    }

    public function testTheGuideOfAnInteractiveCourseIsServed(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        self::assertStringContainsString('page HTML entière et autonome', $this->callTool($token, 'format_guide', ['format' => 'cours_interactif'])['text']);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
