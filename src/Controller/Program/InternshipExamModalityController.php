<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Service\PostValue;
use App\Entity\InternshipOptionExamModality;
use App\Entity\InternshipProgramInfo;
use App\Entity\Program;
use App\Form\InternshipExamModalityType;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\ProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Livret de l'alternant, onglet « Modalités d'examen », par option.
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipExamModalityController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/programs/{id}/internship/exam-modalities', name: 'app_program_internship_exam_modalities')]
    public function examModalitiesTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, InternshipOptionExamModalityRepository $examModalityRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipExamModalityType::class, $info);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $info->setExamModalityText($this->sanitizeOrNull($sanitizer, $info->getExamModalityText()));
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $this->syncOptionExamModalities($program, $request, $entityManager, $examModalityRepository, $sanitizer);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_exam_modalities', ['id' => $program->getId()]);
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'exam_modalities',
            'form' => $form,
            'info' => $info,
            'examModalitiesByOptionId' => $examModalityRepository->findMapForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/internship/exam-modalities/{optionId}/reset', name: 'app_program_internship_exam_modalities_reset', methods: ['POST'])]
    public function resetOptionExamModality(int $id, int $optionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipOptionExamModalityRepository $examModalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        // Submitted as "reset_token", not "_token" - this button submits the tab's own Symfony
        // Form (via formaction, see the template) whose built-in "_token" field is checked
        // against a Symfony-internal id, not this one.
        if (!$this->isCsrfTokenValid('program_internship_exam_modalities', $request->request->get('reset_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        foreach ($program->getOptions() as $option) {
            if ($option->getId() === $optionId) {
                $override = $examModalityRepository->findOneForProgramAndOption($program, $option);
                if (null !== $override) {
                    $entityManager->remove($override);
                    $entityManager->flush();
                }
                break;
            }
        }

        return $this->redirectToRoute('app_program_internship_exam_modalities', ['id' => $program->getId()]);
    }

    // Presence of a row IS the per-Option override (see InternshipOptionExamModality's docblock).
    // Merged into examModalitiesTab()'s single form submission (8b pattern: one .cm-actionbar per
    // page) rather than a second form with its own submit button - same shape as
    // syncOptionLegalNames() above.
    private function syncOptionExamModalities(Program $program, Request $request, EntityManagerInterface $entityManager, InternshipOptionExamModalityRepository $examModalityRepository, HtmlSanitizerInterface $sanitizer): void
    {
        $submittedTexts = PostValue::all($request, 'examModalities');

        foreach ($program->getOptions() as $option) {
            $raw = trim($sanitizer->sanitize($this->submittedText($submittedTexts, $option->getId())));
            $existingOverride = $examModalityRepository->findOneForProgramAndOption($program, $option);

            if ('' === $raw) {
                if (null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setExamModalityText($raw);
            } else {
                $entityManager->persist(new InternshipOptionExamModality($program, $option, $raw));
            }
        }
    }

    // HugeRTE-authored HTML rendered back on the booklet - sanitized the same way as
    // Announcement::$body/Message::$body (design/validated/internal-messaging.md).
    private function sanitizeOrNull(HtmlSanitizerInterface $sanitizer, ?string $html): ?string
    {
        return null !== $html && '' !== $html ? $sanitizer->sanitize($html) : $html;
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
