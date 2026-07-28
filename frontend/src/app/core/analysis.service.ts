import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { ApiEnvelope } from './api/api.models';
import {
  Analysis,
  AnalysisFilters,
  AnalysisPage,
  BuyerDecisionResponse,
  BuyerDecisionSubmission,
  ComparableCreateResponse,
  ComparableInput,
  ComparableMarketNormalizationCreateResponse,
  ComparableMarketNormalizationInput,
  ComparableMarketNormalizationRecord,
  ComparableRecord,
  ComparableSet,
  CostConfirmationResponse,
  CostInputSubmission,
  OpportunityConfirmationResponse,
  OpportunityEvidenceSubmission,
} from './analysis.models';

@Injectable({ providedIn: 'root' })
export class AnalysisService {
  private readonly http = inject(HttpClient);

  list(filters: AnalysisFilters = {}): Observable<AnalysisPage> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http.get<AnalysisPage>('/api/v1/analyses', { params });
  }

  get(analysisId: string): Observable<Analysis> {
    return this.http
      .get<ApiEnvelope<Analysis>>(`/api/v1/analyses/${encodeURIComponent(analysisId)}`)
      .pipe(map((response) => response.data));
  }

  createDraft(listingId: string, targetCountryCode: string): Observable<Analysis> {
    return this.http
      .post<ApiEnvelope<Analysis>>('/api/v1/buy-analyses', {
        listing_id: listingId,
        target_country_code: targetCountryCode,
      })
      .pipe(map((response) => response.data));
  }

  submit(analysisId: string): Observable<Analysis> {
    return this.http
      .post<ApiEnvelope<Analysis>>(
        `/api/v1/analyses/${encodeURIComponent(analysisId)}/submit`,
        {},
      )
      .pipe(map((response) => response.data));
  }

  createComparable(
    analysisId: string,
    input: ComparableInput,
  ): Observable<ComparableCreateResponse> {
    return this.http
      .post<
        ApiEnvelope<ComparableRecord> & {
          readonly meta: {
            readonly created: boolean;
            readonly comparable_set: ComparableSet;
          };
        }
      >(`/api/v1/analyses/${encodeURIComponent(analysisId)}/comparables`, input)
      .pipe(
        map((response) => ({
          record: response.data,
          created: response.meta.created,
          comparable_set: response.meta.comparable_set,
        })),
      );
  }

  createMarketNormalization(
    analysisId: string,
    comparableRecordId: string,
    input: ComparableMarketNormalizationInput,
  ): Observable<ComparableMarketNormalizationCreateResponse> {
    return this.http
      .post<
        ApiEnvelope<ComparableMarketNormalizationRecord> & {
          readonly meta: {
            readonly created: boolean;
            readonly comparable_set: ComparableSet;
          };
        }
      >(
        `/api/v1/analyses/${encodeURIComponent(analysisId)}/comparables/${encodeURIComponent(comparableRecordId)}/market-normalizations`,
        input,
      )
      .pipe(
        map((response) => ({
          normalization: response.data,
          created: response.meta.created,
          comparable_set: response.meta.comparable_set,
        })),
      );
  }

  confirmCosts(
    analysisId: string,
    input: CostInputSubmission,
  ): Observable<CostConfirmationResponse> {
    return this.http
      .post<
        ApiEnvelope<Analysis> & {
          readonly meta: {
            readonly created: boolean;
          };
        }
      >(`/api/v1/analyses/${encodeURIComponent(analysisId)}/costs`, input)
      .pipe(
        map((response) => ({
          analysis: response.data,
          created: response.meta.created,
        })),
      );
  }

  confirmOpportunityEvidence(
    analysisId: string,
    input: OpportunityEvidenceSubmission,
  ): Observable<OpportunityConfirmationResponse> {
    return this.http
      .post<
        ApiEnvelope<Analysis> & {
          readonly meta: {
            readonly created: boolean;
          };
        }
      >(
        `/api/v1/analyses/${encodeURIComponent(analysisId)}/opportunity-evidence`,
        input,
      )
      .pipe(
        map((response) => ({
          analysis: response.data,
          created: response.meta.created,
        })),
      );
  }

  recordBuyerDecision(
    analysisId: string,
    input: BuyerDecisionSubmission,
  ): Observable<BuyerDecisionResponse> {
    return this.http
      .post<
        ApiEnvelope<Analysis> & {
          readonly meta: {
            readonly created: boolean;
            readonly event: BuyerDecisionResponse['event'];
          };
        }
      >(`/api/v1/analyses/${encodeURIComponent(analysisId)}/buyer-decisions`, input)
      .pipe(
        map((response) => ({
          analysis: response.data,
          event: response.meta.event,
          created: response.meta.created,
        })),
      );
  }
}
