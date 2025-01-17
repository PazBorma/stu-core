<?php

namespace Stu\Module\Tick\Ship;

use Override;
use RuntimeException;
use Stu\Component\Faction\FactionEnum;
use Stu\Component\Ship\AstronomicalMappingEnum;
use Stu\Component\Ship\Repair\RepairUtilInterface;
use Stu\Component\Ship\ShipAlertStateEnum;
use Stu\Component\Ship\ShipStateEnum;
use Stu\Component\Ship\Storage\ShipStorageManagerInterface;
use Stu\Component\Ship\System\Data\EpsSystemData;
use Stu\Component\Ship\System\ShipSystemManagerInterface;
use Stu\Component\Ship\System\ShipSystemTypeEnum;
use Stu\Component\Station\StationUtilityInterface;
use Stu\Module\Control\GameControllerInterface;
use Stu\Module\Commodity\CommodityTypeEnum;
use Stu\Module\Database\Lib\CreateDatabaseEntryInterface;
use Stu\Module\Logging\LoggerUtilFactoryInterface;
use Stu\Module\Logging\LoggerUtilInterface;
use Stu\Module\Message\Lib\PrivateMessageFolderTypeEnum;
use Stu\Module\Message\Lib\PrivateMessageSenderInterface;
use Stu\Module\PlayerSetting\Lib\UserEnum;
use Stu\Module\Ship\Lib\AstroEntryLibInterface;
use Stu\Module\Ship\Lib\Crew\ShipLeaverInterface;
use Stu\Module\Ship\Lib\Interaction\ShipTakeoverManagerInterface;
use Stu\Module\Ship\Lib\Interaction\TrackerDeviceManagerInterface;
use Stu\Module\Ship\Lib\ReactorWrapperInterface;
use Stu\Module\Ship\Lib\ShipWrapperFactoryInterface;
use Stu\Module\Ship\Lib\ShipWrapperInterface;
use Stu\Module\Ship\View\ShowShip\ShowShip;
use Stu\Module\Tick\Ship\ManagerComponent\ManagerComponentInterface;
use Stu\Orm\Entity\DatabaseEntryInterface;
use Stu\Orm\Entity\ShipInterface;
use Stu\Orm\Repository\DatabaseUserRepositoryInterface;
use Stu\Orm\Repository\ShipRepositoryInterface;
use Stu\Orm\Repository\LocationMiningRepositoryInterface;
use Stu\Orm\Repository\CommodityRepositoryInterface;
use Stu\Orm\Repository\StorageRepositoryInterface;

final class ShipTick implements ShipTickInterface, ManagerComponentInterface
{
    private LoggerUtilInterface $loggerUtil;

    /**
     * @var array<string>
     */
    private array $msg = [];

    public function __construct(
        private ShipWrapperFactoryInterface $shipWrapperFactory,
        private PrivateMessageSenderInterface $privateMessageSender,
        private ShipRepositoryInterface $shipRepository,
        private ShipSystemManagerInterface $shipSystemManager,
        private ShipLeaverInterface $shipLeaver,
        private GameControllerInterface $game,
        private AstroEntryLibInterface $astroEntryLib,
        private DatabaseUserRepositoryInterface $databaseUserRepository,
        private CreateDatabaseEntryInterface $createDatabaseEntry,
        private StationUtilityInterface $stationUtility,
        private RepairUtilInterface $repairUtil,
        private ShipTakeoverManagerInterface $shipTakeoverManager,
        private TrackerDeviceManagerInterface $trackerDeviceManager,
        private ShipStorageManagerInterface $shipStorageManager,
        private LocationMiningRepositoryInterface $locationMiningRepository,
        private CommodityRepositoryInterface $commodityRepository,
        private StorageRepositoryInterface $storageRepository,
        LoggerUtilFactoryInterface $loggerUtilFactory
    ) {
        $this->loggerUtil = $loggerUtilFactory->getLoggerUtil(true);
    }

    #[Override]
    public function work(): void
    {
        foreach ($this->shipRepository->getPlayerShipsForTick() as $ship) {
            $this->workShip($this->shipWrapperFactory->wrapShip($ship));
        }
    }

