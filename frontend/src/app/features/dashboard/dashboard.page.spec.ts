import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { Listing } from '../../core/listing.models';
import { ListingService } from '../../core/listing.service';
import { OrganizationSummary } from '../../core/organizations/organization.models';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';
import { OwnedProduct } from '../../core/owned-product.models';
import { OwnedProductService } from '../../core/owned-product.service';
import { DashboardPage } from './dashboard.page';

const organization = (
  id: string,
  capabilities: OrganizationSummary['capabilities'] = [
    'listings.manage',
    'owned-products.manage',
  ],
): OrganizationSummary => ({
  id,
  name: 'Pilot Workspace',
  type: 'business',
  role: 'owner',
  capabilities,
  joined_at: null,
  is_active: true,
  created_at: null,
  updated_at: null,
});

const listing = {
  id: '01JLISTING',
  title: 'Bosch GSR 18V-55 kit',
  marketplace_name: 'Approved Market',
  target_country_code: 'DE',
  updated_at: '2026-08-13T10:00:00Z',
} as Listing;

const product = {
  id: '01JPRODUCT',
  brand_name: 'Makita',
  model_name: 'DHP484',
  category: { id: '01JCATEGORY', name: 'Professional drills' },
  target_continent_code: 'EU',
  updated_at: '2026-08-13T10:00:00Z',
} as OwnedProduct;

describe('DashboardPage', () => {
  it('presents the two primary actions and recent workspace work', () => {
    const activeOrganization = signal<OrganizationSummary | null>(
      organization('01JORG'),
    );

    TestBed.configureTestingModule({
      imports: [DashboardPage],
      providers: [
        provideRouter([]),
        {
          provide: AuthService,
          useValue: { user: signal({ name: 'Milan' }) },
        },
        {
          provide: OrganizationContextService,
          useValue: { activeOrganization },
        },
        {
          provide: ListingService,
          useValue: {
            list: vi.fn(() => of(cursorPage([listing]))),
          },
        },
        {
          provide: OwnedProductService,
          useValue: {
            list: vi.fn(() => of(cursorPage([product]))),
          },
        },
      ],
    });

    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();
    const content = fixture.nativeElement.textContent as string;
    const links = [...fixture.nativeElement.querySelectorAll('a')].map(
      (element: HTMLAnchorElement) => element.getAttribute('href'),
    );

    expect(content).toContain('What would you like to do, Milan?');
    expect(content).toContain('Bosch GSR 18V-55 kit');
    expect(content).toContain('Makita DHP484');
    expect(links).toContain('/app/buy/new');
    expect(links).toContain('/app/sell/new');
    expect(links).toContain('/app/buy/01JLISTING');
    expect(links).toContain('/app/sell/01JPRODUCT');
  });

  it('shows permission and retry states without exposing broken action links', () => {
    TestBed.configureTestingModule({
      imports: [DashboardPage],
      providers: [
        provideRouter([]),
        {
          provide: AuthService,
          useValue: { user: signal({ name: 'Viewer' }) },
        },
        {
          provide: OrganizationContextService,
          useValue: {
            activeOrganization: signal<OrganizationSummary | null>(
              organization('01JVIEWER', ['listings.view', 'owned-products.view']),
            ),
          },
        },
        {
          provide: ListingService,
          useValue: { list: vi.fn(() => throwError(() => new Error('offline'))) },
        },
        {
          provide: OwnedProductService,
          useValue: { list: vi.fn(() => throwError(() => new Error('offline'))) },
        },
      ],
    });

    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();
    const content = fixture.nativeElement.textContent as string;

    expect(content).toContain('Ask a workspace manager to start');
    expect(content).toContain('We could not load your recent work.');
    expect(fixture.nativeElement.querySelector('a[href="/app/buy/new"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('a[href="/app/sell/new"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('button')?.textContent).toContain('Retry');
  });

  it('reloads recent work after the active workspace changes', () => {
    const activeOrganization = signal<OrganizationSummary | null>(
      organization('01JFIRST'),
    );
    const listListings = vi.fn(() => of(cursorPage<Listing>([])));
    const listProducts = vi.fn(() => of(cursorPage<OwnedProduct>([])));

    TestBed.configureTestingModule({
      imports: [DashboardPage],
      providers: [
        provideRouter([]),
        { provide: AuthService, useValue: { user: signal({ name: 'Milan' }) } },
        { provide: OrganizationContextService, useValue: { activeOrganization } },
        { provide: ListingService, useValue: { list: listListings } },
        { provide: OwnedProductService, useValue: { list: listProducts } },
      ],
    });

    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();
    activeOrganization.set(organization('01JSECOND'));
    fixture.detectChanges();

    expect(listListings).toHaveBeenCalledTimes(2);
    expect(listProducts).toHaveBeenCalledTimes(2);
  });
});

function cursorPage<T>(data: readonly T[]) {
  return {
    data,
    links: { prev: null, next: null },
    meta: {
      path: '/api/test',
      per_page: 3,
      next_cursor: null,
      prev_cursor: null,
    },
  };
}
