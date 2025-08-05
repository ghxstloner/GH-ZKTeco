<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;

class UserInfoManager
{
    public function createUserInfo($userInfoList = [])
    {
        foreach ($userInfoList as $useList) {
            $useList->save();
        }
    }

    public function updateUserPic($userInfoList = [])
    {
        $this->createUserInfo($userInfoList);

        return $userInfoList;
    }

    public function getUserInfoByPinAndSn(?string $userPin, ?string $deviceSn)
    {
        $info = ProFxUserInfo::where('USER_PIN', $userPin)->where('DEVICE_SN', $deviceSn)->first();
        if (!$info) {
            return null;
        }

        return $info;
    }
}