    #[Override]
    public function workShip(ShipWrapperInterface $wrapper): void
    {
        $ship = $wrapper->get();

        $startTime = microtime(true);

        // do construction stuff
        if ($this->doConstructionStuff($ship)) {
            $this->shipRepository->save($ship);
            $this->sendMessages($ship);
            return;
        }

        $this->potentialLog($ship, "marker0", $startTime);


        $startTime = microtime(true);
        // repair station
        if ($ship->isBase() && $ship->getState() === ShipStateEnum::SHIP_STATE_REPAIR_PASSIVE) {
            $this->doRepairStation($wrapper);
        }
        $this->potentialLog($ship, "marker1", $startTime);

        $startTime = microtime(true);
        // leave ship
        if ($ship->getCrewCount() > 0 && !$ship->isSystemHealthy(ShipSystemTypeEnum::SYSTEM_LIFE_SUPPORT)) {
            $this->msg[] = _('Die Lebenserhaltung ist ausgefallen:');
            $this->msg[] = $this->shipLeaver->evacuate($wrapper);
            $this->sendMessages($ship);
            $this->potentialLog($ship, "marker2", $startTime);
            return;
        }

        $startTime = microtime(true);
        $eps = $wrapper->getEpsSystemData();
        $reactor = $wrapper->getReactorWrapper();
        if ($eps === null) {
            $this->potentialLog($ship, "marker3", $startTime);
            return;
        }

        $startTime = microtime(true);
        $hasEnoughCrew = $ship->hasEnoughCrew();

        // not enough crew
        if (!$hasEnoughCrew) {
            $this->msg[] = _('Zu wenig Crew an Bord, Schiff ist nicht voll funktionsfähig! Systeme werden deaktiviert!');

            //deactivate all systems except life support
            foreach ($this->shipSystemManager->getActiveSystems($ship) as $system) {
                if ($system->getSystemType() != ShipSystemTypeEnum::SYSTEM_LIFE_SUPPORT) {
                    $this->shipSystemManager->deactivate($wrapper, $system->getSystemType(), true);
                }
            }
        }
        $this->potentialLog($ship, "marker4", $startTime);

        $startTime = microtime(true);
        $reactorUsageForWarpdrive = $this->loadWarpdrive(
            $wrapper,
            $hasEnoughCrew
        );
        $this->potentialLog($ship, "marker5", $startTime);

        $startTime = microtime(true);
        $availableEps = $this->getAvailableEps(
            $wrapper,
            $eps,
            $reactor,
            $hasEnoughCrew,
            $reactorUsageForWarpdrive
        );
        $this->potentialLog($ship, "marker6", $startTime);

        $startTime = microtime(true);
        //try to save energy by reducing alert state
        if ($wrapper->getEpsUsage() > $availableEps) {
            $malus = $wrapper->getEpsUsage() - $availableEps;
            $alertUsage = $ship->getAlertState()->value - 1;

            if ($alertUsage > 0) {
                $preState = $ship->getAlertState();
                $reduce = (int)min($malus, $alertUsage);

                $ship->setAlertState(ShipAlertStateEnum::from($preState->value - $reduce));
                $this->msg[] = sprintf(
                    _('Wechsel von %s auf %s wegen Energiemangel'),
                    $preState->getDescription(),
                    $ship->getAlertState()->getDescription()
                );
            }
        }
        $this->potentialLog($ship, "marker7", $startTime);

        $startTime = microtime(true);
        //try to save energy by deactivating systems from low to high priority
        if ($wrapper->getEpsUsage() > $availableEps) {
            $activeSystems = $this->shipSystemManager->getActiveSystems($ship, true);

            foreach ($activeSystems as $system) {
                $energyConsumption = $this->shipSystemManager->getEnergyConsumption($system->getSystemType());
                if ($energyConsumption < 1) {
                    continue;
                }

                //echo "- eps: ".$eps." - usage: ".$wrapper->getEpsUsage()."\n";
                if ($availableEps - $wrapper->getEpsUsage() - $energyConsumption < 0) {
                    //echo "-- hit system: ".$system->getDescription()."\n";

                    $this->shipSystemManager->deactivate($wrapper, $system->getSystemType(), true);

                    $wrapper->lowerEpsUsage($energyConsumption);
                    $this->msg[] = $system->getSystemType()->getDescription() . ' deaktiviert wegen Energiemangel';

                    if ($ship->getCrewCount() > 0 && $system->getSystemType() == ShipSystemTypeEnum::SYSTEM_LIFE_SUPPORT) {
                        $this->msg[] = _('Die Lebenserhaltung ist ausgefallen:');
                        $this->msg[] = $this->shipLeaver->evacuate($wrapper);
                        $this->sendMessages($ship);
                        return;
                    }
                }
                if ($wrapper->getEpsUsage() <= $availableEps) {
                    break;
                }
            }
        }

        $this->potentialLog($ship, "marker8", $startTime);
        $startTime = microtime(true);

        $newEps = $availableEps - $wrapper->getEpsUsage();
        $batteryReload = $ship->isBase()
            && $eps->reloadBattery()
            && $newEps > $eps->getEps()
            ? min(
                (int) ceil($eps->getMaxBattery() / 10),
                $newEps - $eps->getEps(),
                $eps->getMaxBattery() - $eps->getBattery()
            ) : 0;

        $newEps -= $batteryReload;
        if ($newEps > $eps->getMaxEps()) {
            $newEps = $eps->getMaxEps();
        }


        $usedEnergy = $wrapper->getEpsUsage() + $batteryReload + ($newEps - $eps->getEps()) + $reactorUsageForWarpdrive;

        //echo "--- Generated Id ".$ship->getId()." - eps: ".$eps." - usage: ".$wrapper->getEpsUsage()." - old eps: ".$ship->getEps()." - wk: ".$wkuse."\n";
        $eps->setEps($newEps)
            ->setBattery($eps->getBattery() + $batteryReload)
            ->update();

        if ($usedEnergy > 0 && $reactor !== null) {
            $reactor->changeLoad(-$usedEnergy);
        }

        $this->potentialLog($ship, "marker9", $startTime);

        $startTime = microtime(true);
        $this->checkForFinishedTakeover($ship);
        $this->potentialLog($ship, "marker10", $startTime);

        $startTime = microtime(true);
        $this->checkForFinishedAstroMapping($wrapper);
        $this->potentialLog($ship, "marker11", $startTime);

        //update tracker status
        $startTime = microtime(true);
        $this->doTrackerDeviceStuff($wrapper);
        $this->potentialLog($ship, "marker12", $startTime);

        $startTime = microtime(true);
        $this->shipRepository->save($ship);
        $this->potentialLog($ship, "marker13", $startTime);

        $startTime = microtime(true);
        $this->sendMessages($ship);
        $this->potentialLog($ship, "marker14", $startTime);

        $startTime = microtime(true);
        $this->doBussardCollectorStuff($wrapper);
        $this->potentialLog($ship, "marker15", $startTime);

        $startTime = microtime(true);
        $this->doAggregationSystemStuff($wrapper);
        $this->potentialLog($ship, "marker16", $startTime);
    }

