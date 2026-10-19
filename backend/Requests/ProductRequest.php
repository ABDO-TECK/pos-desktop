<?php

namespace App\Requests;

use App\Core\FormRequest;
use App\Core\ValidationException;
use App\Core\Validator;


class ProductRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'                => 'required|string|max:200',
            'price'               => 'required|numeric|min_value:0|max_value:99999999',
            'barcode'             => 'string|max:100',
            'box_barcode'         => 'string|max:100',
            'cost'                => 'numeric|min_value:0|max_value:99999999',
            'quantity'            => 'numeric|min_value:0|max_value:9999999.999',
            'low_stock_threshold' => 'numeric|min_value:0|max_value:999999999',
            'category_id'         => 'integer|min_value:1',
            'units_per_box'       => 'integer|min_value:1|max_value:1000000',
            'sell_by_weight'      => 'integer',
            'additional_barcodes' => 'array|max_items:20',
            'unit_type'           => 'in:piece,weight,liter',
            'parent_product_id'   => 'integer|min_value:1',
            'size_name'           => 'string|max:100',
            'sizes'               => 'array|max_items:100',
        ];
    }

    public function validated(): array
    {
        $data = parent::validated();
        $errors = [];

        if (array_key_exists('additional_barcodes', $data)) {
            foreach ($data['additional_barcodes'] as $index => $barcode) {
                $field = "additional_barcodes.{$index}";
                if (!is_string($barcode) && !is_numeric($barcode)) {
                    $errors[$field][] = 'Each additional barcode must be text.';
                    continue;
                }
                if (mb_strlen(trim((string) $barcode), 'UTF-8') > 100) {
                    $errors[$field][] = 'Barcodes cannot exceed 100 characters.';
                }
            }
        }

        if (array_key_exists('sizes', $data)) {
            foreach ($data['sizes'] as $index => $size) {
                $prefix = "sizes.{$index}";
                if (!is_array($size)) {
                    $errors[$prefix][] = 'Each size must be an object.';
                    continue;
                }

                $sizeErrors = Validator::validate($size, [
                    'size_name'           => 'required|string|max:100',
                    'price'               => 'required|numeric|min_value:0|max_value:99999999',
                    'cost'                => 'numeric|min_value:0|max_value:99999999',
                    'quantity'            => 'numeric|min_value:0|max_value:9999999.999',
                    'low_stock_threshold' => 'numeric|min_value:0|max_value:999999999',
                    'barcode'             => 'string|max:100',
                    'id'                  => 'integer|min_value:1',
                ]);

                if (isset($size['size_name']) && is_string($size['size_name']) && trim($size['size_name']) === '') {
                    $sizeErrors['size_name'][] = 'Size name is required.';
                }

                foreach (['price', 'cost', 'quantity', 'low_stock_threshold'] as $field) {
                    if (isset($size[$field]) && is_numeric($size[$field]) && !is_finite((float) $size[$field])) {
                        $sizeErrors[$field][] = "Size {$field} must be a finite number.";
                    }
                }

                if (isset($size['barcode']) && !is_string($size['barcode'])) {
                    $sizeErrors['barcode'][] = 'Size barcode must be text.';
                }

                foreach ($sizeErrors as $field => $messages) {
                    $errors["{$prefix}.{$field}"] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if (array_key_exists('cost', $data)) {
            $data['cost'] = ($data['cost'] === '' || $data['cost'] === null) ? 0.0 : (float) $data['cost'];
        }
        if (array_key_exists('quantity', $data)) {
            $data['quantity'] = ($data['quantity'] === '' || $data['quantity'] === null) ? 0.0 : (float) $data['quantity'];
        }
        if (array_key_exists('low_stock_threshold', $data)) {
            $data['low_stock_threshold'] = ($data['low_stock_threshold'] === '' || $data['low_stock_threshold'] === null)
                ? (defined('LOW_STOCK_THRESHOLD') ? LOW_STOCK_THRESHOLD : 5)
                : (int) $data['low_stock_threshold'];
        }

        return $data;
    }
}
