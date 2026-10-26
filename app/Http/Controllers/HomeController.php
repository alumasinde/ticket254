<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Response;

final class HomeController
{
    public function index(): Response
    {
        return view('home.twig');
    }
}