    private function potentialLog(ShipInterface $ship, string $marker, float $startTime): void
    {
        $endTime = microtime(true);

        if (
            $endTime - $startTime > 0.01
        ) {
            $this->loggerUtil->log(sprintf(
                "\t\t\t%s of %d, seconds: %F",
                $marker,
                $ship->getId(),
                $endTime - $startTime
            ));
        }
    }

    private function getAvailableEps(
        ShipWrapperInterface $wrapper,
        EpsSystemData $eps,
        ?ReactorWrapperInterface $reactor,
        bool $hasEnoughCrew,
        int $reactorUsageForWarpdrive
    ): int {
        if ($hasEnoughCrew && $reactor !== null) {

            return $eps->getEps() + $reactor->getEpsProduction() +  $this->getCarryOver(
                $wrapper,
                $reactor,
                $reactorUsageForWarpdrive
            );
        }

        return $eps->getEps();
    }

    private function getCarryOver(
        ShipWrapperInterface $wrapper,
        ReactorWrapperInterface $reactor,
        int $reactorUsageForWarpdrive
    ): int {
        $warpdrive = $wrapper->getWarpDriveSystemData();
        if ($warpdrive === null || !$warpdrive->getAutoCarryOver()) {
            return 0;
        }

        return $reactor->getOutputCappedByLoad() - $reactor->getEpsProduction() - $reactorUsageForWarpdrive;
    }

