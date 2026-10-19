<?php

namespace App\Requests;

use App\Core\FormRequest;
use App\Core\ValidationException;
use App\Core\Validator;

class BulkPurchaseRequest extends FormRequest {
    public function rules(): array {
        return [
            'supplier_id'    => 'required|integer|min_value:1',
            'items'          => 'required|array|min_items:1|max_items:500',
            'notes'          => 'string|max:1000',
            'driver_name'    => 'string|max:150',
            'delivery_date'  => 'date',
            'delivery_notes' => 'string|max:1000',
            'discount'         => 'numeric|min_value:0|max_value:999999999',
            'shipping_cost'    => 'numeric|min_value:0|max_value:999999999',
            'payment_type'   => 'in:cash,credit',
            'deposit'        => 'numeric|min_value:0|max_value:999999999',
            'replace_invoice_id' => 'integer|min_value:1',
        ];
    }

    public function validated(): array
    {
        $data = parent::validated();
        $data['items'] = $this->validatedItems($data['items']);
        return $data;
    }

    private function validatedItems(array $items): array
    {
        $errors = [];
        $validatedItems = [];
        $seenProductIds = [];

        foreach ($items as $index => $item) {
            $prefix = "items.{$index}";
            if (!is_array($item)) {
                $errors[$prefix][] = 'Each purchase item must be an object.';
                continue;
            }

            $itemErrors = Validator::validate($item, [
                'product_id' => 'required|integer|min_value:1',
                'quantity'   => 'required|numeric|min_value:0|max_value:9999999.999',
                'cost'       => 'required|numeric|min_value:0|max_value:99999999',
            ]);

            foreach (['quantity', 'cost'] as $numericField) {
                if (
                    isset($item[$numericField])
                    && is_numeric($item[$numericField])
                    && !is_finite((float) $item[$numericField])
                ) {
                    $itemErrors[$numericField][] = ucfirst($numericField) . ' must be a finite number.';
                }
            }
            if (
                isset($item['quantity'])
                && is_numeric($item['quantity'])
                && (float) $item['quantity'] <= 0
            ) {
                $itemErrors['quantity'][] = 'Quantity must be greater than zero.';
            }
            if (
                array_key_exists('update_cost', $item)
                && !is_bool($item['update_cost'])
                && !in_array($item['update_cost'], [0, 1], true)
            ) {
                $itemErrors['update_cost'][] = 'Update cost must be a boolean.';
            }

            if ($itemErrors !== []) {
                foreach ($itemErrors as $field => $messages) {
                    $errors["{$prefix}.{$field}"] = $messages;
                }
                continue;
            }

            $productId = (int) $item['product_id'];
            if (isset($seenProductIds[$productId])) {
                $errors["{$prefix}.product_id"][] = 'Duplicate products are not allowed.';
                continue;
            }
            $seenProductIds[$productId] = true;

            $validatedItems[] = [
                'product_id' => $productId,
                'quantity' => (float) $item['quantity'],
                'cost' => (float) $item['cost'],
                'update_cost' => (bool) ($item['update_cost'] ?? false),
            ];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validatedItems;
    }
}
