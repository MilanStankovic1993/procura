<?php

namespace App\Operations\Data;

final readonly class ProductionPreflightCheck
{
    public const FAIL = 'fail';

    public const PASS = 'pass';

    public const WARNING = 'warning';

    public function __construct(
        public string $key,
        public string $status,
        public string $message,
    ) {}

    public function blocksDeployment(): bool
    {
        return $this->status === self::FAIL;
    }

    public function requiresReview(): bool
    {
        return $this->status === self::WARNING;
    }

    /**
     * @return array{status: string, message: string}
     */
    public function payload(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
