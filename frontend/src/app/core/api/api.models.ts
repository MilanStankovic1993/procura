export interface ApiEnvelope<T> {
  readonly data: T;
}

export interface ApiHealth {
  readonly service: string;
  readonly status: 'ok';
  readonly version: string;
  readonly timestamp: string;
}
