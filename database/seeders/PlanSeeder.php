<?php

namespace Database\Seeders;

use App\Enums\Subscriptions\FeatureCode;
use App\Enums\Subscriptions\PlanCode;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->plans() as $definition) {
                $plan = Plan::query()->updateOrCreate(
                    ['code' => $definition['code'], 'version' => 1],
                    [
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'is_active' => true,
                        'sort_order' => $definition['sort_order'],
                    ],
                );

                foreach (FeatureCode::cases() as $feature) {
                    $limit = $definition['features'][$feature->value] ?? false;
                    PlanFeature::query()->updateOrCreate(
                        ['plan_id' => $plan->getKey(), 'feature_code' => $feature->value],
                        [
                            'is_enabled' => $limit !== false,
                            'limit' => is_int($limit) ? $limit : null,
                        ],
                    );
                }
            }
        });
    }

    /** @return list<array{code: PlanCode, name: string, description: string, sort_order: int, features: array<string, int|bool>}> */
    private function plans(): array
    {
        return [
            [
                'code' => PlanCode::Free, 'name' => 'Free', 'sort_order' => 10,
                'description' => 'Essential tools for evaluating occasional opportunities.',
                'features' => [
                    FeatureCode::MonthlyAnalyses->value => 5,
                    FeatureCode::SavedSearches->value => 1,
                    FeatureCode::TeamMembers->value => 1,
                    FeatureCode::EmailNotifications->value => true,
                ],
            ],
            [
                'code' => PlanCode::Starter, 'name' => 'Starter', 'sort_order' => 20,
                'description' => 'More analysis and monitoring for active buyers and sellers.',
                'features' => [
                    FeatureCode::MonthlyAnalyses->value => 50,
                    FeatureCode::SavedSearches->value => 10,
                    FeatureCode::TeamMembers->value => 1,
                    FeatureCode::EmailNotifications->value => true,
                    FeatureCode::TelegramNotifications->value => true,
                    FeatureCode::BasicPriceHistory->value => true,
                ],
            ],
            [
                'code' => PlanCode::Pro, 'name' => 'Pro', 'sort_order' => 30,
                'description' => 'Advanced intelligence and higher limits for professionals.',
                'features' => [
                    FeatureCode::MonthlyAnalyses->value => 250,
                    FeatureCode::SavedSearches->value => 50,
                    FeatureCode::TeamMembers->value => 1,
                    FeatureCode::EmailNotifications->value => true,
                    FeatureCode::TelegramNotifications->value => true,
                    FeatureCode::BasicPriceHistory->value => true,
                    FeatureCode::FullRiskReport->value => true,
                    FeatureCode::PriorityAnalysis->value => true,
                    FeatureCode::ProfitTracking->value => true,
                ],
            ],
            [
                'code' => PlanCode::Business, 'name' => 'Business', 'sort_order' => 40,
                'description' => 'Team controls, reporting, exports, and configurable limits.',
                'features' => array_fill_keys(array_map(fn (FeatureCode $feature) => $feature->value, FeatureCode::cases()), true),
            ],
        ];
    }
}
