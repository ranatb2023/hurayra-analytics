<?php

namespace App\Http\Controllers;

use App\Models\ChannelRule;
use App\Services\ChannelResolver;
use App\Services\MetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The channel rules admin screen: how raw utm_source / utm_medium values map
 * to the channels the Acquisition page reports.
 */
class ChannelRuleController extends Controller
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly ChannelResolver $channels,
    ) {}

    public function index(): View
    {
        return view('settings.channels', [
            'rules' => ChannelRule::query()->orderBy('priority')->orderBy('id')->get(),
            // What no rule catches yet, so a rule can be written for it.
            'unmapped' => $this->metrics->unmappedSources(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ChannelRule::create($this->validated($request));
        $this->channels->flush();

        return redirect()->route('channels.index')->with('status', 'Channel rule added.');
    }

    public function update(Request $request, ChannelRule $rule): RedirectResponse
    {
        $rule->update($this->validated($request));
        $this->channels->flush();

        return redirect()->route('channels.index')->with('status', "Rule for “{$rule->channel}” saved.");
    }

    public function destroy(ChannelRule $rule): RedirectResponse
    {
        $rule->delete();
        $this->channels->flush();

        return redirect()->route('channels.index')->with('status', 'Channel rule deleted.');
    }

    /** @return array{priority:int, channel:string, sources:string, mediums:?string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:0', 'max:100000'],
            'channel' => ['required', 'string', 'max:64'],
            'sources' => ['required', 'string', 'max:2000'],
            'mediums' => ['nullable', 'string', 'max:2000'],
        ]);

        // Stored normalised, so the list on screen is the list that matches.
        $data['sources'] = implode(', ', ChannelRule::parseList($data['sources']));
        $mediums = ChannelRule::parseList($data['mediums'] ?? null);
        $data['mediums'] = $mediums === [] ? null : implode(', ', $mediums);

        return $data;
    }
}
