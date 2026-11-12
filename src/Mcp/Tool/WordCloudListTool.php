<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\WordCloud;
use App\Enum\Feature;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Mcp\McpWordClouds;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Service\WordCloud\WordCloudPrograms;

/**
 * Outils › Nuage de mots, as a list (design/validated/word-cloud-public.md): the clouds of the
 * classes the teacher runs and their own clouds of no class, newest first - the screen's list and
 * its class filter. How many took part is a count; who is never read here.
 */
final readonly class WordCloudListTool implements McpTool
{
    public function __construct(
        private WordCloudRepository $clouds,
        private WordCloudSubmissionRepository $submissions,
        private WordCloudPrograms $programs,
        private McpWordClouds $wordClouds,
    ) {
    }

    public function name(): string
    {
        return 'word_cloud_list';
    }

    public function title(): string
    {
        return 'Lister mes nuages de mots';
    }

    public function description(): string
    {
        return 'Liste les nuages de mots que l\'enseignant pilote, du plus récent au plus ancien : ceux des classes où il enseigne et les siens sans classe (nuages publics). Pour chacun : identifiant, nom, question, classe, état (scheduled, open, closed), fenêtre d\'ouverture, ouvert à tous ou non (avec l\'adresse publique et le code), nombre de participants et lien de pilotage. Filtre facultatif par classe (programId) ou sur les nuages sans classe (classless). Les résultats d\'un nuage se lisent avec word_cloud_get.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'programId' => ['type' => 'integer', 'description' => 'Ne lister que les nuages de cette classe.'],
                'classless' => ['type' => 'boolean', 'description' => 'Ne lister que les nuages sans classe de l\'enseignant.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::WordCloud];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $programs = $this->programs->piloted($call->user);
        $programId = $call->optionalId('programId');
        $only = null;
        if (null !== $programId) {
            foreach ($programs as $program) {
                if ($program->getId() === $programId) {
                    $only = $program;
                }
            }
            if (null === $only) {
                throw new McpToolException(\sprintf('Classe %d introuvable parmi celles où vous pouvez piloter un nuage de mots.', $programId));
            }
        }

        $clouds = $this->clouds->findForPilot($call->user, $programs, $only, $call->arguments->bool('classless'));
        $participants = $this->submissions->countParticipantsByCloud($clouds);

        return McpToolResult::data(
            \sprintf('%d nuage(s) de mots.', \count($clouds)),
            ['wordClouds' => array_map(
                fn (WordCloud $cloud): array => $this->wordClouds->describe($cloud) + ['participantCount' => $participants[(int) $cloud->getId()] ?? 0],
                $clouds,
            )],
        );
    }
}
