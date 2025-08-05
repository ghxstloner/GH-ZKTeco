<?php

namespace App\Services\ZKTeco\ProFaceX;

use App\Services\MarcacionesServices;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Models\ZKTeco\ProFaceX\ProFxAttLog;
use App\Models\ZKTeco\ProFaceX\ProFxPersBioTemplate;
use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceLog;
use App\Services\ZKTeco\ProFaceX\Manager\ManagerFactory;

class DataParseUtil
{
    public static function getDateTimeInGMTFormat(): string
    {
        $dt = Carbon::now();
        $dt->setTimezone('GMT');
        return $dt->format('D, d M Y H:i:s T');
    }


    /**
     * Gets operation logs from data and saves it.
     *
     * @param string $data
     * @param string $deviceSn
     * @return int
     */
    public static function parseOPLog(string $data, string $deviceSn)
    {
        $list = [];
        if (null !== $data && !empty($data)) {
            $userInfos = explode("\n", $data);
            foreach ($userInfos as $string) {
                if (!$string) {
                    continue;
                }
                $fieldsStr = substr($string, strpos($string, "OPLOG ") + strlen("OPLOG "));
                $fields = explode("\t", $fieldsStr);
                try {
                    $log = self::prepareOpLog($fields);
                    if ($log) {
                        $log->DEVICE_SN = $deviceSn;
                        $list [] = $log;
                    }
                } catch (\Exception $e) {
                    throw $e;
                }
            }
        }

        ManagerFactory::getDeviceLogManager()->addDeviceLog($list);
        Log::info("oplog size: " . count($list));
        return 0;
    }

    /**
     * Gets DeviceLog from field data.
     */
    private static function prepareOpLog(array $fields)
    {
        if (count($fields) <= 0) {
            return "";
        }

        $log = new ProFxDeviceLog();
        try {
            $opType = (int)$fields[0];
            $log->OPERATOR_TYPE = $opType;
            $log->OPERATOR = $fields[1];
            $log->OP_TIME = $fields[2];
            $log->VALUE1 = $fields[3];
            $log->VALUE2 = $fields[4];
            $log->VALUE3 = $fields[5];
            $log->RESERVED = $fields[6];
        } catch (\Exception $e) {
            throw new \Exception("data parse error");
        }

        return $log;
    }

    /**
     * Split the user data by \n, get the keys/values to parse to UserInfo,
     * add to list, and then save to the database.
     *
     * @param string $data
     * @param string $deviceSn
     * @return int
     */
    public static function parseUserData(string $data, string $deviceSn)
    {
        Log::info("user data:\n" . $data);
        $list = [];
        if (null !== $data && !empty($data)) {
            $userInfos = explode("\n", $data);
            foreach ($userInfos as $string) {
                $fieldsStr = substr($string, strpos($string, "USER ") + strlen("USER "));
                $fields = explode("\t", $fieldsStr);
                $info = self::parseUser($fields);

                if (count($info->toArray()) == 0) {
                    continue;
                }

                $info->DEVICE_SN = $deviceSn;

                $userInfo = ProFxUserInfo::where(['USER_PIN' => $info->USER_PIN, 'DEVICE_SN' => $info->DEVICE_SN])->first();
                if ($userInfo) {
                    $userInfo->delete();
                }

                $list[] = $info;
            }
        }

        ManagerFactory::getUserInfoManager()->createUserInfo($list);
        Log::info("user size: " . count($list));
        return 0;
    }

    /**
     * Parse user info by key=value and return the UserInfo entity.
     *
     * @param array $fields
     * @return UserInfo
     */
    private static function parseUser(array $fields)
    {
        $info = new ProFxUserInfo();
        foreach ($fields as $string) {
            if (empty($string)) {
                continue;
            }
            if (strpos($string, "PIN") === 0) {
                $info->USER_PIN = substr($string, strpos($string, "PIN=") + strlen("PIN="));
            } elseif (strpos($string, "Name") === 0) {
                $info->NAME = substr($string, strpos($string, "Name=") + strlen("Name="));
            } elseif (strpos($string, "Pri") === 0) {
                try {
                    $pri = (int)substr($string, strpos($string, "Pri=") + strlen("Pri="));
                    $info->PRIVILEGE = $pri;
                } catch (\Exception $e) {
                    $info->PRIVILEGE = 0;
                }
            } elseif (strpos($string, "Passwd") === 0) {
                $info->PASSWORD = substr($string, strpos($string, "Passwd=") + strlen("Passwd="));
            } elseif (strpos($string, "Card") === 0) {
                $info->MAIN_CARD = substr($string, strpos($string, "Card=") + strlen("Card="));
            } elseif (strpos($string, "Grp") === 0) {
                try {
                    $accGroupId = (int)substr($string, strpos($string, "Grp=") + strlen("Grp="));
                    $info->ACC_GROUP_ID = $accGroupId;
                } catch (\Exception $e) {
                    $info->ACC_GROUP_ID = 1;
                }
            } elseif (strpos($string, "TZ") === 0) {
                $info->TZ = substr($string, strpos($string, "TZ=") + strlen("TZ="));
            }
        }

        return $info;
    }

