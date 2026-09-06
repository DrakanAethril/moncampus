<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\WordCloud;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Security\StructureAccessChecker;
use App\Service\GotenbergClient;
use App\Service\GotenbergUnavailableException;
use App\Service\QueryValue;
use App\Service\WordCloud\WordCloudAudience;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudCsvExporter;
use App\Service\WordCloud\WordCloudFollowUp;
use App\Service\WordCloud\WordCloudSchedule;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * « Suivi par mot » and « Suivi par étudiant » - two routes, one table.
 *
 * They are two screens rather than one with a filter because they answer two questions a teacher
 * asks at different moments: which words came out, and who said nothing. The segmented control at
 * the top is what links them, and the search box carries across both.
 *
 * Reading the follow-up asks for PILOT, like the rest of the teacher side: this is the nominative
 * record of who wrote what, and it belongs to whoever runs the class.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::WordCloud)]
class FollowUpController extends AbstractController
{
    use WordCloudControllerTrait;

    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly WordCloudRepository $clouds,
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly StructureAccessChecker $accessChecker,
        private readonly WordCloudBoard $board,
        private readonly WordCloudFollowUp $followUp,
        private readonly WordCloudAudience $audience,
        private readonly WordCloudSchedule $schedule,
    ) {
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/follow-up', name: 'app_program_word_cloud_follow_up', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['GET'])]
    public function byWord(int $id, int $cloudId, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);

        // Read through QueryValue: a search box that has just been emptied submits `?q=`, and
        // InputBag would answer a 400 to it.
        $query = QueryValue::trimmed($request, 'q');
        $rows = $this->submissions->findForCloud($cloud);

        return $this->render('word_cloud/follow_up_words.html.twig', $this->shared($program, $cloud, $rows) + [
            'query' => $query,
            'wordRows' => $this->followUp->byWord($cloud, $rows, $query),
        ]);
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/follow-up/students', name: 'app_program_word_cloud_follow_up_students', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['GET'])]
    public function byStudent(int $id, int $cloudId, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);

        $query = QueryValue::trimmed($request, 'q');
        $rows = $this->submissions->findForCloud($cloud);
        $roster = $this->audience->students($cloud);

        return $this->render('word_cloud/follow_up_students.html.twig', $this->shared($program, $cloud, $rows) + [
            'query' => $query,
            'studentRows' => $this->followUp->byStudent($cloud, $roster, $rows, $query),
            'silentCount' => \count($this->followUp->silentStudents($roster, $rows)),
        ]);
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/export.csv', name: 'app_program_word_cloud_export_csv', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['GET'])]
    public function exportCsv(int $id, int $cloudId, WordCloudCsvExporter $exporter): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);

        $csv = $exporter->export(
            $cloud,
            $this->audience->students($cloud),
            $this->submissions->findForCloud($cloud),
            $this->followUp,
        );

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $exporter->filename($cloud)),
        );

        return $response;
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/export.pdf', name: 'app_program_word_cloud_export_pdf', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['GET'])]
    public function exportPdf(int $id, int $cloudId, GotenbergClient $gotenberg): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);

        $rows = $this->submissions->findForCloud($cloud);
        $roster = $this->audience->students($cloud);

        $html = $this->renderView('word_cloud/export_pdf.html.twig', [
            'program' => $program,
            'cloud' => $cloud,
            'board' => $this->board->buildFrom($cloud, $rows),
            'wordRows' => $this->followUp->byWord($cloud, $rows),
            'studentRows' => $this->followUp->byStudent($cloud, $roster, $rows),
            'rosterSize' => \count($roster),
            'date' => new \DateTimeImmutable(),
        ]);

        try {
            $pdf = $gotenberg->convertHtmlToPdf($html);
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'wordCloudPdfFailedFlashMessage');

            return $this->redirectToRoute('app_program_word_cloud_follow_up', ['id' => $program->getId(), 'cloudId' => $cloud->getId()]);
        }

        $filename = (new AsciiSlugger())->slug((string) $cloud->getName())->lower()->toString();

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('%s.pdf', $filename)),
        ]);
    }

    /**
     * The four indicator cards and the title line, identical on both tabs - built once so the two
     * screens cannot announce different totals for the same cloud.
     *
     * @param list<\App\Entity\WordCloudSubmission> $rows
     *
     * @return array<string, mixed>
     */
    private function shared(Program $program, WordCloud $cloud, array $rows): array
    {
        return [
            'program' => $program,
            'cloud' => $cloud,
            'board' => $this->board->buildFrom($cloud, $rows),
            'status' => $this->schedule->status($cloud->window(), new \DateTimeImmutable()),
        ];
    }
}
