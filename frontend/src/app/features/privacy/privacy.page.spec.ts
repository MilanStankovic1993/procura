import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { I18nService } from '../../core/i18n/i18n.service';
import { PrivacyRequest } from '../../core/privacy.models';
import { PrivacyPage } from './privacy.page';

function privacyRequestRecord(
  overrides: Partial<PrivacyRequest> = {},
): PrivacyRequest {
  const event = {
    id: '01KPRIVACYEVENT00000000001',
    sequence: 1,
    prior_status: null,
    next_status: 'requested' as const,
    actor_type: 'subject' as const,
    actor: { id: 1, name: 'Owner' },
    reason_code: 'privacy_request_submitted',
    note: null,
    evidence_reference: null,
    occurred_at: '2026-07-28T12:00:00Z',
  };

  return {
    id: '01KPRIVACYREQUEST000000001',
    type: 'data_export',
    status: 'requested',
    current_event_id: event.id,
    event_sequence: 1,
    residence_country_code: null,
    reason: 'Please provide a portable copy of my data.',
    blocking_reason_codes: [],
    workflow_version: 'privacy-request-workflow:v1',
    privacy_notice_version: 'privacy-notice:v1',
    can_cancel: true,
    requested_at: '2026-07-28T12:00:00Z',
    response_target_at: '2026-08-27T12:00:00Z',
    resolved_at: null,
    current_event: event,
    events: [event],
    fulfillment: null,
    ...overrides,
  };
}

describe('PrivacyPage', () => {
  let http: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [PrivacyPage],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the bounded request ledger and market catalog', () => {
    const fixture = TestBed.createComponent(PrivacyPage);
    fixture.detectChanges();

    http.expectOne('/api/v1/me/privacy-requests').flush({ data: [] });
    http.expectOne('/api/v1/reference/markets').flush({
      data: {
        version: 'iso:test',
        continents: [],
        currencies: [],
      },
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain(
      TestBed.inject(I18nService).translate('privacy.emptyTitle'),
    );
  });

  it('submits an attested export request and renders returned evidence', () => {
    const fixture = TestBed.createComponent(PrivacyPage);
    fixture.detectChanges();
    http.expectOne('/api/v1/me/privacy-requests').flush({ data: [] });
    http.expectOne('/api/v1/reference/markets').flush({
      data: {
        version: 'iso:test',
        continents: [],
        currencies: [],
      },
    });

    const component = fixture.componentInstance as unknown as {
      form: {
        patchValue(value: Record<string, unknown>): void;
      };
      submit(): void;
    };
    component.form.patchValue({
      type: 'data_export',
      reason: 'Please provide a portable copy of my data.',
      privacy_notice_confirmed: true,
    });
    component.submit();

    const request = http.expectOne('/api/v1/me/privacy-requests');
    expect(request.request.method).toBe('POST');
    expect(request.request.body.privacy_notice_confirmed).toBe(true);
    request.flush({ data: privacyRequestRecord() });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain(
      TestBed.inject(I18nService).translate('privacy.created'),
    );
  });

  it('cancels only after confirmation using the current event head', () => {
    const fixture = TestBed.createComponent(PrivacyPage);
    fixture.detectChanges();
    const record = privacyRequestRecord();
    http.expectOne('/api/v1/me/privacy-requests').flush({ data: [record] });
    http.expectOne('/api/v1/reference/markets').flush({
      data: {
        version: 'iso:test',
        continents: [],
        currencies: [],
      },
    });

    const component = fixture.componentInstance as unknown as {
      askToCancel(requestId: string): void;
      cancel(request: PrivacyRequest): void;
    };
    component.askToCancel(record.id);
    component.cancel(record);

    const cancellation = http.expectOne(
      `/api/v1/me/privacy-requests/${record.id}/cancel`,
    );
    expect(cancellation.request.method).toBe('POST');
    expect(cancellation.request.body.expected_current_event_id).toBe(
      record.current_event_id,
    );
    expect(cancellation.request.body.idempotency_key).toMatch(
      /^[0-9a-f-]{36}$/i,
    );
    cancellation.flush({
      data: privacyRequestRecord({
        status: 'cancelled',
        can_cancel: false,
        resolved_at: '2026-07-28T12:05:00Z',
      }),
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain(
      TestBed.inject(I18nService).translate('privacy.cancelled'),
    );
  });

  it('renders the safe fulfillment projection without private evidence', () => {
    const fixture = TestBed.createComponent(PrivacyPage);
    fixture.detectChanges();
    http.expectOne('/api/v1/me/privacy-requests').flush({
      data: [
        privacyRequestRecord({
          status: 'fulfilled',
          can_cancel: false,
          resolved_at: '2026-07-29T12:00:00Z',
          fulfillment: {
            id: '01KPRIVACYFULFILLMENT00001',
            request_type: 'data_export',
            execution_version: 'privacy-fulfillment:v1',
            data_inventory_version: 'privacy-data-inventory:v1',
            artifact_size_bytes: 4096,
            artifact_expires_at: '2026-08-05T12:00:00Z',
            backup_purge_due_at: null,
            completed_at: '2026-07-29T12:00:00Z',
          },
        }),
      ],
    });
    http.expectOne('/api/v1/reference/markets').flush({
      data: {
        version: 'iso:test',
        continents: [],
        currencies: [],
      },
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain(
      TestBed.inject(I18nService).translate('privacy.fulfillmentTitle'),
    );
    expect(fixture.nativeElement.textContent).toContain(
      'privacy-data-inventory:v1',
    );
  });
});
