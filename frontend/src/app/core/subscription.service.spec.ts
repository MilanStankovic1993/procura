import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { SubscriptionService } from './subscription.service';

describe('SubscriptionService', () => {
  it('loads the active tenant subscription without accepting an organization id', async () => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    const service = TestBed.inject(SubscriptionService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(service.current());
    const request = http.expectOne('/api/v1/organization/subscription');

    expect(request.request.method).toBe('GET');
    request.flush({
      data: {
        plan: { code: 'free', version: 1, name: 'Free', description: 'Foundation' },
        period: { starts_at: '2026-07-01', ends_at: '2026-07-31' },
        features: [],
      },
    });

    expect((await result).plan.code).toBe('free');
    http.verify();
  });

  it('starts checkout with an explicit idempotency key', async () => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    const service = TestBed.inject(SubscriptionService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(
      service.checkout('starter', 'monthly', 'checkout-request-0001'),
    );
    const request = http.expectOne('/api/v1/organization/billing/checkout');

    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('Idempotency-Key')).toBe(
      'checkout-request-0001',
    );
    expect(request.request.body).toEqual({
      plan: 'starter',
      interval: 'monthly',
    });
    request.flush({
      data: {
        url: 'https://checkout.stripe.test/session/1',
        expires_at: '2026-07-26T20:00:00Z',
      },
    });

    expect((await result).url).toContain('checkout.stripe.test');
    http.verify();
  });

  it('opens the provider billing portal without client-owned identifiers', async () => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    const service = TestBed.inject(SubscriptionService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(service.portal());
    const request = http.expectOne('/api/v1/organization/billing/portal');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({});
    request.flush({
      data: { url: 'https://billing.stripe.test/session/1' },
    });

    expect((await result).url).toContain('billing.stripe.test');
    http.verify();
  });
});
