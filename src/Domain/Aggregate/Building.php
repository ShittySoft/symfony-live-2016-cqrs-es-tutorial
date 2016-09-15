<?php

declare(strict_types=1);

namespace Building\Domain\Aggregate;

use Building\Domain\Authorization\AuthorizedUsersInterface;
use Building\Domain\DomainEvent\NewBuildingWasRegistered;
use Building\Domain\DomainEvent\UserCheckedMultipleTimesIntoBuilding;
use Building\Domain\DomainEvent\UserWasCheckedIntoBuilding;
use Building\Domain\DomainEvent\UserWasCheckedOutOfBuilding;
use Prooph\EventSourcing\AggregateRoot;
use Rhumsaa\Uuid\Uuid;

final class Building extends AggregateRoot
{
    /**
     * @var Uuid
     */
    private $uuid;

    /**
     * @var string
     */
    private $name;

    /**
     * @var <string, bool>array
     */
    private $checkedInUsers = [];

    public static function new(string $name) : self
    {
        $self = new self();

        $self->recordThat(NewBuildingWasRegistered::occur(
            (string) Uuid::uuid4(),
            [
                'name' => $name
            ]
        ));

        return $self;
    }

    public function checkInUser(AuthorizedUsersInterface $authorizedUsers, string $username)
    {
        if (! $authorizedUsers->has($username)) {
            throw new \DomainException('YOU ARE NOT AUTHORIZED, ACHTUNG!');
        }

        $doubleCheckIn = array_key_exists($username, $this->checkedInUsers);

        $this->recordThat(UserWasCheckedIntoBuilding::fromUsernameAndBuilding(
            $username,
            $this->uuid
        ));

        if ($doubleCheckIn) {
            $this->recordThat(UserCheckedMultipleTimesIntoBuilding::fromUsernameAndBuilding(
                $username,
                $this->uuid
            ));
        }
    }

    public function checkOutUser(string $username)
    {
        if (! array_key_exists($username, $this->checkedInUsers)) {
            throw new \DomainException(sprintf(
                'User "%s" is not checked into the building "%s"',
                $username,
                $this->uuid->toString()
            ));
        }

        $this->recordThat(UserWasCheckedOutOfBuilding::fromUsernameAndBuilding(
            $username,
            $this->uuid
        ));
    }

    public function whenNewBuildingWasRegistered(NewBuildingWasRegistered $event)
    {
        $this->uuid = $event->uuid();
        $this->name = $event->name();
    }

    public function whenUserWasCheckedIntoBuilding(UserWasCheckedIntoBuilding $event)
    {
        $this->checkedInUsers[$event->username()] = true;
    }

    public function whenUserWasCheckedOutOfBuilding(UserWasCheckedOutOfBuilding $event)
    {
        unset($this->checkedInUsers[$event->username()]);
    }

    public function whenUserCheckedMultipleTimesIntoBuilding(UserCheckedMultipleTimesIntoBuilding $event)
    {
        // empty for now
    }

    /**
     * {@inheritDoc}
     */
    protected function aggregateId() : string
    {
        return (string) $this->uuid;
    }

    /**
     * {@inheritDoc}
     */
    public function id() : string
    {
        return $this->aggregateId();
    }
}
