<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LaptopLoan;
use App\Enum\LaptopLoanType;

/**
 * Renders the two paper documents of a laptop loan - the convention handed over with the machine,
 * and the restitution form signed when it comes back.
 *
 * Neither is recomposed in HTML. Each page of the original PDF was rasterised once at 300 dpi (see
 * resources/laptop_loan_documents/) and is laid down as a full-page background, with only the
 * loan's own values positioned on top in millimetres. The fixed text is therefore the original
 * rather than a lookalike, which also settles the commercial fonts the models are typeset in: the
 * application never composes a single character of them.
 *
 * The backgrounds travel as base64 data URIs inside the HTML because GotenbergClient posts one
 * index.html and no companion asset. That inflates the payload by about a third (~1.8 MB for the
 * three-page convention); should it ever start to hurt, re-export the backgrounds as 200 dpi JPEG
 * before looking for anything cleverer.
 */
class LaptopLoanDocumentExporter
{
    /**
     * Per loan type: the resource directory holding its images, the template that lays them out,
     * and which images each of the two documents needs. A new version of a paper model gets a new
     * directory and a new template - the images and the template are tied to one version.
     *
     * The two models are built differently on purpose, and the difference is in the source, not in
     * a preference. The UFA model only exists as a PDF typeset in commercial fonts, so its pages
     * are scanned and only the values are overprinted - hence one background image per page, keyed
     * by page number. The CFC model came as real HTML, so it is composed for real, and its images
     * are just the two logos it embeds.
     *
     * @var array<string, array{directory: string, template: string, convention: non-empty-array<array-key, string>, return_form: non-empty-array<array-key, string>}>
     */
    private const array MODELS = [
        'ufa' => [
            'directory' => 'ufa-v3-2025-08-01',
            'template' => 'laptop/documents/ufa.html.twig',
            'convention' => [1 => 'page-1.png', 2 => 'page-2.png', 3 => 'page-3.png'],
            'return_form' => [4 => 'page-4.png'],
        ],
        'cfc' => [
            'directory' => 'cfc-2026-08',
            'template' => 'laptop/documents/cfc.html.twig',
            'convention' => ['logo' => 'logo-beaupeyrat.png', 'qualiopi' => 'qualiopi.png'],
            'return_form' => ['logo' => 'logo-beaupeyrat.png', 'qualiopi' => 'qualiopi.png'],
        ],
    ];

    public function __construct(
        private readonly LaptopLoanDocumentBuilder $documentBuilder,
        private readonly GotenbergClient $gotenbergClient,
        private readonly string $loanDocumentResourceDir,
    ) {
    }

    /** Whether this loan's type has a paper model built at all. */
    public function supports(?LaptopLoanType $loanType): bool
    {
        return null !== $this->model($loanType);
    }

    /**
     * The model to print on, or null when there is none - either the loan carries no type at all,
     * or it carries one whose paper model has not been built.
     *
     * @return array{directory: string, template: string, convention: non-empty-array<array-key, string>, return_form: non-empty-array<array-key, string>}|null
     */
    private function model(?LaptopLoanType $loanType): ?array
    {
        return null === $loanType ? null : (self::MODELS[$loanType->value] ?? null);
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function exportConvention(LaptopLoan $loan, \Closure $renderView): string
    {
        return $this->export($loan, $renderView, 'convention');
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function exportReturnForm(LaptopLoan $loan, \Closure $renderView): string
    {
        return $this->export($loan, $renderView, 'return_form');
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     * @param 'convention'|'return_form'                     $slice
     *
     * @return non-empty-string raw PDF bytes
     */
    private function export(LaptopLoan $loan, \Closure $renderView, string $slice): string
    {
        $loanType = $loan->getLoanType();
        $model = $this->model($loanType);

        if (null === $model) {
            // No null guard on $loanType: loan_type is a NOT NULL column, so a persisted loan
            // always carries one - the property is only nullable because Doctrine hydrates without
            // the constructor. Reaching here means the type has no paper model built yet.
            throw new \LogicException(\sprintf('No printable model for loan type "%s".', $loanType->value));
        }

        $images = [];

        foreach ($model[$slice] as $key => $filename) {
            $images[$key] = $this->imageDataUri($model['directory'], $filename);
        }

        return $this->gotenbergClient->convertHtmlToPdf($renderView($model['template'], [
            'images' => $images,
            'documentSlice' => $slice,
            'document' => $this->documentBuilder->build($loan),
        ]));
    }

    private function imageDataUri(string $directory, string $filename): string
    {
        $path = \sprintf('%s/%s/%s', rtrim($this->loanDocumentResourceDir, '/'), $directory, $filename);
        $contents = @file_get_contents($path);

        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Missing loan document image "%s".', $path));
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }
}
