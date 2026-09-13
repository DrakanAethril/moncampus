<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The jobboard's list of sites leaves the code and becomes a table.
 *
 * The nine rows inserted here are the enum cases that used to be the whole list, copied with their
 * domains, their legacy prefix and their `source_ref` rule. They are seeded rather than left to an
 * administrator because the offers already stored point at them by name: the backfill below is a
 * join on `slug`, and a missing row would make it fail - which is exactly what should happen, a
 * deploy stopping being infinitely better than offers quietly losing their origin.
 *
 * The second insert is the belt: any value found in `jobboard_offer` or `jobboard_cursor` that the
 * nine do not cover gets its own row, so the `NOT NULL` at the end can only fail if something truly
 * unexpected is in there.
 */
final class Version20260913131239 extends AbstractMigration
{
    /**
     * The closed list, as it stood the day it was closed. Slug, trade name, domains, legacy prefix,
     * `source_ref` rule.
     *
     * @var list<array{string, string, list<string>, string, string}>
     */
    private const array DECLARED = [
        ['hellowork', 'HelloWork', ['hellowork.com'], 'hw', "le nombre qui termine l'URL de l'offre (/emplois/83313525.html → 83313525)"],
        ['francetravail', 'France Travail', ['francetravail.fr', 'pole-emploi.fr'], 'ft', "le dernier segment de l'URL (/detail/212WTTG → 212WTTG)"],
        ['meteojob', 'Meteojob', ['meteojob.com'], 'mj', "le dernier segment de l'URL (/jobs/56745532 → 56745532)"],
        ['jobteaser', 'Jobteaser', ['jobteaser.com'], 'jt', "les 8 premiers caractères de l'UUID de l'URL (e2ec328c-1474-… → e2ec328c)"],
        ['remotefr', 'RemoteFR', ['remotefr.com'], 'rf', "les 12 derniers caractères de l'identifiant rec… de l'URL (recJXBzjn9S2wFILz → Bzjn9S2wFILz)"],
        ['jobillico', 'Jobillico', ['jobillico.com'], 'jb', "l'identifiant numérique de l'offre tel qu'il apparaît dans la liste de résultats"],
        ['aliptic', 'Aliptic', ['aliptic.net'], 'al', "le nombre qui ouvre le dernier segment de l'URL (/job/396-ingenieure… → 396)"],
        ['jobup', 'Jobup', ['jobup.ch'], 'ju', "l'identifiant numérique de l'offre"],
        ['moovijob', 'Moovijob', ['moovijob.com'], 'mv', "l'identifiant de l'offre tel qu'il apparaît dans son URL"],
    ];

    public function getDescription(): string
    {
        return 'Jobboard: the sites become rows (jobboard_source), offers and cursors point at them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jobboard_source (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(32) NOT NULL, label VARCHAR(64) NOT NULL, domains JSON NOT NULL, ref_rule VARCHAR(255) DEFAULT NULL, legacy_prefix VARCHAR(8) DEFAULT NULL, discovered_at DATETIME NOT NULL, discovered_by_agent TINYINT NOT NULL, UNIQUE INDEX uniq_jobboard_source_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        foreach (self::DECLARED as [$slug, $label, $domains, $legacyPrefix, $refRule]) {
            $this->addSql(
                'INSERT INTO jobboard_source (slug, label, domains, ref_rule, legacy_prefix, discovered_at, discovered_by_agent) VALUES (?, ?, ?, ?, ?, NOW(), 0)',
                [$slug, $label, json_encode($domains, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $refRule, $legacyPrefix],
            );
        }

        // The derived table is not decoration: MySQL refuses a subquery reading the very table an
        // INSERT is writing, and wrapping it is the documented way round.
        foreach (['jobboard_offer', 'jobboard_cursor'] as $table) {
            $this->addSql(\sprintf(
                'INSERT INTO jobboard_source (slug, label, domains, discovered_at, discovered_by_agent)
                 SELECT DISTINCT t.source, t.source, JSON_ARRAY(), NOW(), 1 FROM %s t
                 WHERE t.source NOT IN (SELECT slug FROM (SELECT slug FROM jobboard_source) known)',
                $table,
            ));
        }

        foreach (['jobboard_offer' => 'uniq_jobboard_offer_identity', 'jobboard_cursor' => 'uniq_jobboard_cursor_scope'] as $table => $index) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD source_id INT DEFAULT NULL', $table));
            $this->addSql(\sprintf('UPDATE %s t JOIN jobboard_source s ON s.slug = t.source SET t.source_id = s.id', $table));
            // Deliberately left to fail rather than guarded: a row whose source did not resolve is a
            // row whose origin is lost, and a deploy that stops is the cheapest place to find out.
            $this->addSql(\sprintf('ALTER TABLE %s MODIFY source_id INT NOT NULL', $table));
            $this->addSql(\sprintf('DROP INDEX %s ON %s', $index, $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP source', $table));
        }

        $this->addSql('ALTER TABLE jobboard_offer ADD CONSTRAINT FK_82570070953C1C61 FOREIGN KEY (source_id) REFERENCES jobboard_source (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_82570070953C1C61 ON jobboard_offer (source_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jobboard_offer_identity ON jobboard_offer (track_id, source_id, source_ref)');
        $this->addSql('ALTER TABLE jobboard_cursor ADD CONSTRAINT FK_54B0879E953C1C61 FOREIGN KEY (source_id) REFERENCES jobboard_source (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_54B0879E953C1C61 ON jobboard_cursor (source_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jobboard_cursor_scope ON jobboard_cursor (track_id, source_id, search)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_offer DROP FOREIGN KEY FK_82570070953C1C61');
        $this->addSql('ALTER TABLE jobboard_cursor DROP FOREIGN KEY FK_54B0879E953C1C61');

        foreach (['jobboard_offer' => 'uniq_jobboard_offer_identity', 'jobboard_cursor' => 'uniq_jobboard_cursor_scope'] as $table => $index) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD source VARCHAR(32) DEFAULT NULL', $table));
            $this->addSql(\sprintf('UPDATE %s t JOIN jobboard_source s ON s.id = t.source_id SET t.source = s.slug', $table));
            // A slug longer than the old column, or a site that never was an enum case, cannot come
            // back - going down loses what going up made possible.
            $this->addSql(\sprintf('ALTER TABLE %s MODIFY source VARCHAR(32) NOT NULL', $table));
            $this->addSql(\sprintf('DROP INDEX %s ON %s', $index, $table));
            $this->addSql(\sprintf('DROP INDEX IDX_%s ON %s', 'jobboard_offer' === $table ? '82570070953C1C61' : '54B0879E953C1C61', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP source_id', $table));
        }

        $this->addSql('CREATE UNIQUE INDEX uniq_jobboard_offer_identity ON jobboard_offer (track_id, source, source_ref)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jobboard_cursor_scope ON jobboard_cursor (track_id, source, search)');
        $this->addSql('DROP TABLE jobboard_source');
    }
}
