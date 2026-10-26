<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class VersionConflictException extends HttpException
{
    public function __construct(int $currentVersion)
    {
        parent::__construct(
            409,
            'This ticket was updated by someone else. Reload and try again.',
            [],
            'version_conflict',
            ['current_version' => $currentVersion],
        );
    }
}
