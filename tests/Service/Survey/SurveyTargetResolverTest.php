<?php

declare(strict_types=1);

namespace App\Tests\Service\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyTarget;
use App\Entity\User;
use App\Repository\SurveyTargetRepository;
use App\Service\AudienceResolver;
use App\Service\Survey\SurveyTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The one rule of the frozen target, and it only goes one way (design/validated/surveys.md §7.2):
 *
 * > While the campaign is open, refreshing **adds** the people now aimed at. Nothing is ever
 * > removed.
 *
 * Not removing is deliberate, and it is the half a future "clean-up" would break: a student who
 * answered and then left the class did answer, and their opinion counts in the measurement that was
 * made. Removing them would silently rewrite a response rate already published.
 */
class SurveyTargetResolverTest extends TestCase
{
    /** @var list<SurveyTarget> */
    private array $persisted = [];

    /**
     * @param list<User> $recipients
     * @param list<int>  $alreadyTargeted
     */
    private function resolver(array $recipients, array $alreadyTargeted): SurveyTargetResolver
    {
        $audience = $this->createStub(AudienceResolver::class);
        $audience->method('resolveRecipients')->willReturn($recipients);

        $targets = $this->createStub(SurveyTargetRepository::class);
        $targets->method('findTargetedUserIds')->willReturn($alreadyTargeted);

        // A stub with a recording callback rather than a mock with expectations: what the tests
        // below assert is *which rows were written*, collected here, not how many times a method was
        // called.
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof SurveyTarget) {
                $this->persisted[] = $entity;
            }
        });

        return new SurveyTargetResolver($audience, $targets, $entityManager);
    }

    private function user(int $id, string $username): User
    {
        $user = new User($username);
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    public function testFreezingWritesOneRowPerPersonAimedAt(): void
    {
        $resolver = $this->resolver([$this->user(1, 'a'), $this->user(2, 'b'), $this->user(3, 'c')], []);

        self::assertSame(3, $resolver->refresh(new SurveyCampaign()));
        self::assertCount(3, $this->persisted);
    }

    /** Called twice in a row, the second call adds nothing. */
    public function testASecondRefreshAddsNothing(): void
    {
        $resolver = $this->resolver([$this->user(1, 'a'), $this->user(2, 'b')], [1, 2]);

        self::assertSame(0, $resolver->refresh(new SurveyCampaign()));
        self::assertSame([], $this->persisted);
    }

    /** A student enrolled after the launch must be able to answer, so they are added. */
    public function testSomebodyJoiningLaterIsAdded(): void
    {
        $resolver = $this->resolver([$this->user(1, 'a'), $this->user(2, 'b'), $this->user(3, 'newcomer')], [1, 2]);

        self::assertSame(1, $resolver->refresh(new SurveyCampaign()));
        self::assertCount(1, $this->persisted);
        self::assertSame('newcomer', $this->persisted[0]->getUser()?->getUsername());
    }

    /**
     * The half that matters: somebody who left the audience keeps their row. Their answer counts in
     * the measurement that was made, and the denominator of a published rate does not shrink.
     */
    public function testSomebodyWhoLeftTheAudienceIsNeverRemoved(): void
    {
        // Only user 1 is still in the audience; 2 and 3 have left the class.
        $resolver = $this->resolver([$this->user(1, 'a')], [1, 2, 3]);

        self::assertSame(0, $resolver->refresh(new SurveyCampaign()), 'nothing is added');
        self::assertSame([], $this->persisted, 'and above all, nothing is removed');
    }

    /** A closed campaign's target is a fact: reopening its results does not re-resolve it. */
    public function testAClosedCampaignStopsMoving(): void
    {
        $resolver = $this->resolver([$this->user(1, 'a'), $this->user(2, 'b')], []);

        $campaign = new SurveyCampaign();
        $campaign->setTargetFrozenAt(new \DateTimeImmutable('2026-01-01'));
        $campaign->setClosedAt(new \DateTimeImmutable('2026-02-01'));

        self::assertSame(0, $resolver->refreshIfOpen($campaign));
        self::assertSame([], $this->persisted);
    }

    public function testAnOpenCampaignIsToppedUp(): void
    {
        $resolver = $this->resolver([$this->user(1, 'a'), $this->user(2, 'b')], [1]);

        $campaign = new SurveyCampaign();
        $campaign->setTargetFrozenAt(new \DateTimeImmutable('2026-01-01'));

        self::assertSame(1, $resolver->refreshIfOpen($campaign, new \DateTimeImmutable('2026-01-15')));
    }
}
