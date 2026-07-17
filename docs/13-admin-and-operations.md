# 13 — Admin and Operations

## 1. Filament modules

Create resources for:

- Users
- Organizations
- Plans
- Subscriptions
- Marketplace Sources
- Analyses
- Listings
- Products
- Product Aliases
- AI Analyses
- Price Estimates
- Risk Assessments
- Deal Scores
- Saved Searches
- Alerts
- Broker Requests
- Settings
- Audit Logs

## 2. Operational queues

Admin review queues:

- unmatched products,
- low-confidence analyses,
- failed AI jobs,
- failed notifications,
- high-value analyses,
- critical-risk analyses,
- disputed estimates.

## 3. Dashboard metrics

- active users,
- paid users,
- analyses today,
- analyses this month,
- completion rate,
- AI cost,
- average AI cost per analysis,
- unmatched percentage,
- high-risk percentage,
- subscription conversion,
- verified user value,
- estimate accuracy.

## 4. Manual override

Overrides must require:

- reason,
- administrator identity,
- old value,
- new value,
- timestamp.

## 5. Feature flags

Use configurable feature flags for:

- AI provider,
- sell analysis,
- Telegram,
- marketplace connectors,
- broker module,
- public signup,
- payment enforcement.

## 6. Failure handling

Every failed pipeline must show:

- failed step,
- exception summary,
- retry count,
- last attempt,
- manual retry action.
