<?php

namespace Laith343\FcmBlast\Engine;

use Laith343\FcmBlast\Support\Outcome;

final class OutcomeClassifier
{
    /**
     * Transient curl error numbers worth retrying (connection-level blips):
     * 7 couldnt-connect, 18 partial-file, 28 timeout, 35 ssl-connect,
     * 52 got-nothing, 55 send-error, 56 recv-error.
     */
    private const RETRYABLE_CURL_ERRORS = [7, 18, 28, 35, 52, 55, 56];

    public static function classify(int $curlResult, int $httpCode): Outcome
    {
        if ($curlResult !== CURLE_OK) {
            return in_array($curlResult, self::RETRYABLE_CURL_ERRORS, true)
                ? Outcome::Transient
                : Outcome::Failed;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return Outcome::Ok;
        }

        if ($httpCode === 404 || $httpCode === 400) {
            return Outcome::Invalid;
        }

        if ($httpCode === 429 || $httpCode === 503) {
            return Outcome::Throttled;
        }

        return Outcome::Failed;
    }
}
