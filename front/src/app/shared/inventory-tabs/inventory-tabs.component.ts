import { Component, Input, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';

export type InventoryTab = 'catalogue' | 'stock' | 'depots';

interface TabLink {
  key: InventoryTab;
  label: string;
  icon: string;
  route: string;
  permission: string;
}

// Stock is the default tab (the menu entry opens it), so it comes first.
const TABS: TabLink[] = [
  { key: 'stock', label: 'Stock', icon: '📋', route: '/stock', permission: 'view stock' },
  { key: 'catalogue', label: 'Catalogue', icon: '🛞', route: '/products', permission: 'view products' },
  { key: 'depots', label: 'Dépôts', icon: '🏬', route: '/depots', permission: 'view stock' },
];

/**
 * Tab bar of the "Produits & Inventaire" page. Each tab is its own route
 * (/products, /stock) so links, bookmarks and permission guards keep working;
 * a tab the user may not open is not shown.
 */
@Component({
  selector: 'app-inventory-tabs',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './inventory-tabs.component.html',
  styleUrl: './inventory-tabs.component.scss',
})
export class InventoryTabsComponent {
  @Input({ required: true }) active!: InventoryTab;

  private auth = inject(AuthService);
  readonly tabs = computed(() => TABS.filter((tab) => this.auth.hasPermission(tab.permission)));
}
