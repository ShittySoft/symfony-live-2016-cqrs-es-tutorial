<?php

declare(strict_types=1);

namespace Building\Domain\Authorization;

interface AuthorizedUsersInterface
{
    public function has(string $username) : bool;
}
