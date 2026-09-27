<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\FileLibraryNode;
use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceFolder;
use App\Entity\SequenceTemplate;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The MonCampus screen each object a tool names can be checked on - absolute, since the teacher
 * clicks them from claude.ai. Every result that names something carries its link: the connector
 * creates, the teacher verifies, and that second half is what these are for.
 */
final readonly class McpLinks
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function quiz(QuizTemplate $quiz): string
    {
        return $this->url('app_library_quiz_questions', ['id' => $quiz->getId()]);
    }

    public function quizFolder(QuizFolder $folder): string
    {
        return $this->url('app_library_quiz_folder', ['folderId' => $folder->getId()]);
    }

    public function sequence(SequenceTemplate $sequence): string
    {
        return $this->url('app_library_sequences_show', ['id' => $sequence->getId()]);
    }

    public function seance(SeanceTemplate $seance): string
    {
        return $this->url('app_library_seances_show', ['sequenceId' => $seance->getSequenceTemplate()?->getId(), 'id' => $seance->getId()]);
    }

    public function sequenceFolder(SequenceFolder $folder): string
    {
        return $this->url('app_library_sequences_folder', ['folderId' => $folder->getId()]);
    }

    public function fileFolder(?FileLibraryNode $folder): string
    {
        return null === $folder
            ? $this->url('app_file_library')
            : $this->url('app_file_library_folder', ['nodeId' => $folder->getId()]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function url(string $route, array $parameters = []): string
    {
        return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
