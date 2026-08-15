<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SkillRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One evaluable skill/competency (e.g. "Développer des interfaces utilisateurs") within a
 * SkillGroup on the Livret Alternant referential.
 *
 * Beyond the label the Livret needs, a Skill carries the whole body of its "fiche" in the training
 * referential document (the TSF): what the occupation does, the knowledge and activities it
 * covers, how it is judged, and how it is scheduled. Those fields are filled either by hand in the
 * program settings or by App\Command\ImportTsfReferentialCommand, and read by the fiche export.
 *
 * The three list fields hold HugeRTE HTML (a <ul> in practice), like
 * ContractType::$defaultModalitiesHtml: nothing in the app addresses an individual bullet, so
 * child rows would buy nothing.
 */
#[ORM\Entity(repositoryClass: SkillRepository::class)]
#[ORM\Table(name: 'skill')]
class Skill
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    #[ORM\ManyToOne(targetEntity: SkillGroup::class, inversedBy: 'skills')]
    #[ORM\JoinColumn(name: 'skill_group_id', nullable: false)]
    #[Assert\NotNull]
    private ?SkillGroup $skillGroup = null;

    /**
     * The referential's own reference for this competency, "C.1". Deliberately not unique: a
     * Program preparing two certifications has a C.1 on each side (CDA C.1 and AIS C.1), and the
     * two are different competencies.
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $code = null;

    // "order" is a reserved SQL word, hence the quoted column name - same as
    // SequenceTemplate::$order. Rows used to be listed by id, which happened to be the right order
    // for the referential imported first; this makes it explicit.
    #[ORM\Column(name: '`order`')]
    private int $order = 0;

    /** "Description métier" - what the occupation does, from the referential. */
    #[ORM\Column(name: 'occupation_description', type: Types::TEXT, nullable: true)]
    private ?string $occupationDescription = null;

    /** "Connaissances" - HTML. */
    #[ORM\Column(name: 'knowledge_html', type: Types::TEXT, nullable: true)]
    private ?string $knowledgeHtml = null;

    /** "Activités" - HTML. */
    #[ORM\Column(name: 'activities_html', type: Types::TEXT, nullable: true)]
    private ?string $activitiesHtml = null;

    /** "Critères de performance" - HTML. */
    #[ORM\Column(name: 'performance_criteria_html', type: Types::TEXT, nullable: true)]
    private ?string $performanceCriteriaHtml = null;

    /**
     * The three "Modalités d'évaluation" strands, kept apart because the fiche labels each one and
     * they are read separately. They are descriptive text, not a link to any Evaluation row -
     * which is why App\Enum\EvaluationNature (diagnostic/formative/summative) needs no
     * "certificative" case on their account.
     */
    #[ORM\Column(name: 'diagnostic_assessment_html', type: Types::TEXT, nullable: true)]
    private ?string $diagnosticAssessmentHtml = null;

    #[ORM\Column(name: 'summative_assessment_html', type: Types::TEXT, nullable: true)]
    private ?string $summativeAssessmentHtml = null;

    #[ORM\Column(name: 'certifying_assessment_html', type: Types::TEXT, nullable: true)]
    private ?string $certifyingAssessmentHtml = null;

    /** "Volume" - 30H. Decimal like Topic's target hours, so the two can be summed together. */
    #[ORM\Column(name: 'volume_hours', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $volumeHours = null;

    /**
     * "Période" - month names as the referential writes them, including discontinuous ones
     * ("Septembre-Octobre + Janvier-Février"). Free text on purpose: these do not line up with any
     * Period row, which is a dated range inside a PeriodGroup.
     */
    #[ORM\Column(name: 'teaching_period_label', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $teachingPeriodLabel = null;

    /**
     * "Intervenant" - the teacher for this competency, which is finer than SkillGroup::$teacher:
     * one CCP routinely spreads its competencies over several teachers.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: true)]
    private ?User $teacher = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label, SkillGroup $skillGroup)
    {
        $this->label = $label;
        $this->creationDate = new \DateTimeImmutable();
        $this->setSkillGroup($skillGroup);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getSkillGroup(): ?SkillGroup
    {
        return $this->skillGroup;
    }

    public function setSkillGroup(?SkillGroup $skillGroup): static
    {
        $this->skillGroup = $skillGroup;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $skillGroup && !$skillGroup->getSkills()->contains($this)) {
            $skillGroup->getSkills()->add($this);
        }

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function setOrder(int $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function getOccupationDescription(): ?string
    {
        return $this->occupationDescription;
    }

    public function setOccupationDescription(?string $occupationDescription): static
    {
        $this->occupationDescription = $occupationDescription;

        return $this;
    }

    public function getKnowledgeHtml(): ?string
    {
        return $this->knowledgeHtml;
    }

    public function setKnowledgeHtml(?string $knowledgeHtml): static
    {
        $this->knowledgeHtml = $knowledgeHtml;

        return $this;
    }

    public function getActivitiesHtml(): ?string
    {
        return $this->activitiesHtml;
    }

    public function setActivitiesHtml(?string $activitiesHtml): static
    {
        $this->activitiesHtml = $activitiesHtml;

        return $this;
    }

    public function getPerformanceCriteriaHtml(): ?string
    {
        return $this->performanceCriteriaHtml;
    }

    public function setPerformanceCriteriaHtml(?string $performanceCriteriaHtml): static
    {
        $this->performanceCriteriaHtml = $performanceCriteriaHtml;

        return $this;
    }

    public function getDiagnosticAssessmentHtml(): ?string
    {
        return $this->diagnosticAssessmentHtml;
    }

    public function setDiagnosticAssessmentHtml(?string $diagnosticAssessmentHtml): static
    {
        $this->diagnosticAssessmentHtml = $diagnosticAssessmentHtml;

        return $this;
    }

    public function getSummativeAssessmentHtml(): ?string
    {
        return $this->summativeAssessmentHtml;
    }

    public function setSummativeAssessmentHtml(?string $summativeAssessmentHtml): static
    {
        $this->summativeAssessmentHtml = $summativeAssessmentHtml;

        return $this;
    }

    public function getCertifyingAssessmentHtml(): ?string
    {
        return $this->certifyingAssessmentHtml;
    }

    public function setCertifyingAssessmentHtml(?string $certifyingAssessmentHtml): static
    {
        $this->certifyingAssessmentHtml = $certifyingAssessmentHtml;

        return $this;
    }

    public function getVolumeHours(): ?string
    {
        return $this->volumeHours;
    }

    public function setVolumeHours(?string $volumeHours): static
    {
        $this->volumeHours = $volumeHours;

        return $this;
    }

    public function getTeachingPeriodLabel(): ?string
    {
        return $this->teachingPeriodLabel;
    }

    public function setTeachingPeriodLabel(?string $teachingPeriodLabel): static
    {
        $this->teachingPeriodLabel = $teachingPeriodLabel;

        return $this;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function setTeacher(?User $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }
}
