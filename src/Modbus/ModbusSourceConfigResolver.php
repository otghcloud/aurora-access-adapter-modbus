<?php

declare(strict_types=1);

namespace OTGH\AccessControl\ModbusAdapter\Modbus;

use App\Models\Hardware\Source;
use RuntimeException;

class ModbusSourceConfigResolver
{
    /**
     * @return array{source:Source,host:string,port:int,unit_id:int,digital_input_count:int,relay_channel_count:int,poll_interval_ms:int,input_start_address:int,coil_start_address:int,timeout_ms:int}
     */
    public function resolveById(int $sourceId): array
    {
        $source = Source::query()->find($sourceId);

        if (! $source instanceof Source) {
            throw new RuntimeException('Modbus source not found for id ['.$sourceId.'].');
        }

        return $this->resolveFromSource($source);
    }

    /**
     * @return array{source:Source,host:string,port:int,unit_id:int,digital_input_count:int,relay_channel_count:int,poll_interval_ms:int,input_start_address:int,coil_start_address:int,timeout_ms:int}
     */
    public function resolveFromSource(Source $source): array
    {
        $type = strtolower(trim((string) $source->type));

        if ($type !== 'modbus') {
            throw new RuntimeException('Source ['.$source->identifier.'] is not a modbus source.');
        }

        $config = is_array($source->config) ? $source->config : [];
        $modbus = data_get($config, 'modbus');
        $modbusConfig = is_array($modbus) ? $modbus : $config;

        [$endpointHost, $endpointPort] = $this->parseEndpoint((string) ($source->endpoint ?? ''));

        $host = $this->nullableString(data_get($modbusConfig, 'host')) ?? $endpointHost;
        if ($host === null) {
            throw new RuntimeException('Modbus host is not configured for source ['.$source->identifier.'].');
        }

        return [
            'source' => $source,
            'host' => $host,
            'port' => $this->boundedInt(data_get($modbusConfig, 'port', $endpointPort ?? 502), 1, 65535, 502),
            'unit_id' => $this->boundedInt(data_get($modbusConfig, 'unit_id', 1), 1, 255, 1),
            'digital_input_count' => $this->boundedInt(data_get($modbusConfig, 'digital_input_count', 8), 1, 2000, 8),
            'relay_channel_count' => $this->boundedInt(data_get($modbusConfig, 'relay_channel_count', 8), 1, 2000, 8),
            'poll_interval_ms' => $this->boundedInt(data_get($modbusConfig, 'poll_interval_ms', 500), 50, 60000, 500),
            'input_start_address' => $this->boundedInt(data_get($modbusConfig, 'input_start_address', 0), 0, 65535, 0),
            'coil_start_address' => $this->boundedInt(data_get($modbusConfig, 'coil_start_address', 0), 0, 65535, 0),
            'timeout_ms' => $this->boundedInt(data_get($modbusConfig, 'timeout_ms', 3000), 100, 120000, 3000),
        ];
    }

    /**
     * @return array{0:?string,1:?int}
     */
    private function parseEndpoint(string $endpoint): array
    {
        $trimmed = trim($endpoint);

        if ($trimmed === '') {
            return [null, null];
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return [null, null];
        }

        $host = isset($parts['host']) && is_string($parts['host']) ? trim($parts['host']) : null;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        return [$host !== '' ? $host : null, $port];
    }

    private function boundedInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return max($min, min($max, $intValue));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
