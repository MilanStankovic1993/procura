<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\AssessOwnedProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\AssessOwnedProductRequest;
use App\Http\Resources\V1\OwnedProductAssessmentResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class AssessOwnedProductController extends Controller
{
    public function __invoke(
        AssessOwnedProductRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        AssessOwnedProduct $assessments,
    ): JsonResource {
        $organization = $context->organization();
        $record = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $record);

        $assessment = $assessments->assess(
            organization: $organization,
            actor: $request->user(),
            ownedProductId: $record->getKey(),
            expectedSnapshotId: $request->validated('owned_product_snapshot_id'),
        );
        $assessment->load([
            'snapshot',
            'assessedBy',
            'productModel.category',
            'productVariant',
        ]);

        return new OwnedProductAssessmentResource($assessment);
    }
}
