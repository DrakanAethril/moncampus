<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Entity\ConsoleSession;
use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Repository\FileLibraryNodeRepository;
use App\Service\FileLibraryNodeManager;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where a file picked up off a machine lands.
 *
 * The design says « dans le dossier de la formation ». The file library, as built, is **owned by a
 * person** - there is no shared formation library to write into - so what that intent becomes here
 * is: the reader's own library, in a folder named after the batch. A class's harvest stays
 * together, it belongs to somebody, and no second ownership rule is invented for the console's sake.
 * Sharing it with colleagues afterwards is what the sharing feature is for.
 *
 * The folder is created on the first fetch and reused after: fetching thirty homework files from
 * thirty machines must not create thirty folders.
 */
class ConsoleHarvest
{
    public function __construct(
        private readonly FileLibraryNodeManager $nodes,
        private readonly FileLibraryNodeRepository $repository,
        private readonly FileUploadService $uploads,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function store(User $owner, ConsoleSession $session, string $name, string $contents): FileLibraryNode
    {
        $folder = $this->folderFor($owner, $session);
        $safe = GuestFileDrop::safeName($name);

        // Through the ordinary upload path rather than writing to the bucket by hand: the library's
        // objects live under one prefix and nothing else in this repository writes there directly.
        $temporary = tempnam(sys_get_temp_dir(), 'console-fetch-');
        file_put_contents($temporary, $contents);

        try {
            // A random storage name, exactly as the library's own upload endpoint builds one: the
            // key is unique in the bucket and the *display* name is the row's business. Naming the
            // object after the file would collide the second time the same file is picked up off
            // a machine - measured, as a unique-constraint violation on the second fetch.
            $key = $this->uploads->upload(
                FileLibraryNodeManager::UPLOAD_PREFIX,
                $this->storageName($safe),
                new UploadedFile($temporary, $safe, null, null, true),
            );
        } finally {
            @unlink($temporary);
        }

        $node = $this->nodes->createFile(
            $owner,
            $folder,
            $safe,
            $key,
            $safe,
            $this->uploads->mimeType($key),
            \strlen($contents),
        );

        $this->entityManager->flush();

        return $node;
    }

    /** A name unique in the bucket; what the file is *called* lives in the row. */
    private function storageName(string $name): string
    {
        $extension = pathinfo($name, \PATHINFO_EXTENSION);

        return '' === $extension
            ? bin2hex(random_bytes(16))
            : \sprintf('%s.%s', bin2hex(random_bytes(16)), strtolower($extension));
    }

    /** The batch's folder in this person's library, created once and reused after. */
    private function folderFor(User $owner, ConsoleSession $session): FileLibraryNode
    {
        $label = $session->getGuestAccount()?->getBatch()?->getLabel() ?? 'Consoles';
        $name = mb_substr($label, 0, 120);

        foreach ($this->repository->findBy(['owner' => $owner, 'parent' => null, 'type' => FileLibraryNodeType::Folder]) as $node) {
            if ($node->getName() === $name && !$node->isDeleted()) {
                return $node;
            }
        }

        $folder = $this->nodes->createFolder($owner, null, $name);
        $this->entityManager->flush();

        return $folder;
    }
}
