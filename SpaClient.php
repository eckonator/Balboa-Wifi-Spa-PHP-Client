<?php

class SpaClient
{
    /** @var resource|Socket|null */
    private $socket = null;
    private bool $light = false;
    private float $currentTemp = 0;
    private int $hour = 12;
    private int $minute = 0;
    private string $heatingMode = "";
    private string $tempScale = "Celcius";
    private string $tempRange = "";
    private string $pump1 = "Off";
    private string $pump2 = "Off";
    private float $setTemp = 10;
    private bool $priming = false;
    private string $timeScale = "24 Hr";
    private bool $heating = false;
    private array $status = [];
    private string $spaIp;
    private int $faultCode = 0;
    private string $faultMessage = "Spa offline";

    public function __construct(string $spaIp)
    {
        $this->spaIp = $spaIp;
        $this->connect();
        $this->readAllMsg();
    }

    public function __destruct()
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        if ($socket === false) {
            return;
        }
        // SO_SNDTIMEO covers connect() on macOS/BSD as well as sends
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);

        if (!socket_connect($socket, $this->spaIp, 4257)) {
            socket_close($socket);
            return;
        }
        $this->socket = $socket;
    }

    // Sends the standard initialisation requests that legitimate Balboa clients send
    // right after connecting (mirrors pybalboa's request_all_configuration sequence).
    private function requestConfiguration(): void
    {
        // Module identification, system info, setup params, device config, filter cycle
        foreach (["\x94", "\x24", "\x25", "\x2e", "\x23"] as $type) {
            $this->sendMessage("\x0a\xbf" . $type, '');
        }
    }

    // Keep-alive signal (DEVICE_PRESENT). pybalboa sends this when idle for 15 s.
    public function sendDevicePresent(): void
    {
        $this->sendMessage("\x0a\xbf\x04", '');
    }

    // Wait up to $timeoutMs milliseconds for data to become readable on the socket.
    private function waitForData(int $timeoutMs): bool
    {
        if ($this->socket === null) {
            return false;
        }
        $read = [$this->socket];
        $write = null;
        $except = null;
        $result = socket_select($read, $write, $except, intdiv($timeoutMs, 1000), ($timeoutMs % 1000) * 1000);
        return $result !== false && $result > 0;
    }

    private function readMsg(): bool
    {
        if (!$this->waitForData(500)) {
            return false;
        }
        $lenChunk = socket_read($this->socket, 2);
        if ($lenChunk === false || strlen($lenChunk) < 2) {
            return false;
        }
        $length = ord($lenChunk[1]);
        $chunk = socket_read($this->socket, $length);
        if ($chunk === false || strlen($chunk) === 0) {
            return false;
        }
        if (substr($chunk, 0, 3) === "\xff\xaf\x13") {
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

    // After sending a command, wait up to 1500ms for the spa to respond, then drain all messages.
    private function waitAndRead(): void
    {
        if ($this->waitForData(1500)) {
            $this->readAllMsg();
        }
    }

    public function handleStatusUpdate($byteArray): void
    {
        $this->priming = (ord($byteArray[1]) & 0x01) == 1;
        $this->hour = ord($byteArray[3]);
        $this->minute = ord($byteArray[4]);
        $heatingModes = ["Ready", "Rest", "Ready in Rest"];
        $this->heatingMode = $heatingModes[ord($byteArray[5])] ?? "";
        $flag3 = ord($byteArray[9]);
        $this->tempScale = (($flag3 & 0x01) == 0) ? "Farenheit" : "Celcius";
        $this->timeScale = (($flag3 & 0x02) == 0) ? "12 Hr" : "24 Hr";
        $flag4 = ord($byteArray[10]);
        $this->heating = (bool)($flag4 & 0x30);
        $this->tempRange = (($flag4 & 0x04) == 0) ? "Low" : "High";
        $pumpStatus = ord($byteArray[11]);
        $pumpLabels = ["Off", "Low", "High"];
        $this->pump1 = $pumpLabels[$pumpStatus & 0x03] ?? "Off";
        $this->pump2 = $pumpLabels[($pumpStatus >> 2) & 0x03] ?? "Off";
        $this->light = (ord($byteArray[14]) & 0x03) === 0x03;
        $this->faultCode = ord($byteArray[7]);
        $this->faultMessage = $this->faultCodeToString($this->faultCode);
        if (ord($byteArray[2]) === 255) {
            $this->faultCode = 99;
            $this->faultMessage = $this->faultCodeToString(99);
            $this->currentTemp = 0.0;
            $this->setTemp = $this->tempScale === 'Celcius' ? ord($byteArray[20]) / 2.0 : ord($byteArray[20]);
        } elseif ($this->tempScale === 'Celcius') {
            $this->currentTemp = ord($byteArray[2]) / 2.0;
            $this->setTemp = ord($byteArray[20]) / 2.0;
        } else {
            $this->currentTemp = ord($byteArray[2]);
            $this->setTemp = ord($byteArray[20]);
        }
    }

    private function faultCodeToString(int $code): string
    {
        $messages = [
            0  => "Spa offline",
            3  => "Spa OK",
            15 => "Sensoren sind möglicherweise nicht synchronisiert",
            16 => "Geringer Wasserfluss",
            17 => "Kein Wasserfluss",
            19 => "Priming (dies ist eigentlich kein Fehler - Ihr Spa wurde kürzlich eingeschaltet)",
            20 => "Die Uhr ist ausgefallen",
            21 => "Die Einstellungen wurden zurückgesetzt (Fehler im dauerhaften Speicher)",
            22 => "Fehler im Programmspeicher",
            26 => "Sensoren sind nicht synchronisiert - rufen Sie den Service an",
            27 => "Der Heizstab ist trocken",
            28 => "Der Heizstab könnte trocken sein",
            29 => "Das Wasser ist zu heiß",
            30 => "Der Heizstab ist zu heiß",
            31 => "Fehler bei Sensor A",
            32 => "Fehler bei Sensor B",
            33 => "Sicherheitsabschaltung - Blockierung der Pumpensaugleitung",
            34 => "Eine Pumpe könnte feststecken",
            35 => "Heißer Fehler",
            36 => "Der GFCI-Test ist fehlgeschlagen",
            37 => "Haltemodus aktiviert (dies ist eigentlich kein Fehler)",
            99 => "Unbekannte Ist-Temperatur",
        ];
        return $messages[$code] ?? "Unbekannter Fehlercode - Überprüfen Sie die Balboa-Spa-Handbücher";
    }

    public function getTemp(): float
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

    public function getCurrentTemp(): float
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
        $this->status["faultCode"] = $this->faultCode;
        $this->status["faultMessage"] = $this->faultMessage;
        return $this->status;
    }

    public function getStatusForFhem(): array
    {
        $this->status["time"] = $this->hour . ':' . sprintf('%02d', $this->minute);
        $this->status["priming"] = $this->priming ? 1 : 0;
        $this->status["temp"] = $this->currentTemp;
        $this->status["setTemp"] = $this->setTemp;
        $this->status["heating"] = $this->heating ? 1 : 0;
        $this->status["pump1"] = $this->pump1 === "High" ? 2 : ($this->pump1 === "Low" ? 1 : 0);
        $this->status["pump2"] = $this->pump2 === "High" ? 2 : ($this->pump2 === "Low" ? 1 : 0);
        $this->status["light"] = $this->light ? 1 : 0;
        $this->status["faultCode"] = $this->faultCode;
        $this->status["faultMessage"] = $this->faultMessage;
        return $this->status;
    }

    private function computeChecksum(string $lenBytes, string $bytes): int
    {
        $sum = 0x02;
        foreach (str_split($lenBytes . $bytes) as $char) {
            $sum ^= ord($char);
            for ($j = 0; $j < 8; $j++) {
                $sum = ($sum & 0x80) ? (($sum << 1) ^ 0x07) : ($sum << 1);
            }
        }
        return $sum ^ 0x02;
    }

    private function sendMessage(string $type, string $payload, bool $reread = false): void
    {
        if ($this->socket === null) {
            return;
        }
        $length = 5 + strlen($payload);
        $checksum = $this->computeChecksum(chr($length), $type . $payload);
        $message = "\x7e" . chr($length) . $type . $payload . chr($checksum) . "\x7e";
        socket_write($this->socket, $message);
        if ($reread) {
            $this->waitAndRead();
        }
    }

    public function sendConfigRequest(): void
    {
        $this->sendMessage("\x0a\xbf\x04", '');
    }

    public function sendToggleMessage(int $item): void
    {
        $this->sendMessage("\x0a\xbf\x11", chr($item) . "\x00", true);
    }

    public function setTemperature(float $temp): void
    {
        $this->readAllMsg();
        if ($this->setTemp == $temp || $this->faultCode !== 3) {
            return;
        }
        $dec = $this->tempScale === 'Celcius' ? (int)($temp * 2) : (int)$temp;
        $this->sendMessage("\x0a\xbf\x20", chr($dec), true);
    }

    public function setLight(bool $value): void
    {
        $this->readAllMsg();
        if ($this->light === $value || $this->faultCode !== 3) {
            return;
        }
        $this->sendToggleMessage(0x11);
    }

    public function setNewTime(int $newHour, int $newMinute): void
    {
        if ($this->faultCode !== 3) {
            return;
        }
        $this->sendMessage("\x0a\xbf\x21", chr($newHour) . chr($newMinute), true);
    }

    public function setPump1(string $value): void
    {
        $this->readAllMsg();
        if ($this->pump1 === $value || $this->faultCode !== 3) {
            return;
        }
        // Pump cycles: Off → Low → High → Off. Two toggles needed when target is two steps ahead.
        $needsDoubleToggle = ($value === 'High' && $this->pump1 === 'Off')
            || ($value === 'Off'  && $this->pump1 === 'Low')
            || ($value === 'Low'  && $this->pump1 === 'High');

        $this->sendToggleMessage(0x04);
        if ($needsDoubleToggle) {
            usleep(2000000);
            $this->sendToggleMessage(0x04);
        }
    }

    public function setPump2(string $value): void
    {
        $this->readAllMsg();
        if ($this->pump2 === $value || $this->faultCode !== 3) {
            return;
        }
        $needsDoubleToggle = ($value === 'High' && $this->pump2 === 'Off')
            || ($value === 'Off'  && $this->pump2 === 'Low')
            || ($value === 'Low'  && $this->pump2 === 'High');

        $this->sendToggleMessage(0x05);
        if ($needsDoubleToggle) {
            usleep(2000000);
            $this->sendToggleMessage(0x05);
        }
    }
}