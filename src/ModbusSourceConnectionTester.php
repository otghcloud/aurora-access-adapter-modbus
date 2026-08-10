<?php

declare(strict_types=1);

namespace OTGH\AccessControl\ModbusAdapter;

use OTGH\AccessControl\Core\Models\Hardware\Source;
use OTGH\AccessControl\Core\Services\AccessControl\SourceConnectionTesterInterface;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusSourceConfigResolver;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusTcpClient;

class ModbusSourceConnectionTester implements SourceConnectionTesterInterface
{
    public function __construct(
        private readonly ModbusSourceConfigResolver $sourceConfigResolver,
        private readonly ModbusTcpClient $modbusClient,
    ) {}

    /**
     * @return array<int,string>
     */
    public function supportedSourceTypes(): array
    {
        return ['modbus'];
    }

    public function test(Source $source): string
    {
        $resolved = $this->sourceConfigResolver->resolveFromSource($source);
        $coilStartAddress = (int) $resolved['coil_start_address'];

        $states = $this->modbusClient->readCoils(
            host: $resolved['host'],
            port: $resolved['port'],
            unitId: $resolved['unit_id'],
            startAddress: $coilStartAddress,
            quantity: 1,
            timeoutMs: $resolved['timeout_ms'],
        );

        $state = ($states[$coilStartAddress] ?? false) ? 'ON' : 'OFF';

        return sprintf(
            'Modbus source test passed: host=%s port=%d unit=%d coil[%d]=%s.',
            $resolved['host'],
            $resolved['port'],
            $resolved['unit_id'],
            $coilStartAddress,
            $state,
        );
    }
}
