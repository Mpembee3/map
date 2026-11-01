<?php

use App\Models\Flight;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Flight map')] class extends Component
{
    public ?int $flightId = null;

    public function mount(): void
    {
        $this->flightId = Flight::query()->latest('started_at')->value('id');
    }

    /**
     * @return Collection<int, Flight>
     */
    #[Computed]
    public function flights(): Collection
    {
        return Flight::query()->latest('started_at')->get();
    }

    #[Computed]
    public function flight(): ?Flight
    {
        return $this->flights->firstWhere('id', $this->flightId);
    }

    /**
     * @return array{samples: int, positions: int, withoutFix: int, suspect: int, peakJamIndicator: float|null, interferenceStartedAt: string|null, interferenceEndedAt: string|null}|null
     */
    #[Computed]
    public function summary(): ?array
    {
        return $this->flight?->summary();
    }
};
?>

<div class="flex min-h-screen flex-col bg-zinc-50 text-zinc-900 lg:h-screen lg:flex-row dark:bg-zinc-950 dark:text-zinc-100">
    @if ($this->flight)
        <div
            wire:key="flight-{{ $this->flight->id }}"
            x-data="flightMap(@js(route('flights.track', $this->flight, false)), @js(asset('tiles')))"
            class="contents"
        >
            <aside class="flex w-full shrink-0 flex-col gap-5 overflow-y-auto border-zinc-200 p-4 lg:w-80 lg:border-r dark:border-zinc-800">
                <h1 class="text-lg font-semibold">{{ __('Flight map') }}</h1>

                @if ($this->flights->count() > 1)
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Flight') }}</span>
                        <select wire:model.live="flightId" class="rounded border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                            @foreach ($this->flights as $flight)
                                <option wire:key="option-{{ $flight->id }}" value="{{ $flight->id }}">{{ $flight->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Start') }}</dt>
                    <dd class="text-right tabular-nums">{{ $this->flight->started_at?->format('Y-m-d H:i:s') }} UTC</dd>
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('End') }}</dt>
                    <dd class="text-right tabular-nums">{{ $this->flight->ended_at?->format('Y-m-d H:i:s') }} UTC</dd>
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Positions') }}</dt>
                    <dd class="text-right tabular-nums">{{ number_format($this->summary['positions']) }}</dd>
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Without a fix') }}</dt>
                    <dd class="text-right tabular-nums">{{ number_format($this->summary['withoutFix']) }}</dd>
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Suspect points') }}</dt>
                    <dd class="text-right tabular-nums">{{ number_format($this->summary['suspect']) }}</dd>
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Peak jamind') }}</dt>
                    <dd class="text-right tabular-nums">{{ $this->summary['peakJamIndicator'] ?? 'n/a' }}</dd>
                </dl>

                @if ($this->summary['interferenceStartedAt'])
                    <p class="rounded border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                        <span class="font-medium">{{ __('High jamind') }}:</span>
                        <span class="tabular-nums">{{ substr($this->summary['interferenceStartedAt'], 11) }} to {{ substr($this->summary['interferenceEndedAt'], 11) }} UTC</span>
                    </p>
                @endif

                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-zinc-600 dark:text-zinc-400">{{ __('Colour track by') }}</span>
                    <select x-model="metricKey" class="rounded border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                        <option value="jamind">jamind</option>
                        <option value="altitude">Alt (HAE)</option>
                        <option value="hdop">HDOP</option>
                    </select>
                </label>

                <ul class="flex flex-col gap-1 text-sm">
                    <template x-for="band in metric.bands" :key="band.label">
                        <li class="flex items-center gap-2">
                            <span class="h-1 w-6 rounded-full" :style="{ backgroundColor: band.color }"></span>
                            <span x-text="band.label"></span>
                        </li>
                    </template>
                    <li class="flex items-center gap-2">
                        <span class="w-6 border-t-2 border-dashed border-zinc-500"></span>
                        <span>{{ __('No position') }}</span>
                    </li>
                </ul>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" x-model="hideSuspect">
                    <span>{{ __('Hide suspect points') }}</span>
                </label>

                <p x-show="errorMessage" x-text="errorMessage" class="text-sm font-medium text-red-700 dark:text-red-400"></p>
            </aside>

            <main class="relative h-[70vh] grow lg:h-full" wire:ignore>
                <div x-ref="map" class="size-full"></div>
                <p x-show="isLoading" class="absolute top-3 right-3 z-[1000] rounded bg-white px-2 py-1 text-sm text-zinc-900 shadow">{{ __('Loading track…') }}</p>
            </main>
        </div>
    @else
        <main class="m-auto flex flex-col gap-2 p-6 text-center">
            <h1 class="text-lg font-semibold">{{ __('No flights imported') }}</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Add a CSV log to the data folder, then run') }} <code>php artisan gnss:import</code></p>
        </main>
    @endif
</div>
