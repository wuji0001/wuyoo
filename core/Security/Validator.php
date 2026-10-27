<?php

declare(strict_types=1);

namespace Wuyoo\Security;

use InvalidArgumentException;
use Wuyoo\Http\HttpException;

final class Validator
{
    /** Only validated fields are returned; min/max measure string length or numeric value. */
    public static function validate(array $input, array $rules): array
    {
        $validated = [];
        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);
            if (!array_key_exists($field, $input)) {
                if (in_array('required', $fieldRules, true)) {
                    throw new HttpException(422, 'Validation failed: '.$field);
                }
                continue;
            }
            $value = $input[$field];
            if ($value === null && in_array('nullable', $fieldRules, true) && !in_array('required', $fieldRules, true)) {
                $validated[$field] = null;
                continue;
            }
            foreach ($fieldRules as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, '');
                $valid = match ($name) {
                    'required' => $value !== null && $value !== '' && $value !== [],
                    'nullable' => true,
                    'string' => is_string($value),
                    'integer' => (is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false,
                    'boolean' => in_array($value, [true, false, 0, 1, '0', '1'], true),
                    'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'array' => is_array($value),
                    'in' => is_scalar($value) && in_array((string) $value, explode(',', $argument), true),
                    'min' => self::size($value, $fieldRules) >= (float) $argument,
                    'max' => self::size($value, $fieldRules) <= (float) $argument,
                    default => throw new InvalidArgumentException('Unknown validation rule: '.$name),
                };
                if (!$valid) {
                    throw new HttpException(422, 'Validation failed: '.$field);
                }
            }
            $validated[$field] = $value;
        }
        return $validated;
    }

    private static function size(mixed $value, array $rules): float
    {
        if (in_array('integer', $rules, true)) {
            return is_numeric($value) ? (float) $value : -INF;
        }
        return is_string($value) ? mb_strlen($value, 'UTF-8') : (is_array($value) ? count($value) : -INF);
    }
}
