<?php

namespace App\Services\ZKTeco\ProFaceX;

class Constants
{
    /**
     * the label of file config.xml
     */
    /**Configuration file name*/
    const CONFIG_FILE_NAME = "config.xml";
    /**the URL of database connection*/
    const DATABASE_URL = "databaseconnect.url";
    /**user name for database*/
    const DATABASE_USER = "databaseconnect.user";
    /**password for database*/
    const DATABASE_PWD = "databaseconnect.password";
    /**Driver for database*/
    const DATABASE_DRIVER = "databaseconnect.driverclass";
    /**the record count of a single page*/
    const OPTION_PAGE_SIZE = "option.pagesize";
    /**the size of monitor data for a single page*/
    const OPTION_MONIGOR_SIZE = "option.monitorsize";

    /**
     * the Server command and the constant value
     */
    /**Command header*/
    const DEV_CMD_TITLE = "C:";
    /**SHELL command*/
    const DEV_CMD_SHELL = "SHELL %s";
    /**CHECK*/
    const DEV_CMD_CHECK = "CHECK";
    /**CLEAR ATTENDANCE RECORD*/
    const DEV_CMD_CLEAR_LOG = "CLEAR LOG";
    /**CLEAR ATTENDANCE PHOTO*/
    const DEV_CMD_CLEAR_PHOTO = "CLEAR PHOTO";
    /**CLEAR ALL DATA*/
    const DEV_CMD_CLEAR_DATA = "CLEAR DATA";
    /**SEND DEVICE INFO TO SERVER*/
    const DEV_CMD_INFO = "INFO";
    /**SET DEVICE OPTION*/
    const DEV_CMD_SET_OPTION = "SET OPTION %s";
    /**REBOOT DEVICE*/
    const DEV_CMD_REBOOT = "REBOOT";
    /**UPDATE USER INFO*/
    const DEV_CMD_DATA_UPDATE_USERINFO = "DATA UPDATE USERINFO PIN=%s\tName=%s\tPri=%s\tPasswd=%s\tCard=%s\tGrp=%s\tTZ=%s\tCategory=%s";
    /**UPDATE FP TEMPLATE*/
    const DEV_CMD_DATA_UPDATE_FINGER = "DATA UPDATE FINGERTMP PIN=%s\tFID=%s\tSize=%s\tValid=%s\tTMP=%s";
    /**UPFATE FACE TEMPLATE*/
    const DEV_CMD_DATA_UPDATE_FACE = "DATA UPDATE FACE PIN=%s\tFID=%s\tSize=%s\tValid=%s\tTMP=%s";
    /**UPDATE USER PHOTO*/
    const DEV_CMD_DATA_UPDATE_USERPIC = "DATA UPDATE USERPIC PIN=%s\tSize=%s\tContent=%s";
    /**UPDATE BIOPHOTO*/
    const DEV_CMD_DATA_UPDATE_BIOPHOTO = "DATA UPDATE BIOPHOTO PIN=%s\tType=%s\tSize=%s\tContent=%s";
    /**UPDATE BIODATA abhishek*///DATA UPDATE BIODATA Pin=70059\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=9\tMajorVer=58\tMinorVer=7\tFormat=0\tTmp
    const DEV_CMD_DATA_UPDATE_BIODATA = "DATA UPDATE BIODATA Pin=%s\tNo=%s\tIndex=%s\tValid=%s\tDuress=%s\tType=%s\tMajorVer=%s\tMinorVer=%s\tFormat=%s\tTem=%s";
    /**UPDATE SMS*/
    const DEV_CMD_DATA_UPDATE_SMS = "DATA UPDATE SMS MSG=%s\tTAG=%s\tUID=%s\tMIN=%s\tStartTime=%s";
    /**UPDATE USER SMS*/
    const DEV_CMD_DATA_UPDATE_USER_SMS = "DATA UPDATE USER_SMS PIN=%s\tUID=%s";
    /**DELETE USER INFO*/
    const DEV_CMD_DATA_DELETE_USERINFO = "DATA DELETE USERINFO PIN=%s";
    /**DELETE FP TEMPLATE*/
    const DEV_CMD_DATA_DELETE_FINGER = "DATA DELETE FINGERTMP PIN=%s\tFID=%s";
    /**DELETE FACE TEMPLATE*/
    const DEV_CMD_DATA_DELETE_FACE = "DATA DELETE FACE PIN=%s\tFID=%s";
    /**DELETE USER PHOTO*/
    const DEV_CMD_DATA_DELETE_USERPIC = "DATA DELETE USERPIC PIN=%s";
    /**CLEAR USER*/
    const DEV_CMD_DATA_CLEAR_USERINFO = "CLEAR ALL USERINFO";
    /**DELETE SMS*/
    const DEV_CMD_DATA_DELETE_SMS = "DATA DELETE SMS UID=%s";
    /**QUERY ATTENDANCE RECORD*/
    const DEV_CMD_DATA_QUERY_ATTLOG = "DATA QUERY ATTLOG StartTime=%s\tEndTime=%s";
    /**QUERY ATTENDANCE PHOTO*/
    const DEV_CMD_DATA_QUERY_ATTPHOTO = "DATA QUERY ATTPHOTO StartTime=%s\tEndTime=%s";
    /**QUERY USER INFO*/
    const DEV_CMD_DATA_QUERY_USERINFO = "DATA QUERY USERINFO PIN=%s";
    /**QUERY FP BY USER AND FINGER INDEX*/
    const DEV_CMD_DATA_QUERY_FINGERTMP = "DATA QUERY FINGERTMP PIN=%s\tFID=%s";
    /**QUERY FP BY USER ID*/
    const DEV_CMD_DATA_QUERY_FINGERTMP_ALL = "DATA QUERY FINGERTMP PIN=%s";
    /**ONLINE ENROLL USER FP*/
    const DEV_CMD_ENROLL_FP = "ENROLL_FP PIN=%s\tFID=%s\tRETRY=%s\tOVERWRITE=%s";
    /**CHECK AND SEND LOG*/
    const DEV_CMD_LOG = "LOG";
    /**UNLOCK THE DOOR*/
    const DEV_CMD_AC_UNLOCK = "AC_UNLOCK";
    /**CLOSE THE ALARM*/
    const DEV_CMD_AC_UNALARM = "AC_UNALARM";
    /**GET FILE*/
    const DEV_CMD_GET_FILE = "GetFile %s";
    /**SEND FILE*/
    const DEV_CMD_PUT_FILE = "PutFile %s\t%s";
    /**RELOAD DEVICE OPTION*/
    const DEV_CMD_RELOAD_OPTIONS = "RELOAD OPTIONS";
    /**AUTO PROOFREAD ATTENDANCE RECORD*/
    const DEV_CMD_VERIFY_SUM_ATTLOG = "VERIFY SUM ATTLOG StartTime=%s\tEndTime=%s";
    /**UPDATE MEET INFO*/
    const DEV_CMD_DATA_UPDATE_MEETINFO = "DATA UPDATE MEETINFO MetName=%s\tMetStarSignTm=%s\tMetLatSignTm=%s\tEarRetTm=%s\tLatRetTm=%s\tCode=%s\tMetStrTm=%s\tMetEndTm=%s";
    /**DELETE MEET INFO*/
    const DEV_CMD_DATA_DELETE_MEETINFO = "DATA DELETE MEETINFO Code=%s";
    /**UPDATE PERS MEET*/
    const DEV_CMD_DATA_UPDATE_PERSMEET = "UPDATE PERSMEET Code=%s\tPin=%s";
    /**PutAdvFile*/
    const DEV_CMD_DATA_UPDATE_ADV = "PutAdvFile Type=%s\tFileName=%s\tUrl=downloadFile?SN=%s&path=%s";
    /**DelAdvFile*/
    const DEV_CMD_DATA_DELETE_ADV = "DelAdvFile Type=%s\tFileName=%s";
    /**CLEAR ADV FILE*/
    const DEV_CMD_DATA_CLEAR_ADV = "DelAdvFile Type=%s";
    /**CLEAR MEET INFO*/
    const DEV_CMD_DATA_CLEAR_MEET = "CLEAR MEETINFO";
    /**CLEAR PERSMEET INFO*/
    const DEV_CMD_DATA_CLEAR_PERSMEET = "CLEAR PERSMEET";


