<?php

use Illuminate\Support\Facades\Route;
use OTGH\AccessControl\ModbusAdapter\Http\Controllers\Admin\Health\ModbusDiagnosticsController;

Route::middleware(['web', 'auth'])
    ->prefix('admin/health')
    ->group(function (): void {
        Route::get('/modbus-diagnostics', [ModbusDiagnosticsController::class, '__invoke'])
            ->name('admin.modbus-diagnostics');

        Route::post('/modbus-diagnostics/{accessSource}/relay/{channel}', [ModbusDiagnosticsController::class, 'setRelay'])
            ->whereNumber('channel')
            ->name('admin.modbus-diagnostics.set-relay');
    });
