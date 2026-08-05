<?php

namespace App\Http\Controllers\Api\V1\BrokerRequests;

use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Http\Controllers\Controller;
use App\Models\BrokerReport;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadBrokerReportController extends Controller
{
    public function __invoke(
        string $brokerReport,
        OrganizationContext $context,
    ): StreamedResponse {
        $report = BrokerReport::query()
            ->forOrganization($context->organization())
            ->with('brokerRequest.organization')
            ->findOrFail($brokerReport);
        Gate::authorize('view', $report->brokerRequest);

        if (
            $report->status !== BrokerReportStatus::Available
            || $report->artifact_expires_at->isPast()
        ) {
            abort(404);
        }

        $disk = Storage::disk($report->disk);
        if (! $disk->exists($report->path)) {
            abort(404);
        }
        $bytes = $disk->get($report->path);

        if (
            strlen($bytes) !== $report->artifact_size_bytes
            || ! hash_equals(
                $report->artifact_sha256,
                hash('sha256', $bytes),
            )
        ) {
            abort(404);
        }

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            $report->filename,
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ],
        );
    }
}
