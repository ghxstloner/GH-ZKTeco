<?php

namespace App\Http\Controllers\ZKTeco\ProFaceX;

use App\Http\Controllers\Controller;
use App\Models\ZKTeco\ProFaceX\ProFxAttPhoto;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;
use App\Services\ZKTeco\ProFaceX\Constants;
use App\Services\ZKTeco\ProFaceX\DataParseUtil;
use App\Services\ZKTeco\ProFaceX\Manager\ManagerFactory;
use App\Services\ZKTeco\ProFaceX\PushUtil;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UploadProcessController extends Controller
{
    public function getCdata(Request $request)
    {
        $dateTime = DataParseUtil::getDateTimeInGMTFormat();
        $deviceSn = $request->query('SN');
        $options = $request->query('options');
        $table = $request->query('table');
        $userPin = $request->query('PIN');



        if (empty($deviceSn)) {

            return response('error')->header('Content-Type', 'text/plain')->header('Date', $dateTime);
        }

        $lang = "";
        $charset = 'UTF-8';

        try {
            if ($options === 'all') {
                $devInfo = $this->getDeviceInfo($deviceSn, $request);
                if (!is_null($devInfo)) {
                    $deviceOptions = $this->getDeviceOptions($devInfo);

                    return response($deviceOptions)->header('Content-Type', 'text/plain')->header('Date', $dateTime)->header('charset', $charset);
                } else {

                    return response('error')->header('Content-Type', 'text/plain')->header('Date', $dateTime);
                }
            } elseif ($table === 'RemoteAtt') {
                if (is_null($userPin) || empty($userPin)) {

                    return response('error')->header('Content-Type', 'text/plain')->header('Date', $dateTime);
                }

                if ($this->processRemoteAtt($userPin, $deviceSn, $lang) === 0) {
                    return response(null)->header('Content-Type', 'text/plain')->header('Date', $dateTime);
                } else {

                    return response('error')->header('Content-Type', 'text/plain')->header('Date', $dateTime);
                }
            } else {

                return response('error')->header('Content-Type', 'text/plain')->header('Date', $dateTime);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response('error')->header('Content-Type', 'text/plain')->header('Date', $dateTime);
        }
    }

    public function postCdata(Request $request)
    {
        $response = new Response();
        $response->header('Content-Type', 'text/plain');
        $dateTime = DataParseUtil::getDateTimeInGMTFormat();
        $response->header('Date', $dateTime);
        $deviceSn = $request->input('SN');
        $table = $request->input('table');
        $lang = PushUtil::getDeviceLangBySn($deviceSn);

        logger()->info("device language:" . $lang . ",get request and begin update device stamp:" . $request->ip() . ";" . $request->fullUrl());

        try {
            $re = $this->updateDeviceStamp($deviceSn, $request);
            if ($re != 0) {
                return $response->setContent('error:device not exist');
            }

            logger()->info("update device stamp end and get stream.");

            $buffer = '';
            $bufferData = '';
            $postData = $request->getContent();
            $pathStr = storage_path('app/AttPhoto');
            $fileOS = null;
            $dataLines = explode("\n", $postData);
            foreach ($dataLines as $buffer) {
                if (!$buffer) {
                    continue;
                }

                if ((strpos($buffer, 'CMD=realupload') !== false || strpos($buffer, 'CMD=uploadphoto') !== false) && $table === 'ATTPHOTO') {
                    $path = $this->createAttPhotoFile($buffer, $pathStr);

                    if ($path === null) {
                        return $response->setContent('OK');
                    }

                    $filePath = $path;
                    $fileOS = fopen($filePath, 'w');

                    if (strpos($buffer, 'CMD=realupload') !== false) {
                        $cmdUpload = 'CMD=realupload';
                    } else {
                        $cmdUpload = 'CMD=uploadphoto';
                    }

                    $newBuffer = substr($buffer, 0, strpos($buffer, $cmdUpload) + strlen($cmdUpload));
                    fwrite($fileOS, substr($newBuffer, strlen($cmdUpload) + 1));
                } else {
                    if ($fileOS !== null) {
                        fwrite($fileOS, $buffer);
                    } else {
                        if ($lang === Constants::DEV_LANG_ZH_CN) {
                            $bufferData .= iconv('GB2312', 'UTF-8', $buffer) . "\n";
                        } else {
                            $bufferData .= $buffer . "\n";
                        }
                    }
                }
            }

            if ($fileOS !== null) {
                fclose($fileOS);
            }

            $data = $bufferData;

            if ($table === 'options') {
                $this->updateDevInfo($data, $deviceSn);
            }

            logger()->info("data:" . $data);
            logger()->info("end get stream and process data");

            $result = $this->processDatas($table, $data, $deviceSn);

            logger()->info("end process data and return msg to device");

            if ($result === 0) {
                return $response->setContent('OK');
                logger()->info("return msg to device and request over OK");
            } else {
                return $response->setContent('error');
                logger()->info("return msg to device and request over error");
            }
        } catch (\Exception $e) {
            logger()->error($e);
        }

        return null;
    }

    /**
     * Processes the data by table structure.
     *
     * @param string $table
     * @param string $data
     * @param string $deviceSn
     * @return int
     */
    private function processDatas(string $table, string $data, string $deviceSn)
    {
        if ("OPERLOG" === $table) {
            if (strpos($data, "OPLOG ") === 0) {
                try {

                    $result = DataParseUtil::parseOPLog($data, $deviceSn);

                    return $result;
                } catch (\Exception $e) {
                    return -1;
                }
            } elseif (strpos($data, "USER ") === 0) {

                DataParseUtil::parseUserData($data, $deviceSn);

                return 0;
            } elseif (strpos($data, "FP ") === 0) {

                DataParseUtil::parseFingerPrint($data, $deviceSn);

                return 0;
            } elseif (strpos($data, "USERPIC ") === 0) {

                DataParseUtil::parseUserPic($data, $deviceSn);

                return 0;
            } elseif (strpos($data, "FACE ") === 0) {

                DataParseUtil::parseFace($data, $deviceSn);

                return 0;
            } elseif (strpos($data, "BIOPHOTO ") === 0) {

                // DataParseUtil::parseBioPhoto();
                // ...
                // ...

            }
        } elseif ("BIODATA" === $table) {

            DataParseUtil::parseFingerPrint($data, $deviceSn);

            return 0;
        } elseif ("ATTLOG" === $table) {

            $response = DataParseUtil::parseAttlog($data, $deviceSn);
            if ($response === 1) {
                return 1;
            }


            return 0;
        }

        return 0;
    }

    /**
     * Update device information.
     *
     * @param string $data
     * @param string $devSn
     * @return int
     */
    private function updateDevInfo(string $data, string $devSn)
    {
        $deviceInfo = ManagerFactory::getDeviceManager()->getDeviceInfoBySn($devSn);
        if (null === $deviceInfo) {
            return -1;
        }

        $data = substr($data, 0, -1); // Remove curly brackets

        $keyValuePairs = explode(",", $data); // Split the string to create key-value pairs
        $map = [];

        foreach ($keyValuePairs as $pair) {
            $entry = explode("=", $pair); // Split the pairs to get key and value
            if (count($entry) > 1) {
                $map[trim($entry[0])] = trim($entry[1]); // Add them to the map and trim whitespaces
            }
        }

        if (isset($map["IRTempDetectionFunOn"])) {
            try {
                $tempReading = $map["IRTempDetectionFunOn"];
                $deviceInfo->TEMPERATURE = $tempReading;
            } catch (\Exception $e) {
                $deviceInfo->TEMPERATURE = "255";
            }
        }

        if (isset($map["MaskDetectionFunOn"])) {
            try {
                $maskFlag = (int)$map["MaskDetectionFunOn"];
                $deviceInfo->MASK = $maskFlag;
            } catch (\Exception $e) {
                $deviceInfo->MASK = 255;
            }
        }

        if (isset($map["PvFunOn"])) {
            try {
                $palm = (int)$map["PvFunOn"];
                $deviceInfo->PALM = $palm;
            } catch (\Exception $e) {
                $deviceInfo->PALM = 255;
            }
        }

        PushUtil::updateDevMap($deviceInfo);

        return 0;
    }

    /**
     * Creates attendance photo file and saves the photo.
     *
     * @param string $data
     * @param string $filePath
     * @return \Illuminate\Support\Facades\File|null
     */
    private function createAttPhotoFile(string $data, string $filePath)
    {
        $file = null;
        try {
            $photoArr = explode("\n", $data);
            $fileName = explode("=", $photoArr[0])[1];
            $sn = explode("=", $photoArr[1])[1];
            $size = explode("=", $photoArr[2])[1];

            $fileSn = $filePath . "/" . $sn;
            if (!File::exists($fileSn) && !File::isDirectory($fileSn)) {
                File::makeDirectory($fileSn);
            }

            $file = $fileSn . "/" . $fileName;

            if (!File::exists($file)) {
                File::put($file, "");

                $attPhoto = new ProFxAttPhoto();
                $attPhoto->DEVICE_SN = $sn;
                $attPhoto->FILE_NAME = $fileName;
                $attPhoto->SIZE = (int)$size;
                $attPhoto->FILE_PATH = str_replace("\\", "/", $file);
                $list = [$attPhoto];

                ManagerFactory::getAttPhotoManager()->createAttPhoto($list);
            } else {
                $file = null;
            }
        } catch (\Exception $e) {
            $e->printStackTrace();
        }

        return $file;
    }

    /**
     * Update device table data Stamp.
     *
     * @param string $deviceSn
     * @param \Illuminate\Http\Request $request
     * @return int
     * <li> -1: device non-existed
     * <li> 0 : OK
     */
    private function updateDeviceStamp(string $deviceSn, Request $request)
    {
        $stamp = $request->input("Stamp");
        $opStamp = $request->input("OpStamp");
        $photoStamp = $request->input("PhotoStamp");
        $bioDataStamp = $request->input("BioDataStamp");
        $idCardStamp = $request->input("IdCardStamp");
        $errorLogStamp = $request->input("ErrorLogStamp");
        $deviceInfo = ManagerFactory::getDeviceManager()->getDeviceInfoBySn($deviceSn);

        if (null === $deviceInfo) {
            return -1;
        }

        if (null !== $stamp) {
            $deviceInfo->LOG_STAMP = $stamp;
        }

        if (null !== $opStamp) {
            $deviceInfo->OP_LOG_STAMP = $opStamp;
        }

        if (null !== $photoStamp) {
            $deviceInfo->PHOTO_STAMP = $photoStamp;
        }

        if (null !== $bioDataStamp) {
            $deviceInfo->bioData_Stamp = $bioDataStamp;
        }

        if (null !== $idCardStamp) {
            $deviceInfo->idCard_Stamp = $idCardStamp;
        }

        if (null !== $errorLogStamp) {
            $deviceInfo->errorLog_Stamp = $errorLogStamp;
        }

        $deviceInfo->STATE = "connection";
        $deviceInfo->LAST_ACTIVITY = Carbon::now()->format('Y-m-d H:i:s');
        $deviceInfo->save();

        return 0;
    }

    /**
     * Gets the device info by Device SN<br>
     * <li>Query the device info from Server. if it is existed, return it.
     * <li>if not, create a new device info.
     *
     * @param $deviceSn
     * @param Request $request
     * @return
     * device info<code>DeviceInfo</code>
     */
    private function getDeviceInfo($deviceSn, Request $request)
    {
        $devInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();

        /**push version*/
        $pushver = $request->input('pushver');
        /**Current language*/
        $language = $request->input('language');
        /**the communication key between Server and device*/
        $pushcommkey = $request->input('pushcommkey');

        /**Device IP Address*/
        $ipAddress = $request->ip();

        if (!$devInfo) {
            /**if the device is not existed. Set the device info as default value.*/
            $devInfo = new ProFxDeviceInfo();
            $devInfo->IPADDRESS = $ipAddress;
            $devInfo->DEVICE_NAME = $deviceSn . "(" . $ipAddress . ")";
            $devInfo->ALIAS_NAME = $ipAddress;
            $devInfo->TRANS_INTERVAL = 1;
            $devInfo->DEVICE_SN = $deviceSn;
            $devInfo->STATE = "Online";
            $devInfo->LOG_STAMP = "0";
            $devInfo->OP_LOG_STAMP = "0";
            $devInfo->PHOTO_STAMP = "0";
            $devInfo->DEV_LANGUAGE = $language;
            if ($pushver == null) {
                $devInfo->PUSH_VERSION = "1.0.0";
            } else {
                $devInfo->PUSH_VERSION = $pushver;
            }
            $devInfo->TIME_ZONE = "-0500"; // timezone panama
            $devInfo->TRANS_TIMES = "00:00;14:05";
            $devInfo->PUSH_COMM_KEY = $pushcommkey;
            $devInfo->LAST_ACTIVITY = Carbon::now()->format('Y-m-d H:i:s');
            $devInfo->bioData_Stamp = "0";
            $devInfo->idCard_Stamp = "0";
            $devInfo->errorLog_Stamp = "0";

            $devInfo->save();

            //new device add a INFO command
            ManagerFactory::getCommandManager()->createINFOCommand($deviceSn);
        } else {
            $devInfo->IPADDRESS = $ipAddress;
            $devInfo->STATE = "Online";//Device Status
            $devInfo->LAST_ACTIVITY = Carbon::now()->format('Y-m-d H:i:s');
            $devInfo->save();
        }

        return $devInfo;
    }

    public function getDeviceOptions(ProFxDeviceInfo $devInfo): string
    {
        $sb = Str::of("GET OPTION FROM: ")->append($devInfo->DEVICE_SN)->append("\n");
        // Processes Stamp and TransFlag
        $verComp = -1;
        try {
            $verComp = PushUtil::compareVersion($devInfo->PUSH_VERSION, "2.0.0");
        } catch (\Exception $e) {
            Log::error($e->getTraceAsString());
        }

        // If the push is higher than 2.0.0, it will do like {table}Stamp. Otherwise, old style
        if ($verComp >= 0) {
            $sb = Str::of($sb)->append("ATTLOGStamp=")->append($devInfo->LOG_STAMP)->append("\n");
            $sb = Str::of($sb)->append("OPERLOGStamp=")->append($devInfo->OP_LOG_STAMP)->append("\n");
            $sb = Str::of($sb)->append("ATTPHOTOStamp=")->append($devInfo->PHOTO_STAMP)->append("\n");
            $sb = Str::of($sb)->append("BIODATAStamp=")->append($devInfo->bioData_Stamp)->append("\n");
            $sb = Str::of($sb)->append("IDCARDStamp=")->append($devInfo->idCard_Stamp)->append("\n");
            $sb = Str::of($sb)->append("ERRORLOGStamp=")->append($devInfo->errorLog_Stamp)->append("\n");
            $sb = Str::of($sb)->append("TransFlag=TransData AttLog\tOpLog\tAttPhoto\tEnrollUser\tChgUser\tEnrollFP\tChgFP\tFPImag\tFACE\tUserPic\tBioPhoto\n");
        } else {
            $sb = Str::of($sb)->append("Stamp=")->append($devInfo->LOG_STAMP)->append("\n");
            $sb = Str::of($sb)->append("OpStamp=")->append($devInfo->OP_LOG_STAMP)->append("\n");
            $sb = Str::of($sb)->append("PhotoStamp=")->append($devInfo->PHOTO_STAMP)->append("\n");
            $sb = Str::of($sb)->append("BioDataStamp=")->append($devInfo->bioData_Stamp)->append("\n");
            $sb = Str::of($sb)->append("IdCardStamp=")->append($devInfo->idCard_Stamp)->append("\n");
            $sb = Str::of($sb)->append("ErrorLogStamp=")->append($devInfo->errorLog_Stamp)->append("\n");
            $sb = Str::of($sb)->append("TransFlag=111111111111\n");
        }

        // Otra información
        $sb = Str::of($sb)->append("ErrorDelay=60\n");
        $sb = Str::of($sb)->append("Delay=30\n");
        $sb = Str::of($sb)->append("transTimes=")->append($devInfo->TRANS_TIMES)->append("\n");
        $sb = Str::of($sb)->append("TransInterval=")->append($devInfo->TRANS_INTERVAL)->append("\n");
        $sb = Str::of($sb)->append("Realtime=1\n");
        $sb = Str::of($sb)->append("Encrypt=1\n");
        $sb = Str::of($sb)->append("ServerVer=2.2.14\n");

        $timeZone = $devInfo->TIME_ZONE;
        if (!empty($timeZone)) {
            $timeZone = $this->changeTimeZone($devInfo->TIME_ZONE);
        }

        $sb = Str::of($sb)->append("TimeZone=")->append($timeZone)->append("\n");

        return Str::of($sb)->toString();
    }

    private function changeTimeZone($timeZone): string
    {
        $timeStr = '';
        $str1 = substr($timeZone, 0, 1);
        $str2 = substr($timeZone, 1, 2);
        $str3 = substr($timeZone, 3);

        if ($str1 == '-') {
            $timeStr .= $str1;
        }

        if ($str3 === "00") { // Zona horaria completa
            $timeStr .= intval($str2);
        } else { // Medio zona horaria
            $timeStr .= (intval($str2) * 60 + intval($str3));
        }

        return $timeStr;
    }

    private function processRemoteAtt($userPin, $deviceSn, $lang): int
    {
        return 0;
    }
}
