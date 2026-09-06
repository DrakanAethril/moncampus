<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Security\StructureAccessChecker;
use App\Service\WordCloud\WordCloudAudience;
use App\Service\WordCloud\WordCloudSchedule;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may run a word cloud, and who may write into one.
 *
 * Two attributes, because they are two unrelated questions asked of two different people:
 *
 * - **PILOT** is the whole teacher side - creating, editing, moderating, opening, closing,
 *   projecting, reading the follow-up and exporting it. Any teacher of the class, not only its
 *   author: a cloud is run in front of a room, and the colleague taking the next hour must be able
 *   to reopen the question. Staff pass, through StructureAccessChecker::isProgramTeacher().
 * - **SUBMIT** is one student writing one word. It requires the cloud to be **open right now** and
 *   the student to be in its audience, and it is deliberately *not* staff-bypassed: an
 *   administrator is not a member of the class, and a word from them would count in the class's
 *   own cloud.
 *
 * Reading a cloud is not an attribute of its own. A teacher reads it because they pilot it; a
 * student reads it because « Les étudiants voient le nuage sur leur écran » is on, which is a
 * setting rather than a permission and is read by the controller next to this.
 */
class WordCloudVoter extends Voter
{
    public const string PILOT = 'WORD_CLOUD_PILOT';
    public const string SUBMIT = 'WORD_CLOUD_SUBMIT';

    public function __construct(
        private readonly StructureAccessChecker $structureAccessChecker,
        private readonly WordCloudAudience $audience,
        private readonly WordCloudSchedule $schedule,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::PILOT, self::SUBMIT], true) && $subject instanceof WordCloud;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var WordCloud $cloud */
        $cloud = $subject;
        $user = $token->getUser();
        $program = $cloud->getProgram();

        if (!$user instanceof User || null === $program) {
            return false;
        }

        return match ($attribute) {
            self::PILOT => $this->structureAccessChecker->isProgramTeacher($program),
            // The period is checked here as well as at the endpoint, and on purpose: this is what
            // makes « masquer le formulaire » and « refuser le mot » the same decision, taken in
            // one place, rather than two rules that can drift apart.
            self::SUBMIT => $this->schedule->isOpen($cloud->window(), new \DateTimeImmutable())
                && $this->audience->includes($cloud, $user),
            default => false,
        };
    }
}
