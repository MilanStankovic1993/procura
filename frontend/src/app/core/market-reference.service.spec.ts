import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { MarketReferenceService } from './market-reference.service';

describe('MarketReferenceService', () => {
  let service: MarketReferenceService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(MarketReferenceService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('shares the immutable reference catalog request', async () => {
    const first = firstValueFrom(service.catalog());
    const second = firstValueFrom(service.catalog());
    const request = http.expectOne('/api/v1/reference/markets');

    expect(request.request.method).toBe('GET');
    request.flush({ data: { version: 'ICU 77.1', continents: [], currencies: [] } });

    expect((await first).version).toBe('ICU 77.1');
    expect((await second).version).toBe('ICU 77.1');
  });

  it('persists preferences without accepting an organization identifier', async () => {
    const preferences = {
      home_country_code: 'AT',
      reporting_currency_code: 'EUR',
      locale: 'de-AT',
      timezone: 'Europe/Vienna',
      measurement_system: 'metric' as const,
      include_cross_border: true,
      country_codes: ['AT', 'DE'],
    };
    const result = firstValueFrom(service.save(preferences));
    const request = http.expectOne('/api/v1/organization/market-preferences');

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(preferences);
    request.flush({ data: preferences });

    expect(await result).toEqual(preferences);
  });
});