    const DEV_TABLE_ATTLOG = "ATTLOG";
    const DEV_TABLE_OPLOG = "OPERLOG";
    const DEV_TABLE_ATTPHOTO = "ATTPHOTO";
    const DEV_TABLE_SMS = "SMS";
    const DEV_TABLE_USER_SMS = "USER_SMS";
    const DEV_TABLE_USERINFO = "USERINFO";
    const DEV_TABLE_FINGER_TMP = "FINGERTMP";
    const DEV_TABLE_FACE = "FACE";
    const DEV_TABLE_USERPIC = "USERPIC";

    const system_dev_timeZone = "The Time Zone";
    const system_utc_12 = "(UTC-12)International Date Change Line West";
    const system_utc_11 = "(UTC-11)Coordinated Universal Time-11";
    const system_utc_10 = "(UTC-10)Hawaii";
    const system_utc_9 = "(UTC-9)Alaska";
    const system_utc_8 = "(UTC-8)Pacific time (American and Canada)  Baja California";
    const system_utc_7 = "(UTC-7)La Paz, Massa Rand, The mountain time (American and Canada), Arizona";
    const system_utc_6 = "(UTC-6)Saskatchewan, Central time, Central America";
    const system_utc_5 = "(UTC-5)Bogota, Lima, Quito, Leo Branco, Eastern time, Indiana(East)";
    const system_utc_430 = "(UTC-4:30)Caracas";
    const system_utc_4 = "(UTC-4)Atlantic time, Cuiaba, Georgetown, La Paz, Santiago";
    const system_utc_330 = "(UTC-3:30)Newfoundland";
    const system_utc_3 = "(UTC-3)Brasilia, Buenos Aires, Greenland, Cayenne";
    const system_utc_2 = "(UTC-2)The International Date Line West-02";
    const system_utc_1 = "(UTC-1)Cape Verde Islands, Azores";
    const system_utc_0 = "(UTC)Dublin, Edinburgh, Lisbon, London, The International Date Line West";
    const system_utc1 = "(UTC+1)Amsterdam, Brussels, Sarajevo";
    const system_utc2 = "(UTC+2)Beirut, Damascus, Eastern Europe, Cairo,Athens, Jerusalem";
    const system_utc3 = "(UTC+3)Baghdad, Kuwait, Moscow, St Petersburg,Nairobi";
    const system_utc330 = "(UTC+3:30)Teheran or Tehran";
    const system_utc4 = "(UTC+4)Abu Zabi, Yerevan, Baku, Port Louis, Samarra";
    const system_utc430 = "(UTC+4:30)Kabul";
    const system_utc5 = "(UTC+5)Ashkhabad, Islamabad, Karachi";
    const system_utc530 = "(UTC+5:30)Chennai, Calcutta Mumbai, New Delhi";
    const system_utc545 = "(UTC+5:45)Katmandu";
    const system_utc6 = "(UTC+6)Astana, Dhaka, Novosibirsk";
    const system_utc630 = "(UTC+6:30)Yangon";
    const system_utc7 = "(UTC+7)Bangkok, Hanoi, Jakarta";
    const system_utc8 = "(UTC+8)Beijing, Chinese Taipei, Irkutsk, Ulan Bator";
    const system_utc9 = "(UTC+9)Osaka, Tokyo, Seoul, Yakutsk";
    const system_utc930 = "(UTC+9:30)Adelaide, Darwin";
    const system_utc10 = "(UTC+10)Brisbane, Vladivostok, Guam, Canberra";
    const system_utc11 = "(UTC+11)Jo Kul Dah, Solomon Islands, New Caledonia";
    const system_utc12 = "(UTC+12)Anadyr, Oakland, Wellington, Fiji";
    const system_utc13 = "(UTC+13)Nukualofa, The Samoa Islands";
    const system_utc14 = "(UTC+14)Christmas Island";


