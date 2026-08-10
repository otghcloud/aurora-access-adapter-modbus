<?php

namespace OTGH\AccessControl\ModbusAdapter\Services;

use Illuminate\Support\Facades\Cache;
use OTGH\AccessControl\Core\Enums\AccessControl\AccessBindingActionKey;
use OTGH\AccessControl\Core\Models\Access\Area;
use OTGH\AccessControl\Core\Models\Hardware\AdapterBinding;
use OTGH\AccessControl\Core\Models\Hardware\Lock;
use OTGH\AccessControl\Core\Models\Hardware\Reader;
use OTGH\AccessControl\Core\Models\Hardware\Source;
use OTGH\AccessControl\ModbusAdapter\Console\Commands\MonitorModbusSources;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusSourceConfigResolver;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusTcpClient;
use OTGH\AccessControl\ModbusAdapter\ModbusInputActionDispatcher;
use Throwable;

class ModbusInputDiagnosticsService
{
    public function __construct(
        private readonly ModbusSourceConfigResolver $sourceConfigResolver,
        private readonly ModbusTcpClient $modbusClient,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildPayload(): array
    {
        $sources = Source::query()
            ->where('enabled', true)
            ->where('type', 'modbus')
            ->orderBy('id')
            ->get();

        $sourceRows = $sources->map(function (Source $source): array {
            $heartbeatKey = MonitorModbusSources::heartbeatCacheKey((int) $source->id);
            $heartbeat = Cache::get($heartbeatKey);
            $running = Cache::has($heartbeatKey);

            $relayStates = [];
            $inputStates = [];
            $relayChannels = [];
            $inputChannels = [];
            $snapshotError = null;
            $resolvedConfig = null;

            try {
                $resolvedConfig = $this->sourceConfigResolver->resolveFromSource($source);

                $relaySnapshot = $this->modbusClient->readCoils(
                    host: $resolvedConfig['host'],
                    port: $resolvedConfig['port'],
                    unitId: $resolvedConfig['unit_id'],
                    startAddress: $resolvedConfig['coil_start_address'],
                    quantity: $resolvedConfig['relay_channel_count'],
                    timeoutMs: $resolvedConfig['timeout_ms'],
                );

                $inputSnapshot = $this->modbusClient->readDiscreteInputs(
                    host: $resolvedConfig['host'],
                    port: $resolvedConfig['port'],
                    unitId: $resolvedConfig['unit_id'],
                    startAddress: $resolvedConfig['input_start_address'],
                    quantity: $resolvedConfig['digital_input_count'],
                    timeoutMs: $resolvedConfig['timeout_ms'],
                );

                $relayStates = array_values($relaySnapshot);
                $inputStates = array_values($inputSnapshot);
                $relayChannels = $this->mapChannels($relaySnapshot, (int) $resolvedConfig['coil_start_address']);
                $inputChannels = $this->mapChannels($inputSnapshot, (int) $resolvedConfig['input_start_address']);
            } catch (Throwable $e) {
                $snapshotError = $e->getMessage();
            }

            return [
                'id' => (int) $source->id,
                'name' => $source->name,
                'identifier' => $source->identifier,
                'running' => $running,
                'heartbeat' => $heartbeat,
                'host' => $resolvedConfig['host'] ?? null,
                'port' => $resolvedConfig['port'] ?? null,
                'unit_id' => $resolvedConfig['unit_id'] ?? null,
                'relay_states' => $relayStates,
                'input_states' => $inputStates,
                'relay_channels' => $relayChannels,
                'input_channels' => $inputChannels,
                'snapshot_error' => $snapshotError,
            ];
        })->values()->all();

        $bindings = AdapterBinding::query()
            ->with('source')
            ->where('direction', 'input')
            ->where('adapter_type', 'modbus')
            ->orderBy('id')
            ->get();

        $bindingRows = $bindings->map(function (AdapterBinding $binding): array {
            $lastSeen = Cache::get(ModbusInputActionDispatcher::lastSeenCacheKey((int) $binding->id));
            $actionKey = AccessBindingActionKey::fromStored($binding->action_key);

            return [
                'id' => (int) $binding->id,
                'enabled' => (bool) $binding->enabled,
                'action_key' => $actionKey?->key() ?? (string) $binding->action_key,
                'action_key_id' => $actionKey?->value,
                'action_key_label' => $actionKey?->label(),
                'adapter_type' => $binding->adapter_type,
                'channel' => $binding->channel,
                'signal_reversed' => (bool) $binding->signal_reversed,
                'source_id' => (int) ($binding->source_id ?? 0),
                'source_identifier' => $binding->source?->identifier,
                'target_type' => $binding->target_type,
                'target_id' => (int) $binding->target_id,
                'target_label' => $this->resolveTargetLabel($binding->target_type, (int) $binding->target_id),
                'edge_active' => Cache::get(ModbusInputActionDispatcher::edgeStateCacheKey((int) $binding->id)),
                'last_seen' => is_array($lastSeen) ? $lastSeen : null,
                'last_dispatch_at' => Cache::get(ModbusInputActionDispatcher::lastDispatchAtCacheKey((int) $binding->id)),
                'dispatch_count' => (int) Cache::get(ModbusInputActionDispatcher::dispatchCountCacheKey((int) $binding->id), 0),
            ];
        })->values()->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'modbus_monitor_command_processes' => $this->countProcesses('artisan app:monitor-modbus-sources'),
            'sources' => $sourceRows,
            'bindings' => $bindingRows,
        ];
    }

    private function resolveTargetLabel(string $targetType, int $targetId): ?string
    {
        return match ($targetType) {
            'reader' => $this->readerLabel($targetId),
            'lock' => $this->lockLabel($targetId),
            'area' => $this->roomLabel($targetId),
            default => null,
        };
    }

    private function readerLabel(int $readerId): ?string
    {
        $reader = Reader::query()->find($readerId);

        if (! $reader instanceof Reader) {
            return null;
        }

        return $reader->name.' ('.$reader->identifier.')';
    }

    private function lockLabel(int $lockId): ?string
    {
        $lock = Lock::query()->find($lockId);

        if (! $lock instanceof Lock) {
            return null;
        }

        return $lock->name.' ('.$lock->identifier.')';
    }

    private function roomLabel(int $roomId): ?string
    {
        $area = Area::query()->find($roomId);

        if (! $area instanceof Area) {
            return null;
        }

        return $area->name.' ('.$area->identifier.')';
    }

    private function countProcesses(string $needle): ?int
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $cmd = "ps -ww -eo args | grep -F '".str_replace("'", "'\\''", $needle)."' | grep -v grep | wc -l";
        $out = shell_exec($cmd);

        if (! is_string($out)) {
            return null;
        }

        return max(0, (int) trim($out));
    }

    /**
     * @param  array<int,bool>  $snapshot
     * @return array<int,array{channel:int,address:int,state:bool}>
     */
    private function mapChannels(array $snapshot, int $startAddress): array
    {
        $rows = [];
        $index = 1;

        foreach ($snapshot as $address => $state) {
            $addressNumber = is_numeric($address) ? (int) $address : ($startAddress + ($index - 1));
            $rows[] = [
                'channel' => $index,
                'address' => $addressNumber,
                'state' => (bool) $state,
            ];
            $index++;
        }

        return $rows;
    }
}
