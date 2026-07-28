<?php

namespace App\Models;

use App\Enums\Markets\ContinentCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Continent extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'sort_order', 'active', 'source_version'];

    protected function casts(): array
    {
        return [
            'code' => ContinentCode::class,
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function countries(): HasMany
    {
        return $this->hasMany(Country::class, 'continent_code');
    }
}
