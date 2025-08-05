<?php

namespace App\Http\Controllers\ZKTeco\ProFaceX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ZKTeco\ProFaceX\deviceSn;
use App\Http\Controllers\ZKTeco\ProFaceX\devSn;
use App\Http\Controllers\ZKTeco\ProFaceX\Exception;
use App\Http\Controllers\ZKTeco\ProFaceX\info;
use App\Http\Controllers\ZKTeco\ProFaceX\infoDatas;
use App\Services\ZKTeco\ProFaceX\Constants;
use App\Services\ZKTeco\ProFaceX\Manager\ManagerFactory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DownloadProcessController extends Controller
{
    public function getRequest(Request $request)
    {
        $info = $request->input('INFO');
        $devSn = $request->input('SN');

        /**return when device serial number exception*/
        if (is_null($devSn) || empty($devSn)) {
            return response('error')->header('Content-Type', 'text/plain');
        }

        /**Define the character encoding type by the current language*/
        $encoding = 'UTF-8';



        /**Gets the command list by device SN*/
        $list = ManagerFactory::getCommandManager()->getDeviceCommandListToDevice($devSn);



        /**save the logs of commands*/
        $tempList = [];
        if ($list->count() > 0) {
            $sb = '';
            foreach ($list as $command) {
                /**Gets the command content*/
                $content = Constants::DEV_CMD_TITLE;
                $content .= $command->DEV_CMD_ID . ":";
                $content .= $command->CMD_CONTENT . "\n";
                /**the command should be less than setting, default 64K*/


                $sb .= $content;

                /**Sets the transmit time*/
                $command->CMD_TRANS_TIMES = Carbon::now()->toDateTimeString();
                $tempList[] = $command;
            }
            /**Sets the command*/
            $response = response($sb, 200)->header('Content-Type', 'text/plain;charset=' . $encoding);



            /**Update the command list*/
            ManagerFactory::getCommandManager()->updateDeviceCommand($tempList);
        } else {
            $response = response('OK', 200)->header('Content-Type', 'text/plain');
        }

        /**Update device INFO*/
        if ($info) {

            $this->updateDeviceInfo($info, $devSn);
        } else {
            ManagerFactory::getDeviceManager()->updateDeviceState($devSn, "connecting", Carbon::now()->format('Y-m-d H:i:s'));
        }

        return $response;
    }

    public function postDeviceCmd(Request $request)
    {
        $response = new Response();
        $response->header('Content-Type', 'text/plain');
        $deviceSn = $request->input('SN');


        try {
            $bufferData = '';
            $postData = $request->getContent();
            $getFilePathStr = $request->getBasePath() . '/getfile';
            $fileOS = null;
            while (!empty($postData)) {
                $buffer = substr($postData, 0, 1024);
                $postData = substr($postData, 1024);
                $iReadLength = strlen($buffer);
                $inString = $buffer;
                if (str_contains($inString, 'CMD=GetFile')) {
                    $path = $this->processGetFileReturn($inString, $getFilePathStr);
                    if (is_null($path)) {
                        return $response->setContent('OK');
                    }
                    $filePath = $path;
                    $fileOS = new \File($filePath, 'w');
                    $inString = substr($inString, 0, strpos($inString, "Content=") + strlen("Content="));
                    fwrite($fileOS, substr($buffer, strlen($inString), $iReadLength - strlen($inString)));
                } else {
                    if (!is_null($fileOS)) {
                        fwrite($fileOS, substr($buffer, 0, $iReadLength));
                    } else {
                        $bufferData .= substr($buffer, 0, $iReadLength);
                    }
                }
            }
            $data = $bufferData;


            $ret = -1;
            if (str_contains($data, 'CMD=Shell')) {
                $ret = $this->processShellReturn($data);
            } else {
                $ret = $this->updateDeviceCommand($data, $response);
            }

            ManagerFactory::getDeviceManager()->updateDeviceState($deviceSn, "connecting", Carbon::now()->format('Y-m-d H:i:s'));

            if (0 == $ret) {
                return $response->setContent('OK');
            } else {
                return $response->setContent('Error');
            }
        } catch (\Exception $e) {
            Log::error($e);
            return $response->setContent('Error');
        }
    }

    /**
     * Process the return value.
     *
     * @param string $data
     * @param \Illuminate\Http\Response $response
     * @return int
     */
    private function updateDeviceCommand(string $data, Response $response)
    {
        if (null == $data || "" == $data) {
            return -1;
        }

        $devCmdId = 0;
        $cmdReturn = "";
        $cmd = "";
        $deviceSn = "";
        $lines = explode("\n", $data);
        $returnList = [];

        // Processing the return string
        foreach ($lines as $string) {
            // Split by "&"
            if (strpos($string, "ID=") !== false && strpos($string, "Return=") !== false && strpos($string, "CMD=") !== false) {
                $cmdReturns = explode("&", $string);
                foreach ($cmdReturns as $field) {
                    if (strpos($field, "ID") === 0) {
                        try {
                            $devCmdId = (int)substr($field, strlen("ID="));
                        } catch (Exception $e) {
                            return -1;
                        }
                    } else if (strpos($field, "Return") === 0) {
                        $cmdReturn = substr($field, strlen("Return="));
                    } else if (strpos($field, "CMD") === 0) {
                        $cmd = substr($field, strlen("CMD="));
                    }
                }
                $command = ManagerFactory::getCommandManager()->getDeviceCommandById($devCmdId);

                if ($command) {
                    $deviceSn = $command->DEVICE_SN;
                    $command->CMD_OVER_TIME = Carbon::now()->toDateTimeString();
                    $command->CMD_RETURN = $cmdReturn;
                    $command->CMD_RETURN_INFO = $data;

                    $returnList[] = $command;
                }
            } else if (Constants::DEV_CMD_INFO === $cmd) {
                // Command INFO
                $this->updateDeviceInfoDatas($lines, $deviceSn);
                break;
            }
        }

        ManagerFactory::getCommandManager()->updateDeviceCommand($returnList);
        return 0;
    }

    /**
     * Processing the return of command GetFile, create the file.
     *
     * @param string $data
     * @param string $filePath
     * @return null|string
     */
    private function processGetFileReturn(string $data, string $filePath)
    {
        if (is_null($data) || empty($data) || is_null($filePath) || empty($filePath)) {
            return null;
        }

        $lines = explode("\n", $data);
        $devCmdId = 0;
        $cmdReturn = "";
        $cmd = "";
        $deviceSn = "";
        $fileName = "";

        foreach ($lines as $string) {
            if (strpos($string, "ID=") !== false && strpos($string, "Return=") !== false && strpos($string, "CMD=") !== false) {
                $cmdReturns = explode("&", $string);
                foreach ($cmdReturns as $field) {
                    if (Str::startsWith($field, "ID")) {
                        try {
                            $devCmdId = intval(Str::after($field, "ID="));
                        } catch (\Exception $e) {
                            return null;
                        }
                    } else if (Str::startsWith($field, "Return")) {
                        $cmdReturn = Str::after($field, "Return=");
                    } else if (Str::startsWith($field, "CMD")) {
                        $cmd = Str::after($field, "CMD=");
                    }
                }
            } else {
                if (Str::startsWith($string, "ID")) {
                    try {
                        $devCmdId = intval(Str::after($string, "ID="));
                    } catch (\Exception $e) {
                        return null;
                    }
                } else if (Str::startsWith($string, "Return")) {
                    $cmdReturn = Str::after($string, "Return=");
                } else if (Str::startsWith($string, "CMD")) {
                    $cmd = Str::after($string, "CMD=");
                } else if (Str::startsWith($string, "SN")) {
                    $deviceSn = Str::after($string, "SN=");
                } else if (Str::startsWith($string, "FILENAME")) {
                    $fileName = Str::after($string, "FILENAME=");
                }
            }
        }

        // update the device command info
        $command = ManagerFactory::getCommandManager()->getDeviceCommandById($devCmdId);
        if (!is_null($command)) {
            $command->CMD_OVER_TIME = Carbon::now()->toDateTimeString();
            $command->CMD_RETURN = $cmdReturn;
            $command->CMD_RETURN_INFO = $data;
            ManagerFactory::getCommandManager()->updateDeviceCommand([$command]);
        }

        // create the file
        try {
            $fileSnPath = $filePath . DIRECTORY_SEPARATOR . $deviceSn;
            if (!file_exists($fileSnPath) && !is_dir($fileSnPath)) {
                mkdir($fileSnPath, 0777, true);
            }

            if (strpos($fileName, "/") !== false) {
                $dirs = explode("/", $fileName);
                $temp = [];
                for ($i = 0; $i < count($dirs) - 1; $i++) {
                    if (empty($dirs[$i])) {
                        continue;
                    }
                    $temp[] = $dirs[$i];
                }

                $tempFile = $fileSnPath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $temp);
                if (!file_exists($tempFile)) {
                    mkdir($tempFile, 0777, true);
                }
                $fileName = substr($fileName, strrpos($fileName, "/"));
            }

            $file = $fileSnPath . DIRECTORY_SEPARATOR . $fileName;
            if (!file_exists($file)) {
                touch($file);
                return $file;
            } else {
                return null;
            }
        } catch (\Exception $e) {
            Log::error($e);
            return null;
        }
    }

    /**
     * Processing shell return command
     *
     * @param string $data
     * @return int
     */
    private function processShellReturn(string $data)
    {
        if (is_null($data) || empty($data)) {
            return -1;
        }

        $lines = explode("\n", $data);
        $devCmdId = 0;
        $cmdReturn = "";
        $cmd = "";
        $deviceSn = "";

        foreach ($lines as $string) {
            if (Str::startsWith($string, "ID")) {
                try {
                    $devCmdId = intval(Str::after($string, "ID="));
                } catch (\Exception $e) {
                    return -1;
                }
            } else if (Str::startsWith($string, "Return")) {
                $cmdReturn = Str::after($string, "Return=");
            } else if (Str::startsWith($string, "CMD")) {
                $cmd = Str::after($string, "CMD=");
            } else if (Str::startsWith($string, "SN")) {
                $deviceSn = Str::after($string, "SN=");
            }
        }

        $command = ManagerFactory::getCommandManager()->getDeviceCommandById($devCmdId);
        if (!is_null($command)) {
            $command->CMD_OVER_TIME = Carbon::now()->toDateTimeString();
            $command->CMD_RETURN = $cmdReturn;
            $command->CMD_RETURN_INFO = $data;
            ManagerFactory::getCommandManager()->updateDeviceCommand($command);
        }

        return 0;
    }

    /**
     * Updates the device info by the information
     *
     * @param info    the info which is send by device
     * @param devSn    Device SN
     * @return
     */
    private function updateDeviceInfo($info, $devSn): void
    {
        $values = explode(",", $info);

        /**Gets the device info by SN*/
        $deviceInfo = ManagerFactory::getDeviceManager()->getDeviceInfoBySn($devSn);

        if (!$deviceInfo) {
            return;
        }

        // Set the values based on the $values array
        $deviceInfo->FW_VERSION = $values[0]; // firmware version

        try {
            $deviceInfo->USER_COUNT = intval($values[1]); // user count
        } catch (\Exception $e) {
            return;
        }

        try {
            $deviceInfo->FP_COUNT = intval($values[2]); // FP count
        } catch (\Exception $e) {
            return;
        }

        try {
            $deviceInfo->TRANS_COUNT = intval($values[3]); // the count of time attendance logs
        } catch (\Exception $e) {
            return;
        }

        $deviceInfo->IPADDRESS = $values[4]; // IP Address
        $deviceInfo->FP_ALG_VER = $values[5]; // FP algorithm
        $deviceInfo->FACE_ALG_VER = $values[6];

        try {
            $deviceInfo->REG_FACE_COUNT = intval($values[7]);
        } catch (\Exception $e) {
            return;
        }

        try {
            $deviceInfo->FACE_COUNT = intval($values[8]);
        } catch (\Exception $e) {
            return;
        }

        $deviceInfo->DEV_FUNS = $values[9]; // the feature which device can support

        $deviceInfo->STATE = "Online";
        $deviceInfo->LAST_ACTIVITY = Carbon::now()->format('Y-m-d H:i:s'); // Current date time
        $deviceInfo->save();
    }

    /**
     * Update the device info from the return of command INFO
     *
     * @param infoDatas
     * @param deviceSn
     * @return int
     */
    public function updateDeviceInfoDatas($infoDatas, $deviceSn)
    {
        $deviceInfo = ManagerFactory::getDeviceManager()->getDeviceInfoBySn($deviceSn);

        foreach ($infoDatas as $string) {
            if (strpos($string, "UserCount") === 0) {
                $userCount = intval(substr($string, strlen("UserCount=")));
                $deviceInfo->USER_COUNT = $userCount;
            } elseif (strpos($string, "FPCount") === 0) {
                $fpCount = intval(substr($string, strlen("FPCount=")));
                $deviceInfo->FP_COUNT = $fpCount;
            } elseif (strpos($string, "FWVersion") === 0) {
                $fwVersion = substr($string, strlen("FWVersion="));
                $deviceInfo->FW_VERSION = $fwVersion;
                /*} elseif (strpos($string, "PvCount") === 0) {
                    $palm = intval(substr($string, strlen("PvCount=")));
                    $deviceInfo->setPalm($palm);*/
            } elseif (strpos($string, "FaceCount") === 0) {
                $faceCount = intval(substr($string, strlen("FaceCount=")));
                $deviceInfo->FACE_COUNT = $faceCount;
            }
        }

        $deviceInfo->LAST_ACTIVITY = Carbon::now()->format('Y-m-d H:i:s'); // Current date time

        $deviceInfo->save();

        return 0;
    }
}
