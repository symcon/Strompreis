<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

//Messages
include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';

class PowerPriceEntsoeTest extends TestCase
{
    protected function setUp(): void
    {
        //Reset
        IPS\Kernel::reset();
        //Register our core stubs for testing
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        //Register our library we need for testing
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();

        // Set up the error handler to treat warnings as exceptions
        set_error_handler(function ($errno, $errstr, $errfile, $errline)
        {
            if (!(error_reporting() & $errno)) {
                // This error code is not included in error_reporting, so let it fall
                // through to the standard PHP error handler
                return false;
            }
            // Throw an exception for warnings and above
            if ($errno == E_WARNING || $errno == E_NOTICE || $errno == E_USER_WARNING || $errno == E_USER_NOTICE || $errno == E_DEPRECATED || $errno == E_USER_DEPRECATED) {
                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            }
            return false; // Let PHP handle other errors
        });
    }

    public function testDataFromWrongDay() : void
    {
        IPS_CreateVariableProfile('~TextBox', 3);
        $instanceID = IPS_CreateInstance('{9354E28B-4E62-AEE5-8F88-BECA9F3F4F8F}');
        IPS_SetConfiguration($instanceID, json_encode([
            'Provider' => 'EPEXSpot',
            'EPEXSpotToken' => 'test',
            'EPEXSpotMarket' => 'DE-LU',
            'aWATTarMarket' => 'de',
            'TibberPostalCode' => '23554',
            'PriceResolution' => 15,
            'PriceBase' => 0,
            'PriceSurcharge' => 0,
            'PriceTax' => 0,
        ]));
        IPS_ApplyChanges($instanceID);
        // Time is in UTC, so 17:47:17 in UTC is 18:47:17 in CET
        SPX_SetTime($instanceID, strtotime('16.02.2026 17:47:17'));
        $securityToken = 'test';
        $start = mktime(0, 0, 0, 2, 16, 2026);
        $end = strtotime('+2 days', $start);
        $dateFormat = 'YmdHi';
        $market = '10Y1001A1001A82H';
        SPX_SetContentsOverride($instanceID, sprintf(
            'https://web-api.tp.entsoe.eu/api?securityToken=%s&documentType=A44&periodStart=%s&periodEnd=%s&out_Domain=%s&in_Domain=%s',
            $securityToken,
            gmdate($dateFormat, $start),
            gmdate($dateFormat, $end),
            $market,
            $market
        ), file_get_contents(__DIR__ . '/getContentsOverrides/fromWrongDay.xml'));

        SPX_Update($instanceID);

        $currentPriceID = IPS_GetObjectIDByIdent('CurrentPrice', $instanceID);
        $this->assertEqualsWithDelta(13.408, GetValue($currentPriceID), 0.001);
    }
}