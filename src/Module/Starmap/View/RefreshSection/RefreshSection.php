<?php

declare(strict_types=1);

namespace Stu\Module\Starmap\View\RefreshSection;

use Stu\Exception\SanityCheckException;
use Stu\Module\Control\GameControllerInterface;
use Stu\Module\Control\ViewControllerInterface;
use Stu\Module\Starmap\Lib\StarmapUiFactoryInterface;
use Stu\Module\Starmap\View\ShowSection\ShowSectionRequestInterface;
use Stu\Orm\Repository\LayerRepositoryInterface;

final class RefreshSection implements ViewControllerInterface
{
    public const VIEW_IDENTIFIER = 'REFRESH_SECTION';

    private ShowSectionRequestInterface $request;

    private LayerRepositoryInterface $layerRepository;

    private StarmapUiFactoryInterface $starmapUiFactory;

    public function __construct(
        ShowSectionRequestInterface $request,
        StarmapUiFactoryInterface $starmapUiFactory,
        LayerRepositoryInterface $layerRepository
    ) {
        $this->request = $request;
        $this->layerRepository = $layerRepository;
        $this->starmapUiFactory = $starmapUiFactory;
    }

    public function handle(GameControllerInterface $game): void
    {
        $layerId = $this->request->getLayerId();
        $layer = $this->layerRepository->find($layerId);

        $section = $this->request->getSection();

        //sanity check if user knows layer
        if (!$game->getUser()->hasSeen($layer->getId())) {
            throw new SanityCheckException('user tried to access unseen layer');
        }

        $game->showMacro('html/starmapSectionTable.twig', true);

        $helper = $this->starmapUiFactory->createMapSectionHelper();
        $helper->setTemplateVars(
            $game,
            $layer,
            $section,
            $this->request->getDirection()
        );
    }
}
