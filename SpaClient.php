<?php

class SpaClient
{
    /**
     * @var false|resource|Socket
     */
    private $socket;
    private bool $light = false;
    private float $currentTemp = 0;
    private int $hour = 12;
    private int $minute = 0;
    private string $heatingMode = "";
    private string $tempScale = "";
    private string $tempRange = "";
    private string $pump1 = "";
    private string $pump2 = "";
    private float $setTemp = 0;
    private bool $priming = false;
    private string $timeScale = "12 Hr";
    private bool $heating = false;
    private array $status = [];
    private string $spaIp;

    public function __construct($spaIp)
    {
        $this->spaIp = $spaIp;
        $this->socket = $this->getSocket();
        $this->readAllMsg();
    }

    private function getSocket()
    {
        if ($this->socket === null) {
            $this->socket = socket_create(AF_INET, SOCK_STREAM, 0);
            socket_connect($this->socket, $this->spaIp, 4257);
            socket_set_nonblock($this->socket);
        }
        return $this->socket;
    }

    public function handleStatusUpdate($byteArray): void
    {
        $this->priming = (ord($byteArray[1]) & 0x01) == 0;
        $this->hour = ord($byteArray[3]);
        $this->minute = ord($byteArray[4]);
        $heatingModes = array("Ready", "Rest", "Ready in Rest");
        $this->heatingMode = $heatingModes[ord($byteArray[5])];
        $flag3 = ord($byteArray[9]);
        $this->tempScale = (($flag3 & 0x01) == 0) ? "Farenheit" : "Celcius";
        $this->timeScale = (($flag3 & 0x02) == 0) ? "12 Hr" : "24 Hr";
        $flag4 = ord($byteArray[10]);
        $this->heating = ($flag4 & 0x30);
        $this->tempRange = (($flag4 & 0x04) == 0) ? "Low" : "High";
        $pumpStatus = ord($byteArray[11]);
        $pumpLabels = array("Off", "Low", "High");
        $this->pump1 = $pumpLabels[$pumpStatus & 0x03];
        $this->pump2 = $pumpLabels[($pumpStatus >> 2) & 0x03];
        $this->light = (ord($byteArray[14]) & 0x03) == 0x03;
        if (ord($byteArray[2]) == 255) {
            $this->currentTemp = 0.0;
            $this->setTemp = 1.0 * ord($byteArray[20]);
        } elseif ($this->tempScale == 'Celcius') {
            $this->currentTemp = ord($byteArray[2]) / 2.0;
            $this->setTemp = ord($byteArray[20]) / 2.0;
        } else {
            $this->currentTemp = 1.0 * ord($byteArray[2]);
            $this->setTemp = 1.0 * ord($byteArray[20]);
        }
    }

    public function getTemp(): int
    {
        return $this->setTemp;
    }

    public function getPump1(): string
    {
        return $this->pump1;
    }

    public function getPump2(): string
    {
        return $this->pump2;
    }

    public function getTempRange(): string
    {
        return $this->tempRange;
    }

    public function getCurrentTime(): string
    {
        return $this->hour . ':' . sprintf('%02d', $this->minute);
    }

    public function getLight(): bool
    {
        return $this->light;
    }

    public function getCurrentTemp(): int
    {
        return $this->currentTemp;
    }

    public function getStatus(): array
    {
        $this->status["time"] = $this->hour . ':' . sprintf('%02d', $this->minute);
        $this->status["timeScale"] = $this->timeScale;
        $this->status["tempScale"] = $this->tempScale;
        $this->status["priming"] = $this->priming;
        $this->status["temp"] = $this->currentTemp;
        $this->status["setTemp"] = $this->setTemp;
        $this->status["tempRange"] = $this->tempRange;
        $this->status["heatingMode"] = $this->heatingMode;
        $this->status["heating"] = $this->heating;
        $this->status["pump1"] = $this->pump1;
        $this->status["pump2"] = $this->pump2;
        $this->status["light"] = $this->light;
        return $this->status;
    }

    public function getStatusForFhem(): array
    {
        $this->status["time"] = $this->hour . ':' . sprintf('%02d', $this->minute);
        $this->status["priming"] = ($this->priming === true) ? 1 : 0;
        $this->status["temp"] = $this->currentTemp;
        $this->status["setTemp"] = $this->setTemp;
        $this->status["heating"] = ($this->heating === true) ? 1 : 0;
        $this->status["pump1"] = ($this->pump1 === "High") ? 2 : (($this->pump1 === "Low") ? 1 : 0);
        $this->status["pump2"] = ($this->pump2 === "High") ? 2 : (($this->pump2 === "Low") ? 1 : 0);
        $this->status["light"] = ($this->light === true) ? 1 : 0;
        return $this->status;
    }

