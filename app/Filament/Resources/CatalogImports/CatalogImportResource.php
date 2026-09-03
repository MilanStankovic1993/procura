<?php

namespace App\Filament\Resources\CatalogImports;

use App\Filament\Resources\CatalogImports\Pages\ListCatalogImports;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\CatalogImport;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class CatalogImportResource extends ReadOnlyResource
{
    protected static ?string $model = CatalogImport::class;

    protected static ?string $translationKey = 'catalog_imports';

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
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('source_name')
                    ->label(__('admin.columns.catalog_source'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('dataset_version')
                    ->label(__('admin.columns.dataset_version'))
                    ->searchable(),
                TextColumn::make('license_name')
                    ->label(__('admin.columns.license'))
                    ->limit(32),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value('catalog_import_status', $state),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_rows')
                    ->label(__('admin.columns.total_rows'))
                    ->numeric(),
                TextColumn::make('imported_rows')
                    ->label(__('admin.columns.imported_rows'))
                    ->numeric(),
                TextColumn::make('unchanged_rows')
                    ->label(__('admin.columns.unchanged_rows'))
                    ->numeric(),
                TextColumn::make('rejected_rows')
                    ->label(__('admin.columns.rejected_rows'))
                    ->numeric(),
                TextColumn::make('createdBy.email')
                    ->label(__('admin.columns.created_by'))
                    ->placeholder(__('admin.placeholders.system')),
                TextColumn::make('last_error_code')
                    ->label(__('admin.columns.error'))
                    ->placeholder(__('admin.placeholders.none'))
                    ->limit(48),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCatalogImports::route('/')];
    }
}
