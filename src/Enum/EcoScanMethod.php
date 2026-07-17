<?php

namespace App\Enum;

// A manual code entry is journalisée exactly like a QR scan (see e-CO.dc.html) - same GPS
// tolerance check applies, just a different input method.
enum EcoScanMethod: string
{
    case QrScan = 'qr_scan';
    case ManualCode = 'manual_code';

    public function labelKey(): string
    {
        return match ($this) {
            self::QrScan => 'ecoScanMethodQrScanLabel',
            self::ManualCode => 'ecoScanMethodManualCodeLabel',
        };
    }
}
