import { inject, Injectable } from '@angular/core';

import { I18nService } from './i18n.service';
import { TranslationKey } from './locales/en';

const ANALYSIS_CODE_KEYS: Readonly<Record<string, TranslationKey>> = {
  low: 'analysisLabel.low',
  medium: 'analysisLabel.medium',
  high: 'analysisLabel.high',
  critical: 'analysisLabel.critical',
  estimated: 'analysisLabel.estimated',
  low_confidence: 'analysisLabel.lowConfidence',
  needs_input: 'analysisLabel.needsInput',
  assessed: 'analysisLabel.assessed',
  included: 'analysisLabel.included',
  outlier: 'analysisLabel.outlier',
  missing_rate: 'analysisLabel.missingRate',
  stale_rate: 'analysisLabel.staleRate',
  invalid_amount: 'analysisLabel.invalidAmount',
  invalid_normalization: 'analysisDetail.normalization.code.invalidEvidence',
  unresolved: 'analysisLabel.unresolved',
  identity: 'analysisLabel.identity',
  direct: 'analysisLabel.direct',
  inverse: 'analysisLabel.inverse',
  strong_opportunity: 'analysisLabel.strongOpportunity',
  potential_opportunity: 'analysisLabel.potentialOpportunity',
  needs_verification: 'analysisLabel.needsVerification',
  weak_opportunity: 'analysisLabel.weakOpportunity',
  avoid: 'analysisLabel.avoid',
  insufficient_data: 'analysisLabel.insufficientData',
  estimated_net_margin: 'analysisLabel.estimatedNetMargin',
  price_confidence: 'analysisLabel.priceConfidence',
  resale_demand: 'analysisLabel.resaleDemand',
  inverse_risk: 'analysisLabel.inverseRisk',
  logistics_simplicity: 'analysisLabel.logisticsSimplicity',
  strengthens: 'analysisLabel.strengthens',
  neutral: 'analysisLabel.neutral',
  reduces: 'analysisLabel.reduces',
  unknown: 'analysisLabel.unknown',
  listing: 'analysisLabel.listing',
  seller: 'analysisLabel.seller',
  product: 'analysisLabel.product',
  transaction: 'analysisLabel.transaction',
  draft: 'analysisLabel.draft',
  queued: 'analysisLabel.queued',
  processing: 'analysisLabel.processing',
  completed: 'analysisLabel.completed',
  failed: 'analysisLabel.failed',
  archived: 'analysisLabel.archived',
  pending: 'analysisLabel.pending',
  dispatching: 'analysisLabel.dispatching',
  dispatched: 'analysisLabel.dispatched',
  valid: 'analysisLabel.valid',
  invalid: 'analysisLabel.invalid',
  matched: 'analysisLabel.matched',
  review_required: 'analysisLabel.reviewRequired',
  unmatched: 'analysisLabel.unmatched',
  not_required: 'analysisLabel.notRequired',
  confirmed: 'analysisLabel.confirmed',
  rejected: 'analysisLabel.rejected',
  compatible: 'analysisLabel.compatible',
  incompatible: 'analysisLabel.incompatible',
  target_currency_unavailable:
    'analysisDetail.normalization.code.targetCurrencyUnavailable',
  currency_conversion_unavailable:
    'analysisDetail.normalization.code.currencyUnavailable',
  cross_country_normalization_unavailable:
    'analysisDetail.normalization.code.crossCountryUnavailable',
  market_compatibility_rejected:
    'analysisDetail.normalization.code.compatibilityRejected',
  market_normalization_evidence_invalid:
    'analysisDetail.normalization.code.invalidEvidence',
  normalized_amount_invalid:
    'analysisDetail.normalization.code.invalidAmount',
  cross_country_normalized:
    'analysisDetail.normalization.code.crossCountryNormalized',
  dated_currency_normalized:
    'analysisDetail.normalization.code.currencyNormalized',
  regional_compatibility_confirmed:
    'analysisDetail.normalization.code.compatibilityConfirmed',
  regional_compatibility_rejected:
    'analysisDetail.normalization.code.compatibilityRejected',
  dated_exchange_rate_resolved:
    'analysisDetail.normalization.code.rateResolved',
  identity_currency_conversion:
    'analysisDetail.normalization.code.identityRate',
  market_factor_applied:
    'analysisDetail.normalization.code.marketFactorApplied',
  shipping_cost_applied:
    'analysisDetail.normalization.code.shippingApplied',
  import_duty_applied:
    'analysisDetail.normalization.code.importDutyApplied',
  tax_cost_applied:
    'analysisDetail.normalization.code.taxApplied',
  other_market_cost_applied:
    'analysisDetail.normalization.code.otherCostApplied',
  cross_market_normalization_applied:
    'analysisDetail.normalization.code.normalizationApplied',
  explicit_market_normalization_applied:
    'analysisDetail.normalization.code.normalizationApplied',
  identity_currency_normalized:
    'analysisDetail.normalization.code.identityRate',
  dated_exchange_rates_applied:
    'analysisDetail.normalization.code.datedRatesApplied',
  unspecified: 'analysisLabel.unspecified',
  ready: 'analysisLabel.ready',
  insufficient: 'analysisLabel.insufficient',
  excluded: 'analysisLabel.excluded',
  exact_catalog_alias: 'ownedProduct.assessment.code.exactCatalogAlias',
  searchable_product_text_missing:
    'ownedProduct.assessment.code.searchableProductTextMissing',
  catalog_alias_not_found: 'ownedProduct.assessment.code.catalogAliasNotFound',
  multiple_close_catalog_candidates:
    'ownedProduct.assessment.code.multipleCloseCatalogCandidates',
  target_market_variant_incompatible:
    'ownedProduct.assessment.code.targetMarketVariantIncompatible',
  catalog_match_below_auto_threshold:
    'ownedProduct.assessment.code.catalogMatchBelowAutoThreshold',
  accessories_unchecked: 'ownedProduct.assessment.code.accessoriesUnchecked',
  expected_accessory_baseline_unavailable:
    'ownedProduct.assessment.code.expectedAccessoryBaselineUnavailable',
  expected_accessories_missing:
    'ownedProduct.assessment.code.expectedAccessoriesMissing',
  condition_unknown: 'ownedProduct.assessment.code.conditionUnknown',
  defects_unchecked: 'ownedProduct.assessment.code.defectsUnchecked',
  product_images_missing: 'ownedProduct.assessment.code.productImagesMissing',
  serial_label_missing: 'ownedProduct.assessment.code.serialLabelMissing',
  check_accessories: 'ownedProduct.assessment.code.checkAccessories',
  verify_expected_accessories:
    'ownedProduct.assessment.code.verifyExpectedAccessories',
  confirm_missing_accessories:
    'ownedProduct.assessment.code.confirmMissingAccessories',
  confirm_catalog_candidate:
    'ownedProduct.assessment.code.confirmCatalogCandidate',
  provide_identifying_model_evidence:
    'ownedProduct.assessment.code.provideIdentifyingModelEvidence',
  confirm_condition: 'ownedProduct.assessment.code.confirmCondition',
  inspect_defects: 'ownedProduct.assessment.code.inspectDefects',
  add_product_photos: 'ownedProduct.assessment.code.addProductPhotos',
  add_serial_label_photo:
    'ownedProduct.assessment.code.addSerialLabelPhoto',
  category: 'ownedProduct.assessment.code.category',
  brand: 'ownedProduct.assessment.code.brand',
  model: 'ownedProduct.assessment.code.model',
  condition: 'ownedProduct.assessment.code.condition',
  included_accessories:
    'ownedProduct.assessment.code.includedAccessories',
  missing_accessories: 'ownedProduct.assessment.code.missingAccessories',
  defects: 'ownedProduct.assessment.code.defects',
  same_market_currency_only:
    'ownedProduct.pricing.code.sameMarketCurrencyOnly',
  explicit_market_normalization_only:
    'ownedProduct.pricing.code.explicitMarketNormalizationOnly',
  native_market_amount_used:
    'ownedProduct.pricing.code.nativeMarketAmountUsed',
  sufficient_eligible_sell_comparables:
    'ownedProduct.pricing.code.sufficientEligibleComparables',
  no_eligible_sell_comparables:
    'ownedProduct.pricing.code.noEligibleSellComparables',
  insufficient_sell_comparables:
    'ownedProduct.pricing.code.insufficientSellComparables',
  candidate_pool_truncated:
    'ownedProduct.pricing.code.candidatePoolTruncated',
  superseded_source_observation:
    'ownedProduct.pricing.code.supersededSourceObservation',
  spare_part_listing: 'ownedProduct.pricing.code.sparePartListing',
  broken_only_listing: 'ownedProduct.pricing.code.brokenOnlyListing',
  wanted_listing: 'ownedProduct.pricing.code.wantedListing',
  rental_listing: 'ownedProduct.pricing.code.rentalListing',
  unclear_bundle_listing: 'ownedProduct.pricing.code.unclearBundleListing',
  broken_condition: 'ownedProduct.pricing.code.brokenCondition',
  currency_conversion_not_authorized:
    'ownedProduct.pricing.code.currencyConversionNotAuthorized',
  cross_market_normalization_not_authorized:
    'ownedProduct.pricing.code.crossMarketNormalizationNotAuthorized',
  incompatible_variant: 'ownedProduct.pricing.code.incompatibleVariant',
  region_incompatible_variant:
    'ownedProduct.pricing.code.regionIncompatibleVariant',
  selection_limit_reached:
    'ownedProduct.pricing.code.selectionLimitReached',
  exact_model: 'ownedProduct.pricing.code.exactModel',
  same_market: 'ownedProduct.pricing.code.sameMarket',
  same_currency: 'ownedProduct.pricing.code.sameCurrency',
  exact_variant: 'ownedProduct.pricing.code.exactVariant',
  variant_unspecified: 'ownedProduct.pricing.code.variantUnspecified',
  variant_not_required_by_assessment:
    'ownedProduct.pricing.code.variantNotRequired',
  target_condition_unavailable:
    'ownedProduct.pricing.code.targetConditionUnavailable',
  exact_condition: 'ownedProduct.pricing.code.exactCondition',
  condition_differs: 'ownedProduct.pricing.code.conditionDiffers',
  target_accessories_unavailable:
    'ownedProduct.pricing.code.targetAccessoriesUnavailable',
  accessories_compared: 'ownedProduct.pricing.code.accessoriesCompared',
  asking_price_evidence_only: 'ownedProduct.pricing.code.askingPriceOnly',
  minimum_sell_evidence_met: 'ownedProduct.pricing.code.minimumEvidenceMet',
  weighted_median_recorded:
    'ownedProduct.pricing.code.weightedMedianRecorded',
  interquartile_range_recorded:
    'ownedProduct.pricing.code.interquartileRangeRecorded',
  data_derived_sell_bands: 'ownedProduct.pricing.code.dataDerivedBands',
  no_currency_or_market_mixing:
    'ownedProduct.pricing.code.noMarketMixing',
  extreme_outliers_excluded:
    'ownedProduct.pricing.code.extremeOutliersExcluded',
  high_price_dispersion: 'ownedProduct.pricing.code.highPriceDispersion',
  sell_price_confidence_low: 'ownedProduct.pricing.code.lowConfidence',
  realized_transaction_prices: 'ownedProduct.pricing.code.realizedPrices',
  expected_sale_duration: 'ownedProduct.pricing.code.saleDuration',
  independent_source_diversity:
    'ownedProduct.pricing.code.sourceDiversity',
  sufficient_same_market_comparables:
    'ownedProduct.pricing.code.sufficientComparables',
  confirm_realized_sale_outcomes:
    'ownedProduct.pricing.code.confirmOutcomes',
  add_independent_marketplace_source:
    'ownedProduct.pricing.code.addIndependentSource',
  add_same_market_comparables:
    'ownedProduct.pricing.code.addSameMarketComparables',
  add_eligible_sell_comparables:
    'ownedProduct.pricing.code.addEligibleSellComparables',
  selected_comparable: 'ownedProduct.pricing.code.selectedComparable',
  median_absolute_deviation_outlier:
    'ownedProduct.pricing.code.madOutlier',
  insufficient_sell_comparables_after_outlier_removal:
    'ownedProduct.pricing.code.insufficientAfterOutliers',
  comparable_count: 'ownedProduct.pricing.code.comparableCount',
  product_match_quality: 'ownedProduct.pricing.code.productMatchQuality',
  condition_completeness:
    'ownedProduct.pricing.code.conditionCompleteness',
  price_consistency: 'ownedProduct.pricing.code.priceConsistency',
  source_diversity: 'ownedProduct.pricing.code.sourceDiversity',
  freshness: 'ownedProduct.pricing.code.freshness',
  asking_price_guidance_not_guarantee:
    'ownedProduct.listing.code.askingGuidance',
  photo_evidence_incomplete: 'ownedProduct.listing.code.photoIncomplete',
  photo_semantic_review_required: 'ownedProduct.listing.code.photoReview',
  low_confidence_price_band:
    'ownedProduct.listing.code.lowConfidenceBand',
  target_price_outside_selected_band:
    'ownedProduct.listing.code.priceOutsideBand',
  realized_sale_price: 'ownedProduct.listing.code.realizedSalePrice',
  time_to_sale: 'ownedProduct.listing.code.timeToSale',
  provide_product_overview: 'ownedProduct.listing.code.provideOverview',
  provide_multiple_product_angles:
    'ownedProduct.listing.code.provideAngles',
  provide_high_resolution_product_photos:
    'ownedProduct.listing.code.provideHighResolution',
  provide_serial_label_photo: 'ownedProduct.listing.code.provideSerial',
  provide_defect_photos: 'ownedProduct.listing.code.provideDefects',
  provide_accessory_group_photo:
    'ownedProduct.listing.code.provideAccessories',
  confirm_accessories_visible:
    'ownedProduct.listing.code.confirmAccessories',
  confirm_each_defect_visible:
    'ownedProduct.listing.code.confirmDefects',
  keep_proof_of_purchase_private:
    'ownedProduct.listing.code.keepProofPrivate',
  review_target_price_override:
    'ownedProduct.listing.code.reviewPriceOverride',
  review_low_confidence_price_band:
    'ownedProduct.listing.code.reviewLowConfidence',
  watchlist: 'buyerDecision.state.watchlist',
  interested: 'buyerDecision.state.interested',
  contacted: 'buyerDecision.state.contacted',
  negotiating: 'buyerDecision.state.negotiating',
  purchased: 'buyerDecision.state.purchased',
};

