<?php

namespace App\Services\ZKTeco\ProFaceX;

use App\Models\ZKTeco\ProFaceX\ProFxDeviceCommand;
use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;
use Carbon\Carbon;

final class DevCmdUtil
{
    public static function getNewCommand(string $deviceSn, string $content): ProFxDeviceCommand
    {
        $deviceCommand = new ProFxDeviceCommand();
        $deviceCommand->DEVICE_SN = $deviceSn;
        $deviceCommand->CMD_CONTENT = $content;
        $deviceCommand->CMD_COMMIT_TIMES = Carbon::now()->format('Y-m-d H:i:s');
        $deviceCommand->CMD_TRANS_TIMES = "";
        $deviceCommand->CMD_OVER_TIME = "";
        $deviceCommand->CMD_RETURN = "";

        return $deviceCommand;
    }

    public static function getINFOCommand(string $deviceSn): ProFxDeviceCommand
    {
        /**Gets INFO Command objects*/
        return self::getNewCommand($deviceSn, Constants::DEV_CMD_INFO);
    }

    public static function getClearAllDataCommand(string $deviceSn): ProFxDeviceCommand
    {
        /**Gets the command*/
        return self::getNewCommand($deviceSn, Constants::DEV_CMD_CLEAR_DATA);
    }

    public static function createRebootCommand(string $deviceSn): ProFxDeviceCommand
    {
        return self::getNewCommand($deviceSn, Constants::DEV_CMD_REBOOT);
    }

    public static function getDeleteUserCommand(ProFxUserInfo $userInfo): ProFxDeviceCommand
    {
        $content = sprintf(Constants::DEV_CMD_DATA_DELETE_USERINFO, $userInfo->USER_PIN);
        return self::getNewCommand($userInfo->DEVICE_SN, $content);
    }

    /**
     * Obtiene el comando de actualización de la información del usuario.
     *
     * @param UserInfo $userInfo
     * @param string|null $deviceSn
     * @return ProFxDeviceCommand
     */
    public static function getUpdateUserCommand(ProFxUserInfo $userInfo, ?string $deviceSn): ProFxDeviceCommand
    {
        // Si no hay un número de serie de dispositivo, obtén el número de serie del dispositivo que tiene el usuario
        if (null === $deviceSn || "" === $deviceSn) {
            $sn = $userInfo->DEVICE_SN;
        } else {
            $sn = $deviceSn;
        }

        // Obtiene el objeto de comando
        return self::getNewCommand($sn, self::getUpdateUserContent($userInfo));
    }

    /**
     * Formatea el contenido del comando UPDATE USERINFO.
     *
     * @param ProFxUserInfo $userInfo
     * @return string
     */
    public static function getUpdateUserContent(ProFxUserInfo $userInfo): string
    {
        return sprintf(
            Constants::DEV_CMD_DATA_UPDATE_USERINFO,
            $userInfo->USER_PIN,
            $userInfo->NAME,
            $userInfo->PRIVILEGE,
            $userInfo->PASSWORD,
            $userInfo->MAIN_CARD,
            $userInfo->ACC_GROUP_ID,
            $userInfo->TZ,
            $userInfo->category,
        );
    }

    /**
     * Obtiene el comando de actualización de la foto del usuario.
     *
     * @param UserInfo $userInfo
     * @param string|null $deviceSn
     * @return ProFxDeviceCommand
     */
    public static function getUpdateUserPicCommand(ProFxUserInfo $userInfo, ?string $deviceSn): ProFxDeviceCommand
    {
        // Si no hay un número de serie de dispositivo, obtén el número de serie del dispositivo que tiene el usuario
        if (null === $deviceSn || "" === $deviceSn) {
            $sn = $userInfo->DEVICE_SN;
        } else {
            $sn = $deviceSn;
        }

        // Obtiene el objeto de comando
        return self::getNewCommand($sn, self::getUpdateUserPicContent($userInfo));
    }

    /**
     * Obtiene el contenido del comando de actualización de la foto del usuario.
     *
     * @param ProFxUserInfo $userInfo
     * @return string
     */
    public static function getUpdateUserPicContent(ProFxUserInfo $userInfo): string
    {
        return sprintf(
            Constants::DEV_CMD_DATA_UPDATE_USERPIC,
            $userInfo->USER_PIN,
            strval($userInfo->PHOTO_ID_SIZE),
            $userInfo->PHOTO_ID_CONTENT
        );
    }

    /**
     * Obtiene el comando de actualización de la foto biométrica del usuario.
     *
     * @param ProFxUserInfo $userInfo
     * @param string|null $deviceSn
     * @return ProFxDeviceCommand
     */
    public static function getUpdateBioPhotoCommand(ProFxUserInfo $userInfo, ?string $deviceSn): ProFxDeviceCommand
    {
        // Si no hay un número de serie de dispositivo, obtén el número de serie del dispositivo que tiene el usuario
        if (null === $deviceSn || "" === $deviceSn) {
            $sn = $userInfo->DEVICE_SN;
        } else {
            $sn = $deviceSn;
        }

        // Obtiene el objeto de comando
        return self::getNewCommand($sn, self::getUpdateBioPhotoContent($userInfo));
    }

    /**
     * Obtiene el contenido del comando de actualización de la foto biométrica del usuario.
     *
     * @param ProFxUserInfo $userInfo
     * @return string
     */
    public static function getUpdateBioPhotoContent(ProFxUserInfo $userInfo): string
    {
        return sprintf(
            Constants::DEV_CMD_DATA_UPDATE_BIOPHOTO,
            $userInfo->USER_PIN,
            "2",
            strval($userInfo->PHOTO_ID_SIZE),
            $userInfo->PHOTO_ID_CONTENT
        );
    }
}
