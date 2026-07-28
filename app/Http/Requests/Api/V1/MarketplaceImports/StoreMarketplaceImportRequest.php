<?php

namespace App\Http\Requests\Api\V1\MarketplaceImports;

use App\Models\MarketplaceSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class StoreMarketplaceImportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header(
                'Idempotency-Key',
                $this->input('idempotency_key'),
            ),
            'marketplace_source_key' => $this->input(
                'marketplace_source_key',
                MarketplaceSource::AUTHORIZED_CSV_KEY,
            ),
            'delimiter' => $this->input('delimiter', 'comma'),
            'default_target_country_code' => is_string(
                $this->input('default_target_country_code'),
            )
                ? mb_strtoupper($this->input('default_target_country_code'))
                : null,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'marketplace_source_key' => [
                'required',
                'string',
                Rule::exists('marketplace_sources', 'key')
                    ->where('connector_type', 'csv')
                    ->where('compliance_status', 'approved')
                    ->where('active', true),
            ],
            'file' => [
                'required',
                File::types(['csv', 'txt'])
                    ->max((int) config('marketplace_connectors.max_file_kilobytes')),
            ],
            'delimiter' => ['required', Rule::in(['comma', 'semicolon', 'tab'])],
            'default_target_country_code' => [
                'nullable',
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'authorization_confirmed' => ['accepted'],
        ];
    }
}
