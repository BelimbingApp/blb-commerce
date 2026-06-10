# BLB Commerce

Commerce domain for the [Belimbing (BLB)](https://github.com/BelimbingApp/belimbing) framework: Catalog, Inventory, Marketplace, Sales, Settings, and the Commerce plugin seams.

This repository is a **nested-git domain repo**. It mounts at `app/Modules/Commerce/` inside a Belimbing checkout; the framework discovers its providers, migrations, menus, routes, settings, and tests by path convention — no registration step. See `docs/architecture/module-system.md` in the main repo.

```bash
git clone https://github.com/BelimbingApp/belimbing
git clone https://github.com/BelimbingApp/blb-commerce belimbing/app/Modules/Commerce
```

Licensed under AGPL-3.0-only, same as the framework.
