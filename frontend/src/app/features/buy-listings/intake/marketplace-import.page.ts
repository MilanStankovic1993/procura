import { Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import { apiErrorMessage } from '../../../core/api/api-error';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/locales/en';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  MarketplaceImport,
  MarketplaceImportRowStatus,
  MarketplaceImportStatus,
} from '../../../core/listing.models';
import { ListingService } from '../../../core/listing.service';
import { MarketReferenceCatalog } from '../../../core/market.models';
import { MarketReferenceService } from '../../../core/market-reference.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';

type ImportDelimiter = 'comma' | 'semicolon' | 'tab';

@Component({
  selector: 'app-marketplace-import-page',
  imports: [ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './marketplace-import.page.html',
  styleUrl: './marketplace-import.page.scss',
})
export class MarketplaceImportPage {
  private readonly listings = inject(ListingService);
  private readonly markets = inject(MarketReferenceService);
  private readonly organizations = inject(OrganizationContextService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private loadedOrganizationId: string | null = null;
  private idempotencyKey: string | null = null;

  protected readonly records = signal<readonly MarketplaceImport[]>([]);
  protected readonly catalog = signal<MarketReferenceCatalog | null>(null);
  protected readonly connectorAvailable = signal(false);
  protected readonly selectedFile = signal<File | null>(null);
  protected readonly selectedImport = signal<MarketplaceImport | null>(null);
  protected readonly loading = signal(true);
  protected readonly refreshing = signal(false);
  protected readonly saving = signal(false);
  protected readonly detailsLoading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly countries = computed(
    () => this.catalog()?.continents.flatMap((continent) => continent.countries) ?? [],
  );
  protected readonly canManage = computed(
    () =>
      this.organizations.activeOrganization()?.capabilities.includes('listings.manage') ===
      true,
  );
  protected readonly form = this.formBuilder.nonNullable.group({
    delimiter: ['comma' as ImportDelimiter, [Validators.required]],
    default_target_country_code: [''],
    authorization_confirmed: [false, [Validators.requiredTrue]],
  });

  constructor() {
    effect(() => {
      const organizationId = this.organizations.activeOrganization()?.id ?? null;

      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.reset();
        this.load(false);
      }
    });
  }

  protected selectFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    if (
      file !== null &&
      (!file.name.toLocaleLowerCase().endsWith('.csv') || file.size > 5 * 1024 * 1024)
    ) {
      this.selectedFile.set(null);
      this.error.set(this.i18n.translate('marketplaceImport.filePolicyError'));
      input.value = '';
      return;
    }

    this.selectedFile.set(file);
    this.idempotencyKey = file === null ? null : globalThis.crypto.randomUUID();
    this.error.set(null);
    this.success.set(null);
  }

  protected submit(): void {
    const file = this.selectedFile();

    if (!this.canManage()) {
      this.error.set(this.i18n.translate('marketplaceImport.roleError'));
      return;
    }

    if (!this.connectorAvailable()) {
      this.error.set(this.i18n.translate('marketplaceImport.connectorUnavailable'));
      return;
    }

    if (file === null || this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('marketplaceImport.requiredError'));
      return;
    }

    const value = this.form.getRawValue();
    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);

    this.listings
      .createImport(
        file,
        value.delimiter,
        value.default_target_country_code || null,
        this.idempotencyKey ?? globalThis.crypto.randomUUID(),
      )
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (record) => {
          this.records.update((records) => [
            record,
            ...records.filter((candidate) => candidate.id !== record.id),
          ]);
          this.selectedImport.set(record);
          this.selectedFile.set(null);
          this.idempotencyKey = null;
          this.form.controls.authorization_confirmed.setValue(false);
          this.success.set(this.i18n.translate('marketplaceImport.queued'));
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('marketplaceImport.submitError'),
            ),
          );
        },
      });
  }

  protected refresh(): void {
    this.load(true);
  }

  protected showDetails(importId: string): void {
    this.detailsLoading.set(true);
    this.error.set(null);

    this.listings
      .importDetails(importId)
      .pipe(finalize(() => this.detailsLoading.set(false)))
      .subscribe({
        next: (record) => {
          this.selectedImport.set(record);
          this.records.update((records) =>
            records.map((candidate) => (candidate.id === record.id ? record : candidate)),
          );
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('marketplaceImport.detailsError'),
            ),
          );
        },
      });
  }

  protected downloadTemplate(): void {
    const headers = [
      'external_id',
      'marketplace_name',
      'title',
      'description',
      'asking_price_minor',
      'currency_code',
      'source_country_code',
      'target_country_code',
      'status',
      'source_url',
      'seller_information',
      'location',
      'notes',
    ];
    const blob = new Blob([`${headers.join(',')}\n`], {
      type: 'text/csv;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'procura-marketplace-import-v1.csv';
    anchor.click();
    URL.revokeObjectURL(url);
  }

  protected statusLabel(status: MarketplaceImportStatus): string {
    const keys: Readonly<Record<MarketplaceImportStatus, TranslationKey>> = {
      pending: 'marketplaceImport.status.pending',
      processing: 'marketplaceImport.status.processing',
      completed: 'marketplaceImport.status.completed',
      completed_with_errors: 'marketplaceImport.status.completedWithErrors',
      failed: 'marketplaceImport.status.failed',
    };

    return this.i18n.translate(keys[status]);
  }

  protected rowStatusLabel(status: MarketplaceImportRowStatus): string {
    const keys: Readonly<Record<MarketplaceImportRowStatus, TranslationKey>> = {
      imported: 'marketplaceImport.row.imported',
      rejected: 'marketplaceImport.row.rejected',
      duplicate: 'marketplaceImport.row.duplicate',
    };

    return this.i18n.translate(keys[status]);
  }

  protected errorFields(errors: Readonly<Record<string, readonly string[]>> | null): string {
    return errors === null ? '—' : Object.keys(errors).join(', ');
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknownDate')
      : this.i18n.formatDate(value, { dateStyle: 'medium', timeStyle: 'short' });
  }

  protected bytes(value: number): string {
    return new Intl.NumberFormat(this.i18n.locale(), {
      style: 'unit',
      unit: value >= 1024 * 1024 ? 'megabyte' : 'kilobyte',
      maximumFractionDigits: 1,
    }).format(value >= 1024 * 1024 ? value / (1024 * 1024) : value / 1024);
  }

  private load(refreshing: boolean): void {
    if (refreshing) {
      this.refreshing.set(true);
    } else {
      this.loading.set(true);
    }
    this.error.set(null);

    forkJoin({
      catalog: this.markets.catalog(),
      sources: this.listings.sources(),
      imports: this.listings.imports(),
    })
      .pipe(
        finalize(() => {
          this.loading.set(false);
          this.refreshing.set(false);
        }),
      )
      .subscribe({
        next: ({ catalog, sources, imports }) => {
          this.catalog.set(catalog);
          this.connectorAvailable.set(
            sources.some(
              (source) =>
                source.key === 'authorized_csv' &&
                source.connector_type === 'csv' &&
                source.compliance_status === 'approved' &&
                source.available,
            ),
          );
          this.records.set(imports.data);

          const selectedId = this.selectedImport()?.id;
          if (selectedId !== undefined) {
            this.selectedImport.set(
              imports.data.find((record) => record.id === selectedId) ??
                this.selectedImport(),
            );
          }
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(error, this.i18n.translate('marketplaceImport.loadError')),
          );
        },
      });
  }

  private reset(): void {
    this.records.set([]);
    this.selectedFile.set(null);
    this.selectedImport.set(null);
    this.connectorAvailable.set(false);
    this.idempotencyKey = null;
    this.form.reset({
      delimiter: 'comma',
      default_target_country_code: '',
      authorization_confirmed: false,
    });
  }
}
