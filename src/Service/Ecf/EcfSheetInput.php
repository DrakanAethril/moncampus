<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Enum\EcfResult;

/**
 * What a submitted activity sheet carries - the main sheet or its complementary page. The fields
 * a part does not have are simply ignored by EcfBookletWriter.
 */
final readonly class EcfSheetInput
{
    /**
     * @param list<EcfRowInput> $rows
     * @param list<int>         $reassessCompetences
     */
    public function __construct(
        public array $rows,
        public ?EcfResult $result,
        public ?string $attentionPoints = null,
        public ?string $reassessNote = null,
        public array $reassessCompetences = [],
        public ?string $observations = null,
    ) {
    }
}
