import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { WebstoreHistoryComponent } from './webstore-history.component';
import { WebstoreService } from '../../data-access/webstore.service';

const batch = (id: number, statuses: Record<string, number>) => ({
  id, status: 'superseded', margin_percent: 20, created_at: '2026-11-14T06:30:00Z', created_by_name: 'Automatique',
  counts: { create: 1, update: 2, unchanged: 0, variable_skipped: 0, no_price_skipped: 0, with_alerts: 0 }, statuses,
});

describe('WebstoreHistoryComponent', () => {
  let service: { batches: ReturnType<typeof vi.fn> };

  const create = () => {
    TestBed.configureTestingModule({ providers: [{ provide: WebstoreService, useValue: service }] });
    const comp = TestBed.runInInjectionContext(() => new WebstoreHistoryComponent());
    comp.ngOnInit();
    return comp;
  };

  beforeEach(() => {
    TestBed.resetTestingModule();
    service = { batches: vi.fn(() => of({ data: [batch(5, { published: 2, failed: 1 })], meta: { current_page: 1, last_page: 3, per_page: 20, total: 41 } })) };
  });

  it('lists the batches with their counts', () => {
    const comp = create();
    expect(service.batches).toHaveBeenCalledWith(1);
    expect(comp.rows().length).toBe(1);
    expect(comp.count(comp.rows()[0], 'published')).toBe(2);
    expect(comp.count(comp.rows()[0], 'rejected')).toBe(0);
    expect(comp.lastPage()).toBe(3);
  });

  it('pages, opens a batch and reports errors', () => {
    const comp = create();
    comp.goToPage(2);
    expect(service.batches).toHaveBeenLastCalledWith(2);

    const opened: number[] = [];
    comp.open.subscribe((id) => opened.push(id));
    comp.open.emit(5);
    expect(opened).toEqual([5]);

    service.batches.mockReturnValue(throwError(() => new Error('x')));
    comp.load();
    expect(comp.error()).toBe('Historique indisponible.');
  });
});
