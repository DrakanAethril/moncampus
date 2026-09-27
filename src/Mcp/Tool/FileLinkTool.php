<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\LibraryResource;
use App\Entity\SeancePhaseTemplate;
use App\Enum\Feature;
use App\Enum\LibraryResourceSourceType;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\SeancePhaseTemplateRepository;
use App\Security\Voter\FileLibraryVoter;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hangs a file of the library on a séquence, a séance or a phase - « Ajouter une ressource » of
 * those screens, with a library file picked. **A reference, not a copy**: the resource takes the
 * node's own storage key (App\Service\UploadIntake::store()), so correcting the file in the library
 * corrects it on the course too.
 */
final readonly class FileLinkTool implements McpTool
{
    public function __construct(
        private McpLibraryAccess $library,
        private SeancePhaseTemplateRepository $phases,
        private UploadIntake $intake,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'file_link';
    }

    public function title(): string
    {
        return 'Rattacher un fichier à une séquence';
    }

    public function description(): string
    {
        return 'Ajoute un fichier de la bibliothèque comme ressource d\'une séquence (sequenceId), d\'une séance (seanceId) ou d\'une phase de séance (phaseId). Le fichier n\'est pas copié : c\'est un lien vers la bibliothèque. `label` est le nom affiché sur la séquence (le nom du fichier par défaut).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileId' => ['type' => 'integer'],
                'sequenceId' => ['type' => 'integer'],
                'seanceId' => ['type' => 'integer'],
                'phaseId' => ['type' => 'integer'],
                'label' => ['type' => 'string', 'maxLength' => 255],
            ],
            'required' => ['fileId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::FileLibrary, Feature::SequenceLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $file = $this->library->file($call->requiredId('fileId'), FileLibraryVoter::LINK);
        $label = mb_substr(trim($call->arguments->string('label')), 0, 255);
        $resource = new LibraryResource($call->user, '' === $label ? $file->getName() : $label);
        $resource->setType(LibraryResourceSourceType::Upload);
        $resource->setStorageKey($this->intake->store($file, 'library-resources/', $file->getName()));
        $resource->setLibraryNode(UploadIntake::libraryNodeOf($file));

        $phaseId = $call->optionalId('phaseId');
        $seanceId = $call->optionalId('seanceId');
        $sequenceId = $call->optionalId('sequenceId');

        if (null !== $phaseId) {
            $phase = $this->phase($phaseId);
            $resource->setSeancePhaseTemplate($phase);
            $target = \sprintf('la phase « %s »', $phase->getNom());
            $url = null !== $phase->getSeanceTemplate() ? $this->links->seance($phase->getSeanceTemplate()) : null;
        } elseif (null !== $seanceId) {
            $seance = $this->library->seance($seanceId);
            $resource->setSeanceTemplate($seance);
            $target = \sprintf('la séance « %s »', $seance->getTitre());
            $url = $this->links->seance($seance);
        } elseif (null !== $sequenceId) {
            $sequence = $this->library->sequence($sequenceId);
            $resource->setSequenceTemplate($sequence);
            $target = \sprintf('la séquence « %s »', $sequence->getTitre());
            $url = $this->links->sequence($sequence);
        } else {
            throw new McpToolException('Indiquez la séquence (sequenceId), la séance (seanceId) ou la phase (phaseId).');
        }

        $this->entityManager->persist($resource);
        $this->entityManager->flush();

        return McpToolResult::data(
            \sprintf('« %s » rattaché à %s%s', $resource->getLabel(), $target, null === $url ? '.' : ' : '.$url),
            ['resourceId' => $resource->getId(), 'fileId' => $file->getId(), 'url' => $url],
        );
    }

    private function phase(int $id): SeancePhaseTemplate
    {
        $phase = $this->phases->find($id);
        $sequence = $phase?->getSeanceTemplate()?->getSequenceTemplate();

        if (!$phase instanceof SeancePhaseTemplate || null === $sequence) {
            throw new McpToolException(\sprintf('Phase %d introuvable dans votre bibliothèque.', $id));
        }

        // Checked on the séquence, which is where the voter's rule is written.
        $this->library->sequence((int) $sequence->getId());

        return $phase;
    }
}
