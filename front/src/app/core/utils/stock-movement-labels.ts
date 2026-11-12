/** French label and colour of a stock movement type, shared by every movement list. */
export const STOCK_MOVEMENT_LABELS: Record<string, string> = {
  AUTO_CREATE: 'Création produit',
  INITIAL: 'Création initiale',
  IMPORT: 'Import Excel',
  ADJUSTMENT: 'Ajustement',
  DELETION: 'Suppression',
  SALE_OUT: 'Vente',
  SALE_IN: 'Annulation vente',
  PURCHASE_IN: 'Achat',
  PURCHASE_OUT: 'Annulation achat',
  SERVICE_OUT: 'Service Auto',
  SERVICE_IN: 'Annulation service',
};

export const STOCK_MOVEMENT_CLASSES: Record<string, string> = {
  AUTO_CREATE: 'mv-grey',
  INITIAL: 'mv-grey',
  IMPORT: 'mv-blue',
  ADJUSTMENT: 'mv-orange',
  DELETION: 'mv-dark-red',
  SALE_OUT: 'mv-red',
  SALE_IN: 'mv-green',
  PURCHASE_IN: 'mv-green',
  PURCHASE_OUT: 'mv-red',
  SERVICE_OUT: 'mv-red',
  SERVICE_IN: 'mv-green',
};

export function stockMovementLabel(type: string | null | undefined): string {
  return (type && STOCK_MOVEMENT_LABELS[type]) || type || '-';
}

export function stockMovementClass(type: string | null | undefined): string {
  return (type && STOCK_MOVEMENT_CLASSES[type]) || 'mv-grey';
}
