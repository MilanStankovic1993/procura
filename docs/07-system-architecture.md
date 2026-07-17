# 07 — System Architecture

## 1. Stack

- Laravel 12
- PHP 8.3+
- MySQL 8+
- Redis
- Laravel Horizon
- Laravel Scheduler
- Laravel Sanctum
- Filament v5
- Livewire
- Tailwind CSS
- Laravel Cashier
- Pest
- S3-compatible production storage

## 2. Architecture style

Use a modular monolith for MVP.

Reasons:

- faster delivery,
- simpler deployment,
- easier transactions,
- lower operations cost,
- appropriate for early-stage product.

Modules must remain internally separated through:

- contracts,
- services,
- DTOs,
- events,
- policies,
- queues.

## 3. Application layers

```text
HTTP / Livewire / Filament
        ↓
Application services
        ↓
Domain services and policies
        ↓
Repositories or Eloquent models
        ↓
Database and external providers
```

## 3.1 Global market foundation

Global reference data must use stable standards:

- ISO 3166-1 alpha-2 for countries,
- ISO 4217 for currencies,
- BCP 47 for language tags,
- IANA identifiers for time zones.

Market context must be passed explicitly to pricing, comparable selection, shipping, customs, and tax services. The application must not infer a target market only from the user locale.

## 4. Queue pipeline

```text
CreateAnalysis
→ NormalizeInputJob
→ AnalyzeWithAiJob
→ MatchProductJob
→ BuildComparableSetJob
→ CalculatePriceEstimateJob
→ CalculateRiskJob
→ CalculateProfitJob
→ CalculateDealScoreJob
→ FinalizeAnalysisJob
```

Each step must be:

- retryable,
- independently observable,
- idempotent where possible,
- safe from duplicate execution.

## 5. Services

```text
AnalysisService
ListingIntakeService
ListingNormalizationService
AiListingAnalysisService
ProductMatchingService
ComparableSelectionService
PriceEstimationService
ProfitCalculationService
RiskAssessmentService
DealScoreService
SellRecommendationService
OutcomeTrackingService
SavedSearchMatchingService
AlertService
SubscriptionUsageService
```

## 6. API design

All future mobile and browser-extension clients should use versioned APIs:

```text
/api/v1/
```

Initial endpoints may include:

```text
POST /api/v1/buy-analyses
POST /api/v1/sell-analyses
GET  /api/v1/analyses/{id}
POST /api/v1/analyses/{id}/recalculate
POST /api/v1/analyses/{id}/outcome
GET  /api/v1/products/search
```

## 7. File storage

Original uploads must be private.

Use:

- private disks,
- signed URLs,
- image validation,
- file-size limits,
- normalized derivatives where necessary.

## 8. Observability

Track:

- queue runtime,
- failed jobs,
- AI latency,
- AI cost,
- analysis completion rate,
- product-match confidence,
- price-estimate confidence,
- notification failures,
- user conversion.

## 9. Deployment

Initial deployment can use:

- Ubuntu 24.04,
- nginx,
- PHP-FPM 8.3,
- MySQL 8,
- Redis,
- Supervisor,
- Horizon,
- cron for scheduler,
- GitHub Actions.

## 10. Scaling strategy

Do not split services prematurely.

Scale in this order:

1. optimize queries and indexes,
2. add Redis caching,
3. separate queue workers by workload,
4. move files to object storage,
5. add read replicas if needed,
6. split high-load connectors or AI processing only when justified.
