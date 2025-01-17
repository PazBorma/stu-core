<?php

declare(strict_types=1);

namespace Stu\Module\Ship\Lib\Movement\Component\Consequence\Flight;

use Override;
use Stu\Component\Ship\System\Utility\TractorMassPayloadUtilInterface;
use Stu\Module\Ship\Lib\CancelColonyBlockOrDefendInterface;
use Stu\Module\Ship\Lib\Message\MessageCollectionInterface;
use Stu\Module\Ship\Lib\Message\MessageFactoryInterface;
use Stu\Module\Ship\Lib\Movement\Component\Consequence\AbstractFlightConsequence;
use Stu\Module\Ship\Lib\Movement\Route\FlightRouteInterface;
use Stu\Module\Ship\Lib\ShipWrapperInterface;

class TractorConsequence extends AbstractFlightConsequence
{
    public function __construct(
        private TractorMassPayloadUtilInterface $tractorMassPayloadUtil,
        private CancelColonyBlockOrDefendInterface $cancelColonyBlockOrDefend,
        private MessageFactoryInterface $messageFactory
    ) {}

    #[Override]
    protected function triggerSpecific(
        ShipWrapperInterface $wrapper,
        FlightRouteInterface $flightRoute,
        MessageCollectionInterface $messages
    ): void {

        $ship = $wrapper->get();

        $tractoredShip = $ship->getTractoredShip();
        if ($tractoredShip === null) {
            return;
        }

        $message = $this->messageFactory->createMessage();
        $messages->add($message);

        //can tow tractored ship?
        $canTowTractoredShip = $this->tractorMassPayloadUtil->tryToTow($wrapper, $tractoredShip, $message);
        if ($canTowTractoredShip) {
            $this->cancelColonyBlockOrDefend->work($ship, $message, true);
        }
    }
}
