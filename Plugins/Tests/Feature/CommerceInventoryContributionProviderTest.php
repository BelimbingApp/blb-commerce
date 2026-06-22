<?php

use App\Base\Software\Inventory\ContributionSummary;
use App\Modules\Commerce\Plugins\Inventory\CommerceInventoryContributionProvider;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;

it('summarizes registered commerce plugin contributions for the inventory', function (): void {
    $registry = new CommercePluginRegistry;
    $registry->registerCatalogPreset(['id' => 'electronics']);
    $registry->registerWorkbenchPanel(['id' => 'qa', 'label' => 'QA panel', 'subject' => 'commerce.inventory.item']);

    $contributions = collect((new CommerceInventoryContributionProvider($registry))->contributions());

    $preset = $contributions->firstWhere(fn (ContributionSummary $c): bool => $c->seam === 'commerce.catalog.preset');
    $panel = $contributions->firstWhere(fn (ContributionSummary $c): bool => $c->seam === 'commerce.workbench.panel');

    expect($preset)->not->toBeNull()
        ->and($preset->hostModule)->toBe('commerce/catalog')
        ->and($preset->kind)->toBe('data')
        ->and($preset->metadata['id'])->toBe('electronics')
        ->and($panel)->not->toBeNull()
        ->and($panel->label)->toBe('Workbench panel: QA panel')
        ->and($panel->kind)->toBe('panel');
});

it('reports no contributions when the registry is empty', function (): void {
    expect((new CommerceInventoryContributionProvider(new CommercePluginRegistry))->contributions())->toBe([]);
});
