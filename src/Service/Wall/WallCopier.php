<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallList;
use App\Enum\WallCardStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Copier vers… », « Déplacer vers… » and « Dupliquer », for a card and for a whole list.
 *
 * A copy is a new row holding everything the card says - label, text, link, checklist as ticked -
 * and **copies of its picture and its file**, never the same objects. It keeps its author, and
 * leaves the comments behind: they are a conversation about the card they were written under. A
 * move is the same row somewhere else, comments included.
 *
 * What arrives on a wall is moderated by that wall: a card lands awaiting validation when the wall
 * asks whoever brings it for one - and a card that was still waiting where it came from is never
 * published by being copied.
 *
 * Like App\Service\Wall\WallWriter it decides nothing about who may, and never flushes.
 */
final class WallCopier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WallAccess $access,
        private readonly WallFiles $files,
        private readonly WallWriter $writer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** « Dupliquer »: right under the original, in the same list. */
    public function duplicateCard(WallCard $card, User $user): WallCard
    {
        $copy = $this->cloneInto($card, $card->getList(), $user);
        $copy->setTitle($this->copyTitle($card->getTitle(), WallWriter::TITLE_MAX));
        $this->writer->place($card->getList(), $copy, $this->nextAfter($card));

        return $copy;
    }

    /** « Copier vers… »: at the foot of the list chosen, on this wall or another. */
    public function copyCard(WallCard $card, WallList $target, User $user): WallCard
    {
        $copy = $this->cloneInto($card, $target, $user);
        $this->writer->place($target, $copy);

        return $copy;
    }

    /** « Déplacer vers… »: the card itself, to the foot of the list chosen. */
    public function moveCard(WallCard $card, WallList $target, User $user): void
    {
        $source = $card->getList();
        if ($source === $target) {
            return;
        }

        if ($source->getWall() !== $target->getWall()) {
            $card->setStatus($this->arrivingStatus($card, $target->getWall(), $user));
        }

        $card->setList($target);
        $this->writer->place($target, $card);
        $this->writer->renumber($source);
    }

    /** « Dupliquer la liste »: right after the original, on the same wall. */
    public function duplicateList(WallList $list, User $user): WallList
    {
        $wall = $list->getWall();
        foreach ($wall->getLists() as $other) {
            if ($other->getPosition() > $list->getPosition()) {
                $other->setPosition($other->getPosition() + 1);
            }
        }

        return $this->cloneList($list, $wall, $list->getPosition() + 1, $this->copyTitle($list->getTitle(), WallWriter::LIST_TITLE_MAX), $user);
    }

    /** « Copier la liste vers… »: every card with it, after the last list of the wall chosen. */
    public function copyList(WallList $list, Wall $target, User $user): WallList
    {
        return $this->cloneList($list, $target, $this->writer->nextListPosition($target), $list->getTitle(), $user);
    }

    public function moveList(WallList $list, Wall $target, User $user): void
    {
        if ($list->getWall() === $target) {
            return;
        }

        foreach ($list->getCards() as $card) {
            $card->setStatus($this->arrivingStatus($card, $target, $user));
        }

        $position = $this->writer->nextListPosition($target);
        $list->setWall($target);
        $list->setPosition($position);
    }

    private function cloneList(WallList $list, Wall $target, int $position, string $title, User $user): WallList
    {
        $copy = new WallList($target, $title, $position);
        $copy->setColor($list->getColor());
        $this->entityManager->persist($copy);

        foreach ($list->getCards() as $card) {
            $this->cloneInto($card, $copy, $user)->setPosition($card->getPosition());
        }

        return $copy;
    }

    private function cloneInto(WallCard $card, WallList $target, User $user): WallCard
    {
        $copy = new WallCard($target, $card->getTitle(), $card->getAuthor());
        $copy->setLabel($card->getLabel(), $card->getLabelTone());
        $copy->setText($card->getText());
        $copy->setLink($card->getLinkUrl(), $card->getLinkTitle());
        $copy->setChecklist($card->getChecklist());
        $copy->setStatus($this->arrivingStatus($card, $target->getWall(), $user));

        if (null !== $card->getImageKey()) {
            $copy->setImageKey($this->files->duplicate($card->getImageKey()));
        }
        if (null !== $card->getFileKey()) {
            $copy->setFile($this->files->duplicate($card->getFileKey()), $card->getFileName(), $card->getFileSize());
        }

        $this->entityManager->persist($copy);

        return $copy;
    }

    private function arrivingStatus(WallCard $card, Wall $target, User $user): WallCardStatus
    {
        return $card->isPending() ? WallCardStatus::Pending : $this->access->statusOfNewCard($target, $user);
    }

    private function nextAfter(WallCard $card): ?WallCard
    {
        $next = null;
        foreach ($card->getList()->getCards() as $other) {
            if ($other->getPosition() > $card->getPosition() && (null === $next || $other->getPosition() < $next->getPosition())) {
                $next = $other;
            }
        }

        return $next;
    }

    private function copyTitle(string $title, int $max): string
    {
        $suffix = ' '.$this->translator->trans('wallCopySuffix');

        return mb_substr($title, 0, $max - mb_strlen($suffix)).$suffix;
    }
}
