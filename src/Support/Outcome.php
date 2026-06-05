<?php

namespace Laith343\FcmBlast\Support;

enum Outcome
{
    case Ok;
    case Invalid;
    case Throttled;
    case Failed;
}
