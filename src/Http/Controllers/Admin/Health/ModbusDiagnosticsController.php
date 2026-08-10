<?php

namespace OTGH\AccessControl\ModbusAdapter\Http\Controllers\Admin\Health;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OTGH\AccessControl\Core\Enums\AccessControl\AccessBindingActionKey;
use OTGH\AccessControl\Core\Http\Controllers\Controller;
use OTGH\AccessControl\Core\Models\Hardware\Source;
use OTGH\AccessControl\ModbusAdapter\Modbus\ModbusSourceConfigResolver;
use OTGH\AccessControl\ModbusAdapter\ModbusOutputAdapter;
use OTGH\AccessControl\ModbusAdapter\Services\ModbusInputDiagnosticsService;
use Throwable;

class ModbusDiagnosticsController extends Controller
{
    public function __invoke(Request $request, ModbusInputDiagnosticsService $diagnosticsService): View
    {
        $requestedAutoRefresh = (int) $request->integer('auto_refresh', 0);
        $allowedAutoRefreshIntervals = [0, 3, 5, 10, 30];
        $autoRefreshSeconds = in_array($requestedAutoRefresh, $allowedAutoRefreshIntervals, true)
            ? $requestedAutoRefresh
            : 0;

        $selectedAction = AccessBindingActionKey::fromStored($request->input('action_key'));
        $diagnostics = $diagnosticsService->buildPayload();

        if ($selectedAction instanceof AccessBindingActionKey) {
            $diagnostics['bindings'] = array_values(array_filter(
                is_array($diagnostics['bindings'] ?? null) ? $diagnostics['bindings'] : [],
                static function (array $binding) use ($selectedAction): bool {
                    $bindingAction = AccessBindingActionKey::fromStored($binding['action_key_id'] ?? $binding['action_key'] ?? null);

                    return $bindingAction === $selectedAction;
                }
            ));
        }

        return view('modbus-adapter::admin.health.modbus-diagnostics', [
            'diagnostics' => $diagnostics,
            'autoRefreshSeconds' => $autoRefreshSeconds,
            'autoRefreshOptions' => $allowedAutoRefreshIntervals,
            'actionOptions' => AccessBindingActionKey::options('input'),
            'selectedActionKey' => $selectedAction?->value,
        ]);
    }

    public function setRelay(
        Request $request,
        Source $accessSource,
        int $channel,
        ModbusSourceConfigResolver $configResolver,
        ModbusOutputAdapter $outputAdapter,
    ): RedirectResponse {
        $validated = $request->validate([
            'state' => ['required', 'string', 'in:0,1,toggle'],
        ]);

        if (strtolower(trim((string) $accessSource->type)) !== 'modbus') {
            return back()->withErrors([
                'modbus_relay' => 'Selected source is not a Modbus source.',
            ]);
        }

        try {
            $resolved = $configResolver->resolveFromSource($accessSource);

            if ($channel < 1 || $channel > (int) $resolved['relay_channel_count']) {
                return back()->withErrors([
                    'modbus_relay' => sprintf(
                        'Channel %d is out of range for source [%s]. Allowed range is 1..%d.',
                        $channel,
                        $accessSource->identifier,
                        (int) $resolved['relay_channel_count'],
                    ),
                ]);
            }

            $sourceConfig = is_array($accessSource->config) ? $accessSource->config : [];
            $stateOption = $validated['state'];
            $targetState = false;

            if ($stateOption === 'toggle') {
                $currentRaw = $outputAdapter->read((string) $channel, $sourceConfig);
                $targetState = ! ((bool) $currentRaw);
            } else {
                $targetState = $stateOption === '1';
            }

            $outputAdapter->write((string) $channel, $targetState ? 1 : 0, $sourceConfig);

            return back()->with('status', sprintf(
                'Modbus source [%s] relay channel %d set to %s.',
                $accessSource->identifier,
                $channel,
                $targetState ? 'ON' : 'OFF',
            ));
        } catch (Throwable $e) {
            return back()->withErrors([
                'modbus_relay' => sprintf(
                    'Failed to set relay channel %d on source [%s]: %s',
                    $channel,
                    $accessSource->identifier,
                    $e->getMessage(),
                ),
            ]);
        }
    }
}
