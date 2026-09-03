import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { BrokerRequestService } from './broker-request.service';

describe('BrokerRequestService', () => {
  let service: BrokerRequestService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(BrokerRequestService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('keeps list filters in the tenant API contract', () => {
    service.index({ q: 'CNC', status: 'searching', per_page: 20 }).subscribe();

    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/v1/broker-requests' &&
        candidate.params.get('q') === 'CNC' &&
        candidate.params.get('status') === 'searching' &&
        candidate.params.get('per_page') === '20',
    );
    expect(request.request.method).toBe('GET');
    request.flush({ data: [], links: {}, meta: {} });
  });

  it('uses exact event heads for submit and cancel transitions', () => {
    service.submit('01JBROKER', '01JEVENT', 'submit-key').subscribe();
    const submit = http.expectOne(
      '/api/v1/broker-requests/01JBROKER/submit',
    );
    expect(submit.request.method).toBe('POST');
    expect(submit.request.body).toEqual({
      expected_current_event_id: '01JEVENT',
      idempotency_key: 'submit-key',
    });
    submit.flush({ data: {} });

    service.cancel('01JBROKER', '01JNEXT', 'cancel-key').subscribe();
    const cancel = http.expectOne(
      '/api/v1/broker-requests/01JBROKER/cancel',
    );
    expect(cancel.request.method).toBe('POST');
    expect(cancel.request.body).toEqual({
      expected_current_event_id: '01JNEXT',
      idempotency_key: 'cancel-key',
    });
    cancel.flush({ data: {} });
  });

  it('binds offer acceptance to both exact event heads', () => {
    service
      .acceptOffer(
        '01JBROKER',
        '01JOFFER',
        '01JREQUESTEVENT',
        '01JOFFEREVENT',
        'accept-key',
      )
      .subscribe();

    const request = http.expectOne(
      '/api/v1/broker-requests/01JBROKER/offers/01JOFFER/accept',
    );
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      expected_request_event_id: '01JREQUESTEVENT',
      expected_offer_event_id: '01JOFFEREVENT',
      idempotency_key: 'accept-key',
    });
    request.flush({ data: {} });
  });
});
