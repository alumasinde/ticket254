<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    public function validate(array $data, array $rules, array $messages = []): array
    {
        $errors = $this->errors($data, $rules, $messages);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $validated = [];

        foreach (array_keys($rules) as $field) {
            if (array_key_exists($field, $data)) {
                $validated[$field] = $data[$field];
            }
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @return array<string, string>
     */
    public function errors(array $data, array $rules, array $messages = []): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleSet) {
            $ruleList = $this->normalizeRules($ruleSet);
            $value = $data[$field] ?? null;
            $present = array_key_exists($field, $data);
            $required = in_array('required', $ruleList, true);
            $nullable = in_array('nullable', $ruleList, true);

            if (!$present || $value === null || $value === '') {
                if ($required) {
                    $errors[$field] = $this->message($messages, $field, 'required')
                        ?? sprintf('The %s field is required.', $this->label($field));

                    continue;
                }

                if (!$present || $nullable || $value === null) {
                    continue;
                }
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }

                $error = $this->checkRule($field, $value, $rule, $ruleList);

                if ($error !== null) {
                    $errors[$field] = $this->message($messages, $field, $rule)
                        ?? $this->defaultMessage($field, $rule, $error);

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string|list<string>> $rules
     * @return list<string>
     */
    private function normalizeRules(string|array $rules): array
    {
        $list = [];

        foreach ((array) $rules as $rule) {
            foreach (explode('|', (string) $rule) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $list[] = $part;
                }
            }
        }

        return $list;
    }

    /**
     * @param list<string> $ruleList
     * @return string|null An error detail consumed by the default message, null when valid.
     */
    private function checkRule(string $field, mixed $value, string $rule, array $ruleList): ?string
    {
        [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

        return match ($name) {
            'string' => is_string($value) ? null : 'text',
            'integer' => is_string($value) || is_int($value)
                ? (preg_match('/^-?\d+$/', (string) $value) === 1 ? null : 'whole number')
                : 'whole number',
            'numeric' => is_numeric($value) ? null : 'number',
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : 'valid email address',
            'boolean' => in_array($value, [true, false, 1, 0, '1', '0'], true) ? null : 'true or false',
            'array' => is_array($value) ? null : 'list',
            'max' => $this->checkBound($value, $parameter, $ruleList, true),
            'min' => $this->checkBound($value, $parameter, $ruleList, false),
            'in' => $this->checkIn($value, $parameter),
            'regex' => $this->checkRegex($value, $parameter),
            'timezone' => $this->checkTimezone($value),
            'date' => $this->checkDate($value),
            default => throw new RuntimeException("Unknown validation rule [$name]."),
        };
    }

    /**
     * @param list<string> $ruleList
     */
    private function checkBound(mixed $value, ?string $parameter, array $ruleList, bool $max): ?string
    {
        if ($parameter === null || !is_numeric($parameter)) {
            throw new RuntimeException('The max and min rules require a numeric limit.');
        }

        $limit = (float) $parameter;
        $numeric = is_int($value) || is_float($value)
            || in_array('integer', $ruleList, true)
            || in_array('numeric', $ruleList, true);

        if (is_array($value)) {
            $actual = count($value);
        } elseif ($numeric) {
            $actual = is_numeric($value) ? (float) $value : 0.0;
        } else {
            $actual = mb_strlen((string) $value);
        }

        $fails = $max ? $actual > $limit : $actual < $limit;

        return $fails ? $parameter : null;
    }

    private function checkIn(mixed $value, ?string $parameter): ?string
    {
        if ($parameter === null) {
            throw new RuntimeException('The in rule requires at least one value.');
        }

        $allowed = explode(',', $parameter);

        return in_array((string) $value, $allowed, true) ? null : implode(', ', $allowed);
    }

    private function checkRegex(mixed $value, ?string $parameter): ?string
    {
        if ($parameter === null || $parameter === '') {
            throw new RuntimeException('The regex rule requires a pattern.');
        }

        return is_string($value) && preg_match($parameter, $value) === 1 ? null : $parameter;
    }

    private function checkTimezone(mixed $value): ?string
    {
        return is_string($value) && in_array($value, timezone_identifiers_list(), true)
            ? null
            : 'valid timezone';
    }

    private function checkDate(mixed $value): ?string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?(Z|[+-]\d{2}:\d{2})?$/', $value) !== 1
        ) {
            return 'date';
        }

        try {
            new \DateTimeImmutable($value);

            return null;
        } catch (\Exception) {
            return 'date';
        }
    }

    /**
     * @param array<string, string> $messages
     */
    private function message(array $messages, string $field, string $rule): ?string
    {
        $ruleName = explode(':', $rule, 2)[0];

        return $messages[$field . '.' . $ruleName] ?? null;
    }

    private function defaultMessage(string $field, string $rule, string $detail): string
    {
        $label = $this->label($field);
        $ruleName = explode(':', $rule, 2)[0];

        return match ($ruleName) {
            'string' => sprintf('The %s field must be text.', $label),
            'integer' => sprintf('The %s field must be a whole number.', $label),
            'numeric' => sprintf('The %s field must be a number.', $label),
            'email' => sprintf('The %s field must be a valid email address.', $label),
            'boolean' => sprintf('The %s field must be true or false.', $label),
            'array' => sprintf('The %s field must be a list.', $label),
            'max' => sprintf('The %s field may not be greater than %s.', $label, $detail),
            'min' => sprintf('The %s field must be at least %s.', $label, $detail),
            'in' => sprintf('The selected %s is invalid.', $label),
            'regex' => sprintf('The %s field format is invalid.', $label),
            'timezone' => sprintf('The %s field must be a valid timezone.', $label),
            'date' => sprintf('The %s field must be a valid date and time.', $label),
            default => sprintf('The %s field is invalid.', $label),
        };
    }

    private function label(string $field): string
    {
        return str_replace(['_', '-'], ' ', $field);
    }
}
