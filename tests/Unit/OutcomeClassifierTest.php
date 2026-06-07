<?php

use Laith343\FcmBlast\Engine\OutcomeClassifier;
use Laith343\FcmBlast\Support\Outcome;

it('maps 2xx to ok', function () {
    expect(OutcomeClassifier::classify(CURLE_OK, 200))->toBe(Outcome::Ok)
        ->and(OutcomeClassifier::classify(CURLE_OK, 299))->toBe(Outcome::Ok);
});

it('maps 404 and 400 to invalid', function () {
    expect(OutcomeClassifier::classify(CURLE_OK, 404))->toBe(Outcome::Invalid)
        ->and(OutcomeClassifier::classify(CURLE_OK, 400))->toBe(Outcome::Invalid);
});

it('maps 429 and 503 to throttled', function () {
    expect(OutcomeClassifier::classify(CURLE_OK, 429))->toBe(Outcome::Throttled)
        ->and(OutcomeClassifier::classify(CURLE_OK, 503))->toBe(Outcome::Throttled);
});

it('classifies transient curl errors as transient (retryable)', function (int $errno) {
    expect(OutcomeClassifier::classify($errno, 0))->toBe(Outcome::Transient);
})->with([7, 18, 28, 35, 52, 55, 56]);

it('fails on non-retryable curl errors and other http codes', function () {
    expect(OutcomeClassifier::classify(6, 0))->toBe(Outcome::Failed)        // couldnt resolve host
        ->and(OutcomeClassifier::classify(CURLE_OK, 401))->toBe(Outcome::Failed)
        ->and(OutcomeClassifier::classify(CURLE_OK, 500))->toBe(Outcome::Failed);
});
