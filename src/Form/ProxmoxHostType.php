<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProxmoxHost;
use App\Enum\ProxmoxCredentialKind;
use App\Enum\ProxmoxTlsMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Declaring a hypervisor: identification, the operating credentials, the optional provisioning
 * ones, the TLS policy, and the perimeter.
 *
 * The two secret fields are `mapped: false`, and that is the whole of point 2 of "never visible
 * again": they are never prefilled, so editing a host shows an empty box with "leave blank to
 * keep", and the only way to change a secret is to retype it in full. The controller is what turns
 * a non-empty submission into a sealed column - the entity has no setter that takes plaintext.
 *
 * Deleting a machine is not a checkbox that happens to be off. It is not a field at all, here or
 * anywhere; the template draws it disabled purely so the question "and if I want to delete one?"
 * gets its answer where it is asked.
 */
class ProxmoxHostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'proxmoxHostLabelFieldLabel',
                'empty_data' => '',
                'attr' => ['placeholder' => 'proxmoxHostLabelPlaceholder'],
            ])
            ->add('hostname', TextType::class, [
                'label' => 'proxmoxHostHostnameFieldLabel',
                'empty_data' => '',
                'help' => 'proxmoxHostHostnameHelp',
                'attr' => ['placeholder' => 'proxmoxHostHostnamePlaceholder'],
            ])
            ->add('port', IntegerType::class, [
                'label' => 'proxmoxHostPortFieldLabel',
                'empty_data' => (string) ProxmoxHost::DEFAULT_PORT,
                'constraints' => [new Range(min: 1, max: 65535)],
            ])
            ->add('credentialKind', EnumType::class, [
                'class' => ProxmoxCredentialKind::class,
                'choice_label' => static fn (ProxmoxCredentialKind $kind): string => $kind->labelKey(),
                'expanded' => true,
                'label' => 'proxmoxHostCredentialKindFieldLabel',
            ])
            ->add('username', TextType::class, [
                'label' => 'proxmoxHostUsernameFieldLabel',
                'empty_data' => '',
                'help' => 'proxmoxHostUsernameHelp',
                'attr' => ['placeholder' => 'svc-moncampus'],
            ])
            ->add('realm', TextType::class, [
                'label' => 'proxmoxHostRealmFieldLabel',
                'empty_data' => 'pve',
                'attr' => ['placeholder' => 'pve'],
            ])
            ->add('tokenName', TextType::class, [
                'label' => 'proxmoxHostTokenNameFieldLabel',
                'required' => false,
                'help' => 'proxmoxHostTokenNameHelp',
                'attr' => ['placeholder' => 'moncampus'],
            ])
            // Point 2 of "never visible again": unmapped, so nothing can prefill it, and the
            // browser is told not to remember it either. PasswordType additionally clears the
            // submitted value from the rendered form when validation sends the page back.
            ->add('secret', PasswordType::class, [
                'label' => 'proxmoxHostSecretFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => 'proxmoxHostSecretHelp',
                'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'proxmoxHostSecretPlaceholder'],
            ])
            ->add('allowCreate', CheckboxType::class, [
                'label' => 'proxmoxHostAllowCreateFieldLabel',
                'required' => false,
                'help' => 'proxmoxHostAllowCreateHelp',
            ])
            ->add('provisionUsername', TextType::class, [
                'label' => 'proxmoxHostProvisionUsernameFieldLabel',
                'required' => false,
                'attr' => ['placeholder' => 'svc-moncampus-provision'],
            ])
            ->add('provisionRealm', TextType::class, [
                'label' => 'proxmoxHostProvisionRealmFieldLabel',
                'required' => false,
                'attr' => ['placeholder' => 'pve'],
            ])
            ->add('provisionTokenName', TextType::class, [
                'label' => 'proxmoxHostProvisionTokenNameFieldLabel',
                'required' => false,
                'attr' => ['placeholder' => 'moncampus'],
            ])
            ->add('provisionSecret', PasswordType::class, [
                'label' => 'proxmoxHostProvisionSecretFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => 'proxmoxHostSecretHelp',
                'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'proxmoxHostSecretPlaceholder'],
            ])
            ->add('tlsMode', EnumType::class, [
                'class' => ProxmoxTlsMode::class,
                'choice_label' => static fn (ProxmoxTlsMode $mode): string => $mode->labelKey(),
                'expanded' => true,
                'label' => 'proxmoxHostTlsModeFieldLabel',
            ])
            ->add('tlsCaPem', TextareaType::class, [
                'label' => 'proxmoxHostTlsCaFieldLabel',
                'required' => false,
                'help' => 'proxmoxHostTlsCaHelp',
                'attr' => ['rows' => 6, 'placeholder' => '-----BEGIN CERTIFICATE-----'],
            ])
            // Named "public-key pin", never "fingerprint", and read-only in the template: the
            // value that has to go here is the SPKI digest the connection test offers, not the
            // certificate fingerprint Proxmox displays. Nothing invites anyone to type it.
            ->add('tlsPinSha256', TextType::class, [
                'label' => 'proxmoxHostTlsPinFieldLabel',
                'required' => false,
                'help' => 'proxmoxHostTlsPinHelp',
                'constraints' => [new Length(max: 64)],
            ])
            ->add('managedPool', TextType::class, [
                'label' => 'proxmoxHostManagedPoolFieldLabel',
                'required' => false,
                'help' => 'proxmoxHostManagedPoolHelp',
                'attr' => ['placeholder' => 'moncampus'],
            ])
            ->add('vmidMin', IntegerType::class, [
                'label' => 'proxmoxHostVmidMinFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
                'attr' => ['placeholder' => '200'],
            ])
            ->add('vmidMax', IntegerType::class, [
                'label' => 'proxmoxHostVmidMaxFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
                'attr' => ['placeholder' => '299'],
            ])
            ->add('allowStart', CheckboxType::class, [
                'label' => 'proxmoxHostAllowStartFieldLabel',
                'required' => false,
            ])
            ->add('allowStop', CheckboxType::class, [
                'label' => 'proxmoxHostAllowStopFieldLabel',
                'required' => false,
            ])
            ->add('maxGuests', IntegerType::class, [
                'label' => 'proxmoxHostMaxGuestsFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
            ])
            ->add('maxCores', IntegerType::class, [
                'label' => 'proxmoxHostMaxCoresFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
            ])
            ->add('maxMemoryMib', IntegerType::class, [
                'label' => 'proxmoxHostMaxMemoryFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
            ])
            ->add('maxDiskGib', IntegerType::class, [
                'label' => 'proxmoxHostMaxDiskFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProxmoxHost::class,
            // ProxmoxHost's constructor demands the four values that identify it, so a fresh row
            // cannot be built by plain reflection - same shape as LessonTypeType.
            'empty_data' => static function (FormInterface $form): ProxmoxHost {
                $stringOf = static function (string $field) use ($form): string {
                    $value = $form->get($field)->getData();

                    return \is_string($value) ? $value : '';
                };

                return new ProxmoxHost(
                    $stringOf('label'),
                    $stringOf('hostname'),
                    $stringOf('username'),
                    '' !== $stringOf('realm') ? $stringOf('realm') : 'pve',
                );
            },
        ]);
    }
}
