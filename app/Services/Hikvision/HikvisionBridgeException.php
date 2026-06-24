<?php

namespace App\Services\Hikvision;

use RuntimeException;

/**
 * Error fatal al comunicarse con el Bridge ISUP de Hikvision.
 *
 * Se lanza cuando:
 *  - El Bridge responde con code != 200 (distinto de feature_disabled).
 *  - Falla la conexión HTTP (timeout, DNS, conexión rechazada).
 *  - El JSON devuelto no se puede parsear o no respeta el envelope {code,msg,data}.
 *  - El Bridge reporta un sdkError.
 *
 * NO se lanza para "feature disabled" (503 / feature_disabled) — para eso
 * existe HikvisionFeatureDisabledException, que es recuperable.
 */
class HikvisionBridgeException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?int $bridgeCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
