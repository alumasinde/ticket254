<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\Auth\SessionAuth;
use App\Services\Platform\PlatformService;

final class PlatformController
{
    public function __construct(
        private readonly PlatformService $platform,
        private readonly UserRepository $users,
        private readonly SessionAuth $auth,
    ) {
    }

    public function index(): Response
    {
        return $this->page('platform/overview.twig', [
            'overview' => $this->platform->overview(),
            'tenants' => array_slice($this->platform->tenants([]), 0, 8),
        ], 'overview');
    }

    public function tenants(Request $request): Response
    {
        return $this->page('platform/tenants.twig', [
            'tenants' => $this->platform->tenants($request->query()),
            'filters' => [
                'status' => (string) ($request->query()['status'] ?? ''),
                'q' => (string) ($request->query()['q'] ?? ''),
            ],
        ], 'tenants');
    }

    public function show(int $id): Response
    {
        return $this->page('platform/tenant.twig', [
            'tenant' => $this->platform->detail($id),
        ], 'tenants');
    }

    public function health(): Response
    {
        return $this->page('platform/health.twig', [
            'health' => $this->platform->health(),
        ], 'health');
    }

    public function suspend(Request $request, int $id): Response
    {
        $this->platform->suspend($id, $request->all(), $request, (int) $this->auth->userId());

        return redirect(route('platform.tenants.show', ['id' => $id]))
            ->with('success', 'Workspace suspended.');
    }

    public function reactivate(Request $request, int $id): Response
    {
        $this->platform->reactivate($id, $request, (int) $this->auth->userId());

        return redirect(route('platform.tenants.show', ['id' => $id]))
            ->with('success', 'Workspace reactivated.');
    }

    public function subscription(Request $request, int $id): Response
    {
        $this->platform->subscription($id, $request->all(), $request, (int) $this->auth->userId());

        return redirect(route('platform.tenants.show', ['id' => $id]))
            ->with('success', 'Subscription updated.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(string $template, array $data, string $nav): Response
    {
        return view($template, array_merge([
            'user' => $this->users->findById((int) $this->auth->userId()),
            'nav' => $nav,
            'errors' => session('errors') ?? [],
        ], $data));
    }
}
