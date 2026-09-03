<?php

namespace App\Filament\Resources\ProductMatchReviews;

use App\Actions\Analyses\ReviewProductMatch;
use App\Enums\Catalog\ProductMatchReviewDecision;
use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Localization\SupportedLocale;
use App\Filament\Resources\ProductMatchReviews\Pages\ListProductMatchReviews;
use App\Filament\Resources\ReadOnlyResource;
use App\Filament\Support\AdminLabel;
use App\Models\ProductMatch;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ProductMatchReviewResource extends ReadOnlyResource
{
    protected static ?string $model = ProductMatch::class;

    protected static ?string $translationKey = 'product_match_reviews';

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
                    ->where('review_status', ProductMatchReviewStatus::Pending)
                    ->whereIn('status', [
                        ProductMatchStatus::ReviewRequired,
                        ProductMatchStatus::Unmatched,
                    ])
                    ->whereNotExists(function ($newerMatch): void {
                        $newerMatch
                            ->selectRaw('1')
                            ->from('product_matches as newer_product_matches')
                            ->whereColumn(
                                'newer_product_matches.analysis_id',
                                'product_matches.analysis_id',
                            )
                            ->whereColumn(
                                'newer_product_matches.run_number',
                                '>',
                                'product_matches.run_number',
                            );
                    })
                    ->with([
                        'organization:id,name',
                        'analysis:id,organization_id,listing_id,target_country_code',
                        'analysis.listing:id,title',
                        'productModel:id,brand_id,name,model_number',
                        'productModel.brand:id,name',
                        'productVariant:id,product_model_id,name,sku',
                    ]),
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.columns.requested_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label(__('admin.columns.organization'))
                    ->searchable(),
                TextColumn::make('analysis.listing.title')
                    ->label(__('admin.columns.listing'))
                    ->searchable()
                    ->limit(44)
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('admin.columns.match_status'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'product_match_status',
                            $state,
                        ),
                    )
                    ->badge()
                    ->sortable(),
                TextColumn::make('confidence_basis_points')
                    ->label(__('admin.columns.confidence'))
                    ->formatStateUsing(
                        fn (int $state): string => number_format(
                            $state / 100,
                            2,
                        ).'%',
                    )
                    ->sortable(),
                TextColumn::make('candidates')
                    ->label(__('admin.columns.candidates'))
                    ->state(fn (ProductMatch $record): string => collect(
                        $record->candidate_snapshot,
                    )
                        ->take(3)
                        ->map(static fn (array $candidate): string => trim(
                            implode(' ', array_filter([
                                $candidate['brand'] ?? null,
                                $candidate['model'] ?? null,
                                $candidate['variant'] ?? null,
                            ])),
                        ))
                        ->filter()
                        ->implode(', '))
                    ->placeholder(__('admin.placeholders.no_candidates'))
                    ->wrap(),
                TextColumn::make('productModel.name')
                    ->label(__('admin.columns.suggested_product'))
                    ->formatStateUsing(
                        fn ($state, ProductMatch $record): string => (
                            self::modelLabel($record->productModel)
                            ?? (string) $state
                        ),
                    )
                    ->placeholder(__('admin.placeholders.not_selected'))
                    ->wrap(),
                TextColumn::make('analysis.target_country_code')
                    ->label(__('admin.columns.target_market'))
                    ->badge(),
                TextColumn::make('method')
                    ->label(__('admin.columns.match_method'))
                    ->formatStateUsing(
                        fn ($state): string => AdminLabel::value(
                            'product_match_method',
                            $state,
                        ),
                    )
                    ->badge(),
                TextColumn::make('reason_codes')
                    ->label(__('admin.columns.match_reasons'))
                    ->formatStateUsing(
                        fn ($state): string => collect($state)
                            ->map(fn ($reason): string => AdminLabel::value(
                                'product_match_reason',
                                (string) $reason,
                            ))
                            ->implode(', '),
                    )
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.columns.match_status'))
                    ->options([
                        ProductMatchStatus::ReviewRequired->value => AdminLabel::value(
                            'product_match_status',
                            ProductMatchStatus::ReviewRequired,
                        ),
                        ProductMatchStatus::Unmatched->value => AdminLabel::value(
                            'product_match_status',
                            ProductMatchStatus::Unmatched,
                        ),
                    ]),
            ])
            ->recordActions([
                self::confirmAction(),
                self::rejectAction(),
            ])
            ->defaultSort('created_at', 'asc');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductMatchReviews::route('/')];
    }

    private static function confirmAction(): Action
    {
        return Action::make('confirmMatch')
            ->label(__('admin.actions.product_match_review.confirm_label'))
            ->modalHeading(
                __('admin.actions.product_match_review.confirm_heading'),
            )
            ->modalDescription(
                __('admin.actions.product_match_review.confirm_description'),
            )
            ->visible(
                fn (ProductMatch $record): bool => app(
                    ReviewProductMatch::class,
                )->isReviewable($record),
            )
            ->schema([
                Hidden::make('expected_current_match_id')
                    ->default(
                        fn (ProductMatch $record): string => $record->getKey(),
                    )
                    ->required(),
                Hidden::make('idempotency_key')
                    ->default(fn (): string => (string) Str::uuid())
                    ->required(),
                Select::make('product_model_id')
                    ->label(__('admin.actions.product_match_review.product_model'))
                    ->default(
                        fn (ProductMatch $record): ?string => (
                            $record->product_model_id
                        ),
                    )
                    ->getSearchResultsUsing(
                        fn (string $search): array => ProductModel::query()
                            ->where('active', true)
                            ->where(function (Builder $query) use ($search): void {
                                $query
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('model_number', 'like', "%{$search}%")
                                    ->orWhereHas(
                                        'brand',
                                        fn (Builder $brand): Builder => $brand
                                            ->where('name', 'like', "%{$search}%"),
                                    );
                            })
                            ->with('brand:id,name')
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(
                                fn (ProductModel $model): array => [
                                    $model->getKey() => self::modelLabel($model),
                                ],
                            )
                            ->all(),
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string => self::modelLabel(
                            ProductModel::query()
                                ->with('brand:id,name')
                                ->find($value),
                        ),
                    )
                    ->searchable()
                    ->required(),
                Select::make('product_variant_id')
                    ->label(__('admin.actions.product_match_review.product_variant'))
                    ->default(
                        fn (ProductMatch $record): ?string => (
                            $record->product_variant_id
                        ),
                    )
                    ->getSearchResultsUsing(
                        fn (string $search): array => ProductVariant::query()
                            ->where('active', true)
                            ->where(function (Builder $query) use ($search): void {
                                $query
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('sku', 'like', "%{$search}%");
                            })
                            ->with('productModel.brand:id,name')
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(
                                fn (ProductVariant $variant): array => [
                                    $variant->getKey() => self::variantLabel($variant),
                                ],
                            )
                            ->all(),
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string => self::variantLabel(
                            ProductVariant::query()
                                ->with('productModel.brand:id,name')
                                ->find($value),
                        ),
                    )
                    ->searchable()
                    ->nullable(),
                TextInput::make('alias')
                    ->label(__('admin.actions.product_match_review.alias'))
                    ->helperText(
                        __('admin.actions.product_match_review.alias_help'),
                    )
                    ->minLength(2)
                    ->maxLength(180),
                Select::make('alias_locale')
                    ->label(__('admin.actions.product_match_review.alias_locale'))
                    ->options(SupportedLocale::nativeOptions())
                    ->default(
                        fn (): string => str_replace('_', '-', app()->getLocale()),
                    )
                    ->selectablePlaceholder(false),
                Textarea::make('reason')
                    ->label(__('admin.actions.product_match_review.reason'))
                    ->helperText(
                        __('admin.actions.product_match_review.reason_help'),
                    )
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000),
            ])
            ->action(function (array $data, ProductMatch $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                $result = app(ReviewProductMatch::class)->review(
                    productMatch: $record,
                    operator: $actor,
                    decision: ProductMatchReviewDecision::Confirm,
                    expectedCurrentMatchId: $data['expected_current_match_id'],
                    idempotencyKey: $data['idempotency_key'],
                    reason: $data['reason'],
                    productModel: ProductModel::query()->findOrFail(
                        $data['product_model_id'],
                    ),
                    productVariant: isset($data['product_variant_id'])
                        ? ProductVariant::query()->findOrFail(
                            $data['product_variant_id'],
                        )
                        : null,
                    alias: $data['alias'] ?? null,
                    aliasLocale: $data['alias_locale'] ?? null,
                    ipAddress: request()->ip(),
                    userAgent: request()->userAgent(),
                );

                Notification::make()
                    ->title(
                        $result['created']
                            ? __('admin.actions.product_match_review.confirmed')
                            : __('admin.actions.product_match_review.replayed'),
                    )
                    ->success()
                    ->send();
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('rejectMatch')
            ->label(__('admin.actions.product_match_review.reject_label'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(
                __('admin.actions.product_match_review.reject_heading'),
            )
            ->modalDescription(
                __('admin.actions.product_match_review.reject_description'),
            )
            ->visible(
                fn (ProductMatch $record): bool => app(
                    ReviewProductMatch::class,
                )->isReviewable($record),
            )
            ->schema([
                Hidden::make('expected_current_match_id')
                    ->default(
                        fn (ProductMatch $record): string => $record->getKey(),
                    )
                    ->required(),
                Hidden::make('idempotency_key')
                    ->default(fn (): string => (string) Str::uuid())
                    ->required(),
                Textarea::make('reason')
                    ->label(__('admin.actions.product_match_review.reason'))
                    ->helperText(
                        __('admin.actions.product_match_review.reject_reason_help'),
                    )
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000),
            ])
            ->action(function (array $data, ProductMatch $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                $result = app(ReviewProductMatch::class)->review(
                    productMatch: $record,
                    operator: $actor,
                    decision: ProductMatchReviewDecision::Reject,
                    expectedCurrentMatchId: $data['expected_current_match_id'],
                    idempotencyKey: $data['idempotency_key'],
                    reason: $data['reason'],
                    ipAddress: request()->ip(),
                    userAgent: request()->userAgent(),
                );

                Notification::make()
                    ->title(
                        $result['created']
                            ? __('admin.actions.product_match_review.rejected')
                            : __('admin.actions.product_match_review.replayed'),
                    )
                    ->success()
                    ->send();
            });
    }

    private static function modelLabel(?ProductModel $model): ?string
    {
        if ($model === null) {
            return null;
        }

        return trim(sprintf(
            '%s %s (%s)',
            $model->brand?->name,
            $model->name,
            $model->model_number,
        ));
    }

    private static function variantLabel(?ProductVariant $variant): ?string
    {
        if ($variant === null) {
            return null;
        }

        return trim(sprintf(
            '%s · %s%s',
            self::modelLabel($variant->productModel),
            $variant->name,
            $variant->sku === null ? '' : " ({$variant->sku})",
        ));
    }
}
