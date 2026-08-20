<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GroupBatch;
use App\Entity\User;
use App\Entity\Wiki;
use App\Enum\WikiType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creating one shared wiki per group of a saved set, each holding that group's students.
 *
 * Three things are deliberate:
 *
 * - **Nothing is unique.** Running this twice over the same set creates a second family of wikis
 *   rather than refusing or reusing the first: a class does two projects a term, and the set that
 *   composes the groups is the same one both times. So there is no lookup of "already provisioned"
 *   anywhere here, and no column recording the origin - the title carries it.
 * - **The set's snapshot is read as it stands**, ids and all. A student who has left the class
 *   since the set was saved no longer resolves and is simply dropped, the same way the
 *   group-creation screen drops them when it reloads a set.
 * - **The wikis are Shared and their creator is the composer**, never Personal - a personal wiki
 *   refuses members outright (Wiki::assertShared()).
 *
 * Note what putting students in a shared wiki implies, because it is the model's own rule and not
 * a choice made here: as soon as a wiki has a student member, WikiAccess counts it as having a
 * student audience, and every teacher and staff member may then read it. Group wikis are not
 * private to their group and their teacher.
 */
class WikiGroupProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly WikiNodeManager $nodeManager,
        private readonly GroupWikiPlanner $planner,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<Wiki> the wikis created, in group order - empty when every group is empty
     */
    public function provision(GroupBatch $batch, string $titlePrefix, User $creator): array
    {
        $plan = $this->planner->plan(
            $batch->getGroups(),
            '' === trim($titlePrefix) ? $batch->getName() : $titlePrefix,
            $this->translator->trans('programToolsGroupTitleTemplateLabel'),
        );

        if ([] === $plan) {
            return [];
        }

        $membersById = $this->membersById($plan);
        $wikis = [];

        foreach ($plan as $row) {
            $wiki = new Wiki($row['title'], WikiType::Shared, $creator);

            foreach ($row['memberIds'] as $memberId) {
                $member = $membersById[$memberId] ?? null;

                if ($member instanceof User) {
                    $wiki->addMember($member);
                }
            }

            $this->entityManager->persist($wiki);
            $wikis[] = $wiki;
        }

        // The home page needs the wiki to carry an id, so the wikis are flushed first and the pages
        // in a second pass - the same two-step the creation form does for a single wiki.
        $this->entityManager->flush();

        foreach ($wikis as $wiki) {
            $this->nodeManager->createHomePage(
                $wiki,
                $creator,
                $this->translator->trans('wikiHomePageTitle'),
                '<p>'.$this->translator->trans('wikiHomePageWelcomeText').'</p>',
            );
        }

        $this->entityManager->flush();

        return $wikis;
    }

    /**
     * @param list<array{title: string, memberIds: list<int>}> $plan
     *
     * @return array<int, User> keyed by id - one query for the whole set rather than one per group
     */
    private function membersById(array $plan): array
    {
        $ids = array_values(array_unique(array_merge(...array_map(
            static fn (array $row): array => $row['memberIds'],
            $plan,
        ))));

        $byId = [];

        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            $byId[$user->getId()] = $user;
        }

        return $byId;
    }
}
