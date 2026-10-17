<?php
/**
 * ZKTeco Socket Protocol Constants
 *
 * @package   TahaRafi\ZKTeco
 * @author    Taha Rafi <connect@taharafi.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace TahaRafi\ZKTeco;

class Constants
{
    // Protocol Modes
    public const PROTOCOL_AUTO      = 'auto';
    public const PROTOCOL_TCP       = 'tcp';
    public const PROTOCOL_UDP       = 'udp';

    // TCP Magic Header Bytes (\x50\x50\x82\x7d)
    public const TCP_MAGIC_HEADER   = "\x50\x50\x82\x7d";

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
    public const CMD_REFRESHDATA    = 1013;
    public const CMD_REFRESHOPTION  = 1014;
    public const CMD_TESTVOICE      = 1017;
    public const CMD_VERSION        = 1100;
    public const CMD_CHANGE_SPEED   = 1101;
    public const CMD_AUTH           = 1102;

    // Acknowledgements & Streaming
    public const CMD_ACK_OK         = 2000;
    public const CMD_ACK_ERROR      = 2001;
    public const CMD_ACK_DATA       = 2002;
    public const CMD_ACK_RETRY      = 2003;
    public const CMD_ACK_REPEAT     = 2004;
    public const CMD_ACK_UNAUTH     = 2005;
    public const CMD_ACK_UNKNOWN    = 65535;
    public const CMD_PREPARE_DATA   = 1500;
    public const CMD_DATA           = 1501;
    public const CMD_FREE_DATA      = 1502;
    public const CMD_PREPARE_BUFFER = 1503;
    public const CMD_READ_BUFFER    = 1504;
    public const CMD_UPDATEFILE     = 1700;
    public const CMD_READFILE       = 1702;

    // Direct Hardware Database Write & Selective Delete Commands
    public const CMD_SET_DATA              = 3001;
    public const CMD_TIME_ATTLOG_DELE      = 10005;
    public const CMD_TIMEPOINT_ATTLOG_DELE = 10006;

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
    public const CMD_UNLOCK         = 31;
    public const CMD_ENABLE_CLOCK   = 57;
    public const CMD_STARTVERIFY    = 60;
    public const CMD_STARTENROLL    = 61;
    public const CMD_CANCELCAPTURE  = 62;
    public const CMD_STATE_RRQ      = 64;
    public const CMD_WRITE_LCD      = 66;
    public const CMD_CLEAR_LCD      = 67;
    public const CMD_GET_PINWIDTH   = 69;
    public const CMD_REG_EVENT      = 500;

    // Time Commands
    public const CMD_GET_TIME       = 201;
    public const CMD_SET_TIME       = 202;

    // Real-Time Event Flags (CMD_REG_EVENT)
    public const EF_ATTLOG          = 1;
    public const EF_FINGER          = 2;
    public const EF_ENROLLUSER      = 4;
    public const EF_ENROLLFINGER    = 8;
    public const EF_BUTTON          = 16;
    public const EF_UNLOCK          = 32;
    public const EF_VERIFY          = 128;
    public const EF_FPFTR           = 256;
    public const EF_ALARM           = 512;
    public const EF_ALL             = 65535;

    // Attendance States (Check-In / Check-Out)
    public const ATT_STATE_CHECK_IN     = 0;
    public const ATT_STATE_CHECK_OUT    = 1;
    public const ATT_STATE_BREAK_OUT    = 2;
    public const ATT_STATE_BREAK_IN     = 3;
    public const ATT_STATE_OVERTIME_IN  = 4;
    public const ATT_STATE_OVERTIME_OUT = 5;

    // Biometric Verification Types
    public const VERIFY_PASSWORD    = 0;
    public const VERIFY_FINGERPRINT = 1;
    public const VERIFY_CARD        = 2;
    public const VERIFY_FACE        = 15;
    public const VERIFY_PALM        = 25;

    // Protocol Limits
    public const USHRT_MAX          = 65535;

    // User Roles / Privilege Levels
    public const LEVEL_USER         = 0;
    public const LEVEL_ENROLLER     = 2;
    public const LEVEL_MANAGER      = 12;
    public const LEVEL_SUPERADMIN   = 14;

    /**
     * Human-readable label for attendance punch state
     */
    public static function getStateLabel(int $state): string
    {
        return match ($state) {
            self::ATT_STATE_CHECK_IN, 255 => 'Check-In',
            self::ATT_STATE_CHECK_OUT     => 'Check-Out',
            self::ATT_STATE_BREAK_OUT     => 'Break-Out',
            self::ATT_STATE_BREAK_IN      => 'Break-In',
            self::ATT_STATE_OVERTIME_IN   => 'Overtime-In',
            self::ATT_STATE_OVERTIME_OUT  => 'Overtime-Out',
            default                       => 'Unknown (' . $state . ')',
        };
    }

    /**
     * Human-readable label for biometric verification method
     */
    public static function getVerifyLabel(int $verifyType): string
    {
        return match ($verifyType) {
            self::VERIFY_PASSWORD    => 'Password',
            self::VERIFY_FINGERPRINT => 'Fingerprint',
            self::VERIFY_CARD        => 'RFID Card',
            self::VERIFY_FACE        => 'Face',
            self::VERIFY_PALM        => 'Palm',
            default                  => 'Biometric (' . $verifyType . ')',
        };
    }
}
