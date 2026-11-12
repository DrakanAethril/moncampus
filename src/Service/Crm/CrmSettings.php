<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmDossier;
use App\Entity\CrmPiece;
use App\Entity\CrmProgram;
use App\Entity\CrmSchoolYear;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Enum\CrmModality;
use App\Repository\CrmPieceRepository;
use App\Repository\CrmProgramRepository;
use App\Repository\CrmSchoolYearRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The CRM's settings as everything else reads them: the years opened and the default one, the
 * formations followed in a year, a formation's own settings (or their defaults when it has none),
 * the piece catalogue, and a dossier's progress against them.
 *
 * Memoised per request (worker mode: ResetInterface).
 */
final class CrmSettings implements ResetInterface
{
    /** @var list<CrmPiece>|null */
    private ?array $catalogue = null;

    /** @var array<int, CrmProgram|null> */
    private array $programSettings = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CrmPieceRepository $pieces,
        private readonly CrmProgramRepository $crmPrograms,
        private readonly CrmSchoolYearRepository $crmYears,
        private readonly SchoolYearRepository $schoolYears,
        private readonly ProgramRepository $programs,
    ) {
    }

    public function reset(): void
    {
        $this->catalogue = null;
        $this->programSettings = [];
    }

    /** @return list<CrmPiece> every piece, in the catalogue's order */
    public function catalogue(): array
    {
        return $this->catalogue ??= $this->pieces->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function piece(string $code): ?CrmPiece
    {
        foreach ($this->catalogue() as $piece) {
            if ($piece->getCode() === $code) {
                return $piece;
            }
        }

        return null;
    }

    public function forgetCatalogue(): void
    {
        $this->catalogue = null;
    }

    // --- Years ------------------------------------------------------------------------------

    /** @return list<SchoolYear> Formatool's years, the latest first */
    public function schoolYears(): array
    {
        return $this->schoolYears->findBy([], ['startDate' => 'DESC']);
    }

    public function yearRow(SchoolYear $year): ?CrmSchoolYear
    {
        return $this->crmYears->findOneBy(['schoolYear' => $year]);
    }

    public function isOpened(SchoolYear $year): bool
    {
        return true === $this->yearRow($year)?->isOpened();
    }

    /** @return list<SchoolYear> the years opened in the CRM, the latest first */
    public function openedYears(): array
    {
        return array_values(array_filter($this->schoolYears(), fn (SchoolYear $y): bool => $this->isOpened($y)));
    }

    public function defaultYear(): ?SchoolYear
    {
        $row = $this->crmYears->findOneBy(['default' => true, 'opened' => true]);

        return $row?->getSchoolYear() ?? ($this->openedYears()[0] ?? null);
    }

    /** The year one works on: the one asked for if it is opened, otherwise the default. */
    public function workYear(?int $wanted): ?SchoolYear
    {
        foreach ($this->openedYears() as $year) {
            if ($year->getId() === $wanted) {
                return $year;
            }
        }

        return $this->defaultYear();
    }

    /** @throws CrmRefusal when the default year would be closed */
    public function setOpened(SchoolYear $year, bool $opened): void
    {
        $row = $this->yearRow($year) ?? new CrmSchoolYear($year);
        if (!$opened && $row->isDefault()) {
            throw CrmRefusal::because('crmRefusalDefaultYearClosed', ['%year%' => self::yearLabel($year)]);
        }
        $row->setOpened($opened);
        $this->entityManager->persist($row);
        if ($opened && null === $this->crmYears->findOneBy(['default' => true])) {
            $row->setDefault(true);
        }
    }

    /** @throws CrmRefusal when the year is not opened */
    public function setDefault(SchoolYear $year): void
    {
        $row = $this->yearRow($year);
        if (null === $row || !$row->isOpened()) {
            throw CrmRefusal::because('crmRefusalDefaultYearNotOpened', ['%year%' => self::yearLabel($year)]);
        }
        foreach ($this->crmYears->findBy(['default' => true]) as $other) {
            $other->setDefault(false);
        }
        $row->setDefault(true);
    }

    public static function yearLabel(SchoolYear $year): string
    {
        return \sprintf('%s-%s', $year->getStartDate()?->format('Y'), $year->getEndDate()?->format('Y'));
    }

    // --- Formations -------------------------------------------------------------------------

    /** @return list<Program> the year's Formatool formations, test ones apart, by section then name */
    public function programsOf(SchoolYear $year): array
    {
        $programs = array_values(array_filter(
            $this->programs->findBy(['schoolYear' => $year]),
            static fn (Program $p): bool => !$p->isTestProgram() && null === $p->getInactiveDate(),
        ));
        usort($programs, static fn (Program $a, Program $b): int => [self::sectionName($a), $a->getShortName()] <=> [self::sectionName($b), $b->getShortName()]);

        return $programs;
    }

    /** @return list<Program> the formations followed in the CRM that year */
    public function followedPrograms(SchoolYear $year): array
    {
        return array_values(array_filter($this->programsOf($year), fn (Program $p): bool => true === $this->settingsOf($p)?->isFollowed()));
    }

    public function isFollowed(Program $program): bool
    {
        return true === $this->settingsOf($program)?->isFollowed();
    }

    /** A formation's CRM settings; null when the CRM never touched it - read the defaults then. */
    public function settingsOf(Program $program): ?CrmProgram
    {
        $id = (int) $program->getId();
        if (!\array_key_exists($id, $this->programSettings)) {
            $this->programSettings[$id] = $this->crmPrograms->findOneBy(['program' => $program]);
        }

        return $this->programSettings[$id];
    }

    /** A formation's settings, created (and persisted) the first time something is set. */
    public function settingsFor(Program $program): CrmProgram
    {
        $settings = $this->settingsOf($program);
        if (null === $settings) {
            $settings = new CrmProgram($program);
            $this->entityManager->persist($settings);
            $this->programSettings[(int) $program->getId()] = $settings;
        }

        return $settings;
    }

    public function defaultModality(Program $program): CrmModality
    {
        return $this->settingsOf($program)?->getDefaultModality() ?? CrmModality::Initial;
    }

    /** @return list<Option> every Formatool option of the formation, by short name */
    public function programOptions(Program $program): array
    {
        $options = array_values($program->getOptions()->toArray());
        usort($options, static fn (Option $a, Option $b): int => $a->getShortName() <=> $b->getShortName());

        return $options;
    }

    /**
     * The options the CRM follows apart in the formation - the ones a dossier chooses among. An
     * option taken off the formation in Formatool is no longer one, whatever was ticked.
     *
     * @return list<Option> by short name
     */
    public function optionsOf(Program $program): array
    {
        $settings = $this->settingsOf($program);
        if (null === $settings) {
            return [];
        }

        return array_values(array_filter($this->programOptions($program), static fn (Option $o): bool => $settings->tracksOption((int) $o->getId())));
    }

    /** Whether a dossier of the formation must name one of its options. */
    public function asksOption(Program $program): bool
    {
        return [] !== $this->optionsOf($program);
    }

    /**
     * The option lines of a formation that asks for one: the options followed, then the options
     * dossiers still carry although they are no longer followed - unticking an option moves no
     * dossier, so its dossiers keep a line to be counted and found on.
     *
     * @param iterable<CrmDossierProgress> $progresses
     *
     * @return list<Option>
     */
    public function optionLines(Program $program, iterable $progresses): array
    {
        $lines = $this->optionsOf($program);
        $carried = [];
        foreach ($progresses as $progress) {
            $option = $progress->dossier()->getOption();
            if (null !== $option && !\in_array($option, $lines, true) && !\in_array($option, $carried, true)) {
                $carried[] = $option;
            }
        }
        usort($carried, static fn (Option $a, Option $b): int => $a->getShortName() <=> $b->getShortName());

        return [...$lines, ...$carried];
    }

    /** The amount pre-filled for the deposit or the NPEC: the option's, else the formation's. */
    public function amountDefault(CrmPiece $piece, CrmDossier $dossier): ?int
    {
        $kind = match ($piece->getCode()) {
            CrmPiece::DEPOSIT => 'deposit',
            CrmPiece::NPEC => 'npec',
            default => null,
        };
        $settings = $this->settingsOf($dossier->getProgram());
        if (null === $kind || null === $settings) {
            return null;
        }

        $option = $dossier->getOption();
        $own = null === $option ? null : $settings->optionAmount((int) $option->getId(), $kind);

        return $own ?? ('deposit' === $kind ? $settings->getDepositAmount() : $settings->getNpecAmount());
    }

    /**
     * What the dossier forms need to know of each formation as it is chosen: whether it asks for an
     * option, which ones, and the modality a new dossier starts in.
     *
     * @param list<Program> $programs
     *
     * @return array<string, array{asksOption: bool, options: list<array{id: int, label: string, full: string}>, modality: string, modalityLabel: string}>
     */
    public function formData(array $programs): array
    {
        $data = [];
        foreach ($programs as $program) {
            $modality = $this->defaultModality($program);
            $data[(string) $program->getId()] = [
                'asksOption' => $this->asksOption($program),
                'options' => array_map(static fn (Option $o): array => ['id' => (int) $o->getId(), 'label' => $o->getShortName(), 'full' => $o->getName()], $this->optionsOf($program)),
                'modality' => $modality->value,
                'modalityLabel' => $modality->label(),
            ];
        }

        return $data;
    }

    public function progress(CrmDossier $dossier): CrmDossierProgress
    {
        return CrmDossierProgress::of($dossier, $this->settingsOf($dossier->getProgram()), $this->catalogue());
    }

    /** « BTS », « DCG », « Bachelor » - the group a formation is listed under. */
    public static function sectionName(Program $program): string
    {
        return $program->getCohort()?->getTrack()?->getSection()?->getName() ?? '';
    }

    /** « Bachelor Informatique CDA », the formation as a dossier reads it. */
    public static function dossierProgramName(CrmDossier $dossier): string
    {
        return $dossier->getProgram()->getShortName().(null !== $dossier->getOption() ? ' '.$dossier->getOption()->getShortName() : '');
    }
}
