import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiEnvelope, ApiHealth } from './api.models';

@Injectable({ providedIn: 'root' })
export class ApiStatusService {
  private readonly http = inject(HttpClient);

  getHealth(): Observable<ApiEnvelope<ApiHealth>> {
    return this.http.get<ApiEnvelope<ApiHealth>>('/api/v1/health');
  }
}
