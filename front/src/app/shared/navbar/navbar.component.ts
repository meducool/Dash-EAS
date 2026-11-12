import { Component, HostListener, OnDestroy, computed, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NavigationEnd, Router, RouterLink, RouterLinkActive } from '@angular/router';
import { filter } from 'rxjs';
import { AuthService } from '../../core/services/auth.service';
import { SettingsService } from '../../features/settings/data-access/settings.service';
import { CompanySettings, MenuLayout } from '../../features/settings/models/company-settings.model';

interface NavItem {
  icon: string;
  label: string;
  route?: string;
  permission?: string;
  exact?: boolean;
  children?: NavItem[];
  /**
   * One menu entry for a page split into tabs living on several routes: shown
   * when any tab is allowed, links to the first allowed one, and highlighted
   * on all of them.
   */
  tabs?: { route: string; permission: string }[];
}

const COLLAPSED_KEY = 'nav_collapsed';

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSED_KEY) === '1';
  } catch {
    return false;
  }
}

@Component({
  selector: 'app-navbar',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive],
  templateUrl: './navbar.component.html',
  styleUrl: './navbar.component.scss'
})
export class NavbarComponent implements OnDestroy {
  userName = computed(() => this.authService.user()?.name || '');
  companySettings = signal<CompanySettings | null>(null);
  brandLogoUrl = signal('logo.png');
  readonly menuLayout = computed<MenuLayout>(() => this.companySettings()?.menu_layout ?? 'vertical');
  userRole = computed(() => {
    const roles = this.authService.user()?.roles;
    return roles && roles.length > 0 ? roles[0].name : '';
  });
  // Mobile drawer (hamburger). A signal, like all template state: the app is zoneless.
  menuOpen = signal(false);

  // Desktop vertical layout only: the sidebar folds to an icon rail so wide
  // tables get the room back. The choice is a per-browser convenience.
  collapsed = signal(readCollapsed());
  // The rail only exists at sidebar widths (same breakpoint as the SCSS):
  // below it the menu is a drawer and must behave as if never folded.
  private desktop = signal(true);
  private desktopQuery: MediaQueryList | null = null;
  private desktopListener = (e: MediaQueryListEvent) => this.desktop.set(e.matches);
  readonly isRail = computed(() => this.menuLayout() === 'vertical' && this.collapsed() && this.desktop());

  private currentUrl = signal('');

  // Groups expanded by the user (vertical layout accordion) — a group's
  // label doubles as its identity key, same pattern as `activeType()` on the
  // transaction-categories page. Additive: opening one group never closes
  // another; only autoExpandActiveGroup() decides what opens by default.
  expandedGroups = signal<Set<string>>(new Set());

  // Pages used daily stay one click away; everything else is grouped by
  // theme (Finance, Administration) so the vertical menu doesn't need to
  // list ~25 rows at once — see navbar.component.scss for the accordion
  // collapse behind `.expanded`.
  allNavItems: NavItem[] = [
    { icon: '🏠', label: 'Accueil', route: '/dashboard', exact: true },
    { icon: '🏷️', label: 'Ventes', route: '/sales', permission: 'view sales' },
    { icon: '🔧', label: 'Service Auto', route: '/service-orders', permission: 'view service-orders' },
    { icon: '📦', label: 'Achats', route: '/achats', permission: 'view purchases' },
    { icon: '💰', label: 'Cash Flow', route: '/cash-flow', permission: 'view cash-flow' },
    {
      icon: '🛞',
      label: 'Produits & Inventaire',
      // Stock first: the menu opens on the stock tab by default.
      tabs: [
        { route: '/stock', permission: 'view stock' },
        { route: '/products', permission: 'view products' },
        { route: '/depots', permission: 'view stock' },
      ],
    },
    {
      icon: '🤝',
      label: 'Tiers',
      children: [
        { icon: '🧑‍💼', label: 'Clients', route: '/clients', permission: 'view clients' },
        { icon: '🏢', label: 'Fournisseurs', route: '/suppliers', permission: 'view suppliers' },
        { icon: '🚚', label: 'Transporteurs', route: '/carriers', permission: 'view carriers' },
        { icon: '🤝', label: 'Partenaires', route: '/partners', permission: 'view partners' },
        { icon: '🏭', label: 'Marques', route: '/brands', permission: 'view brands' },
      ]
    },
    {
      icon: '🏦',
      label: 'Finance',
      children: [
        { icon: '🏦', label: 'Comptes', route: '/accounts', permission: 'view accounts' },
        { icon: '🎯', label: 'Primes', route: '/primes', permission: 'view primes' },
        { icon: '🧾', label: 'Charges RH', route: '/charges-rh', permission: 'view hr-charges' },
        { icon: '📊', label: 'Reporting', route: '/reporting', permission: 'view reporting' },
      ]
    },
    {
      icon: '👤',
      label: 'Administration',
      children: [
        { icon: '👥', label: 'Utilisateurs', route: '/users', permission: 'view users' },
        { icon: '🔐', label: 'Rôles', route: '/roles', permission: 'view roles' },
        { icon: '📋', label: 'Activité', route: '/activity-log', permission: 'view activity-log' },
        { icon: '📈', label: 'KPI', route: '/kpi-history', permission: 'view activity-log' },
      ]
    },
    {
      icon: '⚙️',
      label: 'Paramètres',
      children: [
        { icon: '🏢', label: 'Entreprise', route: '/settings', permission: 'view settings' },
        { icon: '🏷️', label: 'Catégories', route: '/settings/transaction-categories', permission: 'view transaction-categories' },
      ]
    },
  ];

