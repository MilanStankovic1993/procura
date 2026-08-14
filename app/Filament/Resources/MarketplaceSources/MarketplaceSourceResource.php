<?php

namespace App\Filament\Resources\MarketplaceSources;

use App\Enums\Listings\MarketplaceConnectorType;
use App\Filament\Resources\MarketplaceSources\Pages\ListMarketplaceSources;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\MarketplaceSource;
use App\Models\User;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class MarketplaceSourceResource extends ReadOnlyResource
{
    protected static ?string $model = MarketplaceSource::class;

    protected static ?string $translationKey = 'marketplace_sources';

    protected static ?string $navigationGroupKey = 'market_reference';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->is_super_admin
            && $user->hasVerifiedEmail();
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->withCount([
                    'listings',
                    'imports',
                ]),
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label(__('admin.columns.key'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('connector_type')
                    ->label(__('admin.columns.connector_type'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'marketplace_connector_type',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('compliance_status')
                    ->label(__('admin.columns.compliance_status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'marketplace_compliance_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('geographic_coverage')
                    ->label(__('admin.columns.geographic_coverage'))
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->limit(55)
                    ->wrap(),
                TextColumn::make('listings_count')
                    ->label(__('admin.columns.listings'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('imports_count')
                    ->label(__('admin.columns.imports'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reliability_score')
                    ->label(__('admin.columns.reliability'))
                    ->formatStateUsing(fn (int $state): string => "{$state} / 100")
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('freshness_score')
                    ->label(__('admin.columns.freshness'))
                    ->formatStateUsing(fn (int $state): string => "{$state} / 100")
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                TextColumn::make('completeness_score')
                    ->label(__('admin.columns.completeness'))
                    ->formatStateUsing(fn (int $state): string => "{$state} / 100")
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable(),
                IconColumn::make('cross_border_supported')
                    ->label(__('admin.columns.cross_border'))
                    ->boolean(),
                IconColumn::make('active')
                    ->label(__('admin.columns.active'))
                    ->boolean(),
                TextColumn::make('capabilities')
                    ->label(__('admin.columns.capabilities'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('asking_price_only')
                    ->label(__('admin.columns.asking_price_only'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('transaction_price_supported')
                    ->label(__('admin.columns.transaction_price_supported'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('terms_reviewed_at')
                    ->label(__('admin.columns.terms_reviewed_at'))
                    ->date()
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('connector_type')
                    ->label(__('admin.columns.connector_type'))
                    ->options(self::connectorTypeOptions()),
                SelectFilter::make('compliance_status')
                    ->label(__('admin.columns.compliance_status'))
                    ->options(fn (): array => self::complianceStatusOptions()),
                TernaryFilter::make('cross_border_supported')
                    ->label(__('admin.columns.cross_border')),
                TernaryFilter::make('active')
                    ->label(__('admin.columns.active')),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketplaceSources::route('/')];
    }

    /** @return array<string, string> */
    private static function connectorTypeOptions(): array
    {
        return collect(MarketplaceConnectorType::cases())
            ->mapWithKeys(fn (MarketplaceConnectorType $type): array => [
                $type->value => AdminLabel::value(
                    'marketplace_connector_type',
                    $type,
                ),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function complianceStatusOptions(): array
    {
        return MarketplaceSource::query()
            ->whereNotNull('compliance_status')
            ->distinct()
            ->orderBy('compliance_status')
            ->pluck('compliance_status')
            ->mapWithKeys(fn (string $status): array => [
                $status => AdminLabel::value(
                    'marketplace_compliance_status',
                    $status,
                ),
            ])
            ->all();
    }
}
