<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\HelpArticle;
use App\Entity\HelpSection;
use App\Help\HelpContentCatalog;
use App\Repository\HelpArticleRepository;
use App\Repository\HelpSectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Loads App\Help\HelpContentCatalog into the database, once, without ever overwriting an admin.
 *
 * The help is editable from the application (App\Controller\HelpAdminController), so the database
 * is its home; this command only puts the initial content there - after a first install, after a
 * database rebuild, or when the catalogue gains an entry that production does not have yet.
 *
 * Matching is by slug. An entry that already exists is left alone, which is what makes running it
 * twice harmless. --refresh additionally rewrites the entries nobody has edited since (those whose
 * "modifié le" is still empty): that is the safe half of an update, and it stops exactly where an
 * admin's own work starts.
 */
#[AsCommand(
    name: 'app:help:sync-content',
    description: "Crée les rubriques et articles d'aide manquants à partir du catalogue livré avec le code.",
)]
class SyncHelpContentCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HelpSectionRepository $sections,
        private readonly HelpArticleRepository $articles,
        private readonly HelpContentCatalog $catalog,
        #[Target('app.help_article_body')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('refresh', null, InputOption::VALUE_NONE, "Réécrit aussi les entrées qu'aucun administrateur n'a modifiées");
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait fait, sans écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refresh = true === $input->getOption('refresh');
        $dryRun = true === $input->getOption('dry-run');

        $created = 0;
        $refreshed = 0;
        $untouched = 0;

        foreach ($this->catalog->sections() as $index => $definition) {
            $section = $this->sections->findOneBySlug($definition['slug']);

            if (null === $section) {
                $section = new HelpSection($definition['slug'], $definition['title']);
                $this->fillSection($section, $definition, $index);
                $this->entityManager->persist($section);
                ++$created;
                $io->text(sprintf('+ rubrique %s', $definition['slug']));
            } elseif ($refresh && null === $section->getUpdatedAt()) {
                $this->fillSection($section, $definition, $index);
                ++$refreshed;
                $io->text(sprintf('~ rubrique %s', $definition['slug']));
            } else {
                ++$untouched;
            }

            foreach ($definition['articles'] as $position => $articleDefinition) {
                // A section created in this same run has no id yet, so it cannot be looked up
                // through the repository - flush first, then match by slug as usual.
                if (!$dryRun) {
                    $this->entityManager->flush();
                }

                $article = null !== $section->getId()
                    ? $this->articles->findOneBySlug($section, $articleDefinition['slug'])
                    : null;

                if (null === $article) {
                    $article = new HelpArticle($section, $articleDefinition['slug'], $articleDefinition['title']);
                    $this->fillArticle($article, $articleDefinition, $position);
                    $this->entityManager->persist($article);
                    ++$created;
                    $io->text(sprintf('  + %s / %s', $definition['slug'], $articleDefinition['slug']));
                } elseif ($refresh && null === $article->getUpdatedAt()) {
                    $this->fillArticle($article, $articleDefinition, $position);
                    ++$refreshed;
                    $io->text(sprintf('  ~ %s / %s', $definition['slug'], $articleDefinition['slug']));
                } else {
                    ++$untouched;
                }
            }
        }

        if ($dryRun) {
            $this->entityManager->clear();
            $io->warning(sprintf('Simulation : %d à créer, %d à réécrire, %d inchangés.', $created, $refreshed, $untouched));

            return Command::SUCCESS;
        }

        $this->entityManager->flush();
        $io->success(sprintf('%d créés, %d réécrits, %d inchangés.', $created, $refreshed, $untouched));

        return Command::SUCCESS;
    }

    /** @param array{slug: string, title: string, description: string, audiences: list<\App\Enum\HelpAudience>} $definition */
    private function fillSection(HelpSection $section, array $definition, int $index): void
    {
        $section
            ->setSlug($definition['slug'])
            ->setTitle($definition['title'])
            ->setDescription($definition['description'])
            ->setAudiences($definition['audiences'])
            ->setPosition($index * 10)
            ->setPublished(true);
    }

    /**
     * @param array{
     *     kind: \App\Enum\HelpArticleKind,
     *     slug: string,
     *     title: string,
     *     audiences: list<\App\Enum\HelpAudience>,
     *     summary: string,
     *     body?: string
     * } $definition
     */
    private function fillArticle(HelpArticle $article, array $definition, int $position): void
    {
        $body = $definition['body'] ?? null;

        $article
            ->setKind($definition['kind'])
            ->setSlug($definition['slug'])
            ->setTitle($definition['title'])
            ->setSummary($definition['summary'])
            // Sanitized exactly like an admin's own input: the catalogue is trusted, the pipeline
            // it goes through should not be a second one.
            ->setBody(null === $body ? null : $this->sanitizer->sanitize($this->dedent($body)))
            ->setAudiences($definition['audiences'])
            ->setPosition($position * 10)
            ->setPublished(true);
    }

    /** The catalogue's heredocs are indented to read well in PHP; the stored HTML should not be. */
    private function dedent(string $html): string
    {
        return trim(preg_replace('/^[ \t]+/m', '', $html) ?? $html);
    }
}
