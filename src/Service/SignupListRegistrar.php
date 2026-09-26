<?php

declare(strict_types=1);

namespace App\Service;

use App\Counter\CounterDrift;
use App\Counter\CounterRun;
use App\Counter\RecomputableCounter;
use App\Entity\SignupList;
use App\Entity\SignupListRegistration;
use App\Entity\User;
use App\Repository\SignupListRegistrationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registering to a sign-up list and leaving it - the web screen and the mobile API both come here -
 * together with the list's stored registration count, which moves in the same transaction.
 *
 * The count is also a App\Counter\RecomputableCounter: `app:counters:recompute` sums
 * `signup_list_registration` again at night and reports whatever drifted.
 */
class SignupListRegistrar implements RecomputableCounter
{
    public const string NAME = 'signup_registrations';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SignupListRegistrationRepository $registrations,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Registers the person unless they already are. The caller has checked the Voter and that
     * registration is still open.
     *
     * @return bool whether a registration was added
     */
    public function register(SignupList $signupList, User $user): bool
    {
        if (null !== $this->registrations->findOneForSignupListAndUser($signupList, $user)) {
            return false;
        }

        $this->transactional(function () use ($signupList, $user): void {
            $this->entityManager->persist(new SignupListRegistration($signupList, $user));
            $this->entityManager->flush();
            $this->move($signupList, 1);
        });

        return true;
    }

    public function unregister(SignupListRegistration $registration): void
    {
        $signupList = $registration->getSignupList();

        $this->transactional(function () use ($registration, $signupList): void {
            $this->entityManager->remove($registration);
            $this->entityManager->flush();

            if (null !== $signupList) {
                $this->move($signupList, -1);
            }
        });
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return "Listes d'inscription : nombre d'inscrits de chaque liste";
    }

    public function recompute(?int $id = null, bool $dryRun = false): CounterRun
    {
        return $this->connection->transactional(function (Connection $connection) use ($id, $dryRun): CounterRun {
            $parameters = null !== $id ? ['id' => $id] : [];

            /** @var list<array{id: int|string, title: string, registration_count: int|string}> $lists */
            $lists = $connection->fetchAllAssociative(
                'SELECT id, title, registration_count FROM signup_list'.(null !== $id ? ' WHERE id = :id' : '').' ORDER BY id FOR UPDATE',
                $parameters,
            );

            /** @var list<array{signup_list_id: int|string, total: int|string}> $sums */
            $sums = $connection->fetchAllAssociative(
                'SELECT signup_list_id, COUNT(*) AS total FROM signup_list_registration'
                .(null !== $id ? ' WHERE signup_list_id = :id' : '').' GROUP BY signup_list_id',
                $parameters,
            );

            $expected = [];
            foreach ($sums as $sum) {
                $expected[(int) $sum['signup_list_id']] = (int) $sum['total'];
            }

            $drifts = [];
            foreach ($lists as $list) {
                $listId = (int) $list['id'];
                $stored = (int) $list['registration_count'];
                $computed = $expected[$listId] ?? 0;

                if ($stored === $computed) {
                    continue;
                }

                $drifts[] = new CounterDrift($listId, $list['title'], ['registration_count' => $stored], ['registration_count' => $computed]);

                if (!$dryRun) {
                    $connection->executeStatement('UPDATE signup_list SET registration_count = :count WHERE id = :id', ['count' => $computed, 'id' => $listId]);
                }
            }

            return new CounterRun(self::NAME, \count($lists), $drifts, $dryRun);
        });
    }

    private function move(SignupList $signupList, int $delta): void
    {
        $this->connection->executeStatement(
            'UPDATE signup_list SET registration_count = registration_count + :delta WHERE id = :id',
            ['delta' => $delta, 'id' => $signupList->getId()],
        );
        $signupList->mirrorRegistrationCount($delta);
    }

    private function transactional(callable $work): void
    {
        $this->connection->beginTransaction();

        try {
            $work();
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }
}
