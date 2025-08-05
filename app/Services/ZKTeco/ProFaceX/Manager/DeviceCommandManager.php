<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

use App\Models\ZKTeco\ProFaceX\ProFxDeviceCommand;
use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;
use App\Services\ZKTeco\ProFaceX\Constants;
use App\Services\ZKTeco\ProFaceX\DevCmdUtil;
use App\Services\ZKTeco\ProFaceX\PushUtil;

class DeviceCommandManager
{
    public function getDeviceCommandById($devCmdId)
    {
        return ProFxDeviceCommand::find($devCmdId);
    }

    public function createINFOCommand(string $deviceSn): void
    {
        $deviceCommandModel = DevCmdUtil::getINFOCommand($deviceSn);
        $deviceCommandModel->save();
    }

    public function createClearAllDataCommand(string $deviceSn): void
    {
        $deviceCommandModel = DevCmdUtil::getClearAllDataCommand($deviceSn);
        $deviceCommandModel->save();
    }

    public function createRebootCommand(string $deviceSn): void
    {
        $deviceCommandModel = DevCmdUtil::createRebootCommand($deviceSn);
        $deviceCommandModel->save();
    }

    public function createDeleteUserCommandByIds(array $userIds): void
    {
        $userInfoList = ProFxUserInfo::whereIn('USER_ID', $userIds)->get();
        foreach($userInfoList as $userInfo) {
            $deviceCommandModel = DevCmdUtil::getDeleteUserCommand($userInfo);
            $deviceCommandModel->save();
        }
    }

    public function getDeviceCommandListToDevice(string $deviceSn)
    {
        return ProFxDeviceCommand::where('DEVICE_SN', $deviceSn)
            ->where('CMD_RETURN', '')
            ->orderBy('DEV_CMD_ID', 'asc')
            ->skip(0)->take(100)->get();
    }

    public static function updateDeviceCommand(array $commandModelList = []): void
    {
        foreach ($commandModelList as $commandModel) {
            $commandModel->save();
        }
    }

    /**
     * Crea un comando de actualización de datos de usuario del dispositivo según el ID de usuario.
     * - Se enviará el comando de actualización de la plantilla de huella dactilar al dispositivo si la función de huella dactilar es compatible en el dispositivo.
     * - Se enviará el comando de actualización de la foto del usuario al dispositivo si la función de foto de usuario es compatible en el dispositivo.
     * - Se enviará el comando de actualización de la plantilla facial al dispositivo si la función de reconocimiento facial es compatible en el dispositivo.
     *
     * @param ProFxUserInfo $userInfo
     * @param string|null $DEV_FUNS
     * @return int
     */
    public static function createUpdateUserInfosCommandByIds(ProFxUserInfo $userInfo, string $DEV_FUNS): int
    {
        // Verificar las funciones que soporta el dispositivo
        $isSupportUserPic = PushUtil::isDevFun($DEV_FUNS, Constants::DEV_FUNS['USERPIC']);
        $isSupportBioPhoto = PushUtil::isDevFun($DEV_FUNS, Constants::DEV_FUNS['BIOPHOTO']);

        try {
            $comandos = [];
            // Crear el comando de actualización de la información del usuario
            $comandos [] = DevCmdUtil::getUpdateUserCommand($userInfo, $userInfo->DEVICE_SN);
            // Crear el comando de actualización de la foto del usuario
            if ($isSupportUserPic && null !== $userInfo->PHOTO_ID_NAME && !empty($userInfo->PHOTO_ID_NAME)) {
                $comandos [] = DevCmdUtil::getUpdateUserPicCommand($userInfo, $userInfo->DEVICE_SN);
            }
            // Crear el comando de actualización de la foto biométrica del usuario
            if ($isSupportBioPhoto && null !== $userInfo->PHOTO_ID_NAME && !empty($userInfo->PHOTO_ID_NAME)) {
                $comandos [] = DevCmdUtil::getUpdateBioPhotoCommand($userInfo, $userInfo->DEVICE_SN);
            }

            ManagerFactory::getCommandManager()->updateDeviceCommand($comandos);
        } catch (\Exception $e) {
            return 1;
        }

        return 0;
    }

}
