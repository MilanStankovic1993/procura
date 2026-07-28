import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';

import { MarketReferenceService } from '../../../core/market-reference.service';
import { OwnedProductInput } from '../../../core/owned-product.models';
import { OwnedProductService } from '../../../core/owned-product.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { OwnedProductIntakePage } from './owned-product-intake.page';

describe('OwnedProductIntakePage', () => {
  it('normalizes a browser number input before creating the owned product', () => {
    const create = vi.fn((input: OwnedProductInput) =>
      of({ id: '01JOWNED', ...input }),
    );

    TestBed.configureTestingModule({
      imports: [OwnedProductIntakePage],
      providers: [
        provideRouter([]),
        {
          provide: OwnedProductService,
          useValue: {
            categories: () => of([]),
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
                      {
                        code: 'RS',
                        alpha3_code: 'SRB',
                        numeric_code: '688',
                        name: 'Serbia',
                        currency_code: 'RSD',
                        measurement_system: 'metric',
                      },
                    ],
                  },
                ],
                currencies: [],
              }),
            preferences: () =>
              of({
                home_country_code: 'RS',
                reporting_currency_code: 'RSD',
                locale: 'en',
                timezone: 'Europe/Belgrade',
                measurement_system: 'metric',
                include_cross_border: false,
                country_codes: ['RS'],
              }),
          },
        },
        {
          provide: OrganizationContextService,
          useValue: {
            activeOrganization: signal({
              id: '01JORG',
              capabilities: ['owned-products.manage'],
            }),
          },
        },
      ],
    });

    const fixture = TestBed.createComponent(OwnedProductIntakePage);
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    fixture.detectChanges();

    const ageInput = fixture.nativeElement.querySelector(
      'input[type="number"]',
    ) as HTMLInputElement;
    ageInput.value = '18';
    ageInput.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    const form = fixture.nativeElement.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    expect(create).toHaveBeenCalledOnce();
    expect(create.mock.calls[0][0].age_months).toBe(18);
    expect(navigate).toHaveBeenCalledWith(['/app/sell', '01JOWNED']);
  });
});
