<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Monitoring;

use App\Enums\EventKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Monitoring\AlertNotifier;
use App\Monitoring\EventRecorder;
use App\Sending\SendScheduler;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Superadmin: pacing, limits and alerts, without a deploy. */
final class SettingsController extends Controller
{
    public function edit(Settings $settings, AlertNotifier $alerts): View
    {
        return view('monitoring.settings', [
            'settings' => $settings->all(),
            'alertsConfigured' => $alerts->isConfigured(),
        ]);
    }

    public function update(SettingsRequest $request, Settings $settings, EventRecorder $events): RedirectResponse
    {
        $before = $settings->all();
        $settings->update($request->settings(), $request->user()->id);
        $changed = array_diff_assoc(array_map('strval', $settings->all()), array_map('strval', $before));

        if ($changed !== []) {
            $events->info(EventKind::System, 'Settings changed', ['changed' => $changed], userId: $request->user()->id);
        }

        return back()->with('success', __('settings.saved'));
    }

    public function testAlert(Request $request, AlertNotifier $alerts): JsonResponse
    {
        $ok = $alerts->send('✅ <b>'.e(config('app.name')).'</b>'."\n".e(__('settings.test_alert_text', ['name' => $request->user()->name])));

        return response()->json(['ok' => $ok, 'message' => $ok ? __('settings.test_alert_sent') : __('settings.test_alert_failed')], $ok ? 200 : 422);
    }

    /** Runs the minute tick (repair sweep, queue due sends) now instead of waiting for it. */
    public function sweep(SendScheduler $scheduler): JsonResponse
    {
        $scheduler->tick();

        return response()->json(['ok' => true, 'message' => __('settings.sweep_done')]);
    }
}
