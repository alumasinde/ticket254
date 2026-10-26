<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(array $allowedMethods)
    {
        $allowed = implode(', ', $allowedMethods);

        parent::__construct(405, 'Method not allowed.', ['Allow' => $allowed]);
    }
}
