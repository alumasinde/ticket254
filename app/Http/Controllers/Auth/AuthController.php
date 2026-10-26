<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Response;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\LoginUser;
use App\Services\Auth\RegisterWorkspace;
use App\Services\Auth\SessionAuth;

final class AuthController
{
    public function __construct(
        private readonly RegisterWorkspace $registerWorkspace,
        private readonly LoginUser $loginUser,
        private readonly SessionAuth $auth,
    ) {
    }

    public function showRegister(): Response
    {
        return view('auth/register.twig', [
            'errors' => session('errors') ?? [],
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function register(RegisterRequest $request): Response
    {
        $result = $this->registerWorkspace->execute($request->validated());

        $this->auth->start((int) $result['user']['id'], (int) $result['tenant']['id'], 1);

        return redirect(route('app.home'))->with('success', 'Your workspace is ready.');
    }

    public function showLogin(): Response
    {
        return view('auth/login.twig', [
            'errors' => session('errors') ?? [],
        ]);
    }

    public function login(LoginRequest $request): Response
    {
        $result = $this->loginUser->attempt(
            (string) $request->input('email'),
            (string) $request->input('password'),
        );

        if ($result === null) {
            return redirect(route('login'))
                ->withErrors(['email' => 'The email or password is incorrect.'])
                ->withInput($request->all());
        }

        return redirect(route('app.home'))->with('success', 'Signed in.');
    }

    public function logout(): Response
    {
        $this->auth->destroy();

        return redirect(route('login'));
    }
}
