import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { ProductFormComponent } from './product-form.component';
import { StockService } from '../../../core/services/stock.service';
import { StockMovementService } from '../../../core/services/stock-movement.service';
import { AuthService } from '../../../core/services/auth.service';
import { BrandService } from '../../brands/data-access/brand.service';
import { ProductService } from '../data-access/product.service';

describe('ProductFormComponent — part description copied into Nom / Libellé', () => {
  let comp: ProductFormComponent;

  beforeEach(() => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: StockService, useValue: { getStocks: () => of({ data: [] }) } },
        { provide: StockMovementService, useValue: { getMovements: () => of({ data: [] }) } },
        { provide: AuthService, useValue: { hasPermission: () => true, hasRole: () => false } },
        { provide: BrandService, useValue: { getProfiles: () => of([]) } },
        { provide: ProductService, useValue: { previewReference: () => of({ reference: '' }) } },
      ],
    });
    comp = TestBed.runInInjectionContext(
      () => new ProductFormComponent(TestBed.inject(StockService), TestBed.inject(StockMovementService), TestBed.inject(AuthService)),
    );
    comp.formData.type = 'part';
  });

  const type = (text: string) => comp.onDescriptionInput(text);

  it('copies the whole description into the name, and nothing else', () => {
    type('FILTRE GAZOIL  W55824');
    expect(comp.formData.profile).toBe('FILTRE GAZOIL W55824');
    expect(comp.formData.part_category).toBeNull();
    expect(comp.formData.oem_reference).toBe('');
    expect(comp.formData.compatibility).toBe('');
    expect(comp.formData.brand_id).toBeNull();
    expect(comp.autofillNote()).toBe('Copié automatiquement dans Nom / Libellé.');

    type('FILTRE GAZOIL W55824 CLIO 4');
    expect(comp.formData.profile).toBe('FILTRE GAZOIL W55824 CLIO 4');
  });

  it('never overwrites a name changed by hand', () => {
    type('FILTRE GAZOIL W55824');
    comp.formData.profile = 'Filtre carburant';
    type('FILTRE A HUILE');
    expect(comp.formData.profile).toBe('Filtre carburant');
  });

  it('empties the copied name with the description', () => {
    type('FILTRE GAZOIL');
    type('');
    expect(comp.formData.profile).toBe('');
    expect(comp.autofillNote()).toBe('');
  });

  it('copies nothing for a tyre', () => {
    comp.formData.type = 'tyre';
    type('MICHELIN 215/45R16 90V PRIMACY 3');
    expect(comp.formData.profile).toBe('');
  });

  it('sends no reference for a new part: the server generates it', () => {
    const sent: any[] = [];
    comp.save.subscribe((p: any) => sent.push(p));
    comp.formData.reference = 'TAPEE';
    type('FILTRE GAZOIL W55824');
    comp.onSubmit();
    expect(sent[0].reference).toBeNull();
    expect(sent[0].profile).toBe('FILTRE GAZOIL W55824');
  });

  it('shows the generated reference, or "P + numéro" before the id exists', () => {
    comp.referencePreview.set('FG-W55824');
    expect(comp.generatedReference).toBe('FG-W55824');
    comp.referencePreview.set('PXXXXXXX');
    expect(comp.generatedReference).toBe("P + numéro (attribué à l'enregistrement)");
  });

  it('asks before creating a tyre that already exists, and stops when refused', () => {
    const sent: any[] = [];
    comp.save.subscribe((p: any) => sent.push(p));
    comp.formData.type = 'tyre';
    comp.existingTyres.set([{ id: 9, reference: 'C175651482T-EC6', profile: 'EcoContact 6', is_active: true, stock: 8 }]);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);

    comp.onSubmit();
    expect(confirm.mock.calls[0][0]).toContain('C175651482T-EC6');
    expect(sent.length).toBe(0);

    comp.onSubmit();
    expect(sent.length).toBe(1);
  });
});
