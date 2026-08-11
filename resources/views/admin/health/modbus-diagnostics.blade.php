@extends('layouts.admin')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Modbus Diagnostics</h1>
            <p class="text-muted mb-0">Live relay/input states and manual relay control per Modbus source.</p>
        </div>
        <form method="GET" action="{{ route('admin.modbus-diagnostics') }}" class="d-flex flex-wrap align-items-center gap-2">
            <label for="auto_refresh" class="form-label mb-0 small text-muted">Auto-refresh</label>
            <select name="auto_refresh" id="auto_refresh" class="form-select form-select-sm" style="min-width: 170px;">
                @foreach ($autoRefreshOptions as $interval)
                    <option value="{{ $interval }}" @selected($autoRefreshSeconds === $interval)>
                        {{ $interval === 0 ? 'Off' : 'Every '.$interval.'s' }}
                    </option>
                @endforeach
            </select>
            <label for="action_key" class="form-label mb-0 small text-muted">Action</label>
            <select name="action_key" id="action_key" class="form-select form-select-sm" style="min-width: 220px;">
                <option value="">All Input Actions</option>
                @foreach (($actionOptions ?? []) as $option)
                    <option value="{{ $option['value'] }}" @selected((int) request('action_key', (string) ($selectedActionKey ?? '')) === (int) $option['value'])>
                        {{ $option['key'] }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Refresh</button>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Generated</p>
                    <p class="mb-0 fw-semibold">{{ \Illuminate\Support\Carbon::parse($diagnostics['generated_at'])->format('d/m/Y H:i:s') }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Monitor Processes</p>
                    <p class="display-6 mb-0">{{ $diagnostics['modbus_monitor_command_processes'] ?? 'n/a' }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Modbus Sources</p>
                    <p class="display-6 mb-0">{{ count($diagnostics['sources'] ?? []) }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Input Bindings</p>
                    <p class="display-6 mb-0">{{ count($diagnostics['bindings'] ?? []) }}</p>
                </div>
            </div>
        </div>
    </div>

    @forelse ($diagnostics['sources'] as $source)
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h5 mb-0">{{ $source['name'] }} <span class="text-muted">({{ $source['identifier'] }})</span></h2>
                    <div class="small text-muted mt-1">
                        host={{ $source['host'] ?? 'n/a' }}:{{ $source['port'] ?? 'n/a' }}
                        unit={{ $source['unit_id'] ?? 'n/a' }}
                    </div>
                </div>
                <div>
                    <span class="badge text-bg-{{ ($source['running'] ?? false) ? 'success' : 'secondary' }}">
                        {{ ($source['running'] ?? false) ? 'monitor running' : 'monitor not running' }}
                    </span>
                    <div class="small text-muted text-end mt-1">heartbeat={{ $source['heartbeat'] ?? 'n/a' }}</div>
                </div>
            </div>
            <div class="card-body">
                @if (is_string($source['snapshot_error'] ?? null) && $source['snapshot_error'] !== '')
                    <div class="alert alert-danger mb-3">
                        Failed to read source snapshot: {{ $source['snapshot_error'] }}
                    </div>
                @endif

                <div class="row g-3">
                    <div class="col-12 col-xl-6">
                        <div class="border rounded p-3 h-100">
                            <h3 class="h6 mb-3">Relay Channels (Outputs)</h3>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Channel</th>
                                            <th>Address</th>
                                            <th>State</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($source['relay_channels'] as $relay)
                                            <tr>
                                                <td>{{ $relay['channel'] }}</td>
                                                <td><code>{{ $relay['address'] }}</code></td>
                                                <td>
                                                    <span class="badge text-bg-{{ $relay['state'] ? 'success' : 'secondary' }}">
                                                        {{ $relay['state'] ? 'ON' : 'OFF' }}
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="Relay controls">
                                                        <form method="POST" action="{{ route('admin.modbus-diagnostics.set-relay', ['accessSource' => $source['id'], 'channel' => $relay['channel']]) }}" class="d-inline">
                                                            @csrf
                                                            <input type="hidden" name="state" value="1">
                                                            <button type="submit" class="btn btn-outline-success" @disabled($relay['state'])>On</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('admin.modbus-diagnostics.set-relay', ['accessSource' => $source['id'], 'channel' => $relay['channel']]) }}" class="d-inline">
                                                            @csrf
                                                            <input type="hidden" name="state" value="0">
                                                            <button type="submit" class="btn btn-outline-secondary" @disabled(! $relay['state'])>Off</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('admin.modbus-diagnostics.set-relay', ['accessSource' => $source['id'], 'channel' => $relay['channel']]) }}" class="d-inline">
                                                            @csrf
                                                            <input type="hidden" name="state" value="toggle">
                                                            <button type="submit" class="btn btn-outline-primary">Toggle</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="text-muted text-center py-3">No relay channels available.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-6">
                        <div class="border rounded p-3 h-100">
                            <h3 class="h6 mb-3">Digital Inputs</h3>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Input</th>
                                            <th>Address</th>
                                            <th>State</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($source['input_channels'] as $input)
                                            <tr>
                                                <td>{{ $input['channel'] }}</td>
                                                <td><code>{{ $input['address'] }}</code></td>
                                                <td>
                                                    <span class="badge text-bg-{{ $input['state'] ? 'success' : 'secondary' }}">
                                                        {{ $input['state'] ? 'HIGH' : 'LOW' }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3" class="text-muted text-center py-3">No digital inputs available.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="card shadow-sm">
            <div class="card-body text-center py-5 text-muted">No enabled Modbus sources found.</div>
        </div>
    @endforelse

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Input Binding Telemetry</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Binding</th>
                        <th>Source / Channel</th>
                        <th>Target</th>
                        <th>State</th>
                        <th>Last Seen</th>
                        <th>Last Dispatch</th>
                        <th>Dispatch Count</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($diagnostics['bindings'] as $binding)
                        <tr>
                            <td>
                                <div>#{{ $binding['id'] }} <code>{{ $binding['action_key'] }}</code></div>
                                <div class="small text-muted">{{ strtoupper($binding['adapter_type']) }} | {{ $binding['enabled'] ? 'enabled' : 'disabled' }}</div>
                            </td>
                            <td>
                                <div>{{ $binding['source_identifier'] ?? 'n/a' }}</div>
                                <div><code>{{ $binding['channel'] ?? 'n/a' }}</code></div>
                            </td>
                            <td>{{ $binding['target_label'] ?? ($binding['target_type'].'#'.$binding['target_id']) }}</td>
                            <td>
                                @if (is_bool($binding['effective_active'] ?? null))
                                    <span class="badge text-bg-{{ $binding['effective_active'] ? 'success' : 'secondary' }}">{{ $binding['effective_active'] ? 'HIGH' : 'LOW' }}</span>
                                @else
                                    <span class="badge text-bg-warning">unknown</span>
                                @endif
                                <div class="small text-muted">reversed={{ $binding['signal_reversed'] ? 'yes' : 'no' }} source={{ $binding['state_source'] ?? 'n/a' }}</div>
                            </td>
                            <td>
                                @if (is_array($binding['last_seen']))
                                    <div>{{ $binding['last_seen']['seen_at'] ?? 'n/a' }}</div>
                                    <div class="small text-muted">addr={{ $binding['last_seen']['address'] ?? 'n/a' }} raw={{ is_scalar($binding['last_seen']['raw'] ?? null) ? (string) $binding['last_seen']['raw'] : 'n/a' }}</div>
                                @else
                                    n/a
                                @endif
                            </td>
                            <td>{{ $binding['last_dispatch_at'] ?? 'n/a' }}</td>
                            <td>{{ $binding['dispatch_count'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No Modbus input bindings found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($autoRefreshSeconds > 0)
        <script>
            window.setTimeout(function () {
                window.location.reload();
            }, {{ $autoRefreshSeconds * 1000 }});
        </script>
    @endif
@endsection
