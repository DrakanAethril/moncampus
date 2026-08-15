<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\DocumentationArticle;
use App\Entity\User;
use App\Enum\DocumentationAudience;
use App\Enum\DocumentationStatus;
use App\Security\Voter\DocumentationArticleVoter;
use App\Service\DocumentationAccess;
use App\Service\DocumentationPerimeter;

/**
 * Reading and managing one article of the documentation base.
 *
 * The two attributes answer different questions and must not drift into one: a teacher of another
 * filière may well read an article they can never edit, and the owner of a draft edits one nobody
 * else can read yet.
 */
class DocumentationArticleVoterTest extends VoterTestCase
{
    private const int SIO = 9;
    private const int SIO_2 = 11;
    private const int MCO = 14;

    public function testAReaderOfTheRightSectionAndAudienceViews(): void
    {
        $article = $this->article($this->author(), [self::SIO], [DocumentationAudience::Student]);
        $student = $this->user(['ROLE_STUDENT', 'ROLE_CAMPUS', 'ROLE_SIO', 'ROLE_SIO-2'], 'student');

        $this->assertGranted($this->voter([self::SIO_2, self::SIO]), $student, $article, DocumentationArticleVoter::VIEW);
    }

    public function testAReaderOfAnotherSectionDoesNotView(): void
    {
        $article = $this->article($this->author(), [self::MCO], [DocumentationAudience::Student]);
        $student = $this->user(['ROLE_STUDENT', 'ROLE_SIO-2'], 'student');

        $this->assertDenied($this->voter([self::SIO_2, self::SIO]), $student, $article, DocumentationArticleVoter::VIEW);
    }

    public function testAReaderOfTheRightSectionButTheWrongAudienceDoesNotView(): void
    {
        $article = $this->article($this->author(), [self::SIO], [DocumentationAudience::Teacher]);
        $student = $this->user(['ROLE_STUDENT', 'ROLE_SIO'], 'student');

        $this->assertDenied($this->voter([self::SIO]), $student, $article, DocumentationArticleVoter::VIEW);
    }

    public function testADraftIsReadableByItsOwnerAndByStaffOnly(): void
    {
        $author = $this->author();
        $article = $this->article($author, [self::SIO], [DocumentationAudience::Student, DocumentationAudience::Teacher], DocumentationStatus::Draft);
        $voter = $this->voter([self::SIO]);

        $this->assertDenied($voter, $this->user(['ROLE_STUDENT', 'ROLE_SIO'], 'student'), $article, DocumentationArticleVoter::VIEW);
        $this->assertGranted($voter, $this->user(['ROLE_STAFF'], 'staff'), $article, DocumentationArticleVoter::VIEW);
        // The owner manages their draft even though nobody may read it yet - that is what a draft is.
        $this->assertGranted($voter, $author, $article, DocumentationArticleVoter::MANAGE);
    }

    public function testOnlyTheOwnerAndTheManagersManage(): void
    {
        $author = $this->author();
        $article = $this->article($author, [self::SIO], [DocumentationAudience::Teacher]);
        $voter = $this->voter([self::SIO]);

        $this->assertGranted($voter, $author, $article, DocumentationArticleVoter::MANAGE);
        $this->assertGranted($voter, $this->user(['ROLE_STAFF-LEAD'], 'lead'), $article, DocumentationArticleVoter::MANAGE);
        $this->assertGranted($voter, $this->user(['ROLE_ADMIN'], 'admin'), $article, DocumentationArticleVoter::MANAGE);

        // Another teacher of the very same filière reads it, and only reads it.
        $colleague = $this->user(['ROLE_TEACHER', 'ROLE_SIO'], 'colleague');
        $this->assertGranted($voter, $colleague, $article, DocumentationArticleVoter::VIEW);
        $this->assertDenied($voter, $colleague, $article, DocumentationArticleVoter::MANAGE);
    }

    public function testATutorReadsNothingWhileTheAnnuaireGivesThemNoPerimeter(): void
    {
        $article = $this->article($this->author(), [self::SIO], [DocumentationAudience::Tutor]);

        $this->assertDenied($this->voter([]), $this->user(['ROLE_TUTOR'], 'tutor'), $article, DocumentationArticleVoter::VIEW);
    }

    public function testItStaysOutOfOtherDecisions(): void
    {
        $voter = $this->voter([self::SIO]);

        $this->assertAbstains($voter, $this->user(), new \stdClass(), DocumentationArticleVoter::VIEW);
        $this->assertAbstains($voter, $this->user(), $this->article($this->author(), [self::SIO], []), 'SOMETHING_ELSE');
    }

    /** @param list<int> $readerGroupIds */
    private function voter(array $readerGroupIds): DocumentationArticleVoter
    {
        $perimeter = $this->createStub(DocumentationPerimeter::class);
        $perimeter->method('readerGroupIds')->willReturn($readerGroupIds);

        return new DocumentationArticleVoter(new DocumentationAccess(), $perimeter);
    }

    private function author(): User
    {
        return $this->user(['ROLE_TEACHER', 'ROLE_SIO'], 'author');
    }

    /**
     * @param list<int>                   $perimeterIds
     * @param list<DocumentationAudience> $audiences
     */
    private function article(User $author, array $perimeterIds, array $audiences, DocumentationStatus $status = DocumentationStatus::Published): DocumentationArticle
    {
        $article = new class($author, $perimeterIds) extends DocumentationArticle {
            /** @param list<int> $perimeterIds */
            public function __construct(User $author, private readonly array $perimeterIds)
            {
                parent::__construct($author);
            }

            public function getPerimeterIds(): array
            {
                return $this->perimeterIds;
            }
        };

        return $article->setAudiences($audiences)->setStatus($status);
    }
}
