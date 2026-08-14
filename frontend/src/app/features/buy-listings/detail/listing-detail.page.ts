import { Component, computed, effect, inject, Injector, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { finalize, map } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { AnalysisStatus, AnalysisSummary } from '../../../core/analysis.models';
import { AnalysisService } from '../../../core/analysis.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  Listing,
  ListingImage,
  ListingImageKind,
  ListingStatus,
} from '../../../core/listing.models';
import { ListingService } from '../../../core/listing.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

type BuyJourneyState =
  | 'loading'
  | 'unavailable'
  | 'ready'
  | 'draft'
  | 'running'
  | 'needs_input'
  | 'completed'
  | 'failed'
  | 'read_only';

@Component({
  selector: 'app-listing-detail-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './listing-detail.page.html',
  styleUrl: './listing-detail.page.scss',
})
export class ListingDetailPage {
  private readonly listings = inject(ListingService);
  private readonly analyses = inject(AnalysisService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly formBuilder = inject(FormBuilder);
  private readonly injector = inject(Injector);
  private readonly i18n = inject(I18nService);
  private readonly routeListingId = toSignal(
    this.route.paramMap.pipe(map((parameters) => parameters.get('id'))),
    { initialValue: null, injector: this.injector },
  );
  private loadedContextKey: string | null = null;

  protected readonly listing = signal<Listing | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly uploading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly selectedFiles = signal<readonly File[]>([]);
  protected readonly removingImageIds = signal<ReadonlySet<string>>(new Set());
  protected readonly analysisRecords = signal<readonly AnalysisSummary[]>([]);
  protected readonly analysesLoading = signal(false);
  protected readonly analysesError = signal<string | null>(null);
  protected readonly creatingDraft = signal(false);
  protected readonly openDraft = computed(
    () => this.analysisRecords().find((analysis) => analysis.status === 'draft') ?? null,
  );
  protected readonly primaryAnalysis = computed(
    () =>
      this.analysisRecords().find((analysis) =>
        ['needs_input', 'processing', 'queued', 'draft'].includes(analysis.status),
      ) ??
      this.analysisRecords().find((analysis) =>
        ['completed', 'failed'].includes(analysis.status),
      ) ??
      null,
  );
  protected readonly canManage = computed(
    () =>
      this.organizations.activeOrganization()?.capabilities.includes('listings.manage') ===
      true,
  );
  protected readonly canManageAnalyses = computed(
    () =>
      this.organizations.activeOrganization()?.capabilities.includes('analyses.manage') ===
      true,
  );
  protected readonly journeyState = computed<BuyJourneyState>(() => {
    if (this.analysesLoading() && this.analysisRecords().length === 0) {
      return 'loading';
    }

    if (this.analysesError() !== null && this.analysisRecords().length === 0) {
      return 'unavailable';
    }

    const analysis = this.primaryAnalysis();

    if (analysis === null) {
      return this.canManageAnalyses() ? 'ready' : 'read_only';
    }

    const states: Readonly<Record<AnalysisStatus, BuyJourneyState>> = {
      draft: 'draft',
      queued: 'running',
      processing: 'running',
      needs_input: 'needs_input',
      completed: 'completed',
      failed: 'failed',
      archived: this.canManageAnalyses() ? 'ready' : 'read_only',
    };

    return states[analysis.status];
  });
  protected readonly lifecycleForm = this.formBuilder.nonNullable.group({
    status: ['unknown' as ListingStatus],
    notes: [''],
  });
  protected readonly uploadForm = this.formBuilder.nonNullable.group({
    kind: ['product' as ListingImageKind],
  });

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;
      const listingId = this.routeListingId();
      const contextKey =
        organizationId !== null && listingId !== null ? `${organizationId}:${listingId}` : null;

      if (contextKey !== null && contextKey !== this.loadedContextKey) {
        this.loadedContextKey = contextKey;
        this.listing.set(null);
        this.analysisRecords.set([]);
        this.load();
      }
    });
  }

  protected retry(): void {
    this.load();
  }

  protected saveLifecycle(): void {
    const listing = this.listing();
    if (listing === null || !this.canManage()) {
      return;
    }

    const value = this.lifecycleForm.getRawValue();
    const notes = value.notes.trim() === '' ? null : value.notes.trim();

    if (value.status === listing.status && notes === listing.notes) {
      this.success.set(this.i18n.translate('listingDetail.noChanges'));
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);
    this.listings
      .updateLifecycle(listing.id, value.status, notes)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (updated) => {
          this.setListing(updated);
          this.success.set(this.i18n.translate('listingDetail.lifecycleSaved'));
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('listingDetail.lifecycleError'),
            ),
          );
        },
      });
  }

  protected createAnalysisDraft(): void {
    const listing = this.listing();
    if (listing === null || !this.canManageAnalyses() || this.creatingDraft()) {
      return;
    }

    const existingDraft = this.openDraft();
    if (existingDraft !== null) {
      void this.router.navigate([
        '/app/buy',
        listing.id,
        'analysis',
        existingDraft.id,
      ]);
      return;
    }

    this.creatingDraft.set(true);
    this.analysesError.set(null);
    this.analyses
      .createDraft(listing.id, listing.target_country_code)
      .pipe(finalize(() => this.creatingDraft.set(false)))
      .subscribe({
        next: (analysis) => {
          void this.router.navigate([
            '/app/buy',
            listing.id,
            'analysis',
            analysis.id,
          ]);
        },
        error: (error: unknown) => {
          this.analysesError.set(
            apiErrorMessage(error, this.i18n.translate('listingDetail.draftError')),
          );
        },
      });
  }

  protected retryAnalyses(): void {
    const listing = this.listing();
    if (listing !== null) {
      this.loadAnalyses(listing.id);
    }
  }

  protected selectFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const invalid = files.find(
      (file) => !allowedTypes.has(file.type) || file.size > 10 * 1024 * 1024,
    );

    if (invalid !== undefined || files.length > 10) {
      this.error.set(
        this.i18n.translate('listingDetail.fileSelectionError'),
      );
      input.value = '';
      this.selectedFiles.set([]);
      return;
    }

    this.error.set(null);
    this.selectedFiles.set(files);
  }

  protected upload(): void {
    const listing = this.listing();
    const files = this.selectedFiles();
    if (listing === null || files.length === 0 || !this.canManage()) {
      return;
    }

    this.uploading.set(true);
    this.error.set(null);
    this.success.set(null);
    this.listings
      .uploadImages(listing.id, this.uploadForm.controls.kind.value, files)
      .pipe(finalize(() => this.uploading.set(false)))
      .subscribe({
        next: () => {
          this.selectedFiles.set([]);
          this.success.set(this.i18n.translate('listingDetail.uploaded'));
          this.load(true);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(error, this.i18n.translate('listingDetail.uploadError')),
          );
        },
      });
  }

  protected removeImage(image: ListingImage): void {
    const listing = this.listing();
    if (listing === null || !this.canManage() || this.removingImageIds().has(image.id)) {
      return;
    }

    const removing = new Set(this.removingImageIds());
    removing.add(image.id);
    this.removingImageIds.set(removing);
    this.error.set(null);

    this.listings
      .deleteImage(listing.id, image.id)
      .pipe(
        finalize(() => {
          const next = new Set(this.removingImageIds());
          next.delete(image.id);
          this.removingImageIds.set(next);
        }),
      )
      .subscribe({
        next: () => {
          this.listing.update((current) =>
            current === null
              ? null
              : {
                  ...current,
                  images: current.images?.filter((item) => item.id !== image.id),
                  image_count: Math.max(0, current.image_count - 1),
                },
          );
          this.success.set(this.i18n.translate('listingDetail.removed'));
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(error, this.i18n.translate('listingDetail.removeError')),
          );
        },
      });
  }

  protected price(listing: Listing): string {
    return this.i18n.formatMoney(
      listing.asking_price_minor,
      listing.currency_code,
      listing.currency_minor_unit,
    );
  }

  protected date(value: string | null): string {
    if (value === null) {
      return this.i18n.translate('common.unknownDate');
    }

    return this.i18n.formatDate(value, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }

  protected fileSize(size: number): string {
    if (size < 1024 * 1024) {
      return `${this.i18n.formatNumber(Math.max(1, Math.round(size / 1024)))} KB`;
    }

    return `${this.i18n.formatNumber(size / (1024 * 1024), {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    })} MB`;
  }

  protected shortHash(hash: string): string {
    return `${hash.slice(0, 10)}…${hash.slice(-6)}`;
  }

  protected listingStatusLabel(status: ListingStatus): string {
    const keys: Readonly<Record<ListingStatus, TranslationKey>> = {
      active: 'listing.status.active',
      reserved: 'listing.status.reserved',
      sold: 'listing.status.sold',
      removed: 'listing.status.removed',
      expired: 'listing.status.expired',
      unknown: 'listing.status.unknown',
    };

    return this.i18n.translate(keys[status]);
  }

  protected analysisStatusLabel(status: AnalysisStatus): string {
    const keys: Readonly<Record<AnalysisStatus, TranslationKey>> = {
      draft: 'analysis.status.draft',
      queued: 'analysis.status.queued',
      processing: 'analysis.status.processing',
      needs_input: 'analysis.status.needsInput',
      completed: 'analysis.status.completed',
      failed: 'analysis.status.failed',
      archived: 'analysis.status.archived',
    };

    return this.i18n.translate(keys[status]);
  }

  protected journeyTitleKey(): TranslationKey {
    return `listingDetail.journey.${this.journeyState()}.title` as TranslationKey;
  }

  protected journeyDescriptionKey(): TranslationKey {
    return `listingDetail.journey.${this.journeyState()}.description` as TranslationKey;
  }

  protected journeyActionKey(): TranslationKey {
    return `listingDetail.journey.${this.journeyState()}.action` as TranslationKey;
  }

  protected journeyStepState(step: 1 | 2 | 3): 'complete' | 'current' | 'upcoming' {
    if (step === 1) {
      return 'complete';
    }

    if (this.journeyState() === 'completed') {
      return step === 2 ? 'complete' : 'current';
    }

    return step === 2 ? 'current' : 'upcoming';
  }

  protected imageKindLabel(kind: ListingImageKind): string {
    return this.i18n.translate(
      kind === 'product' ? 'listing.image.product' : 'listing.image.screenshot',
    );
  }

  private load(preserveMessages = false): void {
    const listingId = this.routeListingId();
    if (listingId === null) {
      this.error.set(this.i18n.translate('listingDetail.idMissing'));
      this.loading.set(false);
      return;
    }

    this.loading.set(true);
    if (!preserveMessages) {
      this.error.set(null);
      this.success.set(null);
    }

    this.listings
      .get(listingId)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (listing) => {
          this.setListing(listing);
          this.loadAnalyses(listing.id);
        },
        error: (error: unknown) => {
          this.listing.set(null);
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('listingDetail.loadError'),
            ),
          );
        },
      });
  }

  private loadAnalyses(listingId: string): void {
    this.analysesLoading.set(true);
    this.analysesError.set(null);
    this.analyses
      .list({ listing_id: listingId, per_page: 10 })
      .pipe(finalize(() => this.analysesLoading.set(false)))
      .subscribe({
        next: (page) => this.analysisRecords.set(page.data),
        error: (error: unknown) => {
          this.analysisRecords.set([]);
          this.analysesError.set(
            apiErrorMessage(
              error,
              this.i18n.translate('listingDetail.analysisLoadError'),
            ),
          );
        },
      });
  }

  private setListing(listing: Listing): void {
    this.listing.set(listing);
    this.lifecycleForm.setValue({
      status: listing.status,
      notes: listing.notes ?? '',
    });
  }
}
