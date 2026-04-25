# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A minimal, standalone PHP utility for controlling Balboa WiFi spa/hot tubs over TCP. It exposes an HTTP GET API that translates query parameters into binary protocol messages sent to the spa device, then returns JSON status. Designed for integration with FHEM home automation.

No framework, no Composer dependencies, no build system.

## Architecture

Two files do all the work:

- **`index.php`** — HTTP entry point. Parses GET parameters, merges into persistent targets, applies exponential backoff on connect failure, calls `SpaClient` methods, clears confirmed targets, writes `spa_state.json`, outputs JSON.
- **`SpaClient.php`** — Core class. Opens a raw TCP socket to the spa on port 4257, encodes/decodes the Balboa binary protocol, and provides methods for all control operations.
- **`spa_state.json`** — Runtime state in `sys_get_temp_dir()` (i.e. `/tmp`): pending targets (with TTL) and connection-failure backoff metadata. Does not survive reboots — intentional.

**Data flow:** HTTP GET → `index.php` → pending targets merged → connect with backoff → `SpaClient` → TCP binary frame → spa device → binary response → parsed status → confirm/clear targets → JSON output.

## Development Environment

The project runs under **ddev** with PHP 8.2 and Apache 2.4. To execute the script locally:

```bash
ddev exec php public/index.php
```

CLI context has no HTTP server, so `REQUEST_METHOD` is undefined (produces a warning, harmless). Query parameters can be passed by modifying `$_GET` directly in a test wrapper.

To test raw TCP connectivity outside PHP:
```bash
nc -z -w5 192.168.178.127 4257 && echo "open"
```

To decode a live status frame:
```bash
python3 -c "import socket,time; s=socket.socket(); s.settimeout(5); s.connect(('192.168.178.127',4257)); time.sleep(0.5); print(s.recv(256).hex()); s.close()"
```

## Configuration

```php
const SPA_IP = '192.168.178.127';  // index.php line 6
```

## API

All requests are HTTP GET. Multiple parameters can be combined in one request.

| Parameter | Values | Effect |
|-----------|--------|--------|
| `setTemp` | `10`–`37` (0.5° steps) | Set target temperature in °C |
| `setPump1` | `High`, `Low`, anything else = Off | Control pump 1 |
| `setPump2` | `High`, `Low`, anything else = Off | Control pump 2 |
| `setLight` | `On`, anything else = Off | Toggle light |

Response JSON fields:

| Field | Type | Notes |
|-------|------|-------|
| `time` | string | Spa's internal clock, e.g. `"15:51"` |
| `temp` | float | Current water temperature in °C |
| `setTemp` | float | Target temperature in °C |
| `heating` | 0/1 | Whether heater element is active |
| `pump1` | 0/1/2 | Off/Low/High |
| `pump2` | 0/1/2 | Off/Low/High |
| `light` | 0/1 | |
| `priming` | 0/1 | |
| `faultCode` | int | 3 = OK, 0 = offline, see `faultCodeToString()` for full list |
| `faultMessage` | string | German description of fault code |
| `pendingTargets` | object/null | Targets not yet confirmed by spa |
| `retryIn` | string | Only present during backoff, e.g. `"30s"` |

## Pending Targets & Backoff

Commands are stored as *pending targets* in `spa_state.json` with a 15-minute TTL (`TARGET_TTL = 900`). On every request, pending targets are re-applied if the spa's current status doesn't match. Targets are cleared once the spa confirms the new state.

If the spa is unreachable, exponential backoff is applied: 30 s → 60 s → 120 s → 300 s (max). During backoff, requests return an offline response immediately without attempting TCP connection.

## Protocol Notes

### Frame Format

```
7e | LEN | TYPE (3 bytes) | PAYLOAD | CHECKSUM | 7e
```

- `LEN` counts itself + TYPE + PAYLOAD + CHECKSUM (NOT the two `7e` delimiters).
- `readMsg()` reads 2 bytes (`7e` + `LEN`), then reads exactly `LEN` bytes (which includes the trailing `7e` delimiter — this is intentional and keeps framing aligned across back-to-back messages).
- Checksum: CRC-like, computed by `computeChecksum()` over LEN + TYPE + PAYLOAD, XOR-chained with polynomial `0x07`.

### Status Update Message (verified against live device)

Type bytes: `\xff\xaf\x13`

`handleStatusUpdate()` receives the payload after stripping the 3 type bytes. Byte offsets (0-indexed):

| Offset | Content |
|--------|---------|
| 1 | Flags: bit 0 = priming |
| 2 | Current temp raw (0xFF = unknown); °C = value / 2.0 |
| 3 | Hour |
| 4 | Minute |
| 5 | Heating mode: 0=Ready, 1=Rest, 2=Ready in Rest |
| 7 | Fault/sensor code (3 = OK) |
| 9 | Flags: bit 0 = Celsius, bit 1 = 24-hour clock |
| 10 | Flags: bits 4-5 = heating active, bit 2 = High temp range |
| 11 | Pump status: bits 0-1 = pump1, bits 2-3 = pump2 (0=Off,1=Low,2=High) |
| 14 | Light: bits 0-1 = `0x03` means On |
| 20 | Set temp raw; °C = value / 2.0 |

### Commands

| Action | Type bytes | Payload |
|--------|-----------|---------|
| Set temperature | `\x0a\xbf\x20` | `chr(temp_celsius * 2)` |
| Toggle item | `\x0a\xbf\x11` | `chr(item_id) . "\x00"` |
| Set time | `\x0a\xbf\x21` | `chr(hour) . chr(minute)` |
| Config request | `\x0a\xbf\x04` | (empty) |

Toggle item IDs: pump1 = `0x04`, pump2 = `0x05`, light = `0x11`.

Pumps cycle: Off → Low → High → Off. Two toggles (with 2 s delay) are needed when skipping a step.

### Initialisation Sequence

After connecting, `requestConfiguration()` sends 5 requests (`\x94`, `\x24`, `\x25`, `\x2e`, `\x23` with type prefix `\x0a\xbf`) — mirroring pybalboa's `request_all_configuration`. Without this, the spa may not respond with status updates.

## Reference

- HA Balboa integration (used as protocol reference): https://github.com/home-assistant/core/tree/dev/homeassistant/components/balboa
- pybalboa library: https://github.com/garbled1/pybalboa