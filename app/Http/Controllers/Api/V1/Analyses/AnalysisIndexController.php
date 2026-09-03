<?php

namespace App\Http\Controllers\Api\V1\Analyses;

use App\Analysis\Queries\AnalysisIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Analyses\IndexAnalysisRequest;
use App\Http\Resources\V1\AnalysisSummaryResource;
use App\Models\Analysis;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AnalysisIndexController extends Controller
{
    public function __invoke(
        IndexAnalysisRequest $request,
        OrganizationContext $context,
        AnalysisIndexQuery $analyses,
    ): AnonymousResourceCollection {
        $organization = $context->organization();
        Gate::authorize('viewAny', [Analysis::class, $organization]);
        $validated = $request->validated();

        return AnalysisSummaryResource::collection(
            $analyses
                ->build($organization, $validated)
                ->cursorPaginate((int) ($validated['per_page'] ?? 20)),
        );
    }
}