  visibleNavItems = computed(() => {
    return this.allNavItems
      .map(item => {
        if (item.children) {
          const visibleChildren = item.children
            .map(child => this.resolveTabs(child))
            .filter((child): child is NavItem => child !== null)
            .filter(child => !child.permission || this.authService.hasPermission(child.permission));
          if (visibleChildren.length > 0) {
            return { ...item, children: visibleChildren };
          }
          return null;
        }
        const resolved = this.resolveTabs(item);
        if (resolved && (!resolved.permission || this.authService.hasPermission(resolved.permission))) {
          return resolved;
        }
        return null;
      })
      .filter((item): item is NavItem => item !== null);
  });

  /** A tabbed entry becomes a plain link to its first allowed tab — or disappears. */
  private resolveTabs(item: NavItem): NavItem | null {
    if (!item.tabs) return item;
    const first = item.tabs.find(tab => this.authService.hasPermission(tab.permission));
    return first ? { ...item, route: first.route } : null;
  }

  /** Every route a menu entry stands for (its tabs, or its own route). */
  private routesOf(item: NavItem): string[] {
    return item.tabs ? item.tabs.map(tab => tab.route) : item.route ? [item.route] : [];
  }

  isChildActive(child: NavItem): boolean {
    const url = this.currentUrl().split('?')[0];
    return this.routesOf(child).some(route => url === route || url.startsWith(route + '/'));
  }

  constructor(
    private authService: AuthService,
    private settingsService: SettingsService,
    private router: Router,
  ) {
    const applySettings = (settings: CompanySettings) => {
      this.companySettings.set(settings);
      this.brandLogoUrl.set(settings.logo_url || 'logo.png');
    };
    this.settingsService.getCompanySettings().subscribe({ next: applySettings });
    // A logo changed in Paramètres › Entreprise shows up without reloading.
    this.settingsService.saved$.subscribe(applySettings);

    this.router.events.pipe(filter((e) => e instanceof NavigationEnd)).subscribe(() => {
      this.currentUrl.set(this.router.url);
      this.menuOpen.set(false);
      this.autoExpandActiveGroup();
    });
    this.currentUrl.set(this.router.url);
    this.autoExpandActiveGroup();

    if (typeof window !== 'undefined' && window.matchMedia) {
      this.desktopQuery = window.matchMedia('(min-width: 1025px)');
      this.desktop.set(this.desktopQuery.matches);
      this.desktopQuery.addEventListener('change', this.desktopListener);
    }
  }

  ngOnDestroy(): void {
    this.desktopQuery?.removeEventListener('change', this.desktopListener);
  }

  // Opens whichever group contains the current route, without touching
  // groups the user already opened manually — so navigating around never
  // surprises them by collapsing an unrelated section they left open.
  private autoExpandActiveGroup(): void {
    const url = this.router.url;
    const match = this.allNavItems.find((item) =>
      item.children?.some((child) => this.routesOf(child).some((route) => url.startsWith(route))),
    );
    if (match) {
      this.expandedGroups.update((set) => new Set(set).add(match.label));
    }
  }

  isGroupExpanded(item: NavItem): boolean {
    return this.expandedGroups().has(item.label);
  }

  // Lets a folded group (accordion closed, or icon rail) still show that the
  // current page lives inside it.
  isGroupActive(item: NavItem): boolean {
    const url = this.currentUrl();
    return !!item.children?.some((child) => this.routesOf(child).some((route) => url.startsWith(route)));
  }

  toggleGroup(item: NavItem, event: Event): void {
    event.preventDefault();
    // In the icon rail the children are hidden: a click on a group unfolds
    // the sidebar on that group instead of toggling something invisible.
    if (this.isRail()) {
      this.setCollapsed(false);
      this.expandedGroups.update((set) => new Set(set).add(item.label));
      return;
    }
    this.expandedGroups.update((set) => {
      const next = new Set(set);
      if (next.has(item.label)) {
        next.delete(item.label);
      } else {
        next.add(item.label);
      }
      return next;
    });
  }

  toggleCollapsed(): void {
    this.setCollapsed(!this.collapsed());
  }

  private setCollapsed(value: boolean): void {
    this.collapsed.set(value);
    try {
      localStorage.setItem(COLLAPSED_KEY, value ? '1' : '0');
    } catch {
      // Storage unavailable (private mode…): the preference just isn't kept.
    }
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.menuOpen.set(false);
  }

  toggleMenu() { this.menuOpen.update((open) => !open); }
  closeMenu() { this.menuOpen.set(false); }
  logout() { this.authService.logout(); }

  /** Shortcut next to the logo, for the users allowed to change it. */
  get canChangeLogo(): boolean {
    return this.authService.hasPermission('edit settings');
  }
}
