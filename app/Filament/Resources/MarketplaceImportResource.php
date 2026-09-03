<?php

namespace App\Filament\Resources;

use App\Filament\Support\AdminLabel;
use App\Models\MarketplaceImport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class MarketplaceImportResource extends ReadOnlyResource
{
    protected static ?string $model = MarketplaceImport::class;

    protected static ?string $translationKey = 'marketplace_imports';

    protected static ?string $navigationGroupKey = 'operations';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable(),
                TextColumn::make('marketplaceSource.name')
                    ->label(__('admin.columns.connector'))
                    ->searchable(),
                TextColumn::make('original_file_name')
                    ->label(__('admin.columns.file'))
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('admin.columns.status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'marketplace_import_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_rows')
                    ->label(__('admin.columns.total_rows'))
                    ->numeric(),
                TextColumn::make('imported_rows')
                    ->label(__('admin.columns.imported_rows'))
                    ->numeric(),
                TextColumn::make('rejected_rows')
                    ->label(__('admin.columns.rejected_rows'))
                    ->numeric(),
                TextColumn::make('duplicate_rows')
                    ->label(__('admin.columns.duplicate_rows'))
                    ->numeric(),
                TextColumn::make('processing_attempts')
                    ->label(__('admin.columns.processing_attempts'))
                    ->numeric(),
                TextColumn::make('last_error_code')
                    ->label(__('admin.columns.error'))
                    ->placeholder('—')
                    ->limit(60),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => MarketplaceImportListPage::route('/')];
    }
}
