<?php

use App\Base\Media\Models\MediaAsset;
use App\Modules\Commerce\Inventory\Livewire\Items\Show;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;

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
                    ->filter(fn (ItemPhoto $photo): bool => $photo->cleanedAssets->isNotEmpty())
                    ->count();
                $unreviewedCleanedPhotoCount = $item->photos
                    ->filter(fn (ItemPhoto $photo): bool => $photo->cleanedAssets->isNotEmpty() && ! $photo->use_cleaned_photo)
                    ->count();
                $activeCleanupProvider = collect($photoCleanupProviders)->firstWhere('active', true);
                $activeCleanupProviderLabel = data_get($activeCleanupProvider, 'label');
                $hasPhotoCleanupProvider = count($photoCleanupProviders) > 0;
                $availablePhotos = $photoReviewPhoto instanceof ItemPhoto
                    ? $item->photos->reject(fn (ItemPhoto $photo): bool => $photo->id === $photoReviewPhoto->id)->values()
                    : $item->photos;
            @endphp

            <div
                id="photos"
                class="scroll-mt-24 space-y-6"
                x-data="{
                    dragging: false,
                    dragDepth: 0,
                    autoUploadOnFinish: false,
                    uploadError: false,
                    background: 'checker',
                    zoomed: false,
                    panelClass() {
                        return this.background === 'dark' ? 'bg-surface-secondary' : 'bg-surface-card';
                    },
                    panelStyle() {
                        return this.background === 'checker'
                            ? 'background-color: var(--color-surface-card); background-image: linear-gradient(45deg, var(--color-border-default) 25%, transparent 25%), linear-gradient(-45deg, var(--color-border-default) 25%, transparent 25%), linear-gradient(45deg, transparent 75%, var(--color-border-default) 75%), linear-gradient(-45deg, transparent 75%, var(--color-border-default) 75%); background-size: 24px 24px; background-position: 0 0, 0 12px, 12px -12px, -12px 0;'
                            : '';
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
                    if (!dt || !dt.files || dt.files.length === 0) return;
                    $refs.photoInput.files = dt.files;
                    $refs.photoInput.dispatchEvent(new Event('change', { bubbles: true }));
                    autoUploadOnFinish = true;
                "
            >
                <x-ui.card class="relative overflow-hidden">
                    <div
                        x-cloak
                        x-show="dragging"
                        class="absolute inset-0 z-10 flex items-center justify-center rounded-2xl border-2 border-dashed border-accent/70 bg-surface-card/80"
                    >
                        <div class="text-center px-6">
                            <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-surface-subtle text-muted">
                                <x-icon name="heroicon-o-arrow-up-tray" class="h-5 w-5" />
                            </div>
                            <p class="text-sm font-medium text-ink">{{ __('Drop photos to add them') }}</p>
                            <p class="mt-1 text-xs text-muted">{{ __('Images only. Multiple files supported.') }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Photo workbench') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Pick the listing image, compare provider versions, and run image operations without losing the overview.') }}</p>
                        </div>

                        <div class="flex flex-wrap items-end gap-2">
                            @if ($this->canEdit() && count($photoCleanupProviders) > 1)
                                <div class="w-52">
                                    <x-ui.select
                                        id="photo-batch-cleanup-provider"
                                        wire:change="setPhotoCleanupProvider($event.target.value)"
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
                                    size="sm"
                                    wire:click="runPhotoCleanupBatch"
                                    wire:loading.attr="disabled"
                                    wire:target="runPhotoCleanupBatch"
                                    title="{{ __('Remove the background from every photo that does not already have a version from the active provider.') }}"
                                >
                                    <x-icon name="heroicon-o-sparkles" class="h-4 w-4" />
                                    {{ $activeCleanupProviderLabel ? __('Clean with :provider', ['provider' => $activeCleanupProviderLabel]) : __('Clean photos') }}
                                </x-ui.button>
                            @endif

                            @if ($this->canEdit() && $cleanedPhotoCount > 0)
                                <x-ui.button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    wire:click="openFirstCleanedPhotoReview"
                                    title="{{ __('Jump to a photo with cleaned versions to review.') }}"
                                >
                                    <x-icon name="heroicon-o-arrows-right-left" class="h-4 w-4" />
                                    {{ $unreviewedCleanedPhotoCount > 0
                                        ? trans_choice('Review :count cleaned photo|Review :count cleaned photos', $unreviewedCleanedPhotoCount, ['count' => $unreviewedCleanedPhotoCount])
                                        : __('Review cleaned') }}
                                </x-ui.button>
                            @endif

                            <x-ui.badge>{{ trans_choice(':count photo|:count photos', $item->photos->count(), ['count' => $item->photos->count()]) }}</x-ui.badge>
                        </div>
                    </div>

                    @if ($this->canEdit())
                        <form wire:submit="uploadPhotos" class="mt-4 flex flex-col gap-3 border-t border-border-default pt-4">
                            <div>
                                <label for="item-photos" class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Add photos') }}</label>

                                <input
                                    x-ref="photoInput"
                                    id="item-photos"
                                    type="file"
                                    multiple
                                    accept="image/*"
                                    wire:model="photoFiles"
                                    x-on:change="if ($event.target.files && $event.target.files.length > 0) autoUploadOnFinish = true"
                                    class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded file:border-0 file:bg-surface-subtle file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-subtle/80"
                                />

                                <p x-cloak x-show="uploadError" class="mt-1 text-sm text-status-danger">
                                    {{ __('Upload failed — each photo must be an image no larger than 10 MB. Try a smaller photo.') }}
                                </p>

                                @error('photoFiles') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
                                @error('photoFiles.*') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
                                @if (! $errors->has('photoFiles') && ! $errors->has('photoFiles.*'))
                                    <x-ui.field-help :hint="__('Raw photos from phone or desktop. Use image operations afterwards to make listing-ready versions.')" />
                                @endif
                            </div>
                        </form>
                    @endif

                    @if ($item->photos->isEmpty())
                        <div class="mt-6 rounded-2xl border border-dashed border-border-default bg-surface-subtle p-6 text-center">
                            <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-surface-card text-muted">
                                <x-icon name="heroicon-o-photo" class="h-5 w-5" />
                            </div>
                            <p class="text-sm font-medium text-ink">{{ __('No photos yet.') }}</p>
                            <p class="mt-1 text-sm text-muted">{{ __('Add item photos here, then clean backgrounds and choose the image each marketplace listing should use.') }}</p>
                        </div>
                    @else
                        <div class="mt-6 grid gap-6 xl:grid-cols-[18rem_minmax(0,1fr)]">
                            <aside class="space-y-4">
                                <section class="space-y-2">
                                    <div>
                                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Reviewing') }}</h3>
                                        <p class="mt-1 text-xs text-muted">{{ __('The photo currently open in the work area.') }}</p>
                                    </div>

                                    @if ($photoReviewPhoto instanceof ItemPhoto)
                                        @php
                                            $activeDisplayAsset = $photoReviewPhoto->displayAsset();
                                            $activeSelectedAsset = $photoReviewPhoto->use_cleaned_photo ? $photoReviewPhoto->activeCleanedAsset() : null;
                                            $activeListingLabel = $activeSelectedAsset instanceof MediaAsset
                                                ? (data_get($activeSelectedAsset->metadata, 'provider_label')
                                                    ?: \Illuminate\Support\Str::headline((string) data_get($activeSelectedAsset->metadata, 'provider', __('cleanup provider'))))
                                                : __('Original');
                                        @endphp

                                        @if ($activeDisplayAsset instanceof MediaAsset)
                                            <div class="rounded-2xl border border-accent bg-surface-card p-2 shadow-sm ring-2 ring-accent/20">
                                                <img
                                                    src="{{ $activeDisplayAsset->displayUrl() }}"
                                                    alt="{{ __('Selected item photo: :filename', ['filename' => $activeDisplayAsset->original_filename ?? __('Photo')]) }}"
                                                    class="aspect-square w-full rounded-xl object-cover"
                                                    loading="lazy"
                                                />
                                                <div class="mt-2 flex items-center justify-between gap-2">
                                                    <span class="min-w-0 truncate text-sm font-medium text-ink">{{ __('Photo :current of :total', ['current' => $photoReviewPosition['current'], 'total' => $photoReviewPosition['total']]) }}</span>
                                                    <x-ui.badge :variant="$photoReviewPhoto->use_cleaned_photo ? 'success' : 'default'">{{ $activeListingLabel }}</x-ui.badge>
                                                </div>
                                            </div>
                                        @endif
                                    @endif
                                </section>

                                <section class="space-y-2">
                                    <div>
                                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Available photos') }}</h3>
                                        <p class="mt-1 text-xs text-muted">{{ __('Choose another photo to review.') }}</p>
                                    </div>

                                    @if ($availablePhotos->isEmpty())
                                        <p class="rounded-2xl border border-dashed border-border-default bg-surface-subtle p-3 text-sm text-muted">{{ __('No other photos yet.') }}</p>
                                    @else
                                        <div class="grid grid-cols-2 gap-2 xl:grid-cols-1">
                                            @foreach ($availablePhotos as $photo)
                                                @php
                                                    $displayAsset = $photo->displayAsset();
                                                    $selectedAsset = $photo->use_cleaned_photo ? $photo->activeCleanedAsset() : null;
                                                    $listingLabel = $selectedAsset instanceof MediaAsset
                                                        ? (data_get($selectedAsset->metadata, 'provider_label')
                                                            ?: \Illuminate\Support\Str::headline((string) data_get($selectedAsset->metadata, 'provider', __('cleanup provider'))))
                                                        : __('Original');
                                                    $versionCount = $photo->cleanedAssets->count() + 1;
                                                    $photoNumber = $item->photos->search(fn (ItemPhoto $candidate): bool => $candidate->id === $photo->id);
                                                    $photoNumber = is_int($photoNumber) ? $photoNumber + 1 : $loop->iteration;
                                                @endphp

                                                @if ($displayAsset instanceof MediaAsset)
                                                    <button
                                                        type="button"
                                                        wire:key="photo-roll-{{ $photo->id }}"
                                                        wire:click="openPhotoReview({{ $photo->id }})"
                                                        class="group overflow-hidden rounded-2xl border border-border-default bg-surface-card text-left transition-colors hover:border-accent hover:bg-surface-subtle"
                                                    >
                                                        <img
                                                            src="{{ $displayAsset->displayUrl() }}"
                                                            alt="{{ __('Review item photo: :filename', ['filename' => $displayAsset->original_filename ?? __('Photo')]) }}"
                                                            class="aspect-square w-full cursor-pointer object-cover"
                                                            loading="lazy"
                                                        />
                                                        <span class="block space-y-1 p-2">
                                                            <span class="flex items-center justify-between gap-2">
                                                                <span class="truncate text-xs font-medium text-ink">{{ __('Photo :number', ['number' => $photoNumber]) }}</span>
                                                                <x-ui.badge :variant="$photo->use_cleaned_photo ? 'success' : 'default'">{{ $listingLabel }}</x-ui.badge>
                                                            </span>
                                                            <span class="block text-[11px] text-muted">{{ trans_choice(':count version|:count versions', $versionCount, ['count' => $versionCount]) }}</span>
                                                        </span>
                                                    </button>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </section>
                            </aside>

                            @if ($photoReviewPhoto instanceof ItemPhoto)
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
                                        && ! ($activeProviderCleanedAsset instanceof MediaAsset);
                                    $reviewVersions = collect([[
                                        'id' => 'original',
                                        'label' => __('Original'),
                                        'asset' => $reviewOriginalAsset,
                                        'type' => 'original',
                                        'selected' => $originalSelected,
                                        'subtitle' => __('Source image'),
                                    ]])->merge($reviewCleanedAssets->map(function (MediaAsset $asset) use ($selectedCleanedAssetId): array {
                                        $provider = data_get($asset->metadata, 'provider_label')
                                            ?: \Illuminate\Support\Str::headline((string) data_get($asset->metadata, 'provider', __('cleanup provider')));
                                        $cleanedAt = data_get($asset->metadata, 'cleaned_at');

                                        return [
                                            'id' => 'cleaned-'.$asset->id,
                                            'label' => $provider,
                                            'asset' => $asset,
                                            'type' => 'cleaned',
                                            'selected' => $selectedCleanedAssetId === $asset->id,
                                            'subtitle' => __('Cleaned :time', [
                                                'time' => $cleanedAt ? \Illuminate\Support\Carbon::parse($cleanedAt)->diffForHumans() : __('recently'),
                                            ]),
                                        ];
                                    }))->values();
                                    $selectedReviewVersions = $reviewVersions->filter(fn (array $version): bool => (bool) $version['selected'])->values();
                                    $availableReviewVersions = $reviewVersions->reject(fn (array $version): bool => (bool) $version['selected'])->values();
                                    $reviewVersionTabs = $reviewVersions
                                        ->map(fn (array $version): array => ['id' => $version['id'], 'label' => $version['label']])
                                        ->values()
                                        ->all();
                                    $defaultReviewVersionId = $reviewCleanedAsset ? 'cleaned-'.$reviewCleanedAsset->id : 'original';
                                    $unselectedCleanedVersionCount = $photoReviewPhoto->use_cleaned_photo && $selectedCleanedAsset instanceof MediaAsset
                                        ? $reviewCleanedAssets->reject(fn (MediaAsset $asset): bool => $asset->id === $selectedCleanedAsset->id)->count()
                                        : $reviewCleanedAssets->count();
                                @endphp

                                <div wire:key="photo-workbench-{{ $photoReviewPhoto->id }}" class="min-w-0 space-y-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-medium tracking-tight text-ink">{{ __('Work area') }}</h3>
                                            <p class="mt-1 text-sm text-muted">
                                                {{ __('Photo :current of :total', ['current' => $photoReviewPosition['current'], 'total' => $photoReviewPosition['total']]) }}
                                                <span aria-hidden="true">·</span>
                                                {{ $reviewFilename }}
                                            </p>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ui.badge>{{ trans_choice(':count version|:count versions', $reviewVersions->count(), ['count' => $reviewVersions->count()]) }}</x-ui.badge>
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="previousPhotoReview">
                                                <x-icon name="heroicon-o-chevron-left" class="h-4 w-4" />
                                                {{ __('Previous') }}
                                            </x-ui.button>
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="nextPhotoReview">
                                                {{ __('Next') }}
                                                <x-icon name="heroicon-o-chevron-right" class="h-4 w-4" />
                                            </x-ui.button>
                                        </div>
                                    </div>

                                    <div class="grid gap-4 xl:grid-cols-[18rem_minmax(0,1fr)]">
                                        <aside class="space-y-4">
                                            <section class="space-y-2">
                                                <div>
                                                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Listing image') }}</h3>
                                                    <p class="mt-1 text-xs text-muted">{{ __('The version marketplace listings use.') }}</p>
                                                </div>

                                                <div class="space-y-2">
                                                    @foreach ($selectedReviewVersions as $version)
                                                        @php
                                                            /** @var MediaAsset $versionAsset */
                                                            $versionAsset = $version['asset'];
                                                        @endphp
                                                        <button
                                                            type="button"
                                                            disabled
                                                            class="flex w-full items-center gap-3 rounded-2xl border border-accent bg-surface-card p-2 text-left shadow-sm ring-2 ring-accent/20"
                                                        >
                                                            <img
                                                                src="{{ $versionAsset->displayUrl() }}"
                                                                alt="{{ __(':version thumbnail for :filename', ['version' => $version['label'], 'filename' => $versionAsset->original_filename ?? $reviewFilename]) }}"
                                                                class="h-14 w-14 rounded-xl object-cover"
                                                                loading="lazy"
                                                            />
                                                            <span class="min-w-0 flex-1">
                                                                <span class="block truncate text-sm font-medium text-ink">{{ $version['label'] }}</span>
                                                                <span class="block truncate text-xs text-muted">{{ $version['subtitle'] }}</span>
                                                            </span>
                                                            <x-icon name="heroicon-o-check" class="h-4 w-4 text-status-success" />
                                                        </button>
                                                    @endforeach
                                                </div>
                                            </section>

                                            <section class="space-y-2">
                                                <div>
                                                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Other versions') }}</h3>
                                                    <p class="mt-1 text-xs text-muted">{{ __('Choose one to make it the listing image.') }}</p>
                                                </div>

                                                @if ($availableReviewVersions->isEmpty())
                                                    <p class="rounded-2xl border border-dashed border-border-default bg-surface-subtle p-3 text-sm text-muted">{{ __('No alternates yet.') }}</p>
                                                @else
                                                    <div class="space-y-2">
                                                        @foreach ($availableReviewVersions as $version)
                                                            @php
                                                                /** @var MediaAsset $versionAsset */
                                                                $versionAsset = $version['asset'];
                                                            @endphp
                                                            <button
                                                                type="button"
                                                                @if ($this->canEdit())
                                                                    @if ($version['type'] === 'original')
                                                                        wire:click="revertCleanedPhoto({{ $photoReviewPhoto->id }})"
                                                                        wire:target="revertCleanedPhoto({{ $photoReviewPhoto->id }})"
                                                                    @else
                                                                        wire:click="acceptCleanedPhoto({{ $photoReviewPhoto->id }}, {{ $versionAsset->id }})"
                                                                        wire:target="acceptCleanedPhoto({{ $photoReviewPhoto->id }})"
                                                                    @endif
                                                                    wire:loading.attr="disabled"
                                                                @else
                                                                    disabled
                                                                @endif
                                                                class="flex w-full items-center gap-3 rounded-2xl border border-border-default bg-surface-card p-2 text-left transition-colors hover:border-accent hover:bg-surface-subtle disabled:cursor-default disabled:hover:border-border-default disabled:hover:bg-surface-card"
                                                            >
                                                                <img
                                                                    src="{{ $versionAsset->displayUrl() }}"
                                                                    alt="{{ __(':version thumbnail for :filename', ['version' => $version['label'], 'filename' => $versionAsset->original_filename ?? $reviewFilename]) }}"
                                                                    class="h-14 w-14 rounded-xl object-cover"
                                                                    loading="lazy"
                                                                />
                                                                <span class="min-w-0 flex-1">
                                                                    <span class="block truncate text-sm font-medium text-ink">{{ $version['label'] }}</span>
                                                                    <span class="block truncate text-xs text-muted">{{ $version['subtitle'] }}</span>
                                                                </span>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </section>

                                            @if ($this->canEdit())
                                                <section class="space-y-3 rounded-2xl border border-border-default bg-surface-subtle p-3">
                                                    <div>
                                                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Image operations') }}</h3>
                                                        <p class="mt-1 text-xs text-muted">{{ __('Run provider-specific edits without changing the listing choice.') }}</p>
                                                    </div>

                                                    <div class="grid gap-2 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)] sm:items-end">
                                                        @if (count($photoCleanupProviders) > 1)
                                                            <x-ui.select
                                                                id="photo-review-cleanup-provider"
                                                                wire:change="setPhotoCleanupProvider($event.target.value)"
                                                                label="{{ __('Provider') }}"
                                                            >
                                                                @foreach ($photoCleanupProviders as $cleanupProvider)
                                                                    <option value="{{ $cleanupProvider['key'] }}" @selected($cleanupProvider['active'])>
                                                                        {{ $cleanupProvider['label'] }}
                                                                    </option>
                                                                @endforeach
                                                            </x-ui.select>
                                                        @elseif ($activeCleanupProviderLabel)
                                                            <div>
                                                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Provider') }}</p>
                                                                <x-ui.badge class="mt-1">{{ $activeCleanupProviderLabel }}</x-ui.badge>
                                                            </div>
                                                        @endif

                                                        @if (! $hasPhotoCleanupProvider)
                                                            <p class="text-xs text-muted sm:col-span-2">{{ __('Connect an image provider to run photo operations.') }}</p>
                                                        @elseif ($canCleanWithActiveProvider || ! $reviewCleanedAsset)
                                                            <x-ui.button type="button" variant="primary" size="sm" class="w-full" wire:click="runPhotoCleanup({{ $photoReviewPhoto->id }})" wire:loading.attr="disabled" wire:target="runPhotoCleanup({{ $photoReviewPhoto->id }})">
                                                                <x-icon name="heroicon-o-sparkles" class="h-4 w-4" />
                                                                {{ $activeCleanupProviderLabel ? __('Remove background with :provider', ['provider' => $activeCleanupProviderLabel]) : __('Remove background') }}
                                                            </x-ui.button>
                                                        @else
                                                            <p class="text-xs text-muted sm:col-span-2">
                                                                {{ $activeCleanupProviderLabel
                                                                    ? __('This provider already has a cleaned version for this photo.')
                                                                    : __('Connect a provider to run image operations.') }}
                                                            </p>
                                                        @endif
                                                    </div>
                                                </section>

                                                <section class="space-y-3 rounded-2xl border border-border-default bg-surface-subtle p-3">
                                                    <div>
                                                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Delete') }}</h3>
                                                        <p class="mt-1 text-xs text-muted">{{ __('Remove alternates or delete the entire photo.') }}</p>
                                                    </div>

                                                    @if ($unselectedCleanedVersionCount > 0)
                                                        <x-ui.button type="button" variant="outline" size="sm" class="w-full" wire:click="deleteUnselectedCleanedVersions({{ $photoReviewPhoto->id }})" wire:confirm="{{ __('Delete all cleaned versions that are not selected for listings?') }}" wire:loading.attr="disabled" wire:target="deleteUnselectedCleanedVersions({{ $photoReviewPhoto->id }})">
                                                            <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                                            {{ __('Delete other cleaned versions') }}
                                                        </x-ui.button>
                                                    @endif

                                                    <x-ui.button type="button" variant="danger" size="sm" class="w-full" wire:click="deletePhoto({{ $photoReviewPhoto->id }})" wire:confirm="{{ __('Delete this photo and all its versions?') }}" wire:loading.attr="disabled" wire:target="deletePhoto({{ $photoReviewPhoto->id }})">
                                                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                                        {{ __('Delete photo') }}
                                                    </x-ui.button>
                                                </section>
                                            @endif
                                        </aside>

                                        <section class="min-w-0 space-y-4">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <h3 class="text-sm font-medium tracking-tight text-ink">{{ __('Preview') }}</h3>
                                                    <p class="mt-1 text-sm text-muted">{{ __('Compare edges and defects, then choose the version listings use.') }}</p>
                                                </div>

                                                <x-ui.segmented-control
                                                    x-model="background"
                                                    :options="[
                                                        ['value' => 'checker', 'label' => __('Checker')],
                                                        ['value' => 'light', 'label' => __('Light')],
                                                        ['value' => 'dark', 'label' => __('Dark')],
                                                    ]"
                                                    value="checker"
                                                    :label="__('Preview background')"
                                                />
                                            </div>

                                            <x-ui.tabs :tabs="$reviewVersionTabs" :default="$defaultReviewVersionId" variant="pill" size="sm" persistence="none">
                                                @foreach ($reviewVersions as $version)
                                                    @php
                                                        /** @var MediaAsset $versionAsset */
                                                        $versionAsset = $version['asset'];
                                                        $versionIsSelected = (bool) $version['selected'];
                                                    @endphp

                                                    <x-ui.tab :id="$version['id']" wire:key="photo-review-version-{{ $photoReviewPhoto->id }}-{{ $version['id'] }}">
                                                        @if ($version['type'] === 'original')
                                                            <section class="space-y-2">
                                                                <h4 class="text-sm font-medium text-ink">{{ __('Original') }}</h4>

                                                                <div class="flex min-h-[28rem] items-center justify-center overflow-auto rounded-xl border p-3 {{ $versionIsSelected ? 'border-accent ring-2 ring-accent/20' : 'border-border-default' }}" :class="panelClass()" :style="panelStyle()">
                                                                    <img
                                                                        src="{{ $reviewOriginalAsset->displayUrl() }}"
                                                                        alt="{{ __('Original photo: :filename', ['filename' => $reviewFilename]) }}"
                                                                        class="max-h-[64vh] w-full object-contain transition-transform duration-150"
                                                                        :class="zoomed ? 'scale-150 cursor-zoom-out' : 'cursor-zoom-in'"
                                                                        @click="zoomed = ! zoomed"
                                                                    />
                                                                </div>
                                                            </section>
                                                        @else
                                                            <div class="grid gap-4 lg:grid-cols-2">
                                                                <section class="space-y-2">
                                                                    <h4 class="text-sm font-medium text-ink">{{ __('Original') }}</h4>

                                                                    <div class="flex min-h-[28rem] items-center justify-center overflow-auto rounded-xl border p-3 {{ $originalSelected ? 'border-accent ring-2 ring-accent/20' : 'border-border-default' }}" :class="panelClass()" :style="panelStyle()">
                                                                        <img
                                                                            src="{{ $reviewOriginalAsset->displayUrl() }}"
                                                                            alt="{{ __('Original photo: :filename', ['filename' => $reviewFilename]) }}"
                                                                            class="max-h-[64vh] w-full object-contain transition-transform duration-150"
                                                                            :class="zoomed ? 'scale-150 cursor-zoom-out' : 'cursor-zoom-in'"
                                                                            @click="zoomed = ! zoomed"
                                                                        />
                                                                    </div>
                                                                </section>

                                                                <section class="space-y-2">
                                                                    <h4 class="text-sm font-medium text-ink">{{ $version['label'] }}</h4>

                                                                    <div class="flex min-h-[28rem] items-center justify-center overflow-auto rounded-xl border p-3 {{ $versionIsSelected ? 'border-accent ring-2 ring-accent/20' : 'border-border-default' }}" :class="panelClass()" :style="panelStyle()">
                                                                        <img
                                                                            src="{{ $versionAsset->displayUrl() }}"
                                                                            alt="{{ __('Cleaned photo: :filename', ['filename' => $versionAsset->original_filename ?? $reviewFilename]) }}"
                                                                            class="max-h-[64vh] w-full object-contain transition-transform duration-150"
                                                                            :class="zoomed ? 'scale-150 cursor-zoom-out' : 'cursor-zoom-in'"
                                                                            @click="zoomed = ! zoomed"
                                                                        />
                                                                    </div>
                                                                </section>
                                                            </div>
                                                        @endif

                                                        @if ($version['type'] === 'cleaned')
                                                            <p class="mt-3 text-xs text-muted">
                                                                {{ __('Cleaned :time via :provider. Original stays available; choosing a version only changes what marketplace listings use.', [
                                                                    'time' => data_get($versionAsset->metadata, 'cleaned_at') ? \Illuminate\Support\Carbon::parse(data_get($versionAsset->metadata, 'cleaned_at'))->diffForHumans() : __('recently'),
                                                                    'provider' => $version['label'],
                                                                ]) }}
                                                            </p>
                                                        @endif
                                                    </x-ui.tab>
                                                @endforeach
                                            </x-ui.tabs>
                                        </section>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </x-ui.card>
            </div>
        </x-ui.tab>
        </x-ui.tabs>
    </div>
</div>
