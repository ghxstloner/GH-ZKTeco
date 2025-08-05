<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

class ManagerFactory
{
    private static ?DeviceCommandManager $commandManager = null;
    private static ?DeviceManager $deviceManager = null;
    private static ?AttPhotoManager $attPhotoManager = null;
    private static ?DeviceLogManager $logManager = null;
    private static ?UserInfoManager $userInfoManager = null;
    private static ?BioTemplateManager $bioTemplateManager = null;
    private static ?AttLogManager $attLogManager = null;

    public static function getCommandManager(): DeviceCommandManager
    {
        if (null == self::$commandManager) {
            self::$commandManager = new DeviceCommandManager();
        }

        return self::$commandManager;
    }

    public static function getDeviceManager(): ?DeviceManager
    {
        if (null == self::$deviceManager) {
            self::$deviceManager = new DeviceManager();
        }

        return self::$deviceManager;
    }

    public static function getAttPhotoManager(): ?AttPhotoManager
    {
        if (null == self::$attPhotoManager) {
            self::$attPhotoManager = new AttPhotoManager();
        }

        return self::$attPhotoManager;
    }

    public static function getDeviceLogManager(): ?DeviceLogManager
    {
        if (null == self::$logManager) {
            self::$logManager = new DeviceLogManager();
        }

        return self::$logManager;
    }

    public static function getUserInfoManager(): ?UserInfoManager
    {
        if (null == self::$userInfoManager) {
            self::$userInfoManager = new UserInfoManager();
        }

        return self::$userInfoManager;
    }

    public static function getBioTemplateManager(): ?BioTemplateManager
    {
        if (null == self::$bioTemplateManager) {
            self::$bioTemplateManager = new BioTemplateManager();
        }

        return self::$bioTemplateManager;
    }

    public static function getAttLogManager(): ?AttLogManager
    {
        if (null == self::$attLogManager) {
            self::$attLogManager = new AttLogManager();
        }

        return self::$attLogManager;
    }
}
