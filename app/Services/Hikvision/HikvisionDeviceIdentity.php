<?php

namespace App\Services\Hikvision;

class HikvisionDeviceIdentity
{
    public function __construct(
        public readonly string $rawDeviceId,
        public readonly string $canonicalDeviceId,
        public readonly string $empresaCodigo,
        public readonly int $deviceSequence,
        public readonly bool $legacyAlias = false,
    ) {}
}
