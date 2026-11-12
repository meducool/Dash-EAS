import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { AuthService } from '../../core/services/auth.service';
import { DepotService } from '../../core/services/depot.service';
import { DepotSelectComponent } from './depot-select.component';

describe('DepotSelectComponent', () => {
  const depotServiceStub = {
    getDepots: vi.fn(),
    createDepot: vi.fn(),
  };
  const authStub = { hasPermission: vi.fn().mockReturnValue(true) };

  function create(): DepotSelectComponent {
    TestBed.configureTestingModule({
      imports: [DepotSelectComponent],
      providers: [
        { provide: DepotService, useValue: depotServiceStub },
        { provide: AuthService, useValue: authStub },
      ],
    });
    return TestBed.createComponent(DepotSelectComponent).componentInstance;
  }

  beforeEach(() => {
    TestBed.resetTestingModule();
    depotServiceStub.getDepots.mockReset().mockReturnValue(of([
      { id: 1, name: 'ATELIER', stocks_count: 3 },
      { id: 2, name: 'Dépôt central', stocks_count: 0 },
    ]));
    depotServiceStub.createDepot.mockReset();
  });

  it('keeps a value missing from the list selectable', () => {
    const comp = create();
    comp.writeValue('Ancien dépôt');
    expect(comp.options()).toEqual(['Ancien dépôt', 'ATELIER', 'Dépôt central']);

    comp.writeValue('atelier'); // same depot, other case: not duplicated
    expect(comp.options()).toEqual(['ATELIER', 'Dépôt central']);
  });

  it('emits the chosen depot name', () => {
    const comp = create();
    const changed = vi.fn();
    comp.registerOnChange(changed);

    comp.onSelect('ATELIER');

    expect(changed).toHaveBeenCalledWith('ATELIER');
    expect(comp.value()).toBe('ATELIER');
  });

  it('creates a new depot on the spot and selects it', () => {
    depotServiceStub.createDepot.mockReturnValue(of({ id: 3, name: 'Magasin', stocks_count: 0 }));
    const comp = create();
    const changed = vi.fn();
    comp.registerOnChange(changed);

    comp.onSelect('__new__');
    expect(comp.creating()).toBe(true);
    comp.newName.set('  Magasin ');
    comp.confirmCreate();

    expect(depotServiceStub.createDepot).toHaveBeenCalledWith('Magasin');
    expect(changed).toHaveBeenCalledWith('Magasin');
    expect(comp.creating()).toBe(false);
    expect(comp.options()).toContain('Magasin');
  });

  it('reuses an existing depot instead of creating a duplicate', () => {
    const comp = create();
    comp.onSelect('__new__');
    comp.newName.set('atelier');
    comp.confirmCreate();

    expect(depotServiceStub.createDepot).not.toHaveBeenCalled();
    expect(comp.value()).toBe('ATELIER');
  });

  it('falls back to a text field when the list cannot be loaded', () => {
    depotServiceStub.getDepots.mockReturnValue(throwError(() => new Error('403')));
    const comp = create();
    expect(comp.loadFailed()).toBe(true);
  });
});
