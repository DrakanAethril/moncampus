<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FormValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;

/**
 * Reading a submitted field as the type the caller needs.
 *
 * FormInterface::getData() answers mixed by design - a text field holds a string once submitted,
 * but nothing in the type system says so, and a form built with a different data class or read
 * before submission holds something else entirely. Every caller used to cast on the spot.
 */
class FormValueTest extends TestCase
{
    public function testReadsAScalarFieldAsAString(): void
    {
        self::assertSame('Dupont', FormValue::string($this->form(['name' => 'Dupont']), 'name'));
        self::assertSame('42', FormValue::string($this->form(['name' => 42]), 'name'));
    }

    public function testANullOrNonScalarFieldReadsAsTheDefault(): void
    {
        self::assertSame('', FormValue::string($this->form(['name' => null]), 'name'));
        self::assertSame('', FormValue::string($this->form(['name' => ['a', 'b']]), 'name'));
        self::assertSame('—', FormValue::string($this->form(['name' => null]), 'name', '—'));
    }

    public function testStringDoesNotTrimOnItsOwn(): void
    {
        // Callers that want trimming say so - a rich-text body must keep what the editor sent.
        self::assertSame('  x  ', FormValue::string($this->form(['name' => '  x  ']), 'name'));
    }

    public function testReadsANumericFieldAsAnInt(): void
    {
        self::assertSame(7, FormValue::int($this->form(['n' => 7]), 'n'));
        self::assertSame(7, FormValue::int($this->form(['n' => '7']), 'n'));
        self::assertSame(7, FormValue::int($this->form(['n' => 7.9]), 'n'), 'truncated, like a cast');
    }

    public function testANonNumericFieldReadsAsTheIntDefault(): void
    {
        self::assertSame(0, FormValue::int($this->form(['n' => 'sept']), 'n'));
        self::assertSame(0, FormValue::int($this->form(['n' => null]), 'n'));
        self::assertSame(10, FormValue::int($this->form(['n' => null]), 'n', 10));
    }

    public function testReadsANumericFieldAsAFloat(): void
    {
        self::assertSame(1.5, FormValue::float($this->form(['n' => '1.5']), 'n'));
        self::assertNull(FormValue::float($this->form(['n' => 'beaucoup']), 'n'));
    }

    /** @param array<string, mixed> $values */
    private function form(array $values): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('get')->willReturnCallback(function (string $field) use ($values): FormInterface {
            $child = $this->createStub(FormInterface::class);
            $child->method('getData')->willReturn($values[$field] ?? null);

            return $child;
        });

        return $form;
    }
}