    private function loadWarpdrive(ShipWrapperInterface $wrapper, bool $hasEnoughCrew): int
    {
        if (!$hasEnoughCrew) {
            return 0;
        }

        $reactor = $wrapper->getReactorWrapper();
        $warpdrive = $wrapper->getWarpDriveSystemData();
        if ($warpdrive === null || $reactor === null) {
            return 0;
        }

        $effectiveWarpdriveProduction = $reactor->getEffectiveWarpDriveProduction();
        if ($effectiveWarpdriveProduction === 0) {
            return 0;
        }

        $currentLoad = $warpdrive->getWarpDrive();

        $warpdrive->setWarpDrive($currentLoad + $effectiveWarpdriveProduction)->update();

        return $effectiveWarpdriveProduction * $wrapper->get()->getRump()->getFlightECost();
    }

    private function doConstructionStuff(ShipInterface $ship): bool
    {
        $progress =  $this->stationUtility->getConstructionProgress($ship);

        if ($progress === null) {
            return false;
        }

        if ($progress->getRemainingTicks() === 0) {
            return false;
        }

        $isUnderConstruction = $ship->getState() === ShipStateEnum::SHIP_STATE_UNDER_CONSTRUCTION;

        if (!$this->stationUtility->hasEnoughDockedWorkbees($ship, $ship->getRump())) {
            $neededWorkbees = $isUnderConstruction ? $ship->getRump()->getNeededWorkbees() :
                (int)ceil($ship->getRump()->getNeededWorkbees() / 2);

            $this->msg[] = sprintf(
                _('Nicht genügend Workbees (%d/%d) angedockt um %s weiterführen zu können'),
                $this->stationUtility->getDockedWorkbeeCount($ship),
                $neededWorkbees,
                $isUnderConstruction ? 'den Bau' : 'die Demontage'
            );
            return true;
        }

        if ($progress->getRemainingTicks() === 1) {
            if ($isUnderConstruction) {
                $this->stationUtility->finishStation($ship, $progress);
            } else {
                $this->stationUtility->finishScrapping($ship, $progress);
            }

            $this->msg[] = sprintf(
                _('%s: %s bei %s fertiggestellt'),
                $ship->getRump()->getName(),
                $isUnderConstruction ? 'Bau' : 'Demontage',
                $ship->getSectorString()
            );
        } else {
            $this->stationUtility->reduceRemainingTicks($progress);

            if ($isUnderConstruction) {
                // raise hull
                $increase = intdiv($ship->getMaxHull(), 2 * $ship->getRump()->getBuildtime());
                $ship->setHuell($ship->getHull() + $increase);
            }
        }

        return true;
    }

    private function doRepairStation(ShipWrapperInterface $wrapper): void
    {
        $station = $wrapper->get();

        if (!$this->stationUtility->hasEnoughDockedWorkbees($station, $station->getRump())) {
            $neededWorkbees = (int)ceil($station->getRump()->getNeededWorkbees() / 5);

            $this->msg[] = sprintf(
                _('Nicht genügend Workbees (%d/%d) angedockt um die Reparatur weiterführen zu können'),
                $this->stationUtility->getDockedWorkbeeCount($station),
                $neededWorkbees
            );
            return;
        }

        $neededParts = $this->repairUtil->determineSpareParts($wrapper, true);

        // parts stored?
        if (!$this->repairUtil->enoughSparePartsOnEntity($neededParts, $station, $station)) {
            return;
        }

        //repair hull
        $station->setHuell($station->getHull() + $station->getRepairRate());
        if ($station->getHull() > $station->getMaxHull()) {
            $station->setHuell($station->getMaxHull());
        }

        //repair station systems
        $damagedSystems = $wrapper->getDamagedSystems();
        if ($damagedSystems !== []) {
            $firstSystem = $damagedSystems[0];
            $firstSystem->setStatus(100);

            if ($station->getCrewCount() > 0) {
                $firstSystem->setMode($this->shipSystemManager->lookupSystem($firstSystem->getSystemType())->getDefaultMode());
            }

            // maximum of two systems get repaired
            if (count($damagedSystems) > 1) {
                $secondSystem = $damagedSystems[1];
                $secondSystem->setStatus(100);

                if ($station->getCrewCount() > 0) {
                    $secondSystem->setMode($this->shipSystemManager->lookupSystem($secondSystem->getSystemType())->getDefaultMode());
                }
            }
        }

        // consume spare parts
        $this->repairUtil->consumeSpareParts($neededParts, $station);

        if (!$wrapper->canBeRepaired()) {
            $station->setHuell($station->getMaxHull());
            $station->setState(ShipStateEnum::SHIP_STATE_NONE);

            $shipOwnerMessage = sprintf(
                "Die Reparatur der %s wurde in Sektor %s fertiggestellt",
                $station->getName(),
                $station->getSectorString()
            );

            $this->privateMessageSender->send(
                UserEnum::USER_NOONE,
                $station->getUser()->getId(),
                $shipOwnerMessage,
                PrivateMessageFolderTypeEnum::SPECIAL_STATION
            );
        }
        $this->shipRepository->save($station);
    }

