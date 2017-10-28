<?php
namespace Fiber\Mysql;

use Fiber\Helper as f;

class Connection
{
    const CLIENT_LONG_FLAG = 0x00000004;
    const CLIENT_CONNECT_WITH_DB = 0x00000008;
    const CLIENT_COMPRESS = 0x00000020;
    const CLIENT_PROTOCOL_41 = 0x00000200;
    const CLIENT_SSL = 0x00000800;
    const CLIENT_TRANSACTIONS = 0x00002000;
    const CLIENT_SECURE_CONNECTION = 0x00008000;
    const CLIENT_MULTI_STATEMENTS = 0x00010000;
    const CLIENT_MULTI_RESULTS = 0x00020000;
    const CLIENT_PS_MULTI_RESULTS = 0x00040000;
    const CLIENT_PLUGIN_AUTH = 0x00080000;
    const CLIENT_CONNECT_ATTRS = 0x00100000;
    const CLIENT_SESSION_TRACK = 0x00800000;
    const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;
    const CLIENT_DEPRECATE_EOF = 0x01000000;

    const OK_PACKET = 0x00;
    const EXTRA_AUTH_PACKET = 0x01;
    const LOCAL_INFILE_REQUEST = 0xfb;
    const EOF_PACKET = 0xfe;
    const ERR_PACKET = 0xff;

    const UNCONNECTED = 0;
    const ESTABLISHED = 1;
    const READY = 2;
    const CLOSING = 3;
    const CLOSED = 4;

    private $config;
    private $connInfo;
    private $authPluginName;
    private $authPluginData;
    private $authPluginDataLen;
    private $serverCapabilities;
    private $seqId;
    private $protocol;
    private $connectionId;
    private $fd;

    /**
     * @var \Generator
     */
    private $parser;

    private $capabilities = self::CLIENT_SESSION_TRACK
        | self::CLIENT_TRANSACTIONS
        | self::CLIENT_PROTOCOL_41
        | self::CLIENT_SECURE_CONNECTION
        | self::CLIENT_MULTI_RESULTS
        | self::CLIENT_PS_MULTI_RESULTS
        | self::CLIENT_MULTI_STATEMENTS
        | self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;

    public function __construct(Config $config)
    {
        $this->connInfo = new \stdclass;
        $this->config = $config;
        $this->parser = $this->parseMysql();
    }

    private function connect()
    {
        if ($this->fd) {
            return;
        }

        $this->fd = f\connect("tcp://{$this->config->host}:{$this->config->port}");
        $this->handshake();
    }

    private function handshake()
    {
        $buf = f\read0($this->fd, 1024);
        $packets = $this->parser->send($buf);

        $this->handleHandshake($packets[0]);
        $packet = $this->sendHandshake();
        $packet = $this->compilePacket($packet);
        $len = f\write($this->fd, $packet);

        $buf =f\read0($this->fd, 1024);
        $packets = $this->parser->send($buf);
        $packet = $packets[0];

        $this->handleCommonPacket($packet);
    }

    private function parseMysql(): \Generator
    {
        $buf = "";
        $parsed = [];

        while (true) {
            $packet = "";

            do {
                while (\strlen($buf) < 4) {
                    $buf .= yield $parsed;
                    $parsed = [];
                }

                $len = DataTypes::decode_unsigned24($buf);
                $this->seqId = ord($buf[3]);
                $buf = substr($buf, 4);

                while (\strlen($buf) < ($len & 0xffffff)) {
                    $buf .= yield $parsed;
                    $parsed = [];
                }

                $lastIn = $len != 0xffffff;
                if ($lastIn) {
                    $size = $len % 0xffffff;
                } else {
                    $size = 0xffffff;
                }

                $packet .= substr($buf, 0, $size);
                $buf = substr($buf, $size);
            } while (!$lastIn);

            if (\strlen($packet) > 0) {
                $parsed[] = $packet;
            }
        }
    }

