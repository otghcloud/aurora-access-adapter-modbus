<?php

namespace OTGH\AccessControl\ModbusAdapter\Console\Commands;

use App\Models\Hardware\Source;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusSourceConfigResolver;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusTcpClient;
use OTGH\AccessControl\ModbusAdapter\ModbusInputActionDispatcher;
use Throwable;

#[Signature('app:monitor-modbus-sources {--source-id=* : Optional source IDs to monitor} {--once : Poll once and exit} {--verbose-polls : Print every poll result summary}')]
#[Description('Monitor enabled Modbus TCP access sources and dispatch input actions')]
class MonitorModbusSources extends Command
{
    /**
     * @var array<int,int>
     */
    private array $nextDueAtMs = [];

    public function __construct(
        private readonly ModbusSourceConfigResolver $configResolver,
        private readonly ModbusTcpClient $modbusClient,
        private readonly ModbusInputActionDispatcher $inputActionDispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sourceFilter = $this->resolveSourceFilter();
        $once = (bool) $this->option('once');
        $verbosePolls = (bool) $this->option('verbose-polls');

        $this->info('Starting Modbus source monitor'.($once ? ' (single pass)' : '').'...');

        do {
            $sources = Source::query()
                ->where('enabled', true)
                ->where('type', 'modbus')
                ->when($sourceFilter !== [], fn ($query) => $query->whereIn('id', $sourceFilter))
                ->orderBy('id')
                ->get();

            $nowMs = $this->nowMs();
            $dueSoonestMs = null;

            foreach ($sources as $source) {
                try {
                    $resolved = $this->configResolver->resolveFromSource($source);
                } catch (Throwable $e) {
                    Log::warning('modbus.monitor.source_config_invalid', [
                        'source_id' => $source->id,
                        'source_identifier' => $source->identifier,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $sourceId = (int) $source->id;
                $nextDue = $this->nextDueAtMs[$sourceId] ?? 0;

                if (! $once && $nextDue > $nowMs) {
                    $dueSoonestMs = $dueSoonestMs === null ? $nextDue : min($dueSoonestMs, $nextDue);

                    continue;
                }

                try {
                    $relayStates = $this->modbusClient->readCoils(
                        host: $resolved['host'],
                        port: $resolved['port'],
                        unitId: $resolved['unit_id'],
                        startAddress: $resolved['coil_start_address'],
                        quantity: $resolved['relay_channel_count'],
                        timeoutMs: $resolved['timeout_ms'],
                    );

                    $inputStates = $this->modbusClient->readDiscreteInputs(
                        host: $resolved['host'],
                        port: $resolved['port'],
                        unitId: $resolved['unit_id'],
                        startAddress: $resolved['input_start_address'],
                        quantity: $resolved['digital_input_count'],
                        timeoutMs: $resolved['timeout_ms'],
                    );

                    $this->inputActionDispatcher->handleInputSnapshot($source, $inputStates, [
                        'input_start_address' => $resolved['input_start_address'],
                    ]);

                    Cache::put(self::heartbeatCacheKey($sourceId), now()->toIso8601String(), now()->addMinutes(2));

                    if ($verbosePolls) {
                        $this->line(sprintf(
                            '[%s] source=%s relays=%d inputs=%d',
                            date('H:i:s'),
                            $source->identifier,
                            count($relayStates),
                            count($inputStates),
                        ));
                    }
                } catch (Throwable $e) {
                    Log::warning('modbus.monitor.poll_failed', [
                        'source_id' => $source->id,
                        'source_identifier' => $source->identifier,
                        'error' => $e->getMessage(),
                    ]);

                    if ($verbosePolls || $once) {
                        $this->warn(sprintf('Poll failed for source [%s]: %s', $source->identifier, $e->getMessage()));
                    }
                }

                $pollIntervalMs = max(50, (int) $resolved['poll_interval_ms']);
                $next = $this->nowMs() + $pollIntervalMs;
                $this->nextDueAtMs[$sourceId] = $next;
                $dueSoonestMs = $dueSoonestMs === null ? $next : min($dueSoonestMs, $next);
            }

            if ($once) {
                break;
            }

            $sleepMs = 50;
            if ($dueSoonestMs !== null) {
                $sleepMs = max(10, min(250, $dueSoonestMs - $this->nowMs()));
            }

            usleep($sleepMs * 1000);
        } while (true);

        $this->info('Modbus source monitor stopped.');

        return self::SUCCESS;
    }

    public static function heartbeatCacheKey(int $sourceId): string
    {
        return 'access_control:modbus_monitor:source:'.$sourceId.':heartbeat';
    }

    /**
     * @return array<int,int>
     */
    private function resolveSourceFilter(): array
    {
        $sourceOption = $this->option('source-id');

        if (! is_array($sourceOption)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $sourceOption,
        ), static fn (int $id): bool => $id > 0));
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
