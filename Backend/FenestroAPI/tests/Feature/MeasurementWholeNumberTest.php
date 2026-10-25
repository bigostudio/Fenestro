<?php

namespace Tests\Feature;

use App\Http\Requests\MeasurementRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Aperture millimetres (width, height, diagonal) and glass thickness are whole numbers. */
class MeasurementWholeNumberTest extends TestCase
{
    private function errors(array $aperture): array
    {
        $rules = array_intersect_key((new MeasurementRequest())->rules(), array_flip([
            'apertures.*.width', 'apertures.*.height', 'apertures.*.diagonal', 'apertures.*.glassThickness',
        ]));

        return Validator::make(['apertures' => [$aperture]], $rules)->errors()->keys();
    }

    public function test_whole_millimetres_are_accepted(): void
    {
        $this->assertSame([], $this->errors(['width' => '1200', 'height' => '1500', 'diagonal' => '1921', 'glassThickness' => '5']));
        $this->assertSame([], $this->errors(['width' => null, 'height' => '', 'diagonal' => null]));
    }

    public function test_decimals_and_other_text_are_refused(): void
    {
        $this->assertSame(['apertures.0.width'], $this->errors(['width' => '1200.5']));
        $this->assertSame(['apertures.0.height'], $this->errors(['height' => '12.5']));
        $this->assertSame(['apertures.0.diagonal'], $this->errors(['diagonal' => '1e3']));
        $this->assertSame(['apertures.0.glassThickness'], $this->errors(['glassThickness' => '5.5']));
        $this->assertSame(['apertures.0.width'], $this->errors(['width' => '12345'])); // still max 4 digits
    }
}
