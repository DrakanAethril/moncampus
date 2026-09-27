<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * The places a teacher can send an evaluation to in École Directe: for each class or group they
 * grade, each period, each subject - read from `niveauxListe.awp`, the list École Directe's own
 * gradebook opens on.
 *
 * École Directe nests them establishment > level > class|group > period > subject, and puts some
 * groups at the top level instead; the walk below takes any object carrying periods, wherever it
 * sits, and knows it is a group when it was found under a `groupes` key.
 */
class EcoleDirecteGradebookCatalog
{
    public function __construct(private readonly EcoleDirecteClient $client)
    {
    }

    /**
     * @return array{options: list<array{key: string, label: string, start: string, end: string}>, session: EcoleDirecteSession}
     *
     * @throws EcoleDirecteException
     */
    public function options(EcoleDirecteSession $session): array
    {
        if (!$session->account->isTeacher()) {
            throw new EcoleDirecteException('ecoleDirecteNotTeacherMessage');
        }

        $result = $this->client->read($session, 'niveauxListe.awp');

        $options = [];
        $this->walk($result->data, 'C', $options);

        $unique = [];
        foreach ($options as $option) {
            $unique[$option['key']] ??= $option;
        }

        return ['options' => array_values($unique), 'session' => $result->session];
    }

    /**
     * @param list<array{key: string, label: string, start: string, end: string}> $options
     */
    private function walk(mixed $node, string $type, array &$options): void
    {
        if (!\is_array($node)) {
            return;
        }

        if (\is_int($node['id'] ?? null) && \is_array($node['periodes'] ?? null)) {
            $entityType = \is_string($node['typeEntity'] ?? null) && \in_array($node['typeEntity'], ['C', 'G'], true) ? $node['typeEntity'] : $type;
            $entityLabel = self::text($node['libelle'] ?? null) ?: self::text($node['code'] ?? null);

            foreach ($node['periodes'] as $period) {
                if (!\is_array($period) || '' === self::text($period['codePeriode'] ?? null)) {
                    continue;
                }
                foreach (\is_array($period['matieres'] ?? null) ? $period['matieres'] : [] as $subject) {
                    if (!\is_array($subject) || '' === self::text($subject['code'] ?? null)) {
                        continue;
                    }
                    $target = new EcoleDirecteGradebookTarget(
                        $entityType,
                        $node['id'],
                        self::text($period['codePeriode']),
                        self::text($subject['code']),
                        self::text($subject['codeSSMatiere'] ?? $subject['codeSousMatiere'] ?? null),
                    );
                    $options[] = [
                        'key' => $target->key(),
                        'label' => implode(' · ', array_filter([$entityLabel, self::text($period['libelle'] ?? null) ?: $target->periodCode, self::text($subject['libelle'] ?? null) ?: $target->subjectCode])),
                        'start' => substr(self::text($period['dateDebut'] ?? null), 0, 10),
                        'end' => substr(self::text($period['dateFin'] ?? null), 0, 10),
                    ];
                }
            }

            return;
        }

        foreach ($node as $key => $child) {
            $this->walk($child, \is_string($key) && str_contains(strtolower($key), 'groupe') ? 'G' : ('classes' === $key ? 'C' : $type), $options);
        }
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
