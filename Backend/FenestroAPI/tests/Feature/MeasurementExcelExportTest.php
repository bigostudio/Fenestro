<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Measurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * GET /api/measurements/{id}/export/excel produces a real .xlsx (maatwebsite/excel + PhpSpreadsheet):
 * one sheet per aperture-type group, the agreed 39 columns, and the saved values. Admin and internal
 * team only; refusals carry no measurement data.
 */
class MeasurementExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Correct-Horse-9!';

    private const HEADINGS = ['W-ID', 'Location', 'Project / Tower', 'Floor', 'Flat', 'Series', 'Types', 'Lock Type', 'Mesh Type',
        'Mesh Color', 'FX Grill', 'FX Scaffolding Ack', 'VW Louver Type', 'VW Grill', 'VW Grill Ack', 'OPW Opening (V)',
        'OPW Opening (H)', 'OPW Hinge', 'OPW Handle', 'OPD Opening (V)', 'OPD Opening (H)', 'OPD Hinge', 'OPD Handle',
        'OPD Espag Handle', 'OPD Panel', 'OPD Panel Combo', 'OPD Bottom Frame', 'Glass Finish', 'Glass Finish (Other)',
        'Glass Thickness (mm)', 'Glass Type', 'Profile Color', 'Width (mm)', 'Height (mm)', 'Diagonal (mm)',
        'Diagonal Auto Calc (mm)', 'PND Note', 'Notes', 'Date / Time Saved'];

    private Measurement $m;

    protected function setUp(): void
    {
        parent::setUp();
        $lead = Lead::create(['client_name' => 'Export Customer', 'client_phone' => '9000000401', 'type' => 'B2C', 'status' => 'new']);
        $this->m = Measurement::create(['lead_id' => $lead->id, 'customer_name' => 'Export Customer', 'customer_phone' => '9000000401',
            'type' => 'B2C', 'measured_by' => 'Ashu', 'status' => 'submitted', 'date' => '2026-11-05']);
        $this->m->apertures()->create(['aperture_ref_id' => 'A1', 'w_id' => 'W1', 'location' => 'Living room', 'series' => '60',
            'types' => ['FX', '2T'], 'width' => '1200', 'height' => '1500', 'glass_thickness' => '5', 'notes' => 'Corner window',
            'date_time_saved' => '2026-11-05 10:15:00']);
        $this->m->apertures()->create(['aperture_ref_id' => 'A2', 'w_id' => 'W2', 'location' => 'Bedroom', 'series' => '50',
            'types' => ['2T', 'FX'], 'width' => '900', 'height' => '1200']);
        $this->m->apertures()->create(['aperture_ref_id' => 'A3', 'w_id' => 'D1', 'location' => 'Balcony', 'series' => '60',
            'types' => ['OPD'], 'opd_panel_combo' => ['Glass', 'Panel'], 'width' => '900', 'height' => '2100']);
        $this->m->apertures()->create(['aperture_ref_id' => 'A4', 'w_id' => 'X1', 'location' => 'Store', 'series' => '60', 'types' => []]);
    }

    private function token(string $role): string
    {
        $u = User::create(['name' => ucfirst($role), 'email' => "{$role}@example.test", 'phone' => '91234550'.strlen($role),
            'password' => self::PW, 'role' => $role, 'is_active' => 1]);

        return JWTAuth::fromUser($u);
    }

    /** Download the export and open it with PhpSpreadsheet. */
    private function download(string $token): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $this->app['auth']->forgetGuards();
        $res = $this->withToken($token)->get("/api/measurements/{$this->m->id}/export/excel");
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename=.?measurement_'.$this->m->id.'_\d{8}_\d{6}\.xlsx/', (string) $res->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'mx').'.xlsx';
        copy($res->baseResponse->getFile()->getPathname(), $path);
        $this->assertSame("PK\x03\x04", file_get_contents($path, false, null, 0, 4), 'not an .xlsx (zip) file');
        $book = IOFactory::createReader('Xlsx')->load($path);
        @unlink($path);

        return $book;
    }

    private function rows(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $sheet): array
    {
        $ws = $book->getSheetByName($sheet);
        $this->assertNotNull($ws, "sheet {$sheet} missing");

        return $ws->toArray(null, false, false, false);
    }

    public function test_admin_downloads_a_real_workbook_with_the_expected_sheets_columns_and_values(): void
    {
        $book = $this->download($this->token('admin'));

        // Grouped by sorted type set: FX+2T and 2T+FX share one sheet.
        $this->assertEqualsCanonicalizing(['2T & FX', 'OPD', 'Untyped'], $book->getSheetNames());

        $grouped = $this->rows($book, '2T & FX');
        $this->assertSame(self::HEADINGS, $grouped[0]);
        $this->assertCount(3, $grouped, 'heading + 2 apertures');
        $byWid = collect(array_slice($grouped, 1))->keyBy(0);
        $w1 = array_combine(self::HEADINGS, $byWid['W1']);
        $this->assertSame('Living room', $w1['Location']);
        $this->assertSame('FX, 2T', $w1['Types']);
        $this->assertEquals('60', $w1['Series']);
        $this->assertEquals('1200', $w1['Width (mm)']);
        $this->assertEquals('1500', $w1['Height (mm)']);
        $this->assertEquals('5', $w1['Glass Thickness (mm)']);
        $this->assertSame('Corner window', $w1['Notes']);
        $this->assertSame('2026-11-05 10:15:00', $w1['Date / Time Saved']);
        $this->assertSame('Bedroom', array_combine(self::HEADINGS, $byWid['W2'])['Location']);

        $opd = array_combine(self::HEADINGS, $this->rows($book, 'OPD')[1]);
        $this->assertSame('D1', $opd['W-ID']);
        $this->assertSame('Glass, Panel', $opd['OPD Panel Combo']);
        $this->assertEquals('2100', $opd['Height (mm)']);

        $untyped = $this->rows($book, 'Untyped');
        $this->assertSame(self::HEADINGS, $untyped[0]);
        $this->assertSame('X1', $untyped[1][0]);
    }

    public function test_a_measurement_without_apertures_exports_headings_only(): void
    {
        $this->m->apertures()->delete();
        $book = $this->download($this->token('admin'));
        $this->assertSame(['Apertures'], $book->getSheetNames());
        $this->assertSame([self::HEADINGS], $this->rows($book, 'Apertures'));
    }

    public function test_internal_team_can_export(): void
    {
        $this->assertCount(3, $this->download($this->token('internal_team'))->getSheetNames());
    }

    public function test_partners_and_signed_out_users_cannot_export_and_get_no_data(): void
    {
        $partner = User::create(['name' => 'P', 'phone' => '9123455099', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->m->lead->update(['referral_partner_id' => $partner->id]); // even their own referral
        foreach ([[JWTAuth::fromUser($partner), 403], [null, 401]] as [$token, $status]) {
            $this->app['auth']->forgetGuards();
            $this->app->forgetInstance('tymon.jwt');
            $this->app->forgetInstance('tymon.jwt.auth');
            $this->flushHeaders();
            $res = $token ? $this->withToken($token)->getJson("/api/measurements/{$this->m->id}/export/excel") : $this->getJson("/api/measurements/{$this->m->id}/export/excel");
            $res->assertStatus($status);
            $this->assertStringNotContainsString('spreadsheetml', (string) $res->headers->get('Content-Type'));
            foreach (['Export Customer', '9000000401', 'Living room'] as $secret) {
                $this->assertStringNotContainsString($secret, $res->getContent());
            }
        }
    }
}
