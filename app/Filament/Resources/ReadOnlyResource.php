<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

abstract class ReadOnlyResource extends Resource
{
    protected static ?string $translationKey = null;

    protected static ?string $navigationGroupKey = null;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __(static::translationPath('singular'));
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::translationPath('plural'));
    }

    public static function getNavigationLabel(): string
    {
        return __(static::translationPath('navigation'));
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return static::$navigationGroupKey === null
            ? null
            : __('admin.navigation.groups.'.static::$navigationGroupKey);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function translationPath(string $label): string
    {
        if (static::$translationKey === null) {
            throw new \LogicException('A localized Filament resource must define its translation key.');
        }

        return 'admin.resources.'.static::$translationKey.".{$label}";
    }
}
