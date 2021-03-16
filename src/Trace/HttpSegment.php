<?php

namespace Simplia\Integration\Trace;

class HttpSegment extends \Pkerrigan\Xray\HttpSegment {
    public function setStartEnd(float $startTime, float $endTime): self {
        $this->startTime = $startTime;
        $this->endTime = $endTime;

        return $this;
    }
}
