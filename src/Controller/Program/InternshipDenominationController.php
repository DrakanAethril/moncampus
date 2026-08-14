<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\InternshipOptionLegalName;
use App\Entity\InternshipProgramInfo;
use App\Entity\Program;
use App\Form\InternshipLegalNameType;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
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
 * Formation > Livret de l'alternant, onglet « Dénomination » : intitulés légaux de la formation et de ses options.
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipDenominationController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/programs/{id}/internship/denomination', name: 'app_program_internship_denomination')]
    public function denominationTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, InternshipOptionLegalNameRepository $legalNameRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipLegalNameType::class, $info);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $this->syncOptionLegalNames($program, $request, $entityManager, $legalNameRepository);
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
        ]);
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
