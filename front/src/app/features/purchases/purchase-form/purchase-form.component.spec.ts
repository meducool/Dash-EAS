import { TestBed } from '@angular/core/testing';
import { provideZonelessChangeDetection } from '@angular/core';
import { of, throwError } from 'rxjs';

import { PurchaseFormComponent } from './purchase-form.component';
import { PurchaseService } from '../../../core/services/purchase.service';
import { ProductService } from '../../../core/services/product.service';
import { SupplierService } from '../../suppliers/data-access/supplier.service';
import { StockService } from '../../../core/services/stock.service';
import { AuthService } from '../../../core/services/auth.service';

const purchaseServiceStub = {};
const productServiceStub = {
  getProducts: () => of({ data: [] }),
  getFilters: vi.fn(),
  createProduct: vi.fn(),
};
const supplierServiceStub = { getSuppliers: () => of({ data: [] }) };
const stockServiceStub = {
  getStocks: () => of({ data: [] }),
  getFilters: vi.fn(),
  createStock: vi.fn(),
};
const authServiceStub = { hasPermission: vi.fn() };

describe('PurchaseFormComponent', () => {
  let comp: PurchaseFormComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [PurchaseFormComponent],
      providers: [
        provideZonelessChangeDetection(),
        { provide: PurchaseService, useValue: purchaseServiceStub },
        { provide: ProductService, useValue: productServiceStub },
        { provide: SupplierService, useValue: supplierServiceStub },
        { provide: StockService, useValue: stockServiceStub },
        { provide: AuthService, useValue: authServiceStub },
      ],
    }).compileComponents();

    stockServiceStub.getFilters.mockReset().mockReturnValue(of({ brands: [], depots: ['Dépôt central'], zones: [], countries: [] }));
    stockServiceStub.createStock.mockReset();
    productServiceStub.getFilters.mockReset().mockReturnValue(of({ brands: [{ id: 3, name: 'Marque A' }] }));
    productServiceStub.createProduct.mockReset();
    authServiceStub.hasPermission.mockReset().mockReturnValue(true);

    comp = TestBed.createComponent(PurchaseFormComponent).componentInstance;
  });

  describe('quick product creation', () => {
    const payload = { type: 'tyre', reference: 'NEW-001', profile: 'Primacy 4' } as any;
    const created = { id: 42, type: 'tyre', reference: 'NEW-001', profile: 'Primacy 4' } as any;

    it('is offered only with the "create products" permission', () => {
      authServiceStub.hasPermission.mockImplementation((p: string) => p !== 'create products');
      expect(comp.canCreateProduct).toBe(false);

      authServiceStub.hasPermission.mockReturnValue(true);
      expect(comp.canCreateProduct).toBe(true);
    });

    it('loads the brand list once, on first opening', () => {
      comp.openProductCreate();
      comp.closeProductCreate();
      comp.openProductCreate();

      expect(comp.productBrands()).toEqual([{ id: 3, name: 'Marque A' }]);
      expect(productServiceStub.getFilters).toHaveBeenCalledTimes(1);
    });

    it('selects the new product on the line and chains to the stock panel', () => {
      productServiceStub.createProduct.mockReturnValue(of(created));
      comp.currentItem.stock_id = 5;
      comp.stocks.set([{ id: 5, quantity: 2 } as any]);
      comp.openProductCreate();

      comp.onProductCreated(payload);

      expect(productServiceStub.createProduct).toHaveBeenCalledWith(payload);
      expect(comp.currentItem.product_id).toBe(42);
      expect(comp.products()[0]).toEqual(created);
      // The previous product's lot must not stay attached to the new product.
      expect(comp.currentItem.stock_id).toBeNull();
      expect(comp.stocks()).toEqual([]);
      expect(comp.showProductCreate()).toBe(false);
      expect(comp.showStockCreate()).toBe(false);
    });

    it('selects the stock lot created with the product instead of asking for another one', () => {
      productServiceStub.createProduct.mockReturnValue(of(created));
      const lot = { id: 77, product_id: 42, quantity: 0, depot: 'ATELIER', zone: 'A1' } as any;
      const original = stockServiceStub.getStocks;
      stockServiceStub.getStocks = () => of({ data: [lot] }) as any;
      try {
        comp.onProductCreated(payload);
      } finally {
        stockServiceStub.getStocks = original;
      }

      expect(comp.currentItem.stock_id).toBe(77);
      expect(comp.stocks()).toEqual([lot]);
      expect(comp.showStockCreate()).toBe(false);
    });

    it('loads no lot for a service', () => {
      productServiceStub.createProduct.mockReturnValue(of({ ...created, type: 'service' }));
      comp.onProductCreated(payload);
      expect(comp.showStockCreate()).toBe(false);
      expect(comp.currentItem.stock_id).toBeNull();
    });

    it('keeps the window open and shows the server message when creation fails', () => {
      productServiceStub.createProduct.mockReturnValue(
        throwError(() => ({ error: { message: 'Invalide', errors: { reference: ['Cette référence existe déjà.'] } } })),
      );
      comp.openProductCreate();

      comp.onProductCreated(payload);

      expect(comp.productCreateError()).toBe('Cette référence existe déjà.');
      expect(comp.showProductCreate()).toBe(true);
      expect(comp.creatingProduct()).toBe(false);
      expect(comp.currentItem.product_id).toBe(0);
    });
  });

  describe('quick stock creation', () => {
    it('is offered only with the "create stock" permission', () => {
      expect(comp.canCreateStock).toBe(true);
      expect(authServiceStub.hasPermission).toHaveBeenCalledWith('create stock');

      authServiceStub.hasPermission.mockReturnValue(false);
      expect(comp.canCreateStock).toBe(false);
    });

    it('does not open until a product is chosen', () => {
      comp.openStockCreate();
      expect(comp.showStockCreate()).toBe(false);

      comp.currentItem.product_id = 7;
      comp.openStockCreate();
      expect(comp.showStockCreate()).toBe(true);
      expect(comp.stockOptions().depots).toEqual(['Dépôt central']);
    });

    it('creates the lot empty, then selects it on the current line', () => {
      const created = { id: 99, product_id: 7, quantity: 0, depot: 'Dépôt central', zone: null, dot: '2426', made_in: null };
      stockServiceStub.createStock.mockReturnValue(of(created));
      comp.currentItem.product_id = 7;
      comp.currentItem.unit_price = 650;
      comp.openStockCreate();
      comp.newStock = { depot: '  Dépôt central ', zone: '', dot: '2426', made_in: '' };

      comp.createStock();

      // quantity 0: saving the purchase is what adds the bought quantity.
      expect(stockServiceStub.createStock).toHaveBeenCalledWith({
        product_id: 7, quantity: 0, purchase_price: 650, depot: 'Dépôt central', zone: null, dot: '2426', made_in: null,
      });
      expect(comp.currentItem.stock_id).toBe(99);
      expect(comp.stocks()[0]).toEqual(created);
      expect(comp.showStockCreate()).toBe(false);
    });

    it('keeps the panel open and shows the server message when creation fails', () => {
      stockServiceStub.createStock.mockReturnValue(
        throwError(() => ({ error: { message: 'Invalide', errors: { product_id: ["Les services ne gèrent pas d'inventaire."] } } })),
      );
      comp.currentItem.product_id = 7;
      comp.openStockCreate();

      comp.createStock();

      expect(comp.stockCreateError()).toBe("Les services ne gèrent pas d'inventaire.");
      expect(comp.showStockCreate()).toBe(true);
      expect(comp.creatingStock()).toBe(false);
      expect(comp.currentItem.stock_id).toBeNull();
    });

    it('closes when another product is picked', () => {
      comp.currentItem.product_id = 7;
      comp.openStockCreate();

      comp.onProductSelected({ target: { value: '8' } } as unknown as Event);

      expect(comp.showStockCreate()).toBe(false);
    });
  });

  describe('totals', () => {
    beforeEach(() => {
      comp.formData.items = [
        { product_id: 1, stock_id: 1, quantity: 4, unit_price: 500 },
        { product_id: 2, stock_id: 2, quantity: 2, unit_price: 250 },
      ] as any;
    });

    it('sums quantities and amounts over the lines', () => {
      expect(comp.totalQuantity).toBe(6);
      expect(comp.totalAmount).toBe(2500);
    });

    it('applies the global discount to the net amount', () => {
      comp.formData.discount = 10;
      expect(comp.discountAmount).toBe(250);
      expect(comp.netAmount).toBe(2250);
    });
  });

  describe('noStockAvailable', () => {
    it('is false until a product is chosen', () => {
      expect(comp.noStockAvailable).toBe(false);
    });

    it('flags a chosen product that has no stock lot, but not while lots are loading', () => {
      comp.currentItem.product_id = 7;
      expect(comp.noStockAvailable).toBe(true);

      comp.loadingStocks.set(true);
      expect(comp.noStockAvailable).toBe(false);

      comp.loadingStocks.set(false);
      comp.stocks.set([{ id: 1, quantity: 3 } as any]);
      expect(comp.noStockAvailable).toBe(false);
    });
  });

  describe('statusOptions', () => {
    it('defaults to EN COURS transitions when formData.status is unset', () => {
      expect(comp.statusOptions).toEqual(['EN COURS', 'RECU', 'ANNULE']);
    });

    it('lists RECU transitions with RECU kept first', () => {
      comp.formData.status = 'RECU';
      expect(comp.statusOptions).toEqual(['RECU', 'EN COURS', 'TERMINE']);
    });

    it('treats ANNULE as a dead end: only EN COURS is offered', () => {
      comp.formData.status = 'ANNULE';
      expect(comp.statusOptions).toEqual(['ANNULE', 'EN COURS']);
    });

    it('restricts TERMINE to going back to RECU only', () => {
      comp.formData.status = 'TERMINE';
      expect(comp.statusOptions).toEqual(['TERMINE', 'RECU']);
    });
  });
});
