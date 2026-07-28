<?php

namespace App\Models;

use App\Enums\Localization\SupportedLocale;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'preferred_locale',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
            'preferred_locale' => SupportedLocale::class,
        ];
    }

    public function preferredLocale(): string
    {
        return $this->preferred_locale->value;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->is_super_admin
            && $this->hasVerifiedEmail();
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function personalOrganization(): HasOne
    {
        return $this->hasOne(Organization::class, 'personal_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function telegramConnections(): HasMany
    {
        return $this->hasMany(TelegramConnection::class);
    }

    public function marketplaceImports(): HasMany
    {
        return $this->hasMany(MarketplaceImport::class, 'created_by_user_id');
    }

    public function comparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(
            ComparableMarketNormalization::class,
            'created_by_user_id',
        );
    }

    public function sellComparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(
            SellComparableMarketNormalization::class,
            'created_by_user_id',
        );
    }

    public function privacyRequests(): HasMany
    {
        return $this->hasMany(PrivacyRequest::class, 'subject_user_id');
    }

    public function privacyRequestEvents(): HasMany
    {
        return $this->hasMany(PrivacyRequestEvent::class, 'actor_user_id');
    }

    public function sentOrganizationInvitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class, 'invited_by_user_id');
    }

    public function acceptedOrganizationInvitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class, 'accepted_by_user_id');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationMembership::class)
            ->withPivot(['id', 'role', 'joined_at'])
            ->withTimestamps();
    }
}
