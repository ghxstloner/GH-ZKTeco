<?php

namespace App\Services\Hikvision;

use RuntimeException;

/**
 * Un feature del Bridge está desactivado (HTTP 503 o estado "feature_disabled").
 *
 * Es RECUPERABLE: el flujo de sincronización/pull NO debe abortar ni marcar
 * el dispositivo como roto. Simplemente se omite la operación y se registra.
 *
 * Features relevantes del Bridge: provisioning, attendance-events, raw-isapi,
 * channel-sync, stream, playback, voice, alarm, storage.
 */
class HikvisionFeatureDisabledException extends RuntimeException
{
    public function __construct(
        string $message = 'Hikvision bridge feature disabled',
        public readonly ?string $feature = null,
        public readonly ?int $httpStatus = null,
        public readonly ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
