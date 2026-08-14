import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { AnalysisStatus, AnalysisSummary } from '../../../core/analysis.models';
import { AnalysisService } from '../../../core/analysis.service';
import { Listing } from '../../../core/listing.models';
import { ListingService } from '../../../core/listing.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { OrganizationSummary } from '../../../core/organizations/organization.models';
import { ListingDetailPage } from './listing-detail.page';

const listing = {
  id: '01JLISTING',
  marketplace_source: {
    key: 'manual',
    name: 'Approved Market',
    connector_type: 'manual',
    capabilities: ['manual_intake'],
    supported_country_codes: null,
    supported_currency_codes: null,
    supported_language_tags: null,
    geographic_coverage: 'global',
    cross_border_supported: true,
    compliance_status: 'approved',
    available: true,
    asking_price_only: true,
    transaction_price_supported: false,
  },
  source_url: 'https://example.com/listing',
  external_id: null,
  marketplace_name: 'Approved Market',
  title: 'Bosch GSR 18V-55 kit',
  description: 'Professional drill kit',
  asking_price_minor: 18900,
  currency_code: 'EUR',
  currency_minor_unit: 2,
  seller_information: null,
  location: 'Berlin',
  source_country_code: 'DE',
  target_country_code: 'AT',
  status: 'active',
  notes: null,
  image_count: 0,
  snapshot_count: 0,
  images: [],
  snapshots: [],
  created_at: '2026-08-14T10:00:00Z',
  updated_at: '2026-08-14T10:00:00Z',
} as Listing;

describe('ListingDetailPage', () => {
  it('guides a manager to start the first analysis', () => {
    const fixture = createPage();
    const content = fixture.nativeElement.textContent as string;
    const action = fixture.nativeElement.querySelector(
      '.journey-action button',
    ) as HTMLButtonElement;

    expect(content).toContain('Ready to check this deal?');
    expect(action.textContent).toContain('Start guided analysis');
    expect(stepStates(fixture)).toEqual(['complete', 'current', 'upcoming']);
  });

  it('continues the current draft instead of offering duplicate work', () => {
    const fixture = createPage('draft');
    const content = fixture.nativeElement.textContent as string;
    const action = fixture.nativeElement.querySelector(
      '.journey-action a',
    ) as HTMLAnchorElement;

    expect(content).toContain('Your analysis is ready to submit');
    expect(action.textContent).toContain('Continue analysis');
    expect(action.getAttribute('href')).toBe(
      '/app/buy/01JLISTING/analysis/01JANALYSIS',
    );
    expect(content).not.toContain('Create another draft');
  });

  it('moves a completed analysis to the decision step', () => {
    const fixture = createPage('completed');
    const content = fixture.nativeElement.textContent as string;

    expect(content).toContain('Your decision report is ready');
    expect(content).toContain('Review results');
    expect(content).toContain('Create another draft');
    expect(stepStates(fixture)).toEqual(['complete', 'complete', 'current']);
  });
});

function createPage(status?: AnalysisStatus): ComponentFixture<ListingDetailPage> {
  const analyses = status === undefined ? [] : [analysis(status)];
  const organization = {
    id: '01JORG',
    name: 'Pilot Workspace',
    type: 'business',
    role: 'owner',
    capabilities: ['listings.manage', 'analyses.manage'],
    joined_at: null,
    is_active: true,
    created_at: null,
    updated_at: null,
  } as OrganizationSummary;

  TestBed.configureTestingModule({
    imports: [ListingDetailPage],
    providers: [
      provideRouter([]),
      {
        provide: ActivatedRoute,
        useValue: {
          paramMap: of(convertToParamMap({ id: listing.id })),
        },
      },
      {
        provide: ListingService,
        useValue: {
          get: vi.fn(() => of(listing)),
        },
      },
      {
        provide: AnalysisService,
        useValue: {
          list: vi.fn(() => of(cursorPage(analyses))),
        },
      },
      {
        provide: OrganizationContextService,
        useValue: { activeOrganization: signal(organization) },
      },
    ],
  });

  const fixture = TestBed.createComponent(ListingDetailPage);
  fixture.detectChanges();

  return fixture;
}

function analysis(status: AnalysisStatus): AnalysisSummary {
  return {
    id: '01JANALYSIS',
    listing: {
      id: listing.id,
      title: listing.title,
      marketplace_name: listing.marketplace_name,
      asking_price_minor: listing.asking_price_minor,
      currency_code: listing.currency_code,
    },
    analysis_type: 'buy',
    status,
    source_country_code: listing.source_country_code,
    target_country_code: listing.target_country_code,
    pipeline_version: 'buy-v1',
    processing_attempts: 0,
    submitted_at: status === 'draft' ? null : '2026-08-14T10:05:00Z',
    finished_at: status === 'completed' ? '2026-08-14T10:06:00Z' : null,
    failed_at: status === 'failed' ? '2026-08-14T10:06:00Z' : null,
    next_retry_at: null,
    last_error_code: null,
    created_at: '2026-08-14T10:04:00Z',
    updated_at: '2026-08-14T10:06:00Z',
  };
}

function cursorPage(data: readonly AnalysisSummary[]) {
  return {
    data,
    links: { prev: null, next: null },
    meta: {
      path: '/api/v1/analyses',
      per_page: 10,
      next_cursor: null,
      prev_cursor: null,
    },
  };
}

function stepStates(fixture: ComponentFixture<ListingDetailPage>): string[] {
  return [...fixture.nativeElement.querySelectorAll('.journey-steps li')].map(
    (element: Element) => element.getAttribute('data-state') ?? '',
  );
}
