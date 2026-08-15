<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\DocumentationArticle;
use App\Entity\User;
use App\Service\DocumentationAccess;
use App\Service\DocumentationPerimeter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who reads and who writes one article of the documentation base.
 *
 * VIEW is App\Service\DocumentationAccess's rule and nothing else - the perimeter and the audience
 * of the article against the reader. MANAGE is the narrower question the handoff's three article
 * variants turn on: the owner, staff, staff-lead and admin edit and see the management panel; any
 * other teacher gets the same panel inert, which is a template concern, not a second attribute.
 */
class DocumentationArticleVoter extends Voter
{
    public const string VIEW = 'DOCUMENTATION_ARTICLE_VIEW';
    public const string MANAGE = 'DOCUMENTATION_ARTICLE_MANAGE';

    public function __construct(
        private readonly DocumentationAccess $access,
        private readonly DocumentationPerimeter $perimeter,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true) && $subject instanceof DocumentationArticle;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var DocumentationArticle $article */
        $article = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();
        $isManager = $this->access->isManagerRole($roles);

        return match ($attribute) {
            // Compared as objects rather than on the id, like every other Voter here: Doctrine's
            // identity map returns the one instance, and two ids that are both null would
            // otherwise make any hand-built user the owner of any hand-built article.
            self::MANAGE => $isManager || $article->getAuthor() === $user,
            self::VIEW => $this->access->allowsArticle($article, $this->perimeter->readerGroupIds($user), $roles),
            default => false,
        };
    }
}
