<?php

namespace App\Filament\Resources\SavedSearches;

use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Resources\SavedSearches\Pages\ListSavedSearches;
use App\Filament\Support\AdminLabel;
use App\Models\SavedSearch;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SavedSearchResource extends ReadOnlyResource
{
    protected static ?string $model = SavedSearch::class;

    protected static ?string $translationKey = 'saved_searches';

    protected static ?string $navigationGroupKey = 'operations';

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
                fn (Builder $query): Builder => $query
                    ->with([
                        'organization:id,name',
                        'owner:id,email',
                    ])
                    ->withCount(['versions', 'matches', 'alerts']),
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.email')
                    ->label(__('admin.columns.owner'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lifecycle')
                    ->label(__('admin.columns.lifecycle'))
                    ->state(fn (SavedSearch $record): string => self::lifecycle($record))
                    ->badge(),
                TextColumn::make('versions_count')
                    ->label(__('admin.columns.versions'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('matches_count')
                    ->label(__('admin.columns.matches'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('alerts_count')
                    ->label(__('admin.columns.alerts'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('archived_at')
                    ->label(__('admin.columns.archived_at'))
                    ->dateTime()
                    ->placeholder(__('admin.placeholders.not_provided'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('version_sequence')
                    ->label(__('admin.columns.current_version'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('current_version_id')
                    ->label(__('admin.columns.current_version'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(
                        fn (SavedSearch $record): ?string => $record->current_version_id,
                    )
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label(__('admin.columns.id'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (SavedSearch $record): string => $record->getKey())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('lifecycle')
                    ->label(__('admin.columns.lifecycle'))
                    ->options(self::lifecycleOptions())
                    ->query(
                        fn (Builder $query, array $data): Builder => match (
                            $data['value'] ?? null
                        ) {
                            'active' => $query
                                ->whereNull('archived_at')
                                ->where('active', true),
                            'paused' => $query
                                ->whereNull('archived_at')
                                ->where('active', false),
                            'archived' => $query->whereNotNull('archived_at'),
                            default => $query,
                        },
                    ),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSavedSearches::route('/')];
    }

    private static function lifecycle(SavedSearch $search): string
    {
        $state = match (true) {
            $search->archived_at !== null => 'archived',
            $search->active => 'active',
            default => 'paused',
        };

        return AdminLabel::value('saved_search_state', $state);
    }

    /** @return array<string, string> */
    private static function lifecycleOptions(): array
    {
        return collect(['active', 'paused', 'archived'])
            ->mapWithKeys(fn (string $state): array => [
                $state => AdminLabel::value('saved_search_state', $state),
            ])
            ->all();
    }
}
