<?php

declare(strict_types=1);

namespace App\Services\PublicAccess;

use App\Core\Exceptions\HttpException;
use App\Core\ValidationException;
use App\Repositories\CategoryRepository;
use App\Repositories\SiteRepository;
use App\Services\Tickets\CreateTicket;
use App\Services\Tickets\TicketAttachmentService;
use App\Services\Tenants\TenantSettings;
use App\Support\RateLimiter;

final class PublicRequestService
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly SiteRepository $sites,
        private readonly CreateTicket $createTicket,
        private readonly TicketAttachmentService $attachments,
        private readonly TenantSettings $settings,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    public function configuration(array $tenant): array
    {
        $settings = $this->settings->all($tenant);
        $domains = $settings['allowed_email_domains'];

        return [
            'name' => (string) $tenant['name'],
            'categories' => array_map(
                static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                $this->categories->activeForTenant((int) $tenant['id']),
            ),
            'sites' => array_map(
                static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                $this->sites->activeForTenant((int) $tenant['id']),
            ),
            'requires_work_email' => $domains !== [],
            'email_domain_hint' => $domains[0] ?? null,
            'attachments_enabled' => $settings['attachments_enabled'],
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $photo
     * @return array{reference: string, tracking_url: string, tracking_token: string, message: string}
     */
    public function submit(array $tenant, array $input, ?array $photo, string $ip): array
    {
        $tenantId = (int) $tenant['id'];

        if (trim((string) ($input['website'] ?? '')) !== '') {
            throw new ValidationException(['website' => 'Leave this field empty.']);
        }

        $this->validate($input);

        $email = strtolower(trim((string) ($input['requester_email'] ?? '')));

        if ($email !== '' && !$this->settings->allowsEmailDomain($tenant, $email)) {
            $hint = $this->settings->all($tenant)['allowed_email_domains'][0] ?? '';
            throw new HttpException(
                422,
                'Use your work email address' . ($hint === '' ? '.' : ' on ' . $hint . '.'),
                [],
                'email_domain_not_allowed',
                ['fields' => ['requester_email' => 'Use an email address on ' . $hint . '.']],
            );
        }

        $this->guardRate($ip, $tenantId);

        $hasPhoto = $photo !== null && (int) ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasPhoto && !$this->settings->attachmentsEnabled($tenant)) {
            throw new ValidationException(['photo' => 'Attachments are not enabled for this request form.']);
        }

        if ($hasPhoto) {
            $this->attachments->validate($photo, 'photo');
        }

        $result = $this->createTicket->executePublic($tenant, $input);
        $row = $result['row'];
        $reference = (string) \App\Support\TicketReference::format($result['number']);

        if ($hasPhoto) {
            $this->attachments->store($tenantId, (int) $row['id'], $photo, null, null, 'photo');
        }

        return [
            'reference' => $reference,
            'tracking_url' => $result['tracking_url'],
            'tracking_token' => $result['tracking_token'],
            'message' => 'We have received your request.',
        ];
    }

    private function validate(array $input): void
    {
        $errors = [];
        $name = trim((string) ($input['requester_name'] ?? ''));
        $email = strtolower(trim((string) ($input['requester_email'] ?? '')));
        $subject = trim((string) ($input['subject'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $phone = trim((string) ($input['requester_phone'] ?? ''));

        if ($name === '') {
            $errors['requester_name'] = 'Enter your name.';
        } elseif (mb_strlen($name) > 160) {
            $errors['requester_name'] = 'The name may not be greater than 160 characters.';
        }

        if ($email === '') {
            $errors['requester_email'] = 'Enter your email address.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $errors['requester_email'] = 'Enter a valid email address.';
        }

        if ($phone !== '' && mb_strlen($phone) > 32) {
            $errors['requester_phone'] = 'The phone number may not be greater than 32 characters.';
        }

        if ($subject === '') {
            $errors['subject'] = 'Enter a short subject.';
        } elseif (mb_strlen($subject) > 200) {
            $errors['subject'] = 'The subject may not be greater than 200 characters.';
        }

        if ($description === '') {
            $errors['description'] = 'Describe the problem.';
        } elseif (mb_strlen($description) > 20000) {
            $errors['description'] = 'The description may not be greater than 20000 characters.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function guardRate(string $ip, int $tenantId): void
    {
        $retry = $this->limiter->attempt(['public_ticket:ip:' . $ip], 5, 600);

        if ($retry === null) {
            $retry = $this->limiter->attempt(['public_ticket:tenant:' . $tenantId], 100, 86400);
        }

        if ($retry !== null) {
            throw new HttpException(
                429,
                'Too many requests from this connection. Try again later.',
                ['Retry-After' => (string) $retry],
                'rate_limited',
            );
        }
    }
}
