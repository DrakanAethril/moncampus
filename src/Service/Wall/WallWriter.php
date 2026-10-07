<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallComment;
use App\Entity\WallList;
use App\Enum\WallCardStatus;
use App\Enum\WallFormat;
use App\Enum\WallLabelTone;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every change to a collaborative wall, in one place: the screens and anything else that ever
 * writes a wall call these, so a title is trimmed, a colour checked and a file forgotten the same
 * way whoever asks.
 *
 * It decides nothing about *who*: the controllers ask App\Security\Voter\WallVoter first. And it
 * never flushes - the caller commits, which is also when the wall's revision moves and the
 * browsers watching it are told (App\Controller\Wall\WallControllerTrait::commit()).
 *
 * @phpstan-import-type ChecklistItem from WallCard
 */
final class WallWriter
{
    public const int TITLE_MAX = 255;
    public const int LIST_TITLE_MAX = 120;
    public const int LABEL_MAX = 40;
    public const int TEXT_MAX = 5000;
    public const int LINK_MAX = 2000;
    public const int CHECKLIST_ITEM_MAX = 200;
    public const int CHECKLIST_MAX_ITEMS = 50;
    public const int COMMENT_MAX = 2000;

    /** The list colour that means « none »: stored as null so the default follows the theme. */
    public const string DEFAULT_LIST_COLOR = '#e7edf2';

    /**
     * The swatches of a list's « Couleur » menu, in the order they are drawn. A free colour from
     * the picker is accepted too - these are only the ones offered by name.
     *
     * @var array<string, string> translation key => hex
     */
    public const array LIST_COLORS = [
        'wallColorGreyLabel' => self::DEFAULT_LIST_COLOR,
        'wallColorBlueLabel' => '#d6e4f1',
        'wallColorSandLabel' => '#f1e6cc',
        'wallColorGreenLabel' => '#d9ebe0',
        'wallColorMauveLabel' => '#e6ddef',
        'wallColorCoralLabel' => '#f5dcd3',
        'wallColorWhiteLabel' => '#ffffff',
    ];

