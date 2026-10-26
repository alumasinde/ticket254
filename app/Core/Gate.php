<?php

declare(strict_types=1);

namespace App\Core;

interface Gate
{
    public function allows(Request $request, string $permission): bool;
}
