<?php

namespace App\Modules\Commerce\Plugins\Inventory;

use App\Base\Software\Inventory\Contracts\InventoryContributionProvider;
use App\Base\Software\Inventory\ContributionSummary;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;

/**
 * Reports the contributions discovered on the Commerce plugin seam to the Software
 * Inventory: marketplace channels, readiness contributors, catalog presets, workbench
 * panels and insight pages.
 *
 * Read-only adapter over CommercePluginRegistry — the inventory shows that these
 * contributions exist; Commerce keeps full ownership of what they do.
 */
class CommerceInventoryContributionProvider implements InventoryContributionProvider
{
    public function __construct(private readonly CommercePluginRegistry $registry) {}

    /**
     * @return list<ContributionSummary>
     */
    public function contributions(): array
    {
        $summaries = [];

        foreach ($this->registry->marketplaceChannelProviders() as $provider) {
            $summaries[] = new ContributionSummary(
                hostModule: 'commerce/marketplace',
                seam: 'commerce.marketplace.channel',
                kind: ContributionSummary::KIND_CHANNEL,
                label: __('Marketplace channel: :name', ['name' => class_basename($provider)]),
                metadata: ['provider' => $provider],
            );
        }

        foreach ($this->registry->readinessContributors() as $contributor) {
            $summaries[] = new ContributionSummary(
                hostModule: 'commerce/inventory',
                seam: 'commerce.readiness',
                kind: ContributionSummary::KIND_READINESS,
                label: __('Readiness contributor: :name', ['name' => class_basename($contributor)]),
                metadata: ['contributor' => $contributor],
            );
        }

        foreach (array_keys($this->registry->catalogPresets()) as $id) {
            $summaries[] = new ContributionSummary(
                hostModule: 'commerce/catalog',
                seam: 'commerce.catalog.preset',
                kind: ContributionSummary::KIND_DATA,
                label: __('Catalog preset: :id', ['id' => $id]),
                metadata: ['id' => (string) $id],
            );
        }

        foreach ($this->registry->workbenchPanels() as $id => $panel) {
            $summaries[] = new ContributionSummary(
                hostModule: 'commerce/inventory',
                seam: 'commerce.workbench.panel',
                kind: ContributionSummary::KIND_PANEL,
                label: __('Workbench panel: :label', ['label' => (string) ($panel['label'] ?? $id)]),
                metadata: ['id' => (string) $id],
            );
        }

        foreach ($this->registry->insightPages() as $id => $page) {
            $summaries[] = new ContributionSummary(
                hostModule: 'commerce/marketplace',
                seam: 'commerce.insight.page',
                kind: ContributionSummary::KIND_PANEL,
                label: __('Insight page: :label', ['label' => (string) ($page['label'] ?? $id)]),
                metadata: ['id' => (string) $id],
            );
        }

        return $summaries;
    }
}
