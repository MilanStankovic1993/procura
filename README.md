# Procura — Product Documentation

This repository documentation defines the product, business model, architecture, data model, AI behavior, implementation phases, and engineering rules for Procura, a global SaaS platform that supports market research for buying and selling products.

The platform has two primary product modes:

1. **Buy Analysis** — helps a user decide whether a product should be purchased.
2. **Sell Analysis** — helps a user decide how, when, and at what price a product should be sold.

Procura is global by design. A continent is used for discovery and grouping, while countries, currencies, languages, shipping boundaries, and local market conditions drive search and price analysis.

The long-term product may also support:

- professional resellers,
- procurement teams,
- brokers,
- sourcing services,
- saved market searches,
- alerts,
- product portfolios,
- actual profit tracking,
- multi-marketplace intelligence.

## Documentation order

Read and implement the documents in this order:

1. `docs/01-product-vision.md`
2. `docs/02-users-and-use-cases.md`
3. `docs/03-business-requirements.md`
4. `docs/04-functional-requirements.md`
5. `docs/05-user-flows.md`
6. `docs/06-domain-model.md`
7. `docs/07-system-architecture.md`
8. `docs/08-price-intelligence-engine.md`
9. `docs/09-ai-engine.md`
10. `docs/10-risk-and-deal-scoring.md`
11. `docs/11-marketplace-connectors.md`
12. `docs/12-security-compliance.md`
13. `docs/13-admin-and-operations.md`
14. `docs/14-testing-strategy.md`
15. `docs/15-delivery-roadmap.md`
16. `docs/16-codex-development-rules.md`
17. `docs/17-global-market-model.md`
18. `docs/18-development-handoff.md`
19. `docs/19-production-go-live.md`

Repository branching, pull-request checks, and release promotion rules are defined in
[`CONTRIBUTING.md`](CONTRIBUTING.md). `main` contains reviewed production checkpoints, while
`develop` is the shared integration branch for the next release.

## Implementation principle

Do not begin broad marketplace automation first.

The initial commercial MVP must prove that the system can:

- receive product and listing data,
- identify the product,
- estimate a realistic market price,
- calculate purchase or sale economics,
- display risk and confidence,
- explain the result,
- store actual outcomes for future learning.

## Product success criterion

The platform is valuable only when it measurably helps users:

- avoid overpaying,
- sell at a better price,
- reduce time spent researching,
- reduce purchase risk,
- increase net profit,
- make more confident decisions.

The primary long-term metric is:

```text
verified financial value delivered to users
divided by
subscription and service cost
```
