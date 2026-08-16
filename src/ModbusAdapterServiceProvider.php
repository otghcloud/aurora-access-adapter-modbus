<?php

declare(strict_types=1);

namespace OTGH\AccessControl\ModbusAdapter;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use OTGH\AccessControl\Core\Models\Hardware\Source;
use OTGH\AccessControl\Core\Services\AccessControl\AccessControlCapabilityRegistry;
use OTGH\AccessControl\Core\Services\AccessControl\DiagnosticsNavigationRegistry;
use OTGH\AccessControl\Core\Services\AccessControl\OutputAdapterRegistry;
use OTGH\AccessControl\Core\Services\AccessControl\SourceConnectionTesterRegistry;
use OTGH\AccessControl\Core\Services\Supervisor\SupervisorProgramRegistry;
use OTGH\AccessControl\ModbusAdapter\Console\Commands\ModbusInputDiagnostics;
use OTGH\AccessControl\ModbusAdapter\Console\Commands\MonitorModbusSources;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusSourceConfigResolver;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusTcpClient;
use OTGH\AccessControl\ModbusAdapter\Services\ModbusInputDiagnosticsService;

class ModbusAdapterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModbusTcpClient::class);
        $this->app->singleton(ModbusSourceConfigResolver::class);
        $this->app->singleton(ModbusOutputAdapter::class);
        $this->app->singleton(ModbusInputActionDispatcher::class);
        $this->app->singleton(ModbusInputDiagnosticsService::class);
        $this->app->singleton(ModbusSourceConnectionTester::class);

        $this->app->afterResolving(OutputAdapterRegistry::class, function (OutputAdapterRegistry $registry): void {
            $registry->register($this->app->make(ModbusOutputAdapter::class));
        });

        $this->app->afterResolving(AccessControlCapabilityRegistry::class, function (AccessControlCapabilityRegistry $registry): void {
            $registry->registerBindingAdapterType('modbus', 'MODBUS TCP');
            $registry->registerSourceType('modbus', 'MODBUS TCP');
        });

        $this->app->afterResolving(SourceConnectionTesterRegistry::class, function (SourceConnectionTesterRegistry $registry): void {
            $registry->register($this->app->make(ModbusSourceConnectionTester::class));
        });

        $this->app->afterResolving(DiagnosticsNavigationRegistry::class, function (DiagnosticsNavigationRegistry $registry): void {
            $registry->register('admin.modbus-diagnostics', 'Modbus', 30);
        });

        $this->app->afterResolving(SupervisorProgramRegistry::class, function (SupervisorProgramRegistry $registry): void {
            $registry->register(function (string $phpBinary, string $workingDir): array {
                if (! Schema::hasTable('sources')) {
                    return [];
                }

                $hasModbusSources = Source::query()
                    ->where('enabled', true)
                    ->where('type', 'modbus')
                    ->exists();

                if (! $hasModbusSources) {
                    return [];
                }

                return [<<<CONF
[program:access-control-modbus-monitor]
command={$phpBinary} {$workingDir}/artisan app:monitor-modbus-sources
directory={$workingDir}
process_name=%(program_name)s
numprocs=1
autostart=true
autorestart=true
startsecs=5
startretries=10
stdout_logfile={$workingDir}/storage/logs/supervisor-modbus-monitor.log
stderr_logfile={$workingDir}/storage/logs/supervisor-modbus-monitor-error.log
stopwaitsecs=30
CONF];
            });
        });

    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'modbus-adapter');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ModbusInputDiagnostics::class,
                MonitorModbusSources::class,
            ]);
        }
    }
}
