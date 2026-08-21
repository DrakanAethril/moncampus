<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyResponseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person's response to a campaign - or one *anonymous* response, when $respondent is null
 * (surveys.md §4).
 *
 * There is deliberately no unique index on (campaign, respondent). MySQL lets several NULLs
 * through, which is exactly what is wanted here: an anonymous campaign has n responses with a null
 * respondent. The double response is prevented by survey_target.responded_at, which is also the
 * only thing ever known about an anonymous respondent.
 *
 * $displayKey exists to defuse a trap that no amount of null-ing respondent_id would have avoided:
 * an anonymous campaign whose responses are listed by id or by submitted_at *is not anonymous* -
 * the first row is the first person who answered, and in a class of twenty everybody knows who
 * answers first. So the detail screen and the CSV export of an anonymous campaign sort on this
 * random, stable, non-chronological, non-guessable key, and the rows read « Réponse A3F1 » rather
 * than « Réponse 1 ». Its corollary: started_at and submitted_at are never displayed on an
 * anonymous campaign.
 */
#[ORM\Entity(repositoryClass: SurveyResponseRepository::class)]
#[ORM\Table(name: 'survey_response')]
#[ORM\Index(name: 'idx_survey_response_campaign_submitted', columns: ['survey_campaign_id', 'submitted_at'])]
class SurveyResponse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveyCampaign::class)]
    #[ORM\JoinColumn(name: 'survey_campaign_id', nullable: false, onDelete: 'CASCADE')]
    private ?SurveyCampaign $campaign = null;

    // Null on an anonymous campaign - not "hidden", absent. There is no name to reveal, to anybody.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'respondent_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $respondent = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    // Null while the response is still a draft.
    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'display_key', length: 8, options: ['fixed' => true])]
    private string $displayKey = '';

    /** @var Collection<int, SurveyResponseAnswer> */
    #[ORM\OneToMany(mappedBy: 'response', targetEntity: SurveyResponseAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $answers;

    public function __construct(SurveyCampaign $campaign, ?User $respondent = null)
    {
        $this->campaign = $campaign;
        $this->respondent = $respondent;
        $this->answers = new ArrayCollection();
        $this->startedAt = new \DateTimeImmutable();
        $this->displayKey = self::generateDisplayKey();
    }

    /**
     * Eight characters out of an unambiguous alphabet - no O/0, no I/1/L, because the key is read
     * aloud and copied into a report.
     */
    public static function generateDisplayKey(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $key = '';
        for ($i = 0; $i < 8; ++$i) {
            $key .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return $key;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): ?SurveyCampaign
    {
        return $this->campaign;
    }

    public function getRespondent(): ?User
    {
        return $this->respondent;
    }

    public function setRespondent(?User $respondent): static
    {
        $this->respondent = $respondent;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function isSubmitted(): bool
    {
        return null !== $this->submittedAt;
    }

    public function getDisplayKey(): string
    {
        return $this->displayKey;
    }

    public function setDisplayKey(string $displayKey): static
    {
        $this->displayKey = $displayKey;

        return $this;
    }

    /** The four-character short form the screens print: « Réponse A3F1 ». */
    public function shortDisplayKey(): string
    {
        return substr($this->displayKey, 0, 4);
    }

    /** @return Collection<int, SurveyResponseAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(SurveyResponseAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }

        return $this;
    }

    public function answerFor(SurveyCampaignQuestion $question): ?SurveyResponseAnswer
    {
        foreach ($this->answers as $answer) {
            if ($answer->getQuestion() === $question) {
                return $answer;
            }
        }

        return null;
    }
}
