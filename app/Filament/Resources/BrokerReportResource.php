<?php

namespace App\Filament\Resources;

use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Filament\Support\AdminLabel;
use App\Models\BrokerReport;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class BrokerReportResource extends ReadOnlyResource
{
    protected static ?string $model = BrokerReport::class;

    protected static ?string $translationKey = 'broker_reports';

    protected static ?string $navigationGroupKey = 'operations';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return
            $user instanceof User
            && $user->is_super_admin
            && $user->hasVerifiedEmail();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn ($query) => $query->with([
                    'organization:id,name',
                    'brokerRequest:id,title',
                    'generatedBy:id,email',
                ]),
            )
            ->columns([
                TextColumn::make('generated_at')
                    ->label(__('admin.columns.generated_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable(),
                TextColumn::make('brokerRequest.title')
                    ->label(__('admin.columns.broker_request'))
                    ->searchable()
                    ->limit(42)
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'broker_report_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('locale')
                    ->label(__('admin.columns.locale'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('sequence')
                    ->label(__('admin.columns.sequence'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('page_count')
                    ->label(__('admin.columns.page_count'))
                    ->numeric(),
                TextColumn::make('artifact_size_bytes')
                    ->label(__('admin.columns.artifact_size'))
                    ->formatStateUsing(
                        fn (int $state): string => number_format(
                            $state / 1024,
                            1,
                        ).' KiB',
                    ),
                TextColumn::make('generatedBy.email')
                    ->label(__('admin.columns.generated_by'))
                    ->placeholder(__('admin.placeholders.system')),
                TextColumn::make('artifact_expires_at')
                    ->label(__('admin.columns.artifact_expires_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('purged_at')
                    ->label(__('admin.columns.purged_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options(collect(BrokerReportStatus::cases())
                        ->mapWithKeys(
                            fn (BrokerReportStatus $status): array => [
                                $status->value => AdminLabel::value(
                                    'broker_report_status',
                                    $status,
                                ),
                            ],
                        )
                        ->all()),
            ])
            ->defaultSort('generated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => BrokerReportListPage::route('/')];
    }
}
