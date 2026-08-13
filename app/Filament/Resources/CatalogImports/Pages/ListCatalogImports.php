<?php

namespace App\Filament\Resources\CatalogImports\Pages;

use App\Actions\CatalogImports\CreateCatalogImport;
use App\Filament\Resources\CatalogImports\CatalogImportResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;

final class ListCatalogImports extends ListRecords
{
    protected static string $resource = CatalogImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCatalog')
                ->label(__('admin.actions.catalog_import.label'))
                ->modalHeading(__('admin.actions.catalog_import.heading'))
                ->modalDescription(__('admin.actions.catalog_import.description'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('admin.actions.catalog_import.file'))
                        ->helperText(__('admin.actions.catalog_import.file_help', [
                            'max' => (int) config('catalog.max_rows'),
                        ]))
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize((int) config('catalog.max_file_size_kb'))
                        ->storeFiles(false)
                        ->required(),
                    TextInput::make('source_name')
                        ->label(__('admin.actions.catalog_import.source_name'))
                        ->minLength(2)
                        ->maxLength(160)
                        ->required(),
                    TextInput::make('source_url')
                        ->label(__('admin.actions.catalog_import.source_url'))
                        ->url()
                        ->maxLength(500),
                    TextInput::make('license_name')
                        ->label(__('admin.actions.catalog_import.license_name'))
                        ->minLength(2)
                        ->maxLength(160)
                        ->required(),
                    TextInput::make('dataset_version')
                        ->label(__('admin.actions.catalog_import.dataset_version'))
                        ->helperText(__('admin.actions.catalog_import.dataset_version_help'))
                        ->maxLength(100)
                        ->required(),
                    Select::make('delimiter')
                        ->label(__('admin.actions.catalog_import.delimiter'))
                        ->options([
                            'comma' => __('admin.actions.catalog_import.delimiter_comma'),
                            'semicolon' => __('admin.actions.catalog_import.delimiter_semicolon'),
                            'tab' => __('admin.actions.catalog_import.delimiter_tab'),
                        ])
                        ->default('comma')
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('admin.actions.catalog_import.notes'))
                        ->maxLength(2000),
                    Checkbox::make('rights_confirmed')
                        ->label(__('admin.actions.catalog_import.rights_confirmed'))
                        ->accepted()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $file = $data['file'] ?? null;

                    if (! $actor instanceof User || ! $file instanceof UploadedFile) {
                        return;
                    }

                    $import = app(CreateCatalogImport::class)->create(
                        actor: $actor,
                        file: $file,
                        sourceName: $data['source_name'],
                        sourceUrl: $data['source_url'] ?? null,
                        licenseName: $data['license_name'],
                        datasetVersion: $data['dataset_version'],
                        delimiter: $data['delimiter'],
                        rightsConfirmed: (bool) $data['rights_confirmed'],
                        notes: $data['notes'] ?? null,
                        ipAddress: request()->ip(),
                        userAgent: request()->userAgent(),
                    );

                    Notification::make()
                        ->title(__('admin.actions.catalog_import.queued'))
                        ->body(__('admin.actions.catalog_import.queued_body', [
                            'source' => $import->source_name,
                            'version' => $import->dataset_version,
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
