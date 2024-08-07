<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class PowerPriceValidationTest extends TestCaseSymconValidation
{
    public function testValidatePowerPrice(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidatePowerPriceModule(): void
    {
        $this->validateModule(__DIR__ . '/../PowerPrice');
    }
}