@Injectable({ providedIn: 'root' })
export class AnalysisCodeLabelService {
  private readonly i18n = inject(I18nService);

  label(value: string): string {
    const key = ANALYSIS_CODE_KEYS[value];

    return key === undefined ? this.humanize(value) : this.i18n.translate(key);
  }

  verificationAction(value: string): string {
    const keys: Readonly<Record<string, TranslationKey>> = {
      'Verify the listing, seller, product identity, and condition before payment.':
        'verification.listingSellerProduct',
      'Record a positive asking price in the estimate currency or provide verified conversion evidence.':
        'verification.priceConversion',
      'Collect additional verified comparables before relying on the market band.':
        'verification.moreComparables',
      'Verify shipping, customs, tax, returns, and regional compatibility before purchase.':
        'verification.crossBorder',
      'Verify seller identity, history, ownership, and contact details.':
        'verification.seller',
      'Confirm the physical location of the product and seller.':
        'verification.location',
      'Obtain current, original product and serial-number images.':
        'verification.images',
      'Inspect and document condition, defects, included parts, and functionality.':
        'verification.condition',
      'Verify ownership proof, serial number, locks, and blacklist status.':
        'verification.ownership',
      'Use a traceable payment method with buyer protection.':
        'verification.payment',
      'Confirm insured shipping, handover evidence, and return terms.':
        'verification.shipping',
    };
    const key = keys[value];

    return key === undefined ? value : this.i18n.translate(key);
  }

  private humanize(value: string): string {
    return value
      .replaceAll('_', ' ')
      .split(' ')
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ');
  }
}
