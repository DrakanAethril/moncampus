<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\ContractType;
use App\Entity\Program;
use App\Entity\ProgramContractModality;
use App\Enum\ContractTypeCode;
use App\Repository\ContractTypeRepository;
use App\Repository\ProgramContractModalityRepository;
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
 * Formation > Livret de l'alternant, onglet « Modalités de contrat » : le texte par type de contrat, hérité du Centre de formation tant que la formation ne le redéfinit pas.
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipContractModalityController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/programs/{id}/internship/contract-modalities', name: 'app_program_internship_contract_modalities')]
    public function contractModalitiesTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        if ($request->isMethod('POST')) {
            $this->assertValidFormToken('program_internship_contract_modalities', $request);
            $this->syncContractModalities($program, $request, $entityManager, $contractTypeRepository, $modalityRepository, $sanitizer);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_contract_modalities', ['id' => $program->getId()]);
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'contract_modalities',
            'blocks' => $this->contractModalityBlocks($program, $contractTypeRepository, $modalityRepository),
        ]);
    }

    #[Route(path: '/programs/{id}/internship/contract-modalities/{code}/reset', name: 'app_program_internship_contract_modalities_reset', methods: ['POST'])]
    public function resetContractModalityOverride(int $id, string $code, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $contractType = $contractTypeRepository->findOneByCode(ContractTypeCode::from($code)) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('program_internship_contract_modalities', $request);

        $override = $modalityRepository->findOneForProgramAndContractType($program, $contractType);
        if (null !== $override) {
            $entityManager->remove($override);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_program_internship_contract_modalities', ['id' => $program->getId()]);
    }

    /** @return list<array{contractType: ContractType, override: ?ProgramContractModality}> */
    private function contractModalityBlocks(Program $program, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository): array
    {
        return array_map(
            function (ContractTypeCode $code) use ($program, $contractTypeRepository, $modalityRepository): array {
                $contractType = $contractTypeRepository->findOneByCode($code) ?? new ContractType($code);

                return [
                    'contractType' => $contractType,
                    'override' => null !== $contractType->getId() ? $modalityRepository->findOneForProgramAndContractType($program, $contractType) : null,
                ];
            },
            ContractTypeCode::cases(),
        );
    }

    // Presence of a non-blank submitted text IS the override (see ProgramContractModality's
    // docblock), same convention as syncOptionLegalNames()/syncOptionExamModalities() above - a
    // ContractType with no default set yet is created here (stamped with the current user) so the
    // override row has a real id to point at.
    private function syncContractModalities(Program $program, Request $request, EntityManagerInterface $entityManager, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository, HtmlSanitizerInterface $sanitizer): void
    {
        $submitted = $request->request->all('modalities');

        foreach (ContractTypeCode::cases() as $code) {
            $contractType = $contractTypeRepository->findOneByCode($code);
            if (null === $contractType) {
                $contractType = (new ContractType($code))->setCreatedBy($this->currentUser());
                $entityManager->persist($contractType);
            }

            $raw = trim($sanitizer->sanitize((string) ($submitted[$code->value] ?? '')));
            $existingOverride = $modalityRepository->findOneForProgramAndContractType($program, $contractType);

            if ('' === $raw) {
                if (null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setModalitiesHtml($raw);
            } else {
                $entityManager->persist((new ProgramContractModality($program, $contractType, $raw))->setCreatedBy($this->currentUser()));
            }
        }
    }

    // For plain <form method="post"> submissions (contract-modalities save/reset) - the token
    // travels as a body field (name="_token"), never as a header. Using assertValidToken() above
    // for these silently 403'd (redirected to /login) every submit, since no header was ever
    // sent - caught during a design-fidelity re-audit, not something a screenshot comparison
    // would catch on its own.
    private function assertValidFormToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
