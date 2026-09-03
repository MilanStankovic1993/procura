<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Subscriptions\FeatureCode;
use App\Enums\Validation\ApplicationValidationCode;
use App\Exceptions\InvalidAnalysisTransition;
use App\Models\Analysis;
use App\Models\AnalysisDispatch;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\SubscriptionUsageService;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;

class SubmitAnalysis
{
    public function __construct(
        private readonly AnalysisAuthorizer $authorizer,
        private readonly SubscriptionUsageService $usage,
        private readonly DispatchAnalysis $dispatcher,
    ) {}

    public function submit(
        Organization $organization,
        User $actor,
        string $analysisId,
    ): Analysis {
        if (! (bool) config('analyses.submission_enabled')) {
            ApplicationValidation::fail(
                'analysis',
                ApplicationValidationCode::AnalysisSubmissionDisabled,
            );
        }

        [$analysis, $dispatch] = DB::transaction(function () use (
            $organization,
            $actor,
            $analysisId,
        ): array {
            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageAnalyses,
                lockForUpdate: true,
            );
            $analysis = Analysis::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($analysisId);

            if ($analysis->status !== AnalysisStatus::Draft) {
                if (in_array($analysis->status, [
                    AnalysisStatus::Queued,
                    AnalysisStatus::Processing,
                    AnalysisStatus::NeedsInput,
                    AnalysisStatus::Completed,
                ], true)) {
                    $dispatch = AnalysisDispatch::query()
                        ->where('analysis_id', $analysis->getKey())
                        ->where('run_number', 1)
                        ->firstOrFail();

                    return [$analysis, $dispatch];
                }

                throw new InvalidAnalysisTransition($analysis->status);
            }

            $this->usage->consume(
                $organization,
                FeatureCode::MonthlyAnalyses,
                1,
                "analysis:{$analysis->getKey()}:submission:v1",
            );

            $analysis->update([
                'status' => AnalysisStatus::Queued,
                'submitted_at' => now(),
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            $dispatch = AnalysisDispatch::query()->firstOrCreate(
                [
                    'analysis_id' => $analysis->getKey(),
                    'run_number' => 1,
                ],
                [
                    'organization_id' => $organization->getKey(),
                    'pipeline_version' => $analysis->pipeline_version,
                    'dispatch_key' => "analysis:{$analysis->getKey()}:run:1",
                    'status' => AnalysisDispatchStatus::Pending,
                    'queue_name' => config('analyses.queue'),
                    'max_processing_attempts' => config('analyses.max_processing_attempts'),
                    'available_at' => now(),
                ],
            );

            return [$analysis->fresh(), $dispatch];
        }, attempts: 3);

        $this->dispatcher->dispatch($dispatch);

        return $analysis->fresh();
    }
}
