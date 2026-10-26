<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\VersionConflictException;
use App\Core\ValidationException;
use App\Database\Database;
use App\Repositories\CategoryRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TicketEventRepository;
use App\Repositories\TicketRepository;
use App\Services\Tenants\TenantContext;
use App\Support\TicketReference;

final class UpdateTicket
{
    public function __construct(
        private readonly Database $db,
        private readonly TicketRepository $tickets,
        private readonly TicketEventRepository $events,
        private readonly TicketAccess $access,
        private readonly CategoryRepository $categories,
        private readonly SiteRepository $sites,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{row: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function execute(TenantContext $ctx, string $reference, array $input): array
    {
        $number = TicketReference::parse($reference);

        if ($number === null) {
            throw new NotFoundException();
        }

        $row = $this->tickets->findByNumber($ctx, $number);

        if ($row === null || !$this->access->canView($ctx, $row)) {
            throw new NotFoundException();
        }

        if (!$this->access->canEdit($ctx, $row)) {
            throw new ForbiddenException();
        }

        $version = (int) $input['version'];
        $data = [];
        $old = [];
        $new = [];
        $changed = [];

        if (array_key_exists('subject', $input)) {
            $subject = trim((string) $input['subject']);

            if ($subject === '') {
                throw new ValidationException(['subject' => 'Enter the ticket subject.']);
            }

            $data['subject'] = $subject;
            $old['subject'] = (string) $row['subject'];
            $new['subject'] = $subject;
            $changed[] = 'subject';
        }

        if (array_key_exists('description', $input)) {
            $description = trim((string) $input['description']);

            if ($description === '') {
                throw new ValidationException(['description' => 'Enter the ticket description.']);
            }

            $data['description'] = $description;
            $changed[] = 'description';
        }

        if (array_key_exists('category_id', $input)) {
            $categoryId = $this->categoryId($ctx, $input['category_id']);
            $data['category_id'] = $categoryId;
            $old['category_id'] = $row['category_id'] === null ? null : (int) $row['category_id'];
            $new['category_id'] = $categoryId;
            $changed[] = 'category_id';
        }

        if (array_key_exists('site_id', $input)) {
            $siteId = $this->siteId($ctx, $input['site_id']);
            $data['site_id'] = $siteId;
            $old['site_id'] = $row['site_id'] === null ? null : (int) $row['site_id'];
            $new['site_id'] = $siteId;
            $changed[] = 'site_id';
        }

        if ($data === []) {
            return [
                'row' => $row,
                'events' => $this->events->forTicket($ctx, (int) $row['id']),
            ];
        }

        $this->db->transaction(function () use ($ctx, $row, $version, $data, $old, $new, $changed): void {
            $updated = $this->tickets->updateVersioned($ctx, (int) $row['id'], $version, $data);

            if ($updated === 0) {
                $current = $this->tickets->currentVersion($ctx, (int) $row['id']);

                throw new VersionConflictException($current ?? (int) $row['version']);
            }

            $old['changed'] = $changed;
            $new['changed'] = $changed;

            $this->events->record(
                $ctx->tenantId,
                (int) $row['id'],
                $ctx->userId,
                'edited',
                $old,
                $new,
            );
        });

        $updatedRow = $this->tickets->findByNumber($ctx, $number) ?? $row;

        return [
            'row' => $updatedRow,
            'events' => $this->events->forTicket($ctx, (int) $row['id']),
        ];
    }

    private function categoryId(TenantContext $ctx, mixed $value): ?int
    {
        $id = $value === null || $value === '' ? null : (int) $value;

        if ($id === null) {
            return null;
        }

        $category = $this->categories->find($ctx, $id);

        if ($category === null || (string) $category['status'] !== 'active') {
            throw new ValidationException([
                'category_id' => 'The selected category is not available in this workspace.',
            ]);
        }

        return $id;
    }

    private function siteId(TenantContext $ctx, mixed $value): ?int
    {
        $id = $value === null || $value === '' ? null : (int) $value;

        if ($id === null) {
            return null;
        }

        $site = $this->sites->find($ctx, $id);

        if ($site === null || (string) $site['status'] !== 'active') {
            throw new ValidationException([
                'site_id' => 'The selected site is not available in this workspace.',
            ]);
        }

        return $id;
    }
}
