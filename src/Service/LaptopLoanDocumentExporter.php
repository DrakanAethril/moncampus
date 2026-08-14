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
     * Per loan type: the resource directory holding the backgrounds, and which of its pages make up
     * each document. A new version of a paper model gets a new directory and a new template - both
     * the images and every coordinate in the template are tied to one version.
     *
     * @var array<string, array{directory: string, template: string, conventionPages: non-empty-list<int>, returnFormPages: non-empty-list<int>}>
     */
    private const array MODELS = [
        'ufa' => [
            'directory' => 'ufa-v3-2025-08-01',
            'template' => 'laptop/documents/ufa.html.twig',
            'conventionPages' => [1, 2, 3],
            'returnFormPages' => [4],
        ],
    ];

    public function __construct(
        private readonly LaptopLoanDocumentBuilder $documentBuilder,
        private readonly GotenbergClient $gotenbergClient,
        private readonly string $loanDocumentResourceDir,
    ) {
    }

    /** Whether this loan's model has been built yet - the CFC one is still waiting for its source PDF. */
    public function supports(?LaptopLoanType $loanType): bool
    {
        return null !== $this->model($loanType);
    }

    /**
     * The model to print on, or null when there is none - either the loan carries no type at all,
     * or it carries one whose paper model has not been built.
     *
     * @return array{directory: string, template: string, conventionPages: non-empty-list<int>, returnFormPages: non-empty-list<int>}|null
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
        return $this->export($loan, $renderView, 'conventionPages');
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function exportReturnForm(LaptopLoan $loan, \Closure $renderView): string
    {
        return $this->export($loan, $renderView, 'returnFormPages');
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     * @param 'conventionPages'|'returnFormPages'            $pageSet
     *
     * @return non-empty-string raw PDF bytes
     */
    private function export(LaptopLoan $loan, \Closure $renderView, string $pageSet): string
    {
        $loanType = $loan->getLoanType();
        $model = $this->model($loanType);

        if (null === $model) {
            throw new \LogicException(\sprintf('No printable model for loan type "%s".', $loanType?->value ?? 'none'));
        }

        $pages = [];

        foreach ($model[$pageSet] as $pageNumber) {
            $pages[$pageNumber] = $this->backgroundDataUri($model['directory'], $pageNumber);
        }

        return $this->gotenbergClient->convertHtmlToPdf($renderView($model['template'], [
            'pages' => $pages,
            'document' => $this->documentBuilder->build($loan),
        ]));
    }

    private function backgroundDataUri(string $directory, int $pageNumber): string
    {
        $path = \sprintf('%s/%s/page-%d.png', rtrim($this->loanDocumentResourceDir, '/'), $directory, $pageNumber);
        $contents = @file_get_contents($path);

        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Missing loan document background "%s".', $path));
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }
}
