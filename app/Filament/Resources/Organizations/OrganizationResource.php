<?php

namespace App\Filament\Resources\Organizations;

use App\Actions\Administration\AssignOrganizationPlan;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrganizationResource extends ReadOnlyResource
{
    protected static ?string $model = Organization::class;

    protected static ?string $translationKey = 'organizations';

    protected static ?string $navigationGroupKey = 'identity';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.columns.name'))->searchable()->sortable(),
                TextColumn::make('type')
                    ->label(__('admin.columns.type'))
                    ->formatStateUsing(fn ($state): string => AdminLabel::value('organization_type', $state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('memberships_count')
                    ->counts('memberships')
                    ->label(__('admin.columns.members')),
                TextColumn::make('planAssignment.plan.name')
                    ->label(__('admin.columns.assigned_plan'))
                    ->placeholder(__('admin.placeholders.free_default')),
                TextColumn::make('created_at')->label(__('admin.columns.created_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('assignPlan')
                    ->label(__('admin.actions.assign_plan.label'))
                    ->modalHeading(__('admin.actions.assign_plan.heading'))
                    ->modalDescription(__('admin.actions.assign_plan.description'))
                    ->schema([
                        Select::make('plan_id')
                            ->label(__('admin.actions.assign_plan.plan'))
                            ->options(fn (): array => Plan::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderByDesc('version')
                                ->get()
                                ->mapWithKeys(fn (Plan $plan): array => [
                                    $plan->getKey() => "{$plan->name} v{$plan->version}",
                                ])
                                ->all())
                            ->required()
                            ->searchable(),
                        Textarea::make('reason')
                            ->label(__('admin.actions.assign_plan.reason'))
                            ->required()
                            ->minLength(10)
                            ->maxLength(1000),
                    ])
                    ->action(function (array $data, Organization $record): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        app(AssignOrganizationPlan::class)->assign(
                            organization: $record,
                            plan: Plan::query()->findOrFail($data['plan_id']),
                            actor: $actor,
                            reason: $data['reason'],
                            ipAddress: request()->ip(),
                            userAgent: request()->userAgent(),
                        );

                        Notification::make()
                            ->title(__('admin.actions.assign_plan.success'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListOrganizations::route('/')];
    }
}
