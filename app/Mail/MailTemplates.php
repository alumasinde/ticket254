<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\TwigFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class MailTemplates
{
    private readonly string $viewsPath;

    private ?Environment $text = null;

    public function __construct(private readonly TwigFactory $twig)
    {
        $this->viewsPath = base_path('resources/views');
    }

    public function render(Notification $notification): EmailMessage
    {
        $type = $this->type($notification->eventType);
        $subject = $this->subject($notification, $type);
        $data = array_merge($notification->payload, [
            'workspace_name' => (string) ($notification->payload['workspace_name'] ?? config('app.name', '')),
            'email_subject' => $subject,
        ]);

        return new EmailMessage(
            $notification->recipientEmail,
            $notification->recipientName,
            $subject,
            $this->text($type, $data),
            $this->twig->render('mail/' . $type . '.twig', $data),
        );
    }

    private function type(string $eventType): string
    {
        return in_array($eventType, ['ticket_created', 'ticket_reply', 'ticket_requester_reply', 'invitation'], true)
            ? $eventType
            : 'generic';
    }

    private function subject(Notification $notification, string $type): string
    {
        $reference = (string) ($notification->payload['reference'] ?? '');
        $subject = (string) ($notification->payload['subject'] ?? '');
        $workspace = (string) ($notification->payload['workspace_name'] ?? '');

        return match ($type) {
            'ticket_created' => trim(sprintf(
                '%s: we received your request%s',
                $workspace === '' ? 'Support' : $workspace,
                $reference === '' ? '' : ' (' . $reference . ')',
            )),
            'ticket_reply' => $reference === '' ? 'Support replied to your request' : 'Re: ' . $reference . ' — ' . $subject,
            'ticket_requester_reply' => $reference === '' ? 'A requester replied' : 'New requester reply on ' . $reference,
            'invitation' => $workspace === '' ? 'You have been invited' : 'You are invited to ' . $workspace,
            default => $reference === '' ? 'Support update' : 'Update on ' . $reference,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(string $type, array $data): string
    {
        return $this->textEnvironment()->render('mail/text/' . $type . '.twig', $data);
    }

    private function textEnvironment(): Environment
    {
        if ($this->text === null) {
            $this->text = new Environment(new FilesystemLoader($this->viewsPath), [
                'cache' => false,
                'debug' => false,
                'strict_variables' => true,
                'autoescape' => false,
                'charset' => 'UTF-8',
            ]);
        }

        return $this->text;
    }
}
