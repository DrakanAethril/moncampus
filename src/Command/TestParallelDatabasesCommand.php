<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives each ParaTest process a database of its own: a copy of the test database, tables and rows.
 *
 * ParaTest numbers its processes through TEST_TOKEN, which config/packages/doctrine.yaml appends to
 * the test database's name (`dbname_suffix`): process 3 opens `<database>_test3`. Those copies are
 * what this command writes, from whichever test database the connection opens - `<database>_test`
 * on a developer's machine, `<database>_testci` on CI, where the migrations have just been replayed
 * into it. Each copy is dropped and written again, so running it again after a schema change is
 * the whole upgrade.
 *
 * Copied rather than built with `doctrine:schema:update`, which took 26 s per database on the
 * development machine against a second or two here; and a copy also carries the rows the migrations
 * insert, so on CI the tests keep running against exactly what the migrations produced.
 *
 * Refused outside the test environment: it drops databases.
 */
#[AsCommand(
    name: 'app:test:parallel-databases',
    description: 'Copie la base de test en une base par processus ParaTest.',
)]
class TestParallelDatabasesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('processes', 'p', InputOption::VALUE_REQUIRED, 'Nombre de processus ParaTest, donc de copies.', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('test' !== $this->environment) {
            $io->error('Cette commande supprime des bases : elle ne tourne que dans l\'environnement de test (APP_ENV=test).');

            return Command::FAILURE;
        }

        $processes = filter_var($input->getOption('processes'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 64]]);
        if (false === $processes) {
            $io->error('--processes attend un entier entre 1 et 64.');

            return Command::FAILURE;
        }

        $source = (string) $this->connection->getDatabase();
        if (1 !== preg_match('/^(.+_test)[^_]*$/', $source, $match)) {
            $io->error(\sprintf('« %s » ne ressemble pas à une base de test.', $source));

            return Command::FAILURE;
        }
        $targets = array_map(static fn (int $token): string => $match[1].$token, range(1, $processes));
        if (\in_array($source, $targets, true)) {
            $io->error(\sprintf('La base source « %s » est elle-même une copie : lancer la commande sans TEST_TOKEN.', $source));

            return Command::FAILURE;
        }

        $tables = $this->tables($source);
        $charset = $this->connection->fetchAssociative(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$source],
        );
        $characterSet = \is_string($charset['charset'] ?? null) ? $charset['charset'] : 'utf8mb4';
        $collation = \is_string($charset['collation'] ?? null) ? $charset['collation'] : 'utf8mb4_unicode_ci';

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($targets as $target) {
                $this->copy($source, $target, $tables, $characterSet, $collation);
                $io->writeln(\sprintf('%s → %s (%d tables)', $source, $target, \count($tables)));
            }
        } finally {
            $this->connection->executeStatement('USE '.$this->quote($source));
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $io->success(\sprintf('%d base(s) prête(s) pour ParaTest.', \count($targets)));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, list<string>> each table with the columns a copy may write (generated ones are computed by MySQL)
     */
    private function tables(string $schema): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name, c.EXTRA AS extra
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE'
              ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
            [$schema],
        );

        $tables = [];
        foreach ($rows as $row) {
            $table = $row['table_name'] ?? null;
            $column = $row['column_name'] ?? null;
            if (!\is_string($table) || !\is_string($column)) {
                continue;
            }
            $tables[$table] ??= [];
            if (!str_contains(strtoupper(\is_string($row['extra'] ?? null) ? $row['extra'] : ''), 'GENERATED')) {
                $tables[$table][] = $column;
            }
        }

        return $tables;
    }

    /**
     * @param array<string, list<string>> $tables
     */
    private function copy(string $source, string $target, array $tables, string $characterSet, string $collation): void
    {
        $this->connection->executeStatement('DROP DATABASE IF EXISTS '.$this->quote($target));
        $this->connection->executeStatement(\sprintf('CREATE DATABASE %s CHARACTER SET %s COLLATE %s', $this->quote($target), $characterSet, $collation));
        // SHOW CREATE TABLE names the table and its foreign keys' targets without a schema: they land
        // in whichever database is current.
        $this->connection->executeStatement('USE '.$this->quote($target));

        foreach (array_keys($tables) as $table) {
            $create = $this->connection->fetchAssociative(\sprintf('SHOW CREATE TABLE %s.%s', $this->quote($source), $this->quote($table)));
            $statement = $create['Create Table'] ?? null;
            if (!\is_string($statement)) {
                throw new \RuntimeException(\sprintf('SHOW CREATE TABLE gave nothing for %s.', $table));
            }
            $this->connection->executeStatement($statement);
        }

        foreach ($tables as $table => $columns) {
            $list = implode(', ', array_map($this->quote(...), $columns));
            $this->connection->executeStatement(\sprintf(
                'INSERT INTO %s.%s (%s) SELECT %s FROM %s.%s',
                $this->quote($target), $this->quote($table), $list, $list, $this->quote($source), $this->quote($table),
            ));
        }
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
