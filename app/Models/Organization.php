<?php

namespace App\Models;

use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Cashier\Billable;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Billable, HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'type',
        'personal_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'trial_ends_at' => 'immutable_datetime',
        ];
    }

    public function stripeEmail(): ?string
    {
        return $this->memberships()
            ->where('role', OrganizationRole::Owner)
            ->with('user:id,email')
            ->first()
            ?->user
            ?->email;
    }

    public function personalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'personal_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(OrganizationAuditEvent::class);
    }

    public function marketPreference(): HasOne
    {
        return $this->hasOne(OrganizationMarketPreference::class);
    }

    public function planAssignment(): HasOne
    {
        return $this->hasOne(OrganizationPlanAssignment::class);
    }

    public function billingCheckoutSessions(): HasMany
    {
        return $this->hasMany(BillingCheckoutSession::class);
    }

    public function billingProviderEvents(): HasMany
    {
        return $this->hasMany(BillingProviderEvent::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function marketplaceImports(): HasMany
    {
        return $this->hasMany(MarketplaceImport::class);
    }

    public function comparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(ComparableMarketNormalization::class);
    }

    public function sellComparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(SellComparableMarketNormalization::class);
    }

    public function ownedProducts(): HasMany
    {
        return $this->hasMany(OwnedProduct::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationMembership::class)
            ->withPivot(['id', 'role', 'joined_at'])
            ->withTimestamps();
    }
}
