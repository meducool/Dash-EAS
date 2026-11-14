import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { of, throwError } from 'rxjs';
import { WebstoreProposalsComponent } from './webstore-proposals.component';
import { WebstoreService } from '../../data-access/webstore.service';
import { AuthService } from '../../../../core/services/auth.service';

const batch = (statuses: Record<string, number> = {}) => ({
  id: 7, margin_percent: 20, catalogue_read_at: null, created_at: '2026-11-14T10:00:00Z', created_by_name: 'Admin',
  counts: { create: 1, update: 2, unchanged: 4, variable_skipped: 0, no_price_skipped: 1, with_alerts: 1 },
  statuses: { proposed: 3, approved: 0, rejected: 0, published: 0, failed: 0, ...statuses },
});
const change = (id: number, over: Record<string, unknown> = {}) => ({
  id, action: 'update', status: 'proposed', fields: ['price'], alerts: [], current: { price: 900 }, proposed: { price: 960 },
  price_override: null, final_price: 960, decided_by_name: null, decided_at: null, product: null, woo: null, ...over,
});
const page = (rows: unknown[], b = batch()) => ({ data: rows, meta: { current_page: 1, last_page: 1, per_page: 50, total: rows.length }, batch: b });

describe('WebstoreProposalsComponent', () => {
  let service: Record<'changes' | 'generate' | 'decide' | 'setPrice' | 'publish', ReturnType<typeof vi.fn>>;
  let permissions: string[];

  const create = () => {
    TestBed.configureTestingModule({
      providers: [
        { provide: WebstoreService, useValue: service },
        { provide: AuthService, useValue: { hasPermission: (p: string) => permissions.includes(p) } },
      ],
    });
    const comp = TestBed.runInInjectionContext(() => new WebstoreProposalsComponent());
    comp.ngOnInit();
    return comp;
  };

  beforeEach(() => {
    TestBed.resetTestingModule();
    permissions = ['view webstore', 'manage webstore', 'approve webstore'];
    service = {
      changes: vi.fn(() => of(page([change(1), change(2)]))),
      generate: vi.fn(() => of({ batch: batch() })),
      decide: vi.fn(() => of({ updated: 2, batch: batch() })),
      setPrice: vi.fn(() => of({ id: 1, status: 'proposed', price_override: 999, final_price: 999, alerts: ['manual_price'] })),
      publish: vi.fn(() => of({ published: 2, failed: 0, warnings: 0, stopped: null, batch: batch() })),
    };
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });

  it('loads the undecided lines of the open batch', () => {
    const comp = create();
    expect(service.changes).toHaveBeenCalledWith({ status: 'proposed', action: '', alert: '', search: '', page: 1, per_page: 50, batch_id: null });
    expect(comp.rows().length).toBe(2);
    expect(comp.batch()?.id).toBe(7);
  });

  it('selects lines and approves the selection', () => {
    const comp = create();
    comp.toggleAll();
    expect(comp.selectedCount()).toBe(2);
    comp.toggle(1);
    expect(comp.isSelected(1)).toBe(false);

    comp.decide('approve');
    expect(service.decide).toHaveBeenCalledWith('approve', [2], 'ids');
    expect(comp.notice()?.text).toContain('approuvée');
    expect(comp.selectedCount()).toBe(0); // reloaded
  });

  it('approves everything without alert after confirmation', () => {
    const comp = create();
    comp.approveAllWithoutAlert();
    expect(service.decide).toHaveBeenCalledWith('approve', [], 'no_alerts');
  });

  it('asks before replacing a batch that still has lines', () => {
    const comp = create();
    (window.confirm as any).mockReturnValueOnce(false);
    comp.generate();
    expect(service.generate).not.toHaveBeenCalled();

    comp.generate();
    expect(service.generate).toHaveBeenCalled();
    expect(comp.notice()?.text).toContain('2 mise(s) à jour et 1 création(s)');
  });

  it('saves a typed price, and refuses an invalid one', () => {
    const comp = create();
    const row = comp.rows()[0];
    comp.startPrice(row);
    expect(comp.priceInput()).toBe('960');

    comp.priceInput.set('abc');
    comp.savePrice(row);
    expect(service.setPrice).not.toHaveBeenCalled();

    comp.priceInput.set('999,5');
    comp.savePrice(row);
    expect(service.setPrice).toHaveBeenCalledWith(1, 999.5);
    expect(comp.editingId()).toBeNull();

    comp.savePrice(row, true);
    expect(service.setPrice).toHaveBeenLastCalledWith(1, null);
  });

  it('shows the price change against the site', () => {
    const comp = create();
    expect(comp.priceDelta(comp.rows()[0])).toBeCloseTo(6.67, 1);
    expect(comp.priceDelta(change(3, { action: 'create', current: { price: null } }) as any)).toBeNull();
  });

  it('hides the decisions from who cannot approve', () => {
    permissions = ['view webstore', 'manage webstore'];
    const comp = create();
    expect(comp.canApprove()).toBe(false);
    expect(comp.canDecide(comp.rows()[0])).toBe(false);
  });

  it('shows the server message on failure', () => {
    service.decide.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 403 })));
    const comp = create();
    comp.decide('reject', [1]);
    expect(comp.notice()).toEqual({ kind: 'error', text: 'Action réservée à l\'administrateur.' });
  });

  it('publishes the approved lines after confirmation, then shows them', () => {
    service.changes.mockReturnValue(of(page([change(1, { status: 'approved' })], batch({ proposed: 0, approved: 1 }))));
    const comp = create();
    (window.confirm as any).mockReturnValueOnce(false);
    comp.publish();
    expect(service.publish).not.toHaveBeenCalled();

    comp.publish();
    expect(service.publish).toHaveBeenCalled();
    expect(comp.notice()).toEqual({ kind: 'ok', text: '2 produit(s) publié(s).' });
    expect(comp.status()).toBe('published');
  });

  it('goes to the failures and explains a stopped run', () => {
    service.changes.mockReturnValue(of(page([], batch({ approved: 3 }))));
    service.publish.mockReturnValue(of({ published: 1, failed: 2, warnings: 1, stopped: 'Écriture refusée par le site.', batch: batch() }));
    const comp = create();
    comp.publish();
    expect(comp.notice()?.kind).toBe('error');
    expect(comp.notice()?.text).toContain('2 échec(s)');
    expect(comp.notice()?.text).toContain('arrêtée : Écriture refusée');
    expect(comp.status()).toBe('failed');
  });

  it('does nothing without approved lines, and lets a failed line be decided again', () => {
    const comp = create();
    comp.publish();
    expect(service.publish).not.toHaveBeenCalled();
    expect(comp.canDecide(change(9, { status: 'failed' }) as any)).toBe(true);
    expect(comp.canDecide(change(9, { status: 'published' }) as any)).toBe(false);
  });

  it('reads an older batch without any decision', () => {
    service.changes.mockReturnValue(of(page([change(1)], { ...batch(), status: 'superseded' } as any)));
    TestBed.configureTestingModule({
      providers: [
        { provide: WebstoreService, useValue: service },
        { provide: AuthService, useValue: { hasPermission: () => true } },
      ],
    });
    const comp = TestBed.runInInjectionContext(() => new WebstoreProposalsComponent());
    comp.batchId = 4;
    comp.ngOnInit();
    expect(service.changes).toHaveBeenCalledWith(expect.objectContaining({ batch_id: 4 }));
    expect(comp.readOnly()).toBe(true);
    expect(comp.canApprove()).toBe(false);
    expect(comp.canGenerate()).toBe(false);
    expect(comp.canDecide(comp.rows()[0])).toBe(false);
  });
});
