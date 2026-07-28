import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap } from '@angular/router';
import { of } from 'rxjs';

import { ListingService } from '../../../core/listing.service';
import { OrganizationContextService } from '../../../core/organizations/organization-context.service';
import { ListingDetailPage } from './listing-detail.page';

describe('ListingDetailPage', () => {
  it('creates with route parameters inside a valid injection context', () => {
    TestBed.configureTestingModule({
      imports: [ListingDetailPage],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of(convertToParamMap({ id: '01JLISTING' })),
          },
        },
        {
          provide: ListingService,
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

    const fixture = TestBed.createComponent(ListingDetailPage);
    fixture.detectChanges();

    expect(fixture.componentInstance).toBeTruthy();
  });
});
