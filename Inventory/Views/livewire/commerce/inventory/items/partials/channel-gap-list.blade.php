<?php
/** @var iterable<int, array<string, mixed>> $gaps */
/** @var array<string, array{label: string, href: string|null}> $gapLinks */
/** @var string $bulletClass */
?>
<ul class="mt-1 space-y-1">
    @foreach ($gaps as $gap)
        <li class="flex gap-1.5">
            <span class="{{ $bulletClass }}">•</span>
            <span>
                {{ $gap['label'] ?? __('Readiness gap') }}
                @if (isset($gap['action'], $gapLinks[$gap['action']]) && $gapLinks[$gap['action']]['href'])
                    @if ($gap['action'] === 'settings')
                        <a href="{{ $gapLinks[$gap['action']]['href'] }}" class="ml-1 font-medium text-accent hover:underline" wire:navigate>{{ $gapLinks[$gap['action']]['label'] }}</a>
                    @else
                        <a href="{{ $gapLinks[$gap['action']]['href'] }}" class="ml-1 font-medium text-accent hover:underline">{{ $gapLinks[$gap['action']]['label'] }}</a>
                    @endif
                @endif
            </span>
        </li>
    @endforeach
</ul>
