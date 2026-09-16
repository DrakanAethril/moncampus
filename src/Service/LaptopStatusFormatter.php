<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Laptop;
use App\Entity\LaptopLoan;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formats a Laptop's derived status (retired / available / on loan / overdue) for display -
 * the status itself is never stored, only computed from inactiveDate and the laptop's active
 * LaptopLoan (if any), same idea as App\Service\QueueStateFormatter.
 */
class LaptopStatusFormatter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function label(Laptop $laptop, ?LaptopLoan $activeLoan): string
    {
        if (null !== $laptop->getInactiveDate()) {
            return $this->translator->trans('laptopStatusRetiredLabel');
        }

        if (null === $activeLoan) {
            return $this->translator->trans('laptopStatusAvailableLabel');
        }

        return $activeLoan->isOverdue()
            ? $this->translator->trans('laptopStatusOverdueLabel')
            : $this->translator->trans('laptopStatusLaptopOnLoanLabel');
    }

    public function cssClass(Laptop $laptop, ?LaptopLoan $activeLoan): string
    {
        if (null !== $laptop->getInactiveDate()) {
            return 'bg-secondary-lt';
        }

        if (null === $activeLoan) {
            return 'bg-green-lt';
        }

        return $activeLoan->isOverdue() ? 'bg-red-lt' : 'bg-blue-lt';
    }

    /**
     * The "Retour prévu" cell of a loan: its due date, or the word for a loan that has none.
     *
     * An empty due date is never a date somebody forgot to enter - LaptopLoan::validateDueDate()
     * only lets an internal loan through without one - so it is said rather than dashed out.
     */
    public function dueAtLabel(LaptopLoan $loan): string
    {
        return $loan->isIndefinite()
            ? $this->translator->trans('laptopLoanIndefiniteDurationShortLabel')
            : $loan->getDueAt()->format('d/m/Y');
    }

    public function loanLabel(LaptopLoan $loan): string
    {
        if ($loan->isReturned()) {
            return $this->translator->trans('laptopStatusReturnedLabel');
        }

        return $loan->isOverdue()
            ? $this->translator->trans('laptopStatusOverdueLabel')
            : $this->translator->trans('laptopStatusOnLoanLabel');
    }

    public function loanCssClass(LaptopLoan $loan): string
    {
        if ($loan->isReturned()) {
            return 'bg-secondary-lt';
        }

        return $loan->isOverdue() ? 'bg-red-lt' : 'bg-blue-lt';
    }
}
