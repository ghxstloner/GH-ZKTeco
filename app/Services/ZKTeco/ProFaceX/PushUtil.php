<?php

namespace App\Services\ZKTeco\ProFaceX;

use App\Services\ZKTeco\ProFaceX\Manager\ManagerFactory;

class PushUtil
{
    /** Verification type */
    public static $ATT_VERIFY = array(
        "0" => "FP/PW/RF",
        "1" => "FP",
        "2" => "PIN",
        "3" => "PW",
        "4" => "RF",
        "5" => "FP/PW",
        "6" => "FP/RF",
        "7" => "PW/RF",
        "8" => "PIN&FP",
        "9" => "FP&PW",
        "10" => "FP&RF",
        "11" => "PW&RF",
        "12" => "FP&PW&RF",
        "13" => "PIN&FP&PW",
        "14" => "FP&RF/PIN",
        "15" => "FACE",
        "16" => "FACE&FP",
        "17" => "FACE&PW",
        "18" => "FACE&RF",
        "19" => "FACE&FP&RF",
        "20" => "FACE&FP&PW",
        "101" => "SLAVE DEVICE"
    );

    /** Attendance status */
    public static $ATT_STATUS = array(
        "0" => "Check-In",        // work attendance
        "1" => "CheckOut",        // sign-off from work
        "2" => "BreakOut",        // out
        "3" => "Break-In",        // out return
        "4" => "OT-IN",           // overtime attendance
        "5" => "OT-OUT",          // overtime sign-off
        "255" => "255"
    );

    /** Real time event */
    public static $ATT_OP_TYPE = array(
        0 => "Power ON Device",
        1 => "Power OFF Device",
        2 => "Fail Verification",
        3 => "Generate an Alarm",
        4 => "Enter Menu",
        5 => "Modify Configuration",
        6 => "Enroll FingerPrint",
        7 => "Enroll Password",
        8 => "Enroll HID Card",
        9 => "Delete one User",
        10 => "Delete FingerPrint",
        11 => "Delete Password",
        12 => "Delete RF Card",
        13 => "Purge Data",
        14 => "Create MF Card",
        15 => "Enroll MF Card",
        16 => "Register MF Card",
        17 => "Delete MF Card",
        18 => "Clean MF Card",
        19 => "Copy Data to Card",
        20 => "Copy C. D. to Device",
        21 => "Set New Time",
        22 => "Factory Setting",
        23 => "Delete Att. Records",
        24 => "Clean Admin. Privilege",
        25 => "Modify Group Settings",
        26 => "Modify User AC Settings",
        27 => "Modify Time Zone",
        28 => "Modify Unlock Settings",
        29 => "Open Door Lock",
        30 => "Enroll one User",
        31 => "Modify FP Template",
        32 => "Duress Alarm",
        34 => "Antipassback Failed",
        35 => "Delete User pic",
    );


    /**
     * Compares the version.
     * @param $version1
     * @param $version2
     * @return int|void
     * @throws \Exception
     */
    public static function compareVersion($version1, $version2)
    {
        if ($version1 == null || $version2 == null) {
            throw new \Exception("compareVersion error:illegal params.");
        }

        $result = version_compare($version1, $version2);

        if ($result == -1) {
            // version1 is less than version2
            return -1;
        } else if ($result == 0) {
            // versions are equal
            return 0;
        } else if ($result == 1) {
            // version1 is greater than version2
            return 1;
        }
    }

    public static function getDeviceLangBySn(string $deviceSn)
    {
        $device = ManagerFactory::getDeviceManager()->getDeviceInfoBySn($deviceSn);
        if (!$deviceSn) {
            return "";
        }

        return $device->DEV_LANGUAGE ?? "";
    }

    public static function updateDevMap($deviceInfoModel)
    {
        $deviceInfoModel->save();
    }

    /**
     * El indicador de características del dispositivo. Formato: 101, cada carácter indica una característica. 0 no compatible. 1 compatible.
     * @param string $devFuns
     * @param DEV_FUNS $devFun
     * @return bool
     */
    public static function isDevFun(string $devFuns, string $devFun): bool
    {
        $isSupport = false;
        if (empty($devFuns)) {
            return false;
        }

        switch ($devFun) {
            case Constants::DEV_FUNS['FP']:
                // El primer carácter indica la función de huella dactilar.
                $isSupport = $devFuns[0] === "1";
                break;
            case Constants::DEV_FUNS['FACE']:
                // El segundo carácter indica la función facial.
                $isSupport = $devFuns[1] === "1";
                break;
            case Constants::DEV_FUNS['USERPIC']:
                // El tercer carácter indica la foto del usuario.
                $isSupport = $devFuns[2] === "1";
                break;
            case Constants::DEV_FUNS['BIOPHOTO']:
                // El cuarto carácter indica la foto biométrica del usuario.
                if (strlen($devFuns) > 3) {
                    $isSupport = $devFuns[3] === "1";
                }
                break;
            case Constants::DEV_FUNS['BIODATA']:
                // El quinto carácter indica los datos biométricos del usuario.
                if (strlen($devFuns) > 4) {
                    $isSupport = $devFuns[4] === "1";
                }
                break;
            default:
                break;
        }

        return $isSupport;
    }

}
