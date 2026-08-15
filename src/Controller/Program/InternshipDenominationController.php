<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\InternshipOptionLegalName;
use App\Entity\InternshipProgramInfo;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Form\InternshipLegalNameType;
use App\Form\ProgramCertificationType;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\ProgramCertificationRepository;
use App\Repository\ProgramRepository;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Livret de l'alternant, onglet « Dénomination » : intitulés légaux de la formation et
 * de ses options, puis la certification que chaque option prépare.
 *
 * The two belong on one screen because they answer the same question about the same option - what
 * this formation is called, and what it awards - and the certification is short enough that a tab
 * of its own only added a click. It rides the tab's single form (one .cm-actionbar per page): the
 * certification sub-forms are added to it unmapped, since the form's own model is
 * InternshipProgramInfo and a ProgramCertification is a different row.
 *
 * Split out of the former ProgramInternshipController - the legal-name routes, their names and
 * their bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipDenominationController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/programs/{id}/internship/denomination', name: 'app_program_internship_denomination')]
    public function denominationTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, InternshipOptionLegalNameRepository $legalNameRepository, ProgramCertificationRepository $certificationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipLegalNameType::class, $info);
        $certificationRows = $this->buildCertificationRows($program, $certificationRepository);
        foreach ($certificationRows as $row) {
            // Unmapped: this form's model is InternshipProgramInfo, and each of these children owns
            // a ProgramCertification of its own. The child still maps submitted values into that
            // object - only the parent's read/write of it is switched off.
            $form->add($row['fieldName'], ProgramCertificationType::class, [
                'data' => $row['certification'],
                'mapped' => false,
            ]);
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $this->syncOptionLegalNames($program, $request, $entityManager, $legalNameRepository);
            foreach ($certificationRows as $row) {
                $this->persistOrDropCertification($row['certification'], $entityManager);
            }
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_denomination', ['id' => $program->getId()]);
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'denomination',
            'form' => $form,
            'info' => $info,
            'legalNamesByOptionId' => $legalNameRepository->findMapForProgram($program),
            'certificationRows' => $certificationRows,
        ]);
    }

    /**
     * One row per certifying option, or a single null-option row when the program has none - see
     * ProgramCertification's docblock.
     *
     * @return list<array{fieldName: string, option: Option|null, certification: ProgramCertification}>
     */
    private function buildCertificationRows(Program $program, ProgramCertificationRepository $certifications): array
    {
        /** @var list<Option|null> $options */
        $options = array_values($program->getOptions()->toArray());
        if ([] === $options) {
            $options = [null];
        }

        $rows = [];
        foreach ($options as $index => $option) {
            $existing = $certifications->findOneForProgramAndOption($program, $option);

            $rows[] = [
                // Keyed by position, not by option id: the id is null for the program-wide row, and
                // the field name only has to be stable within one rendering of this form.
                'fieldName' => 'certification_'.$index,
                'option' => $option,
                'certification' => $existing ?? new ProgramCertification($program, $option, ''),
            ];
        }

        return $rows;
    }

    /**
     * Presence of a row IS the setting, same as the legal names above: a blank intitulé deletes the
     * row rather than storing an empty one, so an absence stays readable as an absence.
     */
    private function persistOrDropCertification(ProgramCertification $certification, EntityManagerInterface $entityManager): void
    {
        $label = trim($certification->getLabel());

        if ('' === $label) {
            if (null !== $certification->getId()) {
                $entityManager->remove($certification);
            }

            return;
        }

        $certification->setLabel($label);

        if (null === $certification->getId()) {
            $entityManager->persist($certification);
        }
    }

    // Presence of a row IS the per-Option override (see InternshipOptionLegalName's docblock).
    // Merged into denominationTab()'s single form submission (8a pattern: one .cm-actionbar per
    // page) rather than a second form with its own submit button - same shape as
    // syncOptionExamModalities() below.
    private function syncOptionLegalNames(Program $program, Request $request, EntityManagerInterface $entityManager, InternshipOptionLegalNameRepository $legalNameRepository): void
    {
        $submittedNames = PostValue::all($request, 'legalNames');

        foreach ($program->getOptions() as $option) {
            $raw = trim($this->submittedText($submittedNames, $option->getId()));
            $existingOverride = $legalNameRepository->findOneForProgramAndOption($program, $option);

            if ('' === $raw) {
                if (null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setLegalName($raw);
            } else {
                $entityManager->persist(new InternshipOptionLegalName($program, $option, $raw));
            }
        }
    }

    /**
     * One cell of a per-row override form, keyed by the row's own id. The values come straight off
     * a repeated field, so nothing guarantees a submitted key holds a string - a blank reads the
     * same as a row the form never sent, which both mean "no override".
     *
     * @param array<array-key, mixed> $submitted
     */
    private function submittedText(array $submitted, string|int $key): string
    {
        $value = $submitted[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
