<?php

namespace Laith343\FcmBlast\Dispatching;

final class RunPlanner
{
    /**
     * Split a total into N contiguous, non-overlapping offset/limit slices.
     *
     * @return list<array{offset:int,limit:int}>
     */
    public function slices(int $total, int $workers): array
    {
        if ($total <= 0 || $workers <= 0) {
            return [];
        }

        $perWorker = (int) ceil($total / $workers);
        $slices = [];

        for ($i = 0; $i < $workers; $i++) {
            $offset = $i * $perWorker;
            if ($offset >= $total) {
                break;
            }
            $slices[] = [
                'offset' => $offset,
                'limit' => min($perWorker, $total - $offset),
            ];
        }

        return $slices;
    }
}
