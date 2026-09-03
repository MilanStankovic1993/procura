<?php

namespace App\Http\Controllers\Api\V1\Analyses;

use App\Actions\Analyses\SubmitAnalysis;
use App\Exceptions\InvalidAnalysisTransition;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AnalysisResource;
use App\Models\Analysis;
use App\Subscriptions\UsageLimitExceeded;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SubmitAnalysisController extends Controller
{
    public function __invoke(
        Request $request,
        string $analysis,
        OrganizationContext $context,
        SubmitAnalysis $submitter,
    ): JsonResponse {
        $record = Analysis::query()
            ->forOrganization($context->organization())
            ->findOrFail($analysis);
        Gate::authorize('submit', $record);

        try {
            $record = $submitter->submit(
                $context->organization(),
                $request->user(),
                $record->getKey(),
            );
        } catch (UsageLimitExceeded $exception) {
            return response()->json([
                'message' => 'The monthly analysis allowance has been reached.',
                'code' => 'analysis_quota_exceeded',
                'meta' => [
                    'feature' => $exception->feature->value,
                    'limit' => $exception->limit,
                    'used' => $exception->used,
                    'requested' => $exception->requested,
                ],
            ], 422);
        } catch (InvalidAnalysisTransition $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'invalid_analysis_transition',
                'meta' => ['status' => $exception->status->value],
            ], 409);
        }

        return (new AnalysisResource(
            $record->load([
                'listing',
                'currentDispatch',
                'aiAnalyses',
                'currentProductMatch',
            ]),
        ))->response()->setStatusCode(202);
    }
}
