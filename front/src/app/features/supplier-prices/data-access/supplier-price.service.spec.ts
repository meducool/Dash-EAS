import { of } from 'rxjs';
import { HttpParams } from '@angular/common/http';
import { SupplierPriceService } from './supplier-price.service';

describe('SupplierPriceService', () => {
  let svc: SupplierPriceService;
  let http: { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn>; delete: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    svc = Object.create(SupplierPriceService.prototype);
    http = { get: vi.fn().mockReturnValue(of({})), post: vi.fn().mockReturnValue(of({})), delete: vi.fn().mockReturnValue(of(null)) };
    (svc as any).http = http;
    (svc as any).apiUrl = '/api';
  });

  it('drops empty filters from the search', () => {
    svc.search({ search: '2055516', brand_id: '', supplier_id: '3' });

    const [url, options] = http.get.mock.calls[0];
    const params = options.params as HttpParams;
    expect(url).toBe('/api/supplier-prices');
    expect(params.get('search')).toBe('2055516');
    expect(params.get('supplier_id')).toBe('3');
    expect(params.has('brand_id')).toBe(false);
  });

  it('sends the file, booleans as 1/0 and the column mapping as multipart fields', () => {
    const file = new File(['x'], 'tarif.xlsx');
    svc.createFile({ supplier_id: 4, name: 'Tarif', source_type: 'upload', auto_refresh: false, column_mapping: { price: 'C', designation: 'A', brand: '' } }, file);

    const [url, body] = http.post.mock.calls[0];
    const data = body as FormData;
    expect(url).toBe('/api/supplier-price-files');
    expect(data.get('supplier_id')).toBe('4');
    expect(data.get('auto_refresh')).toBe('0');
    expect(data.get('column_mapping[price]')).toBe('C');
    expect(data.get('column_mapping[designation]')).toBe('A');
    expect(data.has('column_mapping[brand]')).toBe(false);
    expect(data.has('column_mapping')).toBe(false);
    expect(data.get('file')).toBeInstanceOf(File);
  });

  it('sends an empty margin to go back to the default one, and saves the default margin with PUT', () => {
    (http as any).put = vi.fn().mockReturnValue(of({}));
    svc.updateFile(3, { margin_percent: null });
    svc.updateFile(3, { name: 'X' });
    svc.updateSettings(12.5);

    expect((http.post.mock.calls[0][1] as FormData).get('margin_percent')).toBe('');
    expect((http.post.mock.calls[1][1] as FormData).has('margin_percent')).toBe(false);
    expect((http as any).put).toHaveBeenCalledWith('/api/supplier-prices-settings', { default_margin: 12.5 });
  });

  it('updates with POST (a replacement file may be sent) and creates the product from a line', () => {
    svc.updateFile(7, { is_active: true });
    svc.productFor(12);

    expect(http.post.mock.calls[0][0]).toBe('/api/supplier-price-files/7');
    expect((http.post.mock.calls[0][1] as FormData).get('is_active')).toBe('1');
    expect(http.post.mock.calls[1][0]).toBe('/api/supplier-prices/12/product');
  });
});
