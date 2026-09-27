<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * The École Directe account a login opened, as its login answer describes it.
 *
 * For a teacher (`P`) the answer already lists the classes, level groups and subjects they teach -
 * which is what the future mapping to MonCampus's programs and topics will be built on, and why the
 * three lists are kept here rather than read again.
 */
final readonly class EcoleDirecteAccount
{
    public const string TYPE_TEACHER = 'P';

    /**
     * @param list<array{id: int, code: string, label: string}>                                        $classes
     * @param list<array{id: int, code: string, label: string, classId: ?int, subjectCode: string}>    $groups
     * @param list<array{code: string, label: string}>                                                 $subjects
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $firstName,
        public string $lastName,
        public string $establishment = '',
        public string $schoolYear = '',
        public array $classes = [],
        public array $groups = [],
        public array $subjects = [],
    ) {
    }

    public function isTeacher(): bool
    {
        return self::TYPE_TEACHER === $this->type;
    }

    /**
     * The account a login answer opened: the one flagged `main`, else the first. Null when the
     * answer carries no readable account at all, which the client treats as an answer it does not
     * understand.
     *
     * @param array<array-key, mixed> $data the `data` member of the login answer
     */
    public static function fromLoginData(array $data): ?self
    {
        $accounts = \is_array($data['accounts'] ?? null) ? array_values(array_filter($data['accounts'], 'is_array')) : [];
        if ([] === $accounts) {
            return null;
        }

        $chosen = $accounts[0];
        foreach ($accounts as $account) {
            if (true === ($account['main'] ?? null)) {
                $chosen = $account;
                break;
            }
        }

        $id = $chosen['id'] ?? null;
        $type = $chosen['typeCompte'] ?? null;
        if (!\is_int($id) || !\is_string($type)) {
            return null;
        }

        $profile = \is_array($chosen['profile'] ?? null) ? $chosen['profile'] : [];

        $classes = [];
        foreach (self::records($profile['classes'] ?? null) as $class) {
            if (\is_int($class['id'] ?? null)) {
                $classes[] = ['id' => $class['id'], 'code' => self::text($class['code'] ?? null), 'label' => self::text($class['libelle'] ?? null)];
            }
        }

        $groups = [];
        foreach (self::records($profile['groupesNiveau'] ?? $profile['groupes'] ?? null) as $group) {
            if (\is_int($group['id'] ?? null)) {
                $groups[] = [
                    'id' => $group['id'],
                    'code' => self::text($group['code'] ?? null),
                    'label' => self::text($group['libelle'] ?? null),
                    'classId' => \is_int($group['idClasse'] ?? null) ? $group['idClasse'] : null,
                    'subjectCode' => self::text($group['codeMatiere'] ?? null),
                ];
            }
        }

        $subjects = [];
        foreach (self::records($profile['matieres'] ?? null) as $subject) {
            $code = self::text($subject['code'] ?? null);
            if ('' !== $code) {
                $subjects[] = ['code' => $code, 'label' => self::text($subject['libelle'] ?? null)];
            }
        }

        return new self(
            $id,
            $type,
            self::text($chosen['prenom'] ?? null),
            self::text($chosen['nom'] ?? null),
            self::text($chosen['nomEtablissement'] ?? null),
            self::text($chosen['anneeScolaireCourante'] ?? null),
            $classes,
            $groups,
            $subjects,
        );
    }

    /**
     * @return array{id: int, type: string, firstName: string, lastName: string, establishment: string, schoolYear: string, classes: list<array{id: int, code: string, label: string}>, groups: list<array{id: int, code: string, label: string, classId: ?int, subjectCode: string}>, subjects: list<array{code: string, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'establishment' => $this->establishment,
            'schoolYear' => $this->schoolYear,
            'classes' => $this->classes,
            'groups' => $this->groups,
            'subjects' => $this->subjects,
        ];
    }

    /**
     * Read back what toArray() wrote - the sealed session, which only this class ever writes.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        if (!\is_int($id) || !\is_string($type)) {
            return null;
        }

        $classes = [];
        foreach (self::records($data['classes'] ?? null) as $class) {
            if (\is_int($class['id'] ?? null)) {
                $classes[] = ['id' => $class['id'], 'code' => self::text($class['code'] ?? null), 'label' => self::text($class['label'] ?? null)];
            }
        }

        $groups = [];
        foreach (self::records($data['groups'] ?? null) as $group) {
            if (\is_int($group['id'] ?? null)) {
                $groups[] = [
                    'id' => $group['id'],
                    'code' => self::text($group['code'] ?? null),
                    'label' => self::text($group['label'] ?? null),
                    'classId' => \is_int($group['classId'] ?? null) ? $group['classId'] : null,
                    'subjectCode' => self::text($group['subjectCode'] ?? null),
                ];
            }
        }

        $subjects = [];
        foreach (self::records($data['subjects'] ?? null) as $subject) {
            $subjects[] = ['code' => self::text($subject['code'] ?? null), 'label' => self::text($subject['label'] ?? null)];
        }

        return new self(
            $id,
            $type,
            self::text($data['firstName'] ?? null),
            self::text($data['lastName'] ?? null),
            self::text($data['establishment'] ?? null),
            self::text($data['schoolYear'] ?? null),
            $classes,
            $groups,
            $subjects,
        );
    }

    /** @return list<array<array-key, mixed>> */
    private static function records(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
