<?php

namespace App\Http\Resources\V1;

use App\BrokerRequests\BrokerReportConfiguration;
use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Models\BrokerReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin BrokerReport */
class BrokerReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $downloadUrl = null;

        if (
            $this->status === BrokerReportStatus::Available
            && $this->artifact_expires_at->isFuture()
        ) {
            $configuration = BrokerReportConfiguration::load();
            $downloadUrl = URL::temporarySignedRoute(
                'api.v1.broker-reports.content',
                now()->addMinutes($configuration->downloadTtlMinutes),
                ['brokerReport' => $this->getKey()],
                absolute: false,
            );
        }

        return [
            'id' => $this->getKey(),
            'status' => $this->status->value,
            'report_version' => $this->report_version,
            'locale' => $this->locale->value,
            'sequence' => $this->sequence,
            'artifact_size_bytes' => $this->artifact_size_bytes,
            'page_count' => $this->page_count,
            'download_url' => $downloadUrl,
            'generated_at' => $this->generated_at->toIso8601String(),
            'artifact_expires_at' => (
                $this->artifact_expires_at->toIso8601String()
            ),
            'purged_at' => $this->purged_at?->toIso8601String(),
        ];
    }
}
