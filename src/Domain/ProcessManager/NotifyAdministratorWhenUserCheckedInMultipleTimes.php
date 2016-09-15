<?php

declare(strict_types=1);

namespace Building\Domain\ProcessManager;

use Building\Domain\Command\NotifyAdministratorOfMultipleCheckInAnomaly;
use Building\Domain\DomainEvent\UserCheckedMultipleTimesIntoBuilding;

final class NotifyAdministratorWhenUserCheckedInMultipleTimes
{
    /**
     * @var callable
     */
    private $commandBus;

    public function __construct(callable $commandBus)
    {
        $this->commandBus = $commandBus;
    }

    public function __invoke(UserCheckedMultipleTimesIntoBuilding $anomaly)
    {
        call_user_func($this->commandBus, NotifyAdministratorOfMultipleCheckInAnomaly::fromUsernameAndBuilding(
            $anomaly->username(),
            $anomaly->building()
        ));
    }
}
