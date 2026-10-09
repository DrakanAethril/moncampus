<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Evaluation;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Carnet de notes access, in two steps rather than one, because a matière can be held by several
 * titulaires (Topic::$teachers) who each keep their own evaluations inside it:
 *
 *  - **being a titulaire of the matière opens reading** - the whole carnet of that matière, a
 *    co-titulaire's evaluations included. Two people teaching the same matière to the same class
 *    see the same carnet; that is what holding it together means.
 *  - **authorship alone opens writing.** MANAGE is the titulaire check *and* Evaluation::$createdBy
 *    being the reader: a barème, a coefficient, a date and a column of grades belong to whoever
 *    posed the devoir, and a colleague never rewrites them.
 *
 * An evaluation still being created carries no author yet (the controller asks this voter before
 * stamping createdBy on a `new Evaluation`), so a null author reads as "mine, in the making".
 *
 * Staff are deliberately not bypassed on MANAGE, exactly as before: an administrator reads every
 * matière of the class and writes none. VIEW additionally lets staff and an enrolled student
 * through - the student only once the evaluation is actually visible to them
 * (Evaluation::isVisibleAt()) - callers still need to scope which Grade rows a student sees to
 * their own (never another student's, never a ranking), this voter only gates the evaluation
 * itself.
 *
 * READ_GRADES is the other reading, the one that carries **the whole class's marks**: the entry
 * screen opened read-only, and what the Claude connector reads back. It is VIEW without the student
 * and with the class's referent teachers, who read every matière of their class: titulaires,
 * referents, staff - and nobody a mark is about.
 */
class EvaluationVoter extends Voter
{
    public const string VIEW = 'EVALUATION_VIEW';
    public const string MANAGE = 'EVALUATION_MANAGE';
    public const string READ_GRADES = 'EVALUATION_READ_GRADES';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE, self::READ_GRADES], true) && $subject instanceof Evaluation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Evaluation $evaluation */
        $evaluation = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $topic = $evaluation->getTopic();
        $isTitulaire = null !== $topic && $topic->hasTeacher($user);

        if (self::MANAGE === $attribute) {
            // Staff read every matière, but write none they do not teach themselves: a carnet is
            // the work of the teachers who hold the matière, and an administrator watching over it
            // is still a reader. Deliberately NOT a staff bypass, unlike most screens.
            $author = $evaluation->getCreatedBy();

            return $isTitulaire && (null === $author || $author === $user);
        }

        if ($isTitulaire) {
            return true;
        }

        if ($this->accessChecker->isStaff()) {
            return true;
        }

        if (self::READ_GRADES === $attribute) {
            $program = $topic?->getProgram();

            return null !== $program && $this->accessChecker->isProgramReferentTeacher($program);
        }

        return null !== $topic
            && $topic->getProgram()->getStudents()->contains($user)
            && $evaluation->isVisibleAt(new \DateTimeImmutable());
    }
}
