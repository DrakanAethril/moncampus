<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * Where in École Directe an evaluation is sent: a class or a group, a period, a subject - the four
 * coordinates of every gradebook route. Travels through the page as one opaque key.
 */
final readonly class EcoleDirecteGradebookTarget
{
    private const string SEPARATOR = '|';

    public function __construct(
        public string $entityType,
        public int $entityId,
        public string $periodCode,
        public string $subjectCode,
        public string $subSubjectCode = '',
    ) {
    }

    public function key(): string
    {
        return implode(self::SEPARATOR, [$this->entityType, (string) $this->entityId, rawurlencode($this->periodCode), rawurlencode($this->subjectCode), rawurlencode($this->subSubjectCode)]);
    }

    /** Null for anything this class did not write. */
    public static function fromKey(string $key): ?self
    {
        $parts = explode(self::SEPARATOR, $key);
        if (5 !== \count($parts) || !\in_array($parts[0], ['C', 'G'], true) || 1 !== preg_match('/^\d+$/', $parts[1])) {
            return null;
        }

        $period = rawurldecode($parts[2]);
        $subject = rawurldecode($parts[3]);
        if ('' === $period || '' === $subject) {
            return null;
        }

        return new self($parts[0], (int) $parts[1], $period, $subject, rawurldecode($parts[4]));
    }

    /** The common stem of the gradebook routes: `enseignants/{me}/{C|G}/{id}/periodes/{p}/matieres/{s}`. */
    public function routeStem(EcoleDirecteAccount $account): string
    {
        return \sprintf(
            'enseignants/%d/%s/%d/periodes/%s/matieres/%s',
            $account->id,
            $this->entityType,
            $this->entityId,
            rawurlencode($this->periodCode),
            EcoleDirecteSubjectCode::gradebookSegment($this->subjectCode, $this->subSubjectCode),
        );
    }
}
