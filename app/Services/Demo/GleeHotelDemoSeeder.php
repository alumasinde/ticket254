<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Core\Request;
use App\Database\Database;
use App\Repositories\CategoryRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TicketEventRepository;
use App\Repositories\TicketMessageRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RegisterWorkspace;
use App\Services\Tickets\CreateTicket;
use App\Services\Tickets\WorkTicket;
use App\Services\Tenants\TenantContext;
use App\Support\PublicUrl;
use App\Support\Slug;
use App\Support\TicketReference;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Builds one complete Glee Hotel workspace for a pilot demo. Every ticket goes
 * through the real services so timelines, counters and the outbox behave as
 * they would in production.
 */
final class GleeHotelDemoSeeder
{
    public function __construct(
        private readonly Database $db,
        private readonly RegisterWorkspace $register,
        private readonly RoleRepository $roles,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly TeamRepository $teams,
        private readonly CategoryRepository $categories,
        private readonly SiteRepository $sites,
        private readonly TenantRepository $tenants,
        private readonly CreateTicket $tickets,
        private readonly TicketRepository $ticketRepo,
        private readonly WorkTicket $work,
        private readonly TicketMessageRepository $messages,
        private readonly TicketEventRepository $events,
        private readonly AuditLogger $audit,
        private readonly PublicUrl $urls,
    ) {
    }

    /**
     * @param array{email?: string, password?: string, slug?: string} $options
     * @return array<string, mixed>
     */
    public function seed(array $options = []): array
    {
        $slug = trim((string) ($options['slug'] ?? 'glee-hotel-demo'));
        $email = strtolower(trim((string) ($options['email'] ?? ('owner@' . $slug . '.example'))));
        $password = (string) ($options['password'] ?? '');
        $generated = false;

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Enter a valid owner email address.');
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1
            || mb_strlen($slug) < 3
            || mb_strlen($slug) > 40
            || Slug::isReserved($slug)
        ) {
            throw new RuntimeException('The demo slug must be 3-40 lowercase letters, numbers or single hyphens.');
        }

