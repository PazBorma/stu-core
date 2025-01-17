<?php

declare(strict_types=1);

namespace Stu\Module\Ship\Lib\Battle;

use Override;
use Stu\Component\Ship\ShipAlertStateEnum;
use Stu\Component\Ship\System\Exception\InsufficientEnergyException;
use Stu\Component\Ship\System\Exception\ShipSystemException;
use Stu\Component\Ship\System\ShipSystemManagerInterface;
use Stu\Component\Ship\System\ShipSystemTypeEnum;
use Stu\Lib\Information\InformationInterface;
use Stu\Module\Ship\Lib\ShipWrapperInterface;

final class AlertLevelBasedReaction implements AlertLevelBasedReactionInterface
{
    public function __construct(private ShipSystemManagerInterface $shipSystemManager)
    {
    }

    #[Override]
    public function react(ShipWrapperInterface $wrapper, InformationInterface $informations): void
    {
        $ship = $wrapper->get();

        if ($this->changeFromGreenToYellow($wrapper, $informations)) {
            return;
        }

        if ($ship->getAlertState() === ShipAlertStateEnum::ALERT_YELLOW && $this->doAlertYellowReactions($wrapper, $informations)) {
            return;
        }

        if ($ship->getAlertState() === ShipAlertStateEnum::ALERT_RED) {
            if ($this->doAlertYellowReactions($wrapper, $informations)) {
                return;
            }
            $this->doAlertRedReactions($wrapper, $informations);
        }
    }

    private function changeFromGreenToYellow(ShipWrapperInterface $wrapper, InformationInterface $informations): bool
    {
        $ship = $wrapper->get();

        if ($ship->getAlertState() == ShipAlertStateEnum::ALERT_GREEN) {
            try {
                $alertMsg = $wrapper->setAlertState(ShipAlertStateEnum::ALERT_YELLOW);
                $informations->addInformation("- Erhöhung der Alarmstufe wurde durchgeführt, Grün -> Gelb");
                if ($alertMsg !== null) {
                    $informations->addInformation("- " . $alertMsg);
                }
                return true;
            } catch (InsufficientEnergyException) {
                $informations->addInformation("- Nicht genügend Energie vorhanden um auf Alarm-Gelb zu wechseln");
                return true;
            }
        }

        return false;
    }

    private function doAlertYellowReactions(ShipWrapperInterface $wrapper, InformationInterface $informations): bool
    {
        $ship = $wrapper->get();

        if ($ship->getCloakState()) {
            try {
                $this->shipSystemManager->deactivate($wrapper, ShipSystemTypeEnum::SYSTEM_CLOAK);
                $informations->addInformation("- Die Tarnung wurde deaktiviert");
            } catch (ShipSystemException) {
            }

            return true;
        }

        if (!$ship->isTractoring() && !$ship->isTractored()) {
            try {
                $this->shipSystemManager->activate($wrapper, ShipSystemTypeEnum::SYSTEM_SHIELDS);

                $informations->addInformation("- Die Schilde wurden aktiviert");
            } catch (ShipSystemException) {
            }
        } else {
            $informations->addInformation("- Die Schilde konnten wegen aktiviertem Traktorstrahl nicht aktiviert werden");
        }
        try {
            $this->shipSystemManager->activate($wrapper, ShipSystemTypeEnum::SYSTEM_NBS);

            $informations->addInformation("- Die Nahbereichssensoren wurden aktiviert");
        } catch (ShipSystemException) {
        }

        try {
            $this->shipSystemManager->activate($wrapper, ShipSystemTypeEnum::SYSTEM_PHASER);

            $informations->addInformation("- Die Energiewaffe wurde aktiviert");
        } catch (ShipSystemException) {
        }

        return false;
    }

    private function doAlertRedReactions(ShipWrapperInterface $wrapper, InformationInterface $informations): void
    {
        try {
            $this->shipSystemManager->activate($wrapper, ShipSystemTypeEnum::SYSTEM_TORPEDO);

            $informations->addInformation("- Der Torpedowerfer wurde aktiviert");
        } catch (ShipSystemException) {
        }
    }
}
