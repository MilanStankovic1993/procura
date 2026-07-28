<?php

use App\Actions\Administration\AssignOrganizationPlan;
use App\Actions\Administration\BootstrapSuperAdmin;
use App\Actions\Privacy\CreatePrivacyRequest;
use App\Enums\Localization\SupportedLocale;
use App\Filament\Resources\AnalysisOperations\AnalysisOperationResource;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\BillingProviderEvents\BillingProviderEventResource;
use App\Filament\Resources\ComparableMarketNormalizations\ComparableMarketNormalizationResource;
use App\Filament\Resources\Countries\CountryResource;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\Memberships\MembershipResource;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\PlanFeatures\PlanFeatureResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\PrivacyRequests\PrivacyRequestResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\TelegramConnections\TelegramConnectionResource;
use App\Filament\Resources\Usages\UsageResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\BillingProviderEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAuditEvent;
use App\Models\PrivacyRequest;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

function superAdmin(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->forceFill(['is_super_admin' => true])->save();

    return $user->fresh();
}

test('ordinary and unverified users cannot access the Filament administration panel', function () {
    $ordinaryUser = User::factory()->create();
    $unverifiedAdmin = superAdmin(['email_verified_at' => null]);

    expect($ordinaryUser->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($unverifiedAdmin->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();

    $this->actingAs($ordinaryUser)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertForbidden();

    $this->actingAs($unverifiedAdmin)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertForbidden();
});

test('verified super administrators can access operational resources while resource creation remains disabled', function () {
    $this->seed(PlanSeeder::class);
    $admin = superAdmin();

    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(UserResource::canCreate())->toBeFalse()
        ->and(OrganizationResource::canCreate())->toBeFalse()
        ->and(PlanResource::canCreate())->toBeFalse()
        ->and(AuditEventResource::canCreate())->toBeFalse()
        ->and(AnalysisOperationResource::canCreate())->toBeFalse()
        ->and(ComparableMarketNormalizationResource::canCreate())->toBeFalse()
        ->and(PrivacyRequestResource::canCreate())->toBeFalse();

    $this->actingAs($admin)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertOk()
        ->assertSee('Procura Operations');

    foreach ([
        UserResource::class,
        OrganizationResource::class,
        MembershipResource::class,
        PlanResource::class,
        PlanFeatureResource::class,
        SubscriptionResource::class,
        UsageResource::class,
        CountryResource::class,
        CurrencyResource::class,
        AuditEventResource::class,
        AnalysisOperationResource::class,
        NotificationDeliveryResource::class,
        TelegramConnectionResource::class,
        BillingProviderEventResource::class,
        ComparableMarketNormalizationResource::class,
        PrivacyRequestResource::class,
    ] as $resource) {
        $this->actingAs($admin)
            ->get($resource::getUrl())
            ->assertOk();
    }
});

test('privacy operations expose workflow evidence without internal request hashes', function () {
    $subject = User::factory()->create([
        'email' => 'privacy-subject@example.test',
    ]);
    app(CreatePrivacyRequest::class)->create($subject, [
        'type' => 'data_export',
        'residence_country_code' => null,
        'reason' => 'Provide a portable copy of the account data.',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $privacyRequest = PrivacyRequest::query()->sole();

    $this->actingAs(User::factory()->create())
        ->get(PrivacyRequestResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(PrivacyRequestResource::getUrl())
        ->assertOk()
        ->assertSeeText('privacy-subject@example.test')
        ->assertSeeText('Data export')
        ->assertSeeText('Requested')
        ->assertSeeText('Request submitted')
        ->assertDontSee($privacyRequest->requester_email_hash)
        ->assertDontSee($privacyRequest->payload_hash)
        ->assertDontSee($privacyRequest->idempotency_key);
});

test('billing operations expose safe translated events only to verified super administrators', function () {
    $organization = Organization::factory()->create([
        'name' => 'Billing Operations Workspace',
    ]);
    $event = BillingProviderEvent::query()->create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_sensitive_provider_event',
        'organization_id' => $organization->getKey(),
        'event_type' => 'customer.subscription.updated',
        'provider_customer_id' => 'cus_sensitive_customer',
        'provider_subscription_id' => 'sub_sensitive_subscription',
        'provider_price_id' => 'price_sensitive_price',
        'provider_status' => 'active',
        'payload_sha256' => str_repeat('a', 64),
        'livemode' => true,
        'outcome' => 'applied',
        'reason_code' => 'paid_plan_projected',
        'projected_plan_code' => 'pro',
        'occurred_at' => now(),
        'processed_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(BillingProviderEventResource::getUrl())
        ->assertForbidden();

    $this->actingAs(superAdmin())
        ->get(BillingProviderEventResource::getUrl())
        ->assertOk()
        ->assertSeeText('Billing Operations Workspace')
        ->assertSeeText('Subscription updated')
        ->assertSeeText('Paid plan projected')
        ->assertDontSee($event->provider_event_id)
        ->assertDontSee($event->provider_customer_id)
        ->assertDontSee($event->provider_subscription_id)
        ->assertDontSee($event->provider_price_id)
        ->assertDontSee($event->payload_sha256);
});

test('admin translation catalogs have one complete shared key contract', function () {
    $catalogs = [
        'en' => lang_path('en/admin.php'),
        'de' => lang_path('de/admin.php'),
        'es' => lang_path('es/admin.php'),
        'fr' => lang_path('fr/admin.php'),
        'sr_Latn' => lang_path('sr_Latn/admin.php'),
    ];
    $referenceKeys = array_keys(Arr::dot(require $catalogs['en']));

    foreach ($catalogs as $locale => $path) {
        $translations = Arr::dot(require $path);

        expect(array_keys($translations))
            ->toBe($referenceKeys, "Admin translation keys differ for {$locale}.")
            ->and(array_filter(
                $translations,
                static fn (mixed $value): bool => ! is_string($value) || trim($value) === '',
            ))
            ->toBe([], "Admin translations contain empty values for {$locale}.");
    }
});

test('Filament admin controls use localized application overrides without English fallbacks', function (
    SupportedLocale $locale,
    string $skipToContent,
    string $theme,
    string $navigation,
    string $topbar,
    string $closeNotification,
    string $yes,
    string $no,
    string $results,
) {
    App::setLocale($locale->laravelLocale());

    expect(__('filament-panels::layout.skip_to_content.label'))->toBe($skipToContent)
        ->and(__('filament-panels::layout.actions.theme_switcher.label'))->toBe($theme)
        ->and(__('filament-panels::layout.navigation.label'))->toBe($navigation)
        ->and(__('filament-panels::layout.topbar.label'))->toBe($topbar)
        ->and(__('filament-notifications::notification.actions.close.label'))->toBe($closeNotification)
        ->and(__('filament-tables::table.columns.icon.boolean.true'))->toBe($yes)
        ->and(__('filament-tables::table.columns.icon.boolean.false'))->toBe($no)
        ->and(trans_choice('filament-tables::table.result_count', 6, ['count' => 6]))->toBe($results);
})->with([
    'English controls' => [
        SupportedLocale::English,
        'Skip to content',
        'Theme',
        'Sidebar navigation',
        'Topbar',
        'Close notification',
        'Yes',
        'No',
        '6 results',
    ],
    'German controls' => [
        SupportedLocale::German,
        'Zum Inhalt springen',
        'Darstellung',
        'Seitennavigation',
        'Kopfzeile',
        'Benachrichtigung schließen',
        'Ja',
        'Nein',
        '6 Ergebnisse',
    ],
    'Spanish controls' => [
        SupportedLocale::Spanish,
        'Saltar al contenido',
        'Tema',
        'Barra de navegación lateral',
        'Barra superior',
        'Cerrar notificación',
        'Sí',
        'No',
        '6 resultados',
    ],
    'French controls' => [
        SupportedLocale::French,
        'Aller au contenu',
        'Thème',
        'Navigation latérale',
        'Barre supérieure',
        'Fermer la notification',
        'Oui',
        'Non',
        '6 résultats',
    ],
    'Serbian Latin controls' => [
        SupportedLocale::SerbianLatin,
        'Preskoči na sadržaj',
        'Tema',
        'Navigacija bočne trake',
        'Gornja traka',
        'Zatvori obaveštenje',
        'Da',
        'Ne',
        '6 rezultata',
    ],
]);

test('the admin panel follows the authenticated personal interface locale', function (
    SupportedLocale $locale,
    string $serverLocale,
    string $brand,
    string $users,
    string $identity,
    string $accounts,
    string $dashboard,
    string $readiness,
) {
    $admin = superAdmin(['preferred_locale' => $locale]);

    $this->actingAs($admin)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertOk()
        ->assertSeeText($brand)
        ->assertSeeText($users)
        ->assertSeeText($identity)
        ->assertSeeText($accounts)
        ->assertSeeText($dashboard)
        ->assertSeeText($readiness);

    expect(app()->getLocale())->toBe(config('app.locale'));

    try {
        App::setLocale($serverLocale);

        expect(UserResource::getNavigationLabel())->toBe($users);
    } finally {
        App::setLocale(config('app.locale'));
    }
})->with([
    'English' => [
        SupportedLocale::English,
        'en',
        'Procura Operations',
        'Users',
        'Identity',
        'Registered accounts',
        'Dashboard',
        'Core ready',
    ],
    'German' => [
        SupportedLocale::German,
        'de',
        'Procura-Betrieb',
        'Benutzer',
        'Identität',
        'Registrierte Konten',
        'Dashboard',
        'Basis bereit',
    ],
    'Spanish' => [
        SupportedLocale::Spanish,
        'es',
        'Operaciones de Procura',
        'Usuarios',
        'Identidad',
        'Cuentas registradas',
        'Escritorio',
        'Núcleo preparado',
    ],
    'French' => [
        SupportedLocale::French,
        'fr',
        'Opérations Procura',
        'Utilisateurs',
        'Identité',
        'Comptes enregistrés',
        'Tableau de bord',
        'Socle prêt',
    ],
    'Serbian Latin' => [
        SupportedLocale::SerbianLatin,
        'sr_Latn',
        'Procura operacije',
        'Korisnici',
        'Identitet',
        'Registrovani nalozi',
        'Nadzorna tabla',
        'Osnova spremna',
    ],
]);

test('the guest admin login resolves a supported browser locale without persisting it', function () {
    $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9,en;q=0.8')
        ->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSeeText('Opérations Procura')
        ->assertSeeText('Connectez-vous à votre compte')
        ->assertSeeText('Adresse e-mail');

    expect(app()->getLocale())->toBe(config('app.locale'));
});

test('organization plan assignment is super-admin only transactional and audited', function () {
    $this->seed(PlanSeeder::class);

    $organization = Organization::factory()->create();
    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    $ordinaryUser = User::factory()->create();
    $unverifiedAdmin = superAdmin(['email_verified_at' => null]);
    $admin = superAdmin();
    $assign = app(AssignOrganizationPlan::class);

    expect(fn () => $assign->assign(
        $organization,
        $starter,
        $ordinaryUser,
        'Attempted unauthorized plan assignment.',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $assign->assign(
        $organization,
        $starter,
        $unverifiedAdmin,
        'Attempted unverified administrator plan assignment.',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $assign->assign(
        $organization,
        $starter,
        $admin,
        'short',
    ))->toThrow(InvalidArgumentException::class);

    $first = $assign->assign(
        organization: $organization,
        plan: $starter,
        actor: $admin,
        reason: 'Approved Starter plan for the pilot workspace.',
        ipAddress: '127.0.0.1',
        userAgent: 'Procura administration test',
    );
    $second = $assign->assign(
        organization: $organization,
        plan: $pro,
        actor: $admin,
        reason: 'Expanded analysis allowance after operational review.',
        ipAddress: '127.0.0.1',
        userAgent: 'Procura administration test',
    );

    expect($first->organization_id)->toBe($organization->getKey())
        ->and($second->fresh()->plan_id)->toBe($pro->getKey())
        ->and(PlatformAuditEvent::query()->count())->toBe(2);

    $event = PlatformAuditEvent::query()->orderByDesc('id')->firstOrFail();

    expect($event->actor_user_id)->toBe($admin->getKey())
        ->and($event->organization_id)->toBe($organization->getKey())
        ->and($event->action)->toBe('organization.plan_assigned')
        ->and($event->old_values['plan_id'])->toBe($starter->getKey())
        ->and($event->new_values['plan_id'])->toBe($pro->getKey())
        ->and($event->reason)->toContain('operational review')
        ->and($event->ip_address)->toBe('127.0.0.1');

    $pro->forceFill(['is_active' => false])->save();

    expect(fn () => $assign->assign(
        $organization,
        $pro,
        $admin,
        'Attempted assignment of an inactive plan version.',
    ))->toThrow(LogicException::class);
});

test('reassigning the same plan is idempotent and does not create a false audit event', function () {
    $this->seed(PlanSeeder::class);

    $organization = Organization::factory()->create();
    $plan = Plan::query()->where('code', 'free')->firstOrFail();
    $admin = superAdmin();
    $assign = app(AssignOrganizationPlan::class);

    $assign->assign($organization, $plan, $admin, 'Initial explicit Free plan assignment.');
    $assign->assign($organization, $plan, $admin, 'Duplicate delivery of the same operation.');

    expect(PlatformAuditEvent::query()->count())->toBe(1);
});

test('bootstraps exactly one verified super administrator with an audit trail', function () {
    $candidate = User::factory()->create([
        'email' => 'first-admin@example.test',
        'email_verified_at' => now(),
    ]);

    $this->artisan('admin:bootstrap-super-admin', [
        'email' => $candidate->email,
        '--reason' => 'Initial production administrator bootstrap.',
    ])->assertSuccessful();

    expect($candidate->refresh()->is_super_admin)->toBeTrue();

    $event = PlatformAuditEvent::query()->sole();

    expect($event->actor_user_id)->toBe($candidate->id)
        ->and($event->action)->toBe('user.super_admin_bootstrapped')
        ->and($event->subject_type)->toBe($candidate->getMorphClass())
        ->and($event->subject_id)->toBe((string) $candidate->id)
        ->and($event->old_values)->toBe(['is_super_admin' => false])
        ->and($event->new_values)->toBe(['is_super_admin' => true])
        ->and($event->reason)->toBe('Initial production administrator bootstrap.');

    $secondCandidate = User::factory()->create(['email_verified_at' => now()]);

    $this->artisan('admin:bootstrap-super-admin', [
        'email' => $secondCandidate->email,
        '--reason' => 'Attempted second administrator bootstrap.',
    ])->assertFailed();

    expect($secondCandidate->refresh()->is_super_admin)->toBeFalse()
        ->and(PlatformAuditEvent::query()->count())->toBe(1);
});

test('rejects an unverified or unexplained super administrator bootstrap', function () {
    $unverified = User::factory()->unverified()->create();

    expect(fn () => app(BootstrapSuperAdmin::class)->execute(
        $unverified,
        'Initial production administrator bootstrap.',
    ))->toThrow(LogicException::class);

    $verified = User::factory()->create(['email_verified_at' => now()]);

    expect(fn () => app(BootstrapSuperAdmin::class)->execute($verified, 'short'))
        ->toThrow(InvalidArgumentException::class);

    expect(User::query()->where('is_super_admin', true)->exists())->toBeFalse()
        ->and(PlatformAuditEvent::query()->exists())->toBeFalse();
});
