<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Tenants\TenantContext;
use App\Services\Workspace\DirectoryService;

final class DirectoryController
{
    public function __construct(private readonly DirectoryService $directory)
    {
    }

    public function teams(TenantContext $context): Response
    {
        return Response::json(['data' => $this->directory->teams($context)]);
    }

    public function storeTeam(Request $request, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->createTeam($context, $request->all(), $request),
        ], 201);
    }

    public function updateTeam(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->updateTeam($context, $id, $request->all(), $request),
        ]);
    }

    public function deleteTeam(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->archiveTeam($context, $id, $request),
        ]);
    }

    public function addTeamMember(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->addTeamMember(
                $context,
                $id,
                (int) $request->input('user_id', 0),
                $request,
            ),
        ]);
    }

    public function removeTeamMember(Request $request, int $id, int $userId, TenantContext $context): Response
    {
        $this->directory->removeTeamMember($context, $id, $userId, $request);

        return Response::noContent();
    }

    public function sites(TenantContext $context): Response
    {
        return Response::json(['data' => $this->directory->sites($context)]);
    }

    public function storeSite(Request $request, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->createSite($context, $request->all(), $request),
        ], 201);
    }

    public function updateSite(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->updateSite($context, $id, $request->all(), $request),
        ]);
    }

    public function deleteSite(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->archiveSite($context, $id, $request),
        ]);
    }

    public function categories(TenantContext $context): Response
    {
        return Response::json(['data' => $this->directory->categories($context)]);
    }

    public function storeCategory(Request $request, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->createCategory($context, $request->all(), $request),
        ], 201);
    }

    public function updateCategory(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->updateCategory($context, $id, $request->all(), $request),
        ]);
    }

    public function deleteCategory(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->directory->archiveCategory($context, $id, $request),
        ]);
    }
}
