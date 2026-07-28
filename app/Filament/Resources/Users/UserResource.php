<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends ReadOnlyResource
{
    protected static ?string $model = User::class;

    protected static ?string $translationKey = 'users';

    protected static ?string $navigationGroupKey = 'identity';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label(__('admin.columns.id'))->sortable(),
                TextColumn::make('name')->label(__('admin.columns.name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('admin.columns.email'))->searchable()->sortable(),
                IconColumn::make('email_verified_at')->label(__('admin.columns.verified'))->boolean(),
                IconColumn::make('is_super_admin')->label(__('admin.columns.super_admin'))->boolean(),
                TextColumn::make('memberships_count')
                    ->counts('memberships')
                    ->label(__('admin.columns.memberships')),
                TextColumn::make('created_at')->label(__('admin.columns.created_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