    /**
     * Gets finger print template from data and saves it.
     *
     * @param string $data
     * @param string $deviceSn
     * @return int
     */
    public static function parseFingerPrint(string $data, string $deviceSn)
    {
        Log::info("finger data:\n" . $data);
        $list = [];
        $fieldsStr = null;
        if (!empty($data)) {
            $fps = explode("\n", $data);
            foreach ($fps as $string) {
                if (!$string) {
                    continue;
                }

                if (Str::startsWith($data, "FP ")) {
                    $fieldsStr = substr($string, strpos($string, "FP ") + strlen("FP "));
                } elseif (Str::startsWith($data, "BIODATA ")) {
                    $fieldsStr = substr($string, strpos($string, "BIODATA ") + strlen("BIODATA "));
                }

                $fields = explode("\t", $fieldsStr);
                $fp = self::prepareFingerPrint($fields, $deviceSn);
                if (!$fp) {
                    return -1;
                }
                $list [] = $fp;
            }
        }

        ManagerFactory::getBioTemplateManager()->createBioTemplate($list);
        Log::info("fp size: " . count($list));
        return 0;
    }

    /**
     * Gets FP PersonBioTemplate by field data.
     *
     * @param array $fields
     * @param string $deviceSn
     */
    private static function prepareFingerPrint(array $fields, string $deviceSn)
    {
        $template = new ProFxPersBioTemplate();
        foreach ($fields as $string) {
            if (strpos($string, "PIN") === 0 || strpos($string, "Pin") === 0) {
                if (strpos($string, "PIN") === 0) {
                    $template->USER_PIN = substr($string, strpos($string, "PIN=") + strlen("PIN="));
                } elseif (strpos($string, "Pin") === 0) {
                    $template->USER_PIN = substr($string, strpos($string, "Pin=") + strlen("Pin="));
                }
            } elseif (strpos($string, "FID") === 0) {
                try {
                    $templateNo = (int)substr($string, strpos($string, "FID=") + strlen("FID="));
                    $template->TEMPLATE_NO = $templateNo;
                } catch (\Exception $e) {
                    $template->TEMPLATE_NO = 0;
                }
            } elseif (strpos($string, "No") === 0) {
                try {
                    $templateNo = (int)substr($string, strpos($string, "No=") + strlen("No="));
                    $template->TEMPLATE_NO = $templateNo;
                } catch (\Exception $e) {
                    $template->TEMPLATE_NO = 0;
                }
            } elseif (strpos($string, "Size") === 0) {
                try {
                    $size = (int)substr($string, strpos($string, "Size=") + strlen("Size="));
                    $template->SIZE = $size;
                } catch (\Exception $e) {
                    $template->SIZE = 0;
                }
            } elseif (strpos($string, "Valid") === 0) {
                try {
                    $valid = (int)substr($string, strpos($string, "Valid=") + strlen("Valid="));
                    $template->VALID = $valid;
                } catch (\Exception $e) {
                    $template->VALID = 1;
                }
            } elseif (strpos($string, "TMP") === 0) {
                $template->TEMPLATE_DATA = substr($string, strpos($string, "TMP=") + strlen("TMP="));
            } elseif (strpos($string, "Tmp") === 0) {
                $template->TEMPLATE_DATA = substr($string, strpos($string, "Tmp=") + strlen("Tmp="));
                $s = substr($string, strpos($string, "Tmp=") + strlen("Tmp="));
                $y = strlen($s);
                $template->SIZE = $y;
            }
        }
        $template->IS_DURESS = 0;

        $template->BIO_TYPE = Constants::BIO_TYPE_FP;
        $template->DATA_FORMAT = Constants::BIO_DATA_FMT_ZK;
        foreach ($fields as $string) {
            if (strpos($string, "MajorVer") === 0) {
                $a = (int)substr($string, strpos($string, "MajorVer=") + strlen("MajorVer="));
                $majorVer = (string)$a;
                $template->VERSION = $majorVer;
            }
        }
        foreach ($fields as $string) {
            if (strpos($string, "Type") === 0) {
                try {
                    $type = (int)substr($string, strpos($string, "Type=") + strlen("Type="));
                    $template->BIO_TYPE = $type;
                    if ($type === 9) {
                        $template->VERSION = Constants::BIO_VERSION_FACE_7;
                    }
                } catch (\Exception $e) {
                    $template->BIO_TYPE = 1;
                }
            }
        }
        $template->TEMPLATE_NO_INDEX = 0;
        $template->DEVICE_SN = $deviceSn;

        $userInfo = ManagerFactory::getUserInfoManager()->getUserInfoByPinAndSn($template->USER_PIN, $template->DEVICE_SN);
        if (null === $userInfo) {
            return null;
        }

        $template->USER_ID = $userInfo->USER_ID;
        if (null === $template->VERSION) {
            $template->VERSION = Constants::BIO_VERSION_FP_12;
        }

        return $template;
    }

