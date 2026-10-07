<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Entity\ProgramEcfSettings;
use App\Entity\User;
use App\Form\EcfSettingsType;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\ProgramCertificationRepository;
use App\Repository\ProgramEcfSettingsRepository;
use App\Service\ProgramCertificationEditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The ECF part of UFA > Formations > « Dénomination » (design/validated/ecf-booklet.md §14): the
 * switch, the organisme and the lieu, which hold for the whole formation. The titre and its dates
 * are the certification's, a block lower on the same screen (ProgramCertificationEditor), and that
 * is why the switch is judged here on the rows of the form rather than on the database: the very
 * submit that types a code titre may switch the booklet on.
 *
 * @phpstan-import-type CertificationRow from ProgramCertificationEditor
 */
class EcfSettingsEditor
{
    public const string FIELD = 'ecf';

    public function __construct(
        private readonly ProgramEcfSettingsRepository $settingsRepository,
        private readonly InternshipFormationCenterRepository $formationCenterRepository,
        private readonly ProgramCertificationRepository $certificationRepository,
        private readonly EcfActivityTypes $activityTypes,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The formation's settings, or what a first visit proposes - the training centre as organisme
     * and lieu - which nothing has saved yet.
     */
    public function settings(Program $program): ProgramEcfSettings
    {
        $settings = $this->settingsRepository->findOneByProgram($program);
        if (null !== $settings) {
            return $settings;
        }

        $settings = new ProgramEcfSettings($program);
        $center = $this->formationCenterRepository->findSingleton();
        if (null !== $center) {
            $settings->setOrganisation($center->getCfaName() ?? $center->getCompanyName());
            $place = trim(($center->getCompanyName() ?? '').' '.($center->getCity() ?? ''));
            $settings->setPlace('' !== $place ? $place : null);
        }

        return $settings;
    }

    /**
     * Hangs the settings off the tab's own form, like the certifications: unmapped, because that
     * form's model is InternshipProgramInfo. The administration reads them; only an administrator
     * writes them, so the child is disabled for anyone else and ignores what they submit.
     */
    public function addToForm(FormInterface $form, ProgramEcfSettings $settings, bool $canEdit): void
    {
        $form->add(self::FIELD, EcfSettingsType::class, [
            'data' => $settings,
            'mapped' => false,
            'disabled' => !$canEdit,
        ]);
    }

    /**
     * The certifying options of the formation - or the whole formation when it has none - whose
     * certification lacks a code titre or a millésime: their students get no booklet. Read from the
     * rows as the form holds them; an option whose own certification is blank answers with the
     * formation-wide one, as ProgramCertificationRepository::findForOption() does.
     *
     * @param list<CertificationRow> $rows
     *
     * @return list<Option|null>
     */
    public function optionsWithoutTitle(Program $program, array $rows): array
    {
        $without = [];
        foreach ($rows as $row) {
            $certification = $this->effective($program, $row['option'], $row['certification']);
            if (!EcfTitle::of('', $certification, $row['option'], $program)->isComplete()) {
                $without[] = $row['option'];
            }
        }

        return $without;
    }

    /**
     * Why the booklet cannot be switched on, as translation keys - none when the switch is off.
     *
     * @param list<CertificationRow> $rows
     *
     * @return list<string>
     */
    public function refusals(ProgramEcfSettings $settings, array $rows): array
    {
        $program = $settings->getProgram();
        if (!$settings->isEnabled() || null === $program) {
            return [];
        }

        $refusals = [];
        if (!$this->activityTypes->hasActivityTypes($program)) {
            $refusals[] = 'ecfSettingsRefusalNoGroupMessage';
        }
        if (\count($this->optionsWithoutTitle($program, $rows)) >= \count($rows)) {
            $refusals[] = 'ecfSettingsRefusalNoTitleMessage';
        }

        return $refusals;
    }

    public function hasActivityTypes(Program $program): bool
    {
        return $this->activityTypes->hasActivityTypes($program);
    }

    /**
     * Stamps and queues the settings; a row that says what it already said is left alone, so that
     * saving the denomination does not sign the ECF settings. Does not flush - the caller owns the
     * transaction.
     */
    public function save(ProgramEcfSettings $settings, User $user): void
    {
        if (null === $settings->getId()) {
            $settings->setCreatedBy($user);
            $this->entityManager->persist($settings);

            return;
        }

        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($settings);
        $current = ['enabled' => $settings->isEnabled(), 'organisation' => $settings->getOrganisation(), 'place' => $settings->getPlace()];
        foreach ($current as $field => $value) {
            if (($original[$field] ?? null) !== $value) {
                $settings->setLastUpdatedBy($user);
                $settings->setLastUpdatedDate(new \DateTimeImmutable());

                return;
            }
        }
    }

    private function effective(Program $program, ?Option $option, ProgramCertification $certification): ?ProgramCertification
    {
        if ('' !== trim($certification->getLabel())) {
            return $certification;
        }

        return null === $option ? null : $this->certificationRepository->findOneForProgramAndOption($program, null);
    }
}
