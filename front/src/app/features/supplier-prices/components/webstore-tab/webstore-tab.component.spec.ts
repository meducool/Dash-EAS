import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { of, throwError } from 'rxjs';
import { WebstoreTabComponent } from './webstore-tab.component';
import { WebstoreService } from '../../data-access/webstore.service';
import { AuthService } from '../../../../core/services/auth.service';

const summary = (over: Record<string, unknown> = {}) => ({
  site_total: 5, matched: 2, site_only: 1, no_sku: 1, duplicate: 1, pos_only: 3, variable: 0,
  store_url: 'https://boutique.example.com', last_pull_at: '2026-11-14T10:00:00+00:00', ...over,
});
const report = (over: Record<string, unknown> = {}) => ({
  data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 }, summary: summary(over),
});
const settings = (over: Record<string, unknown> = {}) => ({
  store_url: 'https://boutique.example.com', has_credentials: true, consumer_key_hint: '1234',
  margin_percent: 20, last_pull_at: null, last_pull_summary: null, ...over,
});

describe('WebstoreTabComponent', () => {
  let service: Record<'settings' | 'updateSettings' | 'test' | 'pull' | 'report' | 'status' | 'prepareNow', ReturnType<typeof vi.fn>>;
  let permissions: string[];

  const create = () => {
    TestBed.configureTestingModule({
      providers: [
        { provide: WebstoreService, useValue: service },
        { provide: AuthService, useValue: { hasPermission: (p: string) => permissions.includes(p) } },
      ],
    });
    const comp = TestBed.runInInjectionContext(() => new WebstoreTabComponent());
    comp.ngOnInit();
    return comp;
  };

  beforeEach(() => {
    TestBed.resetTestingModule();
    permissions = ['view webstore', 'manage webstore'];
    service = {
      settings: vi.fn(() => of(settings())),
      updateSettings: vi.fn(() => of(settings())),
      test: vi.fn(() => of({ ok: true, products_total: 42 })),
      pull: vi.fn(() => of(summary())),
      report: vi.fn(() => of(report())),
      status: vi.fn(() => of({ batch: null, auto: { enabled: false, last_run_at: null, last_result: null }, last_publish_at: null })),
      prepareNow: vi.fn(() => of({ status: 'ok', message: 'Lot #3 préparé.' })),
    };
  });

  it('loads the report, and the settings only for who manages the connection', () => {
    const comp = create();
    expect(service.settings).toHaveBeenCalled();
    expect(service.report).toHaveBeenCalledWith({ status: 'matched', search: '', page: 1, per_page: 50 });
    expect(comp.count('pos_only')).toBe(3);
    expect(comp.hasCatalogue()).toBe(true);
    expect(comp.marginInput()).toBe('20');

    TestBed.resetTestingModule();
    service.settings.mockClear();
    permissions = ['view webstore'];
    const viewer = create();
    expect(service.settings).not.toHaveBeenCalled();
    expect(viewer.canManage).toBe(false);
  });

  it('opens the connection form on first use', () => {
    service.settings.mockReturnValue(of(settings({ has_credentials: false, store_url: null })));
    const comp = create();
    expect(comp.showSettings()).toBe(true);
  });

  it('sends empty key / secret as null so the saved ones are kept', () => {
    const comp = create();
    comp.urlInput.set(' https://boutique.example.com ');
    comp.keyInput.set('');
    comp.secretInput.set('');
    comp.marginInput.set('18,5');
    comp.saveSettings();
    expect(service.updateSettings).toHaveBeenCalledWith({
      store_url: 'https://boutique.example.com', consumer_key: null, consumer_secret: null, margin_percent: 18.5, create_status: 'draft', auto_prepare: false,
    });
    expect(comp.notice()?.kind).toBe('ok');
  });

  it('refuses an invalid margin without calling the server', () => {
    const comp = create();
    comp.marginInput.set('abc');
    comp.saveSettings();
    expect(service.updateSettings).not.toHaveBeenCalled();
    expect(comp.notice()?.kind).toBe('error');
  });

  it('shows the server message when the connection test fails', () => {
    service.test.mockReturnValue(throwError(() => new HttpErrorResponse({
      status: 422, error: { errors: { store_url: ['Clés API refusées par le site.'] } },
    })));
    const comp = create();
    comp.testConnection();
    expect(comp.notice()).toEqual({ kind: 'error', text: 'Clés API refusées par le site.' });
    expect(comp.testing()).toBe(false);
  });

  it('reads the catalogue then reloads the report from page 1', () => {
    const comp = create();
    comp.page.set(3);
    service.report.mockClear();
    comp.pull();
    expect(service.pull).toHaveBeenCalled();
    expect(comp.notice()?.text).toContain('2 correspondant(s)');
    expect(service.report).toHaveBeenCalledWith(expect.objectContaining({ page: 1 }));
  });

  it('switches the matching status and goes back to page 1', () => {
    const comp = create();
    comp.page.set(2);
    comp.setStatus('pos_only');
    expect(comp.isPosOnly()).toBe(true);
    expect(service.report).toHaveBeenLastCalledWith({ status: 'pos_only', search: '', page: 1, per_page: 50 });
  });

  it('formats the stock of the site', () => {
    const comp = create();
    const row = { match_status: 'matched', product: null, woo: { stock_status: 'onbackorder', stock_quantity: null } } as any;
    expect(comp.wooStock(row)).toBe('Sur commande');
    row.woo = { stock_status: 'instock', stock_quantity: 4 };
    expect(comp.wooStock(row)).toBe('En stock (4)');
  });

  it('shows the count to decide and prepares a batch now', () => {
    service.status.mockReturnValue(of({
      batch: { statuses: { proposed: 4, approved: 0, rejected: 0, published: 0, failed: 0 } },
      auto: { enabled: true, last_run_at: '2026-11-14T06:30:00Z', last_result: { status: 'ok', message: 'Lot #3 préparé.' } },
      last_publish_at: null,
    }));
    const comp = create();
    expect(comp.toDecide()).toBe(4);

    comp.prepareNow();
    expect(service.prepareNow).toHaveBeenCalled();
    expect(comp.notice()).toEqual({ kind: 'ok', text: 'Lot #3 préparé.' });
    expect(comp.view()).toBe('proposals');
  });

  it('opens an older batch from the history, then goes back', () => {
    const comp = create();
    comp.openHistoryBatch(2);
    expect(comp.view()).toBe('proposals');
    expect(comp.historyBatchId()).toBe(2);
    comp.openView('history');
    expect(comp.historyBatchId()).toBeNull();
  });
});
