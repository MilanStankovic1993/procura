import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { ApiEnvelope } from './api/api.models';
import { CursorPage } from './listing.models';
import {
  ActualCostSnapshot,
  ActualCostSnapshotInput,
  ActualPurchase,
  ActualPurchaseInput,
  ActualSale,
  ActualSaleInput,
  EstimateAccuracyAttributionInput,
  EstimateAccuracyCandidate,
  EstimateAccuracyReport,
  OutcomeEstimateAttribution,
  OwnedProduct,
  OwnedProductAssessment,
  OwnedProductFilters,
  OwnedProductImage,
  OwnedProductImageKind,
  OwnedProductInput,
  OwnedProductStatus,
  OutcomeTrackingProjection,
  ProductCategoryReference,
  SalePortfolioEntryInput,
  SalePortfolioEvent,
  SalePortfolioEventInput,
  SalePortfolioProjection,
  SellComparableInput,
  SellComparableMarketNormalizationInput,
  SellComparableMarketNormalizationRecord,
  SellComparableMutation,
  SellComparableSelection,
  SellListingDraft,
  SellListingDraftInput,
  SellListingDraftProjection,
  SellPriceIntelligence,
  SellPriceBand,
} from './owned-product.models';

@Injectable({ providedIn: 'root' })
export class OwnedProductService {
  private readonly http = inject(HttpClient);

  categories(): Observable<readonly ProductCategoryReference[]> {
    return this.http
      .get<ApiEnvelope<readonly ProductCategoryReference[]>>(
        '/api/v1/product-categories',
      )
      .pipe(map((response) => response.data));
  }

