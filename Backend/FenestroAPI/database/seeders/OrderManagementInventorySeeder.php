<?php

namespace Database\Seeders;

use App\Models\OrderManagement\InventoryProfile;
use Illuminate\Database\Seeder;

/**
 * OPT-IN: loads the uPVC profile catalogue (codes + descriptions) from the
 * approved Order Management prototype into the Inventory module.
 *
 * Stock, unit cost and project usage all start at zero — the prototype's
 * sample quantities are NOT loaded. Existing profiles are left untouched.
 *
 * Not called by DatabaseSeeder. Run explicitly:
 *   php artisan db:seed --class=OrderManagementInventorySeeder
 */
class OrderManagementInventorySeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            ['EWSPL-LF-1001', '2 TRACK OUTER FRAME'],
            ['EWSPL-LF-1002', '2.5 TRACK OUTER FRAME'],
            ['EWSPL-LF-1003', '3 TRACK OUTER FRAME'],
            ['EWSPL-LF-1004', 'SLIDING WINDOW SASH'],
            ['EWSPL-LF-1004-WG', 'SLIDNG WINDOW SASH WITH GASKET'],
            ['EWSPL-LF-1005', 'SLIDING DOOR SASH'],
            ['EWSPL-LF-1005-WG', 'SLIDNG DOOR SASH WITH GASKET'],
            ['EWSPL-LF-1006', 'SLIDING MESH SASH'],
            ['EWSPL-LF-1007', 'SLIDING SINGLE GLAZING BEAD'],
            ['EWSPL-LF-1007-WG', 'SLIDING SINGLE GLAZING BEAD WITH GASKET'],
            ['EWSPL-LF-1008', 'SLIDING DOUBLE GLAZING BEAD'],
            ['EWSPL-LF-1008-WG', 'SLIDING DOUBLE GLAZING BEAD WITH GASKET'],
            ['EWSPL-LF-1009', 'SLIDING WINDOW INTERLOCK'],
            ['EWSPL-LF-1010', 'SLIDING DOOR INTERLOCK'],
            ['EWSPL-LF-1011', 'MULTI PANE ADAPTOR'],
            ['EWSPL-LF-1012', 'SLIDING MESH MULLION'],
            ['EWSPL-LF-1013', 'SLIDING SASH MULLION'],
            ['EWSPL-LF-1013-WG', 'SLIDING SASH MULLION WITH GASKET'],
            ['EWSPL-LF-1014', 'SLIDING MEGA WINDOW SASH'],
            ['EWSPL-LF-1014-WG', 'SLIDNG MEGA WINDOW SASH WITH GASKET'],
            ['EWSPL-LF-1015', 'SLIDING MEGA WINDOW INTERLOCK'],
            ['EWSPL-LF-1016', 'SLIDING MONORAIL OUTER FRAME'],
            ['EWSPL-LF-1016-WG', 'SLIDING MONORAIL OUTER FRAME WITH GASKET'],
            ['EWSPL-LF-2001', 'CASEMENT OUTER FRAME'],
            ['EWSPL-LF-2001-WG', 'CASEMENT OUTER FRAME WITH GASKET'],
            ['EWSPL-LF-2002', 'OUTWARD WINDOW SASH'],
            ['EWSPL-LF-2002-WG', 'OUTWARD WINDOW SASH WITH GASKET'],
            ['EWSPL-LF-2003', 'CASEMENT DOOR SASH'],
            ['EWSPL-LF-2003-WG', 'CASEMENT DOOR SASH WITH GASKET'],
            ['EWSPL-LF-2004', 'T MULLION'],
            ['EWSPL-LF-2004-WG', 'T MULLION WITH GASKET'],
            ['EWSPL-LF-2005', 'INWARD DOOR SASH'],
            ['EWSPL-LF-2005-WG', 'INWARD DOOR SASH WITH GASKET'],
            ['EWSPL-LF-2006', 'FALSE MULLION'],
            ['EWSPL-LF-2006-WG', 'FALSE MULLION WITH GASKET'],
            ['EWSPL-LF-2007', 'SINGLE GLAZING BEAD'],
            ['EWSPL-LF-2007-WG', 'SINGLE GLAZING BEAD WITH GASKET'],
            ['EWSPL-LF-2008', 'DOUBLE GLAZING BEAD'],
            ['EWSPL-LF-2008-WG', 'DOUBLE GLAZING BEAD WITH GASKET'],
            ['EWSPL-LF-2009', 'CASEMENT Z MULLION/SASH'],
            ['EWSPL-LF-2009-WG', 'CASEMENT Z MULLION/SASH-WITH GASKET'],
            ['EWSPL-LF-4001', '2 TRACK SLEEK OUTER FRAME'],
            ['EWSPL-LF-4002', '2.5 TRACK SLEEK OUTER FRAME'],
            ['EWSPL-LF-4003', 'SLEEK WINDOW SASH'],
            ['EWSPL-LF-4003-WG', 'SLEEK WINDOW SASH WITH GASKET'],
            ['EWSPL-LF-4004', 'SLEEK MESH SASH'],
            ['EWSPL-LF-4005', 'SLEEK SINGLE GLAZING BEAD'],
            ['EWSPL-LF-4005-WG', 'SLEEK SINGLE GLAZNG BEAD WITH GASKET'],
            ['EWSPL-LF-4006', 'SLEEK WINDOW INTERLOCK'],
            ['EWSPL-LF-4007', '3 TRACK SLEEK OUTER FRAME'],
            ['EWSPL-LF-4008', 'SLIDING SLEEK DOOR SASH'],
            ['EWSPL-LF-4008 - WG', 'SLIDING SLEEK DOOR SASH WITH GASKET'],
            ['EWSPL-LF-4009', 'SLIDING SLEEK DOOR INTERLOCK'],
            ['EWSPL-LF-5001', 'CASEMENT SLEEK OUTER FRAME'],
            ['EWSPL-LF-5001 - WG', 'CASEMENT SLEEK OUTER FRAME-WITH GASKET'],
            ['EWSPL-LF-5002', 'CASEMENT SLEEK WINDOW SASH'],
            ['EWSPL-LF-5002 - WG', 'CASEMENT SLEEK WINDOW SASH - WITH GASKET'],
            ['EWSPL-LF-5003', 'CASEMENT SLEEK T MULLION'],
            ['EWSPL-LF-5003 - WG', 'CASEMENT SLEEK T MULLION WITH GASKET'],
            ['EWSPL-LF-5004', 'CASEMENT SLEEK SINGLE GLAZING BEAD'],
            ['EWSPL-LF-5004 - WG', 'CASEMENT SLEEK SINGLE GLAZING BEAD WITH GASKET'],
            ['EWSPL-LF-5007 - R', 'SLEEK PUNCHING CAP - 50- SERIES - RIGHT'],
            ['EWSPL-LF-3001', 'BAY POLE'],
            ['EWSPL-LF-3005', 'DOOR PANEL 20 MM'],
        ];

        $created = 0;
        foreach ($profiles as [$code, $item]) {
            $profile = InventoryProfile::firstOrCreate(
                ['code' => $code],
                ['item' => $item, 'stock' => 0, 'base_stock' => 0, 'unit_cost' => 0]
            );
            $created += $profile->wasRecentlyCreated ? 1 : 0;
        }

        $this->command?->info("Inventory catalogue: {$created} profile(s) added, ".(count($profiles) - $created).' already present.');
    }
}