    public function query(string $sql): \Iterator
    {
        if (stripos($sql, 'select') !== 0) {
            throw new Exception("invalid sql: '$sql'");
        }

        $this->connect();

        $packet = $this->compilePacket("\x03$sql");
        $len = f\write($this->fd, $packet);

        $buf =f\read0($this->fd, 4096);
        $packets = $this->parser->send($buf);

        $packet = array_shift($packets);
        $num = $column_num = DataTypes::decode_int8($packet[0]);

        while (count($packets) < $column + 1) {
            $buf =f\read0($this->fd, 4096);
            $packets = array_merge($packets, $this->parser->send($buf));
        }

        $result = new Result;

        do {
            $packet = array_shift($packets);
            $column = $this->parseColumnDefinition($packet);
            $result->addColumn($column);
        } while (--$num);

        $packet = array_shift($packets);
        $this->parseEof($packet);


        for (;;) {
            while (!count($packets)) {
                $buf =f\read0($this->fd, 4096);
                $packets = array_merge($packets, $this->parser->send($buf));
            }

            $packet = array_shift($packets);
            if (ord($packet) === self::EOF_PACKET) {
                $this->parseEof($packet);
                break;
            }

            $row = [];
            $off = 0;
            for ($j = 0; $j < $column_num; $j++) {
                $row[] = DataTypes::decodeStringOff($packet, $off);
            }

            $result->addRow($row);
        }

        return $result;
    }

    public function exec(string $sql)
    {
        if (stripos($sql, 'select') === 0) {
            throw new Exception("invalid sql: '$sql'");
        }

        $this->connect();

        $packet = $this->compilePacket("\x03$sql");
        $len = f\write($this->fd, $packet);
        $buf =f\read0($this->fd, 4096);
        $packets = $this->parser->send($buf);

        $this->handleCommonPacket($packets[0]);
    }

    public function ping()
    {
        $this->connect();
        $packet = $this->compilePacket("\x0e");
        f\write($this->fd, $packet);

        $buf =f\read0($this->fd, 4096);
        $packets = $this->parser->send($buf);

        $this->handleCommonPacket($packets[0]);
    }

    private function handleHandshake($packet)
    {
        $off = 1;

        $this->protocol = ord($packet);
        if ($this->protocol !== 0x0a) {
            throw new \UnexpectedValueException("Unsupported protocol version ".ord($packet)." (Expected: 10)");
        }

        $this->connInfo->serverVersion = DataTypes::decodeNullString(substr($packet, $off), $len);
        $off += $len + 1;

        $this->connectionId = DataTypes::decode_unsigned32(substr($packet, $off));
        $off += 4;

        $this->authPluginData = substr($packet, $off, 8);
        $off += 8;

        $off += 1; // filler byte

        $this->serverCapabilities = DataTypes::decode_unsigned16(substr($packet, $off));
        $off += 2;

        if (\strlen($packet) > $off) {
            $this->connInfo->charset = ord(substr($packet, $off));
            $off += 1;

            $this->connInfo->statusFlags = DataTypes::decode_unsigned16(substr($packet, $off));
            $off += 2;

            $this->serverCapabilities += DataTypes::decode_unsigned16(substr($packet, $off)) << 16;
            $off += 2;

            $this->authPluginDataLen = $this->serverCapabilities & self::CLIENT_PLUGIN_AUTH ? ord(substr($packet, $off)) : 0;
            $off += 1;

            if ($this->serverCapabilities & self::CLIENT_SECURE_CONNECTION) {
                $off += 10;

                $strlen = max(13, $this->authPluginDataLen - 8);
                $this->authPluginData .= substr($packet, $off, $strlen);
                $off += $strlen;

                if ($this->serverCapabilities & self::CLIENT_PLUGIN_AUTH) {
                    $this->authPluginName = DataTypes::decodeNullString(substr($packet, $off));
                }
            }
        }
    }