    /**
     * Gets the user photo from data and saves it.
     *
     * @param string $data
     * @param string $deviceSn
     * @return int
     */
    public static function parseUserPic(string $data, string $deviceSn)
    {
        $list = [];
        if (null !== $data && !empty($data)) {
            $userInfos = explode("\n", $data);
            foreach ($userInfos as $string) {
                $fieldsStr = substr($string, strpos($string, "USERPIC ") + strlen("USERPIC "));
                $fields = explode("\t", $fieldsStr);
                $info = self::prepareUserPic($fields, $deviceSn);

                if (null === $info) {
                    return -1;
                }
                $list[] = $info;
            }
        }

        /** save it into the database */
        $ret = ManagerFactory::getUserInfoManager()->updateUserPic($list);
        Log::info("userpic size: " . count($list));
        return $ret;
    }

    /**
     * Gets the user info by user photo data.
     *
     * @param array $fields
     * @param string $deviceSn
     * @return UserInfo|null
     */
    private static function prepareUserPic(array $fields, string $deviceSn)
    {
        $info = new ProFxUserInfo();

        /** Gets all the field values */
        foreach ($fields as $string) {
            if (strpos($string, "PIN") === 0) {
                $info->USER_PIN = substr($string, strpos($string, "PIN=") + strlen("PIN="));
            } elseif (strpos($string, "FileName") === 0) {
                $info->PHOTO_ID_NAME = substr($string, strpos($string, "FileName=") + strlen("FileName="));
            } elseif (strpos($string, "Size") === 0) {
                try {
                    $size = (int)substr($string, strpos($string, "Size=") + strlen("Size="));
                    $info->PHOTO_ID_SIZE = $size;
                } catch (\Exception $e) {
                    return null;
                }
            } elseif (strpos($string, "Content") === 0) {
                $info->PHOTO_ID_CONTENT = substr($string, strpos($string, "Content=") + strlen("Content="));
            }
        }

        /** Gets the user info */
        $userInfo = ManagerFactory::getUserInfoManager()->getUserInfoByPinAndSn($info->USER_PIN, $deviceSn);
        if (null === $userInfo) {
            return null;
        }

        /** Updates the user info */
        $userInfo->PHOTO_ID_NAME = $info->PHOTO_ID_NAME;
        $userInfo->PHOTO_ID_SIZE = $info->PHOTO_ID_SIZE;
        $userInfo->PHOTO_ID_CONTENT = $info->PHOTO_ID_CONTENT;

        return $userInfo;
    }

    /**
     * Gets the face template from data and saves it into the database.
     *
     * @param string $data
     * @param string $deviceSn
     * @return int
     */
    public static function parseFace(string $data, string $deviceSn)
    {
        if (null === $data || empty($data) || null === $deviceSn || empty($deviceSn)) {
            return -1;
        }

        Log::info("face data:\n" . $data);
        $list = [];
        $faces = explode("\n", $data);
        foreach ($faces as $string) {
            $fieldsStr = substr($string, strpos($string, "FACE ") + strlen("FACE "));
            $fields = explode("\t", $fieldsStr);
            $face = self::prepareFace($fields, $deviceSn);

            if (null === $face) {
                return -1;
            }
            $list[] = $face;
        }

        /** Save the templates into the database */
        ManagerFactory::getBioTemplateManager()->createBioTemplate($list);
        Log::info("face size: " . count($list));
        return 0;
    }