    /** The six colours of « Fond ». Closed, unlike a list's: a wall's ground is one of these or a picture. */
    public const array BACKGROUND_COLORS = ['#dbe7f2', '#f1e6cc', '#d9ebe0', '#e6ddef', '#12344d', '#3d4a55'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WallAccess $access,
        private readonly WallFiles $files,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * A wall is born with the lists its format calls for - « À faire / En cours / Terminé » for
     * the columns, one « Cartes » for the grid - so that it never opens on nothing.
     */
    public function create(User $owner, string $title, WallFormat $format): Wall
    {
        $title = $this->clean($title, self::TITLE_MAX);
        $wall = new Wall($owner, '' === $title ? $this->translator->trans('wallUntitledName') : $title, $format);
        $this->entityManager->persist($wall);

        foreach ($format->initialListKeys() as $position => $key) {
            $this->entityManager->persist(new WallList($wall, $this->translator->trans($key), $position));
        }

        return $wall;
    }

    public function rename(Wall $wall, string $title): void
    {
        $wall->setTitle($this->required($title, self::TITLE_MAX, 'wallEmptyTitleMessage'));
    }

    /**
     * « Paramètres du mur »: only the settings the call names are written, so that one switch
     * flipped in one browser never rewrites the six others with what that browser last saw.
     */
    public function applySettings(Wall $wall, JsonRequestPayload $settings, User $user): void
    {
        if ($settings->has('title')) {
            $this->rename($wall, $settings->string('title'));
        }

        if ($settings->has('format')) {
            $wall->setFormat(WallFormat::tryFrom($settings->string('format')) ?? throw new WallRefusal('wallInvalidRequestMessage'));
        }

        if ($settings->has('backgroundImage')) {
            $key = $this->files->claimImage($settings->string('backgroundImage'), $user);
            $this->files->forget($wall->getBackgroundImageKey());
            $wall->setBackgroundImageKey($key);
        } elseif ($settings->has('backgroundColor')) {
            $color = '' === $settings->string('backgroundColor') ? null : self::color($settings->string('backgroundColor'));
            if (null !== $color && !\in_array($color, self::BACKGROUND_COLORS, true)) {
                throw new WallRefusal('wallInvalidColorMessage');
            }
            $this->files->forget($wall->getBackgroundImageKey());
            $wall->setBackgroundColor($color);
        }

        foreach ([
            'authorsShown' => $wall->setAuthorsShown(...),
            'labelsShown' => $wall->setLabelsShown(...),
            'countsShown' => $wall->setCountsShown(...),
            'commentsEnabled' => $wall->setCommentsEnabled(...),
            'participantsMayAdd' => $wall->setParticipantsMayAdd(...),
            'participantsMayEditOthers' => $wall->setParticipantsMayEditOthers(...),
            'moderated' => $wall->setModerated(...),
        ] as $key => $setter) {
            if ($settings->has($key)) {
                $setter($settings->bool($key));
            }
        }
    }

    public function delete(Wall $wall): void
    {
        $this->files->forgetWall($wall);
        $this->entityManager->remove($wall);
    }

    public function addList(Wall $wall, string $title): WallList
    {
        $list = new WallList($wall, $this->required($title, self::LIST_TITLE_MAX, 'wallEmptyTitleMessage'), $this->nextListPosition($wall));
        $this->entityManager->persist($list);

        return $list;
    }

    public function renameList(WallList $list, string $title): void
    {
        $list->setTitle($this->required($title, self::LIST_TITLE_MAX, 'wallEmptyTitleMessage'));
    }

    public function colorList(WallList $list, string $color): void
    {
        $color = self::color($color);
        $list->setColor(self::DEFAULT_LIST_COLOR === $color ? null : $color);
    }

    public function deleteList(WallList $list): void
    {
        $this->files->forgetList($list);
        $list->getWall()->removeList($list);
        $this->entityManager->remove($list);
    }

    /** A new card goes to the foot of its list, awaiting validation if the wall asks its author for one. */
    public function addCard(WallList $list, User $author, string $title): WallCard
    {
        $card = new WallCard($list, $this->required($title, self::TITLE_MAX, 'wallEmptyTitleMessage'), $author);
        $card->setPosition($this->nextCardPosition($list, $card));
        $card->setStatus($this->access->statusOfNewCard($list->getWall(), $author));
        $this->entityManager->persist($card);

        return $card;
    }

    /**
     * Writes the fields the call names and no other. A block is removed by naming it empty: an
     * empty text, an empty link, `image: null`.
     */
    public function updateCard(WallCard $card, JsonRequestPayload $fields, User $user): void
    {
        if ($fields->has('title')) {
            $card->setTitle($this->required($fields->string('title'), self::TITLE_MAX, 'wallEmptyTitleMessage'));
        }

        if ($fields->has('label')) {
            $label = $this->clean($fields->string('label'), self::LABEL_MAX);
            $card->setLabel('' === $label ? null : $label, WallLabelTone::tryFrom($fields->string('labelTone')) ?? $card->getLabelTone());
        }

        if ($fields->has('text')) {
            $text = mb_substr(trim($fields->string('text')), 0, self::TEXT_MAX);
            $card->setText('' === $text ? null : $text);
        }

        if ($fields->has('linkUrl')) {
            $url = trim($fields->string('linkUrl'));
            if ('' === $url) {
                $card->setLink(null);
            } else {
                $url = self::url($url);
                $title = $this->clean($fields->string('linkTitle'), self::TITLE_MAX);
                $card->setLink($url, '' === $title ? (string) parse_url($url, \PHP_URL_HOST) : $title);
            }
        }

        if ($fields->has('image')) {
            $token = $fields->string('image');
            $key = '' === $token ? null : $this->files->claimImage($token, $user);
            $this->files->forget($card->getImageKey());
            $card->setImageKey($key);
        }

        if ($fields->has('file')) {
            $token = $fields->string('file');
            $file = '' === $token ? null : $this->files->claimFile($token, $user);
            $this->files->forget($card->getFileKey());
            $card->setFile($file['key'] ?? null, $file['name'] ?? null, $file['size'] ?? null);
        }
    }

    /**
     * Puts a card in a list of its own wall, before the card named or at the foot. The neighbour
     * is named rather than a rank given: a participant does not see the cards awaiting validation,
     * so the rank they count is not the rank the list holds.
     */
    public function moveCard(WallCard $card, WallList $target, ?WallCard $before): void
    {
        if ($target->getWall() !== $card->getWall() || (null !== $before && ($before->getList() !== $target || $before === $card))) {
            throw new WallRefusal('wallInvalidRequestMessage');
        }

        $source = $card->getList();
        $card->setList($target);
        $this->place($target, $card, $before);
        if ($source !== $target) {
            $this->renumber($source);
        }
    }

    public function deleteCard(WallCard $card): void
    {
        $this->files->forgetCard($card);
        $list = $card->getList();
        $list->removeCard($card);
        $this->entityManager->remove($card);
        $this->renumber($list);
    }

    public function approve(WallCard $card): void
    {
        $card->setStatus(WallCardStatus::Published);
    }

    public function addChecklistItem(WallCard $card, string $text): void
    {
        $items = $card->getChecklist();
        if (\count($items) >= self::CHECKLIST_MAX_ITEMS) {
            throw new WallRefusal('wallChecklistFullMessage');
        }

        $items[] = ['id' => bin2hex(random_bytes(4)), 'text' => $this->required($text, self::CHECKLIST_ITEM_MAX, 'wallEmptyTitleMessage'), 'done' => false];
        $card->setChecklist($items);
    }

    /** An item somebody else has just removed is simply not there any more: nothing to refuse. */
    public function toggleChecklistItem(WallCard $card, string $itemId): void
    {
        $card->setChecklist(array_map(
            static fn (array $item): array => $item['id'] === $itemId ? ['id' => $item['id'], 'text' => $item['text'], 'done' => !$item['done']] : $item,
            $card->getChecklist(),
        ));
    }

    public function removeChecklistItem(WallCard $card, string $itemId): void
    {
        $card->setChecklist(array_filter($card->getChecklist(), static fn (array $item): bool => $item['id'] !== $itemId));
    }

    public function comment(WallCard $card, User $author, string $text): WallComment
    {
        $text = mb_substr(trim($text), 0, self::COMMENT_MAX);
        if ('' === $text) {
            throw new WallRefusal('wallEmptyCommentMessage');
        }

        $comment = new WallComment($card, $author, $text);
        $this->entityManager->persist($comment);

        return $comment;
    }

    public function deleteComment(WallComment $comment): void
    {
        $comment->getCard()->getComments()->removeElement($comment);
        $this->entityManager->remove($comment);
    }

    /** Inserts the card before its neighbour (or last) and rewrites the list's ranks 0..n. */
    public function place(WallList $list, WallCard $card, ?WallCard $before = null): void
    {
        $ordered = [];
        foreach ($this->sorted($list) as $other) {
            if ($other === $card) {
                continue;
            }
            if ($other === $before) {
                $ordered[] = $card;
            }
            $ordered[] = $other;
        }
        if (null === $before) {
            $ordered[] = $card;
        }

        foreach ($ordered as $position => $each) {
            $each->setPosition($position);
        }
    }

    public function renumber(WallList $list): void
    {
        foreach ($this->sorted($list) as $position => $card) {
            $card->setPosition($position);
        }
    }

    public function nextListPosition(Wall $wall): int
    {
        $last = -1;
        foreach ($wall->getLists() as $list) {
            $last = max($last, $list->getPosition());
        }

        return $last + 1;
    }

    /** A colour as the picker and the swatches send it: `#rrggbb`, lowercased. */
    public static function color(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (1 !== preg_match('/\A#[0-9a-f]{6}\z/', $value)) {
            throw new WallRefusal('wallInvalidColorMessage');
        }

        return $value;
    }

    /**
     * A link somebody typed: `ovhcloud.com/fr/vps` is what people write, so a missing scheme is
     * https. Anything that is then not a plain web address - `javascript:`, a file path - is refused.
     */
    public static function url(string $value): string
    {
        $url = 1 === preg_match('#\A[a-z][a-z0-9+.-]*:#i', $value) ? $value : 'https://'.$value;
        $scheme = mb_strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        if (mb_strlen($url) > self::LINK_MAX || !\in_array($scheme, ['http', 'https'], true) || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new WallRefusal('wallInvalidLinkMessage');
        }

        return $url;
    }

    private function nextCardPosition(WallList $list, WallCard $card): int
    {
        $last = -1;
        foreach ($list->getCards() as $other) {
            if ($other !== $card) {
                $last = max($last, $other->getPosition());
            }
        }

        return $last + 1;
    }

    /** @return list<WallCard> */
    private function sorted(WallList $list): array
    {
        $cards = $list->getCards()->toArray();
        usort($cards, static fn (WallCard $a, WallCard $b): int => [$a->getPosition(), $a->getId() ?? \PHP_INT_MAX] <=> [$b->getPosition(), $b->getId() ?? \PHP_INT_MAX]);

        return $cards;
    }

    private function clean(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $max);
    }

    private function required(string $value, int $max, string $refusalKey): string
    {
        $value = $this->clean($value, $max);

        return '' === $value ? throw new WallRefusal($refusalKey) : $value;
    }
}
