<?php

namespace App\Requests;

use App\Core\FormRequest;
use App\Core\ValidationException;
use App\Core\Validator;

class SaleRequest extends FormRequest {
    public function rules(): array {
        return [
            'idempotency_key' => 'required|string|uuid_v4',
            'items'          => 'required|array|min_items:1|max_items:500',
            'payment_method' => 'required|in:cash,card,vodafone_cash,instapay,other_wallet,credit',
            'discount'       => 'numeric|min_value:0|max_value:99999999.99',
            'amount_paid'    => 'numeric|min_value:0|max_value:99999999.99',
            'customer_id'    => 'integer',
            'new_customer'   => 'array',
            'invoice_id'     => 'integer',
            'status'         => 'in:completed,reserved',
            'driver_name'    => 'string|max:150',
            'vehicle_number' => 'string|max:100',
            'shipping_cost'  => 'numeric|min_value:0|max_value:99999999',
            'delivery_date'  => 'date',
            'delivery_notes' => 'string|max:1000',
        ];
    }

    public function validated(): array
    {
        $data = parent::validated();
        $data['items'] = $this->validatedItems($data['items']);

        if (!array_key_exists('new_customer', $data)) {
            return $data;
        }

        if (isset($data['customer_id'])) {
            throw new ValidationException([
                'customer' => ['Choose either an existing customer or a new customer, not both.'],
            ]);
        }

        $newCustomer = $data['new_customer'];
        $errors = Validator::validate($newCustomer, [
            'name'    => 'required|string|max:200',
            'phone'   => 'string|max:30',
            'address' => 'string|max:1000',
        ]);

        if (isset($newCustomer['name']) && is_string($newCustomer['name']) && trim($newCustomer['name']) === '') {
            $errors['name'][] = 'Customer name is required.';
        }

        if ($errors !== []) {
            $nestedErrors = [];
            foreach ($errors as $field => $messages) {
                $nestedErrors["new_customer.{$field}"] = $messages;
            }
            throw new ValidationException($nestedErrors);
        }

        $data['new_customer'] = [
            'name' => trim($newCustomer['name']),
            'phone' => isset($newCustomer['phone']) && trim($newCustomer['phone']) !== ''
                ? trim($newCustomer['phone'])
                : null,
            'address' => isset($newCustomer['address']) && trim($newCustomer['address']) !== ''
                ? trim($newCustomer['address'])
                : null,
        ];

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
                $errors[$prefix][] = 'Each sale item must be an object.';
                continue;
            }

            $itemErrors = Validator::validate($item, [
                'product_id' => 'required|integer|min_value:1',
                'quantity'   => 'required|numeric|min_value:0|max_value:9999999.999',
                'price'      => 'numeric|min_value:0|max_value:99999999',
            ]);

            if (
                isset($item['quantity'])
                && is_numeric($item['quantity'])
                && (!is_finite((float) $item['quantity']) || (float) $item['quantity'] <= 0)
            ) {
                $itemErrors['quantity'][] = 'Quantity must be a finite number greater than zero.';
            }
            if (
                isset($item['price'])
                && is_numeric($item['price'])
                && !is_finite((float) $item['price'])
            ) {
                $itemErrors['price'][] = 'Price must be a finite number.';
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

            $validatedItem = [
                'product_id' => $productId,
                'quantity' => (float) $item['quantity'],
            ];
            if (array_key_exists('price', $item) && $item['price'] !== null && $item['price'] !== '') {
                $validatedItem['price'] = (float) $item['price'];
            }
            $validatedItems[] = $validatedItem;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validatedItems;
    }
}