  list(filters: OwnedProductFilters = {}): Observable<CursorPage<OwnedProduct>> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http.get<CursorPage<OwnedProduct>>('/api/v1/owned-products', {
      params,
    });
  }

  get(ownedProductId: string): Observable<OwnedProduct> {
    return this.http
      .get<ApiEnvelope<OwnedProduct>>(
        `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}`,
      )
      .pipe(map((response) => response.data));
  }

  create(input: OwnedProductInput): Observable<OwnedProduct> {
    return this.http
      .post<ApiEnvelope<OwnedProduct>>('/api/v1/owned-products', input)
      .pipe(map((response) => response.data));
  }

  updateLifecycle(
    ownedProductId: string,
    status: OwnedProductStatus,
    notes: string | null,
  ): Observable<OwnedProduct> {
    return this.http
      .patch<ApiEnvelope<OwnedProduct>>(
        `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}`,
        { status, notes },
      )
      .pipe(map((response) => response.data));
  }

  assess(
    ownedProductId: string,
    ownedProductSnapshotId: string,
  ): Observable<OwnedProductAssessment> {
    return this.http
      .post<ApiEnvelope<OwnedProductAssessment>>(
        `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/assessments`,
        { owned_product_snapshot_id: ownedProductSnapshotId },
      )
      .pipe(map((response) => response.data));
  }

  sellPriceIntelligence(
    ownedProductId: string,
  ): Observable<SellPriceIntelligence> {
    return this.http
      .get<ApiEnvelope<SellPriceIntelligence>>(
        `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/sell-intelligence`,
      )
      .pipe(map((response) => response.data));
  }

  createSellComparable(
    ownedProductId: string,
    input: SellComparableInput,
  ): Observable<{
    readonly data: SellComparableMutation;
    readonly meta: { readonly created: boolean };
  }> {
    return this.http.post<{
      readonly data: SellComparableMutation;
      readonly meta: { readonly created: boolean };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/comparables`,
      input,
    );
  }

  createSellComparableMarketNormalization(
    ownedProductId: string,
    comparableRecordId: string,
    input: SellComparableMarketNormalizationInput,
  ): Observable<{
    readonly data: SellComparableMarketNormalizationRecord;
    readonly meta: {
      readonly created: boolean;
      readonly selection: SellComparableSelection;
      readonly price_band: SellPriceBand;
    };
  }> {
    return this.http.post<{
      readonly data: SellComparableMarketNormalizationRecord;
      readonly meta: {
        readonly created: boolean;
        readonly selection: SellComparableSelection;
        readonly price_band: SellPriceBand;
      };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/comparables/${encodeURIComponent(comparableRecordId)}/market-normalizations`,
      input,
    );
  }

  sellListingDrafts(
    ownedProductId: string,
  ): Observable<SellListingDraftProjection> {
    return this.http
      .get<ApiEnvelope<SellListingDraftProjection>>(
        `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/listing-drafts`,
      )
      .pipe(map((response) => response.data));
  }

  createSellListingDraft(
    ownedProductId: string,
    input: SellListingDraftInput,
  ): Observable<{
    readonly data: SellListingDraft;
    readonly meta: { readonly created: boolean };
  }> {
    return this.http.post<{
      readonly data: SellListingDraft;
      readonly meta: { readonly created: boolean };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/listing-drafts`,
      input,
    );
  }

  salePortfolio(ownedProductId: string): Observable<SalePortfolioProjection> {
    return this.http
      .get<ApiEnvelope<SalePortfolioProjection>>(
        `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/sale-portfolio`,
      )
      .pipe(map((response) => response.data));
  }

  createSalePortfolioEntry(
    ownedProductId: string,
    input: SalePortfolioEntryInput,
  ): Observable<{
    readonly data: SalePortfolioProjection;
    readonly meta: {
      readonly created: boolean;
      readonly entry_id: string;
    };
  }> {
    return this.http.post<{
      readonly data: SalePortfolioProjection;
      readonly meta: {
        readonly created: boolean;
        readonly entry_id: string;
      };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/sale-portfolio`,
      input,
    );
  }

  recordSalePortfolioEvent(
    ownedProductId: string,
    entryId: string,
    input: SalePortfolioEventInput,
  ): Observable<{
    readonly data: SalePortfolioProjection;
    readonly meta: {
      readonly created: boolean;
      readonly event: SalePortfolioEvent;
    };
  }> {
    return this.http.post<{
      readonly data: SalePortfolioProjection;
      readonly meta: {
        readonly created: boolean;
        readonly event: SalePortfolioEvent;
      };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/sale-portfolio/${encodeURIComponent(entryId)}/events`,
      input,
    );
  }

  outcomes(ownedProductId: string): Observable<OutcomeTrackingProjection> {
    return this.http
      .get<ApiEnvelope<OutcomeTrackingProjection>>(
        `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/outcomes`,
      )
      .pipe(map((response) => response.data));
  }

  recordActualPurchase(
    ownedProductId: string,
    input: ActualPurchaseInput,
  ): Observable<{
    readonly data: OutcomeTrackingProjection;
    readonly meta: {
      readonly created: boolean;
      readonly purchase: ActualPurchase;
    };
  }> {
    return this.http.post<{
      readonly data: OutcomeTrackingProjection;
      readonly meta: {
        readonly created: boolean;
        readonly purchase: ActualPurchase;
      };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/outcomes/purchases`,
      input,
    );
  }

  recordActualCostSnapshot(
    ownedProductId: string,
    input: ActualCostSnapshotInput,
  ): Observable<{
    readonly data: OutcomeTrackingProjection;
    readonly meta: {
      readonly created: boolean;
      readonly cost_snapshot: ActualCostSnapshot;
    };
  }> {
    return this.http.post<{
      readonly data: OutcomeTrackingProjection;
      readonly meta: {
        readonly created: boolean;
        readonly cost_snapshot: ActualCostSnapshot;
      };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/outcomes/cost-snapshots`,
      input,
    );
  }

  recordActualSale(
    ownedProductId: string,
    entryId: string,
    input: ActualSaleInput,
  ): Observable<{
    readonly data: OutcomeTrackingProjection;
    readonly meta: {
      readonly created: boolean;
      readonly sale: ActualSale;
    };
  }> {
    return this.http.post<{
      readonly data: OutcomeTrackingProjection;
      readonly meta: {
        readonly created: boolean;
        readonly sale: ActualSale;
      };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/sale-portfolio/${encodeURIComponent(entryId)}/outcomes`,
      input,
    );
  }

  estimateAccuracyCandidates(
    ownedProductId: string,
    search: string | null = null,
  ): Observable<{
    readonly data: readonly EstimateAccuracyCandidate[];
    readonly meta: { readonly count: number; readonly limit: number };
  }> {
    const params =
      search === null || search.trim() === ''
        ? undefined
        : new HttpParams().set('search', search.trim());

    return this.http.get<{
      readonly data: readonly EstimateAccuracyCandidate[];
      readonly meta: { readonly count: number; readonly limit: number };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/outcomes/estimate-candidates`,
      { params },
    );
  }

  recordEstimateAccuracyAttribution(
    ownedProductId: string,
    input: EstimateAccuracyAttributionInput,
  ): Observable<{
    readonly data: OutcomeTrackingProjection;
    readonly meta: {
      readonly created: boolean;
      readonly attribution: OutcomeEstimateAttribution;
      readonly report: EstimateAccuracyReport;
    };
  }> {
    return this.http.post<{
      readonly data: OutcomeTrackingProjection;
      readonly meta: {
        readonly created: boolean;
        readonly attribution: OutcomeEstimateAttribution;
        readonly report: EstimateAccuracyReport;
      };
    }>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/outcomes/estimate-attributions`,
      input,
    );
  }

  uploadImages(
    ownedProductId: string,
    kind: OwnedProductImageKind,
    files: readonly File[],
  ): Observable<readonly OwnedProductImage[]> {
    const data = new FormData();
    data.append('kind', kind);
    files.forEach((file) => data.append('images[]', file, file.name));

    return this.http
      .post<ApiEnvelope<readonly OwnedProductImage[]>>(
        `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/images`,
        data,
      )
      .pipe(map((response) => response.data));
  }

  deleteImage(ownedProductId: string, imageId: string): Observable<void> {
    return this.http.delete<void>(
      `/api/v1/owned-products/${encodeURIComponent(ownedProductId)}/images/${encodeURIComponent(imageId)}`,
    );
  }
}
