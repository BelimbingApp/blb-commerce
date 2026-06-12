<?php

use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use Illuminate\Database\Eloquent\Collection;

/** @var Settings $this */
/** @var array<string, mixed> $group */
/** @var string $groupId */
/** @var Collection<int, AccountResource> $accountResources */
/** @var Collection<int, ProductTemplate> $productTemplates */
/** @var string|null $environment */
$paymentPolicies = $accountResources->where('kind', AccountResource::KIND_PAYMENT_POLICY);
$fulfillmentPolicies = $accountResources->where('kind', AccountResource::KIND_FULFILLMENT_POLICY);
$returnPolicies = $accountResources->where('kind', AccountResource::KIND_RETURN_POLICY);
$inventoryLocations = $accountResources->where('kind', AccountResource::KIND_INVENTORY_LOCATION);

$ebaySettingsTabs = [
    ['id' => 'connection', 'label' => __('Connection'), 'icon' => 'heroicon-o-link'],
    ['id' => 'defaults', 'label' => __('Seller defaults'), 'icon' => 'heroicon-o-adjustments-horizontal'],
    ['id' => 'categories', 'label' => __('Categories'), 'icon' => 'heroicon-o-tag'],
];
?>

<div>
    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle" />

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.tabs :tabs="$ebaySettingsTabs" default="connection">
            {{-- 1. Connection: credentials and verification, one tab, two cards --}}
            <x-ui.tab id="connection">
                <div class="space-y-section-gap">
                    {{-- Card 1 — app credentials and OAuth setup fields --}}
                    <x-ui.card>
                        <form wire:submit="save" class="space-y-6">
                            @if (($group['fields'] ?? []) === [])
                                <p class="text-sm text-muted">{{ __('No editable settings are registered for this page.') }}</p>
                            @else
                                <div wire:key="settings-group-{{ $groupId }}">
                                    @if ($group['help_title'] ?? null)
                                        <div class="mb-5" x-data="{ helpOpen: false }">
                                            <div class="flex items-center gap-2">
                                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Credentials & OAuth') }}</h2>
                                                <x-ui.help size="lg" @click="helpOpen = !helpOpen" ::aria-expanded="helpOpen" />
                                            </div>

                                            <div
                                                x-cloak
                                                x-show="helpOpen"
                                                x-transition:enter="transition-all ease-out duration-200 motion-reduce:duration-0"
                                                x-transition:enter-start="max-h-0 opacity-0"
                                                x-transition:enter-end="max-h-96 opacity-100"
                                                x-transition:leave="transition-all ease-in duration-150 motion-reduce:duration-0"
                                                x-transition:leave-start="max-h-96 opacity-100"
                                                x-transition:leave-end="max-h-0 opacity-0"
                                                class="mt-3 overflow-hidden rounded-2xl border border-border-default bg-surface-subtle text-sm text-muted"
                                                @click="helpOpen = false"
                                                role="note"
                                                aria-label="{{ __('Click to dismiss') }}"
                                            >
                                                <div class="space-y-3 p-4">
                                                    <div>
                                                        <p class="text-sm font-medium text-ink">{{ __($group['help_title']) }}</p>
                                                        @if ($group['help_intro'] ?? null)
                                                            <p class="mt-1 text-sm text-muted">{!! __($group['help_intro']) !!}</p>
                                                        @endif
                                                    </div>

                                                    @if (($group['help_steps'] ?? []) !== [])
                                                        <ol class="list-decimal space-y-1.5 pl-5 text-sm text-muted">
                                                            @foreach ($group['help_steps'] as $step)
                                                                @if (is_array($step))
                                                                    <li>
                                                                        {{ __($step['before_link'] ?? '') }}
                                                                        <a href="{{ $step['url'] }}" target="_blank" rel="noreferrer" class="text-accent hover:underline">
                                                                            {{ __($step['link_label']) }}
                                                                        </a>
                                                                        {!! __($step['after_link'] ?? '') !!}
                                                                    </li>
                                                                @else
                                                                    <li>{!! __($step) !!}</li>
                                                                @endif
                                                            @endforeach
                                                        </ol>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @include('livewire.settings.partials.fields-grid', ['group' => $group])
                                </div>
                            @endif

                            <div class="flex items-center gap-3">
                                <x-ui.button type="submit" variant="primary">
                                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                    {{ __('Save Settings') }}
                                </x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>

                    {{-- Card 2 — connection & endpoint diagnostics (plan ham/06) --}}
                    @php
                        $selectedProbe = $diagnosticProbes[$this->diagnosticProbeKey] ?? null;
                        $ranProbe = isset($this->diagnostics['probe_key'])
                            ? ($diagnosticProbes[$this->diagnostics['probe_key']] ?? null)
                            : null;
                        $diagQuery = collect($this->diagnostics['query'] ?? [])
                            ->map(fn ($value, $key) => $key.'='.$value)
                            ->implode('&');
                    @endphp
                    <x-ui.card>
                        <div class="space-y-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0 space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Connection & diagnostics') }}</h2>
                                        @if ($environment)
                                            <x-ui.badge :variant="$environment === 'live' ? 'success' : 'warning'">
                                                {{ $environment === 'live' ? __('Live') : __('Sandbox') }}
                                            </x-ui.badge>
                                        @endif
                                        <x-ui.badge :variant="$this->diagnosticsBadgeVariant()">
                                            {{ __(\Illuminate\Support\Str::headline($this->diagnostics['status'] ?? 'not run')) }}
                                        </x-ui.badge>
                                    </div>
                                    <p class="text-sm text-muted">
                                        {{ __('Run a read-only eBay API probe to verify the saved credentials, OAuth grant, environment, and the scope and endpoint a probe needs. Each run records an integration exchange you can open for the full payload.') }}
                                    </p>
                                </div>

                                <x-ui.button type="button" variant="outline" wire:click="connect" wire:loading.attr="disabled" wire:target="connect" class="shrink-0">
                                    <x-icon name="heroicon-o-link" class="h-4 w-4" />
                                    {{ $this->connectButtonLabel() }}
                                </x-ui.button>
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <x-ui.select
                                    id="ebay-diagnostic-probe"
                                    wire:model.live="diagnosticProbeKey"
                                    :label="__('Diagnostic probe')"
                                    :help="$selectedProbe?->intent"
                                    class="sm:max-w-sm"
                                >
                                    @foreach ($diagnosticProbes as $key => $probe)
                                        <option value="{{ $key }}">{{ $probe->label }}</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.button type="button" variant="primary" wire:click="runDiagnostics" wire:loading.attr="disabled" wire:target="runDiagnostics">
                                    <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="runDiagnostics">{{ __('Run diagnostics') }}</span>
                                    <span wire:loading wire:target="runDiagnostics">{{ __('Running...') }}</span>
                                </x-ui.button>
                            </div>

                            @if ($this->diagnostics['message'] ?? null)
                                <x-ui.alert :variant="$this->diagnosticsAlertVariant()">
                                    {{ $this->diagnostics['message'] }}
                                </x-ui.alert>
                            @else
                                <p class="text-sm text-muted">{{ __('Not run yet.') }}</p>
                            @endif

                            @if ($this->diagnostics['tested_at'] ?? null)
                                <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Probe') }}</dt>
                                        <dd class="mt-1 text-ink">{{ $ranProbe?->label ?? $this->diagnostics['probe_key'] ?? '—' }}</dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Request') }}</dt>
                                        <dd class="mt-1 break-all font-mono text-ink">{{ ($this->diagnostics['method'] ?? 'GET').' '.($this->diagnostics['endpoint'] ?? '') }}</dd>
                                    </div>
                                    @if ($diagQuery !== '')
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Query') }}</dt>
                                            <dd class="mt-1 break-all font-mono text-ink">{{ $diagQuery }}</dd>
                                        </div>
                                    @endif
                                    @if ($this->diagnostics['http_status'] ?? null)
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('HTTP status') }}</dt>
                                            <dd class="mt-1 font-mono text-ink">{{ $this->diagnostics['http_status'] }}</dd>
                                        </div>
                                    @endif
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Last checked') }}</dt>
                                        <dd class="mt-1 text-ink">{{ \Illuminate\Support\Carbon::parse($this->diagnostics['tested_at'])->diffForHumans() }}</dd>
                                    </div>
                                    @if ($this->diagnostics['response_excerpt'] ?? null)
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Response') }}</dt>
                                            <dd class="mt-1 break-all font-mono text-ink">{{ $this->diagnostics['response_excerpt'] }}</dd>
                                        </div>
                                    @endif
                                    @if ($this->diagnostics['exchange_id'] ?? null)
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Exchange') }}</dt>
                                            <dd class="mt-1 break-all font-mono text-ink">
                                                @if ($canViewExchanges)
                                                    <a href="{{ route('admin.integration.outbound-exchanges.show', $this->diagnostics['exchange_id']) }}" class="text-accent hover:underline">
                                                        {{ __('Open exchange') }}
                                                    </a>
                                                @else
                                                    {{ $this->diagnostics['exchange_id'] }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endif
                                </dl>
                            @endif
                        </div>
                    </x-ui.card>
                </div>
            </x-ui.tab>

            {{-- 2. Seller defaults: imported policies and locations --}}
            <x-ui.tab id="defaults">
                {{-- Account setup: one-time, operator-visible eBay account preparation. --}}
                <x-ui.card class="mb-6">
                    <div class="space-y-6">
                        <div class="min-w-0 space-y-2">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Account setup') }}</h2>
                            <p class="text-sm text-muted">
                                {{ __('Three one-time steps eBay needs before you can list anything — do them top to bottom. Each step talks to your connected eBay account and shows the result right here. All are safe to run again.') }}
                            </p>
                        </div>

                        @php $policiesExist = $paymentPolicies->isNotEmpty() || $returnPolicies->isNotEmpty() || $fulfillmentPolicies->isNotEmpty(); @endphp

                        {{-- Step 1 — Turn on Business Policies --}}
                        <div class="space-y-3 border-t border-line pt-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-medium text-ink">{{ __('Step 1 — Turn on Business Policies') }}</h3>
                                @if ($businessPoliciesOptedIn === true || $policiesExist)
                                    <x-ui.badge variant="success">{{ __('On') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="warning">{{ __('Not set up yet') }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="text-xs text-muted">
                                {{ __('"Business Policies" is an eBay setting that lets you reuse shipping, payment, and return rules across listings. It must be on before Steps 2 and 3 will work. The button switches it on at eBay (or confirms it is already on) — it creates nothing, and clicking it again does no harm.') }}
                            </p>
                            <div>
                                <x-ui.button type="button" variant="primary" wire:click="optInToBusinessPolicies" wire:loading.attr="disabled" wire:target="optInToBusinessPolicies">
                                    <x-icon name="heroicon-o-check-badge" class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="optInToBusinessPolicies">{{ ($businessPoliciesOptedIn === true || $policiesExist) ? __('Re-check Business Policies') : __('Turn on Business Policies') }}</span>
                                    <span wire:loading wire:target="optInToBusinessPolicies">{{ __('Working…') }}</span>
                                </x-ui.button>
                            </div>
                            @if (! empty($setupFeedback['optin']))
                                <x-ui.alert :variant="$setupFeedback['optin']['variant']">{{ $setupFeedback['optin']['message'] }}</x-ui.alert>
                            @endif
                        </div>

                        {{-- Step 2 — Shipping location --}}
                        <div class="space-y-3 border-t border-line pt-4">
                            <h3 class="text-sm font-medium text-ink">{{ __('Step 2 — Add a shipping location') }}</h3>
                            <p class="text-xs text-muted">
                                {{ __('eBay needs a warehouse address to ship from before any item can be listed. The button saves this address to your eBay account and makes it your default. You can change the address later — if you move, just edit it here and save again. (The name/key is a permanent label and can\'t be renamed.)') }}
                            </p>
                            @if ($inventoryLocations->isNotEmpty())
                                <p class="text-xs text-muted">
                                    <span class="font-medium text-ink">{{ __('Already on your eBay account:') }}</span>
                                    @foreach ($inventoryLocations as $loc)<span class="font-mono">{{ $loc->external_id }}</span>{{ $loc->status ? ' ('.$loc->status.')' : '' }}@unless ($loop->last), @endunless @endforeach
                                </p>
                            @else
                                <p class="text-xs text-muted">{{ __('No shipping location on your eBay account yet.') }}</p>
                            @endif
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <x-ui.input id="ebay-new-location-key" wire:model="newLocationKey" :label="__('Name/key')" :help="__('A short label, e.g. warehouse')" />
                                <x-ui.country-combobox id="ebay-new-location-country" wire:model.live="newLocationCountry" :label="__('Country')" />
                                <x-ui.combobox
                                    id="ebay-new-location-state"
                                    wire:model="newLocationState"
                                    wire:key="ebay-state-{{ $newLocationCountry }}"
                                    :label="__('State')"
                                    :placeholder="__('Select or type')"
                                    :options="$newLocationStateOptions"
                                    editable
                                    :hint="$newLocationStateOptions === [] ? __('No states on file for this country — type it in.') : null"
                                />
                                <x-ui.input id="ebay-new-location-postal" wire:model="newLocationPostal" :label="__('Postal code')" />
                                <x-ui.input id="ebay-new-location-city" wire:model="newLocationCity" :label="__('City')" />
                            </div>
                            <div>
                                <x-ui.button type="button" variant="primary" wire:click="createMerchantLocation" wire:loading.attr="disabled" wire:target="createMerchantLocation">
                                    <x-icon name="heroicon-o-map-pin" class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="createMerchantLocation">{{ __('Save location at eBay') }}</span>
                                    <span wire:loading wire:target="createMerchantLocation">{{ __('Saving…') }}</span>
                                </x-ui.button>
                            </div>
                            @if (! empty($setupFeedback['location']))
                                <x-ui.alert :variant="$setupFeedback['location']['variant']">{{ $setupFeedback['location']['message'] }}</x-ui.alert>
                            @endif
                        </div>

                        {{-- Step 3 — Policies --}}
                        <div class="space-y-3 border-t border-line pt-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-medium text-ink">{{ __('Step 3 — Set up your policies') }}</h3>
                                @if ($environment !== 'live')
                                    <x-ui.badge variant="warning">{{ __('Sandbox') }}</x-ui.badge>
                                @endif
                            </div>

                            @if ($policiesExist)
                                <div class="rounded-md border border-line p-3 text-xs text-muted">
                                    <p class="font-medium text-ink">{{ __('Policies on your eBay account:') }}</p>
                                    <ul class="mt-1 space-y-0.5">
                                        <li>{{ __('Payment:') }} {{ $paymentPolicies->pluck('name')->filter()->join(', ') ?: '—' }}</li>
                                        <li>{{ __('Shipping:') }} {{ $fulfillmentPolicies->pluck('name')->filter()->join(', ') ?: '—' }}</li>
                                        <li>{{ __('Returns:') }} {{ $returnPolicies->pluck('name')->filter()->join(', ') ?: '—' }}</li>
                                    </ul>
                                    <p class="mt-2">{{ __('To read or change the full rules of any policy, open eBay Seller Hub → Business Policies.') }}</p>
                                </div>
                            @else
                                <p class="text-xs text-muted">{{ __('No policies on your eBay account yet.') }}</p>
                            @endif

                            @if ($environment !== 'live')
                                <p class="text-xs text-muted">
                                    {{ __('For testing, the button creates one of each policy you are missing — payment (buyer pays immediately), returns (30-day, item replaced, you pay return shipping), and shipping (USPS Priority flat $9.99, 2-day handling) — and selects them as your defaults below.') }}
                                </p>
                                <div>
                                    <x-ui.button type="button" variant="primary" wire:click="createStarterPolicies" wire:loading.attr="disabled" wire:target="createStarterPolicies">
                                        <x-icon name="heroicon-o-document-plus" class="h-4 w-4" />
                                        <span wire:loading.remove wire:target="createStarterPolicies">{{ __('Create starter policies') }}</span>
                                        <span wire:loading wire:target="createStarterPolicies">{{ __('Creating…') }}</span>
                                    </x-ui.button>
                                </div>
                            @else
                                <p class="text-xs text-muted">
                                    {{ __('On your live account, create and edit policies in eBay Seller Hub → Business Policies, then use "Refresh from eBay" below to pull them in and choose your defaults.') }}
                                </p>
                            @endif

                            @if (! empty($setupFeedback['policies']))
                                <x-ui.alert :variant="$setupFeedback['policies']['variant']">{{ $setupFeedback['policies']['message'] }}</x-ui.alert>
                            @endif
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <div class="space-y-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0 space-y-2">
                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Seller setup choices') }}</h2>
                                <p class="text-sm text-muted">
                                    {{ __('Import eBay business policies and merchant locations, then choose the defaults Belimbing should use for listing drafts.') }}
                                </p>
                            </div>

                            <x-ui.button type="button" variant="outline" wire:click="importAccountSetup" wire:loading.attr="disabled" wire:target="importAccountSetup">
                                <x-icon name="heroicon-o-arrow-path" class="h-4 w-4" />
                                <span wire:loading.remove wire:target="importAccountSetup">{{ __('Refresh from eBay') }}</span>
                                <span wire:loading wire:target="importAccountSetup">{{ __('Refreshing...') }}</span>
                            </x-ui.button>
                        </div>

                        @if ($accountResources->isEmpty())
                            <x-ui.alert variant="info">
                                {{ __('No eBay setup choices have been imported yet. Connect eBay, grant the Sell Account and Sell Inventory scopes, then refresh from eBay.') }}
                            </x-ui.alert>
                        @else
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-ui.select
                                    id="ebay-default-payment-policy"
                                    wire:model="defaultPaymentPolicyId"
                                    :label="__('Default payment policy')"
                                    :help="__('Payment policy used when a draft does not choose a different eBay policy.')"
                                >
                                    <option value="">{{ __('Choose when publishing') }}</option>
                                    @foreach ($paymentPolicies as $policy)
                                        <option value="{{ $policy->external_id }}">{{ $policy->name }} ({{ $policy->external_id }})</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.select
                                    id="ebay-default-fulfillment-policy"
                                    wire:model="defaultFulfillmentPolicyId"
                                    :label="__('Default fulfillment policy')"
                                    :help="__('Shipping/handling policy used when a draft does not choose a different eBay policy.')"
                                >
                                    <option value="">{{ __('Choose when publishing') }}</option>
                                    @foreach ($fulfillmentPolicies as $policy)
                                        <option value="{{ $policy->external_id }}">{{ $policy->name }} ({{ $policy->external_id }})</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.select
                                    id="ebay-default-return-policy"
                                    wire:model="defaultReturnPolicyId"
                                    :label="__('Default return policy')"
                                    :help="__('Return policy used when a draft does not choose a different eBay policy.')"
                                >
                                    <option value="">{{ __('Choose when publishing') }}</option>
                                    @foreach ($returnPolicies as $policy)
                                        <option value="{{ $policy->external_id }}">{{ $policy->name }} ({{ $policy->external_id }})</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.select
                                    id="ebay-default-merchant-location"
                                    wire:model="defaultMerchantLocationKey"
                                    :label="__('Default merchant location')"
                                    :help="__('Inventory location key used when a draft does not choose a different eBay location.')"
                                >
                                    <option value="">{{ __('Choose when publishing') }}</option>
                                    @foreach ($inventoryLocations as $location)
                                        <option value="{{ $location->external_id }}">
                                            {{ $location->name }} ({{ $location->external_id }}){{ $location->status ? ' · '.$location->status : '' }}
                                        </option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <div class="flex items-center gap-3">
                                <x-ui.button type="button" variant="primary" wire:click="saveAccountSetupDefaults" wire:loading.attr="disabled" wire:target="saveAccountSetupDefaults">
                                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                    {{ __('Save setup defaults') }}
                                </x-ui.button>
                                <p class="text-xs text-muted">
                                    {{ __('Imported choices are refreshed from eBay; selected defaults are stored in Belimbing settings.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                </x-ui.card>
            </x-ui.tab>

            {{-- 3. Categories: template-to-eBay category mappings --}}
            <x-ui.tab id="categories">
                <x-ui.card>
                    <div class="space-y-5">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('eBay category mappings') }}</h2>
                            <p class="mt-1 text-sm text-muted">
                                {{ __('Every eBay listing sits in one eBay category — it decides where buyers find the item and which item specifics eBay requires. Map each template once and every item using it inherits the category. Changes save immediately.') }}
                            </p>
                        </div>

                        @if ($productTemplates->isEmpty())
                            <x-ui.alert variant="info">{{ __('No catalog templates exist yet. Create templates in Catalog before mapping eBay categories.') }}</x-ui.alert>
                        @else
                            <x-ui.table container="flush" :caption="__('eBay category mappings')">

                                <x-slot name="head">
                                        <tr>
                                            <x-ui.th>{{ __('Template') }}</x-ui.th>
                                            <x-ui.th>{{ __('Marketplace') }}</x-ui.th>
                                            <x-ui.th>{{ __('eBay category') }}</x-ui.th>
                                        </tr>
                                    </x-slot>

                                        @foreach ($productTemplates as $template)
                                            @php($mapping = $templateCategoryMappings[$template->id] ?? [])
                                            <tr wire:key="ebay-template-category-{{ $template->id }}">
                                                <td class="px-table-cell-x py-table-cell-y align-middle">
                                                    <div class="text-sm font-medium text-ink">{{ $template->name }}</div>
                                                    <div class="mt-1 text-xs text-muted">{{ $template->category?->name ?? __('Any category') }}</div>
                                                </td>
                                                <td class="px-table-cell-x py-table-cell-y min-w-48 align-middle">
                                                    {{-- The category tree id is derived from this choice; it is
                                                         never asked of the operator. --}}
                                                    <x-ui.select
                                                        id="ebay-template-{{ $template->id }}-marketplace"
                                                        wire:model.live="templateCategoryMappings.{{ $template->id }}.marketplace_id"
                                                        aria-label="{{ __('Marketplace for :template', ['template' => $template->name]) }}"
                                                        :error="$errors->first('templateCategoryMappings.' . $template->id . '.marketplace_id')"
                                                    >
                                                        <option value="">{{ __('Select...') }}</option>
                                                        @foreach (\App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration::MARKETPLACES as $marketplaceId => $marketplaceLabel)
                                                            <option value="{{ $marketplaceId }}">{{ $marketplaceLabel }}</option>
                                                        @endforeach
                                                    </x-ui.select>
                                                </td>
                                                <td class="px-table-cell-x py-table-cell-y min-w-72 align-middle">
                                                    @if (($mapping['category_id'] ?? null) !== null && trim((string) $mapping['category_id']) !== '')
                                                        <div class="flex items-center justify-between gap-3">
                                                            <div class="min-w-0">
                                                                <p class="text-sm font-medium text-ink">{{ $mapping['category_label'] ?: __('Category #:id', ['id' => $mapping['category_id']]) }}</p>
                                                                <p class="mt-0.5 font-mono text-xs text-muted">#{{ $mapping['category_id'] }}</p>
                                                            </div>
                                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="openCategoryPicker({{ $template->id }})">
                                                                {{ __('Change') }}
                                                            </x-ui.button>
                                                        </div>
                                                    @else
                                                        <x-ui.button type="button" variant="outline" size="sm" wire:click="openCategoryPicker({{ $template->id }})">
                                                            <x-icon name="heroicon-o-magnifying-glass" class="h-4 w-4" />
                                                            {{ __('Choose category') }}
                                                        </x-ui.button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach

                            </x-ui.table>

                            @php($pickerTemplate = $categoryPickerTemplateId !== null ? $productTemplates->firstWhere('id', $categoryPickerTemplateId) : null)
                            @php($pickerMapping = $categoryPickerTemplateId !== null ? ($templateCategoryMappings[$categoryPickerTemplateId] ?? []) : [])
                            <x-ui.modal wire:model="categoryPickerOpen" class="max-w-xl">
                                <div class="space-y-4 p-6">
                                    <div>
                                        <h3 class="text-base font-medium tracking-tight text-ink">{{ __('eBay category for “:template”', ['template' => $pickerTemplate?->name ?? '']) }}</h3>
                                        <p class="mt-1 text-sm text-muted">{{ __('Search by part type and pick the best fit. The choice saves immediately and eBay’s rules for that category are fetched in the background.') }}</p>
                                    </div>

                                    @if (($pickerMapping['category_id'] ?? null) !== null && trim((string) $pickerMapping['category_id']) !== '')
                                        <div class="flex items-center justify-between gap-3 rounded-xl border border-border-default bg-surface-subtle px-3 py-2">
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-ink">{{ $pickerMapping['category_label'] ?: __('Category #:id', ['id' => $pickerMapping['category_id']]) }}</p>
                                                <p class="mt-0.5 font-mono text-xs text-muted">#{{ $pickerMapping['category_id'] }}</p>
                                            </div>
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="removeCategoryMapping" wire:confirm="{{ __('Remove this template’s eBay category mapping?') }}">
                                                {{ __('Remove') }}
                                            </x-ui.button>
                                        </div>
                                    @endif

                                    <div class="flex items-end gap-2">
                                        <div class="flex-1">
                                            <x-ui.input
                                                id="ebay-category-picker-search"
                                                wire:model="categorySearch"
                                                wire:keydown.enter.prevent="searchEbayCategories"
                                                :label="__('Search eBay categories')"
                                                :placeholder="__('Part type, e.g. alternator')"
                                                :error="$errors->first('categorySearch')"
                                            />
                                        </div>
                                        <x-ui.button type="button" variant="primary" size="sm" wire:click="searchEbayCategories" wire:loading.attr="disabled" wire:target="searchEbayCategories">
                                            <x-icon name="heroicon-o-magnifying-glass" class="h-4 w-4" />
                                            {{ __('Search') }}
                                        </x-ui.button>
                                    </div>

                                    @if ($categorySuggestions !== [])
                                        <ul class="max-h-72 space-y-1 overflow-y-auto">
                                            @foreach ($categorySuggestions as $index => $suggestion)
                                                <li wire:key="ebay-category-picker-suggestion-{{ $index }}">
                                                    <button
                                                        type="button"
                                                        wire:click="applyEbayCategorySuggestion({{ $index }})"
                                                        class="w-full rounded-xl border border-border-default bg-surface-subtle px-3 py-2 text-left hover:border-accent/60 hover:bg-surface-card"
                                                    >
                                                        <span class="block text-sm font-medium text-ink">{{ $suggestion['name'] }}</span>
                                                        <span class="mt-0.5 block text-xs text-muted">{{ $suggestion['path'] }} <span class="font-mono">#{{ $suggestion['category_id'] }}</span></span>
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <x-ui.disclosure :title="__('Enter the ID manually')" title-class="text-xs font-medium text-muted" content-class="mt-2">
                                        <div class="flex items-end gap-2">
                                            <div class="flex-1">
                                                <x-ui.input
                                                    id="ebay-category-picker-manual-id"
                                                    wire:model="templateCategoryMappings.{{ $categoryPickerTemplateId ?? 0 }}.category_id"
                                                    :label="__('eBay category ID')"
                                                    :help="__('Numeric id, if you already know it. The category tree is resolved automatically.')"
                                                    :error="$errors->first('templateCategoryMappings.' . ($categoryPickerTemplateId ?? 0) . '.category_id')"
                                                />
                                            </div>
                                            <x-ui.button type="button" variant="outline" size="sm" wire:click="saveManualCategory">
                                                {{ __('Save') }}
                                            </x-ui.button>
                                        </div>
                                    </x-ui.disclosure>

                                    <div class="flex justify-end">
                                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeCategoryPicker">
                                            {{ __('Close') }}
                                        </x-ui.button>
                                    </div>
                                </div>
                            </x-ui.modal>
                        @endif
                    </div>
                </x-ui.card>
            </x-ui.tab>
        </x-ui.tabs>
    </div>
</div>
