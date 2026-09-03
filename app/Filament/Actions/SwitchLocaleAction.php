<?php

namespace App\Filament\Actions;

use App\Actions\Users\UpdatePreferredLocale;
use App\Enums\Localization\SupportedLocale;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

final class SwitchLocaleAction
{
    public static function make(): Action
    {
        return Action::make('switchLocale')
            ->label(fn (): string => __('admin.actions.language.label'))
            ->icon(Heroicon::Language)
            ->schema([
                Select::make('preferred_locale')
                    ->label(fn (): string => __('admin.actions.language.field'))
                    ->options(SupportedLocale::nativeOptions())
                    ->rules([Rule::enum(SupportedLocale::class)])
                    ->required()
                    ->selectablePlaceholder(false)
                    ->native(false),
            ])
            ->fillForm(function (): array {
                $user = Filament::auth()->user();

                return [
                    'preferred_locale' => $user instanceof User
                        ? $user->preferred_locale->value
                        : SupportedLocale::English->value,
                ];
            })
            ->modalHeading(fn (): string => __('admin.actions.language.heading'))
            ->modalDescription(fn (): string => __('admin.actions.language.description'))
            ->modalSubmitActionLabel(fn (): string => __('admin.actions.language.save'))
            ->action(function (array $data): void {
                $user = Filament::auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $locale = SupportedLocale::from($data['preferred_locale']);

                app(UpdatePreferredLocale::class)->update(
                    $user,
                    $locale,
                );

                App::setLocale($locale->laravelLocale());
            })
            ->successNotificationTitle(fn (): string => __('admin.actions.language.saved'))
            ->successRedirectUrl(fn (): string => url()->previous())
            ->sort(10);
    }
}
