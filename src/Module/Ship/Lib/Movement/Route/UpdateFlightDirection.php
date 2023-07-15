<?php

declare(strict_types=1);

namespace Stu\Module\Ship\Lib\Movement\Route;

use RuntimeException;
use Stu\Component\Ship\ShipEnum;
use Stu\Orm\Entity\MapInterface;
use Stu\Orm\Entity\ShipInterface;
use Stu\Orm\Entity\StarSystemMapInterface;

final class UpdateFlightDirection implements UpdateFlightDirectionInterface
{
    public function update(
        MapInterface|StarSystemMapInterface $oldWaypoint,
        MapInterface|StarSystemMapInterface $waypoint,
        ShipInterface $ship
    ): int {

        $startX = $oldWaypoint->getX();
        $startY = $oldWaypoint->getY();

        $destinationX = $waypoint->getX();
        $destinationY = $waypoint->getY();

        $flightDirection = null;

        if ($destinationX == $startX) {
            $oldy = $startY;
            if ($destinationY > $oldy) {
                $flightDirection = ShipEnum::DIRECTION_BOTTOM;
            } elseif ($destinationY < $oldy) {
                $flightDirection = ShipEnum::DIRECTION_TOP;
            }
        }
        if ($destinationY == $startY) {
            $oldx = $startX;
            if ($destinationX > $oldx) {
                $flightDirection = ShipEnum::DIRECTION_RIGHT;
            } elseif ($destinationX < $oldx) {
                $flightDirection = ShipEnum::DIRECTION_LEFT;
            }
        }

        if ($flightDirection === null) {
            throw new RuntimeException('this should not happen');
        }

        $ship->setFlightDirection($flightDirection);

        return $flightDirection;
    }
}