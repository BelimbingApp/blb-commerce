<?php

use App\Modules\Commerce\Plugins\Inventory\CommerceInventoryContributionProvider;

return [
    /*
    | Software Inventory contribution providers for the Commerce plugin seam.
    |
    | Discovered from `Config/inventory.php` by the Base
    | InventoryContributionDiscoveryService. The Commerce provider reports the
    | marketplace channels, readiness contributors, catalog presets, workbench
    | panels and insight pages registered on CommercePluginRegistry.
    */
    'contribution_providers' => [
        CommerceInventoryContributionProvider::class,
    ],
];
