import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';

import { ListingInput } from '../../../core/listing.models';
import { ListingService } from '../../../core/listing.service';
import { MarketReferenceService } from '../../../core/market-reference.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { ListingIntakePage } from './listing-intake.page';

describe('ListingIntakePage', () => {
  it('guides the user through three steps and infers the marketplace from the URL', () => {
    const create = vi.fn((input: ListingInput) => of({ id: '01JLISTING', ...input }));

    TestBed.configureTestingModule({
      imports: [ListingIntakePage],
      providers: [
        provideRouter([]),
        {
          provide: ListingService,
          useValue: {
            sources: () =>
              of([
                {
                  key: 'manual',
                  name: 'Manual entry',
                  connector_type: 'manual',
                  capabilities: [],
                  supported_country_codes: null,
                  supported_currency_codes: null,
                  supported_language_tags: null,
                  geographic_coverage: null,
                  cross_border_supported: true,
                  compliance_status: 'approved',
                  available: true,
                  asking_price_only: true,
                  transaction_price_supported: false,
                },
              ]),
            create,
          },
        },
        {
          provide: MarketReferenceService,
          useValue: {
            catalog: () =>
              of({
                version: 'test',
                continents: [
                  {
                    code: 'EU',
                    name: 'Europe',
                    countries: [
                      { code: 'RS', name: 'Serbia', currency_code: 'RSD' },
                      { code: 'DE', name: 'Germany', currency_code: 'EUR' },
                    ],
                  },
                ],
                currencies: [
                  { code: 'RSD', name: 'Serbian Dinar', minor_unit: 2 },
                ],
              }),
            preferences: () =>
              of({
                home_country_code: 'RS',
                reporting_currency_code: 'RSD',
                locale: 'en',
                timezone: 'Europe/Belgrade',
                measurement_system: 'metric',
                include_cross_border: true,
                country_codes: ['RS', 'DE'],
              }),
          },
        },
        {
          provide: OrganizationContextService,
          useValue: {
            activeOrganization: signal({
              id: '01JORG',
              capabilities: ['listings.manage'],
            }),
          },
        },
      ],
    });

    const fixture = TestBed.createComponent(ListingIntakePage);
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    fixture.detectChanges();

    const sourceUrl = fixture.nativeElement.querySelector('#source-url') as HTMLInputElement;
    const title = fixture.nativeElement.querySelector('#listing-title') as HTMLInputElement;
    sourceUrl.value = 'https://www.kleinanzeigen.de/s-anzeige/123';
    sourceUrl.dispatchEvent(new Event('input'));
    sourceUrl.dispatchEvent(new Event('blur'));
    title.value = 'Leica camera';
    title.dispatchEvent(new Event('input'));

    clickPrimary(fixture);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('#source-country')).not.toBeNull();

    clickPrimary(fixture);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('#product-images')).not.toBeNull();

    const form = fixture.nativeElement.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    expect(create).toHaveBeenCalledOnce();
    expect(create.mock.calls[0][0]).toMatchObject({
      marketplace_name: 'kleinanzeigen.de',
      title: 'Leica camera',
      source_country_code: 'RS',
      target_country_code: 'DE',
    });
    expect(navigate).toHaveBeenCalledWith(['/app/buy', '01JLISTING']);
  });
});

function clickPrimary(fixture: ComponentFixture<ListingIntakePage>): void {
  const button = fixture.nativeElement.querySelector(
    'button.button-primary',
  ) as HTMLButtonElement;
  button.click();
}
