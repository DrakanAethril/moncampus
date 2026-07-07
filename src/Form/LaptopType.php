<?php

namespace App\Form;

use App\Entity\Laptop;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LaptopType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('assetTag', TextType::class, [
                'label' => 'laptopAssetTagFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('brand', TextType::class, [
                'label' => 'laptopBrandFieldLabel',
                'required' => false,
            ])
            ->add('model', TextType::class, [
                'label' => 'laptopModelFieldLabel',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'laptopNotesFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Laptop::class,
            // Laptop's constructor requires an assetTag, so a fresh entity can't be built via
            // plain reflection - construct it here once the field has actually been submitted.
            'empty_data' => static function (FormInterface $form): Laptop {
                return new Laptop($form->get('assetTag')->getData() ?? '');
            },
        ]);
    }
}