    private function checkForFinishedTakeover(ShipInterface $ship): void
    {
        $startTime = microtime(true);
        $takeover = $ship->getTakeoverActive();
        if ($takeover === null) {
            return;
        }
        $this->potentialLog($ship, "marker10.1", $startTime);

        $startTime = microtime(true);
        $isTakeoverReady = $this->shipTakeoverManager->isTakeoverReady($takeover);
        $this->potentialLog($ship, "marker10.2", $startTime);

        if ($isTakeoverReady) {
            $startTime = microtime(true);
            $this->shipTakeoverManager->finishTakeover($takeover);
            $this->potentialLog($ship, "marker10.3", $startTime);
        }
    }

    private function checkForFinishedAstroMapping(ShipWrapperInterface $wrapper): void
    {
        $ship = $wrapper->get();

        /** @var null|DatabaseEntryInterface $databaseEntry */
        [$message, $databaseEntry] = $this->getDatabaseEntryForShipLocation($ship);

        $astroLab = $wrapper->getAstroLaboratorySystemData();

        if (
            $ship->getState() === ShipStateEnum::SHIP_STATE_ASTRO_FINALIZING
            && $databaseEntry !== null
            && $astroLab !== null
            && $this->game->getCurrentRound()->getTurn() >= ($astroLab->getAstroStartTurn() + AstronomicalMappingEnum::TURNS_TO_FINISH)
        ) {
            $this->astroEntryLib->finish($wrapper);

            $this->msg[] = sprintf(
                _('Die Kartographierung %s wurde vollendet'),
                $message
            );

            $userId = $ship->getUser()->getId();
            $databaseEntryId = $databaseEntry->getId();

            if (!$this->databaseUserRepository->exists($userId, $databaseEntryId)) {
                $entry = $this->createDatabaseEntry->createDatabaseEntryForUser($ship->getUser(), $databaseEntryId);

                if ($entry !== null) {
                    $this->msg[] = sprintf(
                        _('Neuer Datenbankeintrag: %s (+%d Punkte)'),
                        $entry->getDescription(),
                        $entry->getCategory()->getPoints()
                    );
                }
            }
        }
    }

    /**
     * @return array{0: string|null, 1: DatabaseEntryInterface|null}
     */
    private function getDatabaseEntryForShipLocation(ShipInterface $ship): array
    {
        $system = $ship->getSystem();
        if ($system !== null) {
            return [
                'des Systems ' . $system->getName(),
                $system->getDatabaseEntry()
            ];
        }

        $mapRegion = $ship->getMapRegion();
        if ($mapRegion !== null) {
            return [
                'der Region ' . $mapRegion->getDescription(),
                $mapRegion->getDatabaseEntry()
            ];
        }

        return [null, null];
    }

    private function doTrackerDeviceStuff(ShipWrapperInterface $wrapper): void
    {
        $ship = $wrapper->get();
        $tracker = $wrapper->getTrackerSystemData();

        if ($tracker === null || $tracker->targetId === null) {
            return;
        }

        $targetWrapper = $tracker->getTargetWrapper();
        if ($targetWrapper === null) {
            throw new RuntimeException('should not happen');
        }

        $target = $targetWrapper->get();
        $shipLocation = $ship->getLocation();
        $targetLocation = $target->getLocation();
        $remainingTicks = $tracker->getRemainingTicks();

        $reduceByTicks = max(1, (int)ceil((abs($shipLocation->getCx() - $targetLocation->getCx())
            +  abs($shipLocation->getCy() - $targetLocation->getCy())) / 50));

        //reduce remaining ticks
        if ($remainingTicks > $reduceByTicks) {
            $tracker->setRemainingTicks($remainingTicks - $reduceByTicks)->update();
        } else {
            $this->trackerDeviceManager->deactivateTrackerIfActive($wrapper, true);
        }
    }

