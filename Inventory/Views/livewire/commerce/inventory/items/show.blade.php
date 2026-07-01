<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Show;

/** @var Show $this */
/** @var list<array<string, mixed>> $channelRows */
/** @var list<array{id: string, label: string, description: string|null, entries: list<array{code: string, severity: string, label: string, description?: string, action?: string}>}> $extensionReadinessPanels */
?>

<div>
    <x-slot name="title">{{ $item->title }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$item->title" :subtitle="$item->sku">
            <x-slot name="actions">
                <x-ui.record-history
                    :title="__('History for :sku', ['sku' => $item->sku])"
                    :subjects="[['name' => 'item', 'id' => $item->id]]"
                    :auditable-type="$item->getMorphClass()"
                    :auditable-id="$item->id"
                    source-capability="commerce.inventory.item.view"
                />
                <x-ui.button variant="ghost" as="a" href="{{ route('commerce.inventory.items.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        {{-- Same-page action feedback now flows through the global notification
             outlet (x-ui.notification-hub) via $this->notify(). This inline
             renderer only catches any post-redirect success/error banner that
             lands on this page. --}}
        <x-ui.session-flash />

        @php
            $readyChannelCount = collect($channelRows)->where('can_push', true)->count();
            $listedChannelCount = collect($channelRows)->where('listed', true)->count();
            $blockedChannelCount = collect($channelRows)->where('readiness_status', 'blocked')->count();
        @endphp

        <x-ui.card>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</span>
                    {{-- Native title, not the badge tooltip prop: the tooltip teleports
                         a fixed-position node to <body> that Livewire morphs orphan,
                         leaving it stuck at the top-left of the page. --}}
                    <x-ui.badge :variant="$this->statusVariant($item->status)" title="{{ __('Inventory lifecycle stage, set by you. Whether the item can actually go live is judged per channel in the Channels card.') }}">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Qty') }}</span>
                    <span class="text-sm font-medium text-ink tabular-nums">{{ $item->quantity_on_hand }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Target price') }}</span>
                    <span class="text-sm font-medium text-ink tabular-nums">{{ $this->formatMoney($item->target_price_amount, $item->currency_code) }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Channels') }}</span>
                    <a href="#listing-channels"><x-ui.badge variant="accent">{{ __('Listed :count', ['count' => $listedChannelCount]) }}</x-ui.badge></a>
                    <a href="#listing-channels"><x-ui.badge :variant="$readyChannelCount > 0 ? 'success' : 'default'">{{ __('Ready :count', ['count' => $readyChannelCount]) }}</x-ui.badge></a>
                    @if ($blockedChannelCount > 0)
                        <a href="#listing-channels"><x-ui.badge variant="warning">{{ __('Blocked :count', ['count' => $blockedChannelCount]) }}</x-ui.badge></a>
                    @endif
                </div>
            </div>
        </x-ui.card>

        <x-ui.tabs
            :tabs="[
                ['id' => 'overview', 'label' => __('Overview')],
                ['id' => 'photos', 'label' => __('Photos')],
            ]"
            default="overview"
            variant="underline"
            persistence="hash"
        >
        <x-ui.tab id="overview">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                {{-- Anchored cards: blocker links jump here, so give the jump headroom
                     and a :target ring that shows which card the link meant. --}}
                <x-ui.card id="item-facts" class="scroll-mt-24 target:ring-2 target:ring-accent/60">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Details') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Inventory facts, pricing, status, storage location, and private notes.') }}</p>
                        </div>
                        <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                    </div>

                    @if ($this->canEdit())
                        <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-ui.edit-in-place.text
                                :label="__('SKU')"
                                :value="$item->sku"
                                field="sku"
                                save-method="saveField"
                                maxlength="64"
                                monospace
                                :help="__('Seller-controlled item code, required and unique within the operating company.')"
                                :error="$errors->first('sku')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Title')"
                                :value="$item->title"
                                field="title"
                                save-method="saveField"
                                :help="__('Buyer-facing item name used as the listing title when the item is published.')"
                                :error="$errors->first('title')"
                            />

                            <x-ui.edit-in-place.select
                                :label="__('Status')"
                                :value="$item->status"
                                field="status"
                                save-method="saveField"
                                :help="__('Lifecycle stage from draft through ready, listed, sold, and archived.')"
                                :error="$errors->first('status')"
                            >
                                <x-slot name="read">
                                    <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                                </x-slot>

                                @foreach ($statuses as $statusOption)
                                    <option value="{{ $statusOption }}">{{ __(Illuminate\Support\Str::headline($statusOption)) }}</option>
                                @endforeach
                            </x-ui.edit-in-place.select>

                            <x-ui.edit-in-place.text
                                :label="__('Qty')"
                                :value="$item->quantity_on_hand"
                                field="quantity_on_hand"
                                save-method="saveField"
                                inputmode="numeric"
                                tabular
                                :help="__('Units available for this SKU or tracked item. Use 1 for one-off used parts.')"
                                :error="$errors->first('quantity_on_hand')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Storage location')"
                                :value="$item->storage_location"
                                field="storage_location"
                                save-method="saveField"
                                :empty="__('No location set.')"
                                :help="__('Internal place to find this stock.')"
                                :error="$errors->first('storage_location')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Unit Cost')"
                                :value="$this->formatMoneyInput($item->unit_cost_amount)"
                                :display="$this->formatMoney($item->unit_cost_amount, $item->currency_code)"
                                field="unit_cost_amount"
                                save-method="saveMoneyField"
                                inputmode="decimal"
                                tabular
                                :help="__('Private acquisition cost. Used later to calculate gross margin.')"
                                :error="$errors->first('unit_cost_amount')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Target Price')"
                                :value="$this->formatMoneyInput($item->target_price_amount)"
                                :display="$this->formatMoney($item->target_price_amount, $item->currency_code)"
                                field="target_price_amount"
                                save-method="saveMoneyField"
                                inputmode="decimal"
                                tabular
                                :help="__('Intended selling price and default listing price until a real sale is recorded.')"
                                :error="$errors->first('target_price_amount')"
                            />

                            <x-ui.currency-combobox
                                id="inventory-item-show-currency"
                                wire:model.live="currencyCode"
                                :label="__('Currency')"
                                required
                                :error="$errors->first('currencyCode')"
                            />

                            <div>
                                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Created') }}</dt>
                                <dd class="text-sm text-ink" title="{{ $item->created_at?->format('Y-m-d H:i:s') }}">{{ $item->created_at?->diffForHumans() }}</dd>
                            </div>
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <x-ui.edit-in-place.textarea
                                :label="__('Listing description')"
                                :value="$item->description"
                                field="description"
                                save-method="saveField"
                                :empty="__('No listing description yet.')"
                                rows="6"
                                :help="__('Buyer-facing copy. This is the marketplace listing body (the eBay “See full item description”) and is pushed to each channel. Pulling from a channel fills it in.')"
                                :error="$errors->first('description')"
                            />
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <x-ui.edit-in-place.textarea
                                :label="__('Notes')"
                                :value="$item->notes"
                                field="notes"
                                save-method="saveField"
                                :empty="__('No notes captured yet.')"
                                rows="5"
                                :help="__('Private working notes. Never published to buyers or sent to a marketplace.')"
                                :error="$errors->first('notes')"
                            />
                        </dl>
                    @else
                        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</dt>
                                <dd class="mt-1">
                                    <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Unit Cost') }}</dt>
                                <dd class="mt-1 text-sm text-ink tabular-nums">{{ $this->formatMoney($item->unit_cost_amount, $item->currency_code) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Target Price') }}</dt>
                                <dd class="mt-1 text-sm text-ink tabular-nums">{{ $this->formatMoney($item->target_price_amount, $item->currency_code) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created') }}</dt>
                                <dd class="mt-1 text-sm text-ink" title="{{ $item->created_at?->format('Y-m-d H:i:s') }}">{{ $item->created_at?->diffForHumans() }}</dd>
                            </div>
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <dt class="mb-1 text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Listing description') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $item->description ?: __('No listing description yet.') }}</dd>
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <dt class="mb-1 text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Notes') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $item->notes ?: __('No notes captured yet.') }}</dd>
                        </dl>
                    @endif
                </x-ui.card>

                <x-ui.card id="catalog-fit" class="scroll-mt-24 target:ring-2 target:ring-accent/60">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Catalog Fit') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Pick the reusable category and template that decide which structured fields apply to this item.') }}</p>
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            <x-ui.badge>{{ $item->category?->path_label ?? __('No category') }}</x-ui.badge>
                            <x-ui.badge>{{ $item->productTemplate?->name ?? __('No template') }}</x-ui.badge>
                        </div>
                    </div>

                    @if ($this->canEdit())
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-ui.select
                                id="item-catalog-category"
                                wire:model="catalogCategoryId"
                                wire:blur="saveCatalogAssignment"
                                label="{{ __('Category') }}"
                                :help="__('Broad reusable group for this sellable item.')"
                                :error="$errors->first('catalogCategoryId')"
                            >
                                <option value="">{{ __('No category') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->path_label }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.select
                                id="item-catalog-template"
                                wire:model="catalogProductTemplateId"
                                wire:blur="saveCatalogAssignment"
                                label="{{ __('Template') }}"
                                :help="__('Repeatable item type. Choosing a categorized template also selects its category.')"
                                :error="$errors->first('catalogProductTemplateId')"
                            >
                                <option value="">{{ __('No template') }}</option>
                                @foreach ($productTemplates as $template)
                                    @continue($catalogCategoryId !== null && $template->category_id !== null && $template->category_id !== (int) $catalogCategoryId)
                                    <option value="{{ $template->id }}">
                                        {{ $template->name }}
                                        @if ($template->category)
                                            {{ __('(:category)', ['category' => $template->category->path_label]) }}
                                        @endif
                                    </option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    @else
                        <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Category') }}</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $item->category?->path_label ?? __('No category') }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Template') }}</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $item->productTemplate?->name ?? __('No template') }}</dd>
                            </div>
                        </dl>
                    @endif

                    @if ($this->ebayCategorySettingsUrl())
                        @php
                            $ebayCategory = $this->ebayCategoryMapping();
                        @endphp
                        <div class="mt-4 border-t border-border-default pt-4">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('eBay category') }}</p>

                            @if ($item->productTemplate === null)
                                <p class="mt-1 text-sm text-muted">{{ __('Assign a template above — the eBay category is mapped per template.') }}</p>
                            @elseif ($ebayCategory)
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink">
                                    <x-ui.badge variant="success">{{ __('Mapped') }}</x-ui.badge>
                                    <span class="font-mono">{{ $ebayCategory['category_id'] }}{{ $ebayCategory['category_tree_id'] ? ' · '.__('tree :tree', ['tree' => $ebayCategory['category_tree_id']]) : '' }}</span>
                                    <a href="{{ $this->ebayCategorySettingsUrl() }}" class="font-medium text-accent hover:underline" wire:navigate>{{ __('Change') }}</a>
                                </div>
                            @else
                                <p class="mt-1 text-sm text-muted">
                                    {{ __('“:template” is not mapped to an eBay category yet.', ['template' => $item->productTemplate->name]) }}
                                    <a href="{{ $this->ebayCategorySettingsUrl() }}" class="ml-1 font-medium text-accent hover:underline" wire:navigate>{{ __('Map it in eBay settings → Categories') }}</a>
                                </p>
                            @endif

                            <p class="mt-1 text-xs text-muted">{{ __('Set per template, so every item of this type shares the same eBay category.') }}</p>
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card id="fitment" class="scroll-mt-24 target:ring-2 target:ring-accent/60">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Fitment') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Vehicle or application compatibility confirmed for this item. Marketplace publishing will use this instead of title text alone.') }}</p>
                        </div>
                        <x-ui.badge>{{ $item->fitments->count() }}</x-ui.badge>
                    </div>

                    @if ($item->fitments->isEmpty())
                        <x-ui.alert variant="info">
                            {{ __('No fitment captured yet. Add compatible vehicles, or explicitly mark the item as universal fit when that is true.') }}
                        </x-ui.alert>
                    @else
                        <div class="space-y-3">
                            @foreach ($item->fitments as $fitment)
                                <div wire:key="item-fitment-{{ $fitment->id }}" class="flex items-start justify-between gap-3 border-b border-border-default pb-3 last:border-0 last:pb-0">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-medium text-ink">
                                                @if ($fitment->is_universal)
                                                    {{ __('Universal fit') }}
                                                @else
                                                    {{ collect([$fitment->display_year, $fitment->display_make, $fitment->display_model, $fitment->display_trim, $fitment->display_engine])->filter()->implode(' ') }}
                                                @endif
                                            </p>
                                            <x-ui.badge>{{ __(Illuminate\Support\Str::headline($fitment->confidence)) }}</x-ui.badge>
                                            <x-ui.badge>{{ __(Illuminate\Support\Str::headline($fitment->source)) }}</x-ui.badge>
                                        </div>

                                        @if (! $fitment->is_universal && ($fitment->compatibility_properties ?? []) !== [])
                                            <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                                                @foreach ($fitment->compatibility_properties as $name => $value)
                                                    <div class="flex gap-1">
                                                        <dt class="font-medium text-ink">{{ $name }}:</dt>
                                                        <dd>{{ $value }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif

                                        @if ($fitment->notes)
                                            <p class="mt-2 text-sm text-muted">{{ $fitment->notes }}</p>
                                        @endif
                                    </div>

                                    @if ($this->canEdit())
                                        <div class="flex shrink-0 items-center gap-1">
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="editFitment({{ $fitment->id }})">
                                                <x-icon name="heroicon-o-pencil-square" class="h-4 w-4" />
                                                {{ __('Edit') }}
                                            </x-ui.button>

                                            <button
                                                type="button"
                                                wire:click="deleteFitment({{ $fitment->id }})"
                                                wire:confirm="{{ __('Remove this fitment entry?') }}"
                                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted hover:bg-surface-subtle hover:text-status-danger"
                                                aria-label="{{ __('Remove fitment') }}"
                                                title="{{ __('Remove') }}"
                                            >
                                                <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canEdit())
                        <div class="mt-4 border-t border-border-default pt-4">
                            {{-- The form only appears on demand: most visits read the list. --}}
                            @unless ($fitmentFormOpen)
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.button type="button" variant="outline" size="sm" wire:click="openFitmentForm">
                                        <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                                        {{ __('Add fitment') }}
                                    </x-ui.button>

                                    @if ($canBootstrapFitmentFromAttributes)
                                        <x-ui.button type="button" variant="outline" size="sm" wire:click="bootstrapFitmentFromAttributes" title="{{ __('Create one fitment entry from this item’s year, make, model, trim, and engine attributes.') }}">
                                            <x-icon name="heroicon-o-sparkles" class="h-4 w-4" />
                                            {{ __('Create from attributes') }}
                                        </x-ui.button>
                                    @endif
                                </div>
                            @else
                                <form wire:submit="{{ $editingFitmentId === null ? 'addFitment' : 'updateFitment' }}" class="space-y-4">
                                    <x-ui.checkbox
                                        id="item-fitment-universal"
                                        wire:model.live="fitmentUniversal"
                                        :label="__('Universal fit')"
                                        :help="__('Use only when the part intentionally fits broadly and does not need vehicle-specific compatibility.')"
                                    />

                                    @unless ($fitmentUniversal)
                                        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                                            <x-ui.input id="item-fitment-year" wire:model="fitmentYear" :label="__('Year')" :error="$errors->first('fitmentYear')" />
                                            <x-ui.input id="item-fitment-make" wire:model="fitmentMake" :label="__('Make')" :error="$errors->first('fitmentMake')" />
                                            <x-ui.input id="item-fitment-model" wire:model="fitmentModel" :label="__('Model')" :error="$errors->first('fitmentModel')" />
                                            <x-ui.input id="item-fitment-trim" wire:model="fitmentTrim" :label="__('Trim')" :error="$errors->first('fitmentTrim')" />
                                            <x-ui.input id="item-fitment-engine" wire:model="fitmentEngine" :label="__('Engine')" :error="$errors->first('fitmentEngine')" />
                                        </div>
                                    @endunless

                                    <x-ui.textarea
                                        id="item-fitment-notes"
                                        wire:model="fitmentNotes"
                                        :label="__('Fitment notes')"
                                        rows="2"
                                        :help="__('Optional source or qualifier for this compatibility claim.')"
                                        :error="$errors->first('fitmentNotes')"
                                    />

                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-ui.button type="submit" variant="primary" size="sm">
                                            <x-icon name="{{ $editingFitmentId === null ? 'heroicon-o-plus' : 'heroicon-o-check' }}" class="h-4 w-4" />
                                            {{ $editingFitmentId === null ? __('Add fitment') : __('Save fitment') }}
                                        </x-ui.button>

                                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelFitmentEdit">
                                            {{ __('Cancel') }}
                                        </x-ui.button>
                                    </div>
                                </form>

                                @if ($editingFitmentId === null && $fitmentSourceItems->isNotEmpty())
                                    <form wire:submit="copyFitmentsFromItem" class="mt-4 space-y-3 border-t border-border-default pt-4">
                                        <x-ui.combobox
                                            id="item-fitment-copy-source"
                                            wire:model="copyFitmentsFromItemId"
                                            :label="__('Or copy fitment from another item')"
                                            :placeholder="__('Search by SKU or title')"
                                            :options="$fitmentSourceItems->map(fn ($source) => [
                                                'value' => (string) $source->id,
                                                'label' => $source->sku . ' — ' . $source->title . ' (' . trans_choice(':count entry|:count entries', $source->fitments_count, ['count' => $source->fitments_count]) . ')',
                                            ])->all()"
                                            :error="$errors->first('copyFitmentsFromItemId')"
                                        />

                                        <x-ui.button type="submit" variant="outline" size="sm">
                                            <x-icon name="heroicon-o-clipboard-document" class="h-4 w-4" />
                                            {{ __('Copy fitment') }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            @endunless
                        </div>
                    @endif
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card id="listing-channels" class="scroll-mt-24 target:ring-2 target:ring-accent/60" x-data="{ helpOpen: false }">
                    <div class="mb-4 flex items-center gap-2">
                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Channels') }}</h2>
                        <x-ui.help @click="helpOpen = ! helpOpen" ::aria-expanded="helpOpen" />
                    </div>

                    <div
                        x-cloak
                        x-show="helpOpen"
                        x-transition
                        class="mb-4 overflow-hidden rounded-2xl border border-border-default bg-surface-card text-sm text-muted shadow-sm"
                        @click="helpOpen = false"
                        role="note"
                        aria-label="{{ __('Click to dismiss') }}"
                    >
                        <div class="space-y-2 p-4">
                            <p>{{ __('Publish or update this one item on each marketplace — this is the push side of the workflow.') }}</p>
                            <p>{{ __('Pulling listings and orders in from a marketplace happens on that channel’s own page. Here you push this item out, one channel at a time, once it is ready.') }}</p>
                            <p>{{ __('Readiness (category, price, fitment, photos, policies) re-checks itself whenever the item changes; an item can only be listed once its check passes.') }}</p>
                            <p><span class="font-medium text-ink">{{ __('List / Push') }}</span> — {{ __('List publishes this item as a new listing; Push updates the existing listing with the item’s current data. Quantity syncs automatically when stock changes — push is for content. Live channels confirm before writing.') }}</p>
                        </div>
                    </div>

                    @if (! $this->canPushToMarketplace())
                        <x-ui.alert variant="info" class="mb-4">{{ __('You can review channel readiness here. Marketplace push actions require inventory edit and marketplace execute permission.') }}</x-ui.alert>
                    @endif

                    @if ($channelRows === [])
                        <x-ui.alert variant="info">{{ __('No marketplace channels are registered yet.') }}</x-ui.alert>
                    @else
                        <div class="space-y-3">
                        @foreach ($channelRows as $row)
                            @php
                                $listing = $row['listing'];
                                $draft = $row['draft'];
                                $gapLinks = [
                                    'item_facts' => ['label' => __('Edit details'), 'href' => '#item-facts'],
                                    'catalog_fit' => ['label' => __('Edit catalog fit'), 'href' => '#catalog-fit'],
                                    'fitment' => ['label' => __('Edit fitment'), 'href' => '#fitment'],
                                    'photos' => ['label' => __('Edit media'), 'href' => '#photos'],
                                    'attributes' => ['label' => __('Edit identifiers'), 'href' => '#attributes'],
                                    'settings' => ['label' => __('Open channel settings'), 'href' => $row['settings_url'] ? $row['settings_url'].'#defaults' : null],
                                    'ebay_categories' => ['label' => __('Map eBay category'), 'href' => $row['settings_url'] ? $row['settings_url'].'#categories' : null],
                                ];
                                // Setup gaps are fixed on channel settings pages, item gaps on
                                // this page — grouping them tells the operator which tool to
                                // reach for without reading every line.
                                $isSetupGap = fn (array $gap): bool => in_array($gap['action'] ?? null, ['settings', 'ebay_categories'], true);
                                [$setupBlockers, $itemBlockers] = collect($row['blockers'])->partition($isSetupGap);
                                [$setupWarnings, $itemWarnings] = collect($row['warnings'])->partition($isSetupGap);

                                // One badge answers "where is this listing?" instead of three
                                // overlapping ones (Listed + Ready + ACTIVE). A live, active
                                // listing reads "Listed"; an ended/withdrawn one shows its eBay
                                // status; an unpublished item shows its push-readiness instead.
                                if ($row['listed']) {
                                    $stateLabel = __('Listed');
                                    $stateVariant = 'success';
                                } elseif ($listing) {
                                    $stateLabel = Illuminate\Support\Str::headline($listing->status ?? 'unknown');
                                    $stateVariant = $this->listingStatusVariant($listing->status);
                                } else {
                                    $stateLabel = Illuminate\Support\Str::headline($row['readiness_status']);
                                    $stateVariant = $row['readiness_variant'];
                                }
                            @endphp

                            @php
                                // The Push/List button explains itself whether enabled or not.
                                $pushTitle = ! $row['can_push']
                                    ? $row['push_disabled_reason']
                                    : ($row['listed']
                                        ? __('Update the live listing with this item’s current title, price, description, and photos. Quantity stays in sync automatically — push after content edits.')
                                        : __('Publish this item as a new listing on this channel.'));
                            @endphp
                            <div wire:key="item-channel-{{ $row['key'] }}" class="rounded-2xl border border-border-default bg-surface-subtle p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-medium text-ink">{{ $row['label'] }}</p>
                                            @if ($row['environment'])
                                                <x-ui.badge :variant="$row['environment'] === 'live' ? 'warning' : 'default'">{{ __(Illuminate\Support\Str::headline($row['environment'])) }}</x-ui.badge>
                                            @endif
                                            @if ($row['listed'] && $listing?->listing_url)
                                                {{-- The state pill doubles as the way to the live listing. --}}
                                                <x-ui.link kind="external" href="{{ $listing->listing_url }}" :icon="false" :title="__('Open the live listing')">
                                                    <x-ui.badge :variant="$stateVariant">{{ __($stateLabel) }} ↗</x-ui.badge>
                                                </x-ui.link>
                                            @else
                                                <x-ui.badge :variant="$stateVariant">{{ __($stateLabel) }}</x-ui.badge>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 flex-col items-end gap-2">
                                        @if ($this->canPushToMarketplace())
                                            @if ($row['requires_confirmation'])
                                                <x-ui.button type="button" :variant="$row['can_push'] ? 'primary' : 'outline'" size="sm" wire:click="pushChannel('{{ $row['key'] }}')" wire:loading.attr="disabled" wire:target="pushChannel('{{ $row['key'] }}')" :disabled="! $row['can_push']" :title="$pushTitle" wire:confirm="{{ __('This will write to the live :channel marketplace. Continue?', ['channel' => $row['label']]) }}">
                                                    <x-icon name="{{ $row['listed'] ? 'heroicon-o-arrow-up-tray' : 'heroicon-o-plus-circle' }}" class="h-4 w-4" />
                                                    {{ $row['listed'] ? __('Push') : __('List') }}
                                                </x-ui.button>
                                            @else
                                                <x-ui.button type="button" :variant="$row['can_push'] ? 'primary' : 'outline'" size="sm" wire:click="pushChannel('{{ $row['key'] }}')" wire:loading.attr="disabled" wire:target="pushChannel('{{ $row['key'] }}')" :disabled="! $row['can_push']" :title="$pushTitle">
                                                    <x-icon name="{{ $row['listed'] ? 'heroicon-o-arrow-up-tray' : 'heroicon-o-plus-circle' }}" class="h-4 w-4" />
                                                    {{ $row['listed'] ? __('Push') : __('List') }}
                                                </x-ui.button>
                                            @endif
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-3 border-t border-border-default pt-3 text-xs text-muted">
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                        @if (! $row['listed'] && $listing?->external_listing_id)
                                            <span class="font-mono">{{ $listing->external_listing_id }}</span>
                                        @endif

                                        @if ($row['index_url'])
                                            <a href="{{ $row['index_url'] }}" class="inline-flex items-center gap-1 font-medium text-accent hover:underline" wire:navigate>
                                                <x-icon name="heroicon-o-link" class="h-3.5 w-3.5" />
                                                {{ __(':channel Marketplace', ['channel' => $row['label']]) }}
                                            </a>
                                        @endif
                                    </div>

                                    @unless ($listing?->listing_url || $listing?->external_listing_id)
                                        <p class="mt-1">{{ __('Will use target price: :price', ['price' => $this->formatMoney($row['price_amount'], $row['currency_code'])]) }}</p>
                                    @endunless

                                    @unless ($draft)
                                        <p class="mt-1">{{ __('Readiness has not been checked yet.') }}</p>
                                    @endunless

                                    @if ($row['blockers'] !== [])
                                        <div class="mt-2">
                                            <p class="font-medium text-status-danger">{{ trans_choice(':count blocker|:count blockers', count($row['blockers']), ['count' => count($row['blockers'])]) }}</p>
                                            @foreach ([__('This item') => $itemBlockers, __('Channel setup') => $setupBlockers] as $groupHeading => $groupGaps)
                                                @if ($groupGaps->isNotEmpty())
                                                    @if ($itemBlockers->isNotEmpty() && $setupBlockers->isNotEmpty())
                                                        <p class="mt-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted">{{ $groupHeading }}</p>
                                                    @endif
                                                    @include('commerce-inventory::livewire.commerce.inventory.items.partials.channel-gap-list', [
                                                        'gaps' => $groupGaps,
                                                        'gapLinks' => $gapLinks,
                                                        'bulletClass' => 'text-status-danger',
                                                    ])
                                                @endif
                                            @endforeach
                                        </div>
                                    @elseif ($row['readiness_status'] === 'ready')
                                        <p class="mt-2">{{ __('Ready to publish or revise.') }}</p>
                                    @endif

                                    {{-- Warnings never gate the push, so they stay behind a
                                         disclosure while blockers need attention and only open
                                         by default once the blockers are cleared. --}}
                                    @if ($row['warnings'] !== [])
                                        <div class="mt-2">
                                            <x-ui.disclosure
                                                :title="trans_choice(':count warning|:count warnings', count($row['warnings']), ['count' => count($row['warnings'])])"
                                                :default-open="$row['blockers'] === []"
                                                title-class="text-xs font-medium text-status-warning"
                                                content-class="mt-1"
                                            >
                                                @foreach ([__('This item') => $itemWarnings, __('Channel setup') => $setupWarnings] as $groupHeading => $groupGaps)
                                                    @if ($groupGaps->isNotEmpty())
                                                        @if ($itemWarnings->isNotEmpty() && $setupWarnings->isNotEmpty())
                                                            <p class="mt-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted">{{ $groupHeading }}</p>
                                                        @endif
                                                        @include('commerce-inventory::livewire.commerce.inventory.items.partials.channel-gap-list', [
                                                            'gaps' => $groupGaps,
                                                            'gapLinks' => $gapLinks,
                                                            'bulletClass' => 'text-status-warning',
                                                        ])
                                                    @endif
                                                @endforeach
                                            </x-ui.disclosure>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        </div>
                    @endif
                </x-ui.card>

                @foreach ($extensionReadinessPanels as $panel)
                    @php
                        $attentionEntries = collect($panel['entries'])->whereIn('severity', ['blocker', 'warning', 'suggestion']);
                        $attentionCount = $attentionEntries->count();
                        $panelHasBlocker = $attentionEntries->contains('severity', 'blocker');
                    @endphp
                    <x-ui.card id="extension-readiness-{{ Illuminate\Support\Str::slug($panel['id']) }}" wire:key="extension-readiness-{{ $panel['id'] }}" x-data="{ open: {{ $panelHasBlocker ? 'true' : 'false' }} }">
                        {{-- Collapsed by default so this supplementary checklist does not compete
                             with the source cards it links to. It auto-opens when a blocker needs
                             action; each entry deep-links to the exact field to fix. --}}
                        <button type="button" @click="open = ! open" x-bind:aria-expanded="open.toString()" class="flex w-full items-start justify-between gap-3 text-left">
                            <div>
                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __($panel['label']) }}</h2>
                                @if ($panel['description'])
                                    <p class="mt-1 text-sm text-muted">{{ __($panel['description']) }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($attentionCount > 0)
                                    <x-ui.badge :variant="$panelHasBlocker ? 'danger' : 'warning'">{{ __(':count to improve', ['count' => $attentionCount]) }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="success">{{ __('All clear') }}</x-ui.badge>
                                @endif
                                <x-icon name="heroicon-o-chevron-down" class="h-4 w-4 text-muted transition-transform" ::class="open && 'rotate-180'" />
                            </div>
                        </button>

                        <div x-show="open" x-cloak x-transition class="mt-3 space-y-2">
                            @foreach ($panel['entries'] as $entry)
                                @php
                                    $entryVariant = match ($entry['severity']) {
                                        'success' => 'success',
                                        'blocker' => 'danger',
                                        'warning' => 'warning',
                                        default => 'info',
                                    };
                                    $actionTargets = [
                                        'item_facts' => ['label' => __('Edit item facts'), 'href' => '#item-facts'],
                                        'catalog_fit' => ['label' => __('Edit catalog fit'), 'href' => '#catalog-fit'],
                                        'fitment' => ['label' => __('Edit fitment'), 'href' => '#fitment'],
                                        'attributes' => ['label' => __('Edit attributes'), 'href' => '#attributes'],
                                        'photos' => ['label' => __('Edit photos'), 'href' => '#photos'],
                                    ];
                                @endphp

                                <div wire:key="extension-readiness-{{ $panel['id'] }}-{{ $entry['code'] }}" class="rounded-2xl border border-border-default bg-surface-subtle p-3">
                                    <div class="flex items-start gap-3">
                                        <x-ui.badge :variant="$entryVariant">{{ __(Illuminate\Support\Str::headline($entry['severity'])) }}</x-ui.badge>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-ink">{{ __($entry['label']) }}</p>
                                            @if (isset($entry['description']))
                                                <p class="mt-1 text-sm text-muted">{{ __($entry['description']) }}</p>
                                            @endif
                                            @if (isset($entry['action'], $actionTargets[$entry['action']]))
                                                <a href="{{ $actionTargets[$entry['action']]['href'] }}" class="mt-2 inline-flex text-sm font-medium text-accent hover:underline">
                                                    {{ $actionTargets[$entry['action']]['label'] }}
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endforeach

                <x-ui.card id="attributes" class="scroll-mt-24 target:ring-2 target:ring-accent/60">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Attributes') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Structured facts — part numbers, condition, brand — that power search and become marketplace item specifics.') }}</p>
                        </div>
                        <x-ui.badge>{{ $item->catalogAttributeValues->count() }}</x-ui.badge>
                    </div>

                    @if ($item->catalogAttributeValues->isEmpty())
                        <p class="text-sm text-muted">{{ __('No attributes captured yet.') }}</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($item->catalogAttributeValues as $value)
                                <div wire:key="item-attribute-value-{{ $value->id }}" class="flex items-start justify-between gap-3 border-b border-border-default pb-3 last:border-0 last:pb-0">
                                    <div>
                                        <div class="text-sm font-medium text-ink">{{ $value->attribute->name }}</div>
                                        <div class="mt-1 text-sm text-muted">{{ $value->display_value }}</div>
                                    </div>

                                    @if ($this->canEdit())
                                        <button
                                            type="button"
                                            wire:click="removeAttributeValue({{ $value->id }})"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-full text-muted hover:bg-surface-subtle hover:text-status-danger"
                                            aria-label="{{ __('Remove attribute') }}"
                                            title="{{ __('Remove') }}"
                                        >
                                            <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canEdit())
                        <form wire:submit="saveAttributeValue" class="mt-4 space-y-4 border-t border-border-default pt-4">
                            {{-- .live: the value field only renders once an attribute is
                                 chosen, so the choice must sync immediately. --}}
                            <x-ui.select
                                id="item-attribute-id"
                                wire:model.live="selectedAttributeId"
                                label="{{ __('Attribute') }}"
                                :error="$errors->first('selectedAttributeId')"
                            >
                                <option value="">{{ __('Select...') }}</option>
                                @foreach ($availableAttributes as $attribute)
                                    <option value="{{ $attribute->id }}">
                                        {{ $attribute->name }}
                                        @if ($attribute->productTemplate)
                                            {{ __('(:template)', ['template' => $attribute->productTemplate->name]) }}
                                        @elseif ($attribute->category)
                                            {{ __('(:category)', ['category' => $attribute->category->path_label]) }}
                                        @endif
                                    </option>
                                @endforeach
                            </x-ui.select>

                            @if ($selectedAttributeId)
                                <x-ui.input
                                    id="item-attribute-value"
                                    wire:model="attributeValue"
                                    label="{{ __('Value') }}"
                                    :error="$errors->first('attributeValue')"
                                />

                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.button type="submit" variant="primary" size="sm">
                                        <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                                        {{ __('Save') }}
                                    </x-ui.button>

                                    <x-ui.button variant="ghost" size="sm" as="a" href="{{ route('commerce.catalog.index') }}" wire:navigate>
                                        <x-icon name="heroicon-o-tag" class="h-4 w-4" />
                                        {{ __('Catalog') }}
                                    </x-ui.button>
                                </div>
                            @else
                                <x-ui.field-help :hint="$availableAttributes->isEmpty() ? __('No applicable attributes exist for this item fit yet. Add global, category, or template attributes in Catalog.') : __('Select an attribute first, then enter its value.')"/>
                            @endif
                        </form>
                    @endif
                </x-ui.card>
            </div>
        </div>
        </x-ui.tab>

        <x-ui.tab id="photos">
            @php
                $cleanedPhotoCount = $item->photos
                    ->filter(fn (\App\Modules\Commerce\Inventory\Models\ItemPhoto $photo): bool => $photo->cleanedAssets->isNotEmpty())
                    ->count();
                $listingPhotos = $item->photos
                    ->filter(fn (\App\Modules\Commerce\Inventory\Models\ItemPhoto $photo): bool => $photo->selected_for_listing)
                    ->values();
                $unlistedPhotos = $item->photos
                    ->reject(fn (\App\Modules\Commerce\Inventory\Models\ItemPhoto $photo): bool => $photo->selected_for_listing)
                    ->values();
                $activeCleanupProvider = collect($photoCleanupProviders)->firstWhere('active', true);
                $activeCleanupProviderLabel = data_get($activeCleanupProvider, 'label');
                $hasPhotoCleanupProvider = count($photoCleanupProviders) > 0;
                $photoProviderLabel = static function (\App\Base\Media\Models\MediaAsset $asset): string {
                    $providerLabel = data_get($asset->metadata, 'provider_label');

                    if (is_string($providerLabel) && trim($providerLabel) !== '') {
                        return trim($providerLabel);
                    }

                    return \Illuminate\Support\Str::headline((string) data_get($asset->metadata, 'provider', __('cleanup provider')));
                };
                $photoRollBadgeLabel = static function (\App\Modules\Commerce\Inventory\Models\ItemPhoto $photo, ?\App\Base\Media\Models\MediaAsset $selectedAsset) use ($activePhotoCleanupProviderKey, $photoProviderLabel): ?string {
                    $badgeAsset = $selectedAsset;

                    if (! $badgeAsset instanceof \App\Base\Media\Models\MediaAsset && is_string($activePhotoCleanupProviderKey)) {
                        $badgeAsset = $photo->cleanedAssetForProvider($activePhotoCleanupProviderKey);
                    }

                    $badgeAsset ??= $photo->cleanedAssets->last();

                    if (! $badgeAsset instanceof \App\Base\Media\Models\MediaAsset) {
                        return null;
                    }

                    $label = $photoProviderLabel($badgeAsset);

                    if ($selectedAsset instanceof \App\Base\Media\Models\MediaAsset) {
                        return $label;
                    }

                    $otherCleanedVersionCount = $photo->cleanedAssets
                        ->reject(fn (\App\Base\Media\Models\MediaAsset $asset): bool => $asset->id === $badgeAsset->id)
                        ->count();

                    return $otherCleanedVersionCount > 0
                        ? __(':provider +:count', ['provider' => $label, 'count' => $otherCleanedVersionCount])
                        : $label;
                };
            @endphp

            <div
                id="photos"
                class="scroll-mt-24 space-y-6"
                x-data="{
                    dragging: false,
                    dragDepth: 0,
                    autoUploadOnFinish: false,
                    uploadError: false,
                    lastReviewedPhotoId: null,
                    lastReviewedPhotoTimer: null,
                    reviewModalOpen: @entangle('photoReviewModalOpen'),
                    reviewPhotoId: @entangle('photoReviewPhotoId'),
                    photoRollSize: localStorage.getItem('commerce.inventory.photoRollSize') || 'large',
                    init() {
                        if (!['small', 'medium', 'large'].includes(this.photoRollSize)) {
                            this.photoRollSize = 'large';
                        }

                        this.$watch('reviewModalOpen', (open) => {
                            if (open || !this.reviewPhotoId) return;

                            this.highlightReviewedPhoto(this.reviewPhotoId);
                        });
                    },
                    photoGridClasses() {
                        return {
                            'gap-2 grid-cols-3 sm:grid-cols-4 md:grid-cols-6 xl:grid-cols-8 2xl:grid-cols-10': this.photoRollSize === 'small',
                            'gap-3 grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6': this.photoRollSize === 'medium',
                            'gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4': this.photoRollSize === 'large',
                        };
                    },
                    setPhotoRollSize(value) {
                        this.photoRollSize = value;
                        localStorage.setItem('commerce.inventory.photoRollSize', value);
                    },
                    highlightReviewedPhoto(photoId) {
                        const id = Number(photoId);

                        if (!id) return;

                        this.lastReviewedPhotoId = id;
                        clearTimeout(this.lastReviewedPhotoTimer);

                        this.$nextTick(() => {
                            const card = this.$root.querySelector(`[data-photo-card='${id}']`);
                            card?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
                        });

                        this.lastReviewedPhotoTimer = setTimeout(() => {
                            if (this.lastReviewedPhotoId === id) {
                                this.lastReviewedPhotoId = null;
                            }
                        }, 3500);
                    },
                }"
                @dragenter.prevent.stop="
                    dragDepth++;
                    dragging = true;
                "
                @dragover.prevent.stop="dragging = true"
                @dragleave.prevent.stop="
                    dragDepth = Math.max(0, dragDepth - 1);
                    if (dragDepth === 0) dragging = false;
                "
                x-on:livewire-upload-finish.window="
                    if (!autoUploadOnFinish) return;
                    autoUploadOnFinish = false;
                    $wire.uploadPhotos();
                "
                {{-- A failed temp upload (e.g. a photo over the server's PHP
                     upload limit) never reaches Laravel validation, so it must
                     be surfaced here or the photo silently never appears. --}}
                x-on:livewire-upload-error.window="autoUploadOnFinish = false; uploadError = true"
                x-on:livewire-upload-start.window="uploadError = false"
                @drop.prevent.stop="
                    dragDepth = 0;
                    dragging = false;
                    const dt = $event.dataTransfer;
                    if (!dt || !dt.files || dt.files.length === 0 || !$refs.photoInput) return;
                    $refs.photoInput.files = dt.files;
                    $refs.photoInput.dispatchEvent(new Event('change', { bubbles: true }));
                    autoUploadOnFinish = true;
                "
            >
                <x-ui.card class="relative overflow-hidden">
                    <div
                        x-cloak
                        x-show="dragging"
                        class="absolute inset-0 z-10 flex items-center justify-center rounded-2xl border-2 border-dashed border-accent/70 bg-surface-card/85"
                    >
                        <div class="px-6 text-center">
                            <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-surface-subtle text-muted">
                                <x-icon name="heroicon-o-arrow-up-tray" class="h-5 w-5" />
                            </div>
                            <p class="text-sm font-medium text-ink">{{ __('Drop photos to add them') }}</p>
                            <p class="mt-1 text-xs text-muted">{{ __('Images only. Multiple files supported.') }}</p>
                        </div>
                    </div>

                    <div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Photos') }}</h2>
                                <x-ui.badge>{{ trans_choice(':count photo|:count photos', $item->photos->count(), ['count' => $item->photos->count()]) }}</x-ui.badge>
                                <x-ui.badge :variant="$listingPhotos->isNotEmpty() ? 'success' : 'warning'">{{ trans_choice(':count listing photo|:count listing photos', $listingPhotos->count(), ['count' => $listingPhotos->count()]) }}</x-ui.badge>
                                @if ($unlistedPhotos->isNotEmpty())
                                    <x-ui.badge>{{ trans_choice(':count unlisted|:count unlisted', $unlistedPhotos->count(), ['count' => $unlistedPhotos->count()]) }}</x-ui.badge>
                                @endif
                                @if ($cleanedPhotoCount > 0)
                                    <x-ui.badge variant="success">{{ trans_choice(':count cleaned|:count cleaned', $cleanedPhotoCount, ['count' => $cleanedPhotoCount]) }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-muted">{{ __('Each source photo contributes one listing version. Open review to compare the original with provider results.') }}</p>
                        </div>

                        @if ($this->canEdit())
                            <label for="item-photos" class="sr-only">{{ __('Add photos') }}</label>
                            <input
                                x-ref="photoInput"
                                id="item-photos"
                                type="file"
                                multiple
                                accept="image/*"
                                wire:model="photoFiles"
                                x-on:change="if ($event.target.files && $event.target.files.length > 0) autoUploadOnFinish = true"
                                class="sr-only"
                            />
                        @endif

                        <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                            <div class="space-y-1">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Thumbnails') }}</p>
                                <x-ui.segmented-control
                                    x-model="photoRollSize"
                                    @segmented-control-change="setPhotoRollSize($event.detail.value)"
                                    :options="[
                                        ['value' => 'small', 'label' => __('Small')],
                                        ['value' => 'medium', 'label' => __('Medium')],
                                        ['value' => 'large', 'label' => __('Large')],
                                    ]"
                                    value="large"
                                    :label="__('Thumbnail size')"
                                    size="md"
                                />
                            </div>

                            <div class="ml-auto flex flex-wrap items-end justify-end gap-2">
                                @if ($this->canEdit() && count($photoCleanupProviders) > 1)
                                    <div class="w-56">
                                        <x-ui.select
                                            id="photo-batch-cleanup-provider"
                                            wire:change="setPhotoCleanupProvider($event.target.value)"
                                            wire:loading.attr="disabled"
                                            wire:target="runPhotoCleanupBatch"
                                            label="{{ __('Provider') }}"
                                        >
                                            @foreach ($photoCleanupProviders as $cleanupProvider)
                                                <option value="{{ $cleanupProvider['key'] }}" @selected($cleanupProvider['active'])>
                                                    {{ $cleanupProvider['label'] }}
                                                </option>
                                            @endforeach
                                        </x-ui.select>
                                    </div>
                                @endif

                                @if ($this->canEdit() && $hasPhotoCleanupProvider && $item->photos->isNotEmpty())
                                    <x-ui.button
                                        type="button"
                                        variant="outline"
                                        size="md"
                                        wire:click="runPhotoCleanupBatch"
                                        wire:loading.attr="disabled"
                                        wire:target="runPhotoCleanupBatch"
                                        title="{{ __('Remove the background from every photo that does not already have a version from the active provider.') }}"
                                    >
                                        <x-icon name="heroicon-o-sparkles" class="h-4 w-4" wire:loading.remove wire:target="runPhotoCleanupBatch" />
                                        <x-icon name="heroicon-o-arrow-path" class="h-4 w-4 animate-spin" wire:loading wire:target="runPhotoCleanupBatch" />
                                        <span wire:loading.remove wire:target="runPhotoCleanupBatch">
                                            {{ count($photoCleanupProviders) > 1 || ! $activeCleanupProviderLabel ? __('Clean all') : __('Clean all with :provider', ['provider' => $activeCleanupProviderLabel]) }}
                                        </span>
                                        <span wire:loading wire:target="runPhotoCleanupBatch">
                                            {{ $activeCleanupProviderLabel ? __(':provider processing…', ['provider' => $activeCleanupProviderLabel]) : __('Processing…') }}
                                        </span>
                                    </x-ui.button>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if ($this->canEdit())
                        <div class="mt-2">
                            <p x-cloak x-show="uploadError" class="text-sm text-status-danger">
                                {{ __('Upload failed — each photo must be an image no larger than 10 MB. Try a smaller photo.') }}
                            </p>

                            @error('photoFiles') <p class="text-sm text-status-danger">{{ $message }}</p> @enderror
                            @error('photoFiles.*') <p class="text-sm text-status-danger">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($item->photos->isEmpty())
                        @if ($this->canEdit())
                            <button
                                type="button"
                                x-on:click="$refs.photoInput.click()"
                                class="mt-6 flex w-full flex-col items-center justify-center rounded-2xl border border-dashed border-border-default bg-surface-subtle p-8 text-center transition-colors hover:border-accent hover:bg-surface-card"
                            >
                                <span class="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-surface-card text-muted">
                                    <x-icon name="heroicon-o-arrow-up-tray" class="h-5 w-5" />
                                </span>
                                <span class="text-sm font-medium text-ink">{{ __('Drop photos here or click to add') }}</span>
                                <span class="mt-1 text-sm text-muted">{{ __('Add item photos, then clean backgrounds and choose what listings should use.') }}</span>
                            </button>
                        @else
                            <div class="mt-6 rounded-2xl border border-dashed border-border-default bg-surface-subtle p-6 text-center">
                                <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-surface-card text-muted">
                                    <x-icon name="heroicon-o-photo" class="h-5 w-5" />
                                </div>
                                <p class="text-sm font-medium text-ink">{{ __('No photos yet.') }}</p>
                            </div>
                        @endif
                    @else
                        <section class="mt-5 border-t border-border-default pt-5">
                            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Listing photos') }}</h3>
                                    <p class="mt-1 text-xs text-muted">{{ __('These are sent to marketplaces in photo order.') }}</p>
                                </div>
                            </div>

                            <div class="grid" :class="photoGridClasses()">
                                @foreach ($listingPhotos as $photo)
                                    @php
                                        $displayAsset = $photo->displayAsset();
                                        $selectedAsset = $photo->use_cleaned_photo ? $photo->activeCleanedAsset() : null;
                                        $listingLabel = $selectedAsset instanceof \App\Base\Media\Models\MediaAsset
                                            ? $photoProviderLabel($selectedAsset)
                                            : __('Original');
                                        $photoBadgeLabel = $photoRollBadgeLabel($photo, $selectedAsset);
                                    @endphp

                                    @if ($displayAsset instanceof \App\Base\Media\Models\MediaAsset)
                                        <article wire:key="listing-photo-{{ $photo->id }}" data-photo-card="{{ $photo->id }}" class="overflow-hidden rounded-2xl border border-border-default bg-surface-card transition-shadow" :class="lastReviewedPhotoId === {{ $photo->id }} ? 'border-accent ring-2 ring-accent/25' : ''">
                                            <div class="relative">
                                                <button
                                                    type="button"
                                                    wire:click="openPhotoReview({{ $photo->id }})"
                                                    class="group block aspect-square w-full overflow-hidden bg-surface-subtle text-left"
                                                >
                                                    <img
                                                        src="{{ $displayAsset->displayUrl() }}"
                                                        alt="{{ __('Review item photo: :filename', ['filename' => $displayAsset->original_filename ?? __('Photo')]) }}"
                                                        class="h-full w-full cursor-pointer object-cover transition-transform group-hover:scale-[1.02]"
                                                        loading="lazy"
                                                    />

                                                    @if ($photoBadgeLabel)
                                                        <span class="absolute bottom-2 left-2 max-w-[calc(100%-1rem)] truncate rounded-full bg-surface-card/90 px-2 py-0.5 text-[11px] font-medium text-ink shadow-sm">{{ $photoBadgeLabel }}</span>
                                                    @endif
                                                </button>

                                                @if ($this->canEdit())
                                                    <button
                                                        type="button"
                                                        wire:click="setPhotoListingSelection({{ $photo->id }}, false)"
                                                        wire:loading.attr="disabled"
                                                        wire:target="setPhotoListingSelection({{ $photo->id }}, false)"
                                                        title="{{ __('Unlist photo') }}"
                                                        class="absolute right-2 top-2 inline-flex h-7 w-7 items-center justify-center rounded-full border border-status-success-border bg-surface-card text-status-success shadow-sm transition-colors hover:border-status-danger-border hover:text-status-danger focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 disabled:opacity-50"
                                                    >
                                                        <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                                        <span class="sr-only">{{ __('Unlist photo') }}</span>
                                                    </button>
                                                @else
                                                    <span class="absolute right-2 top-2 inline-flex h-7 w-7 items-center justify-center rounded-full border border-status-success-border bg-surface-card text-status-success shadow-sm">
                                                        <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                                    </span>
                                                @endif
                                            </div>

                                            <div x-cloak x-show="photoRollSize !== 'small'" class="space-y-1 p-3">
                                                <div class="min-w-0">
                                                    <p class="truncate text-sm font-medium text-ink">{{ $displayAsset->original_filename ?? __('Photo') }}</p>
                                                    <p class="text-xs text-muted">{{ __('Version: :version', ['version' => $listingLabel]) }}</p>
                                                </div>
                                            </div>
                                        </article>
                                    @endif
                                @endforeach

                                @if ($this->canEdit())
                                    <button
                                        type="button"
                                        x-on:click="$refs.photoInput.click()"
                                        class="flex aspect-square flex-col items-center justify-center rounded-2xl border border-dashed border-border-default bg-surface-subtle p-4 text-center transition-colors hover:border-accent hover:bg-surface-card"
                                    >
                                        <span class="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-surface-card text-muted">
                                            <x-icon name="heroicon-o-arrow-up-tray" class="h-5 w-5" />
                                        </span>
                                        <span class="text-sm font-medium text-ink">{{ __('Add photos') }}</span>
                                        <span x-cloak x-show="photoRollSize !== 'small'" class="mt-1 text-xs text-muted">{{ __('Drop files anywhere in this panel.') }}</span>
                                    </button>
                                @endif
                            </div>
                        </section>

                        <section class="mt-6 border-t border-border-default pt-5">
                            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Unlisted photos') }}</h3>
                                    <p class="mt-1 text-xs text-muted">{{ __('Kept on this item, not sent to marketplaces.') }}</p>
                                </div>

                                @if ($this->canEdit() && $unlistedPhotos->isNotEmpty())
                                    <x-ui.button type="button" variant="danger-ghost" size="sm" wire:click="deleteUnselectedPhotos" wire:confirm="{{ __('Delete every unlisted photo? Listing photos will be kept.') }}" wire:loading.attr="disabled" wire:target="deleteUnselectedPhotos">
                                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                        {{ __('Delete unlisted') }}
                                    </x-ui.button>
                                @endif
                            </div>

                            @if ($unlistedPhotos->isEmpty())
                                <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle p-4 text-sm text-muted">
                                    {{ __('No unlisted photos. Unlist a photo when you want to keep it on the item without publishing it.') }}
                                </div>
                            @else
                                <div class="grid" :class="photoGridClasses()">
                                    @foreach ($unlistedPhotos as $photo)
                                        @php
                                            $displayAsset = $photo->displayAsset();
                                            $selectedAsset = $photo->use_cleaned_photo ? $photo->activeCleanedAsset() : null;
                                            $listingLabel = $selectedAsset instanceof \App\Base\Media\Models\MediaAsset
                                                ? $photoProviderLabel($selectedAsset)
                                                : __('Original');
                                            $photoBadgeLabel = $photoRollBadgeLabel($photo, $selectedAsset);
                                        @endphp

                                        @if ($displayAsset instanceof \App\Base\Media\Models\MediaAsset)
                                            <article wire:key="unlisted-photo-{{ $photo->id }}" data-photo-card="{{ $photo->id }}" class="overflow-hidden rounded-2xl border border-border-default bg-surface-card transition-shadow" :class="lastReviewedPhotoId === {{ $photo->id }} ? 'border-accent ring-2 ring-accent/25' : ''">
                                                <div class="relative">
                                                    <button
                                                        type="button"
                                                        wire:click="openPhotoReview({{ $photo->id }})"
                                                        class="group block aspect-square w-full overflow-hidden bg-surface-subtle text-left"
                                                    >
                                                        <img
                                                            src="{{ $displayAsset->displayUrl() }}"
                                                            alt="{{ __('Review item photo: :filename', ['filename' => $displayAsset->original_filename ?? __('Photo')]) }}"
                                                            class="h-full w-full cursor-pointer object-cover opacity-80 transition group-hover:scale-[1.02] group-hover:opacity-100"
                                                            loading="lazy"
                                                        />

                                                        @if ($photoBadgeLabel)
                                                            <span class="absolute bottom-2 left-2 max-w-[calc(100%-1rem)] truncate rounded-full bg-surface-card/90 px-2 py-0.5 text-[11px] font-medium text-ink shadow-sm">{{ $photoBadgeLabel }}</span>
                                                        @endif
                                                    </button>

                                                    @if ($this->canEdit())
                                                        <button
                                                            type="button"
                                                            wire:click="setPhotoListingSelection({{ $photo->id }}, true)"
                                                            wire:loading.attr="disabled"
                                                            wire:target="setPhotoListingSelection({{ $photo->id }}, true)"
                                                            title="{{ __('List photo') }}"
                                                            class="absolute right-2 top-2 inline-flex h-7 w-7 items-center justify-center rounded-full border border-border-default bg-surface-card text-muted shadow-sm transition-colors hover:border-status-success-border hover:text-status-success focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 disabled:opacity-50"
                                                        >
                                                            <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                                                            <span class="sr-only">{{ __('List photo') }}</span>
                                                        </button>
                                                    @else
                                                        <span class="absolute right-2 top-2 inline-flex h-7 w-7 items-center justify-center rounded-full border border-border-default bg-surface-card text-muted shadow-sm">
                                                            <span class="h-3.5 w-3.5 rounded-full border border-border-default"></span>
                                                        </span>
                                                    @endif
                                                </div>

                                                <div x-cloak x-show="photoRollSize !== 'small'" class="space-y-1 p-3">
                                                    <div class="min-w-0">
                                                        <p class="truncate text-sm font-medium text-ink">{{ $displayAsset->original_filename ?? __('Photo') }}</p>
                                                        <p class="text-xs text-muted">{{ __('Saved version: :version', ['version' => $listingLabel]) }}</p>
                                                    </div>
                                                </div>
                                            </article>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endif
                </x-ui.card>

                @if ($photoReviewPhoto instanceof \App\Modules\Commerce\Inventory\Models\ItemPhoto)
                    @php
                        $reviewOriginalAsset = $photoReviewPhoto->mediaAsset;
                        $reviewCleanedAssets = $photoReviewPhoto->cleanedAssets;
                        $reviewFilename = $reviewOriginalAsset?->original_filename ?? __('Photo');
                        $activeProviderCleanedAsset = is_string($activePhotoCleanupProviderKey)
                            ? $photoReviewPhoto->cleanedAssetForProvider($activePhotoCleanupProviderKey)
                            : null;
                        $selectedCleanedAsset = $photoReviewPhoto->use_cleaned_photo ? $photoReviewPhoto->activeCleanedAsset() : null;
                        $reviewCleanedAsset = $selectedCleanedAsset ?? $activeProviderCleanedAsset ?? $reviewCleanedAssets->first();
                        $selectedCleanedAssetId = $selectedCleanedAsset?->id;
                        $originalSelected = ! $photoReviewPhoto->use_cleaned_photo || ! $selectedCleanedAsset;
                        $canCleanWithActiveProvider = $this->canEdit()
                            && $hasPhotoCleanupProvider
                            && is_string($activePhotoCleanupProviderKey)
                            && ! ($activeProviderCleanedAsset instanceof \App\Base\Media\Models\MediaAsset);
                        $reviewVersions = collect([[
                            'id' => 'original',
                            'label' => __('Original'),
                            'asset' => $reviewOriginalAsset,
                            'type' => 'original',
                            'selected' => $originalSelected,
                        ]])->merge($reviewCleanedAssets->map(function (\App\Base\Media\Models\MediaAsset $asset) use ($selectedCleanedAssetId, $photoProviderLabel): array {
                            return [
                                'id' => 'cleaned-'.$asset->id,
                                'label' => $photoProviderLabel($asset),
                                'asset' => $asset,
                                'type' => 'cleaned',
                                'selected' => $selectedCleanedAssetId === $asset->id,
                            ];
                        }))->values();
                        $unselectedCleanedVersionCount = $photoReviewPhoto->use_cleaned_photo && $selectedCleanedAsset instanceof \App\Base\Media\Models\MediaAsset
                            ? $reviewCleanedAssets->reject(fn (\App\Base\Media\Models\MediaAsset $asset): bool => $asset->id === $selectedCleanedAsset->id)->count()
                            : $reviewCleanedAssets->count();
                    @endphp

                    <x-ui.modal wire:model="photoReviewModalOpen" class="max-w-7xl">
                        <div
                            wire:key="photo-review-modal-{{ $photoReviewPhoto->id }}"
                            class="space-y-4 p-card-inner"
                            x-data="{
                                background: 'checker',
                                panelClass() {
                                    return this.background === 'dark' ? 'bg-surface-secondary' : 'bg-surface-card';
                                },
                                panelStyle() {
                                    return this.background === 'checker'
                                        ? 'background-color: var(--color-surface-card); background-image: linear-gradient(45deg, var(--color-border-default) 25%, transparent 25%), linear-gradient(-45deg, var(--color-border-default) 25%, transparent 25%), linear-gradient(45deg, transparent 75%, var(--color-border-default) 75%), linear-gradient(-45deg, transparent 75%, var(--color-border-default) 75%); background-size: 24px 24px; background-position: 0 0, 0 12px, 12px -12px, -12px 0;'
                                        : '';
                                },
                            }"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Photo :current of :total', ['current' => $photoReviewPosition['current'], 'total' => $photoReviewPosition['total']]) }}</h2>
                                    <p class="mt-1 truncate text-sm text-muted">{{ $reviewFilename }}</p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="previousPhotoReview">
                                        <x-icon name="heroicon-o-chevron-left" class="h-4 w-4" />
                                        {{ __('Previous') }}
                                    </x-ui.button>
                                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="nextPhotoReview">
                                        {{ __('Next') }}
                                        <x-icon name="heroicon-o-chevron-right" class="h-4 w-4" />
                                    </x-ui.button>

                                    @if ($this->canEdit())
                                        <x-ui.button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            wire:click="setPhotoListingSelection({{ $photoReviewPhoto->id }}, {{ $photoReviewPhoto->selected_for_listing ? 'false' : 'true' }})"
                                            wire:loading.attr="disabled"
                                            wire:target="setPhotoListingSelection({{ $photoReviewPhoto->id }})"
                                            class="{{ $photoReviewPhoto->selected_for_listing ? 'border-status-success-border text-status-success hover:bg-status-success-subtle' : 'hover:bg-surface-subtle' }}"
                                        >
                                            @if ($photoReviewPhoto->selected_for_listing)
                                                <x-icon name="heroicon-o-check" class="h-4 w-4" wire:loading.remove wire:target="setPhotoListingSelection({{ $photoReviewPhoto->id }})" />
                                            @endif
                                            <x-icon name="heroicon-o-arrow-path" class="h-4 w-4 animate-spin" wire:loading wire:target="setPhotoListingSelection({{ $photoReviewPhoto->id }})" />
                                            {{ $photoReviewPhoto->selected_for_listing ? __('Listed') : __('List photo') }}
                                        </x-ui.button>
                                    @elseif ($photoReviewPhoto->selected_for_listing)
                                        <x-ui.badge variant="success">
                                            <x-icon name="heroicon-o-check" class="h-3.5 w-3.5" />
                                            {{ __('Listed') }}
                                        </x-ui.badge>
                                    @endif

                                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="closePhotoReview">
                                        {{ __('Close') }}
                                    </x-ui.button>
                                </div>
                            </div>

                            @if ($reviewOriginalAsset instanceof \App\Base\Media\Models\MediaAsset)
                                <div class="space-y-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border-default pt-4">
                                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                                            @foreach ($reviewVersions as $version)
                                                @php
                                                    /** @var \App\Base\Media\Models\MediaAsset $versionAsset */
                                                    $versionAsset = $version['asset'];
                                                    $versionIsSelected = (bool) $version['selected'];
                                                @endphp

                                                @if ($versionIsSelected)
                                                    <button
                                                        type="button"
                                                        @if ($this->canEdit())
                                                            wire:click="setPhotoListingSelection({{ $photoReviewPhoto->id }}, {{ $photoReviewPhoto->selected_for_listing ? 'false' : 'true' }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="setPhotoListingSelection({{ $photoReviewPhoto->id }})"
                                                        @else
                                                            disabled
                                                        @endif
                                                        title="{{ $photoReviewPhoto->selected_for_listing ? __('Unlist photo') : __('List photo') }}"
                                                        class="inline-flex min-h-9 max-w-full items-center gap-2 rounded-full border border-accent bg-surface-card px-input-x py-input-y text-sm font-medium text-ink shadow-sm ring-2 ring-accent/20 transition-colors hover:bg-surface-subtle focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 disabled:cursor-default disabled:hover:bg-surface-card"
                                                    >
                                                        @if ($photoReviewPhoto->selected_for_listing)
                                                            <x-icon name="heroicon-o-check" class="h-4 w-4 shrink-0 text-status-success" />
                                                            <span class="sr-only">{{ __('Unlist photo:') }}</span>
                                                        @else
                                                            <span class="h-4 w-4 shrink-0 rounded-full border border-border-default"></span>
                                                            <span class="sr-only">{{ __('List photo:') }}</span>
                                                        @endif
                                                        <span class="truncate">{{ $version['label'] }}</span>
                                                    </button>
                                                @else
                                                    <button
                                                        type="button"
                                                        @if ($this->canEdit())
                                                            @if ($version['type'] === 'original')
                                                                wire:click="revertCleanedPhoto({{ $photoReviewPhoto->id }})"
                                                                wire:target="revertCleanedPhoto({{ $photoReviewPhoto->id }})"
                                                            @else
                                                                wire:click="acceptCleanedPhoto({{ $photoReviewPhoto->id }}, {{ $versionAsset->id }})"
                                                                wire:target="acceptCleanedPhoto({{ $photoReviewPhoto->id }}, {{ $versionAsset->id }})"
                                                            @endif
                                                            wire:loading.attr="disabled"
                                                        @else
                                                            disabled
                                                        @endif
                                                        class="inline-flex min-h-9 max-w-full items-center gap-2 rounded-full border border-border-default bg-surface-card px-input-x py-input-y text-sm font-medium text-ink transition-colors hover:border-accent hover:bg-surface-subtle focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 disabled:cursor-default disabled:opacity-60 disabled:hover:border-border-default disabled:hover:bg-surface-card"
                                                    >
                                                        <span class="h-4 w-4 shrink-0 rounded-full border border-border-default"></span>
                                                        <span class="truncate">{{ $version['label'] }}</span>
                                                    </button>
                                                @endif
                                            @endforeach
                                        </div>

                                        @if ($this->canEdit())
                                            <div class="flex flex-wrap items-center justify-end gap-2">
                                                <x-ui.segmented-control
                                                    x-model="background"
                                                    :options="[
                                                        ['value' => 'checker', 'label' => __('Checker')],
                                                        ['value' => 'light', 'label' => __('Light')],
                                                        ['value' => 'dark', 'label' => __('Dark')],
                                                    ]"
                                                    value="checker"
                                                    :label="__('Preview background')"
                                                    size="md"
                                                    class="h-9"
                                                />

                                                @if (count($photoCleanupProviders) > 1)
                                                    <div class="w-44">
                                                        <label for="photo-review-cleanup-provider" class="sr-only">{{ __('Provider') }}</label>
                                                        <x-ui.select
                                                            id="photo-review-cleanup-provider"
                                                            wire:change="setPhotoCleanupProvider($event.target.value)"
                                                            wire:loading.attr="disabled"
                                                            wire:target="runPhotoCleanup({{ $photoReviewPhoto->id }})"
                                                            class="h-9"
                                                        >
                                                            @foreach ($photoCleanupProviders as $cleanupProvider)
                                                                <option value="{{ $cleanupProvider['key'] }}" @selected($cleanupProvider['active'])>
                                                                    {{ $cleanupProvider['label'] }}
                                                                </option>
                                                            @endforeach
                                                        </x-ui.select>
                                                    </div>
                                                @elseif ($activeCleanupProviderLabel)
                                                    <x-ui.badge>{{ $activeCleanupProviderLabel }}</x-ui.badge>
                                                @endif

                                                @if ($hasPhotoCleanupProvider && ($canCleanWithActiveProvider || ! $reviewCleanedAsset))
                                                    <x-ui.button type="button" variant="primary" size="md" wire:click="runPhotoCleanup({{ $photoReviewPhoto->id }})" wire:loading.attr="disabled" wire:target="runPhotoCleanup({{ $photoReviewPhoto->id }})">
                                                        <x-icon name="heroicon-o-sparkles" class="h-4 w-4" wire:loading.remove wire:target="runPhotoCleanup({{ $photoReviewPhoto->id }})" />
                                                        <x-icon name="heroicon-o-arrow-path" class="h-4 w-4 animate-spin" wire:loading wire:target="runPhotoCleanup({{ $photoReviewPhoto->id }})" />
                                                        <span wire:loading.remove wire:target="runPhotoCleanup({{ $photoReviewPhoto->id }})">{{ __('Remove background') }}</span>
                                                        <span wire:loading wire:target="runPhotoCleanup({{ $photoReviewPhoto->id }})">
                                                            {{ $activeCleanupProviderLabel ? __(':provider processing…', ['provider' => $activeCleanupProviderLabel]) : __('Processing…') }}
                                                        </span>
                                                    </x-ui.button>
                                                @endif

                                                @if ($unselectedCleanedVersionCount > 0)
                                                    <x-ui.button type="button" variant="outline" size="md" wire:click="deleteUnselectedCleanedVersions({{ $photoReviewPhoto->id }})" wire:confirm="{{ __('Delete all cleaned versions that are not selected?') }}" wire:loading.attr="disabled" wire:target="deleteUnselectedCleanedVersions({{ $photoReviewPhoto->id }})">
                                                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                                        {{ __('Delete unused') }}
                                                    </x-ui.button>
                                                @endif

                                                <x-ui.button type="button" variant="outline" size="md" class="border-status-danger-border text-status-danger hover:bg-status-danger-subtle" wire:click="deletePhoto({{ $photoReviewPhoto->id }})" wire:confirm="{{ __('Delete this photo and all its versions?') }}" wire:loading.attr="disabled" wire:target="deletePhoto({{ $photoReviewPhoto->id }})">
                                                    <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                                    {{ __('Delete') }}
                                                </x-ui.button>
                                            </div>
                                        @endif
                                    </div>

                                    <section>
                                        <div @class(['grid gap-4', 'lg:grid-cols-2 xl:grid-cols-3' => $reviewVersions->count() > 1])>
                                            @foreach ($reviewVersions as $version)
                                                @php
                                                    /** @var \App\Base\Media\Models\MediaAsset $versionAsset */
                                                    $versionAsset = $version['asset'];
                                                    $versionIsSelected = (bool) $version['selected'];
                                                    $zoomBtnClass = 'inline-flex h-7 w-7 items-center justify-center rounded-md text-muted transition-colors hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent';
                                                    $zoomBtnDisabledClass = 'disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-muted';
                                                @endphp

                                                <article
                                                    class="space-y-2"
                                                    wire:key="photo-review-compare-{{ $photoReviewPhoto->id }}-{{ $version['id'] }}"
                                                    x-data="photoZoom({
                                                        canEdit: @js($this->canEdit()),
                                                        isSelected: @js($versionIsSelected),
                                                        versionType: @js($version['type']),
                                                        photoId: {{ $photoReviewPhoto->id }},
                                                        assetId: @js($versionAsset?->id),
                                                        listed: @js($photoReviewPhoto->selected_for_listing),
                                                    })"
                                                >
                                                    <div class="flex items-center justify-between gap-2">
                                                        <h3 class="truncate text-base font-medium tracking-tight text-ink">{{ $version['label'] }}</h3>
                                                        <div class="flex shrink-0 items-center gap-0.5 text-muted" :class="zoomed ? 'opacity-100' : 'opacity-60 hover:opacity-100'">
                                                            <button
                                                                type="button"
                                                                x-on:click.stop="zoomOut()"
                                                                :disabled="scale <= minScale"
                                                                title="{{ __('Zoom out') }}"
                                                                class="{{ $zoomBtnClass }} {{ $zoomBtnDisabledClass }}"
                                                            >
                                                                <x-icon name="heroicon-o-magnifying-glass-minus" class="h-4 w-4" />
                                                                <span class="sr-only">{{ __('Zoom out') }}</span>
                                                            </button>
                                                            <span class="w-10 text-center text-xs tabular-nums" x-text="zoomLabel()" :class="zoomed ? 'text-ink' : 'text-muted'"></span>
                                                            <button
                                                                type="button"
                                                                x-on:click.stop="zoomIn()"
                                                                :disabled="scale >= maxScale"
                                                                title="{{ __('Zoom in') }}"
                                                                class="{{ $zoomBtnClass }} {{ $zoomBtnDisabledClass }}"
                                                            >
                                                                <x-icon name="heroicon-o-magnifying-glass-plus" class="h-4 w-4" />
                                                                <span class="sr-only">{{ __('Zoom in') }}</span>
                                                            </button>
                                                            <button
                                                                type="button"
                                                                x-on:click.stop="reset()"
                                                                x-show="zoomed"
                                                                x-cloak
                                                                title="{{ __('Reset zoom') }}"
                                                                class="{{ $zoomBtnClass }}"
                                                            >
                                                                <x-icon name="heroicon-o-arrows-pointing-in" class="h-4 w-4" />
                                                                <span class="sr-only">{{ __('Reset zoom') }}</span>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <div
                                                        x-ref="stage"
                                                        class="relative flex min-h-[18rem] items-center justify-center overflow-hidden rounded-2xl border p-3 sm:min-h-[24rem] 2xl:min-h-[30rem] {{ $versionIsSelected ? 'border-accent ring-2 ring-accent/20' : 'border-border-default' }}"
                                                        :class="panelClass()"
                                                        :style="panelStyle()"
                                                    >
                                                        <img
                                                            x-ref="img"
                                                            src="{{ $versionAsset->displayUrl() }}"
                                                            alt="{{ __('Photo version :version: :filename', ['version' => $version['label'], 'filename' => $versionAsset->original_filename ?? $reviewFilename]) }}"
                                                            draggable="false"
                                                            class="max-h-[70vh] w-full select-none touch-none object-contain"
                                                            :class="zoomed ? (panning ? 'cursor-grabbing' : 'cursor-grab') : (canEdit ? 'cursor-pointer' : 'cursor-default')"
                                                            :style="imgStyle()"
                                                            @if ($this->canEdit())
                                                                title="{{ $versionIsSelected
                                                                    ? ($photoReviewPhoto->selected_for_listing ? __('Listed — click to unlist this photo') : __('Click to list this photo'))
                                                                    : ($version['type'] === 'original' ? __('Click to use the original') : __('Click to use this cleaned version')) }}"
                                                            @endif
                                                            x-on:click="select()"
                                                            x-on:wheel.prevent="onWheel($event)"
                                                            x-on:pointerdown="onPointerDown($event)"
                                                            x-on:pointermove="onPointerMove($event)"
                                                            x-on:pointerup="onPointerUp($event)"
                                                            x-on:pointercancel="onPointerUp($event)"
                                                        />
                                                    </div>
                                                </article>
                                            @endforeach
                                        </div>
                                    </section>
                                </div>
                            @else
                                <x-ui.alert variant="warning">{{ __('The selected photo file is missing.') }}</x-ui.alert>
                            @endif
                        </div>
                    </x-ui.modal>
                @endif
            </div>
        </x-ui.tab>
        </x-ui.tabs>
    </div>

    <script>
