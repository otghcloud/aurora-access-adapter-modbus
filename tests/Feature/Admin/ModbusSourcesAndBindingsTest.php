<?php

use App\Enums\AccessControl\AccessBindingActionKey;
use App\Models\Access\Area;
use App\Models\Hardware\AdapterBinding;
use App\Models\Hardware\Lock;
use App\Models\Hardware\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a modbus source from typed fields', function () {
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.access-sources.store'), [
        'name' => 'Main Modbus Source',
        'identifier' => 'main-modbus-source',
        'type' => 'modbus',
        'endpoint' => '',
        'enabled' => '1',
        'modbus_host' => '192.168.0.100',
        'modbus_port' => '502',
        'modbus_unit_id' => '1',
        'modbus_digital_input_count' => '8',
        'modbus_relay_channel_count' => '8',
        'modbus_poll_interval_ms' => '250',
        'modbus_input_start_address' => '0',
        'modbus_coil_start_address' => '0',
        'modbus_timeout_ms' => '3000',
        'metadata_json' => '{"site":"Plant 1"}',
    ]);

    $response->assertRedirect(route('admin.access-sources.index'));

    $source = Source::query()->where('identifier', 'main-modbus-source')->firstOrFail();

    expect($source->type)->toBe('modbus');
    expect($source->endpoint)->toBe('modbus://192.168.0.100:502');
    expect(data_get($source->config, 'modbus.host'))->toBe('192.168.0.100');
    expect(data_get($source->config, 'modbus.port'))->toBe(502);
    expect(data_get($source->config, 'modbus.unit_id'))->toBe(1);
    expect(data_get($source->config, 'modbus.digital_input_count'))->toBe(8);
    expect(data_get($source->config, 'modbus.relay_channel_count'))->toBe(8);
    expect(data_get($source->config, 'modbus.poll_interval_ms'))->toBe(250);
    expect(data_get($source->config, 'modbus.input_start_address'))->toBe(0);
    expect(data_get($source->config, 'modbus.coil_start_address'))->toBe(0);
    expect(data_get($source->config, 'modbus.timeout_ms'))->toBe(3000);
    expect(data_get($source->metadata, 'site'))->toBe('Plant 1');
});

it('creates a binding with modbus adapter type', function () {
    $admin = User::factory()->create();

    $area = Area::create([
        'name' => 'Workshop',
        'identifier' => 'workshop',
        'metadata' => [],
    ]);

    $lock = Lock::create([
        'area_id' => $area->id,
        'name' => 'Workshop Door Lock',
        'identifier' => 'workshop-door-lock',
        'is_primary' => true,
        'config' => [],
        'metadata' => [],
    ]);

    $source = Source::create([
        'name' => 'Workshop Modbus',
        'identifier' => 'workshop-modbus',
        'type' => 'modbus',
        'endpoint' => 'modbus://192.168.0.120:502',
        'enabled' => true,
        'config' => [
            'modbus' => [
                'host' => '192.168.0.120',
                'port' => 502,
                'unit_id' => 1,
                'coil_start_address' => 0,
            ],
        ],
        'metadata' => [],
    ]);

    $response = $this->actingAs($admin)->post(route('admin.access-bindings.store'), [
        'direction' => 'output',
        'adapter_type' => 'modbus',
        'target_type' => 'lock',
        'target_id' => (string) $lock->id,
        'source_id' => (string) $source->id,
        'action_key' => (string) AccessBindingActionKey::LOCK_POWER->value,
        'channel' => '1',
        'signal_reversed' => '0',
        'enabled' => '1',
        'config_json' => '{"modbus":{"coil_start_address":0}}',
        'metadata_json' => '{}',
    ]);

    $response->assertRedirect(route('admin.access-bindings.index'));

    $binding = AdapterBinding::query()
        ->where('adapter_type', 'modbus')
        ->where('source_id', $source->id)
        ->firstOrFail();

    expect($binding->direction)->toBe('output');
    expect($binding->target_type)->toBe('lock');
    expect((int) $binding->target_id)->toBe($lock->id);
    expect($binding->channel)->toBe('1');
});
