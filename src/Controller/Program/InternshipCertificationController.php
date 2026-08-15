<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Form\ProgramCertificationType;
use App\Repository\ProgramCertificationRepository;
use App\Repository\ProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Livret de l'alternant, onglet « Certification », par option.
 *
 * One ProgramCertification per Option, because a single program can prepare two titles with
 * different RNCP codes - which is the whole reason the entity is keyed by option. A program with no
 * option still prepares something, and gets the one row whose option is null.
 *
 * Same "presence of a row IS the setting" semantics as the neighbouring Dénomination and Modalités
 * d'examen tabs: clearing the intitulé deletes the row rather than storing a blank one.
 *
 * The tab builds one sub-form per option rather than using raw repeated field names (the idiom the
 * exam-modality tab uses): a certification is five typed fields, not one text, and going through a
 * real form type keeps their validation and their enum.
 *
 * @phpstan-type CertificationRow array{fieldName: string, option: Option|null, certification: ProgramCertification}
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipCertificationController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/programs/{id}/internship/certification', name: 'app_program_internship_certification')]
    public function certificationTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ProgramCertificationRepository $certifications): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $rows = $this->buildRows($program, $certifications);

        $builder = $this->createFormBuilder();
        foreach ($rows as $row) {
            $builder->add($row['fieldName'], ProgramCertificationType::class, ['data' => $row['certification']]);
        }
        $builder->add('submit', SubmitType::class, ['label' => 'submitEditAction']);

        $form = $builder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($rows as $row) {
                $this->persistOrDrop($row['certification'], $entityManager);
            }
            $entityManager->flush();

            $this->addFlash('success', 'programCertificationUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_certification', ['id' => $program->getId()]);
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'certification',
            'form' => $form,
            'certificationRows' => $rows,
        ]);
    }

    /**
     * One row per certifying option, or a single null-option row when the program has none.
     *
     * @return list<array{fieldName: string, option: Option|null, certification: ProgramCertification}>
     */
    private function buildRows(Program $program, ProgramCertificationRepository $certifications): array
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

    private function persistOrDrop(ProgramCertification $certification, EntityManagerInterface $entityManager): void
    {
        $label = trim($certification->getLabel());

        if ('' === $label) {
            // Blank means "this option prepares nothing" - drop the row rather than keeping an
            // empty one, so the absence stays readable as an absence.
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
}
