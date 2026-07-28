<?php

test('the versioned API health endpoint is available', function () {
    $response = $this->getJson(route('api.v1.health'))
        ->assertOk()
        ->assertJsonPath('data.service', 'procura-api')
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.version', 'v1')
        ->assertJsonPath('data.checks.database', 'ok')
        ->assertJsonPath('data.checks.cache', 'ok')
        ->assertJsonPath('data.checks.queues', 'not_monitored')
        ->assertJsonStructure([
            'data' => [
                'service',
                'status',
                'version',
                'timestamp',
                'checks' => [
                    'database',
                    'cache',
                    'queues',
                ],
            ],
        ]);

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('max-age=0');
});
