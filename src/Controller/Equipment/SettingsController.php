<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Entity\EquipmentCategory;
use App\Entity\EquipmentLocation;
use App\Enum\Feature;
use App\Form\EquipmentNameType;
use App\Repository\EquipmentCategoryRepository;
use App\Repository\EquipmentItemRepository;
use App\Repository\EquipmentLocationRepository;
use App\Repository\EquipmentTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion > Matériel > Paramétrage - the categories and the storage places, both a bare name.
 *
 * Deleting either is allowed, and confirmed with what it touches: the types filed under a category
 * become uncategorised, the pieces kept in a place lose their place. Nothing of the journal depends
 * on them, so no history is lost - which is why a deletion is offered here where it is refused for a
 * type.
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class SettingsController extends AbstractController
{
    use EquipmentTrait;

    private const array KINDS = [
        'categories' => EquipmentCategory::class,
        'locations' => EquipmentLocation::class,
    ];

    #[Route(path: '/equipment/settings', name: 'app_equipment_settings', methods: ['GET'])]
    public function index(
        EquipmentCategoryRepository $categories,
        EquipmentLocationRepository $locations,
        EquipmentTypeRepository $types,
        EquipmentItemRepository $items,
    ): Response {
        $locationList = $locations->findAllOrdered();
        $locationUsage = [];
        foreach ($locationList as $location) {
            $locationUsage[(int) $location->getId()] = $types->countForLocation($location) + $items->countForLocation($location);
        }

        return $this->render('equipment/settings.html.twig', [
            'categories' => $categories->findAllOrdered(),
            'categoryUsage' => $types->countByCategory(),
            'locations' => $locationList,
            'locationUsage' => $locationUsage,
            'categoryForm' => $this->nameForm('categories', new EquipmentCategory()),
            'locationForm' => $this->nameForm('locations', new EquipmentLocation()),
        ]);
    }

    #[Route(path: '/equipment/settings/{kind}/new', name: 'app_equipment_settings_new', requirements: ['kind' => 'categories|locations'], methods: ['POST'])]
    public function new(string $kind, Request $request, EntityManagerInterface $entityManager): Response
    {
        $class = self::KINDS[$kind];
        $entity = new $class();
        $form = $this->nameForm($kind, $entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entity);
            $entityManager->flush();
            $this->addFlash('success', 'equipmentSettingsSavedFlashMessage');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_equipment_settings');
    }

    #[Route(path: '/equipment/settings/{kind}/{id}/edit', name: 'app_equipment_settings_edit', requirements: ['kind' => 'categories|locations', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $kind, int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $entity = $entityManager->find(self::KINDS[$kind], $id) ?? throw $this->createNotFoundException();
        $form = $this->createForm(EquipmentNameType::class, $entity, ['data_class' => self::KINDS[$kind]]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'equipmentSettingsSavedFlashMessage');

            return $this->redirectToRoute('app_equipment_settings');
        }

        return $this->render('equipment/settings_edit.html.twig', [
            'form' => $form,
            'kind' => $kind,
            'entity' => $entity,
        ]);
    }

    #[Route(path: '/equipment/settings/{kind}/{id}/delete', name: 'app_equipment_settings_delete', requirements: ['kind' => 'categories|locations', 'id' => '\d+'], methods: ['POST'])]
    public function delete(string $kind, int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $entity = $entityManager->find(self::KINDS[$kind], $id) ?? throw $this->createNotFoundException();
        $this->assertValidEquipmentToken('equipment_settings_delete', $request);

        // The foreign keys are ON DELETE SET NULL: types and pieces simply lose the reference.
        $entityManager->remove($entity);
        $entityManager->flush();
        $this->addFlash('success', 'equipmentSettingsDeletedFlashMessage');

        return $this->redirectToRoute('app_equipment_settings');
    }

    private function nameForm(string $kind, EquipmentCategory|EquipmentLocation $entity): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('equipment_'.$kind, EquipmentNameType::class, $entity, [
            'data_class' => $entity::class,
            'action' => $this->generateUrl('app_equipment_settings_new', ['kind' => $kind]),
            'submit_label' => 'equipmentSettingsAddAction',
        ]);
    }
}
