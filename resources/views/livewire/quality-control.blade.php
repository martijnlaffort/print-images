<div @if($processing) wire:poll.3s="checkStatus" @endif>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Kwaliteitscontrole</h1>
        <div class="flex gap-2">
            <button wire:click="runForAllPosters" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                QC alle posters
            </button>
            <button wire:click="runForFolder" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                QC over map&hellip;
            </button>
        </div>
    </div>

    @if($processing)
        <div class="mb-6 flex items-center gap-3 rounded-lg bg-indigo-50 border border-indigo-200 px-4 py-3">
            <x-spinner class="h-5 w-5 text-indigo-600" />
            <div class="text-sm font-medium text-indigo-700">
                QC bezig&hellip;
                @if($taskProgress)
                    <span class="text-indigo-500">{{ $taskProgress['stage'] }} ({{ $taskProgress['progress'] }}%)</span>
                @endif
            </div>
        </div>
    @endif

    {{-- Samenvatting --}}
    <div class="mb-6 grid grid-cols-3 gap-4">
        <button wire:click="$set('verdictFilter', '{{ $verdictFilter === 'pass' ? '' : 'pass' }}')"
            class="rounded-lg border p-4 text-left transition-colors {{ $verdictFilter === 'pass' ? 'border-green-400 bg-green-50' : 'border-gray-200 bg-white hover:bg-gray-50' }}">
            <p class="text-2xl font-bold text-green-600">{{ $summary['pass'] }}</p>
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Print-klaar &#9989;</p>
        </button>
        <button wire:click="$set('verdictFilter', '{{ $verdictFilter === 'warn' ? '' : 'warn' }}')"
            class="rounded-lg border p-4 text-left transition-colors {{ $verdictFilter === 'warn' ? 'border-amber-400 bg-amber-50' : 'border-gray-200 bg-white hover:bg-gray-50' }}">
            <p class="text-2xl font-bold text-amber-600">{{ $summary['warn'] }}</p>
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Waarschuwing &#9888;&#65039;</p>
        </button>
        <button wire:click="$set('verdictFilter', '{{ $verdictFilter === 'fail' ? '' : 'fail' }}')"
            class="rounded-lg border p-4 text-left transition-colors {{ $verdictFilter === 'fail' ? 'border-red-400 bg-red-50' : 'border-gray-200 bg-white hover:bg-gray-50' }}">
            <p class="text-2xl font-bold text-red-600">{{ $summary['fail'] }}</p>
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Niet printen &#10060;</p>
        </button>
    </div>

    {{-- Rapporten --}}
    <div class="rounded-lg bg-white shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Bestand</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Fase</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Ruis (sd)</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Korrel</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Modus</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">ICC</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Oordeel</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Datum</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($reports as $r)
                    <tr class="hover:bg-gray-50 cursor-pointer" wire:click="showReport({{ $r->id }})">
                        <td class="px-4 py-2 font-medium text-gray-900 max-w-[240px] truncate" title="{{ $r->source_path }}">
                            {{ $r->poster?->title ?? basename($r->source_path) }}
                        </td>
                        <td class="px-4 py-2 text-gray-500">
                            {{ ['source' => 'bron', 'denoised' => 'na denoise', 'output' => 'output'][$r->phase] ?? $r->phase }}
                        </td>
                        <td class="px-4 py-2">
                            @php($ns = $r->metrics['noise']['status'] ?? null)
                            <span class="{{ in_array($ns, ['fail', 'noisy']) ? 'text-red-600 font-semibold' : (in_array($ns, ['warn', 'acceptable']) ? 'text-amber-600' : 'text-gray-700') }}">
                                {{ number_format($r->metrics['noise']['flattest_mean_sd'] ?? 0, 2) }}
                            </span>
                            @if($ns === 'unreliable')
                                <span class="block text-[10px] text-gray-400" title="Detailrijk beeld zonder egale vlakken — beoordeel via een fysieke sample">niet meetbaar</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-700">{{ number_format($r->metrics['grain']['flattest_mean_laplacian'] ?? 0, 2) }}</td>
                        <td class="px-4 py-2">
                            @if(isset($r->metrics['mode']))
                                <span class="{{ $r->metrics['mode']['print_ready'] ? 'text-gray-700' : 'text-red-600 font-semibold' }}" title="{{ $r->metrics['mode']['colorspace'] }}{{ $r->metrics['mode']['has_alpha'] ? ' + alfakanaal' : '' }}">{{ $r->metrics['mode']['label'] }}</span>
                            @else
                                <span class="text-gray-400">?</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if($r->metrics['icc']['embedded'] ?? false)
                                <span class="text-green-600" title="{{ $r->metrics['icc']['description'] }}">&#10003;</span>
                            @else
                                <span class="text-red-500" title="Geen ICC-profiel">&mdash;</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if($r->verdict === 'pass')
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">PRINT-KLAAR &#9989;</span>
                            @elseif($r->verdict === 'warn')
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">WAARSCHUWING &#9888;&#65039;</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">NIET PRINTEN &#10060;</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-400 text-xs whitespace-nowrap">{{ $r->created_at->format('d-m H:i') }}</td>
                        <td class="px-4 py-2 text-right">
                            @if($r->poster_id)
                                <button wire:click.stop="runForPoster({{ $r->poster_id }})" class="text-xs text-indigo-600 hover:text-indigo-800" title="QC opnieuw draaien">
                                    opnieuw
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-gray-400">
                            Nog geen QC-rapporten. Start een QC-run voor alle posters of een map.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $reports->links() }}
    </div>

    {{-- Rapport-detail --}}
    @if($report)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6" wire:click.self="closeReport">
            <div class="max-h-full w-full max-w-3xl overflow-y-auto rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 sticky top-0 bg-white">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $report->poster?->title ?? basename($report->source_path) }}</h2>
                        <p class="text-xs text-gray-400">{{ $report->source_path }} &middot; fase: {{ ['source' => 'bron', 'denoised' => 'na denoise', 'output' => 'output'][$report->phase] ?? $report->phase }}</p>
                    </div>
                    <button wire:click="closeReport" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                </div>

                <div class="p-6 space-y-6">
                    {{-- Eindoordeel --}}
                    <div class="rounded-lg p-4 {{ $report->verdict === 'pass' ? 'bg-green-50 border border-green-200' : ($report->verdict === 'warn' ? 'bg-amber-50 border border-amber-200' : 'bg-red-50 border border-red-200') }}">
                        <p class="text-base font-bold {{ $report->verdict === 'pass' ? 'text-green-700' : ($report->verdict === 'warn' ? 'text-amber-700' : 'text-red-700') }}">
                            {{ $report->verdictLabel() }}
                            {{ $report->verdict === 'pass' ? '✅' : ($report->verdict === 'warn' ? '⚠️' : '❌') }}
                        </p>
                        @if($report->reasons)
                            <ul class="mt-2 list-disc pl-5 text-sm {{ $report->verdict === 'pass' ? 'text-green-700' : ($report->verdict === 'warn' ? 'text-amber-700' : 'text-red-700') }}">
                                @foreach($report->reasons as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    @php($m = $report->metrics)

                    {{-- Denoise vóór/ná --}}
                    @if($report->comparison)
                        @php($c = $report->comparison)
                        <div>
                            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Denoise-effect (v&oacute;&oacute;r &rarr; n&aacute;)</h3>
                            <div class="grid grid-cols-3 gap-3 text-sm">
                                <div class="rounded-lg border border-gray-200 p-3">
                                    <p class="text-xs text-gray-500">Ruis (vlakste blokken)</p>
                                    <p class="font-semibold text-gray-900">{{ number_format($c['noise_before'], 2) }} &rarr; {{ number_format($c['noise_after'], 2) }}
                                        <span class="text-xs {{ $c['noise_delta'] <= 0 ? 'text-green-600' : 'text-red-600' }}">({{ $c['noise_delta'] > 0 ? '+' : '' }}{{ number_format($c['noise_delta'], 2) }})</span>
                                    </p>
                                </div>
                                <div class="rounded-lg border border-gray-200 p-3">
                                    <p class="text-xs text-gray-500">Fijne korrel</p>
                                    <p class="font-semibold text-gray-900">{{ number_format($c['grain_before'], 2) }} &rarr; {{ number_format($c['grain_after'], 2) }}
                                        <span class="text-xs {{ $c['grain_delta'] <= 0 ? 'text-green-600' : 'text-red-600' }}">({{ $c['grain_delta'] > 0 ? '+' : '' }}{{ number_format($c['grain_delta'], 2) }})</span>
                                    </p>
                                </div>
                                <div class="rounded-lg border p-3 {{ $c['too_aggressive'] ? 'border-red-300 bg-red-50' : 'border-gray-200' }}">
                                    <p class="text-xs text-gray-500">Detailverlies (scherpte)</p>
                                    <p class="font-semibold {{ $c['too_aggressive'] ? 'text-red-700' : 'text-gray-900' }}">{{ number_format($c['detail_loss_percent'], 1) }}%</p>
                                    @if($c['too_aggressive'])
                                        <p class="text-xs text-red-600 mt-0.5">Te agressief &mdash; kies een lichtere instelling</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Visuele vergelijking --}}
                    @if($report->comparison_image_path && file_exists($report->comparison_image_path))
                        <div>
                            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Visuele verificatie (100% zoom, vlakste gebied)</h3>
                            <div class="rounded-lg border border-gray-200 overflow-hidden">
                                <img src="{{ route('qc.image', ['report' => $report->id]) }}" alt="V&oacute;&oacute;r/n&aacute; vergelijking" class="w-full">
                                <div class="grid grid-cols-2 text-center text-xs font-medium text-gray-500 bg-gray-50 border-t border-gray-200">
                                    <span class="py-1">V&Oacute;&Oacute;R</span>
                                    <span class="py-1 border-l border-gray-200">N&Aacute;</span>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Metingen --}}
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Metingen</h3>
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                            <div class="rounded-lg border border-gray-200 p-3">
                                <p class="text-xs text-gray-500">Ruis vlakste {{ $m['noise']['blocks_used'] ?? 50 }} blokken</p>
                                @php($ns = $m['noise']['status'] ?? '')
                                <p class="font-semibold {{ in_array($ns, ['fail', 'noisy']) ? 'text-red-600' : (in_array($ns, ['warn', 'acceptable']) ? 'text-amber-600' : ($ns === 'unreliable' ? 'text-gray-500' : 'text-green-600')) }}">
                                    sd {{ number_format($m['noise']['flattest_mean_sd'] ?? 0, 2) }}
                                    @if($ns === 'unreliable')
                                        <span class="text-xs font-normal">(niet meetbaar)</span>
                                    @endif
                                </p>
                                @if(isset($m['noise']['flattest_block_sd']))
                                    <p class="text-[11px] {{ $ns === 'unreliable' ? 'text-amber-600' : 'text-gray-400' }}">
                                        vlakste blok sd {{ number_format($m['noise']['flattest_block_sd'], 2) }} —
                                        {{ $ns === 'unreliable' ? 'geen egale vlakken: beoordeel via fysieke sample' : 'meting betrouwbaar' }}
                                    </p>
                                @endif
                                <p class="text-[11px] text-gray-400">textuur-indicatie breed: ~{{ number_format($m['noise']['flat10_approx_sd'] ?? 0, 1) }} (telt echte textuur mee; geen drempelwaarde)</p>
                            </div>
                            <div class="rounded-lg border border-gray-200 p-3">
                                <p class="text-xs text-gray-500">Fijne korrel (Laplacian)</p>
                                <p class="font-semibold {{ ($m['grain']['status'] ?? '') === 'clean' ? 'text-green-600' : (($m['grain']['status'] ?? '') === 'acceptable' ? 'text-amber-600' : 'text-red-600') }}">
                                    {{ number_format($m['grain']['flattest_mean_laplacian'] ?? 0, 2) }}
                                </p>
                            </div>
                            <div class="rounded-lg border border-gray-200 p-3">
                                <p class="text-xs text-gray-500">Scherpte (heel beeld)</p>
                                <p class="font-semibold text-gray-900">{{ number_format($m['sharpness'] ?? 0, 2) }}</p>
                            </div>
                            <div class="rounded-lg border border-gray-200 p-3">
                                <p class="text-xs text-gray-500">Kleurtemperatuur</p>
                                <p class="font-semibold text-gray-900">
                                    R&minus;B {{ ($m['color']['r_minus_b'] ?? 0) > 0 ? '+' : '' }}{{ $m['color']['r_minus_b'] ?? 0 }}
                                    <span class="text-xs text-gray-500">({{ $m['color']['indication'] ?? '' }})</span>
                                </p>
                                <p class="text-[11px] text-gray-400">R {{ $m['color']['r'] ?? 0 }} &middot; G {{ $m['color']['g'] ?? 0 }} &middot; B {{ $m['color']['b'] ?? 0 }}</p>
                                @if(isset($m['color']['saturation']))
                                    <p class="text-[11px] {{ $m['color']['saturation'] > config('posterforge.qc.color.saturation_warn', 60) ? 'text-amber-600' : 'text-gray-400' }}">
                                        verzadiging gem. {{ $m['color']['saturation'] }}%{{ $m['color']['saturation'] > config('posterforge.qc.color.saturation_warn', 60) ? ' — kan op print minder levendig ogen' : '' }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Bestand & profiel --}}
                    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 text-sm">
                        <div class="rounded-lg border border-gray-200 p-3">
                            <p class="text-xs text-gray-500">Afmetingen</p>
                            <p class="font-semibold text-gray-900">{{ $m['width'] ?? '?' }} &times; {{ $m['height'] ?? '?' }} px</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <p class="text-xs text-gray-500">Formaat</p>
                            <p class="font-semibold {{ strtoupper($m['format'] ?? '') === 'JPEG' ? 'text-red-600' : 'text-gray-900' }}">{{ $m['format'] ?? '?' }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <p class="text-xs text-gray-500">Kleurmodus</p>
                            @if(isset($m['mode']))
                                <p class="font-semibold {{ $m['mode']['print_ready'] ? 'text-gray-900' : 'text-red-600' }}">
                                    {{ $m['mode']['label'] }}{{ $m['mode']['has_alpha'] ? ' (alfakanaal!)' : '' }}
                                </p>
                            @else
                                <p class="font-semibold text-gray-400">onbekend</p>
                            @endif
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <p class="text-xs text-gray-500">DPI-metadata</p>
                            <p class="font-semibold text-gray-900">{{ $m['density']['dpi_x'] ?? '?' }} &times; {{ $m['density']['dpi_y'] ?? '?' }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <p class="text-xs text-gray-500">ICC-profiel</p>
                            @if($m['icc']['embedded'] ?? false)
                                <p class="font-semibold text-green-600 truncate" title="{{ $m['icc']['description'] }}">{{ $m['icc']['description'] }}</p>
                            @else
                                <p class="font-semibold text-red-600">Ontbreekt &#10060;</p>
                            @endif
                        </div>
                    </div>

                    {{-- DPI per verkoopformaat --}}
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Resolutie per verkoopformaat</h3>
                        <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-500">Formaat (cm)</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-500">DPI (b &times; h)</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-500">Geschikt</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($m['dpi_table'] ?? [] as $size => $row)
                                    <tr>
                                        <td class="px-3 py-1.5 font-medium text-gray-900">{{ $size }}</td>
                                        <td class="px-3 py-1.5 text-gray-700">{{ $row['width_dpi'] }} &times; {{ $row['height_dpi'] }}</td>
                                        <td class="px-3 py-1.5">
                                            @if($row['status'] === 'ideal')
                                                <span class="text-green-600 font-medium">&#9989; Ideaal (&ge;300)</span>
                                            @elseif($row['status'] === 'acceptable')
                                                <span class="text-amber-600 font-medium">&#9888;&#65039; Acceptabel (200&ndash;300)</span>
                                            @else
                                                <span class="text-red-600 font-medium">&#10060; Onvoldoende (&lt;200)</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