    /**
     *Biometric template type
     */
    const BIO_TYPE_GM = 0;//generic
    const BIO_TYPE_FP = 1;//finger print
    const BIO_TYPE_FACE = 2;//FACE
    const BIO_TYPE_VOICE = 3;//VOICE
    const BIO_TYPE_IRIS = 4;//IRIS
    const BIO_TYPE_RETINA = 5;//RETINA
    const BIO_TYPE_PP = 6;//palm print
    const BIO_TYPE_FV = 7;//finger-vein
    const BIO_TYPE_PALM = 8;//PALM
    const BIO_TYPE_VF = 9;//visible face

    /**
     * Biometric algorithm version
     */
    const BIO_VERSION_FP_9 = "9.0";//Finger print version: 9.0
    const BIO_VERSION_FP_10 = "10.0";//Finger print version: 10.0
    const BIO_VERSION_FP_12 = "12.0";//Finger print version: 10.0
    const BIO_VERSION_FACE_5 = "5.0";//Face version: 5.0
    const BIO_VERSION_FACE_7 = "7.0";//Face version: 7.0
    const BIO_VERSION_FV_3 = "3.0";

    /**
     * Biometric data format
     */
    const BIO_DATA_FMT_ZK = 0;//ZK format
    const BIO_DATA_FMT_ISO = 1;//ISO format
    const BIO_DATA_FMT_ANSI = 2;//ANSI format

    /**
     * Language Encoding
     */
    const DEV_LANG_ZH_CN = "83";//chinese

    const DEV_ATTR_CMD_SIZE = "CMD_SIZE";

    /**
     * support feature
     */
    const DEV_FUNS = [
        'FP' => 'FP',
        'FACE' => 'FACE',
        'USERPIC' => 'USERPIC',
        'BIOPHOTO' => 'BIOPHOTO',
        'BIODATA' => 'BIODATA'
    ];
}
