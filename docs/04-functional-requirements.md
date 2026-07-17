# 04 — Functional Requirements

## FR-001 Authentication

The system shall support:

- registration,
- login,
- logout,
- password reset,
- email verification,
- account deletion request.

## FR-002 Organizations

The system shall support:

- personal organizations,
- business organizations,
- organization switching,
- member invitations,
- owner and member roles.

## FR-003 Buy Analysis intake

The user shall be able to submit:

- source URL,
- marketplace name,
- title,
- description,
- asking price,
- currency,
- seller information,
- location,
- source country,
- target market country,
- product images,
- screenshots,
- notes.

## FR-004 Sell Analysis intake

The user shall be able to submit:

- product brand,
- model,
- category,
- condition,
- age,
- accessories,
- defects,
- purchase history,
- target continent and one or more target countries,
- cross-border sale preference,
- desired sale speed,
- images.

## FR-005 Product identification

The system shall identify:

- category,
- brand,
- model,
- variant,
- included accessories,
- missing accessories,
- condition,
- defects.

## FR-006 Comparable offers

The system shall store and rank comparable records based on:

- exact model,
- product variant,
- condition,
- accessories,
- location,
- time period,
- seller type,
- listing status.

## FR-007 Price estimate

The system shall calculate:

- quick-sale price,
- recommended market price,
- ambitious price,
- purchase fair-value range,
- confidence score.

## FR-008 Cost calculation

The system shall accept:

- transport,
- repair,
- platform fees,
- payment fees,
- customs,
- tax,
- other costs,
- safety reserve.

## FR-009 Profit calculation

The system shall calculate:

- gross margin,
- total cost,
- net profit,
- profit margin,
- return on invested capital.

## FR-010 Risk assessment

The system shall calculate:

- risk score,
- risk level,
- individual risk signals,
- required verification actions.

## FR-011 Deal score

The system shall calculate and explain:

- final score,
- margin component,
- confidence component,
- demand component,
- risk component,
- logistics component.

## FR-012 Sell recommendation

The system shall generate:

- recommended listing title,
- recommended description,
- photo checklist,
- proof checklist,
- pricing strategy,
- price-reduction schedule.

## FR-013 Outcome tracking

The user shall be able to record:

- actual purchase price,
- actual total costs,
- actual listing date,
- actual sale date,
- actual sale price,
- actual net profit,
- cancellation reason.

## FR-014 Saved searches

The system shall support criteria for:

- category,
- brand,
- model,
- price,
- continent,
- one or more countries,
- city,
- radius,
- cross-border inclusion,
- minimum profit,
- minimum margin,
- minimum deal score,
- maximum risk.

## FR-015 Alerts

The system shall support:

- in-app notifications,
- email,
- Telegram.

## FR-016 Admin review

Administrators shall be able to:

- confirm product matches,
- create aliases,
- override estimates,
- rerun pipelines,
- review failed jobs,
- review high-risk results.

## FR-017 Subscription enforcement

The backend shall enforce:

- analysis limits,
- saved-search limits,
- team-member limits,
- notification-channel limits,
- report limits.

## FR-018 Exports

Business users should later be able to export:

- analysis reports,
- opportunity lists,
- profit records,
- sourcing comparisons.

## FR-019 Global market context

The system shall support:

- ISO 3166-1 alpha-2 country codes,
- ISO 4217 currency codes,
- BCP 47 language tags,
- IANA time zones,
- continent-to-country selection,
- original and reporting currencies,
- dated exchange rates used in calculations,
- metric and imperial product attributes,
- explicit origin and destination countries for cross-border costs.
