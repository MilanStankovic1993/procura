import {
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  Injector,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize, map } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import {
  Analysis,
  AnalysisStatus,
  ComparableEvidence,
  ComparableInput,
} from '../../../core/analysis.models';
import { AnalysisService } from '../../../core/analysis.service';
import { AnalysisCodeLabelService } from '../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { parseMoneyToMinor } from '../../../core/listing-money';
import { MarketReferenceCatalog } from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { BuyerDecisionPanelComponent } from './buyer-decision-panel/buyer-decision-panel.component';
import { ComparableMarketNormalizationComponent } from './comparable-market-normalization/comparable-market-normalization.component';
import { CostProfitPanelComponent } from './cost-profit-panel/cost-profit-panel.component';
import { DealScorePanelComponent } from './deal-score-panel/deal-score-panel.component';
import { OpportunityEvidencePanelComponent } from './opportunity-evidence-panel/opportunity-evidence-panel.component';
import { PriceEstimatePanelComponent } from './price-estimate-panel/price-estimate-panel.component';
import { RiskAssessmentPanelComponent } from './risk-assessment-panel/risk-assessment-panel.component';

@Component({
  selector: 'app-analysis-detail-page',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
    BuyerDecisionPanelComponent,
    ComparableMarketNormalizationComponent,
    CostProfitPanelComponent,
    DealScorePanelComponent,
    OpportunityEvidencePanelComponent,
    PriceEstimatePanelComponent,
    RiskAssessmentPanelComponent,
  ],
  templateUrl: './analysis-detail.page.html',
  styleUrl: './analysis-detail.page.scss',
})
export class AnalysisDetailPage {
  private readonly analyses = inject(AnalysisService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly markets = inject(MarketReferenceService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly injector = inject(Injector);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);
  private readonly routeAnalysisId = toSignal(
    this.route.paramMap.pipe(map((parameters) => parameters.get('analysisId'))),
    { initialValue: null, injector: this.injector },
  );
  private readonly routeListingId = toSignal(
    this.route.paramMap.pipe(map((parameters) => parameters.get('listingId'))),
    { initialValue: null, injector: this.injector },
  );
  private loadedContextKey: string | null = null;
  private pollTimer: ReturnType<typeof setTimeout> | null = null;

  protected readonly analysis = signal<Analysis | null>(null);
  protected readonly loading = signal(true);
  protected readonly refreshing = signal(false);
  protected readonly submitting = signal(false);
  protected readonly savingComparable = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly comparableError = signal<string | null>(null);
  protected readonly comparablePriceError = signal<string | null>(null);
  protected readonly marketCatalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly canManage = computed(
    () =>
      this.organizations.activeOrganization()?.capabilities.includes('analyses.manage') ===
      true,
  );
  protected readonly comparableForm = this.formBuilder.nonNullable.group({
    marketplace_name: ['', [Validators.required, Validators.maxLength(160)]],
    source_url: ['', [Validators.maxLength(2048)]],
    external_id: ['', [Validators.maxLength(128)]],
    title: ['', [Validators.required, Validators.maxLength(240)]],
    description: ['', [Validators.maxLength(50000)]],
    listing_type: ['product', [Validators.required]],
    condition_code: ['unknown', [Validators.required]],
    seller_type: ['unknown', [Validators.required]],
    price: ['', [Validators.required, Validators.maxLength(32)]],
    currency_code: ['', [Validators.required]],
    country_code: ['', [Validators.required]],
    location: ['', [Validators.maxLength(255)]],
    included_accessories: ['', [Validators.maxLength(4000)]],
    missing_accessories: ['', [Validators.maxLength(4000)]],
    published_at: [''],
    observed_at: ['', [Validators.required]],
  });

  constructor() {
    this.loadMarketCatalog();
    this.destroyRef.onDestroy(() => this.clearPoll());
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;
      const analysisId = this.routeAnalysisId();
      const listingId = this.routeListingId();
      const contextKey =
        organizationId !== null && analysisId !== null && listingId !== null
          ? `${organizationId}:${listingId}:${analysisId}`
          : null;

      if (contextKey !== null && contextKey !== this.loadedContextKey) {
        this.loadedContextKey = contextKey;
        this.analysis.set(null);
        this.load();
      }
    });
  }

  protected refresh(): void {
    this.load(true);
  }

  protected submit(): void {
    const record = this.analysis();
    if (record === null || record.status !== 'draft' || !this.canManage()) {
      return;
    }

    this.clearPoll();
    this.submitting.set(true);
    this.error.set(null);
    this.success.set(null);
    this.analyses
      .submit(record.id)
      .pipe(finalize(() => this.submitting.set(false)))
      .subscribe({
        next: (analysis) => {
          this.analysis.set(analysis);
          this.success.set(
            this.i18n.translate('analysisDetail.queued'),
          );
          this.schedulePoll(analysis.status);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('analysisDetail.submitError'),
            ),
          );
        },
      });
  }

  protected submitComparable(): void {
    const record = this.analysis();
    const match = record?.product_match ?? null;

    if (
      record === null ||
      match === null ||
      match.status !== 'matched' ||
      !this.canManage()
    ) {
      this.comparableError.set(
        this.i18n.translate('analysisDetail.matchRequired'),
      );
      return;
    }

    if (this.comparableForm.invalid) {
      this.comparableForm.markAllAsTouched();
      this.comparableError.set(
        this.i18n.translate('analysisDetail.comparableFields'),
      );
      return;
    }

    const value = this.comparableForm.getRawValue();

    if (value.source_url.trim() === '' && value.external_id.trim() === '') {
      this.comparableError.set(
        this.i18n.translate('analysisDetail.sourceRequired'),
      );
      return;
    }

    const catalog = this.marketCatalog();
    const minorUnit =
      catalog?.currencies.find((currency) => currency.code === value.currency_code)
        ?.minor_unit ?? 2;
    let askingPriceMinor: number;
    this.comparablePriceError.set(null);

    try {
      const parsed = parseMoneyToMinor(value.price, minorUnit);

      if (parsed === null || parsed < 1) {
        this.comparablePriceError.set(
          this.i18n.translate('analysisDetail.positivePrice'),
        );
        this.comparableError.set(this.i18n.translate('analysisDetail.reviewPrice'));
        return;
      }

      askingPriceMinor = parsed;
    } catch {
      this.comparablePriceError.set(this.i18n.translate('analysisDetail.validPrice'));
      this.comparableError.set(this.i18n.translate('analysisDetail.reviewPrice'));
      return;
    }

    const observedAt = this.isoDate(value.observed_at);
    const publishedAt = value.published_at === '' ? null : this.isoDate(value.published_at);

    if (observedAt === null || (value.published_at !== '' && publishedAt === null)) {
      this.comparableError.set(this.i18n.translate('analysisDetail.timestamps'));
      return;
    }

    const input: ComparableInput = {
      marketplace_source_key: 'manual',
      product_variant_id: match.product?.variant_id ?? null,
      source_url: this.nullIfBlank(value.source_url),
      external_id: this.nullIfBlank(value.external_id),
      marketplace_name: value.marketplace_name.trim(),
      title: value.title.trim(),
      description: this.nullIfBlank(value.description),
      listing_type: value.listing_type as ComparableInput['listing_type'],
      condition_code: value.condition_code as ComparableInput['condition_code'],
      seller_type: value.seller_type as ComparableInput['seller_type'],
      asking_price_minor: askingPriceMinor,
      currency_code: value.currency_code,
      country_code: value.country_code,
      location: this.nullIfBlank(value.location),
      included_accessories: this.labels(value.included_accessories),
      missing_accessories: this.labels(value.missing_accessories),
      published_at: publishedAt,
      observed_at: observedAt,
    };

    this.savingComparable.set(true);
    this.comparableError.set(null);
    this.analyses
      .createComparable(record.id, input)
      .pipe(finalize(() => this.savingComparable.set(false)))
      .subscribe({
        next: (response) => {
          this.success.set(
            response.created
              ? this.i18n.translate('analysisDetail.comparableCreated')
              : this.i18n.translate('analysisDetail.comparableDuplicate'),
          );
          this.comparableForm.reset({
            marketplace_name: '',
            source_url: '',
            external_id: '',
            title: '',
            description: '',
            listing_type: 'product',
            condition_code: 'unknown',
            seller_type: 'unknown',
            price: '',
            currency_code: input.currency_code,
            country_code: input.country_code,
            location: '',
            included_accessories: '',
            missing_accessories: '',
            published_at: '',
            observed_at: this.localDateTime(new Date()),
          });
          this.load(true);
        },
        error: (error: unknown) => {
          this.comparableError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('analysisDetail.comparableSaveError'),
            ),
          );
        },
      });
  }

  protected applyCostResult(analysis: Analysis): void {
    this.analysis.set(analysis);
    this.applyComparableDefaults();
  }

  protected normalizationSaved(): void {
    this.load(true);
  }

  protected date(value: string | null): string {
    if (value === null) {
      return this.i18n.translate('common.notYet');
    }

    return this.i18n.formatDate(value, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }

  protected confidence(value: number | null): string {
    return value === null
      ? this.i18n.translate('common.pending')
      : this.i18n.formatNumber(value / 10_000, {
          style: 'percent',
          minimumFractionDigits: 1,
          maximumFractionDigits: 1,
        });
  }

  protected resultValue(key: string): string {
    const value = this.analysis()?.result_payload?.normalized_listing[key];

    return value === null || value === undefined || value === ''
      ? this.i18n.translate('common.unavailable')
      : String(value);
  }

  protected comparableMoney(evidence: ComparableEvidence): string {
    const minorUnit =
      this.marketCatalog()?.currencies.find(
        (currency) => currency.code === evidence.currency_code,
      )?.minor_unit ?? 2;

    return this.i18n.formatMoney(
      evidence.asking_price_minor,
      evidence.currency_code,
      minorUnit,
    );
  }

  protected factorEntries(
    factors: Readonly<Record<string, number>>,
  ): readonly Readonly<{ key: string; value: number }>[] {
    return Object.entries(factors)
      .map(([key, value]) => ({ key, value }))
      .filter((factor) => factor.value > 0);
  }

  protected stepLabel(step: string): string {
    return this.codeLabels.label(step);
  }

  protected statusCopy(status: AnalysisStatus): string {
    const copy = {
      draft: 'analysisDetail.status.draft',
      queued: 'analysisDetail.status.queued',
      processing: 'analysisDetail.status.processing',
      needs_input: 'analysisDetail.status.needsInput',
      completed: 'analysisDetail.status.completed',
      failed: 'analysisDetail.status.failed',
      archived: 'analysisDetail.status.archived',
    } as const;

    return this.i18n.translate(copy[status]);
  }

  protected countryName(code: string, fallback: string): string {
    return this.i18n.regionName(code, fallback);
  }

  protected currencyName(code: string, fallback: string): string {
    return this.i18n.currencyName(code, fallback);
  }

  protected continentName(code: string, fallback: string): string {
    return this.i18n.continentName(code, fallback);
  }

  private load(preserveMessages = false): void {
    const analysisId = this.routeAnalysisId();
    const listingId = this.routeListingId();
    if (analysisId === null || listingId === null) {
      this.error.set(this.i18n.translate('analysisDetail.routeError'));
      this.loading.set(false);
      return;
    }

    this.clearPoll();
    if (this.analysis() === null) {
      this.loading.set(true);
    } else {
      this.refreshing.set(true);
    }
    if (!preserveMessages) {
      this.error.set(null);
      this.success.set(null);
    }

    this.analyses
      .get(analysisId)
      .pipe(
        finalize(() => {
          this.loading.set(false);
          this.refreshing.set(false);
        }),
      )
      .subscribe({
        next: (analysis) => {
          if (analysis.listing.id !== listingId) {
            this.analysis.set(null);
            this.error.set(this.i18n.translate('analysisDetail.listingMismatch'));
            return;
          }

          this.analysis.set(analysis);
          this.applyComparableDefaults();
          this.schedulePoll(analysis.status);
        },
        error: (error: unknown) => {
          this.analysis.set(null);
          this.error.set(
            apiErrorMessage(error, this.i18n.translate('analysisDetail.loadError')),
          );
        },
      });
  }

  private schedulePoll(status: AnalysisStatus): void {
    if (!['queued', 'processing'].includes(status)) {
      return;
    }

    this.pollTimer = setTimeout(() => this.load(true), 5000);
  }

  private clearPoll(): void {
    if (this.pollTimer !== null) {
      clearTimeout(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private loadMarketCatalog(): void {
    this.markets.catalog().subscribe({
      next: (catalog) => {
        this.marketCatalog.set(catalog);
        this.applyComparableDefaults();
      },
      error: (error: unknown) => {
        this.comparableError.set(
          apiErrorMessage(
            error,
            this.i18n.translate('analysisDetail.marketError'),
          ),
        );
      },
    });
  }

  private applyComparableDefaults(): void {
    const analysis = this.analysis();
    const catalog = this.marketCatalog();

    if (analysis === null || catalog === null || this.comparableForm.dirty) {
      return;
    }

    const currencyCode =
      analysis.request_payload.listing.currency_code ??
      catalog.continents
        .flatMap((continent) => continent.countries)
        .find((country) => country.code === analysis.target_country_code)
        ?.currency_code ??
      '';
    this.comparableForm.patchValue({
      currency_code: currencyCode,
      country_code: analysis.target_country_code,
      observed_at:
        this.comparableForm.controls.observed_at.value || this.localDateTime(new Date()),
    });
  }

  private labels(value: string): readonly string[] {
    return [
      ...new Set(
        value
          .split(',')
          .map((label) => label.trim())
          .filter((label) => label !== ''),
      ),
    ];
  }

  private nullIfBlank(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
  }

  private isoDate(value: string): string | null {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
  }

  private localDateTime(date: Date): string {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
  }
}
