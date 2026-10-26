<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\EmailChannel;
use App\Mail\MailTemplates;
use App\Mail\Notification;
use Tests\TestCase;

final class MailTemplatesTest extends TestCase
{
    public function test_ticket_created_email_contains_the_tracking_link_and_escapes_html(): void
    {
        $notification = new Notification(
            1,
            null,
            'ticket_created',
            'njeri@example.co.ke',
            'Njeri Wambui',
            [
                'reference' => 'HD-000001',
                'subject' => 'Leak <script>alert(1)</script>',
                'requester_name' => 'Njeri Wambui',
                'workspace_name' => 'Glee Hotel',
                'tracking_url' => 'http://localhost/track/' . str_repeat('a', 64),
            ],
        );

        $message = $this->app->container()->get(MailTemplates::class)->render($notification);

        $this->assertSame('njeri@example.co.ke', $message->to);
        $this->assertSame('Njeri Wambui', $message->name);
        $this->assertSame('Glee Hotel: we received your request (HD-000001)', $message->subject);
        $this->assertStringContainsString('Glee Hotel', $message->html);
        $this->assertStringContainsString('HD-000001', $message->html);
        $this->assertStringContainsString('http://localhost/track/' . str_repeat('a', 64), $message->html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $message->html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $message->html);
        $this->assertStringContainsString('Leak <script>alert(1)</script>', $message->text);
        $this->assertStringContainsString('http://localhost/track/' . str_repeat('a', 64), $message->text);
    }

    public function test_reply_templates_keep_the_request_subject_and_escape_the_body(): void
    {
        $templates = $this->app->container()->get(MailTemplates::class);

        $reply = $templates->render(new Notification(
            1,
            null,
            'ticket_reply',
            'njeri@example.co.ke',
            'Njeri Wambui',
            [
                'reference' => 'HD-000042',
                'subject' => 'Broken lift',
                'body' => '<b>An engineer</b> will visit.',
                'author_name' => 'Brian Kiptoo',
                'requester_name' => 'Njeri Wambui',
                'workspace_name' => 'Glee Hotel',
                'tracking_url' => 'http://localhost/track/abc',
            ],
        ));

        $this->assertSame('Re: HD-000042 — Broken lift', $reply->subject);
        $this->assertStringContainsString('Brian Kiptoo', $reply->html);
        $this->assertStringContainsString('&lt;b&gt;An engineer&lt;/b&gt;', $reply->html);
        $this->assertStringContainsString('<b>An engineer</b>', $reply->text);

        $requesterReply = $templates->render(new Notification(
            1,
            null,
            'ticket_requester_reply',
            'support@gleehotel.co.ke',
            'Glee Hotel',
            [
                'reference' => 'HD-000042',
                'subject' => 'Broken lift',
                'body' => 'The lift is still stuck.',
                'requester_name' => 'Njeri Wambui',
                'workspace_name' => 'Glee Hotel',
                'staff_url' => 'http://localhost/app/tickets/HD-000042',
            ],
        ));

        $this->assertSame('New requester reply on HD-000042', $requesterReply->subject);
        $this->assertStringContainsString('The lift is still stuck.', $requesterReply->html);
        $this->assertStringContainsString('http://localhost/app/tickets/HD-000042', $requesterReply->html);
    }

    public function test_unknown_event_types_fall_back_to_a_generic_template(): void
    {
        $message = $this->app->container()->get(MailTemplates::class)->render(new Notification(
            1,
            null,
            'ticket_sla_warning',
            'support@gleehotel.co.ke',
            null,
            [
                'reference' => 'HD-000007',
                'subject' => 'Due soon',
                'workspace_name' => 'Glee Hotel',
            ],
        ));

        $this->assertSame('Update on HD-000007', $message->subject);
        $this->assertStringContainsString('There is an update on your support request.', $message->html);
        $this->assertStringContainsString('HD-000007', $message->text);
    }

    public function test_the_email_channel_rejects_an_invalid_recipient(): void
    {
        $result = $this->app->container()->get(EmailChannel::class)->send(new Notification(
            1,
            null,
            'ticket_created',
            'not-an-email',
            null,
            [],
        ));

        $this->assertFalse($result->ok);
        $this->assertSame('The recipient email address is missing or invalid.', $result->error);
    }
}
