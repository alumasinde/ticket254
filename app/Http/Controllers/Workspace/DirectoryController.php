<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\CategoryRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TicketReferenceRepository;
use App\Repositories\UserRepository;
use App\Services\Tenants\TenantContext;
use App\Services\Workspace\DirectoryService;

final class DirectoryController extends WorkspaceController
{
    public function __construct(
        UserRepository $users,
        TenantRepository $tenants,
        private readonly DirectoryService $directory,
        private readonly TeamRepository $teams,
        private readonly SiteRepository $sites,
        private readonly CategoryRepository $categories,
        private readonly MembershipRepository $memberships,
        private readonly TicketReferenceRepository $references,
    ) {
        parent::__construct($users, $tenants);
    }

    public function teams(TenantContext $context): Response
    {
        return $this->page($context, 'app/teams.twig', [
            'teams' => $this->directory->teams($context),
            'sites' => $this->sites->activeForTenant($context->tenantId),
            'members' => $this->memberships->activeMembersForTenant($context->tenantId),
        ], 'teams');
    }

    public function storeTeam(Request $request, TenantContext $context): Response
    {
        $this->directory->createTeam($context, $request->all(), $request);

        return redirect(route('teams.index'))->with('success', 'Team created.');
    }

    public function updateTeam(Request $request, int $id, TenantContext $context): Response
    {
        $this->directory->updateTeam($context, $id, $request->all(), $request);

        return redirect(route('teams.index'))->with('success', 'Team updated.');
    }

    public function archiveTeam(Request $request, int $id, TenantContext $context): Response
    {
        $this->directory->archiveTeam($context, $id, $request);

        return redirect(route('teams.index'))->with('success', 'Team archived.');
    }

    public function addTeamMember(Request $request, int $id, TenantContext $context): Response
    {
        $this->directory->addTeamMember($context, $id, (int) $request->input('user_id', 0), $request);

        return redirect(route('teams.index'))->with('success', 'Team member added.');
    }

    public function removeTeamMember(Request $request, int $id, int $userId, TenantContext $context): Response
    {
        $this->directory->removeTeamMember($context, $id, $userId, $request);

        return redirect(route('teams.index'))->with('success', 'Team member removed.');
    }

    public function categories(TenantContext $context): Response
    {
        return $this->page($context, 'app/categories.twig', [
            'categories' => $this->directory->categories($context),
            'sites' => $this->directory->sites($context),
            'teams' => $this->teams->activeForTenant($context->tenantId),
            'priorities' => $this->references->priorities(),
        ], 'categories');
    }

    public function storeSite(Request $request, TenantContext $context): Response
    {
        $this->directory->createSite($context, $request->all(), $request);

        return redirect(route('categories.index'))->with('success', 'Site created.');
    }

    public function updateSite(Request $request, int $id, TenantContext $context): Response
    {
        $this->directory->updateSite($context, $id, $request->all(), $request);

        return redirect(route('categories.index'))->with('success', 'Site updated.');
    }

    public function archiveSite(Request $request, int $id, TenantContext $context): Response
    {
        $this->directory->archiveSite($context, $id, $request);

        return redirect(route('categories.index'))->with('success', 'Site archived.');
    }

    public function storeCategory(Request $request, TenantContext $context): Response
    {
        $this->directory->createCategory($context, $request->all(), $request);

        return redirect(route('categories.index'))->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, int $id, TenantContext $context): Response
    {
        $this->directory->updateCategory($context, $id, $request->all(), $request);

        return redirect(route('categories.index'))->with('success', 'Category updated.');
    }

    public function archiveCategory(Request $request, int $id, TenantContext $context): Response
    {
        $this->directory->archiveCategory($context, $id, $request);

        return redirect(route('categories.index'))->with('success', 'Category archived.');
    }
}
