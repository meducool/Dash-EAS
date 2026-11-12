<?php

namespace App\Domain\Support;

/**
 * Cash-flow label of a sale / purchase / service-order payment, e.g.
 * "Paiement achat #452 | ROCAMORA | 1234567 BMCE" or "Paiement vente #87 | KARIM | ESPÈCES".
 *
 * The last part is the payment mode: for a cheque or an effet, its reference
 * (number, bank…) as typed; otherwise — or when no reference is given — the
 * method in capitals.
 */
final class PaymentDescription
{
    private const REFERENCE_METHODS = ['Chèque', 'Effet'];

    public const SEPARATOR = ' | ';

    public static function mode(?string $method, ?string $reference): string
    {
        $method = trim((string) $method);
        $reference = trim((string) $reference);

        if ($reference !== '' && in_array($method, self::REFERENCE_METHODS, true)) {
            return $reference;
        }

        return mb_strtoupper($method);
    }

    public static function forPurchase(int $purchaseId, string $supplierName, ?string $method, ?string $reference): string
    {
        return self::build("Paiement achat #{$purchaseId}", $supplierName, $method, $reference);
    }

    public static function forSale(int $saleId, string $clientName, ?string $method, ?string $reference): string
    {
        return self::build("Paiement vente #{$saleId}", $clientName, $method, $reference);
    }

    public static function forServiceOrder(int $orderId, string $clientName, ?string $method, ?string $reference): string
    {
        return self::build("Paiement service #{$orderId}", $clientName, $method, $reference);
    }

    /** Parts joined by " | " — "Paiement achat #453 | CONFORT PNEU | 441784 BMCE"; empty parts are dropped. */
    private static function build(string $prefix, string $name, ?string $method, ?string $reference): string
    {
        $parts = array_filter(
            array_map(fn (string $part) => trim(preg_replace('/\s+/u', ' ', $part)), [$prefix, $name, self::mode($method, $reference)]),
            fn (string $part) => $part !== '',
        );

        return implode(self::SEPARATOR, $parts);
    }

    /**
     * The same label with plain spaces instead of " | " — the format briefly
     * produced before the separator; only used to recognise and migrate it.
     */
    public static function withoutSeparator(string $label): string
    {
        return str_replace(self::SEPARATOR, ' ', $label);
    }
}
