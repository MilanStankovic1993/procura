# 01 — Product Vision

## 1. Product statement

Procura is an AI-assisted global market intelligence platform for buying and selling physical products.

It helps users answer two central questions:

### Buy Analysis

> Should I buy this product at this price?

### Sell Analysis

> At what price and in what way should I sell this product?

The system combines structured market data, comparable offers, historical pricing, deterministic business rules, AI-assisted product identification, cost calculations, and risk assessment.

## 2. Problem

Users currently research products manually across multiple marketplaces and price-comparison websites.

This process is:

- slow,
- inconsistent,
- difficult across languages,
- vulnerable to misleading listing titles,
- dependent on incomplete product information,
- unable to reliably distinguish asking prices from realistic sale prices,
- difficult when transport, repair, fees, tax, or customs must be considered.

Professional resellers and businesses face additional problems:

- no central record of opportunities,
- no consistent profitability calculation,
- no reliable comparison methodology,
- no shared team workflow,
- no history of previous decisions,
- no structured sourcing pipeline.

## 3. Product promise

The platform should transform fragmented product information into an actionable recommendation.

A result should answer:

- What product is this?
- What condition is it in?
- What is its estimated current market value?
- What is a realistic quick-sale value?
- What is an ambitious sale value?
- What additional costs should be expected?
- What is the estimated net profit or saving?
- How reliable is the estimate?
- What risks are present?
- What evidence is missing?
- What should the user do next?

## 4. Initial niche and geographic scope

The first release should focus on professional power tools while accepting manual analyses from any country.

The product is global by design:

- continent is a discovery and grouping filter,
- country is the primary market and compliance boundary,
- users may select one or more countries,
- cross-border searches must be explicit,
- price estimates must identify the market they represent,
- data availability and confidence may differ by country.

Initial brands:

- Bosch Professional
- Makita
- DeWalt
- Milwaukee
- Hilti
- Metabo
- Festool
- Einhell
- Ryobi

Initial categories:

- cordless drills,
- impact drivers,
- rotary hammers,
- angle grinders,
- circular saws,
- jigsaws,
- multi-tools,
- battery sets,
- tool sets,
- measuring tools.

## 5. Why start with a narrow category

A narrow category makes it possible to:

- build a reliable product catalog,
- normalize model names,
- define condition rules,
- compare similar products,
- understand accessories,
- estimate missing-item impact,
- evaluate battery and charger value,
- collect verified outcomes,
- improve accuracy faster.

The system architecture must remain category-agnostic and country-agnostic, but product rules and market adjustments may be category-specific or country-specific.

## 6. Product modes

### Mode A — Buy Analysis

Input:

- listing URL or manual data,
- listing title,
- description,
- price,
- images,
- seller information,
- transport and other costs.

Output:

- identified product,
- market price range,
- expected resale price,
- net profit estimate,
- deal score,
- risk score,
- missing checks,
- recommendation.

### Mode B — Sell Analysis

Input:

- owned product,
- product condition,
- included accessories,
- desired sale speed,
- target country or marketplace,
- images and product details.

Output:

- quick-sale price,
- recommended market price,
- ambitious price,
- expected time-to-sale,
- generated listing title,
- generated listing description,
- missing photos or evidence,
- price reduction strategy.

## 7. Long-term product direction

The product may later support:

- multiple product categories,
- multiple countries,
- procurement teams,
- broker workflows,
- market monitoring,
- alerts,
- portfolio management,
- transaction tracking,
- verified purchase and sale outcomes,
- B2B sourcing requests,
- pricing APIs,
- browser extensions,
- mobile applications.

## 8. Non-goals for MVP

The MVP is not:

- a marketplace,
- an escrow provider,
- a payment intermediary,
- a shipping company,
- a fully autonomous purchasing agent,
- an automatic seller-contact bot,
- a system for bypassing marketplace restrictions,
- a guarantee that a listing is safe,
- a guarantee that an estimated resale price will be achieved.
