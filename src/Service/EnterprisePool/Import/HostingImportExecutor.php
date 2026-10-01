<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool\Import;

use App\Entity\Enterprise;
use App\Entity\EnterpriseContact;
use App\Entity\EnterpriseHosting;
use App\Entity\User;
use App\Enum\HostingSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes what an analysis said, and only an analysis with nothing blocking: the companies to
 * create (once each per file, SIRET recorded unconfirmed), the tutors as declared contacts - never
 * communicable to students until somebody decides it - and the hostings, `source = import`.
 * One flush: the file goes in whole or not at all.
 */
class HostingImportExecutor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{hostings: int, enterprises: int, contacts: int}
     */
    public function execute(HostingImportAnalysis $analysis, User $by): array
    {
        if (!$analysis->isImportable()) {
            throw new \LogicException('An import with blocking lines, or nothing to import, cannot be executed.');
        }

        /** @var array<string, Enterprise> $created */
        $created = [];
        /** @var array<string, EnterpriseContact> $contacts */
        $contacts = [];
        $counts = ['hostings' => 0, 'enterprises' => 0, 'contacts' => 0];

        foreach ($analysis->lines as $line) {
            if ($line->skipped || $line->isBlocking()) {
                continue;
            }

            $enterprise = $line->enterprise;
            if (null === $enterprise) {
                $key = $line->enterpriseKey();
                if (!isset($created[$key])) {
                    $address = trim(implode("\n", array_filter([$line->row->get('adresse'), trim($line->row->get('code_postal').' '.$line->row->get('ville'))])));
                    $enterprise = new Enterprise($line->enterpriseName, '' !== $address ? $address : null);
                    $enterprise->setCity('' !== $line->row->get('ville') ? $line->row->get('ville') : null);
                    $enterprise->setSiret($line->siret);
                    $enterprise->setCreatedBy($by);
                    $enterprise->setTestEnterprise($by->isTestUser());
                    $this->entityManager->persist($enterprise);
                    $created[$key] = $enterprise;
                    ++$counts['enterprises'];
                }
                $enterprise = $created[$key];
            }

            $contact = null;
            $tutorName = $line->row->get('tuteur_nom');
            if ('' !== $tutorName) {
                $contactKey = spl_object_id($enterprise).'|'.mb_strtolower($tutorName);
                $contact = $contacts[$contactKey] ?? $this->existingContact($enterprise, $tutorName);
                if (null === $contact) {
                    $contact = (new EnterpriseContact())
                        ->setEnterprise($enterprise)
                        ->setName($tutorName)
                        ->setJobTitle($line->row->get('tuteur_fonction'))
                        ->setEmail(filter_var($line->row->get('tuteur_email'), \FILTER_VALIDATE_EMAIL) ? $line->row->get('tuteur_email') : null)
                        ->setPhone($line->row->get('tuteur_telephone'))
                        ->setCreatedBy($by);
                    $this->entityManager->persist($contact);
                    ++$counts['contacts'];
                }
                $contacts[$contactKey] = $contact;
            }

            $hosting = (new EnterpriseHosting())
                ->setEnterprise($enterprise)
                ->setKind($line->kind)
                ->setYearStart((int) $line->yearStart)
                ->setTrack($line->track)
                ->setOption($line->option)
                ->setStudent($line->student)
                ->setStudentName($line->studentName)
                ->setMissions(mb_substr($line->row->get('missions'), 0, 500))
                ->setContact($contact)
                ->setSource(HostingSource::Import)
                ->setCreatedBy($by);
            $this->entityManager->persist($hosting);
            ++$counts['hostings'];
        }

        $this->entityManager->flush();

        return $counts;
    }

    private function existingContact(Enterprise $enterprise, string $name): ?EnterpriseContact
    {
        if (null === $enterprise->getId()) {
            return null;
        }

        foreach ($this->entityManager->getRepository(EnterpriseContact::class)->findBy(['enterprise' => $enterprise, 'inactiveDate' => null]) as $contact) {
            if (0 === strcasecmp($contact->getName(), $name)) {
                return $contact;
            }
        }

        return null;
    }
}