        if ($password === '') {
            $password = 'Glee-' . bin2hex(random_bytes(6)) . '!';
            $generated = true;
        }

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('The demo password must be at least 12 characters.');
        }

        if ($this->tenants->findBySlug($slug) !== null) {
            throw new RuntimeException("Workspace [$slug] already exists. Choose another --slug.");
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new RuntimeException("A user with [$email] already exists. Choose another --email.");
        }

        return $this->db->transaction(function () use ($email, $slug, $password, $generated): array {
            $registered = $this->register->execute([
                'first_name' => 'Amina',
                'last_name' => 'Otieno',
                'email' => $email,
                'password' => $password,
                'workspace_name' => 'Glee Hotel',
                'slug' => $slug,
                'timezone' => 'Africa/Nairobi',
            ]);

            $tenantId = (int) $registered['tenant']['id'];
            $ownerId = (int) $registered['user']['id'];
            $timezone = (string) $registered['tenant']['timezone'];
            $ownerRoleId = $this->roles->idByCode('owner');
            $ctx = new TenantContext($tenantId, $ownerId, 'owner', $timezone, $this->roles->permissionsForRole($ownerRoleId));

            $teams = [];
            foreach ($this->teams->activeForTenant($tenantId) as $team) {
                $teams[(string) $team['name']] = (int) $team['id'];
            }

            $sites = [];
            foreach ([
                ['Nairobi CBD', 'Kenyatta Avenue, Nairobi'],
                ['Westlands', 'Ring Road, Westlands'],
                ['JKIA', 'Terminal 1A, JKIA'],
            ] as [$name, $address]) {
                $sites[$name] = $this->sites->create($ctx, ['name' => $name, 'address' => $address]);
            }

            $staff = $this->staff($ctx, $tenantId, $password, $teams, $slug);

            $categories = [];
            foreach ($this->categories->activeForTenant($tenantId) as $category) {
                $categories[(string) $category['name']] = (int) $category['id'];
            }

            $ticketCount = $this->tickets($ctx, $timezone, $categories, $sites, $staff);

            $tenant = $this->tenants->findById($tenantId);

            if ($tenant === null) {
                throw new RuntimeException('The demo workspace could not be reloaded.');
            }

            $this->audit->record(
                Request::create('POST', '/demo/seed'),
                $tenantId,
                $ownerId,
                'demo.seeded',
                'tenant',
                $tenantId,
                [
                    'sites' => count($sites),
                    'members' => count($staff) + 1,
                    'tickets' => $ticketCount,
                ],
            );

            return [
                'tenant_id' => $tenantId,
                'slug' => $slug,
                'owner_email' => $email,
                'password' => $password,
                'generated_password' => $generated,
                'staff' => array_map(static fn (array $member): string => $member['email'], $staff),
                'sites' => count($sites),
                'members' => count($staff) + 1,
                'tickets' => $ticketCount,
                'public_url' => $this->urls->requestForm($tenant),
            ];
        });
    }

    /**
     * @param array<string, int> $teams
     * @return array<string, array{id: int, email: string, name: string}>
     */
    private function staff(TenantContext $ctx, int $tenantId, string $password, array $teams, string $slug): array
    {
        $definitions = [
            ['admin', 'Brian', 'Kiptoo', 'brian.kiptoo', 'IT'],
            ['agent', 'Wanjiku', 'Mwangi', 'wanjiku.mwangi', 'Front Office'],
            ['agent', 'Peter', 'Otieno', 'peter.otieno', 'IT'],
            ['agent', 'Grace', 'Njeri', 'grace.njeri', 'Housekeeping'],
            ['agent', 'Hassan', 'Ali', 'hassan.ali', 'Engineering'],
        ];

        $staff = [];
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $domain = $slug . '.example';

        foreach ($definitions as [$role, $first, $last, $mailbox, $team]) {
            $email = $mailbox . '@' . $domain;
            $userId = $this->users->create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password_hash' => $hash,
            ]);

            $this->memberships->create($tenantId, $userId, $this->roles->idByCode($role));
            $this->teams->addMember($ctx, $teams[$team], $userId);

            $staff[$first] = [
                'id' => $userId,
                'email' => $email,
                'name' => $first . ' ' . $last,
            ];
        }

        return $staff;
    }

    /**
     * @param array<string, int> $categories
     * @param array<string, int> $sites
     * @param array<string, array{id: int, email: string, name: string}> $staff
     */
    private function tickets(TenantContext $ctx, string $timezone, array $categories, array $sites, array $staff): int
    {
        $specs = $this->specs();

        foreach ($specs as $index => $spec) {
            $input = [
                'requester_name' => $spec['requester'],
                'requester_email' => $spec['email'],
                'requester_phone' => $spec['phone'],
                'subject' => $spec['subject'],
                'description' => $spec['description'],
                'category_id' => $categories[$spec['category']],
                'site_id' => $sites[$spec['site']],
                'priority' => $spec['priority'],
            ];

            if ($spec['overdue_days'] !== null) {
                $input['due_at'] = (new DateTimeImmutable(
                    $spec['overdue_days'] . ' days ago',
                    new DateTimeZone($timezone),
                ))->format('Y-m-d H:i');
            }

            $created = $this->tickets->execute($ctx, $input);
            $reference = TicketReference::format((int) $created['number']);
            $row = $created['row'];
            $assigneeId = $spec['assignee'] === null ? null : $staff[$spec['assignee']]['id'];

            if ($assigneeId !== null) {
                $detail = $this->work->assign($ctx, $reference, [
                    'assigned_user_id' => $assigneeId,
                    'version' => (int) $row['version'],
                ]);
                $row = $detail['row'];
            }

            if ($spec['requester_message'] !== null) {
                $this->messages->createRequester(
                    $ctx->tenantId,
                    (int) $row['id'],
                    (string) $row['requester_name'],
                    $spec['requester_message'],
                );
                $this->events->record($ctx->tenantId, (int) $row['id'], null, 'requester_replied');
            }

            if ($spec['reply'] !== null) {
                $this->work->addMessage($ctx, $reference, [
                    'visibility' => 'public',
                    'body' => $spec['reply'],
                ]);
                $row = $this->reload($ctx, $reference);
            }

            if ($spec['note'] !== null) {
                $this->work->addMessage($ctx, $reference, [
                    'visibility' => 'internal',
                    'body' => $spec['note'],
                ]);
                $row = $this->reload($ctx, $reference);
            }

            foreach ($spec['statuses'] as $status) {
                if ((string) $row['status_code'] === $status) {
                    continue;
                }

                $detail = $this->work->transition($ctx, $reference, [
                    'status' => $status,
                    'version' => (int) $row['version'],
                ]);
                $row = $detail['row'];
            }

            // Backdate creation so the dashboard shows plausible resolution times
            // rather than 0h for a workspace seeded seconds ago.
            $ageMinutes = 30 + ($index * 13);
            $this->db->statement(
                'UPDATE tickets SET created_at = DATE_SUB(created_at, INTERVAL ' . $ageMinutes . ' MINUTE) '
                . 'WHERE tenant_id = ? AND id = ?',
                [$ctx->tenantId, (int) $row['id']],
            );
        }

        return count($specs);
    }

    /**
     * @return array<string, mixed>
     */
    private function reload(TenantContext $ctx, string $reference): array
    {
        $found = $this->ticketRepo->findByNumber($ctx, (int) TicketReference::parse($reference));

        if ($found === null) {
            throw new RuntimeException('A demo ticket disappeared while seeding.');
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function specs(): array
    {
        return [
            [
                'requester' => 'Grace Wambui', 'email' => 'grace.wambui@example.co.ke', 'phone' => '+254 712 100 101',
                'subject' => 'Air conditioning not cooling in Room 214',
                'description' => 'Guest reports the room stays warm even after setting the thermostat to 18°C.',
                'category' => 'Room maintenance', 'site' => 'Westlands', 'priority' => 'urgent',
                'assignee' => 'Hassan', 'overdue_days' => 2, 'statuses' => ['in_progress'],
                'requester_message' => 'It is very hot in the afternoon. Please check before check-in time.',
                'reply' => 'Our technician is on site and will check the compressor before 2pm. We will update you here.',
                'note' => null,
            ],
            [
                'requester' => 'James Mwangi', 'email' => 'james.mwangi@example.co.ke', 'phone' => '+254 722 100 102',
                'subject' => 'Wi-Fi keeps dropping in Room 306',
                'description' => 'Connection drops every few minutes on both phone and laptop. Mobile data is working.',
                'category' => 'Wi-Fi/Network', 'site' => 'Nairobi CBD', 'priority' => 'high',
                'assignee' => 'Peter', 'overdue_days' => null, 'statuses' => ['in_progress'],
                'requester_message' => 'It happens mostly in the evening, around 8pm.',
                'reply' => 'We are checking the access point near your corridor and will confirm once the channel is changed.',
                'note' => null,
            ],
            [
                'requester' => 'Front Desk', 'email' => 'frontdesk@example.co.ke', 'phone' => '+254 733 100 103',
                'subject' => 'POS terminal will not print receipts',
                'description' => 'The main bar terminal prints blank receipts. Card payments still go through.',
                'category' => 'POS/Systems', 'site' => 'Nairobi CBD', 'priority' => 'urgent',
                'assignee' => 'Peter', 'overdue_days' => 1, 'statuses' => ['in_progress'],
                'requester_message' => null,
                'reply' => 'We have swapped the paper roll and are testing the printer driver now.',
                'note' => 'Spare thermal printer is in the IT store if the driver rollback fails.',
            ],
            [
                'requester' => 'Achieng Odhiambo', 'email' => 'achieng.odhiambo@example.co.ke', 'phone' => '+254 711 100 104',
                'subject' => 'Leaking shower in Room 118',
                'description' => 'Water is pooling near the shower door and the floor mat is soaked.',
                'category' => 'Room maintenance', 'site' => 'Westlands', 'priority' => 'high',
                'assignee' => 'Hassan', 'overdue_days' => null, 'statuses' => ['in_progress'],
                'requester_message' => null,
                'reply' => 'Housekeeping has placed extra mats and engineering is replacing the shower seal today.',
                'note' => null,
            ],
            [
                'requester' => 'Mary Njoki', 'email' => 'mary.njoki@example.co.ke', 'phone' => '+254 720 100 105',
                'subject' => 'Extra towels and toiletries for Room 205',
                'description' => 'Two extra bath towels and shower gel, please.',
                'category' => 'Housekeeping', 'site' => 'Nairobi CBD', 'priority' => 'low',
                'assignee' => 'Grace', 'overdue_days' => null, 'statuses' => ['in_progress', 'resolved'],
                'requester_message' => 'Please send them before 7pm.',
                'reply' => 'Delivered to Room 205 at 5:40pm. Enjoy your stay.',
                'note' => null,
            ],
            [
                'requester' => 'David Kariuki', 'email' => 'david.kariuki@example.co.ke', 'phone' => '+254 701 100 106',
                'subject' => 'TV remote not responding in Room 402',
                'description' => 'Changed the batteries but the remote still does not switch the TV on.',
                'category' => 'Room maintenance', 'site' => 'Nairobi CBD', 'priority' => 'normal',
                'assignee' => 'Hassan', 'overdue_days' => null, 'statuses' => ['pending'],
                'requester_message' => null,
                'reply' => 'We have ordered a replacement remote and will bring it tomorrow morning.',
                'note' => 'Awaiting delivery from the supplier; ETA 10am.',
            ],
            [
                'requester' => 'Reception', 'email' => 'reception@example.co.ke', 'phone' => '+254 734 100 107',
                'subject' => 'Key card reader rejects new keycards',
                'description' => 'Three keycards issued this morning fail at the east wing door.',
                'category' => 'POS/Systems', 'site' => 'Nairobi CBD', 'priority' => 'high',
                'assignee' => 'Peter', 'overdue_days' => null, 'statuses' => ['in_progress', 'resolved'],
                'requester_message' => 'Guests are waiting at the door, please treat as urgent.',
                'reply' => 'The reader was re-synced and all three cards are working. Please retest with the next check-in.',
                'note' => null,
            ],
            [
                'requester' => 'Housekeeping Supervisor', 'email' => 'housekeeping@example.co.ke', 'phone' => '+254 735 100 108',
                'subject' => 'Laundry pickup missed this morning',
                'description' => 'The 7am laundry pickup did not arrive; two trolleys are waiting at the service door.',
                'category' => 'Housekeeping', 'site' => 'Westlands', 'priority' => 'low',
                'assignee' => 'Grace', 'overdue_days' => null, 'statuses' => ['in_progress', 'resolved', 'closed'],
                'requester_message' => null,
                'reply' => 'The laundry van has collected both trolleys and the return is scheduled for 4pm.',
                'note' => null,
            ],
            [
                'requester' => 'Events Team', 'email' => 'events@example.co.ke', 'phone' => '+254 736 100 109',
                'subject' => 'Conference room projector flickers during presentations',
                'description' => 'The projector in the boardroom flickers every few minutes over HDMI.',
                'category' => 'Computer/Printer', 'site' => 'Nairobi CBD', 'priority' => 'normal',
                'assignee' => null, 'overdue_days' => null, 'statuses' => [],
                'requester_message' => null,
                'reply' => null,
                'note' => 'Vendor booked for Thursday morning; keep the room free until 11am.',
            ],
            [
                'requester' => 'Restaurant Manager', 'email' => 'restaurant@example.co.ke', 'phone' => '+254 737 100 110',
                'subject' => 'Breakfast buffet refill delayed at 9am',
                'description' => 'The hot station ran out of eggs and sausages during the 9am rush.',
                'category' => 'Other', 'site' => 'Nairobi CBD', 'priority' => 'low',
                'assignee' => 'Wanjiku', 'overdue_days' => null, 'statuses' => ['in_progress'],
                'requester_message' => null,
                'reply' => 'We have added a second refill round at 9am and will monitor the next two weekends.',
                'note' => null,
            ],
            [
                'requester' => 'Night Manager', 'email' => 'nightmanager@example.co.ke', 'phone' => '+254 738 100 111',
                'subject' => 'Noise complaint from Room 412',
                'description' => 'Guest in 410 reports loud music from the neighbouring room after 11pm.',
                'category' => 'Other', 'site' => 'Westlands', 'priority' => 'normal',
                'assignee' => 'Wanjiku', 'overdue_days' => null, 'statuses' => ['pending'],
                'requester_message' => null,
                'reply' => 'Security spoke to the guests and the room has been quiet since 11:35pm.',
                'note' => 'Follow up with the guest in 410 at checkout.',
            ],
            [
                'requester' => 'Accounts', 'email' => 'accounts@example.co.ke', 'phone' => '+254 739 100 112',
                'subject' => 'Billing discrepancy on folio 88214',
                'description' => 'The folio shows two minibar charges but the guest only signed for one.',
                'category' => 'Other', 'site' => 'Nairobi CBD', 'priority' => 'high',
                'assignee' => 'Wanjiku', 'overdue_days' => null, 'statuses' => ['in_progress', 'resolved', 'closed'],
                'requester_message' => null,
                'reply' => 'The duplicate charge has been reversed and an updated folio emailed to the guest.',
                'note' => null,
            ],
            [
                'requester' => 'Maintenance Lead', 'email' => 'maintenance@example.co.ke', 'phone' => '+254 740 100 113',
                'subject' => 'Generator service overdue',
                'description' => 'The backup generator has run 520 hours since the last service; the manual says 500.',
                'category' => 'Room maintenance', 'site' => 'Westlands', 'priority' => 'high',
                'assignee' => 'Hassan', 'overdue_days' => 3, 'statuses' => ['in_progress'],
                'requester_message' => 'Can we schedule this before the weekend load-shedding window?',
                'reply' => 'The service is booked for Friday morning and the generator will stay on bypass until then.',
                'note' => null,
            ],
            [
                'requester' => 'HR Office', 'email' => 'hr@example.co.ke', 'phone' => '+254 741 100 114',
                'subject' => 'New staff email account for JKIA team',
                'description' => 'Please create an email account for the new lounge supervisor starting Monday.',
                'category' => 'Computer/Printer', 'site' => 'JKIA', 'priority' => 'normal',
                'assignee' => null, 'overdue_days' => null, 'statuses' => [],
                'requester_message' => null,
                'reply' => null,
                'note' => null,
            ],
        ];
    }
}
