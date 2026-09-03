<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\OutcomeTrackingProjection;
use App\Actions\OwnedProducts\RecordEstimateAccuracyAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreEstimateAccuracyAttributionRequest;
use App\Http\Resources\V1\EstimateAccuracyReportResource;
use App\Http\Resources\V1\OutcomeEstimateAttributionResource;
use App\Http\Resources\V1\OutcomeTrackingProjectionResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreEstimateAccuracyAttributionController extends Controller
{
    public function __invoke(
        StoreEstimateAccuracyAttributionRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        RecordEstimateAccuracyAttribution $attributions,
        OutcomeTrackingProjection $projection,
    ): JsonResponse {
        $organization = $context->organization();
        $product = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $product);
        $result = $attributions->record(
            $organization,
            $request->user(),
            $product->getKey(),
            $request->validated(),
        );

        return response()->json([
            'data' => (new OutcomeTrackingProjectionResource(
                $projection->for($product),
            ))->resolve($request),
            'meta' => [
                'created' => $result['created'],
                'attribution' => (
                    new OutcomeEstimateAttributionResource(
                        $result['attribution'],
                    )
                )->resolve($request),
                'report' => (new EstimateAccuracyReportResource(
                    $result['report'],
                ))->resolve($request),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
