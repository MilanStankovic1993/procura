<?php

namespace App\Filament\Resources\Memberships;

use App\Filament\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\OrganizationMembership;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembershipResource extends ReadOnlyResource
{
    protected static ?string $model = OrganizationMembership::class;

    protected static ?string $translationKey = 'memberships';

    protected static ?string $navigationGroupKey = 'identity';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')->label(__('admin.columns.organization'))->searchable(),
                TextColumn::make('user.name')->label(__('admin.columns.user'))->searchable(),
                TextColumn::make('user.email')->label(__('admin.columns.email'))->searchable(),
                TextColumn::make('role')
                    ->label(__('admin.columns.role'))
                    ->formatStateUsing(fn ($state): string => AdminLabel::value('role', $state))
                    ->badge(),
                TextColumn::make('joined_at')->label(__('admin.columns.joined_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('joined_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListMemberships::route('/')];
    }
}