    private function doBussardCollectorStuff(ShipWrapperInterface $wrapper): void
    {
        $ship = $wrapper->get();
        $bussard = $wrapper->getBussardCollectorSystemData();
        $miningqueue = $ship->getMiningQueue();

        if ($bussard === null) {
            return;
        }

        if ($miningqueue == null) {
            return;
        } else {
            $locationmining = $miningqueue->getLocationMining();
            $actualAmount = $locationmining->getActualAmount();
            $freeStorage = $ship->getMaxStorage() - $ship->getStorageSum();
            $module = $ship->getShipSystem(ShipSystemTypeEnum::SYSTEM_BUSSARD_COLLECTOR)->getModule();
            $gathercount = 0;

            if ($module !== null) {
                if ($module->getFactionId() == null) {
                    $gathercount =  (int) min(min(round(mt_rand(95, 105)), $actualAmount), $freeStorage);
                } else {
                    $gathercount = (int) min(min(round(mt_rand(190, 220)), $actualAmount), $freeStorage);
                }
            }

            $newAmount = $actualAmount - $gathercount;
            if ($gathercount > 0 && $locationmining->getDepletedAt() !== null) {
                $locationmining->setDepletedAt(null);
            }
            if ($newAmount == 0 && $actualAmount > 0) {
                $locationmining->setDepletedAt(time());
            }
            $locationmining->setActualAmount($newAmount);

            $this->locationMiningRepository->save($locationmining);

            $this->shipStorageManager->upperStorage(
                $ship,
                $locationmining->getCommodity(),
                $gathercount
            );
        }
    }

    private function doAggregationSystemStuff(ShipWrapperInterface $wrapper): void
    {
        $ship = $wrapper->get();
        $aggsys = $wrapper->getAggregationSystemSystemData();

        if ($aggsys === null) {
            return;
        } else {
            $module = $ship->getShipSystem(ShipSystemTypeEnum::SYSTEM_AGGREGATION_SYSTEM)->getModule();
            $producedAmount = 0;
            $usedAmount = 0;
            $usedCommodity = null;
            $producedCommodity = null;


            if ($module !== null) {
                $commodity = $aggsys->getCommodityId();
                $commodities = CommodityTypeEnum::COMMODITY_CONVERSIONS;

                if ($commodity > 0) {
                    foreach ($commodities as $entry) {
                        if ($entry[0] === $commodity) {
                            $producedCommodityId = $entry[1];
                            $producedCommodity = $this->commodityRepository->find($producedCommodityId);
                            $usedCommodity = $this->commodityRepository->find($entry[0]);
                            $usedAmount = $entry[2];
                            $producedAmount = $entry[3];
                            break;
                        }
                    }

                    if ($module->getFactionId() == FactionEnum::FACTION_FERENGI) {
                        $producedAmount *= 2;
                        $usedAmount *= 2;
                    }
                    $storage = $this->storageRepository->findOneBy([
                        'commodity' => $usedCommodity,
                        'ship' => $ship
                    ]);
                    if (!$storage && $usedCommodity) {
                        $this->msg[] = sprintf('Es ist kein %s vorhanden!', $usedCommodity->getName());
                        $this->sendMessages($ship);
                    }

                    if ($storage && $producedCommodity && $usedCommodity) {
                        if ($storage->getAmount() >= $usedAmount) {
                            $this->shipStorageManager->lowerStorage(
                                $ship,
                                $usedCommodity,
                                $usedAmount
                            );
                            $this->shipStorageManager->upperStorage(
                                $ship,
                                $producedCommodity,
                                $producedAmount
                            );
                        } else {
                            $this->msg[] = sprintf('Nicht genügend %s vorhanden!', $usedCommodity->getName());
                            $this->sendMessages($ship);
                        }
                    }
                }
            }
        }
    }



    private function sendMessages(ShipInterface $ship): void
    {
        if ($this->msg === []) {
            return;
        }
        $text = "Tickreport der " . $ship->getName() . "\n";
        foreach ($this->msg as $msg) {
            $text .= $msg . "\n";
        }

        $href = sprintf('ship.php?%s=1&id=%d', ShowShip::VIEW_IDENTIFIER, $ship->getId());

        $this->privateMessageSender->send(
            UserEnum::USER_NOONE,
            $ship->getUser()->getId(),
            $text,
            $ship->isBase() ? PrivateMessageFolderTypeEnum::SPECIAL_STATION : PrivateMessageFolderTypeEnum::SPECIAL_SHIP,
            $href
        );

        $this->msg = [];
    }
}
