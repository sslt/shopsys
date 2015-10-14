<?php

namespace SS6\ShopBundle\Controller\Admin;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SS6\ShopBundle\Component\ConfirmDelete\ConfirmDeleteResponseFactory;
use SS6\ShopBundle\Component\Controller\AdminBaseController;
use SS6\ShopBundle\Component\Router\Security\Annotation\CsrfProtection;
use SS6\ShopBundle\Component\Translation\Translator;
use SS6\ShopBundle\Form\Admin\Product\Availability\AvailabilitySettingFormType;
use SS6\ShopBundle\Model\Product\Availability\AvailabilityFacade;
use SS6\ShopBundle\Model\Product\Availability\AvailabilityInlineEdit;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AvailabilityController extends AdminBaseController {

	/**
	 * @var \SS6\ShopBundle\Component\ConfirmDelete\ConfirmDeleteResponseFactory
	 */
	private $confirmDeleteResponseFactory;

	/**
	 * @var \SS6\ShopBundle\Model\Product\Availability\AvailabilityFacade
	 */
	private $availabilityFacade;

	/**
	 * @var \SS6\ShopBundle\Model\Product\Availability\AvailabilityInlineEdit
	 */
	private $availabilityInlineEdit;

	/**
	 * @var \Symfony\Component\Translation\Translator
	 */
	private $translator;

	public function __construct(
		Translator $translator,
		AvailabilityFacade $availabilityFacade,
		AvailabilityInlineEdit $availabilityInlineEdit,
		ConfirmDeleteResponseFactory $confirmDeleteResponseFactory
	) {
		$this->translator = $translator;
		$this->availabilityFacade = $availabilityFacade;
		$this->availabilityInlineEdit = $availabilityInlineEdit;
		$this->confirmDeleteResponseFactory = $confirmDeleteResponseFactory;
	}

	/**
	 * @Route("/product/availability/list/")
	 */
	public function listAction() {
		$grid = $this->availabilityInlineEdit->getGrid();

		return $this->render('@SS6Shop/Admin/Content/Availability/list.html.twig', [
			'gridView' => $grid->createView(),
		]);
	}

	/**
	 * @Route("/product/availability/delete/{id}", requirements={"id" = "\d+"})
	 * @CsrfProtection
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param int $id
	 */
	public function deleteAction(Request $request, $id) {
		$newId = $request->get('newId');

		try {
			$fullName = $this->availabilityFacade->getById($id)->getName();
			$this->transactional(
				function () use ($id, $newId) {
					$this->availabilityFacade->deleteById($id, $newId);
				}
			);

			if ($newId === null) {
				$this->getFlashMessageSender()->addSuccessFlashTwig('Dostupnost <strong>{{ name }}</strong> byla smazána', [
					'name' => $fullName,
				]);
			} else {
				$newAvailability = $this->availabilityFacade->getById($newId);
				$this->getFlashMessageSender()->addSuccessFlashTwig('Dostupnost <strong>{{ oldName }}</strong> byla nahrazena dostupností'
					. ' <strong>{{ newName }}</strong> a byla smazána.',
					[
						'oldName' => $fullName,
						'newName' => $newAvailability->getName(),
					]);
			}

		} catch (\SS6\ShopBundle\Model\Product\Availability\Exception\AvailabilityNotFoundException $ex) {
			$this->getFlashMessageSender()->addErrorFlash('Zvolená dostupnost neexistuje.');
		}

		return $this->redirectToRoute('admin_availability_list');
	}

	/**
	 * @Route("/product/availability/delete_confirm/{id}", requirements={"id" = "\d+"})
	 * @param int $id
	 */
	public function deleteConfirmAction($id) {
		try {
			$availability = $this->availabilityFacade->getById($id);
			$isAvailabilityDefault = $this->availabilityFacade->isAvailabilityDefault($availability);
			if ($this->availabilityFacade->isAvailabilityUsed($availability) || $isAvailabilityDefault) {
				if ($isAvailabilityDefault) {
					$message = $this->translator->trans(
						'Dostupnost "%name%" je nastavena jako výchozí. '
						. 'Pro její odstranění musíte zvolit, která se má všude, '
						. 'kde je aktuálně používaná, nastavit.' . "\n\n" . 'Jakou dostupnost místo ní chcete nastavit?',
						['%name%' => $availability->getName()]
					);
				} else {
					$message = $this->translator->trans(
						'Jelikož dostupnost "%name%" je používána ještě u některých produktů, '
						. 'musíte zvolit, jaká dostupnost bude použita místo ní. Jakou dostupnost chcete těmto produktům nastavit?',
						['%name%' => $availability->getName()]
					);
				}
				$availabilityNamesById = [];
				foreach ($this->availabilityFacade->getAllExceptId($id) as $newAvailabilty) {
					$availabilityNamesById[$newAvailabilty->getId()] = $newAvailabilty->getName();
				}

				return $this->confirmDeleteResponseFactory->createSetNewAndDeleteResponse(
					$message,
					'admin_availability_delete',
					$id,
					$availabilityNamesById
				);
			} else {
				$message = $this->translator->trans(
					'Opravdu si přejete trvale odstranit dostupnost "%name%"? Nikde není použitá.',
					['%name%' => $availability->getName()]
				);

				return $this->confirmDeleteResponseFactory->createDeleteResponse($message, 'admin_availability_delete', $id);
			}
		} catch (\SS6\ShopBundle\Model\Product\Availability\Exception\AvailabilityNotFoundException $ex) {
			return new Response($this->translator->trans('Zvolená dostupnost neexistuje'));
		}
	}

	/**
	 * @Route("/product/availability/setting/")
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 */
	public function settingAction(Request $request) {
		$availabilities = $this->availabilityFacade->getAll();
		$form = $this->createForm(new AvailabilitySettingFormType($availabilities));

		$availabilitySettingsFormData = [];
		$availabilitySettingsFormData['defaultInStockAvailability'] = $this->availabilityFacade->getDefaultInStockAvailability();

		$form->setData($availabilitySettingsFormData);

		$form->handleRequest($request);

		if ($form->isValid()) {
			$availabilitySettingsFormData = $form->getData();
			$this->transactional(
				function () use ($availabilitySettingsFormData) {
					$this->availabilityFacade->setDefaultInStockAvailability($availabilitySettingsFormData['defaultInStockAvailability']);
				}
			);
			$this->getFlashMessageSender()->addSuccessFlash('Nastavení výchozí dostupnosti pro zboží skladem bylo upraveno');

			return $this->redirectToRoute('admin_availability_list');
		}

		return $this->render('@SS6Shop/Admin/Content/Availability/setting.html.twig', [
			'form' => $form->createView(),
		]);
	}

}
