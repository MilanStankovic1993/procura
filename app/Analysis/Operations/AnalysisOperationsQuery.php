<?php

namespace App\Analysis\Operations;

use App\Enums\Analyses\AnalysisDispatchStatus;
use App\Enums\Analyses\AnalysisStatus;
use App\Models\Analysis;
use Illuminate\Database\Eloquent\Builder;

final class AnalysisOperationsQuery
{
    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function apply(Builder $query): Builder
    {
        $processingStaleBefore = now()->subSeconds(
            (int) config('analyses.processing_timeout_seconds'),
        );
        $dispatchStaleBefore = now()->subSeconds(
            (int) config('analyses.dispatch_claim_timeout_seconds'),
        );

        return $query->where(
            static function (Builder $query) use (
                $processingStaleBefore,
                $dispatchStaleBefore,
            ): void {
                $query
                    ->where('status', AnalysisStatus::Failed)
                    ->orWhere(
                        static function (Builder $query) use (
                            $processingStaleBefore,
                        ): void {
                            $query
                                ->where(
                                    'status',
                                    AnalysisStatus::Processing,
                                )
                                ->where(
                                    'processing_started_at',
                                    '<=',
                                    $processingStaleBefore,
                                );
                        },
                    )
                    ->orWhereHas(
                        'currentDispatch',
                        static function (Builder $query) use (
                            $dispatchStaleBefore,
                        ): void {
                            $query
                                ->where(
                                    'status',
                                    AnalysisDispatchStatus::Failed,
                                )
                                ->orWhere(
                                    static function (Builder $query) use (
                                        $dispatchStaleBefore,
                                    ): void {
                                        $query
                                            ->where(
                                                'status',
                                                AnalysisDispatchStatus::Dispatching,
                                            )
                                            ->where(
                                                'last_dispatch_attempt_at',
                                                '<=',
                                                $dispatchStaleBefore,
                                            );
                                    },
                                );
                        },
                    );
            },
        );
    }

    public function count(): int
    {
        return $this->apply(Analysis::query())->count();
    }
}
