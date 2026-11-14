import { EMPTY, of, throwError } from 'rxjs';
import { ProductDetailComponent } from './product-detail.component';
import { StockMovementType } from '../../../core/models/stock-movement.model';

describe('ProductDetailComponent', () => {
  let comp: ProductDetailComponent;
  let mockStockService: { getStocks: ReturnType<typeof vi.fn> };
  let mockMovementService: { getMovements: ReturnType<typeof vi.fn> };
  let mockAuthService: { hasPermission: ReturnType<typeof vi.fn> };
  let mockProductService: Record<'getPurchaseHistory' | 'getImages' | 'uploadImages' | 'reorderImages' | 'deleteImage', ReturnType<typeof vi.fn>>;

  beforeEach(() => {
    mockStockService    = { getStocks:    vi.fn().mockReturnValue(EMPTY) };
    mockMovementService = { getMovements: vi.fn().mockReturnValue(EMPTY) };
    mockAuthService     = { hasPermission: vi.fn().mockReturnValue(false) };
    mockProductService  = {
      getPurchaseHistory: vi.fn().mockReturnValue(EMPTY),
      getImages: vi.fn().mockReturnValue(EMPTY),
      uploadImages: vi.fn(),
      reorderImages: vi.fn(),
      deleteImage: vi.fn(),
    };
    comp = new ProductDetailComponent(
      mockStockService as any,
      mockMovementService as any,
      mockAuthService as any,
      mockProductService as any,
    );
    comp.product = { id: 1, type: 'tyre', is_active: true } as any;
  });

  describe('ngOnInit', () => {
    it('calls getStocks when product type is tyre', () => {
      comp.ngOnInit();
      expect(mockStockService.getStocks).toHaveBeenCalledWith({ product_id: '1', per_page: '200' });
    });

    it('does NOT call getStocks when product type is service', () => {
      comp.product = { id: 2, type: 'service', is_active: true } as any;
      comp.ngOnInit();
      expect(mockStockService.getStocks).not.toHaveBeenCalled();
    });
  });

  describe('photos', () => {
    const img = (id: number, position: number) => ({ id, product_id: 1, url: `/storage/p${id}.jpg`, original_name: `p${id}.jpg`, size: 10, position, created_at: '' });
    const file = (name: string, type = 'image/jpeg') => new File(['x'], name, { type });

    beforeEach(() => {
      mockAuthService.hasPermission.mockImplementation((p: string) => p === 'edit products');
    });

    it('loads the photos on init', () => {
      mockProductService.getImages.mockReturnValue(of({ data: [img(1, 0)], max: 6 }));
      comp.ngOnInit();
      expect(mockProductService.getImages).toHaveBeenCalledWith(1);
      expect(comp.images().length).toBe(1);
      expect(comp.maxImages()).toBe(6);
      expect(comp.emptySlots().length).toBe(5);
    });

    it('sends accepted files one by one and refuses other formats', async () => {
      mockProductService.uploadImages
        .mockReturnValueOnce(of({ data: [img(1, 0)], max: 6 }))
        .mockReturnValueOnce(of({ data: [img(1, 0), img(2, 1)], max: 6 }));

      await comp.addFiles([file('a.jpg'), file('doc.pdf', 'application/pdf'), file('b.png', 'image/png')]);

      expect(mockProductService.uploadImages).toHaveBeenCalledTimes(2);
      expect(mockProductService.uploadImages.mock.calls[0][1][0].name).toBe('a.jpg');
      expect(comp.images().map((i) => i.id)).toEqual([1, 2]);
      expect(comp.uploadingCount()).toBe(0);
      expect(comp.imageError()).toContain('doc.pdf');
    });

    it('does not go over the maximum and keeps the server message', async () => {
      comp.maxImages.set(2);
      comp.images.set([img(1, 0)]);
      mockProductService.uploadImages.mockReturnValue(throwError(() => ({ status: 422, error: { errors: { 'images.0': ['Une photo ne doit pas dépasser 5 Mo.'] } } })));

      await comp.addFiles([file('a.jpg'), file('b.jpg')]);

      expect(mockProductService.uploadImages).toHaveBeenCalledTimes(1);
      expect(comp.imageError()).toContain('2 photos au maximum');
      expect(comp.imageError()).toContain('5 Mo');
    });

    it('does nothing without the edit permission', async () => {
      mockAuthService.hasPermission.mockReturnValue(false);
      await comp.addFiles([file('a.jpg')]);
      expect(mockProductService.uploadImages).not.toHaveBeenCalled();
    });

    it('sets the main photo by moving it first, and rolls back on error', () => {
      comp.images.set([img(1, 0), img(2, 1), img(3, 2)]);
      mockProductService.reorderImages.mockReturnValueOnce(of({ data: [img(3, 0), img(1, 1), img(2, 2)], max: 6 }));
      comp.setMainPhoto(2);
      expect(mockProductService.reorderImages).toHaveBeenCalledWith(1, [3, 1, 2]);
      expect(comp.images()[0].id).toBe(3);

      mockProductService.reorderImages.mockReturnValueOnce(throwError(() => ({ status: 500 })));
      comp.movePhoto(0, 2);
      expect(comp.images().map((i) => i.id)).toEqual([3, 1, 2]);
      expect(comp.imageError()).toContain('ordre');
    });

    it('deletes a photo after confirmation', () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true);
      comp.images.set([img(1, 0), img(2, 1)]);
      mockProductService.deleteImage.mockReturnValue(of({ data: [img(2, 0)], max: 6 }));
      comp.deletePhoto(img(1, 0));
      expect(mockProductService.deleteImage).toHaveBeenCalledWith(1);
      expect(comp.images().map((i) => i.id)).toEqual([2]);
    });

    it('steps through the viewer in a loop', () => {
      comp.images.set([img(1, 0), img(2, 1), img(3, 2)]);
      comp.openViewer(0);
      comp.stepViewer(-1);
      expect(comp.viewerIndex()).toBe(2);
      comp.onKeydown(new KeyboardEvent('keydown', { key: 'ArrowRight' }));
      expect(comp.viewerIndex()).toBe(0);
      comp.onKeydown(new KeyboardEvent('keydown', { key: 'Escape' }));
      expect(comp.viewerIndex()).toBeNull();
    });
  });

  describe('togglePurchases', () => {
    const history = { data: [{ id: 1, purchase_id: 9, unit_price: 800 }], summary: { purchases_count: 1 } };

    it('loads the purchase history once, on first opening', () => {
      mockProductService.getPurchaseHistory.mockReturnValue(of(history));
      comp.togglePurchases();
      expect(comp.showPurchases()).toBe(true);
      expect(mockProductService.getPurchaseHistory).toHaveBeenCalledWith(1);
      expect(comp.purchaseHistory()).toEqual(history);

      comp.togglePurchases();
      comp.togglePurchases();
      expect(mockProductService.getPurchaseHistory).toHaveBeenCalledTimes(1);
    });
  });

  describe('toggleMovements', () => {
    it('fetches movements and sets showMovements true on first toggle', () => {
      mockMovementService.getMovements.mockReturnValue(of({ data: [{ id: 10 }] }));
      comp.toggleMovements();
      expect(comp.showMovements()).toBe(true);
      expect(mockMovementService.getMovements).toHaveBeenCalledWith({ product_id: 1, per_page: 100 });
    });

    it('does NOT re-fetch when movements already loaded on second show', () => {
      comp.movements.set([{ id: 1 } as any]);
      comp.toggleMovements(); // show (movements exist → no fetch)
      comp.toggleMovements(); // hide
      comp.toggleMovements(); // show again (movements still exist → no fetch)
      expect(mockMovementService.getMovements).not.toHaveBeenCalled();
    });
  });

  describe('typeLabel', () => {
    it('returns French labels for all product types', () => {
      comp.product = { ...comp.product, type: 'tyre' };
      expect(comp.typeLabel()).toBe('Pneu');

      comp.product = { ...comp.product, type: 'part' };
      expect(comp.typeLabel()).toBe('Pièce');

      comp.product = { ...comp.product, type: 'service' };
      expect(comp.typeLabel()).toBe('Service');
    });
  });

  describe('movementTypeLabel', () => {
    it('maps all StockMovementType values to French strings', () => {
      const expected: Record<StockMovementType, string> = {
        AUTO_CREATE:   'Création produit',
        INITIAL:       'Création initiale',
        IMPORT:        'Import Excel',
        ADJUSTMENT:    'Ajustement',
        DELETION:      'Suppression',
        SALE_OUT:      'Vente',
        SALE_IN:       'Annulation vente',
        PURCHASE_IN:   'Achat',
        PURCHASE_OUT:  'Annulation achat',
        SERVICE_OUT:   'Service Auto',
        SERVICE_IN:    'Annulation service',
      };
      for (const [type, label] of Object.entries(expected) as [StockMovementType, string][]) {
        expect(comp.movementTypeLabel(type)).toBe(label);
      }
    });
  });

  describe('seasonLabel', () => {
    it('maps tire season keys to French labels', () => {
      comp.product = { id: 1, type: 'tyre', tyre: { tire_season: 'summer' } } as any;
      expect(comp.seasonLabel()).toBe('Été');

      comp.product = { id: 1, type: 'tyre', tyre: { tire_season: 'winter' } } as any;
      expect(comp.seasonLabel()).toBe('Hiver');

      comp.product = { id: 1, type: 'tyre', tyre: { tire_season: 'all_season' } } as any;
      expect(comp.seasonLabel()).toBe('4 Saisons');

      comp.product = { id: 1, type: 'tyre', tyre: {} } as any;
      expect(comp.seasonLabel()).toBe('-');
    });
  });
});
