<?php

namespace App\Modules\Commerce\Inventory\Livewire\Items\Concerns;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemFitment;

trait ManagesItemFitments
{
    public bool $fitmentUniversal = false;

    public string $fitmentYear = '';

    public string $fitmentMake = '';

    public string $fitmentModel = '';

    public string $fitmentTrim = '';

    public string $fitmentEngine = '';

    public string $fitmentNotes = '';

    public ?int $editingFitmentId = null;

    public ?int $copyFitmentsFromItemId = null;

    /**
     * The add/edit form stays hidden until asked for — most visits read the
     * fitment list and never touch it.
     */
    public bool $fitmentFormOpen = false;

    public function openFitmentForm(): void
    {
        $this->authorizeUpdate();

        $this->fitmentFormOpen = true;
    }

    public function addFitment(): void
    {
        $this->authorizeUpdate();

        $validated = $this->validateFitmentForm();

        if (! $validated['fitmentUniversal'] && $this->fitmentProperties($validated) === []) {
            $this->addError('fitmentYear', __('Enter at least one compatibility value, or mark this item as universal fit.'));
            session()->flash('error', __('Fitment was not saved. Enter compatibility values or mark it universal.'));

            return;
        }

        $this->createFitment($validated);
        $this->resetFitmentForm();
        $this->fitmentFormOpen = false;
        $this->item->load('fitments');
        $this->refreshAllChannelReadiness();
        session()->flash('success', __('Fitment added.'));
    }

    public function deleteFitment(int $fitmentId): void
    {
        $this->authorizeUpdate();

        $deleted = ItemFitment::query()
            ->where('id', $fitmentId)
            ->where('company_id', $this->item->company_id)
            ->where('item_id', $this->item->id)
            ->delete();

        $this->item->load('fitments');
        $this->refreshAllChannelReadiness();

        if ($deleted > 0) {
            session()->flash('success', __('Fitment deleted.'));
        }
    }

    public function editFitment(int $fitmentId): void
    {
        $this->authorizeUpdate();

        $fitment = $this->fitmentForItem($fitmentId);

        if (! $fitment instanceof ItemFitment) {
            return;
        }

        $this->editingFitmentId = $fitment->id;
        $this->fitmentFormOpen = true;
        $this->fitmentUniversal = $fitment->is_universal;
        $this->fitmentYear = (string) $fitment->display_year;
        $this->fitmentMake = (string) $fitment->display_make;
        $this->fitmentModel = (string) $fitment->display_model;
        $this->fitmentTrim = (string) $fitment->display_trim;
        $this->fitmentEngine = (string) $fitment->display_engine;
        $this->fitmentNotes = (string) $fitment->notes;
    }

    public function updateFitment(): void
    {
        $this->authorizeUpdate();

        if ($this->editingFitmentId === null) {
            return;
        }

        $fitment = $this->fitmentForItem($this->editingFitmentId);

        if (! $fitment instanceof ItemFitment) {
            $this->editingFitmentId = null;

            return;
        }

        $validated = $this->validateFitmentForm();

        if (! $validated['fitmentUniversal'] && $this->fitmentProperties($validated) === []) {
            $this->addError('fitmentYear', __('Enter at least one compatibility value, or mark this item as universal fit.'));
            session()->flash('error', __('Fitment was not saved. Enter compatibility values or mark it universal.'));

            return;
        }

        $this->fillFitment($fitment, $validated);
        $fitment->save();

        $this->editingFitmentId = null;
        $this->fitmentFormOpen = false;
        $this->resetFitmentForm();
        $this->item->load('fitments');
        $this->refreshAllChannelReadiness();
        session()->flash('success', __('Fitment updated.'));
    }

    public function cancelFitmentEdit(): void
    {
        $this->editingFitmentId = null;
        $this->fitmentFormOpen = false;
        $this->resetFitmentForm();
    }

    public function bootstrapFitmentFromAttributes(): void
    {
        $this->authorizeUpdate();

        $this->item->loadMissing('catalogAttributeValues.attribute');

        $properties = [];
        foreach ($this->fitmentAttributeCodes() as $property => $attributeCode) {
            $value = $this->item->catalogAttributeValues
                ->first(fn ($attributeValue): bool => $attributeValue->attribute?->code === $attributeCode)
                ?->display_value;

            if (is_string($value) && trim($value) !== '') {
                $properties[$property] = trim($value);
            }
        }

        if ($properties === []) {
            session()->flash('error', __('No configured fitment attributes have values on this item.'));

            return;
        }

        $this->createFitment([
            'fitmentUniversal' => false,
            'fitmentYear' => $properties['Year'] ?? '',
            'fitmentMake' => $properties['Make'] ?? '',
            'fitmentModel' => $properties['Model'] ?? '',
            'fitmentTrim' => $properties['Trim'] ?? '',
            'fitmentEngine' => $properties['Engine'] ?? '',
            'fitmentNotes' => __('Created from item attributes.'),
        ]);

        $this->item->load('fitments');
        $this->refreshAllChannelReadiness();
        session()->flash('success', __('Created fitment from item attributes.'));
    }