    function computeChecksum($lenBytes, $bytes): int
    {
        $sum = 0x02;

        $lenBytesLen = strlen($lenBytes);
        for ($i = 0; $i < $lenBytesLen; $i++) {
            $byte = ord($lenBytes[$i]);
            $sum ^= $byte;
            for ($j = 0; $j < 8; $j++) {
                if ($sum & 0x80) {
                    $sum = ($sum << 1) ^ 0x07;
                } else {
                    $sum <<= 1;
                }
            }
        }

        $bytesLen = strlen($bytes);
        for ($i = 0; $i < $bytesLen; $i++) {
            $byte = ord($bytes[$i]);
            $sum ^= $byte;
            for ($j = 0; $j < 8; $j++) {
                if ($sum & 0x80) {
                    $sum = ($sum << 1) ^ 0x07;
                } else {
                    $sum <<= 1;
                }
            }
        }

        return $sum ^ 0x02;
    }

    private function readMsg(): bool
    {
        $chunks = [];
        try {
            $lenChunk = socket_read($this->socket, 2);
        } catch (Exception $e) {
            return false;
        }
        if ($lenChunk == '' || strlen($lenChunk) == 0) {
            return false;
        }
        $length = ord($lenChunk[1]);
        try {
            $chunk = socket_read($this->socket, $length);
        } catch (Exception $e) {
            error_log("Failed to receive: len_chunk: $lenChunk, len: $length");
            return false;
        }
        $chunks[] = $lenChunk;
        $chunks[] = $chunk;

        // Status update prefix
        if (substr($chunk, 0, 3) == "\xff\xaf\x13") {
            $this->handleStatusUpdate(substr($chunk, 3));
        }

        return true;
    }

    public function readAllMsg(): void
    {
        while ($this->readMsg()) {
            continue;
        }
    }

    private function sendMessage($type, $payload)
    {
        $length = 5 + strlen($payload);
        $checksum = $this->computeChecksum(chr($length), $type . $payload);
        $prefix = "\x7e";
        $message = $prefix . chr($length) . $type . $payload . chr($checksum) . $prefix;
        socket_write($this->socket, $message);
    }

    public function sendConfigRequest()
    {
        $this->sendMessage("\x0a\xbf\x04", '');
    }

    public function sendToggleMessage($item)
    {
        $this->sendMessage("\x0a\xbf\x11", chr($item) . "\x00");
    }

    public function setTemperature(float $temp)
    {
        sleep(1);
        $this->readAllMsg(); // Read status first to get current temperature state
        if ($this->setTemp == $temp) {
            return;
        }
        if ($this->tempScale == "Celcius") {
            $dec = $temp * 2;
        } else {
            $dec = $temp;
        }
        $this->setTemp = $temp;
        $this->sendMessage("\x0a\xbf\x20", chr($dec));
    }

    public function setLight($value) {
        if ($this->light == $value) {
            return;
        }
        $this->sendToggleMessage(0x11);
        $this->light = $value;
    }

    public function setNewTime($newHour, $newMinute)
    {
        sleep(1);
        $this->newTime = chr(intval($newHour)) . chr(intval($newMinute));
        $this->sendMessage("\x0a\xbf\x21", $this->newTime);
    }

    public function setPump1($value)
    {
        sleep(1);
        $this->readAllMsg(); // Read status first to get current pump1 state
        if ($this->pump1 == $value) {
            return;
        }
        if ($value == "High" && $this->pump1 == "Off") {
            $this->sendToggleMessage(0x04);
            sleep(2);
            $this->sendToggleMessage(0x04);
        } elseif ($value == "Off" && $this->pump1 == "Low") {
            $this->sendToggleMessage(0x04);
            sleep(2);
            $this->sendToggleMessage(0x04);
        } elseif ($value == "Low" && $this->pump1 == "High") {
            $this->sendToggleMessage(0x04);
            sleep(2);
            $this->sendToggleMessage(0x04);
        } else {
            $this->sendToggleMessage(0x04);
        }
        $this->pump1 = $value;
    }

    public function setPump2($value)
    {
        sleep(1);
        $this->readAllMsg(); // Read status first to get current pump2 state
        if ($this->pump2 == $value) {
            return;
        }
        if ($value == "High" && $this->pump2 == "Off") {
            $this->sendToggleMessage(0x05);
            sleep(2);
            $this->sendToggleMessage(0x05);
        } elseif ($value == "Off" && $this->pump2 == "Low") {
            $this->sendToggleMessage(0x05);
            sleep(2);
            $this->sendToggleMessage(0x05);
        } elseif ($value == "Low" && $this->pump2 == "High") {
            $this->sendToggleMessage(0x05);
            sleep(2);
            $this->sendToggleMessage(0x05);
        } else {
            $this->sendToggleMessage(0x05);
        }
        $this->pump2 = $value;
    }
}