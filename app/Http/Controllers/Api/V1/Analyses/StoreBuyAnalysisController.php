<?php

namespace App\Http\Controllers\Api\V1\Analyses;

use App\Actions\Analyses\CreateBuyAnalysisDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Analyses\StoreBuyAnalysisRequest;
use App\Http\Resources\V1\AnalysisResource;
use App\Models\Analysis;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreBuyAnalysisController extends Controller
{
    public function __invoke(
        StoreBuyAnalysisRequest $request,
        OrganizationContext $context,
        CreateBuyAnalysisDraft $drafts,
    ): JsonResponse {
        $organization = $context->organization();
        Gate::authorize('create', [Analysis::class, $organization]);
        $validated = $request->validated();
        $analysis = $drafts->create(
            $organization,
            $request->user(),
            $validated['listing_id'],
            strtoupper($validated['target_country_code']),
        );

        return (new AnalysisResource($analysis->load([
            'listing',
            'currentDispatch',
            'aiAnalyses',
            'currentProductMatch',
        ])))
            ->response()
            ->setStatusCode(201);
    }
}
