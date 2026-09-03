<?php

use App\Enums\Api\ApiErrorCode;
use App\Enums\Localization\SupportedLocale;
use App\Enums\Validation\ApplicationValidationCode;
use App\Exceptions\PrivacyRequestConflictException;
use App\Models\User;
use App\Support\Localization\ApiErrorLocalizer;
use App\Support\Localization\RequestLocaleResolver;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

dataset('localizedApiLanguages', [
    'English' => [
        'en-US,en;q=0.9',
        'en',
        'The name field is required.',
    ],
    'German' => [
        'de-AT,de;q=0.9,en;q=0.8',
        'de',
        'Das Feld Name ist erforderlich.',
    ],
    'Spanish' => [
        'es-MX,es;q=0.9,en;q=0.8',
        'es',
        'El campo nombre es obligatorio.',
    ],
    'French' => [
        'fr-CA,fr;q=0.9,en;q=0.8',
        'fr',
        'Le champ nom est obligatoire.',
    ],
    'Serbian Latin' => [
        'sr-RS,sr;q=0.9,en;q=0.8',
        'sr-Latn',
        'Polje ime je obavezno.',
    ],
]);

test('guest API validation follows supported browser languages', function (
    string $acceptLanguage,
    string $contentLanguage,
    string $expectedMessage,
) {
    $this->withHeader('Accept-Language', $acceptLanguage)
        ->postJson(route('api.v1.auth.register'), [])
        ->assertUnprocessable()
        ->assertHeader('Content-Language', $contentLanguage)
        ->assertJsonPath('errors.name.0', $expectedMessage);

    expect(App::currentLocale())->toBe(config('app.locale'));
})->with('localizedApiLanguages');

test('an authenticated personal locale overrides the request language', function () {
    $user = User::factory()->create([
        'preferred_locale' => SupportedLocale::French,
    ]);

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->patchJson(route('api.v1.me.preferences.update'), [
            'preferred_locale' => 'it',
        ])
        ->assertUnprocessable()
        ->assertHeader('Content-Language', 'fr')
        ->assertJsonPath(
            'errors.preferred_locale.0',
            'La valeur sélectionnée pour langue préférée est invalide.',
        );

    expect(App::currentLocale())->toBe(config('app.locale'))
        ->and($user->refresh()->preferred_locale)
        ->toBe(SupportedLocale::French);
});

test('an unsupported guest language falls back to English without leaking prior state', function () {
    $this->withHeader('Accept-Language', 'sr-RS,sr;q=0.9')
        ->postJson(route('api.v1.auth.register'), [])
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.name.0',
            'Polje ime je obavezno.',
        );

    $this->withHeader('Accept-Language', 'it-IT,it;q=0.9')
        ->postJson(route('api.v1.auth.register'), [])
        ->assertUnprocessable()
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath(
            'errors.name.0',
            'The name field is required.',
        );
});

test('authentication and privacy-preserving reset responses are localized', function () {
    $user = User::factory()->create([
        'password' => 'ProcuraSecurePass123!',
    ]);

    $this->withHeader('Accept-Language', 'es-ES,es;q=0.9')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])
        ->assertUnprocessable()
        ->assertHeader('Content-Language', 'es')
        ->assertJsonPath(
            'errors.email.0',
            'Estas credenciales no coinciden con nuestros registros.',
        );

    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->postJson(route('api.v1.auth.password.email'), [
            'email' => 'unknown@example.com',
        ])
        ->assertOk()
        ->assertHeader('Content-Language', 'de')
        ->assertExactJson([
            'message' => 'Falls ein Konto mit dieser E-Mail-Adresse existiert, wurde ein Link zum Zurücksetzen des Passworts gesendet.',
        ]);
});