    public function copyFitmentsFromItem(): void
    {
        $this->authorizeUpdate();

        if ($this->copyFitmentsFromItemId === null) {
            $this->addError('copyFitmentsFromItemId', __('Choose an item to copy from.'));
            session()->flash('error', __('Choose an item to copy fitments from.'));

            return;
        }

        $source = Item::query()
            ->where('company_id', $this->item->company_id)
            ->whereKey($this->copyFitmentsFromItemId)
            ->with('fitments')
            ->first();

        if (! $source instanceof Item || $source->fitments->isEmpty()) {
            $this->addError('copyFitmentsFromItemId', __('The selected item has no fitment to copy.'));
            session()->flash('error', __('The selected item has no fitment to copy.'));

            return;
        }

        $this->resetValidation('copyFitmentsFromItemId');

        foreach ($source->fitments as $fitment) {
            ItemFitment::query()->create([
                'company_id' => $this->item->company_id,
                'item_id' => $this->item->id,
                'channel' => $fitment->channel,
                'marketplace_id' => $fitment->marketplace_id,
                'category_tree_id' => $fitment->category_tree_id,
                'category_id' => $fitment->category_id,
                'is_universal' => $fitment->is_universal,
                'compatibility_properties' => $fitment->compatibility_properties,
                'display_year' => $fitment->display_year,
                'display_make' => $fitment->display_make,
                'display_model' => $fitment->display_model,
                'display_trim' => $fitment->display_trim,
                'display_engine' => $fitment->display_engine,
                'source' => ItemFitment::SOURCE_OPERATOR,
                'confidence' => $fitment->confidence,
                'notes' => __('Copied from :sku.', ['sku' => $source->sku]),
            ]);
        }

        $copied = $source->fitments->count();
        $this->copyFitmentsFromItemId = null;
        $this->item->load('fitments');
        $this->refreshAllChannelReadiness();
        session()->flash('success', trans_choice('Copied :count fitment entry.|Copied :count fitment entries.', $copied, ['count' => $copied]));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createFitment(array $values): void
    {
        $fitment = new ItemFitment([
            'company_id' => $this->item->company_id,
            'item_id' => $this->item->id,
        ]);

        $this->fillFitment($fitment, $values);
        $fitment->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fillFitment(ItemFitment $fitment, array $values): void
    {
        $universal = (bool) ($values['fitmentUniversal'] ?? false);

        $fitment->fill([
            'is_universal' => $universal,
            'compatibility_properties' => $universal ? null : $this->fitmentProperties($values),
            'display_year' => $universal ? null : $this->nullableFitmentValue($values['fitmentYear'] ?? null),
            'display_make' => $universal ? null : $this->nullableFitmentValue($values['fitmentMake'] ?? null),
            'display_model' => $universal ? null : $this->nullableFitmentValue($values['fitmentModel'] ?? null),
            'display_trim' => $universal ? null : $this->nullableFitmentValue($values['fitmentTrim'] ?? null),
            'display_engine' => $universal ? null : $this->nullableFitmentValue($values['fitmentEngine'] ?? null),
            'source' => $fitment->source ?: ItemFitment::SOURCE_OPERATOR,
            'confidence' => $fitment->confidence ?: ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
            'notes' => $this->nullableFitmentValue($values['fitmentNotes'] ?? null),
        ]);
    }

    /**
     * @return array{fitmentUniversal: bool, fitmentYear: string|null, fitmentMake: string|null, fitmentModel: string|null, fitmentTrim: string|null, fitmentEngine: string|null, fitmentNotes: string|null}
     */
    private function validateFitmentForm(): array
    {
        return validator([
            'fitmentUniversal' => $this->fitmentUniversal,
            'fitmentYear' => $this->fitmentYear,
            'fitmentMake' => $this->fitmentMake,
            'fitmentModel' => $this->fitmentModel,
            'fitmentTrim' => $this->fitmentTrim,
            'fitmentEngine' => $this->fitmentEngine,
            'fitmentNotes' => $this->fitmentNotes,
        ], [
            'fitmentUniversal' => ['boolean'],
            'fitmentYear' => ['nullable', 'string', 'max:40'],
            'fitmentMake' => ['nullable', 'string', 'max:80'],
            'fitmentModel' => ['nullable', 'string', 'max:120'],
            'fitmentTrim' => ['nullable', 'string', 'max:160'],
            'fitmentEngine' => ['nullable', 'string', 'max:160'],
            'fitmentNotes' => ['nullable', 'string', 'max:1000'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function fitmentProperties(array $values): array
    {
        return collect([
            'Year' => $values['fitmentYear'] ?? null,
            'Make' => $values['fitmentMake'] ?? null,
            'Model' => $values['fitmentModel'] ?? null,
            'Trim' => $values['fitmentTrim'] ?? null,
            'Engine' => $values['fitmentEngine'] ?? null,
        ])
            ->map(fn (mixed $value): ?string => $this->nullableFitmentValue($value))
            ->filter()
            ->all();
    }

    private function nullableFitmentValue(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function resetFitmentForm(): void
    {
        $this->fitmentUniversal = false;
        $this->fitmentYear = '';
        $this->fitmentMake = '';
        $this->fitmentModel = '';
        $this->fitmentTrim = '';
        $this->fitmentEngine = '';
        $this->fitmentNotes = '';
    }

    private function fitmentForItem(int $fitmentId): ?ItemFitment
    {
        return ItemFitment::query()
            ->where('id', $fitmentId)
            ->where('company_id', $this->item->company_id)
            ->where('item_id', $this->item->id)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function fitmentAttributeCodes(): array
    {
        $codes = config('commerce.inventory.fitment_attribute_codes', []);

        return is_array($codes) ? array_filter($codes, 'is_string') : [];
    }

    /**
     * Bootstrap is only offered when it would actually produce something:
     * fitment attribute codes are configured and at least one has a value on
     * this item. Showing the button just to answer with "no values" is noise.
     */
    private function canBootstrapFitmentFromAttributes(): bool
    {
        $codes = $this->fitmentAttributeCodes();

        if ($codes === []) {
            return false;
        }

        $this->item->loadMissing('catalogAttributeValues.attribute');

        return $this->item->catalogAttributeValues
            ->contains(fn ($value): bool => in_array($value->attribute?->code, $codes, true)
                && is_string($value->display_value)
                && trim($value->display_value) !== '');
    }
}
