<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WordCloudModerationState;
use App\Repository\WordCloudSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One word, from one student, at one moment.
 *
 * **This table is the whole of the tool's data.** The cloud on the board, the two follow-up views,
 * the participation count and the CSV are four readings of these rows and nothing else - « les deux
 * vues de suivi ne sont que deux agrégations de la même donnée ». Nothing is ever aggregated into a
 * stored total, so a moderated word changes every screen at once.
 *
 * Refused rows stay. They leave the cloud, not the history: the teacher keeps a nominative record
 * of what was proposed, and the student cannot send the refused word straight back.
 */
#[ORM\Entity(repositoryClass: WordCloudSubmissionRepository::class)]
#[ORM\Table(name: 'word_cloud_submission')]
// One student, one word. The key is the fully normalised form whatever « Regrouper les variantes »
// says: that setting decides how words are *counted*, and « chat » then « Chat » from the same
// person is the same person saying the same thing either way. Enforced in the database rather than
// only in WordCloudSubmissionPolicy, because two tabs racing is exactly how a duplicate gets in.
#[ORM\UniqueConstraint(name: 'uniq_word_cloud_student_word', columns: ['word_cloud_id', 'student_id', 'normalized_text'])]
// The pilot screen and both projections read a cloud's words in arrival order at every refresh.
#[ORM\Index(name: 'idx_word_cloud_submission_cloud_time', columns: ['word_cloud_id', 'submitted_at'])]
class WordCloudSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WordCloud::class, inversedBy: 'submissions')]
    #[ORM\JoinColumn(name: 'word_cloud_id', nullable: false)]
    private ?WordCloud $wordCloud = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    // As typed, trimmed. This is what the board shows back - WordCloudAggregator picks the most
    // frequent spelling of a group, so the class reads its own words rather than a machine's.
    #[ORM\Column(length: 60)]
    private ?string $text = null;

    // The grouping key: lower-case, unaccented, punctuation flattened, simple plurals dropped
    // (App\Service\WordCloud\WordCloudNormalizer). Never displayed.
    #[ORM\Column(name: 'normalized_text', length: 60)]
    private ?string $normalizedText = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(name: 'moderation_state', length: 20, enumType: WordCloudModerationState::class)]
    private WordCloudModerationState $moderationState = WordCloudModerationState::Approved;

    public function __construct(WordCloud $wordCloud, User $student, string $text, string $normalizedText, WordCloudModerationState $moderationState)
    {
        $this->wordCloud = $wordCloud;
        $this->student = $student;
        $this->text = $text;
        $this->normalizedText = $normalizedText;
        $this->moderationState = $moderationState;
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWordCloud(): ?WordCloud
    {
        return $this->wordCloud;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getNormalizedText(): ?string
    {
        return $this->normalizedText;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getModerationState(): WordCloudModerationState
    {
        return $this->moderationState;
    }

    public function setModerationState(WordCloudModerationState $moderationState): static
    {
        $this->moderationState = $moderationState;

        return $this;
    }

    /** In the cloud: everything the teacher has not taken out. */
    public function isCounted(): bool
    {
        return WordCloudModerationState::Approved === $this->moderationState;
    }
}
