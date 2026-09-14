<?php
declare(strict_types=1);

namespace AthMovil;

final readonly class Payment
{
    private array $payload;

    /**
     * Items use provider field names: name, description, quantity, price, tax, metadata.
     * Price/tax must be decimal strings; tax and metadata may be null.
     */
    public function __construct(
        Amount $total,
        string $phoneNumber,
        string $metadata1 = '',
        string $metadata2 = '',
        array $items = [],
        ?Amount $subtotal = null,
        ?Amount $tax = null,
        int $paymentTimeoutSeconds = 600,
    ) {
        if ($total->cents < 100 || $total->cents > 150000) {
            throw new \InvalidArgumentException('Payment total must be between 1.00 and 1500.00.');
        }
        if ($paymentTimeoutSeconds < 120 || $paymentTimeoutSeconds > 600) {
            throw new \InvalidArgumentException('Payment timeout must be between 120 and 600 seconds.');
        }
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('Items must be a list.');
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Each item must be an array.');
            }
            foreach (['name', 'description', 'quantity', 'price', 'tax', 'metadata'] as $key) {
                if (!array_key_exists($key, $item)) {
                    throw new \InvalidArgumentException('Item is missing a required field.');
                }
            }
            if (array_diff(array_keys($item), ['name', 'description', 'quantity', 'price', 'tax', 'metadata']) !== []) {
                throw new \InvalidArgumentException('Unknown item field.');
            }
            if (!is_string($item['name']) || trim($item['name']) === '' || !is_string($item['description'])
                || !is_int($item['quantity']) || $item['quantity'] < 1 || !is_string($item['price'])
                || ($item['tax'] !== null && !is_string($item['tax']))
                || ($item['metadata'] !== null && !is_string($item['metadata']))) {
                throw new \InvalidArgumentException('Invalid item field type or value.');
            }
            Amount::fromDecimal($item['price']);
            if ($item['tax'] !== null) { Amount::fromDecimal($item['tax']); }
            if ($item['metadata'] !== null) { Validation::text($item['metadata'], 40); }
        }
        $this->payload = [
            'env' => 'production',
            'total' => (string) $total,
            'phoneNumber' => Validation::phone($phoneNumber),
            'metadata1' => Validation::text($metadata1, 40),
            'metadata2' => Validation::text($metadata2, 40),
            'items' => $items,
            'timeout' => $paymentTimeoutSeconds,
        ];
        // Build a local array because readonly array properties cannot be modified in place.
        $optional = [];
        if ($subtotal !== null) { $optional['subtotal'] = (string) $subtotal; }
        if ($tax !== null) { $optional['tax'] = (string) $tax; }
        // See payload() for optional fields.
        $this->optional = $optional;
    }

    private array $optional;

    public function payload(): array { return $this->payload + $this->optional; }

    public function __debugInfo(): array { return ['payload' => '[REDACTED]']; }
}
