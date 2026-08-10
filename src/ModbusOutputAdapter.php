<?php

declare(strict_types=1);

namespace OTGH\AccessControl\ModbusAdapter;

use OTGH\AccessControl\Core\Services\AccessControl\OutputAdapterInterface;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusTcpClient;
use RuntimeException;

class ModbusOutputAdapter implements OutputAdapterInterface
{
    public function __construct(private readonly ModbusTcpClient $client) {}

    public function type(): string
    {
        return 'modbus';
    }

    /**
     * @param  array<string,mixed>  $bindingConfig
     */
    public function read(string $channel, array $bindingConfig = []): mixed
    {
        $runtime = $this->resolveRuntimeConfig($bindingConfig);
        $address = $this->resolveAddress($channel, (int) $runtime['coil_start_address']);

        $states = $this->client->readCoils(
            host: (string) $runtime['host'],
            port: (int) $runtime['port'],
            unitId: (int) $runtime['unit_id'],
            startAddress: $address,
            quantity: 1,
            timeoutMs: (int) $runtime['timeout_ms'],
        );

        return ($states[$address] ?? false) ? 1 : 0;
    }

    /**
     * @param  array<string,mixed>  $bindingConfig
     */
    public function write(string $channel, mixed $value, array $bindingConfig = []): void
    {
        $runtime = $this->resolveRuntimeConfig($bindingConfig);
        $address = $this->resolveAddress($channel, (int) $runtime['coil_start_address']);
        $enabled = $this->toBool($value);

        $this->client->writeSingleCoil(
            host: (string) $runtime['host'],
            port: (int) $runtime['port'],
            unitId: (int) $runtime['unit_id'],
            address: $address,
            enabled: $enabled,
            timeoutMs: (int) $runtime['timeout_ms'],
        );
    }

    /**
     * @param  array<string,mixed>  $bindingConfig
     * @return array{host:string,port:int,unit_id:int,timeout_ms:int,coil_start_address:int}
     */
    private function resolveRuntimeConfig(array $bindingConfig): array
    {
        $modbus = data_get($bindingConfig, 'modbus');
        $config = is_array($modbus) ? $modbus : $bindingConfig;

        $host = $this->nullableString(data_get($config, 'host'));
        if ($host === null) {
            throw new RuntimeException('Modbus binding is missing host configuration.');
        }

        return [
            'host' => $host,
            'port' => $this->boundedInt(data_get($config, 'port', 502), 1, 65535, 502),
            'unit_id' => $this->boundedInt(data_get($config, 'unit_id', 1), 1, 255, 1),
            'timeout_ms' => $this->boundedInt(data_get($config, 'timeout_ms', 3000), 100, 120000, 3000),
            'coil_start_address' => $this->boundedInt(data_get($config, 'coil_start_address', 0), 0, 65535, 0),
        ];
    }

    private function resolveAddress(string $channel, int $coilStartAddress): int
    {
        $trimmed = trim($channel);

        if (str_starts_with(strtolower($trimmed), 'addr:')) {
            $value = trim(substr($trimmed, 5));
            if (! is_numeric($value)) {
                throw new RuntimeException('Invalid modbus channel address format ['.$channel.'].');
            }

            return $this->boundedInt((int) $value, 0, 65535, 0);
        }

        if (! is_numeric($trimmed)) {
            throw new RuntimeException('Modbus channel must be a numeric channel (1-based) or addr:<address>.');
        }

        $channelNumber = (int) $trimmed;
        if ($channelNumber < 1) {
            throw new RuntimeException('Modbus channel must be greater than or equal to 1.');
        }

        return $coilStartAddress + ($channelNumber - 1);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'on', 'high', 'yes'], true);
        }

        return false;
    }

    private function boundedInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
