<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

use App\Entity\Laptop;
use App\Entity\LaptopConditionType;
use App\Entity\User;
use App\Enum\LaptopImportAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writing move of the laptop import, and the only one that is not idempotent.
 *
 * It refuses an analysis that no longer says the file can be imported, rather than writing the part
 * of it that still can: the analysis it is handed was rebuilt from the parked rows a moment before,
 * so a refusal here means the inventory moved while the operator was reading the verification
 * screen - which is exactly the case the whole two-step shape exists for.
 *
 * Machines already in the inventory are left untouched, never updated: the file is a purchase list,
 * and what the inventory knows about a machine it already holds was not written from a spreadsheet.
 */
final class LaptopImportExecutor
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @throws LaptopImportNotExecutableException
     */
    public function execute(LaptopImportAnalysis $analysis, User $operator, ?LaptopConditionType $initialCondition = null): LaptopImportOutcome
    {
        if (!$analysis->isImportable()) {
            throw new LaptopImportNotExecutableException();
        }

        $createdAssetTags = [];

        foreach ($analysis->laptops as $analyzed) {
            if (LaptopImportAction::Create !== $analyzed->action) {
                continue;
            }

            $laptop = new Laptop($analyzed->row->assetTag);
            $laptop->setSerialNumber($analyzed->row->serialNumber);
            $laptop->setBrand('' !== $analyzed->row->brand ? $analyzed->row->brand : null);
            $laptop->setModel('' !== $analyzed->row->model ? $analyzed->row->model : null);
            $laptop->setReplacementValue($analyzed->replacementValue);
            // The état an imported machine starts on, when step ① named one - the same "starting
            // point before the machine has ever been lent" the one-machine form offers, and the
            // reason it is asked once for the whole delivery: a box of new machines is all in the
            // same state, which is precisely what an inventory file never says.
            $laptop->setCurrentConditionType($initialCondition);
            $laptop->setCreatedBy($operator);

            $this->entityManager->persist($laptop);
            $createdAssetTags[] = $laptop->getAssetTag();
        }

        // One flush for the whole file: the unique indexes on the two numbers are the last word, and
        // a file that trips one of them must write nothing rather than half of itself.
        $this->entityManager->flush();

        return new LaptopImportOutcome(
            $createdAssetTags,
            array_map(
                static fn (AnalyzedLaptop $analyzed): string => $analyzed->row->assetTag,
                $analysis->laptopsWith(LaptopImportAction::Skip),
            ),
        );
    }
}