test('server localization catalogs keep one complete key contract', function () {
    $referenceLocale = SupportedLocale::English;
    $referenceCatalogs = serverLocalizationCatalogs(
        $referenceLocale,
    );
    $expectedConflictCodes = array_map(
        static fn (ApiErrorCode $code): string => $code->value,
        ApiErrorCode::cases(),
    );
    $expectedValidationCodes = array_map(
        static fn (ApplicationValidationCode $code): string => $code->value,
        ApplicationValidationCode::cases(),
    );

    expect(array_keys($referenceCatalogs['api_errors']))
        ->toBe($expectedConflictCodes)
        ->and(array_keys($referenceCatalogs['application_validation']))
        ->toBe($expectedValidationCodes);

    foreach (SupportedLocale::cases() as $locale) {
        $catalogs = serverLocalizationCatalogs($locale);
        $request = Request::create('/api/v1/localization-contract');
        $request->attributes->set(
            RequestLocaleResolver::REQUEST_ATTRIBUTE,
            $locale->value,
        );

        expect(
            app(ApiErrorLocalizer::class)->message(
                ApiErrorCode::BuyerDecisionStaleState,
                $request,
            ),
        )->toBe(
            $catalogs['api_errors']['buyer_decision_stale_state'],
        )->and(App::currentLocale())
            ->toBe(config('app.locale'));

        foreach ($referenceCatalogs as $catalog => $reference) {
            $referenceKeys = array_keys(Arr::dot($reference));
            $localized = Arr::dot($catalogs[$catalog]);

            expect(array_keys($localized))
                ->toBe(
                    $referenceKeys,
                    "{$catalog} keys differ for {$locale->value}.",
                )
                ->and(array_filter(
                    $localized,
                    static fn (mixed $value): bool => (
                        ! is_string($value)
                        || trim($value) === ''
                    ),
                ))
                ->toBe(
                    [],
                    "{$catalog} contains an empty value for {$locale->value}.",
                );
        }

        if ($locale === SupportedLocale::English) {
            continue;
        }

        foreach ($expectedConflictCodes as $code) {
            expect($catalogs['api_errors'][$code])
                ->not->toBe(
                    $referenceCatalogs['api_errors'][$code],
                    "{$code} falls back to English for {$locale->value}.",
                );
        }

        foreach ($expectedValidationCodes as $code) {
            expect($catalogs['application_validation'][$code])
                ->not->toBe(
                    $referenceCatalogs['application_validation'][$code],
                    "{$code} falls back to English for {$locale->value}.",
                );
        }
    }

    Route::middleware('api')->get(
        '/api/v1/localization-conflict-contract',
        static function (): never {
            throw new PrivacyRequestConflictException(
                ApiErrorCode::PrivacyRequestStaleState,
                'expected_current_event_id',
                'Sensitive internal diagnostic detail.',
            );
        },
    );

    $response = $this
        ->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->getJson('/api/v1/localization-conflict-contract')
        ->assertConflict()
        ->assertHeader('Content-Language', 'de')
        ->assertJsonPath('code', 'privacy_request_stale_state')
        ->assertJsonPath(
            'message',
            'Die Datenschutzanfrage wurde geändert. Aktualisieren Sie sie, bevor Sie einen weiteren Übergang erfassen.',
        )
        ->assertJsonPath(
            'errors.expected_current_event_id.0',
            'Die Datenschutzanfrage wurde geändert. Aktualisieren Sie sie, bevor Sie einen weiteren Übergang erfassen.',
        );

    expect($response->getContent())
        ->not->toContain('Sensitive internal diagnostic detail.');

    Route::middleware('api')->get(
        '/api/v1/localization-validation-contract',
        static fn (): never => ApplicationValidation::fail(
            'telegram',
            ApplicationValidationCode::TelegramNotConfigured,
        ),
    );

    $this
        ->withHeader('Accept-Language', 'sr-RS,sr;q=0.9')
        ->getJson('/api/v1/localization-validation-contract')
        ->assertUnprocessable()
        ->assertHeader('Content-Language', 'sr-Latn')
        ->assertJsonPath(
            'errors.telegram.0',
            'Telegram nije podešen za ovo okruženje.',
        );

    expect(App::currentLocale())->toBe(config('app.locale'));
});

test('migrated application services do not create ad hoc validation messages', function () {
    $migratedFiles = [
        'app/Actions/Analyses/ConfirmAnalysisCosts.php',
        'app/Actions/Analyses/ConfirmOpportunityEvidence.php',
        'app/Actions/Analyses/CreateAnalysisComparable.php',
        'app/Actions/Analyses/CreateComparableMarketNormalization.php',
        'app/Actions/Analyses/CreateListingComparableIdentity.php',
        'app/Actions/Analyses/RecordBuyerDecision.php',
        'app/Actions/Analyses/RequestManualAnalysisRetry.php',
        'app/Actions/Listings/StoreListingImages.php',
        'app/Actions/Monitoring/BeginTelegramConnection.php',
        'app/Actions/Monitoring/RecordNotificationState.php',
        'app/Actions/Monitoring/UpdateSavedSearch.php',
        'app/Actions/OwnedProducts/AssessOwnedProduct.php',
        'app/Actions/OwnedProducts/CreateSalePortfolioEntry.php',
        'app/Actions/OwnedProducts/CreateSellComparable.php',
        'app/Actions/OwnedProducts/CreateSellComparableMarketNormalization.php',
        'app/Actions/OwnedProducts/CreateSellListingDraft.php',
        'app/Actions/OwnedProducts/DeleteOwnedProductImage.php',
        'app/Actions/OwnedProducts/RecordActualCostSnapshot.php',
        'app/Actions/OwnedProducts/RecordActualPurchase.php',
        'app/Actions/OwnedProducts/RecordActualSale.php',
        'app/Actions/OwnedProducts/RecordEstimateAccuracyAttribution.php',
        'app/Actions/OwnedProducts/RecordSalePortfolioEvent.php',
        'app/Actions/OwnedProducts/StoreOwnedProductImages.php',
        'app/Actions/OwnedProducts/UpdateOwnedProduct.php',
        'app/Actions/Organizations/ManageOrganizationInvitations.php',
        'app/Actions/Organizations/ManageOrganizationMembers.php',
        'app/Actions/Privacy/CreatePrivacyRequest.php',
        'app/Actions/Privacy/PrivacyRequestEventRecorder.php',
        'app/Http/Controllers/Api/V1/BeginTelegramConnectionController.php',
        'app/Http/Controllers/Api/V1/Products/SearchProductController.php',
        'app/Monitoring/SavedSearchCriteriaValidator.php',
        'app/Monitoring/SavedSearchNotificationEntitlements.php',
        'app/OutcomeTracking/OutcomeMoneyNormalizer.php',
        'app/Support/Uploads/ImageUploadInspector.php',
    ];

    foreach ($migratedFiles as $file) {
        expect(File::get(base_path($file)))
            ->not->toContain('ValidationException::withMessages')
            ->toContain('ApplicationValidation');
    }
});

