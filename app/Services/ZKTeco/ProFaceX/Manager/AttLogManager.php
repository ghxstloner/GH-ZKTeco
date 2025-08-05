<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

use Illuminate\Support\Facades\Log;

class AttLogManager
{
    public function createAttLog($attLogList = [])
    {
        foreach ($attLogList as $attLog) {
            $attLog->save();
        }
    }
}
