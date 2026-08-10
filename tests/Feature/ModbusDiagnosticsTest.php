<?php

use App\Models\Hardware\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusSourceConfigResolver;
use OTGH\AccessControl\ModbusAdapter\ModbusOutputAdapter;
use OTGH\AccessControl\ModbusAdapter\Services\ModbusInputDiagnosticsService;

uses(RefreshDatabase::class);

it('renders modbus diagnostics page', function () {
    $admin = User::factory()->create();

    $mock = Mockery::mock(ModbusInputDiagnosticsService::class);
    $mock->shouldReceive('buildPayload')->once()->andReturn([
        'generated_at' => now()->toIso8601String(),
        'modbus_monitor_command_processes' => 1,
        'sources' => [
            [
                'id' => 1,
                'name' => 'Waveshare',
                'identifier' => 'waveshare',
                'running' => true,
                'heartbeat' => now()->toIso8601String(),
                'host' => '192.168.0.100',
                'port' => 502,
                'unit_id' => 1,
                'relay_states' => [true, false],
                'input_states' => [false, true],
                'relay_channels' => [
                    ['channel' => 1, 'address' => 0, 'state' => true],
                    ['channel' => 2, 'address' => 1, 'state' => false],
                ],
                'input_channels' => [
                    ['channel' => 1, 'address' => 0, 'state' => false],
                    ['channel' => 2, 'address' => 1, 'state' => true],
                ],
                'snapshot_error' => null,
            ],
        ],
        'bindings' => [],
    ]);
    app()->instance(ModbusInputDiagnosticsService::class, $mock);

    $response = $this->actingAs($admin)->get(route('admin.modbus-diagnostics'));

    $response->assertOk();
    $response->assertSee('Modbus Diagnostics');
    $response->assertSee('Waveshare');
    $response->assertSee('Relay Channels (Outputs)');
    $response->assertSee('Digital Inputs');
});

it('toggles relay channel via modbus diagnostics action', function () {
    $admin = User::factory()->create();

    $source = Source::create([
        'name' => 'Waveshare',
        'identifier' => 'waveshare',
        'type' => 'modbus',
        'endpoint' => 'modbus://192.168.0.100:502',
        'enabled' => true,
        'config' => [
            'modbus' => [
                'host' => '192.168.0.100',
                'port' => 502,
                'unit_id' => 1,
                'relay_channel_count' => 8,
                'coil_start_address' => 0,
            ],
        ],
        'metadata' => [],
    ]);

    $resolver = Mockery::mock(ModbusSourceConfigResolver::class);
    $resolver->shouldReceive('resolveFromSource')->once()->andReturn([
        'source' => $source,
        'host' => '192.168.0.100',
        'port' => 502,
        'unit_id' => 1,
        'digital_input_count' => 8,
        'relay_channel_count' => 8,
        'poll_interval_ms' => 500,
        'input_start_address' => 0,
        'coil_start_address' => 0,
        'timeout_ms' => 3000,
    ]);
    app()->instance(ModbusSourceConfigResolver::class, $resolver);

    $adapter = Mockery::mock(ModbusOutputAdapter::class);
    $adapter->shouldReceive('read')->once()->with('1', Mockery::type('array'))->andReturn(1);
    $adapter->shouldReceive('write')->once()->with('1', 0, Mockery::type('array'));
    app()->instance(ModbusOutputAdapter::class, $adapter);

    $response = $this->actingAs($admin)->post(route('admin.modbus-diagnostics.set-relay', [
        'accessSource' => $source,
        'channel' => 1,
    ]), [
        'state' => 'toggle',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status');
});
