<?php

namespace Laith343\FcmBlast\Contracts;

interface ContextAware
{
    /**
     * Receive the per-run context passed to FcmBlast::withContext().
     *
     * Called once on the resolved TokenSource, MessageBuilder, and
     * InvalidTokenHandler before they are used, so each instance knows
     * which run/campaign it is serving (e.g. a campaign id, segment, or
     * payload reference).
     *
     * The context travels through the queued job, so it must be
     * serializable (arrays, scalars, or SerializesModels-friendly values).
     *
     * @param  mixed  $context  Whatever was passed to withContext(), or null.
     */
    public function withRunContext(mixed $context): static;
}
