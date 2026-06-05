<?php

namespace Laith343\FcmBlast\Support;

enum Outcome
{
    case Ok;
    case Invalid;
    case Throttled;   // FCM 429/503 — retryable
    case Transient;   // transport error (timeout/reset) — retryable
    case Failed;
}