    private function sendHandshake()
    {
        if ($this->config->db !== null) {
            $this->capabilities |= self::CLIENT_CONNECT_WITH_DB;
        }

        $this->capabilities &= $this->serverCapabilities;

        $payload = "";
        $payload .= pack("V", $this->capabilities);
        $payload .= pack("V", 1 << 24 - 1); // max-packet size
        $payload .= chr($this->config->binCharset);
        $payload .= str_repeat("\0", 23); // reserved

        $payload .= $this->config->user."\0";
        if ($this->config->pass == "") {
            $auth = "";
        } elseif ($this->capabilities & self::CLIENT_PLUGIN_AUTH) {
            switch ($this->authPluginName) {
            case "mysql_native_password":
                $auth = $this->secureAuth($this->config->pass, $this->authPluginData);
                break;
            default:
                throw new \UnexpectedValueException("Invalid (or unimplemented?) auth method requested by server: {$this->authPluginName}");
            }
        } else {
            $auth = $this->secureAuth($this->config->pass, $this->authPluginData);
        }
        if ($this->capabilities & self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA) {
            $payload .= DataTypes::encodeInt(strlen($auth));
            $payload .= $auth;
        } elseif ($this->capabilities & self::CLIENT_SECURE_CONNECTION) {
            $payload .= chr(strlen($auth));
            $payload .= $auth;
        } else {
            $payload .= "$auth\0";
        }
        if ($this->capabilities & self::CLIENT_CONNECT_WITH_DB) {
            $payload .= "{$this->config->db}\0";
        }
        if ($this->capabilities & self::CLIENT_PLUGIN_AUTH) {
            $payload .= "\0"; // @TODO AUTH
            // $payload .= "mysql_native_password\0";
        }
        if ($this->capabilities & self::CLIENT_CONNECT_ATTRS) {
            // connection attributes?! 5.6.6+ only!
        }

        return $payload;
    }

    private function secureAuth($pass, $scramble)
    {
        $hash = sha1($pass, 1);
        return $hash ^ sha1(substr($scramble, 0, 20) . sha1($hash, 1), 1);
    }

    private function compilePacket($pending)
    {
        if ($pending == "") {
            return $pending;
        }

        $packet = "";
        do {
            $len = strlen($pending);
            if ($len >= (1 << 24) - 1) {
                $out = substr($pending, 0, (1 << 24) - 1);
                $pending = substr($pending, (1 << 24) - 1);
                $len = (1 << 24) - 1;
            } else {
                $out = $pending;
                $pending = "";
            }
            $packet .= substr_replace(pack("V", $len), chr(++$this->seqId), 3, 1) . $out; // expects $len < (1 << 24) - 1
        } while ($pending != "");

        return $packet;
    }

