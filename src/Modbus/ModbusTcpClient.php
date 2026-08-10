<?php

declare(strict_types=1);

namespace OTGH\AccessControl\ModbusAdapter\Modbus;

use RuntimeException;

class ModbusTcpClient
{
    private int $transactionId = 0;

    /**
     * @return array<int,bool>
     */
    public function readCoils(string $host, int $port, int $unitId, int $startAddress, int $quantity, int $timeoutMs): array
    {
        return $this->readBits(
            host: $host,
            port: $port,
            unitId: $unitId,
            functionCode: 0x01,
            startAddress: $startAddress,
            quantity: $quantity,
            timeoutMs: $timeoutMs,
        );
    }

    /**
     * @return array<int,bool>
     */
    public function readDiscreteInputs(string $host, int $port, int $unitId, int $startAddress, int $quantity, int $timeoutMs): array
    {
        return $this->readBits(
            host: $host,
            port: $port,
            unitId: $unitId,
            functionCode: 0x02,
            startAddress: $startAddress,
            quantity: $quantity,
            timeoutMs: $timeoutMs,
        );
    }

    public function writeSingleCoil(string $host, int $port, int $unitId, int $address, bool $enabled, int $timeoutMs): void
    {
        $value = $enabled ? 0xFF00 : 0x0000;
        $pdu = pack('Cnn', 0x05, $address, $value);
        $responsePdu = $this->sendRequest($host, $port, $unitId, $pdu, $timeoutMs);

        if (strlen($responsePdu) < 5) {
            throw new RuntimeException('Invalid Modbus write response length.');
        }

        $function = ord($responsePdu[0]);
        if ($function === (0x05 | 0x80)) {
            $exceptionCode = ord($responsePdu[1] ?? "\x00");
            throw new RuntimeException('Modbus write exception code '.$exceptionCode.'.');
        }

        if ($function !== 0x05) {
            throw new RuntimeException('Unexpected Modbus function in write response ['.$function.'].');
        }

        $echoAddress = unpack('n', substr($responsePdu, 1, 2))[1] ?? -1;
        if ($echoAddress !== $address) {
            throw new RuntimeException('Modbus write response address mismatch.');
        }
    }

    /**
     * @return array<int,bool>
     */
    private function readBits(
        string $host,
        int $port,
        int $unitId,
        int $functionCode,
        int $startAddress,
        int $quantity,
        int $timeoutMs,
    ): array {
        if ($quantity < 1 || $quantity > 2000) {
            throw new RuntimeException('Modbus read quantity must be between 1 and 2000.');
        }

        $pdu = pack('Cnn', $functionCode, $startAddress, $quantity);
        $responsePdu = $this->sendRequest($host, $port, $unitId, $pdu, $timeoutMs);

        if (strlen($responsePdu) < 3) {
            throw new RuntimeException('Invalid Modbus read response length.');
        }

        $responseFunction = ord($responsePdu[0]);
        if ($responseFunction === ($functionCode | 0x80)) {
            $exceptionCode = ord($responsePdu[1] ?? "\x00");
            throw new RuntimeException('Modbus read exception code '.$exceptionCode.'.');
        }

        if ($responseFunction !== $functionCode) {
            throw new RuntimeException('Unexpected Modbus function in read response ['.$responseFunction.'].');
        }

        $byteCount = ord($responsePdu[1]);
        $bytes = substr($responsePdu, 2);

        if (strlen($bytes) < $byteCount) {
            throw new RuntimeException('Incomplete Modbus read response payload.');
        }

        $bits = [];
        for ($offset = 0; $offset < $quantity; $offset++) {
            $byteIndex = intdiv($offset, 8);
            $bitIndex = $offset % 8;
            $byteValue = ord($bytes[$byteIndex] ?? "\x00");
            $address = $startAddress + $offset;
            $bits[$address] = (($byteValue >> $bitIndex) & 0x01) === 1;
        }

        return $bits;
    }

    private function sendRequest(string $host, int $port, int $unitId, string $pdu, int $timeoutMs): string
    {
        $timeoutSeconds = max(0.1, $timeoutMs / 1000);
        $socket = @stream_socket_client(
            'tcp://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (! is_resource($socket)) {
            throw new RuntimeException(sprintf('Modbus connection failed [%s:%d]: %s (%d)', $host, $port, $errorMessage ?: 'unknown', (int) $errorCode));
        }

        try {
            stream_set_timeout($socket, 0, max(100000, $timeoutMs * 1000));

            $transactionId = $this->nextTransactionId();
            $length = strlen($pdu) + 1;
            $frame = pack('nnnC', $transactionId, 0, $length, $unitId).$pdu;

            $written = fwrite($socket, $frame);
            if ($written !== strlen($frame)) {
                throw new RuntimeException('Failed to write complete Modbus request frame.');
            }

            $header = $this->readExact($socket, 7);
            $headerParts = unpack('ntransaction/nprotocol/nlength/Cunit', $header);
            $protocol = (int) ($headerParts['protocol'] ?? -1);
            $responseLength = (int) ($headerParts['length'] ?? 0);

            if ($protocol !== 0) {
                throw new RuntimeException('Unexpected Modbus protocol identifier ['.$protocol.'].');
            }

            if ($responseLength < 2) {
                throw new RuntimeException('Invalid Modbus response length header.');
            }

            $remaining = $responseLength - 1;
            $responsePdu = $this->readExact($socket, $remaining);

            return $responsePdu;
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function readExact($socket, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($socket, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new RuntimeException('Timed out while reading Modbus response.');
                }

                throw new RuntimeException('Connection closed while reading Modbus response.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function nextTransactionId(): int
    {
        $this->transactionId = ($this->transactionId + 1) % 0x10000;

        return $this->transactionId;
    }
}
