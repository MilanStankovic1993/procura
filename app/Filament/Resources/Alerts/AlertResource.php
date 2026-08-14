<?php

namespace App\Filament\Resources\Alerts;

use App\Enums\Monitoring\AlertType;
use App\Filament\Resources\Alerts\Pages\ListAlerts;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\Alert;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AlertResource extends ReadOnlyResource
{
    protected static ?string $model = Alert::class;

    protected static ?string $translationKey = 'alerts';

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
                        'recipient:id,email',
                    ])
                    ->withCount('logs'),
            )
            ->columns([
                TextColumn::make('triggered_at')
                    ->label(__('admin.columns.triggered_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('recipient.email')
                    ->label(__('admin.columns.recipient'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('alert_type')
                    ->label(__('admin.columns.alert_type'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'alert_type',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('logs_count')
                    ->label(__('admin.columns.delivery_events'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('saved_search_id')
                    ->label(__('admin.columns.saved_search'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (Alert $record): string => $record->saved_search_id)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('listing_id')
                    ->label(__('admin.columns.listing'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (Alert $record): string => $record->listing_id)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label(__('admin.columns.id'))
                    ->copyable()
                    ->limit(18)
                    ->tooltip(fn (Alert $record): string => $record->getKey())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('alert_type')
                    ->label(__('admin.columns.alert_type'))
                    ->options(self::typeOptions()),
            ])
            ->defaultSort('triggered_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAlerts::route('/')];
    }

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        return collect(AlertType::cases())
            ->mapWithKeys(fn (AlertType $type): array => [
                $type->value => AdminLabel::value('alert_type', $type),
            ])
            ->all();
    }
}
