<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\TicketAttachmentRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Tenants\TenantContext;
use finfo;
use RuntimeException;

final class TicketAttachmentService
{
    public function __construct(
        private readonly TicketAttachmentRepository $attachments,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $file
     * @return array{original_name: string, mime_type: string, size_bytes: int, extension: string, checksum_sha256: string}
     */
    public function validate(array $file, string $field = 'photo'): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException([$field => $this->uploadError($error)]);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $maxBytes = ((int) config('uploads.max_mb', 5)) * 1024 * 1024;

        if ($tmp === '' || !is_file($tmp)) {
            throw new ValidationException([$field => 'The file could not be read. Try again.']);
        }

        if ($size < 1 || $size > $maxBytes) {
            throw new ValidationException([
                $field => 'The file must be smaller than ' . (int) config('uploads.max_mb', 5) . ' MB.',
            ]);
        }

        $original = $this->originalName((string) ($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = (array) config('uploads.allowed_extensions', []);

        if (!in_array($extension, $allowed, true)) {
            throw new ValidationException([$field => 'Attach a JPG, PNG, PDF or TXT file.']);
        }

        $detector = new finfo(FILEINFO_MIME_TYPE);
        $mime = $detector->file($tmp);
        $mime = is_string($mime) ? strtolower($mime) : '';
        $allowedMimes = (array) config('uploads.mime_types.' . $extension, []);

        if (!in_array($mime, $allowedMimes, true)) {
            throw new ValidationException([$field => 'The file contents do not match its type.']);
        }

        return [
            'original_name' => $original,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'extension' => $extension,
            'checksum_sha256' => hash_file('sha256', $tmp) ?: '',
        ];
    }

    /**
     * @param array<string, mixed> $file
     */
    public function store(
        int $tenantId,
        int $ticketId,
        array $file,
        ?int $messageId = null,
        ?int $uploadedBy = null,
        string $field = 'photo',
    ): int {
        $meta = $this->validate($file, $field);
        $storageKey = bin2hex(random_bytes(16)) . '.' . $meta['extension'];
        $directory = $this->directory($tenantId);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The private storage directory could not be created.');
        }

        $target = $directory . '/' . $storageKey;

        if (!$this->move((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        try {
            return $this->attachments->create($tenantId, $ticketId, [
                'message_id' => $messageId,
                'storage_key' => $storageKey,
                'original_name' => $meta['original_name'],
                'mime_type' => $meta['mime_type'],
                'size_bytes' => $meta['size_bytes'],
                'checksum_sha256' => $meta['checksum_sha256'],
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (\Throwable $exception) {
            @unlink($target);

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function attach(TenantContext $ctx, int $ticketId, array $file, Request $request): array
    {
        $id = $this->store($ctx->tenantId, $ticketId, $file, null, $ctx->userId, 'file');
        $attachment = $this->attachments->find($ctx->tenantId, $ticketId, $id);

        if ($attachment === null) {
            throw new NotFoundException();
        }

        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'attachment.uploaded', 'ticket_attachment', $id, [
            'ticket_id' => $ticketId,
            'name' => (string) $attachment['original_name'],
            'size_bytes' => (int) $attachment['size_bytes'],
        ]);

        return $attachment;
    }

    public function remove(TenantContext $ctx, int $ticketId, int $attachmentId, Request $request): void
    {
        $attachment = $this->attachments->find($ctx->tenantId, $ticketId, $attachmentId);

        if ($attachment === null) {
            throw new NotFoundException();
        }

        $path = $this->path($ctx->tenantId, $attachment);

        if ($this->attachments->delete($ctx->tenantId, $ticketId, $attachmentId) !== 1) {
            throw new NotFoundException();
        }

        if ($path !== null) {
            @unlink($path);
        }

        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'attachment.deleted', 'ticket_attachment', $attachmentId, [
            'ticket_id' => $ticketId,
            'name' => (string) $attachment['original_name'],
        ]);
    }

    /**
     * @param array<string, mixed> $attachment
     */
    public function path(int $tenantId, array $attachment): ?string
    {
        $key = basename((string) $attachment['storage_key']);
        $path = $this->directory($tenantId) . '/' . $key;
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            return null;
        }

        $root = realpath(base_path('storage/private'));

        if ($root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    private function directory(int $tenantId): string
    {
        return base_path('storage/private/' . $tenantId);
    }

    private function move(string $tmp, string $target): bool
    {
        if (is_uploaded_file($tmp)) {
            return move_uploaded_file($tmp, $target);
        }

        return rename($tmp, $target);
    }

    private function originalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            $name = 'attachment';
        }

        if (mb_strlen($name) > 255) {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $base = mb_substr($name, 0, 250);
            $name = $base . ($extension === '' ? '' : '.' . $extension);
        }

        return $name;
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload did not finish. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to attach.',
            default => 'The file could not be uploaded.',
        };
    }
}
