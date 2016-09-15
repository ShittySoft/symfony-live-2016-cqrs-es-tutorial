<?php

declare(strict_types=1);

namespace Building\Domain\Command;

use Prooph\Common\Messaging\Command;
use Rhumsaa\Uuid\Uuid;

final class CheckUserOutOfBuilding extends Command
{
    /**
     * @var string
     */
    private $username;

    /**
     * @var Uuid
     */
    private $building;

    private function __construct(string $username, Uuid $building)
    {
        $this->init();

        $this->username = $username;
        $this->building = $building;
    }

    public static function fromUsernameAndBuilding(string $username, Uuid $building) : self
    {
        return new self($username, $building);
    }

    public function username() : string
    {
        return $this->username;
    }

    public function building() : Uuid
    {
        return $this->building;
    }

    /**
     * {@inheritDoc}
     */
    public function payload() : array
    {
        return [
            'username' => $this->username,
            'building' => $this->building->toString(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function setPayload(array $payload)
    {
        $this->username = $payload['username'];
        $this->building = Uuid::fromString($payload['building']);
    }
}
