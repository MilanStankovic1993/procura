import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';

import { MarketReferenceService } from '../../../core/market-reference.service';
import { SavedSearchInput } from '../../../core/monitoring.models';
import { MonitoringService } from '../../../core/monitoring.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { SubscriptionService } from '../../../core/subscription.service';
import { SavedSearchFormPage } from './saved-search-form.page';

describe('SavedSearchFormPage', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('normalizes money percentages and delivery into the backend contract', () => {
    const createSavedSearch = vi.fn((input: SavedSearchInput) =>
      of({
        id: '01JSEARCH',
        ...input,
      }),
    );
    vi.stubGlobal('crypto', {
      randomUUID: () => '610b7226-f37e-4824-b48b-a3bc472099c7',
    });

    TestBed.configureTestingModule({
      imports: [SavedSearchFormPage],
      providers: [
        provideRouter([]),
        {
          provide: MonitoringService,
          useValue: {
            productCategories: () => of([]),
            createSavedSearch,
            updateSavedSearch: vi.fn(),
            savedSearch: vi.fn(),
            searchProducts: vi.fn(),
            telegramConnection: () =>
              of({
                available: true,
                entitled: true,
                status: 'connected',
                connection_id: '01JTELEGRAM',
                bot_username: 'ProcuraTestBot',
                challenge_expires_at: null,
                connected_at: '2026-07-26T12:00:00Z',
                can_enable_delivery: true,
              }),
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
                        code: 'DE',
                        alpha3_code: 'DEU',
                        numeric_code: '276',
                        name: 'Germany',
                        currency_code: 'EUR',
                        measurement_system: 'metric',
                      },
                    ],
                  },
                ],
                currencies: [
                  {
                    code: 'EUR',
                    numeric_code: '978',
                    name: 'Euro',
                    symbol: '€',
                    minor_unit: 2,
                    cash_minor_unit: 2,
                  },
                ],
              }),
            preferences: () =>
              of({
                home_country_code: 'DE',
                reporting_currency_code: 'EUR',
                locale: 'en',
                timezone: 'Europe/Berlin',
                measurement_system: 'metric',
                include_cross_border: false,
                country_codes: ['DE'],
              }),
          },
        },
        {
          provide: OrganizationContextService,
          useValue: {
            activeOrganization: signal({
              id: '01JORG',
              capabilities: ['saved-searches.manage'],
            }),
          },
        },
        {
          provide: SubscriptionService,
          useValue: {
            current: () =>
              of({
                plan: {
                  code: 'free',
                  version: 1,
                  name: 'Free',
                  description: 'Test plan',
                },
                period: {
                  starts_at: '2026-07-01T00:00:00Z',
                  ends_at: '2026-07-31T23:59:59Z',
                },
                features: [
                  {
                    code: 'notifications.email',
                    enabled: true,
                    limit: null,
                    used: null,
                    metered: false,
                  },
                ],
              }),
          },
        },
      ],
    });

    const fixture = TestBed.createComponent(SavedSearchFormPage);
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    fixture.detectChanges();

    setInput(fixture.nativeElement, 'title', '  Bosch Germany  ');
    setInput(fixture.nativeElement, 'minimum_price', '125.50');
    setInput(fixture.nativeElement, 'maximum_price', '300');
    setInput(fixture.nativeElement, 'minimum_margin_percent', '12.5');
    setInput(fixture.nativeElement, 'required_keywords', 'Bosch, Battery, bosch');
    const telegram = fixture.nativeElement.querySelector(
      '[formControlName="telegram_notifications"]',
    ) as HTMLInputElement;
    telegram.checked = true;
    telegram.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    const form = fixture.nativeElement.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    fixture.detectChanges();

    expect(createSavedSearch).toHaveBeenCalledOnce();
    expect(createSavedSearch.mock.calls[0][0]).toMatchObject({
      title: 'Bosch Germany',
      minimum_price_minor: 12550,
      maximum_price_minor: 30000,
      price_currency_code: 'EUR',
      minimum_margin_basis_points: 1250,
      country_codes: ['DE'],
      required_keywords: ['bosch', 'battery'],
      notification_channels: ['in_app', 'email', 'telegram'],
      idempotency_key: '610b7226-f37e-4824-b48b-a3bc472099c7',
    });
    expect(navigate).toHaveBeenCalledWith([
      '/app/saved-searches',
      '01JSEARCH',
    ]);

    createSavedSearch.mockClear();
    navigate.mockClear();
    setInput(fixture.nativeElement, 'minimum_price', '400');
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    fixture.detectChanges();

    expect(createSavedSearch).not.toHaveBeenCalled();
    expect(
      (fixture.nativeElement.querySelector('[role="alert"]') as HTMLElement)
        .textContent,
    ).toContain(
      'The minimum price cannot be greater than the maximum price.',
    );
    expect(navigate).not.toHaveBeenCalled();
  });
});

function setInput(root: HTMLElement, controlName: string, value: string): void {
  const input = root.querySelector(
    `[formControlName="${controlName}"]`,
  ) as HTMLInputElement | HTMLTextAreaElement;
  input.value = value;
  input.dispatchEvent(new Event('input'));
}
