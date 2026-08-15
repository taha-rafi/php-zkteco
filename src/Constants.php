<?php
/**
 * ZKTeco Socket Protocol Constants
 *
 * @package   TahaRafi\ZKTeco
 * @author    Taha Rafi <contact@taharafi.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace TahaRafi\ZKTeco;

class Constants
{
    // Commands
    public const CMD_CONNECT        = 1000;
    public const CMD_EXIT           = 1001;
    public const CMD_ENABLEDEVICE   = 1002;
    public const CMD_DISABLEDEVICE  = 1003;
    public const CMD_RESTART        = 1004;
    public const CMD_POWEROFF       = 1005;
    public const CMD_SLEEP          = 1006;
    public const CMD_RESUME         = 1007;
    public const CMD_TEST_TEMP      = 1011;
    public const CMD_TESTVOICE      = 1017;
    public const CMD_VERSION        = 1100;
    public const CMD_CHANGE_SPEED   = 1101;
    public const CMD_AUTH           = 1102;

    // Acknowledgements
    public const CMD_ACK_OK         = 2000;
    public const CMD_ACK_ERROR      = 2001;
    public const CMD_ACK_DATA       = 2002;
    public const CMD_ACK_UNAUTH     = 2005;
    public const CMD_PREPARE_DATA   = 1500;
    public const CMD_DATA           = 1501;

    // Requests
    public const CMD_USER_WRQ       = 8;
    public const CMD_USERTEMP_RRQ   = 9;
    public const CMD_USERTEMP_WRQ   = 10;
    public const CMD_OPTIONS_RRQ    = 11;
    public const CMD_OPTIONS_WRQ    = 12;
    public const CMD_ATTLOG_RRQ     = 13;
    public const CMD_CLEAR_DATA     = 14;
    public const CMD_CLEAR_ATTLOG   = 15;
    public const CMD_DELETE_USER    = 18;
    public const CMD_DELETE_USERTEMP= 19;
    public const CMD_CLEAR_ADMIN    = 20;
    public const CMD_ENABLE_CLOCK   = 57;
    public const CMD_STARTVERIFY    = 60;
    public const CMD_STARTENROLL    = 61;
    public const CMD_CANCELCAPTURE  = 62;
    public const CMD_STATE_RRQ      = 64;
    public const CMD_WRITE_LCD      = 66;
    public const CMD_CLEAR_LCD      = 67;

    // Time Commands
    public const CMD_GET_TIME       = 201;
    public const CMD_SET_TIME       = 202;

    // Protocol Limits
    public const USHRT_MAX          = 65535;

    // User Roles / Privilege Levels
    public const LEVEL_USER         = 0;
    public const LEVEL_ENROLLER     = 2;
    public const LEVEL_MANAGER      = 12;
    public const LEVEL_SUPERADMIN   = 14;
}
