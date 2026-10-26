<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\CategoryRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TicketReferenceRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Tenants\TenantContext;
use PDOException;

final class DirectoryService
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly SiteRepository $sites,
        private readonly CategoryRepository $categories,
        private readonly MembershipRepository $memberships,
        private readonly TicketReferenceRepository $references,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function teams(TenantContext $ctx): array
    {
        $members = [];

        foreach ($this->teams->membersForTenant($ctx) as $member) {
            $members[(int) $member['team_id']][] = [
                'id' => (int) $member['user_id'],
                'name' => trim((string) $member['first_name'] . ' ' . (string) $member['last_name']),
            ];
        }

        return array_map(
            fn (array $row): array => $this->teamView($row, $members[(int) $row['id']] ?? []),
            $this->teams->listWithCounts($ctx),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createTeam(TenantContext $ctx, array $input, Request $request): array
    {
        $name = $this->name($input, 'team');
        $siteId = $this->optionalId($input['site_id'] ?? null, 'site_id');

        if ($siteId !== null) {
            $this->site($ctx, $siteId);
        }

        if ($this->teams->nameExists($ctx, $name)) {
            throw new ValidationException(['name' => 'A team with this name already exists.']);
        }

        $id = $this->guardDuplicate(
            fn (): int => $this->teams->create($ctx, ['name' => $name, 'site_id' => $siteId]),
            ['name' => 'A team with this name already exists.'],
        );

        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'team.created', 'team', $id, [
            'name' => $name,
            'site_id' => $siteId,
        ]);

        return $this->teamView($this->team($ctx, $id));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateTeam(TenantContext $ctx, int $id, array $input, Request $request): array
    {
        $current = $this->team($ctx, $id);
        $data = [];

        if (array_key_exists('name', $input)) {
            $name = $this->name($input, 'team');

            if ($this->teams->nameExists($ctx, $name, $id)) {
                throw new ValidationException(['name' => 'A team with this name already exists.']);
            }

            $data['name'] = $name;
        }

        if (array_key_exists('site_id', $input)) {
            $siteId = $this->optionalId($input['site_id'], 'site_id');

            if ($siteId !== null) {
                $this->site($ctx, $siteId);
            }

            $data['site_id'] = $siteId;
        }

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->status($input['status']);
        }

        if ($data !== []) {
            $this->guardDuplicate(
                function () use ($ctx, $id, $data): int {
                    $this->teams->update($ctx, $id, $data);

                    return 1;
                },
                ['name' => 'A team with this name already exists.'],
            );

            $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'team.updated', 'team', $id, [
                'changed' => array_keys($data),
                'status' => $data['status'] ?? (string) $current['status'],
            ]);
        }

        return $this->teamView($this->team($ctx, $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveTeam(TenantContext $ctx, int $id, Request $request): array
    {
        $this->team($ctx, $id);
        $this->teams->update($ctx, $id, ['status' => 'archived']);
        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'team.archived', 'team', $id);

        return $this->teamView($this->team($ctx, $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function addTeamMember(TenantContext $ctx, int $id, int $userId, Request $request): array
    {
        $this->team($ctx, $id);

        if (!$this->memberships->isActiveMember($ctx->tenantId, $userId)) {
            throw new ValidationException(['user_id' => 'Choose an active member of this workspace.']);
        }

        if ($this->teams->hasMember($ctx, $id, $userId)) {
            throw new ValidationException(['user_id' => 'This person is already in the team.']);
        }

        $this->teams->addMember($ctx, $id, $userId);
        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'team.member_added', 'team', $id, [
            'user_id' => $userId,
        ]);

        return $this->teamView($this->team($ctx, $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function removeTeamMember(TenantContext $ctx, int $id, int $userId, Request $request): array
    {
        $this->team($ctx, $id);

        if ($this->teams->removeMember($ctx, $id, $userId) === 0) {
            throw new NotFoundException();
        }

        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'team.member_removed', 'team', $id, [
            'user_id' => $userId,
        ]);

        return $this->teamView($this->team($ctx, $id));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sites(TenantContext $ctx): array
    {
        return array_map($this->siteView(...), $this->sites->listWithCounts($ctx));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createSite(TenantContext $ctx, array $input, Request $request): array
    {
        $name = $this->name($input, 'site');
        $address = $this->nullable((string) ($input['address'] ?? ''));

        if ($this->sites->nameExists($ctx, $name)) {
            throw new ValidationException(['name' => 'A site with this name already exists.']);
        }

        $id = $this->guardDuplicate(
            fn (): int => $this->sites->create($ctx, ['name' => $name, 'address' => $address]),
            ['name' => 'A site with this name already exists.'],
        );

        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'site.created', 'site', $id, [
            'name' => $name,
        ]);

        return $this->siteView($this->site($ctx, $id));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateSite(TenantContext $ctx, int $id, array $input, Request $request): array
    {
        $this->site($ctx, $id);
        $data = [];

        if (array_key_exists('name', $input)) {
            $name = $this->name($input, 'site');

            if ($this->sites->nameExists($ctx, $name, $id)) {
                throw new ValidationException(['name' => 'A site with this name already exists.']);
            }

            $data['name'] = $name;
        }

        if (array_key_exists('address', $input)) {
            $data['address'] = $this->nullable((string) $input['address']);
        }

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->status($input['status']);
        }

        if ($data !== []) {
            $this->guardDuplicate(
                function () use ($ctx, $id, $data): int {
                    $this->sites->update($ctx, $id, $data);

                    return 1;
                },
                ['name' => 'A site with this name already exists.'],
            );

            $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'site.updated', 'site', $id, [
                'changed' => array_keys($data),
            ]);
        }

        return $this->siteView($this->site($ctx, $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveSite(TenantContext $ctx, int $id, Request $request): array
    {
        $this->site($ctx, $id);
        $this->sites->update($ctx, $id, ['status' => 'archived']);
        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'site.archived', 'site', $id);

        return $this->siteView($this->site($ctx, $id));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(TenantContext $ctx): array
    {
        return array_map($this->categoryView(...), $this->categories->listWithDetails($ctx));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCategory(TenantContext $ctx, array $input, Request $request): array
    {
        $name = $this->name($input, 'category');
        $teamId = $this->optionalId($input['team_id'] ?? null, 'team_id');
        $priorityId = $this->priorityId($input['default_priority'] ?? $input['default_priority_id'] ?? null);

        if ($teamId !== null) {
            $this->team($ctx, $teamId);
        }

        if ($this->categories->nameExists($ctx, $name)) {
            throw new ValidationException(['name' => 'A category with this name already exists.']);
        }

        $id = $this->guardDuplicate(
            fn (): int => $this->categories->create($ctx, [
                'name' => $name,
                'team_id' => $teamId,
                'default_priority_id' => $priorityId,
            ]),
            ['name' => 'A category with this name already exists.'],
        );

        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'category.created', 'category', $id, [
            'name' => $name,
            'team_id' => $teamId,
        ]);

        return $this->categoryView($this->category($ctx, $id));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateCategory(TenantContext $ctx, int $id, array $input, Request $request): array
    {
        $this->category($ctx, $id);
        $data = [];

        if (array_key_exists('name', $input)) {
            $name = $this->name($input, 'category');

            if ($this->categories->nameExists($ctx, $name, $id)) {
                throw new ValidationException(['name' => 'A category with this name already exists.']);
            }

            $data['name'] = $name;
        }

        if (array_key_exists('team_id', $input)) {
            $teamId = $this->optionalId($input['team_id'], 'team_id');

            if ($teamId !== null) {
                $this->team($ctx, $teamId);
            }

            $data['team_id'] = $teamId;
        }

        if (array_key_exists('default_priority', $input) || array_key_exists('default_priority_id', $input)) {
            $data['default_priority_id'] = $this->priorityId(
                $input['default_priority'] ?? $input['default_priority_id'],
            );
        }

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->status($input['status']);
        }

        if ($data !== []) {
            $this->guardDuplicate(
                function () use ($ctx, $id, $data): int {
                    $this->categories->update($ctx, $id, $data);

                    return 1;
                },
                ['name' => 'A category with this name already exists.'],
            );

            $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'category.updated', 'category', $id, [
                'changed' => array_keys($data),
            ]);
        }

        return $this->categoryView($this->category($ctx, $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveCategory(TenantContext $ctx, int $id, Request $request): array
    {
        $this->category($ctx, $id);
        $this->categories->update($ctx, $id, ['status' => 'archived']);
        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'category.archived', 'category', $id);

        return $this->categoryView($this->category($ctx, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function team(TenantContext $ctx, int $id): array
    {
        $team = $this->teams->findWithCount($ctx, $id);

        if ($team === null) {
            throw new NotFoundException();
        }

        return $team;
    }

    /**
     * @return array<string, mixed>
     */
    private function site(TenantContext $ctx, int $id): array
    {
        $site = $this->sites->findWithCount($ctx, $id);

        if ($site === null) {
            throw new NotFoundException();
        }

        return $site;
    }

    /**
     * @return array<string, mixed>
     */
    private function category(TenantContext $ctx, int $id): array
    {
        $category = $this->categories->findWithDetails($ctx, $id);

        if ($category === null) {
            throw new NotFoundException();
        }

        return $category;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{id: int, name: string}> $members
     * @return array<string, mixed>
     */
    private function teamView(array $row, array $members = []): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'site_id' => $row['site_id'] === null ? null : (int) $row['site_id'],
            'site' => $row['site_id'] === null ? null : [
                'id' => (int) $row['site_id'],
                'name' => (string) ($row['site_name'] ?? ''),
            ],
            'status' => (string) $row['status'],
            'member_count' => (int) ($row['member_count'] ?? 0),
            'members' => $members,
            'created_at' => $this->iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function siteView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'address' => $row['address'] === null ? null : (string) $row['address'],
            'status' => (string) $row['status'],
            'team_count' => (int) ($row['team_count'] ?? 0),
            'created_at' => $this->iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function categoryView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'team_id' => $row['team_id'] === null ? null : (int) $row['team_id'],
            'team' => $row['team_id'] === null ? null : [
                'id' => (int) $row['team_id'],
                'name' => (string) ($row['team_name'] ?? ''),
            ],
            'default_priority' => $row['priority_code'] === null ? null : (string) $row['priority_code'],
            'default_priority_label' => $row['priority_label'] === null ? null : (string) $row['priority_label'],
            'status' => (string) $row['status'],
            'created_at' => $this->iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function name(array $input, string $label): string
    {
        $name = trim((string) ($input['name'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new ValidationException(['name' => 'Enter a name between 2 and 120 characters.']);
        }

        return $name;
    }

    private function optionalId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1 || (int) $value < 1) {
            throw new ValidationException([$field => 'Choose a valid option.']);
        }

        return (int) $value;
    }

    private function priorityId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        $priority = is_numeric($value)
            ? $this->references->priorityById((int) $value)
            : $this->references->priorityByCode((string) $value);

        if ($priority === null) {
            throw new ValidationException(['default_priority' => 'Choose a valid priority.']);
        }

        return (int) $priority['id'];
    }

    private function status(mixed $value): string
    {
        $status = (string) $value;

        if (!in_array($status, ['active', 'archived'], true)) {
            throw new ValidationException(['status' => 'Choose active or archived.']);
        }

        return $status;
    }

    /**
     * @param array<string, string> $errors
     */
    private function guardDuplicate(callable $operation, array $errors): mixed
    {
        try {
            return $operation();
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new ValidationException($errors);
            }

            throw $exception;
        }
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
