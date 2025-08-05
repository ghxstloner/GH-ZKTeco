<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

class DeviceLogManager
{
    public function addDeviceLog($deviceLogList = [])
    {
        foreach ($deviceLogList as $deviceLog) {
            $deviceLog->save();
        }
    }
}
