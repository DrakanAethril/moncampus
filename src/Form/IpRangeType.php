<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Repository\IpRangeRepository;
use App\Repository\ProxmoxHostRepository;
use App\Service\Network\IpRangeCalculator;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Declaring a network MonCampus may hand addresses out of.
 *
 * The assignable window (`firstUsable`/`lastUsable`) is the most useful pair of fields on the form
 * and is deliberately *not* derived from the CIDR: a /24 holds 254 addresses, but .1 to .49 are the
 * gateway, the switches and everything addressed by hand. Without the window, the console would
 * eventually offer the gateway to a student's virtual machine. The form prefills the arithmetic
 * default and expects it to be narrowed.
 *
 * Validation runs on submit rather than only in the browser, and it checks the things that go wrong
 * *silently*: a gateway outside its own network, a window that escapes the CIDR, an overlap with an
 * already declared range. An overlap discovered afterwards shows up as address conflicts that take
 * weeks to trace back to their cause.
 *
 * Two ranges on the same bridge is **not** an error - it is perfectly legitimate when their VLANs
 * differ, so the screen says so rather than blocking.
 */
class IpRangeType extends AbstractType
{
    public function __construct(
        private readonly IpRangeCalculator $calculator,
        private readonly ProxmoxHostRepository $hostRepository,
        private readonly IpRangeRepository $rangeRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'ipRangeLabelFieldLabel',
                'empty_data' => '',
                'help' => 'ipRangeLabelHelp',
                'attr' => ['placeholder' => 'ipRangeLabelPlaceholder'],
            ])
            ->add('host', EntityType::class, [
                'class' => ProxmoxHost::class,
                'choice_label' => 'label',
                'label' => 'ipRangeHostFieldLabel',
                'help' => 'ipRangeHostHelp',
                // Required, and with a placeholder: this is an input select, not a consultation one.
                'placeholder' => 'ipRangeHostPlaceholder',
                'choices' => $this->hostRepository->findOrdered(),
            ])
            ->add('cidr', TextType::class, [
                'label' => 'ipRangeCidrFieldLabel',
                'empty_data' => '',
                'attr' => ['placeholder' => '10.30.20.0/24', 'class' => 'cm-mono'],
            ])
            ->add('gateway', TextType::class, [
                'label' => 'ipRangeGatewayFieldLabel',
                'empty_data' => '',
                'attr' => ['placeholder' => '10.30.20.1', 'class' => 'cm-mono'],
            ])
            ->add('firstUsable', TextType::class, [
                'label' => 'ipRangeFirstUsableFieldLabel',
                'empty_data' => '',
                'attr' => ['placeholder' => '10.30.20.50', 'class' => 'cm-mono'],
            ])
            ->add('lastUsable', TextType::class, [
                'label' => 'ipRangeLastUsableFieldLabel',
                'empty_data' => '',
                'attr' => ['placeholder' => '10.30.20.249', 'class' => 'cm-mono'],
            ])
            ->add('bridge', TextType::class, [
                'label' => 'ipRangeBridgeFieldLabel',
                'empty_data' => 'vmbr0',
                'help' => 'ipRangeBridgeHelp',
                'attr' => ['placeholder' => 'vmbr0'],
            ])
            ->add('vlan', IntegerType::class, [
                'label' => 'ipRangeVlanFieldLabel',
                'required' => false,
                'help' => 'ipRangeVlanHelp',
                'constraints' => [new Range(min: 1, max: 4094)],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'ipRangeNoteFieldLabel',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
        ;

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validate(...));
    }

    /**
     * The checks that catch what goes wrong quietly. Each names the field it is about, so the
     * message lands where it can be fixed rather than at the top of the form.
     */
    private function validate(FormEvent $event): void
    {
        $form = $event->getForm();
        $range = $event->getData();

        if (!$range instanceof IpRange) {
            return;
        }

        $cidr = $range->getCidr();

        if (!$this->calculator->isValidCidr($cidr)) {
            $form->get('cidr')->addError(new FormError('ipRangeCidrInvalidError'));

            // Everything below is measured against the CIDR, so there is nothing further to say.
            return;
        }

        if (!$this->calculator->contains($cidr, $range->getGateway())) {
            $form->get('gateway')->addError(new FormError('ipRangeGatewayOutsideError'));
        }

        $this->validateWindow($form, $range, $cidr);
        $this->validateOverlap($form, $range, $cidr);
    }

    private function validateWindow(FormInterface $form, IpRange $range, string $cidr): void
    {
        $first = $range->getFirstUsable();
        $last = $range->getLastUsable();

        foreach (['firstUsable' => $first, 'lastUsable' => $last] as $field => $address) {
            if (!$this->calculator->isValidAddress($address)) {
                $form->get($field)->addError(new FormError('ipRangeAddressInvalidError'));

                return;
            }

            if (!$this->calculator->contains($cidr, $address)) {
                $form->get($field)->addError(new FormError('ipRangeBoundOutsideError'));
            }
        }

        if (0 === $this->calculator->capacity($first, $last)) {
            $form->get('lastUsable')->addError(new FormError('ipRangeWindowEmptyError'));
        }

        // The gateway inside the window is the mistake this whole pair of fields exists to prevent,
        // so it is worth its own message rather than being left to the general containment check.
        $gateway = $this->calculator->toLong($range->getGateway());
        $from = $this->calculator->toLong($first);
        $to = $this->calculator->toLong($last);

        if (null !== $gateway && null !== $from && null !== $to && $gateway >= $from && $gateway <= $to) {
            $form->get('firstUsable')->addError(new FormError('ipRangeGatewayInsideWindowError'));
        }
    }

    /**
     * Only the ranges of the same host can collide: a range belongs to one host, and two
     * hypervisors on two networks may perfectly well use the same private addressing.
     */
    private function validateOverlap(FormInterface $form, IpRange $range, string $cidr): void
    {
        $host = $range->getHost();

        if (null === $host) {
            return;
        }

        foreach ($this->rangeRepository->findActiveForHost($host) as $other) {
            if ($other->getId() === $range->getId() || !$this->calculator->overlaps($cidr, $other->getCidr())) {
                continue;
            }

            // Same bridge and a *different* VLAN is a legitimate declaration, not a collision - two
            // networks can share a bridge as long as their tags differ. Anything else overlaps for
            // real, and an overlap found later reads as unexplained address conflicts.
            if ($other->getBridge() === $range->getBridge() && $other->getVlan() !== $range->getVlan()) {
                continue;
            }

            $form->get('cidr')->addError(new FormError('ipRangeOverlapError'));

            return;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IpRange::class,
            // IpRange's constructor demands the six values that make it one, so a fresh row cannot
            // be built by plain reflection - same shape as ProxmoxHostType.
            'empty_data' => static function (FormInterface $form): ?IpRange {
                $host = $form->get('host')->getData();
                $stringOf = static function (string $field) use ($form): string {
                    $value = $form->get($field)->getData();

                    return \is_string($value) ? $value : '';
                };

                if (!$host instanceof ProxmoxHost) {
                    // No host, no range: the field is required and its own error already says so.
                    return null;
                }

                return new IpRange(
                    $stringOf('label'),
                    $host,
                    $stringOf('cidr'),
                    $stringOf('gateway'),
                    $stringOf('firstUsable'),
                    $stringOf('lastUsable'),
                );
            },
        ]);
    }
}
