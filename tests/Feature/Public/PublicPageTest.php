<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class PublicPageTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsWorkspaces;

    public function test_the_public_form_submits_and_redirects_to_the_tracking_page(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $form = $this->publicFormPage($fixture['slug']);

        $this->assertSame(200, $form->status(), $form->content());
        $this->assertStringContainsString('Request support', $form->content());
        $this->assertStringContainsString('name="website"', $form->content());
        $this->assertStringContainsString('name="requester_email"', $form->content());
        $this->assertStringContainsString('name="_token"', $form->content());
        $this->assertStringContainsString('/request/' . $fixture['slug'] . '?lang=sw', $form->content());

        $submitted = $this->submitPublicForm($fixture['slug'], [
            'subject' => 'Leaking tap in the kitchen',
        ]);

        $this->assertSame(302, $submitted->status(), $submitted->content());
        $location = (string) $submitted->header('Location');
        $token = $this->tokenFromUrl($location);

        $page = $this->trackingPage($token);

        $this->assertSame(200, $page->status(), $page->content());
        $this->assertStringContainsString('Leaking tap in the kitchen', $page->content());
        $this->assertStringContainsString('HD-000001', $page->content());
    }

    public function test_a_honeypot_submission_returns_errors_to_the_form(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $response = $this->submitPublicForm($fixture['slug'], ['website' => 'https://spam.example']);

        $this->assertSame(302, $response->status(), $response->content());
        $this->assertSame('/request/' . $fixture['slug'], $response->header('Location'));

        $errors = $this->session->get('errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('website', $errors);
        $this->assertSame('Leave this field empty.', $errors['website']);
    }

    public function test_an_old_slug_redirects_to_the_current_slug(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $old = $fixture['slug'];
        $new = $this->uniqueSlug('glee-new');

        $change = $this->apiPatch('/api/v1/settings/slug', ['slug' => $new]);
        $this->assertSame(200, $change->status(), $change->content());
        $this->assertSame($new, $this->jsonBody($change)['data']['slug']);

        $redirect = $this->publicFormPage($old);

        $this->assertSame(301, $redirect->status(), $redirect->content());
        $this->assertSame('/request/' . $new, $redirect->header('Location'));

        $this->assertSame(200, $this->publicFormPage($new)->status());
        $this->assertSame(404, $this->publicConfiguration($old)->status());
    }

    public function test_kiswahili_renders_and_sticks_to_the_session(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $swahili = $this->publicFormPage($fixture['slug'], '?lang=sw');

        $this->assertSame(200, $swahili->status(), $swahili->content());
        $this->assertStringContainsString('Omba msaada', $swahili->content());
        $this->assertStringContainsString('Jina lako', $swahili->content());
        $this->assertStringContainsString('Kiswahili', $swahili->content());
        $this->assertStringContainsString('Kiingereza', $swahili->content());
        $this->assertStringContainsString('aria-current="true"', $swahili->content());

        $sticky = $this->publicFormPage($fixture['slug']);
        $this->assertStringContainsString('Omba msaada', $sticky->content());

        $english = $this->publicFormPage($fixture['slug'], '?lang=en');
        $this->assertStringContainsString('Request support', $english->content());
        $this->assertStringNotContainsString('Omba msaada', $english->content());
    }

    public function test_kiswahili_covers_tracking_flashes_and_statuses(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->publicFormPage($fixture['slug'], '?lang=sw');

        $submitted = $this->submitPublicForm($fixture['slug'], [
            'subject' => 'Maji ya moto hayapatikani',
        ]);

        $this->assertSame(302, $submitted->status(), $submitted->content());
        $token = $this->tokenFromUrl((string) $submitted->header('Location'));

        $page = $this->trackingPage($token);

        $this->assertSame(200, $page->status(), $page->content());
        $this->assertStringContainsString('Tumepokea ombi lako', $page->content());
        $this->assertStringContainsString('Mazungumzo', $page->content());
        $this->assertStringContainsString('Hakuna majibu bado', $page->content());
        $this->assertStringContainsString('status-open', $page->content());
        $this->assertStringContainsString('>Wazi<', $page->content());

        $replied = $this->replyOnTrackingPage($token, 'Bado hakuna maji ya moto.');

        $this->assertSame(302, $replied->status(), $replied->content());

        $after = $this->trackingPage($token);

        $this->assertStringContainsString('Jibu lako limetumwa', $after->content());
        $this->assertStringContainsString('Wewe', $after->content());
        $this->assertStringContainsString('Bado hakuna maji ya moto.', $after->content());
    }

    public function test_the_unavailable_page_is_localized(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->update(['status' => 'suspended']);

        $english = $this->publicFormPage($fixture['slug']);
        $this->assertSame(403, $english->status(), $english->content());
        $this->assertStringContainsString('This request form is unavailable', $english->content());

        $swahili = $this->publicFormPage($fixture['slug'], '?lang=sw');
        $this->assertSame(403, $swahili->status(), $swahili->content());
        $this->assertStringContainsString('Fomu ya maombi haipatikani', $swahili->content());
    }

    public function test_the_public_form_without_an_identifier_is_not_found(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');

        $response = $this->publicFormPage();

        $this->assertSame(404, $response->status(), $response->content());
    }
}
