<?php

declare(strict_types=1);

namespace App\Service\Crm;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A candidate's details as a form or the connector sends them, normalised the way the team writes
 * them: the last name in upper case, the mails in lower case, blank fields absent.
 */
final readonly class CrmCandidateInput
{
    /** @var list<array{lastName: string, firstName: string, email: string, phone: string}> */
    public array $guardians;

    /**
     * @param list<array{lastName?: string, firstName?: string, email?: string, phone?: string}> $guardians
     */
    public function __construct(
        public string $lastName,
        public string $firstName,
        public ?string $email = null,
        public ?string $phone = null,
        public bool $foreignStudent = false,
        array $guardians = [],
    ) {
        $clean = [];
        foreach ($guardians as $guardian) {
            $line = [
                'lastName' => mb_strtoupper(trim($guardian['lastName'] ?? '')),
                'firstName' => trim($guardian['firstName'] ?? ''),
                'email' => mb_strtolower(trim($guardian['email'] ?? '')),
                'phone' => trim($guardian['phone'] ?? ''),
            ];
            if ('' !== implode('', $line)) {
                $clean[] = $line;
            }
        }
        $this->guardians = \array_slice($clean, 0, 2);
    }

    /** @param list<array{lastName?: string, firstName?: string, email?: string, phone?: string}> $guardians */
    public static function of(string $lastName, string $firstName, ?string $email, ?string $phone, bool $foreignStudent, array $guardians): self
    {
        $email = null === $email ? null : mb_strtolower(trim($email));
        $phone = null === $phone ? null : trim($phone);

        return new self(
            mb_strtoupper(trim($lastName)),
            trim($firstName),
            '' === $email ? null : $email,
            '' === $phone ? null : $phone,
            $foreignStudent,
            $guardians,
        );
    }

    /** @return list<TranslatableMessage> */
    public function problems(): array
    {
        $problems = [];
        if ('' === $this->lastName) {
            $problems[] = new TranslatableMessage('crmRefusalLastNameMissing');
        }
        if ('' === $this->firstName) {
            $problems[] = new TranslatableMessage('crmRefusalFirstNameMissing');
        }
        if (null !== $this->email && !self::isMail($this->email)) {
            $problems[] = new TranslatableMessage('crmRefusalMailInvalid', ['%mail%' => $this->email]);
        }
        foreach ($this->guardians as $index => $guardian) {
            if ('' !== $guardian['email'] && !self::isMail($guardian['email'])) {
                $problems[] = new TranslatableMessage('crmRefusalGuardianMailInvalid', ['%number%' => $index + 1, '%mail%' => $guardian['email']]);
            }
        }

        return $problems;
    }

    public static function isMail(string $value): bool
    {
        return 1 === preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $value);
    }
}
