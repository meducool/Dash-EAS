<?php

use App\Domain\Support\PaymentDescription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites the cash-flow label of existing single sale / purchase / service payments to
 * the current format (see PaymentDescription), pending and settled alike:
 *   "Paiement achat #452 - 8 pneus - ROCAMORA"      → "Paiement achat #452 | ROCAMORA | 1234567 BMCE"
 *   "Paiement achat #16 - 2 X HANKOOK … 235/60R18"   → "Paiement achat #16 | FOURNISSEUR | ESPÈCES"
 *   "Paiement vente #87 - 4 X MICHELIN … POUR KARIM" → "Paiement vente #87 | KARIM | 556677"
 *   "Paiement service #147 - Vidange POUR KARIM"     → "Paiement service #147 | KARIM | ESPÈCES"
 *
 * Only transactions still carrying an auto-generated text are touched:
 * grouped settlements ("Règlement client / fournisseur …") and descriptions
 * typed by hand are left alone. Data-only and irreversible — the old text is
 * not kept (deploy.sh backs the database up before migrating).
 */
return new class extends Migration
{
    // Old auto formats, plus the short-lived "… CHÈQUE N° 123" / "… EFFET N° 123"
    // labels (a cheque or effet now shows its reference alone).
    private const OLD_PURCHASE = '/^Paiement achat #\d+ (- (.* pneus - |\d+ X )|.* (CHÈQUE|EFFET) N° )/u';

    private const OLD_SALE = '/^Paiement vente #\d+ - /u';

    private const OLD_SERVICE = '/^Paiement service #\d+ - /u';

    public function up(): void
    {
        $this->rewritePurchases();
        $this->rewriteSales();
        $this->rewriteServiceOrders();
    }

    private function rewriteServiceOrders(): void
    {
        DB::table('service_payments')
            ->join('transactions', 'transactions.id', '=', 'service_payments.transaction_id')
            ->join('service_orders', 'service_orders.id', '=', 'service_payments.service_order_id')
            ->leftJoin('clients', 'clients.id', '=', 'service_orders.client_id')
            ->where('transactions.description', 'like', 'Paiement service #%')
            ->select([
                'transactions.id as transaction_id',
                'transactions.description',
                'transactions.method as transaction_method',
                'service_payments.service_order_id',
                'service_payments.method',
                'service_payments.reference',
                'clients.name as party_name',
            ])
            ->orderBy('transactions.id')
            ->chunk(500, fn ($rows) => $this->rewrite($rows, self::OLD_SERVICE, fn ($row) => PaymentDescription::forServiceOrder(
                (int) $row->service_order_id,
                (string) ($row->party_name ?? $this->clientFromOldLabel((string) $row->description)),
                $row->method ?? $row->transaction_method,
                $row->reference,
            )));
    }

    private function rewritePurchases(): void
    {
        DB::table('purchase_payments')
            ->join('transactions', 'transactions.id', '=', 'purchase_payments.transaction_id')
            ->join('purchases', 'purchases.id', '=', 'purchase_payments.purchase_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('transactions.description', 'like', 'Paiement achat #%')
            ->select([
                'transactions.id as transaction_id',
                'transactions.description',
                'transactions.person',
                'purchase_payments.purchase_id',
                'purchase_payments.method',
                'purchase_payments.reference',
                'suppliers.name as party_name',
            ])
            ->orderBy('transactions.id')
            ->chunk(500, fn ($rows) => $this->rewrite($rows, self::OLD_PURCHASE, fn ($row) => PaymentDescription::forPurchase(
                (int) $row->purchase_id,
                (string) ($row->party_name ?? $row->person ?? ''),
                $row->method,
                $row->reference,
            )));
    }

    private function rewriteSales(): void
    {
        DB::table('payments')
            ->join('transactions', 'transactions.id', '=', 'payments.transaction_id')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->leftJoin('clients as sale_client', 'sale_client.id', '=', 'sales.client_id')
            ->leftJoin('clients as payment_client', 'payment_client.id', '=', 'payments.client_id')
            ->where('transactions.description', 'like', 'Paiement vente #%')
            ->select([
                'transactions.id as transaction_id',
                'transactions.description',
                'payments.sale_id',
                'payments.method',
                'payments.reference',
                DB::raw('COALESCE(sale_client.name, payment_client.name) as party_name'),
            ])
            ->orderBy('transactions.id')
            ->chunk(500, fn ($rows) => $this->rewrite($rows, self::OLD_SALE, fn ($row) => PaymentDescription::forSale(
                (int) $row->sale_id,
                (string) ($row->party_name ?? $this->clientFromOldLabel((string) $row->description)),
                $row->method,
                $row->reference,
            )));
    }

    /** Walk-in sales have no client record: keep the name the old label carried after "POUR". */
    private function clientFromOldLabel(string $description): string
    {
        return preg_match('/ POUR (.+)$/u', $description, $m) ? trim($m[1]) : '';
    }

    private function rewrite(iterable $rows, string $oldFormat, callable $label): void
    {
        foreach ($rows as $row) {
            $description = (string) $row->description;
            $new = $label($row);

            // Old auto formats, or the current label without its " | " separators.
            $isOld = preg_match($oldFormat, $description)
                || $description === PaymentDescription::withoutSeparator($new);

            if (! $isOld || $description === $new) {
                continue;
            }

            DB::table('transactions')->where('id', $row->transaction_id)->update(['description' => $new]);
        }
    }

    public function down(): void
    {
        // Irreversible: the old labels are not stored anywhere.
    }
};
