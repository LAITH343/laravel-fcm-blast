<?php

return [

    /*
     | Firebase service-account credentials. Either an absolute path to the
     | service-account JSON file, or the raw JSON string itself. Leave null
     | to run in fake-token mode (useful for load-testing a mock endpoint).
     */
    'credentials' => env('FCM_BLAST_CREDENTIALS', env('FIREBASE_CREDENTIALS')),

    /*
     | Firebase project id. Used to build the FCM v1 send endpoint. Ignored
     | when 'endpoint' below is set.
     */
    'project_id' => env('FCM_BLAST_PROJECT_ID', env('FIREBASE_PROJECT_ID')),

    /*
     | Optional endpoint override. When set, requests go here instead of the
     | real FCM endpoint. Point this at a mock server for load testing.
     */
    'endpoint' => env('FCM_BLAST_ENDPOINT'),

    /*
     | Default integration classes. May be overridden per run via the fluent
     | API (FcmBlast::tokensFrom(...)->buildMessage(...)).
     */
    'token_source' => null,
    'message_builder' => null,
    'invalid_token_handler' => null,

    /*
     | Global per-second send cap across all workers. Each worker gets
     | floor(rate_cap_per_sec / workers).
     */
    'rate_cap_per_sec' => (int) env('FCM_BLAST_RATE_CAP', 10000),

    /*
     | Send messages with validate_only=true by default (validate without
     | delivering). Overridable per run via ->validateOnly().
     */
    'validate_only' => (bool) env('FCM_BLAST_VALIDATE_ONLY', false),

    /*
     | Max attempts per token for retryable responses (429/503) before it is
     | counted as failed.
     */
    'max_retries' => (int) env('FCM_BLAST_MAX_RETRIES', 5),

    /*
     | Queue name and connection the blast jobs are dispatched onto. The
     | connection must be 'redis' for stale-job purging to run.
     */
    'queue' => env('FCM_BLAST_QUEUE', 'fcm-blast'),
    'connection' => env('FCM_BLAST_CONNECTION', 'redis'),

    /*
     | Cache key holding the shared OAuth token, and how many seconds before
     | expiry to proactively refresh it.
     */
    'token_cache_key' => env('FCM_BLAST_TOKEN_CACHE_KEY', 'fcm-blast:oauth'),
    'token_refresh_buffer' => (int) env('FCM_BLAST_TOKEN_REFRESH_BUFFER', 600),

];
