<?php

namespace OTGH\AccessControl\ModbusAdapter\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OTGH\AccessControl\ModbusAdapter\Services\ModbusInputDiagnosticsService;

#[Signature('app:modbus-input-diagnostics {--json : Output machine-readable JSON}')]
#[Description('Show Modbus input binding diagnostics including monitor heartbeat and dispatch telemetry')]
class ModbusInputDiagnostics extends Command
{
    public function __construct(private readonly ModbusInputDiagnosticsService $diagnosticsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->diagnosticsService->buildPayload();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Modbus Input Diagnostics @ '.$payload['generated_at']);
        $this->line('modbus_monitor_command_processes='.(string) ($payload['modbus_monitor_command_processes'] ?? 'n/a'));
        $this->newLine();

        $this->info('Sources');
        foreach ($payload['sources'] as $source) {
            $relayPreview = implode(',', array_map(static fn (bool $state): string => $state ? '1' : '0', array_slice($source['relay_states'] ?? [], 0, 8)));
            $inputPreview = implode(',', array_map(static fn (bool $state): string => $state ? '1' : '0', array_slice($source['input_states'] ?? [], 0, 8)));

            $this->line(sprintf(
                '- [%s] %s (%s:%d unit=%d) heartbeat=%s relays=[%s] inputs=[%s]',
                $source['running'] ? 'running' : 'not_running',
                $source['identifier'],
                $source['host'] ?? 'n/a',
                (int) ($source['port'] ?? 0),
                (int) ($source['unit_id'] ?? 0),
                $source['heartbeat'] ?? 'none',
                $relayPreview,
                $inputPreview,
            ));

            if (($source['snapshot_error'] ?? null) !== null) {
                $this->warn('  snapshot_error='.$source['snapshot_error']);
            }
        }

        $this->newLine();
        $this->info('Input Bindings');
        foreach ($payload['bindings'] as $binding) {
            $this->line(sprintf(
                '- #%d %s source=%s channel=%s target=%s edge=%s last_seen=%s last_dispatch=%s dispatch_count=%d',
                $binding['id'],
                $binding['action_key'],
                $binding['source_identifier'] ?? 'n/a',
                $binding['channel'] ?? 'n/a',
                $binding['target_label'] ?? ($binding['target_type'].'#'.$binding['target_id']),
                is_bool($binding['edge_active']) ? ($binding['edge_active'] ? 'true' : 'false') : 'unknown',
                is_array($binding['last_seen']) ? ($binding['last_seen']['seen_at'] ?? 'n/a') : 'n/a',
                $binding['last_dispatch_at'] ?? 'n/a',
                (int) $binding['dispatch_count'],
            ));
        }

        return self::SUCCESS;
    }
}
