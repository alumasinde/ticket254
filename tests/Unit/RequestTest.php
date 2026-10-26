<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_method_override_from_form_field(): void
    {
        $request = Request::create('POST', '/tickets/HD-000123', ['_method' => 'PATCH']);

        $this->assertSame('PATCH', $request->method());
        $this->assertTrue($request->isMethod('patch'));
    }

    public function test_body_wins_over_query_input(): void
    {
        $request = Request::create('POST', '/search?term=query', ['term' => 'body']);

        $this->assertSame('body', $request->input('term'));
        $this->assertSame('query', $request->query()['term']);
    }

    public function test_get_data_stays_in_query(): void
    {
        $request = Request::create('GET', '/tickets?status=open');

        $this->assertSame('open', $request->input('status'));
        $this->assertSame([], $request->body());
    }

    public function test_path_is_normalized_and_decoded(): void
    {
        $this->assertSame('/', Request::normalizePath('/'));
        $this->assertSame('/tickets', Request::normalizePath('/tickets/'));
        $this->assertSame('/a b/c', Request::normalizePath('/a%20b/c/'));
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $request = Request::create('GET', '/', [], ['Accept' => 'application/json']);

        $this->assertSame('application/json', $request->header('accept'));
        $this->assertTrue($request->expectsJson());
    }

    public function test_json_expectation_from_ajax_header(): void
    {
        $request = Request::create('GET', '/', [], ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertTrue($request->expectsJson());
    }

    public function test_route_parameters_are_readable(): void
    {
        $request = Request::create('GET', '/tickets/HD-000123');
        $request->setRouteParams(['ref' => 'HD-000123']);

        $this->assertSame('HD-000123', $request->routeParam('ref'));
        $this->assertSame(['ref' => 'HD-000123'], $request->routeParams());
    }

    public function test_files_are_exposed(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            [],
            [],
            ['photo' => ['name' => 'photo.jpg', 'error' => UPLOAD_ERR_OK]],
        );

        $this->assertSame('photo.jpg', $request->file('photo')['name']);
        $this->assertNull($request->file('missing'));
    }
}
