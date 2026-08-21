<?php

declare(strict_types=1);

namespace App\Controller\Survey;

use App\Enum\MessageAudienceType;
use App\Form\Survey\SurveyLaunchType;
use App\Repository\ProgramRepository;
use App\Repository\SurveyTemplateRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SurveyVoter;
use App\Service\Survey\SurveyLauncher;
use App\Service\Survey\SurveyTargetResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Lancer une campagne » - the screen where a model becomes a wave
 * (design/validated/surveys.md §8, lot 3).
 *
 * Four things happen in one transaction when this form is submitted, and App\Service\Survey\
 * SurveyLauncher owns all four: the questions are frozen, the target is resolved and frozen, one
 * travail à faire is created per targeted class, and the anonymity becomes irreversible.
 *
 * The « travail à faire » fieldset is absent - not disabled, absent - when no class is aimed at:
 * Assignment.program_id is NOT NULL, so a campaign aiming at « tous les enseignants » has no
 * travail à faire and must not try to have one (§7.9).
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class LaunchController extends AbstractController
{
    use SurveyTabTrait;

    #[Route(path: '/surveys/templates/{id}/launch', name: 'app_survey_launch', methods: ['GET', 'POST'])]
    public function launch(
        int $id,
        Request $request,
        SurveyTemplateRepository $repository,
        ProgramRepository $programRepository,
        StructureAccessChecker $accessChecker,
        SurveyLauncher $launcher,
        SurveyTargetResolver $targetResolver,
    ): Response {
        $template = $repository->find($id);

        if (null === $template) {
            throw $this->createNotFoundException();
        }

        // The owner alone launches, with no staff bypass: launching a survey under a colleague's
        // name is not a gesture the application offers.
        $this->denyAccessUnlessGranted(SurveyVoter::LAUNCH, $template);

        if ([] === $template->answerableQuestions()) {
            $this->addFlash('error', 'surveyLaunchNoQuestionErrorMessage');

            return $this->redirectToRoute('app_survey_template_edit', ['id' => $template->getId()]);
        }

        $author = $this->currentUser();
        $campaign = $launcher->prepare($template, $author);
        $campaign->setAudienceTypes([MessageAudienceType::Program]);

        $programs = $accessChecker->isStaff()
            ? $programRepository->findActiveForNav($author)
            : $programRepository->findAllForTeacher($author);

        $form = $this->createForm(SurveyLaunchType::class, $campaign, ['programs' => $programs]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $createAssignments = $this->wantsAssignments($campaign, $request);
            $mandatory = 'yes' === $request->request->get('assignmentMandatory');

            $launcher->launch($campaign, $template, $author, $createAssignments, $mandatory);

            $this->addFlash('success', 'surveyLaunchedFlashMessage');

            return $this->redirectToRoute('app_survey_campaign', ['id' => $campaign->getId()]);
        }

        return $this->render('survey/launch.html.twig', [
            'surveyTemplate' => $template,
            'campaign' => $campaign,
            'form' => $form->createView(),
            'answerableCount' => \count($template->answerableQuestions()),
            'programs' => $programs,
        ]);
    }

    /**
     * How many people the audience currently reaches - the number the button carries
     * (« Lancer — 47 personnes »), read live while the form is being filled in.
     */
    #[Route(path: '/surveys/templates/{id}/launch/preview', name: 'app_survey_launch_preview', methods: ['POST'])]
    public function preview(
        int $id,
        Request $request,
        SurveyTemplateRepository $repository,
        ProgramRepository $programRepository,
        StructureAccessChecker $accessChecker,
        SurveyLauncher $launcher,
        SurveyTargetResolver $targetResolver,
        TranslatorInterface $translator,
    ): Response {
        $template = $repository->find($id);

        if (null === $template) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SurveyVoter::LAUNCH, $template);

        $author = $this->currentUser();
        $campaign = $launcher->prepare($template, $author);

        $programs = $accessChecker->isStaff()
            ? $programRepository->findActiveForNav($author)
            : $programRepository->findAllForTeacher($author);

        // Bound but never persisted: this is a reading of the audience, and the campaign object it
        // needs is thrown away with the request.
        $form = $this->createForm(SurveyLaunchType::class, $campaign, ['programs' => $programs]);
        $form->handleRequest($request);

        $count = \count($targetResolver->preview($campaign));

        // The two wordings are pluralised *here* rather than client-side: they are ICU plural
        // strings, and a placeholder standing in for the number cannot pick a branch - handing the
        // raw « {0}…|{1}…|]1,Inf[… » to the button is exactly what that mistake looks like.
        return $this->json([
            'count' => $count,
            'buttonLabel' => $translator->trans('surveyLaunchSubmitLabel', ['%count%' => $count]),
            'summary' => $translator->trans('surveyTargetPeopleCountText', ['%count%' => $count]),
        ]);
    }

    /**
     * Whether a travail à faire is wanted - and it can only be, when students of a class are aimed
     * at. The checkbox is simply absent otherwise, so its value is not read either.
     */
    private function wantsAssignments(\App\Entity\SurveyCampaign $campaign, Request $request): bool
    {
        if (!$campaign->hasAudienceType(MessageAudienceType::Program) || !$campaign->isIncludeStudents()) {
            return false;
        }

        if ($campaign->getPrograms()->isEmpty()) {
            return false;
        }

        // Ticked by default, which is why its absence from the payload is read as "no" only when the
        // field was on screen at all - and it was, since we just checked a class is aimed at.
        return 'yes' === $request->request->get('createAssignments', 'yes');
    }
}
