<?php

namespace App\Filament\Resources\CatalogImportRows;

use App\Filament\Resources\CatalogImportRows\Pages\ListCatalogImportRows;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\CatalogImportRow;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class CatalogImportRowResource extends ReadOnlyResource
{
    protected static ?string $model = CatalogImportRow::class;

    protected static ?string $translationKey = 'catalog_import_rows';

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
                TextColumn::make('catalogImport.source_name')
                    ->label(__('admin.columns.catalog_source'))
                    ->searchable(),
                TextColumn::make('catalogImport.dataset_version')
                    ->label(__('admin.columns.dataset_version'))
                    ->searchable(),
                TextColumn::make('row_number')
                    ->label(__('admin.columns.row_number'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value('catalog_import_row_status', $state),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('productModel.canonical_key')
                    ->label(__('admin.columns.canonical_product'))
                    ->placeholder(__('admin.placeholders.not_selected'))
                    ->searchable(),
                TextColumn::make('validation_errors')
                    ->label(__('admin.columns.validation_errors'))
                    ->formatStateUsing(fn ($state): string => self::errorSummary($state))
                    ->placeholder(__('admin.placeholders.none'))
                    ->wrap(),
                TextColumn::make('processed_at')
                    ->label(__('admin.columns.processed_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.status'))
                    ->options([
                        'imported' => AdminLabel::value('catalog_import_row_status', 'imported'),
                        'unchanged' => AdminLabel::value('catalog_import_row_status', 'unchanged'),
                        'rejected' => AdminLabel::value('catalog_import_row_status', 'rejected'),
                    ]),
            ])
            ->defaultSort('processed_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCatalogImportRows::route('/')];
    }

    private static function errorSummary(mixed $state): string
    {
        if (! is_array($state)) {
            return '';
        }

        return collect($state)
            ->flatMap(static function (mixed $errors, string $field): array {
                if (! is_array($errors)) {
                    return ["{$field}: {$errors}"];
                }

                return collect($errors)
                    ->map(static fn (mixed $error): string => "{$field}: {$error}")
                    ->all();
            })
            ->implode('; ');
    }
}
