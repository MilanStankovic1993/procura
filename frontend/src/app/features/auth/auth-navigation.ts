export function safeReturnUrl(value: string | null, fallback = '/app'): string {
  return value?.startsWith('/') && !value.startsWith('//') ? value : fallback;
}
