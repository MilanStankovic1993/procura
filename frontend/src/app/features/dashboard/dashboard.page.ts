import { Component, computed, effect, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { catchError, forkJoin, map, of } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { Listing } from '../../core/listing.models';
import { ListingService } from '../../core/listing.service';
import { OrganizationContextService } from '../../core/organizations/organization-context.service';
import { OwnedProduct } from '../../core/owned-product.models';
import { OwnedProductService } from '../../core/owned-product.service';

type DashboardState = 'idle' | 'loading' | 'ready' | 'error';

@Component({
  selector: 'app-dashboard-page',
  imports: [RouterLink, TranslatePipe],
  templateUrl: './dashboard.page.html',
  styleUrl: './dashboard.page.scss',
})
export class DashboardPage {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(I18nService);
  private readonly listings = inject(ListingService);
  private readonly ownedProducts = inject(OwnedProductService);
  private readonly organizationContext = inject(OrganizationContextService);
  private loadedOrganizationId: string | null = null;

  protected readonly user = this.auth.user;
  protected readonly activeOrganization = this.organizationContext.activeOrganization;
  protected readonly state = signal<DashboardState>('idle');
  protected readonly recentListings = signal<readonly Listing[]>([]);
  protected readonly recentProducts = signal<readonly OwnedProduct[]>([]);
  protected readonly canStartBuy = computed(
    () =>
      this.activeOrganization()?.capabilities.includes('listings.manage') === true,
  );
  protected readonly canStartSell = computed(
    () =>
      this.activeOrganization()?.capabilities.includes('owned-products.manage') ===
      true,
  );
  protected readonly hasRecentWork = computed(
    () => this.recentListings().length > 0 || this.recentProducts().length > 0,
  );

  constructor() {
    effect(() => {
      const organizationId = this.activeOrganization()?.id ?? null;

      if (organizationId !== null && organizationId !== this.loadedOrganizationId) {
        this.loadedOrganizationId = organizationId;
        this.loadRecentWork();
      }
    });
  }

  protected retry(): void {
    this.loadRecentWork();
  }

  protected productTitle(product: OwnedProduct): string {
    const title = [product.brand_name, product.model_name].filter(Boolean).join(' ');

    return title || this.i18n.translate('ownedProduct.common.unknownProduct');
  }

  protected date(value: string | null): string {
    return value === null
      ? this.i18n.translate('common.unknownDate')
      : this.i18n.formatDate(value, { dateStyle: 'medium' });
  }

  private loadRecentWork(): void {
    this.state.set('loading');

    forkJoin({
      listings: this.listings.list({ per_page: 3 }).pipe(
        map((page) => page.data),
        catchError(() => of(null)),
      ),
      products: this.ownedProducts.list({ per_page: 3 }).pipe(
        map((page) => page.data),
        catchError(() => of(null)),
      ),
    }).subscribe(({ listings, products }) => {
      this.recentListings.set(listings ?? []);
      this.recentProducts.set(products ?? []);
      this.state.set(listings === null && products === null ? 'error' : 'ready');
    });
  }
}
