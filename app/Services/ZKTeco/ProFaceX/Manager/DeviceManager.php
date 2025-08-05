<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;

class DeviceManager
{
    public function getDeviceInfoBySn(string $deviceSn)
    {
        return ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();
    }

    public function updateDeviceState(string $deviceSn, string $state, string $lastActivity)
    {
        $deviceInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();
        if (!$deviceInfo) {
            return;
        }

        $deviceInfo->STATE = $state;
        $deviceInfo->LAST_ACTIVITY = $lastActivity; // Current date time
        $deviceInfo->save();
    }
}
