<?php

namespace App\Console\Commands;

use App\Actions\BrokerRequests\PurgeExpiredBrokerReports;
use Illuminate\Console\Command;

final class PurgeExpiredBrokerReportsCommand extends Command
{
    protected $signature = 'broker-reports:purge-expired
        {--limit= : Maximum number of expired reports to process}';

    protected $description = 'Purge expired private broker-report artifacts';

    public function handle(PurgeExpiredBrokerReports $action): int
    {
        $rawLimit = $this->option('limit');
        $limit = $rawLimit === null || $rawLimit === ''
            ? null
            : filter_var($rawLimit, FILTER_VALIDATE_INT);

        if ($limit === false || ($limit !== null && $limit < 1)) {
            $this->components->error('The purge limit must be a positive integer.');

            return self::FAILURE;
        }

        $result = $action->execute($limit);
        $this->components->info(sprintf(
            'Processed %d broker reports: %d purged, %d failed.',
            $result->processed,
            $result->purged,
            $result->failed,
        ));

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
