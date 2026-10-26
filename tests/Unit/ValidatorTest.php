<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Core\Validator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function test_required_rejects_missing_and_empty_values(): void
    {
        $errors = $this->validator->errors([], ['subject' => 'required|string']);
        $this->assertSame('The subject field is required.', $errors['subject']);

        $errors = $this->validator->errors(['subject' => ''], ['subject' => 'required|string']);
        $this->assertSame('The subject field is required.', $errors['subject']);
    }

    public function test_zero_is_a_valid_required_value(): void
    {
        $this->assertSame([], $this->validator->errors(['subject' => '0'], ['subject' => 'required|string']));
    }

    public function test_email_and_length_rules(): void
    {
        $errors = $this->validator->errors(
            ['email' => 'not-an-email', 'subject' => 'too long'],
            ['email' => 'required|email', 'subject' => 'required|string|max:5'],
        );

        $this->assertSame('The email field must be a valid email address.', $errors['email']);
        $this->assertSame('The subject field may not be greater than 5.', $errors['subject']);
    }

    public function test_integer_and_min_rules(): void
    {
        $errors = $this->validator->errors(
            ['age' => '17', 'count' => 'many'],
            ['age' => 'integer|min:18', 'count' => 'integer'],
        );

        $this->assertSame('The age field must be at least 18.', $errors['age']);
        $this->assertSame('The count field must be a whole number.', $errors['count']);
    }

    public function test_in_rule_lists_allowed_values(): void
    {
        $errors = $this->validator->errors(
            ['priority' => 'whenever'],
            ['priority' => 'in:low,normal,high,urgent'],
        );

        $this->assertSame('The selected priority is invalid.', $errors['priority']);
        $this->assertSame([], $this->validator->errors(['priority' => 'high'], ['priority' => 'in:low,high']));
    }

    public function test_nullable_empty_values_skip_other_rules(): void
    {
        $this->assertSame([], $this->validator->errors(
            ['email' => ''],
            ['email' => 'nullable|email'],
        ));
    }

    public function test_validate_returns_only_fields_with_rules(): void
    {
        $validated = $this->validator->validate(
            ['subject' => 'Printer jam', 'extra' => 'ignored'],
            ['subject' => 'required|string'],
        );

        $this->assertSame(['subject' => 'Printer jam'], $validated);
    }

    public function test_validate_throws_with_field_errors(): void
    {
        try {
            $this->validator->validate(['priority' => 'bad'], ['priority' => 'in:low,high']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(['priority' => 'The selected priority is invalid.'], $exception->errors());
        }
    }

    public function test_custom_messages_override_defaults(): void
    {
        $errors = $this->validator->errors(
            [],
            ['subject' => 'required'],
            ['subject.required' => 'Tell us the subject.'],
        );

        $this->assertSame('Tell us the subject.', $errors['subject']);
    }

    public function test_unknown_rule_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validator->errors(['name' => 'x'], ['name' => 'telepathic']);
    }
}
