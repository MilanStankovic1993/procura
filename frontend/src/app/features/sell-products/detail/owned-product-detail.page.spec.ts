import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap } from '@angular/router';

import { OwnedProductService } from '../../../core/owned-product.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { OwnedProductDetailPage } from './owned-product-detail.page';

describe('OwnedProductDetailPage', () => {
  it('creates with route parameters inside a valid injection context', () => {
    TestBed.configureTestingModule({
      imports: [OwnedProductDetailPage],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: {
              paramMap: convertToParamMap({ id: '01JOWNED' }),
            },
          },
        },
        {
          provide: OwnedProductService,
          useValue: {},
        },
        {
          provide: OrganizationContextService,
          useValue: {
            activeOrganization: signal(null),
          },
        },
      ],
    });

    const fixture = TestBed.createComponent(OwnedProductDetailPage);
    fixture.detectChanges();

    expect(fixture.componentInstance).toBeTruthy();
  });
});
