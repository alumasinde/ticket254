<?php

declare(strict_types=1);

namespace App\Core;

final class DenyAllGate implements Gate
{
    public function allows(Request $request, string $permission): bool
    {
        return false;
    }
}
