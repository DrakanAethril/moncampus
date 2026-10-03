<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\OnlineCoursePage;
use App\Service\OnlineCourse\OnlineCourseImageStore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * « Ma page »: the address, the banner (its title, the title's colour, the background colour and an
 * optional picture) and the few lines of presentation of a teacher's public page
 * (design/validated/cours-en-ligne.md, §5 and §8).
 *
 * Not mapped onto the entity: the address does not go through a setter but through
 * App\Service\OnlineCourse\OnlineCoursePageHandles, which is what keeps the one being left
 * redirecting - and the same form serves the day the page does not exist yet. The banner picture is a
 * file the controller hands to App\Service\OnlineCourse\OnlineCourseImageStore once the page has an
 * id to file it under.
 */
class OnlineCoursePageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('handle', TextType::class, [
                'label' => 'onlineCoursePageHandleFieldLabel',
                'help' => 'onlineCoursePageHandleFieldHelp',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'onlineCourseHandleFormatMessage')],
                'attr' => ['maxlength' => 60, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('title', TextType::class, [
                'label' => 'onlineCoursePageTitleFieldLabel',
                'help' => 'onlineCoursePageTitleFieldHelp',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'onlineCoursePageTitleRequiredMessage'), new Length(max: 150)],
                'attr' => ['maxlength' => 150],
            ])
            ->add('titleColor', ColorType::class, [
                'label' => 'onlineCoursePageTitleColorFieldLabel',
                'constraints' => [new Regex('/^#[0-9a-fA-F]{6}$/')],
            ])
            ->add('bannerColor', ColorType::class, [
                'label' => 'onlineCoursePageBannerColorFieldLabel',
                'constraints' => [new Regex('/^#[0-9a-fA-F]{6}$/')],
            ])
            ->add('bannerHeight', IntegerType::class, [
                'label' => 'onlineCoursePageBannerHeightFieldLabel',
                'help' => 'onlineCoursePageBannerHeightFieldHelp',
                'help_translation_parameters' => ['%default%' => OnlineCoursePage::DEFAULT_BANNER_HEIGHT, '%min%' => OnlineCoursePage::MIN_BANNER_HEIGHT, '%max%' => OnlineCoursePage::MAX_BANNER_HEIGHT],
                'constraints' => [new NotNull(), new Range(min: OnlineCoursePage::MIN_BANNER_HEIGHT, max: OnlineCoursePage::MAX_BANNER_HEIGHT)],
                'attr' => ['min' => OnlineCoursePage::MIN_BANNER_HEIGHT, 'max' => OnlineCoursePage::MAX_BANNER_HEIGHT, 'step' => 10],
            ])
            ->add('bannerImage', FilePickerType::class, [
                'label' => 'onlineCoursePageBannerImageFieldLabel',
                'help' => 'onlineCoursePageBannerImageFieldHelp',
                'required' => false,
                'policy' => OnlineCourseImageStore::policy(),
                // Copied into the page's own folder, like a course's picture (OnlineCourseImageStore).
                'library' => true,
            ])
            ->add('removeBannerImage', CheckboxType::class, [
                'label' => 'onlineCoursePageRemoveBannerImageFieldLabel',
                'required' => false,
            ])
            ->add('introduction', TextareaType::class, [
                'label' => 'onlineCoursePageIntroductionFieldLabel',
                'required' => false,
            ]);
    }
}
