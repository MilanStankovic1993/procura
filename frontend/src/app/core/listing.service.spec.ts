import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { ListingInput } from './listing.models';
import { ListingService } from './listing.service';

describe('ListingService', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  it('loads a cursor page from the active tenant without sending an organization id', async () => {
    const service = TestBed.inject(ListingService);
    const http = TestBed.inject(HttpTestingController);
    const result = firstValueFrom(
      service.list({ q: 'Bosch', status: 'active', per_page: 20 }),
    );
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/v1/listings' &&
        candidate.params.get('q') === 'Bosch' &&
        candidate.params.get('status') === 'active' &&
        !candidate.params.has('organization_id'),
    );

    expect(request.request.method).toBe('GET');
    request.flush({
      data: [],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        path: '/api/v1/listings',
        per_page: 20,
        next_cursor: null,
        prev_cursor: null,
      },
    });

    expect((await result).data).toEqual([]);
  });

  it('creates source facts as JSON before separate file uploads', async () => {
    const service = TestBed.inject(ListingService);
    const http = TestBed.inject(HttpTestingController);
    const input: ListingInput = {
      marketplace_source_key: 'manual',
      source_url: 'https://market.example/listing/42',
      external_id: '42',
      marketplace_name: 'Market',
      title: 'Bosch drill',
      description: null,
      asking_price_minor: 12999,
      currency_code: 'EUR',
      seller_information: null,
      location: null,
      source_country_code: 'AT',
      target_country_code: 'DE',
      status: 'active',
      notes: null,
    };
    const result = firstValueFrom(service.create(input));
    const request = http.expectOne('/api/v1/listings');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(input);
    request.flush({ data: { id: '01JLISTING' } });

    expect((await result).id).toBe('01JLISTING');
  });

  it('uses multipart form data for private evidence', async () => {
    const service = TestBed.inject(ListingService);
    const http = TestBed.inject(HttpTestingController);
    const file = new File(['image'], 'drill.jpg', { type: 'image/jpeg' });
    const result = firstValueFrom(service.uploadImages('01JLISTING', 'product', [file]));
    const request = http.expectOne('/api/v1/listings/01JLISTING/images');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeInstanceOf(FormData);
    expect((request.request.body as FormData).get('kind')).toBe('product');
    request.flush({ data: [] });

    expect(await result).toEqual([]);
  });

  it('submits authorized csv evidence with an idempotency boundary', async () => {
    const service = TestBed.inject(ListingService);
    const http = TestBed.inject(HttpTestingController);
    const file = new File(['external_id,title\n42,Drill'], 'listings.csv', {
      type: 'text/csv',
    });
    const result = firstValueFrom(
      service.createImport(file, 'semicolon', 'DE', '018f47f1-4a34-7f35-b5df-1234567890ab'),
    );
    const request = http.expectOne('/api/v1/marketplace-imports');
    const body = request.request.body as FormData;

    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('Idempotency-Key')).toBe(
      '018f47f1-4a34-7f35-b5df-1234567890ab',
    );
    expect(body.get('marketplace_source_key')).toBe('authorized_csv');
    expect(body.get('delimiter')).toBe('semicolon');
    expect(body.get('default_target_country_code')).toBe('DE');
    expect(body.get('authorization_confirmed')).toBe('1');
    expect(body.get('file')).toBeInstanceOf(File);
    request.flush({ data: { id: '01JIMPORT', status: 'pending' } });

    expect((await result).id).toBe('01JIMPORT');
  });
});
