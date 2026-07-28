<?php

return [
    'workflow_version' => env(
        'PRIVACY_WORKFLOW_VERSION',
        'privacy-request-workflow:v1',
    ),
    'privacy_notice_version' => env(
        'PRIVACY_NOTICE_VERSION',
        'privacy-notice:v1',
    ),
    'response_target_days' => (int) env(
        'PRIVACY_RESPONSE_TARGET_DAYS',
        30,
    ),
    'request_history_limit' => 25,
    'event_history_limit' => 50,
    'maximum_events_per_request' => 50,
];