    private function parseOk($packet)
    {
        $off = 1;

        $this->connInfo->affectedRows = DataTypes::decodeUnsigned(substr($packet, $off), $intlen);
        $off += $intlen;

        $this->connInfo->insertId = DataTypes::decodeUnsigned(substr($packet, $off), $intlen);
        $off += $intlen;

        $this->connInfo->statusFlags = DataTypes::decode_unsigned16(substr($packet, $off));
        $off += 2;

        $this->connInfo->warnings = DataTypes::decode_unsigned16(substr($packet, $off));
        $off += 2;

        if ($this->capabilities & self::CLIENT_SESSION_TRACK) {
            // Even though it seems required according to 14.1.3.1, there is no length encoded string, i.e. no trailing NULL byte ....???
            if (\strlen($packet) > $off) {
                $this->connInfo->statusInfo = DataTypes::decodeStringOff($packet, $off);

                if ($this->connInfo->statusFlags & StatusFlags::SERVER_SESSION_STATE_CHANGED) {
                    $sessionState = DataTypes::decodeString(substr($packet, $off), $intlen, $sessionStateLen);
                    $len = 0;
                    while ($len < $sessionStateLen) {
                        $data = DataTypes::decodeString(substr($sessionState, $len + 1), $datalen, $intlen);

                        switch ($type = DataTypes::decode_unsigned8(substr($sessionState, $len))) {
                        case SessionStateTypes::SESSION_TRACK_SYSTEM_VARIABLES:
                            $var = DataTypes::decodeString($data, $varintlen, $strlen);
                            $this->connInfo->sessionState[SessionStateTypes::SESSION_TRACK_SYSTEM_VARIABLES][$var] = DataTypes::decodeString(substr($data, $varintlen + $strlen));
                            break;
                        case SessionStateTypes::SESSION_TRACK_SCHEMA:
                            $this->connInfo->sessionState[SessionStateTypes::SESSION_TRACK_SCHEMA] = DataTypes::decodeString($data);
                            break;
                        case SessionStateTypes::SESSION_TRACK_STATE_CHANGE:
                            $this->connInfo->sessionState[SessionStateTypes::SESSION_TRACK_STATE_CHANGE] = DataTypes::decodeString($data);
                            break;
                        default:
                            throw new \UnexpectedValueException("$type is not a valid mysql session state type");
                        }

                        $len += 1 + $intlen + $datalen;
                    }
                }
            } else {
                $this->connInfo->statusInfo = "";
            }
        } else {
            $this->connInfo->statusInfo = substr($packet, $off);
        }
    }

    private function parseEof($packet)
    {
        $this->connInfo->warnings = DataTypes::decode_unsigned16(substr($packet, 1));
        $this->connInfo->statusFlags = DataTypes::decode_unsigned16(substr($packet, 3));
    }

    private function handleError($packet)
    {
        $off = 1;

        $code = DataTypes::decode_unsigned16(substr($packet, $off, 2));

        $state = '';
        if ($this->capabilities & self::CLIENT_PROTOCOL_41) {
            $state = substr($packet, $off, 8);
            $off += 8;
        }

        $msg = substr($packet, $off);

        throw new Exception($msg, $code, $state);
    }

    private function parseColumnDefinition($packet)
    {
        $off = 0;

        $column = [];

        $column["catalog"] = DataTypes::decodeStringOff($packet, $off);
        $column["schema"] = DataTypes::decodeStringOff($packet, $off);
        $column["table"] = DataTypes::decodeStringOff($packet, $off);
        $column["original_table"] = DataTypes::decodeStringOff($packet, $off);
        $column["name"] = DataTypes::decodeStringOff($packet, $off);
        $column["original_name"] = DataTypes::decodeStringOff($packet, $off);
        $fixlen = DataTypes::decodeUnsignedOff($packet, $off);

        $len = 0;
        $column["charset"] = DataTypes::decode_unsigned16(substr($packet, $off + $len));
        $len += 2;
        $column["columnlen"] = DataTypes::decode_unsigned32(substr($packet, $off + $len));
        $len += 4;
        $column["type"] = ord($packet[$off + $len]);
        $len += 1;
        $column["flags"] = DataTypes::decode_unsigned16(substr($packet, $off + $len));
        $len += 2;
        $column["decimals"] = ord($packet[$off + $len]);
        //$len += 1;

        $off += $fixlen;

        if ($off < \strlen($packet)) {
            $column["defaults"] = DataTypes::decodeString(substr($packet, $off));
        }

        return $column;
    }

    private function handleCommonPacket($packet)
    {
        switch (ord($packet)) {
        case self::OK_PACKET:
            $this->parseOk($packet);
            $this->seqId = -1;
            break;
        case self::ERR_PACKET:
            $this->handleError($packet);
            break;
        default:
            $type = DataTypes::decode_int8($packet[0]);
            throw new Exception("Unsupported packet of type $type");
        }
    }

    public function lastInsertId(): int
    {
        return $this->connInfo->insertId;
    }

    public function affectedRows(): int
    {
        return $this->connInfo->affectedRows;
    }
}
