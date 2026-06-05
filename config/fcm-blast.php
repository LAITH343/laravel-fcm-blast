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
     | Curl HTTP version: '2tls' (HTTP/2 over TLS, HTTP/1.1 over cleartext),
     | '2' (force HTTP/2), or '1.1'. '2tls' is strongly recommended for real
     | FCM — it multiplexes thousands of requests over a few connections and
     | avoids socket exhaustion. It automatically falls back to HTTP/1.1 for
     | plain-http endpoints (e.g. a local mock).
     */
    'http_version' => env('FCM_BLAST_HTTP_VERSION', '2tls'),

    /*
     | Max TCP connections per host. Null uses rateCap * 0.3 (tuned for the
     | many-connections HTTP/1.1 mock). For real FCM over HTTP/2 set a small
     | value (e.g. 8-32) so requests multiplex instead of opening sockets.
     */
    'max_host_connections' => env('FCM_BLAST_MAX_HOST_CONNECTIONS'),

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
