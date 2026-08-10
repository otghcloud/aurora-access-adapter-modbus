<?php

declare(strict_types=1);

namespace OTGH\AccessControl\ModbusAdapter;

use App\Enums\AccessControl\AccessBindingActionKey;
use App\Jobs\ProcessReaderEvent;
use App\Models\Access\Area;
use App\Models\Access\Event;
use App\Models\Hardware\AdapterBinding;
use App\Models\Hardware\Lock;
use App\Models\Hardware\Reader;
use App\Models\Hardware\Source;
use App\Support\SignalValueMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ModbusInputActionDispatcher
{
    /**
     * @param  array<int,bool>  $inputStates
     * @param  array{input_start_address:int}  $runtimeConfig
     */
    public function handleInputSnapshot(Source $source, array $inputStates, array $runtimeConfig): void
    {
        $bindings = AdapterBinding::query()
            ->where('source_id', $source->id)
            ->where('direction', 'input')
            ->where('enabled', true)
            ->whereIn('adapter_type', ['modbus'])
            ->whereIn('action_key', AccessBindingActionKey::queryCandidatesFor([
                AccessBindingActionKey::EXIT_REQUEST,
                AccessBindingActionKey::EMERGENCY_EXIT_REQUEST,
            ]))
            ->get();

        if ($bindings->isEmpty()) {
            return;
        }

        foreach ($bindings as $binding) {
            $address = $this->resolveBindingAddress($binding->channel, (int) ($runtimeConfig['input_start_address'] ?? 0));

            if ($address === null || ! array_key_exists($address, $inputStates)) {
                continue;
            }

            $raw = $inputStates[$address];
            $isActive = SignalValueMapper::toCanonicalBool($raw, (bool) $binding->signal_reversed);

            Cache::put(self::lastSeenCacheKey((int) $binding->id), [
                'seen_at' => now()->toIso8601String(),
                'source_id' => (int) $source->id,
                'source_identifier' => $source->identifier,
                'binding_id' => (int) $binding->id,
                'channel' => $binding->channel,
                'address' => $address,
                'raw' => $raw,
                'active' => $isActive,
            ], now()->addDay());

            if ($isActive === null || ! $this->isRisingEdge((int) $binding->id, $isActive)) {
                continue;
            }

            $reader = $this->resolveReaderForBinding($binding);
            if (! $reader instanceof Reader) {
                Log::warning('modbus.input.unlock_request.reader_not_resolved', [
                    'binding_id' => $binding->id,
                    'target_type' => $binding->target_type,
                    'target_id' => $binding->target_id,
                    'source_id' => $source->id,
                    'channel' => $binding->channel,
                    'address' => $address,
                ]);

                continue;
            }

            $resolvedAction = AccessBindingActionKey::fromStored($binding->action_key);
            $isEmergency = $resolvedAction === AccessBindingActionKey::EMERGENCY_EXIT_REQUEST;
            $status = $isEmergency ? 'emergency_exit_request_detected' : 'exit_request_detected';
            $reason = $isEmergency
                ? 'Emergency exit request input activated.'
                : 'Exit request input activated.';
            $eventName = $isEmergency ? 'emergency_exit_request_detected' : 'exit_request_detected';

            Event::create([
                'access_card_id' => null,
                'access_area_id' => $reader->area_id,
                'access_lock_id' => $reader->area?->primaryLock()?->id,
                'access_source_id' => $source->id,
                'user_id' => null,
                'card_number' => null,
                'origin_type' => 'reader',
                'origin_id' => $reader->id,
                'origin_label' => $reader->name,
                'granted' => true,
                'status' => $status,
                'reason' => $reason,
                'metadata' => [
                    'source' => 'modbus_input',
                    'event' => $eventName,
                    'adapter_type' => (string) $binding->adapter_type,
                    'action_key' => $resolvedAction?->key() ?? (string) $binding->action_key,
                    'binding_id' => (int) $binding->id,
                    'source_id' => (int) $source->id,
                    'target_type' => (string) $binding->target_type,
                    'target_id' => (int) $binding->target_id,
                    'channel' => $binding->channel,
                    'address' => $address,
                    'raw' => $raw,
                    'signal_reversed' => (bool) $binding->signal_reversed,
                ],
                'ip_address' => null,
            ]);

            ProcessReaderEvent::dispatch(null, $reader, 0, ! $isEmergency, 'modbus_input');

            Cache::put(self::lastDispatchAtCacheKey((int) $binding->id), now()->toIso8601String(), now()->addDay());
            $dispatchCountKey = self::dispatchCountCacheKey((int) $binding->id);
            if (! Cache::has($dispatchCountKey)) {
                Cache::put($dispatchCountKey, 0, now()->addDays(7));
            }

            $dispatchCount = (int) Cache::increment($dispatchCountKey);
            Cache::put($dispatchCountKey, $dispatchCount, now()->addDays(7));

            Log::info('modbus.input.request.dispatched', [
                'source_id' => $source->id,
                'source_identifier' => $source->identifier,
                'binding_id' => $binding->id,
                'reader_id' => $reader->id,
                'reader_identifier' => $reader->identifier,
                'action_key' => $resolvedAction?->key() ?? (string) $binding->action_key,
                'emergency' => $isEmergency,
                'channel' => $binding->channel,
                'address' => $address,
                'raw' => $raw,
            ]);
        }
    }

    public static function edgeStateCacheKey(int $bindingId): string
    {
        return 'access_control:modbus_input_binding:'.$bindingId.':active';
    }

    public static function lastSeenCacheKey(int $bindingId): string
    {
        return 'access_control:modbus_input_binding:'.$bindingId.':last_seen';
    }

    public static function lastDispatchAtCacheKey(int $bindingId): string
    {
        return 'access_control:modbus_input_binding:'.$bindingId.':last_dispatch_at';
    }

    public static function dispatchCountCacheKey(int $bindingId): string
    {
        return 'access_control:modbus_input_binding:'.$bindingId.':dispatch_count';
    }

    private function isRisingEdge(int $bindingId, bool $current): bool
    {
        $cacheKey = self::edgeStateCacheKey($bindingId);

        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, $current, now()->addHours(6));

            return false;
        }

        $previous = (bool) Cache::get($cacheKey, false);
        Cache::put($cacheKey, $current, now()->addHours(6));

        return $current && ! $previous;
    }

    private function resolveBindingAddress(?string $channel, int $inputStartAddress): ?int
    {
        if (! is_string($channel)) {
            return null;
        }

        $trimmed = trim($channel);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with(strtolower($trimmed), 'addr:')) {
            $address = trim(substr($trimmed, 5));

            return is_numeric($address) ? max(0, min(65535, (int) $address)) : null;
        }

        if (! is_numeric($trimmed)) {
            return null;
        }

        $channelNumber = (int) $trimmed;
        if ($channelNumber < 1) {
            return null;
        }

        return $inputStartAddress + ($channelNumber - 1);
    }

    private function resolveReaderForBinding(AdapterBinding $binding): ?Reader
    {
        return match ($binding->target_type) {
            'reader' => Reader::query()->find($binding->target_id),
            'lock' => $this->resolveReaderFromLock((int) $binding->target_id),
            'area' => $this->resolveReaderFromRoom((int) $binding->target_id),
            default => null,
        };
    }

    private function resolveReaderFromLock(int $lockId): ?Reader
    {
        $lock = Lock::query()->find($lockId);

        if (! $lock instanceof Lock) {
            return null;
        }

        return $this->resolveReaderFromRoom((int) $lock->area_id);
    }

    private function resolveReaderFromRoom(int $roomId): ?Reader
    {
        $area = Area::query()->find($roomId);

        if (! $area instanceof Area) {
            return null;
        }

        return $area->readers()->orderBy('id')->first();
    }
}