test('every validation rule used by Procura has a non-English server message', function () {
    $ruleKeys = [
        'accepted',
        'alpha_num',
        'array',
        'before_or_equal',
        'boolean',
        'confirmed',
        'current_password',
        'date',
        'dimensions',
        'distinct',
        'email',
        'enum',
        'exists',
        'extensions',
        'file',
        'in',
        'integer',
        'max.array',
        'max.file',
        'max.numeric',
        'max.string',
        'mimes',
        'mimetypes',
        'min.array',
        'min.file',
        'min.numeric',
        'min.string',
        'not_in',
        'password.letters',
        'password.mixed',
        'password.numbers',
        'password.symbols',
        'password.uncompromised',
        'present',
        'regex',
        'required',
        'required_if',
        'required_with',
        'required_without',
        'size.array',
        'size.file',
        'size.numeric',
        'size.string',
        'string',
        'timezone',
        'unique',
        'uploaded',
        'url',
        'ulid',
        'uuid',
    ];
    $english = Arr::dot(
        serverLocalizationCatalogs(
            SupportedLocale::English,
        )['validation'],
    );

    foreach (
        array_filter(
            SupportedLocale::cases(),
            static fn (SupportedLocale $locale): bool => (
                $locale !== SupportedLocale::English
            ),
        ) as $locale
    ) {
        $localized = Arr::dot(
            serverLocalizationCatalogs($locale)['validation'],
        );

        foreach ($ruleKeys as $key) {
            expect($localized[$key])
                ->not->toBe(
                    $english[$key],
                    "{$key} falls back to English for {$locale->value}.",
                );
        }
    }
});

test('every current form-request field has a translated attribute contract', function () {
    $fieldNames = [];

    foreach (
        File::allFiles(app_path('Http/Requests')) as $file
    ) {
        preg_match_all(
            "/^\\s*'([^']+)'\\s*=>/m",
            $file->getContents(),
            $matches,
        );
        $fieldNames = [
            ...$fieldNames,
            ...$matches[1],
        ];
    }

    $fieldNames = array_values(array_unique(array_diff(
        $fieldNames,
        ['*.max'],
    )));
    sort($fieldNames);

    foreach (SupportedLocale::cases() as $locale) {
        $attributes = require lang_path(
            $locale->laravelLocale()
                .'/validation_attributes.php',
        );
        $missing = array_values(array_diff(
            $fieldNames,
            array_keys($attributes),
        ));

        expect($missing)->toBe(
            [],
            "Validation attributes are missing for {$locale->value}.",
        );
    }
});

/**
 * @return array<string, array<string, mixed>>
 */
function serverLocalizationCatalogs(
    SupportedLocale $locale,
): array {
    $directory = lang_path($locale->laravelLocale());

    return [
        'admin' => require "{$directory}/admin.php",
        'api_errors' => require "{$directory}/api_errors.php",
        'application_validation' => require "{$directory}/application_validation.php",
        'auth' => require "{$directory}/auth.php",
        'json' => json_decode(
            File::get(lang_path(
                $locale->laravelLocale().'.json',
            )),
            true,
            flags: JSON_THROW_ON_ERROR,
        ),
        'monitoring' => require "{$directory}/monitoring.php",
        'passwords' => require "{$directory}/passwords.php",
        'validation' => require "{$directory}/validation.php",
    ];
}
