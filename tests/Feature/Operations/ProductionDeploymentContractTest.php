<?php

use Symfony\Component\Yaml\Yaml;

test('continuous integration exercises the production database and cache families', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $job = $workflow['jobs']['production-runtime'];
    $steps = collect($job['steps'])->keyBy('name');

    expect($workflow['concurrency']['cancel-in-progress'])
        ->toBeTrue()
        ->and($workflow['jobs']['tests']['timeout-minutes'])
        ->toBe(20)
        ->and($job['timeout-minutes'])
        ->toBe(20)
        ->and($job['services']['mysql']['image'])
        ->toBe('mysql:8.4')
        ->and($job['services']['redis']['image'])
        ->toBe('redis:7.4-alpine')
        ->and($job['env']['DB_CONNECTION'])
        ->toBe('mysql')
        ->and($job['env']['DB_USERNAME'])
        ->not->toBe('root')
        ->and($job['env']['CACHE_STORE'])
        ->toBe('redis')
        ->and($job['env']['QUEUE_CONNECTION'])
        ->toBe('redis')
        ->and($job['env']['DB_TIMEZONE'])
        ->toBe('+00:00')
        ->and($steps->get('Setup PHP')['with']['extensions'])
        ->toContain('pdo_mysql')
        ->toContain('redis')
        ->and($steps->get('Apply the real MySQL migration ledger')['run'])
        ->toBe('php artisan migrate:fresh --force')
        ->and($steps->get('Verify cached MySQL and Redis readiness')['run'])
        ->toContain('php artisan optimize')
        ->toContain('php artisan operations:readiness --json')
        ->toContain('php artisan optimize:clear')
        ->and($steps->get('Execute MySQL runtime contract')['run'])
        ->toContain('tests/Integration/MySqlRuntimeContractTest.php')
        ->and($steps->get('Execute MySQL runtime contract')['env']['CACHE_STORE'])
        ->toBe('array')
        ->and($steps->get('Execute MySQL runtime contract')['env']['QUEUE_CONNECTION'])
        ->toBe('sync');
});

test('the production build verifies every deploy contract', function () {
    $package = json_decode(
        file_get_contents(base_path('package.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $nginx = file_get_contents(
        base_path('deploy/nginx/procura.conf.example'),
    );

    expect($package['scripts']['build:frontend'])
        ->toContain('npm run verify:production-deployment')
        ->and($package['scripts']['verify:production-deployment'])
        ->toContain('verify:production-serving')
        ->toContain('tools/verify-production-deployment.mjs')
        ->and($nginx)
        ->toContain('add_header Permissions-Policy')
        ->toContain('fastcgi_param HTTP_PROXY "";')
        ->toContain('ssl_protocols TLSv1.2 TLSv1.3;');
});