document.addEventListener('alpine:init', () => {
    /**
     * Per-image zoom + pan controller for the photo review modal.
     * Wheel/zoom buttons zoom toward a focal point; one-finger drag pans when
     * zoomed; two-finger pinch zooms toward the midpoint. A plain click (no
     * movement) selects the version for the listing, or toggles listing when
     * the version is already selected. Read-only viewers get zoom only.
     */
    Alpine.data('photoZoom', (config = {}) => ({
        canEdit: config.canEdit ?? false,
        isSelected: config.isSelected ?? false,
        versionType: config.versionType ?? 'original',
        photoId: config.photoId ?? null,
        assetId: config.assetId ?? null,
        listed: config.listed ?? false,

        minScale: 1,
        maxScale: 5,
        scale: 1,
        tx: 0,
        ty: 0,

        pointers: new Map(),
        panning: false,
        moved: false,
        lastX: 0,
        lastY: 0,
        lastPinchDist: 0,

        get zoomed() { return this.scale > 1.0001; },

        imgStyle() {
            return `transform: translate(${this.tx}px, ${this.ty}px) scale(${this.scale}); transform-origin: 50% 50%;`;
        },

        zoomLabel() {
            return Math.round(this.scale * 100) + '%';
        },

        imgSize() {
            const img = this.$refs.img;
            return { w: img?.clientWidth || 1, h: img?.clientHeight || 1 };
        },

        clamp() {
            if (this.scale < this.minScale) this.scale = this.minScale;
            if (this.scale > this.maxScale) this.scale = this.maxScale;
            if (this.scale <= this.minScale) {
                this.tx = 0;
                this.ty = 0;
                return;
            }
            const { w, h } = this.imgSize();
            const maxX = ((this.scale - 1) * w) / 2;
            const maxY = ((this.scale - 1) * h) / 2;
            this.tx = Math.min(maxX, Math.max(-maxX, this.tx));
            this.ty = Math.min(maxY, Math.max(-maxY, this.ty));
        },

        applyZoom(newScale, focalX, focalY) {
            const oldScale = this.scale;
            newScale = Math.min(this.maxScale, Math.max(this.minScale, newScale));
            if (newScale === oldScale) return;
            const { w, h } = this.imgSize();
            // Keep the focal point (relative to the image box) fixed: with
            // transform-origin at center, translate delta = (focal - center) * (old - new).
            this.tx += (focalX - w / 2) * (oldScale - newScale);
            this.ty += (focalY - h / 2) * (oldScale - newScale);
            this.scale = newScale;
            this.clamp();
        },

        zoomIn() {
            const { w, h } = this.imgSize();
            this.applyZoom(this.scale * 1.25, w / 2, h / 2);
        },

        zoomOut() {
            const { w, h } = this.imgSize();
            this.applyZoom(this.scale / 1.25, w / 2, h / 2);
        },

        reset() {
            this.scale = 1;
            this.tx = 0;
            this.ty = 0;
            this.panning = false;
        },

        onWheel(e) {
            const rect = this.$refs.img.getBoundingClientRect();
            // rect is the transformed box; convert to layout coordinates.
            const focalX = (e.clientX - rect.left) / this.scale;
            const focalY = (e.clientY - rect.top) / this.scale;
            const factor = e.deltaY < 0 ? 1.1 : 1 / 1.1;
            this.applyZoom(this.scale * factor, focalX, focalY);
        },

        dist(a, b) {
            return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
        },

        onPointerDown(e) {
            // Ignore secondary buttons / synthetic touches.
            if (e.button && e.button !== 0) return;
            this.pointers.set(e.pointerId, e);
            this.moved = false;
            try { this.$refs.img.setPointerCapture(e.pointerId); } catch (_) {}
            if (this.pointers.size === 2) {
                const [a, b] = [...this.pointers.values()];
                this.lastPinchDist = this.dist(a, b);
                this.panning = false;
            } else if (this.pointers.size === 1) {
                this.lastX = e.clientX;
                this.lastY = e.clientY;
                this.panning = this.zoomed;
            }
        },

        onPointerMove(e) {
            if (!this.pointers.has(e.pointerId)) return;
            this.pointers.set(e.pointerId, e);

            if (this.pointers.size >= 2) {
                const [a, b] = [...this.pointers.values()];
                const d = this.dist(a, b);
                if (this.lastPinchDist > 0 && d > 0) {
                    const rect = this.$refs.img.getBoundingClientRect();
                    const focalX = ((a.clientX + b.clientX) / 2 - rect.left) / this.scale;
                    const focalY = ((a.clientY + b.clientY) / 2 - rect.top) / this.scale;
                    this.applyZoom(this.scale * (d / this.lastPinchDist), focalX, focalY);
                }
                this.lastPinchDist = d;
                this.moved = true;
                return;
            }

            const dx = e.clientX - this.lastX;
            const dy = e.clientY - this.lastY;
            if (Math.abs(dx) + Math.abs(dy) > 2) this.moved = true;
            if (this.panning) {
                this.tx += dx;
                this.ty += dy;
                this.clamp();
            }
            this.lastX = e.clientX;
            this.lastY = e.clientY;
        },

        onPointerUp(e) {
            this.pointers.delete(e.pointerId);
            try { this.$refs.img.releasePointerCapture(e.pointerId); } catch (_) {}
            if (this.pointers.size < 2) this.lastPinchDist = 0;
            if (this.pointers.size === 1) {
                const [p] = [...this.pointers.values()];
                this.lastX = p.clientX;
                this.lastY = p.clientY;
                this.panning = this.zoomed;
            } else if (this.pointers.size === 0) {
                this.panning = false;
            }
        },

        select() {
            if (this.moved) {
                this.moved = false;
                return;
            }
            if (!this.canEdit) return;
            if (this.isSelected) {
                this.$wire.setPhotoListingSelection(this.photoId, !this.listed);
            } else if (this.versionType === 'original') {
                this.$wire.revertCleanedPhoto(this.photoId);
            } else if (this.assetId !== null) {
                this.$wire.acceptCleanedPhoto(this.photoId, this.assetId);
            }
        },
    }));
});
    </script>
</div>
