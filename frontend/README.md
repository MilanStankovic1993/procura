# Procura Angular frontend

This directory contains the primary Procura browser application.

## Stack

- Angular 22 with standalone components and strict TypeScript
- Angular Router with lazy feature routes
- Angular HttpClient with Laravel-compatible XSRF configuration
- Angular Signals for authenticated user and organization context
- Angular ESLint with TypeScript and template accessibility rules
- Vitest through the Angular CLI test builder
- SCSS design system and responsive application shell

Laravel remains in the repository root and exposes the versioned `/api/v1` contract. First-party
browser authentication uses Laravel Fortify and Sanctum session cookies; credentials are never
stored in browser storage.

## Local development

Keep the Laravel application available at `http://procura.test`, then run:

```powershell
npm ci
npm start
```

Open `http://localhost:4200`. `proxy.conf.json` forwards `/api` and `/sanctum` requests to the
Laragon backend and supplies the `procura.test` virtual-host header, so local DNS is not required
and CSRF/session cookies remain same-origin from the browser's perspective.

## Quality checks

```powershell
npm run lint
npm run test -- --watch=false
npm run build
npm audit --omit=dev --audit-level=moderate
```

The production build is written below `dist/procura-web/`. Production hosting will serve the
compiled Angular application and Laravel API under the same registrable domain.

## Source boundaries

- `src/app/core` — singleton API, authentication, guards, and interceptors
- `src/app/features` — lazy product and workflow features
- `src/app/layout` — application shells and navigation
- `src/styles.scss` — global design tokens and shared primitives

Feature code must not access tenant identifiers from untrusted client state. Laravel middleware,
policies, and the request-scoped active organization context remain authoritative. The Angular
organization service mirrors server state for presentation and switching; it never grants tenant
access.
