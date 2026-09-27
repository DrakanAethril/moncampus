<?php

declare(strict_types=1);

namespace App\Service;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Environment;

/**
 * A course handout written in Markdown - by Claude, through the connector - turned into the
 * document a teacher prints or hands out: a standalone HTML page, or its PDF (Gotenberg).
 *
 * The Markdown is GitHub's dialect, because tables and fenced code are what course material is
 * made of; raw HTML in it is escaped, never interpreted, and what CommonMark produces still goes
 * through the library's sanitizer (`app.library_content`) before it reaches a template - the text
 * came from a model, and the page may end up in front of students.
 */
final readonly class CourseMaterialRenderer
{
    public function __construct(
        private Environment $twig,
        private GotenbergClient $gotenberg,
        #[Target('app.library_content')] private HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function html(string $title, string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return $this->twig->render('file_library/course_material.html.twig', [
            'title' => $title,
            'body' => $this->sanitizer->sanitize((string) $converter->convert($markdown)),
        ]);
    }

    /**
     * @return non-empty-string
     *
     * @throws GotenbergUnavailableException
     */
    public function pdf(string $title, string $markdown): string
    {
        return $this->gotenberg->convertHtmlToPdf($this->html($title, $markdown));
    }
}