    /**
     * Gets the face PersonBioTemplate from data.
     *
     * @param array $fields
     * @param string $deviceSn
     * @return PersonBioTemplate|null
     */
    private static function prepareFace(array $fields, string $deviceSn)
    {
        $template = new ProFxPersBioTemplate();

        /** Gets all the field values */
        foreach ($fields as $string) {
            if (strpos($string, "PIN") === 0) {
                $template->USER_ID = substr($string, strpos($string, "PIN=") + strlen("PIN="));
            } elseif (strpos($string, "FID") === 0) {
                try {
                    $templateNo = (int)substr($string, strpos($string, "FID=") + strlen("FID="));
                    $template->TEMPLATE_NO = $templateNo;
                } catch (\Exception $e) {
                    $template->TEMPLATE_NO = 0;
                }
            } elseif (strpos($string, "SIZE") === 0) {
                try {
                    $size = (int)substr($string, strpos($string, "SIZE=") + strlen("SIZE="));
                    $template->SIZE = $size;
                } catch (\Exception $e) {
                    $template->SIZE = 0;
                }
            } elseif (strpos($string, "VALID") === 0) {
                try {
                    $valid = (int)substr($string, strpos($string, "VALID=") + strlen("VALID="));
                    $template->VALID = $valid;
                } catch (\Exception $e) {
                    $template->VALID = 1;
                }
            } elseif (strpos($string, "TMP") === 0) {
                $template->TEMPLATE_DATA = substr($string, strpos($string, "TMP=") + strlen("TMP="));
            }
        }

        $template->IS_DURESS = 0;
        $template->BIO_TYPE = Constants::BIO_TYPE_FACE; // Biometrics type: face
        $template->DATA_FORMAT = Constants::BIO_DATA_FMT_ZK; // Data format: zk type
        $template->VERSION = Constants::BIO_VERSION_FACE_7; // Face algorithm version
        $template->TEMPLATE_NO_INDEX = 0;
        $template->DEVICE_SN = $deviceSn;

        /** Gets the user info by user ID and device SN */
        $userInfo = ManagerFactory::getUserInfoManager()->getUserInfoByPinAndSn($template->USER_PIN, $template->DEVICE_SN);

        if (null === $userInfo) {
            return null;
        }

        $template->USER_ID = $userInfo->USER_ID;

        return $template;
    }

    /**
     * Gets attendance record from data and saves it.
     *
     * @param string $data
     * @param string $deviceSn
     * @return int
     */
    public static function parseAttlog(string $data, string $deviceSn)
    {
        $list = [];
        $devLoglist = [];
        $result = 0;
        if (!empty($data)) {
            $attLogs = explode("\n", $data);
            foreach ($attLogs as $string) {
                if (!$string) {
                    continue;
                }

                $attValues = explode("\t", $string);
                $log = new ProFxAttLog();

                $devLog = new ProFxDeviceLog();
                $devLog->DEVICE_SN = $deviceSn;
                $devLog->OPERATOR = $attValues[0];
                $devLog->OP_TIME = $attValues[1];

                $log->DEVICE_SN = $deviceSn;
                $log->USER_PIN = $attValues[0];
                $log->VERIFY_TIME = $attValues[1];
                $sb = "";
                try {
                    $sb .= PushUtil::$ATT_STATUS[$attValues[2]];
                    $sb .= ":";
                    $status = (int)$attValues[2];
                    $log->STATUS = $status;
                } catch (\Exception $e) {
                    $log->STATUS = 0;
                }
                try {
                    $sb .= PushUtil::$ATT_VERIFY[$attValues[3]];
                    $verifyType = (int)$attValues[3];
                    $log->VERIFY_TYPE = $verifyType;
                } catch (\Exception $e) {
                    $log->VERIFY_TYPE = 1;
                }
                try {
                    $workcode = (int)$attValues[4];
                    $log->WORK_CODE = $workcode;
                } catch (\Exception $e) {
                    $log->WORK_CODE = 0;
                }
                try {
                    $reserved1 = (int)$attValues[5];
                    $log->RESERVED1 = $reserved1;
                } catch (\Exception $e) {
                    $log->RESERVED1 = 0;
                }
                try {
                    $reserved2 = (int)$attValues[6];
                    $log->RESERVED2 = $reserved2;
                } catch (\Exception $e) {
                    $log->RESERVED2 = 0;
                }

                try {
                    $maskFlag = (int)$attValues[7];
                    $log->MASK = $maskFlag;
                    $devLog->MASK = $maskFlag;
                } catch (\Exception $e) {
                    $log->MASK = 255;
                }

                try {
                    $tempReading = $attValues[8] ?? "";
                    $log->TEMPERATURE = $tempReading;
                    $devLog->TEMPERATURE = $tempReading;
                } catch (\Exception $e) {
                    $log->TEMPERATURE = "255";
                }

                $devLog->OPERATE_TYPE_STR = $sb;

                if (ProFxAttLog::where('USER_PIN', $log->USER_PIN)
                        ->where('VERIFY_TIME', $log->VERIFY_TIME)
                        ->where('DEVICE_SN', $log->DEVICE_SN)
                        ->where('VERIFY_TYPE', $log->VERIFY_TYPE)->count() > 0) {
                    $result = 1;
                    Log::info("Registro de marcacion duplicada: " . print_r($log->toArray(), true));
                    continue;
                }

                $devLoglist [] = $devLog;
                $list[] = $log;
            }
        }

        ManagerFactory::getDeviceLogManager()->addDeviceLog($devLoglist);
        ManagerFactory::getAttLogManager()->createAttLog($list);

        if ($result === 0) {
            (new MarcacionesServices())->registrarMarcaciones($list);
            Log::info("attlog size: " . count($list));
        }

        return $result;
    }
}
