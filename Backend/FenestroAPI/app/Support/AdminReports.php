<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\Measurement;
use App\Models\OrderManagement\InventoryProfile;
use App\Models\OrderManagement\Order;
use App\Models\OrderManagement\OrderReceipt;
use App\Models\OrderManagement\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Admin portal reports (GET /api/admin/reports/{type}). Read-only.
 *
 * Every report is built from the database with the same rules the pages
 * use (Order::outstanding(), isArchived(), isReceivable(),
 * InventoryProfile::currentStock(), the CNS status config), and always
 * covers every matching record, not just one page of a table. The browser
 * turns the result into a PDF or an Excel file; nothing is stored.
 *
 * Shape: title, description, generated_at, filters [{label, value}],
 * summary [{label, value, format}], sections [{title, sheet, columns
 * [{key, label, format, wide?}], rows, totals?, empty}], notes [string].
 * Column formats: text, number, money, date, datetime.
 */
class AdminReports
{
    public const TYPES = ['command-center', 'orders', 'cns', 'inventory', 'receivables', 'archive', 'analytics', 'business', 'partners', 'leads', 'action-hub'];

    /** Allowed values for each report's filter fields (validated by ReportController). */
    public const FILTERS = [
        'orders' => ['filter' => ['all', 'active', 'completed', 'outstanding']],
        'cns' => ['view' => ['active', 'recovery', 'archived', 'all']],
        'inventory' => ['filter' => ['all', 'shortage', 'zero']],
        'receivables' => ['filter' => ['all', 'completed', 'active']],
        'partners' => ['status' => ['all', 'pending', 'approved', 'rejected']],
        'leads' => ['status' => ['active', 'all', 'new', 'in_progress', 'measured', 'closed', 'archived']],
    ];

    /** Most stock movements listed in one Inventory report (newest first). */
    private const MOVEMENT_LIMIT = 1000;

    /** Status values the dashboard always treats as done (dashboard statusDone()), plus each column's configured done values. */
    private const DONE_VALUES = ['YES', 'AVAILABLE', 'PACKED', 'SHARED', 'INFORMED', 'PROJECT COMPLETED', 'NOT APPLICABLE'];

    /** Columns holding names; see col(). */
    private const NAME_COLUMNS = ['customer', 'project', 'client', 'partner', 'name', 'company'];

    private const FILTER_LABELS = [
        'all' => 'All', 'active' => 'Active projects', 'completed' => 'Completed projects', 'outstanding' => 'Outstanding balance',
        'recovery' => 'Completed – payment due', 'archived' => 'Archived (completed & paid, or aborted)',
        'shortage' => 'Shortages', 'zero' => 'Zero / negative stock',
        'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected',
    ];

    public static function build(string $type, array $params): array
    {
        $report = match ($type) {
            'command-center' => self::commandCenter(),
            'orders' => self::orders($params),
            'cns' => self::cns($params),
            'inventory' => self::inventory($params),
            'receivables' => self::receivables($params),
            'archive' => self::archive($params),
            'analytics' => self::analytics(),
            'business' => self::business(),
            'partners' => self::partners($params),
            'leads' => self::leads($params),
            'action-hub' => ActionHubReport::build($params),
        };

        return ['type' => $type, 'generated_at' => OrderManagement::iso(now())] + $report + ['notes' => []];
    }

    // ── A. Command Center ──────────────────────────────────────────────

    private static function commandCenter(): array
    {
        $orders = self::allOrders();
        $inventory = self::allInventory();
        $active = $orders->reject->isCompleted();
        $short = $inventory->filter->isShort();
        $receivables = $orders->filter->isReceivable()->sortByDesc(fn (Order $o) => $o->outstanding())->values();

        // Same six months as the Command Center's order intake chart.
        $months = collect(range(5, 0))->map(fn (int $k) => now()->startOfMonth()->subMonthsNoOverflow($k));
        $intake = $months->map(function (Carbon $m) use ($orders) {
            $in = $orders->filter(fn (Order $o) => $o->confirmed?->format('Y-m') === $m->format('Y-m'));

            return ['month' => $m->format('M Y'), 'orders' => $in->count(), 'invoice' => round($in->sum(fn (Order $o) => $o->invoiceAmount()), 2)];
        })->values()->all();

        return [
            'title' => 'Command Center Report',
            'description' => 'Overall projects, status, receivables and inventory alerts.',
            'filters' => [],
            'summary' => [
                self::stat('Total projects', $orders->count()),
                self::stat('Active projects', $active->count()),
                self::stat('Completed – payment due', $orders->filter(fn (Order $o) => $o->isCompleted() && ! $o->isArchived())->count()),
                self::stat('Archived projects', $orders->filter->isArchived()->count()),
                self::stat('Active order area (SFT)', round($active->sum(fn (Order $o) => (float) $o->sft), 3)),
                self::stat('Invoice value', self::sumMoney($orders, fn (Order $o) => $o->invoiceAmount()), 'money'),
                self::stat('Amount received', self::sumMoney($orders, fn (Order $o) => $o->receivedAmount()), 'money'),
                self::stat('Outstanding balance', self::sumMoney($orders, fn (Order $o) => $o->outstanding()), 'money'),
                self::stat('Inventory shortage profiles', $short->count()),
            ],
            'sections' => [
                self::section('Projects by communication status', 'Project status', [
                    self::col('status', 'PROJ STATUS COMM'), self::col('count', 'Projects', 'number'),
                ], $orders->groupBy(fn (Order $o) => $o->projectStatusLabel() ?: '—')
                    ->map(fn (Collection $g, string $label) => ['status' => $label, 'count' => $g->count()])
                    ->sortByDesc('count')->values()->all(), ['count' => $orders->count()], 'No projects yet.'),
                self::readinessSection($active, 'Readiness (active projects)'),
                self::section('Order intake (last 6 months)', 'Order intake', [
                    self::col('month', 'Month'), self::col('orders', 'Orders confirmed', 'number'), self::col('invoice', 'Invoice value', 'money'),
                ], $intake, [
                    'orders' => array_sum(array_column($intake, 'orders')),
                    'invoice' => round(array_sum(array_column($intake, 'invoice')), 2),
                ], 'No orders confirmed in the last 6 months.'),
                self::section('Outstanding receivables', 'Receivables', [
                    self::col('id', 'Project ID'), self::col('customer', 'Customer'), self::col('project', 'Project'),
                    self::col('payment_status', 'Payment status'), self::col('outstanding', 'Outstanding', 'money'),
                ], $receivables->map(fn (Order $o) => [
                    'id' => $o->code, 'customer' => OrderManagement::upper($o->customer), 'project' => OrderManagement::upper($o->project),
                    'payment_status' => $o->paymentStatus(), 'outstanding' => $o->outstanding(),
                ])->all(), ['outstanding' => self::sumMoney($receivables, fn (Order $o) => $o->outstanding())], 'No payments due.'),
                self::section('Inventory alerts (shortages)', 'Inventory alerts', [
                    self::col('code', 'Profile code'), self::col('item', 'Item', 'text', true),
                    self::col('current', 'Current stock', 'number'), self::col('shortage_value', 'Shortage value to buy', 'money'),
                ], $short->map(fn (InventoryProfile $p) => [
                    'code' => $p->code, 'item' => $p->item, 'current' => $p->currentStock(), 'shortage_value' => $p->shortageValue(),
                ])->values()->all(), ['shortage_value' => self::sumMoney($short, fn (InventoryProfile $p) => $p->shortageValue())], 'No shortage alerts.'),
            ],
        ];
    }

    // ── B. Customer & Orders ───────────────────────────────────────────

    private static function orders(array $params): array
    {
        $q = $params['q'] ?? '';
        $filter = $params['filter'] ?? 'all';
        // Same search and filter as the Customer & Orders page (renderOrders).
        $orders = self::allOrders()
            ->filter(fn (Order $o) => self::matches($q, $o->code, $o->customer, $o->project, $o->phone))
            ->filter(fn (Order $o) => match ($filter) {
                'active' => ! $o->isCompleted(),
                'completed' => $o->isCompleted(),
                'outstanding' => $o->outstanding() > 0,
                default => true,
            })->values();

        $money = fn (callable $f) => self::sumMoney($orders, $f);

        return [
            'title' => 'Customer & Orders Report',
            'description' => 'Customer and project details, order values, payments and status.',
            'filters' => self::filterList($q, ['Show' => $filter]),
            'summary' => [
                self::stat('Projects', $orders->count()),
                self::stat('Order area (SFT)', round($orders->sum(fn (Order $o) => (float) $o->sft), 3)),
                self::stat('Invoice value', $money(fn (Order $o) => $o->invoiceAmount()), 'money'),
                self::stat('Received', $money(fn (Order $o) => $o->receivedAmount()), 'money'),
                self::stat('Outstanding', $money(fn (Order $o) => $o->outstanding()), 'money'),
            ],
            'sections' => [
                self::section('Orders', 'Orders', [
                    self::col('id', 'Project ID'), self::col('customer', 'Customer'), self::col('project', 'Project'),
                    self::col('phone', 'Phone'), self::col('confirmed', 'Confirmed', 'date'), self::col('installation', 'Installation', 'date'),
                    self::col('sft', 'SFT', 'number'), self::col('rate', 'Rate / SFT', 'money'), self::col('tax', 'GST %', 'number'),
                    self::col('taxable', 'Taxable', 'money'), self::col('invoice', 'Invoice', 'money'), self::col('received', 'Received', 'money'),
                    self::col('outstanding', 'Outstanding', 'money'), self::col('payment_status', 'Payment status'),
                    self::col('status', 'Project status'), self::col('written_off', 'Written off'),
                ], $orders->map(fn (Order $o) => [
                    'id' => $o->code, 'customer' => OrderManagement::upper($o->customer), 'project' => OrderManagement::upper($o->project), 'phone' => $o->phone ?? '',
                    'confirmed' => self::ymd($o->confirmed), 'installation' => self::ymd($o->installation_date),
                    'sft' => (float) $o->sft, 'rate' => (float) $o->rate, 'tax' => (float) $o->tax,
                    'taxable' => round((float) $o->sft * (float) $o->rate, 2), 'invoice' => $o->invoiceAmount(),
                    'received' => $o->receivedAmount(), 'outstanding' => $o->outstanding(), 'payment_status' => $o->paymentStatus(),
                    'status' => $o->projectStatusLabel(), 'written_off' => $o->written_off === 'Yes' ? 'Yes' : 'No',
                ])->all(), [
                    'sft' => round($orders->sum(fn (Order $o) => (float) $o->sft), 3),
                    'taxable' => $money(fn (Order $o) => (float) $o->sft * (float) $o->rate),
                    'invoice' => $money(fn (Order $o) => $o->invoiceAmount()),
                    'received' => $money(fn (Order $o) => $o->receivedAmount()),
                    'outstanding' => $money(fn (Order $o) => $o->outstanding()),
                ], 'No orders match this search or filter.'),
                self::paymentHistory($orders),
            ],
        ];
    }

    // ── C. CNS Dashboard ───────────────────────────────────────────────

    private static function cns(array $params): array
    {
        $q = $params['q'] ?? '';
        $view = $params['view'] ?? 'active';
        $inventory = self::allInventory();
        // Same search, view and order as the CNS Dashboard (renderCNS): completed last, then newest confirmed.
        $orders = self::allOrders()
            ->filter(fn (Order $o) => self::matches($q, $o->customer, $o->project, $o->code, $o->phone))
            ->filter(fn (Order $o) => match ($view) {
                'active' => ! $o->isCompleted(),
                'recovery' => $o->isCompleted() && ! $o->isArchived(),
                'archived' => $o->isArchived(),
                default => true,
            })
            ->sort(fn (Order $a, Order $b) => [$a->isCompleted(), $b->confirmed?->format('Y-m-d') ?? ''] <=> [$b->isCompleted(), $a->confirmed?->format('Y-m-d') ?? ''])
            ->values();
        $statuses = OrderManagement::statuses();

        $columns = [self::col('id', 'Project ID'), self::col('customer', 'Customer'), self::col('project', 'Project'), self::col('confirmed', 'Confirmed', 'date')];
        foreach ($statuses as $key => $s) {
            $columns[] = self::col('st_'.$key, $s['label']);
        }
        $columns[] = self::col('followup', 'Follow-up');
        $columns[] = self::col('alerts', 'Stock alerts', 'text', true);
        $columns[] = self::col('outstanding', 'Outstanding', 'money');

        // Profiles with no stock left, each with its usage keyed by Project ID / project / customer.
        $empty = $inventory->filter(fn (InventoryProfile $p) => $p->currentStock() <= 0)
            ->map(fn (InventoryProfile $p) => ['profile' => $p, 'usage' => $p->usages->keyBy('usage_key')]);
        $alertCount = 0;
        $rows = $orders->map(function (Order $o) use ($statuses, $empty, &$alertCount) {
            $values = OrderManagement::normaliseStatuses($o->statuses ?? []);
            $row = ['id' => $o->code, 'customer' => OrderManagement::upper($o->customer), 'project' => OrderManagement::upper($o->project), 'confirmed' => self::ymd($o->confirmed)];
            foreach ($statuses as $key => $s) {
                $row['st_'.$key] = self::statusLabel($key, $values[$key] ?? $s['default']);
            }
            // As the dashboard's projectUsage(): Project ID first, then project name, then customer name.
            $alerts = $empty->filter(function (array $e) use ($o) {
                $u = $e['usage']->get($o->code) ?? $e['usage']->get($o->project) ?? $e['usage']->get($o->customer);

                return $u && (float) $u->qty > 0;
            })->pluck('profile');
            $alertCount += $alerts->count() ? 1 : 0;
            $row['followup'] = self::followUp($o);
            $row['alerts'] = $alerts->map(fn (InventoryProfile $p) => $p->code.' ('.self::num($p->currentStock()).')')->implode(', ') ?: 'Clear';
            $row['outstanding'] = $o->outstanding();

            return $row;
        })->all();

        return [
            'title' => 'CNS Dashboard Report',
            'description' => 'Communication and readiness status, stock alerts and follow-ups per project.',
            'filters' => self::filterList($q, ['View' => $view]),
            'summary' => [
                self::stat('Projects', $orders->count()),
                self::stat('Follow-ups due', $orders->filter(fn (Order $o) => str_starts_with(self::followUp($o), 'Follow-up due'))->count()),
                self::stat('Projects with stock alerts', $alertCount),
                self::stat('Outstanding', self::sumMoney($orders, fn (Order $o) => $o->outstanding()), 'money'),
            ],
            'sections' => [
                self::section('Project status board', 'CNS', $columns, $rows, ['outstanding' => self::sumMoney($orders, fn (Order $o) => $o->outstanding())], 'No portfolios in this view.'),
                self::readinessSection($orders, 'Pending by status (projects in this report)'),
            ],
        ];
    }

    // ── D. Inventory ───────────────────────────────────────────────────

    private static function inventory(array $params): array
    {
        $q = $params['q'] ?? '';
        $filter = $params['filter'] ?? 'all';
        $orders = self::allOrders();
        // Same search and filter as the Inventory page (renderInventory).
        $profiles = self::allInventory()
            ->filter(fn (InventoryProfile $p) => self::matches($q, $p->code, $p->item, $p->unit, ...$p->usages->pluck('usage_key')->all()))
            ->filter(fn (InventoryProfile $p) => match ($filter) {
                'shortage' => $p->isShort(),
                'zero' => $p->currentStock() <= 0,
                default => true,
            })->values();

        $usageRows = [];
        foreach ($profiles as $p) {
            foreach ($p->usages as $u) {
                if ((float) $u->qty == 0.0) {
                    continue;
                }
                $o = self::usageOrder($orders, $u->usage_key);
                $usageRows[] = [
                    'code' => $p->code, 'project_id' => $o?->code ?? 'Legacy / unmapped', 'project' => $o ? OrderManagement::upper($o->project).' · '.OrderManagement::upper($o->customer) : $u->usage_key,
                    'qty' => (float) $u->qty, 'state' => ! $o ? 'Legacy usage' : ($o->isCompleted() ? 'Archived ('.$o->projectStatusLabel().')' : 'Active'),
                ];
            }
        }

        $movements = StockMovement::whereIn('profile_id', $profiles->pluck('id'))
            ->orderByDesc('movement_date')->orderByDesc('id')->limit(self::MOVEMENT_LIMIT + 1)->get();
        $codes = $profiles->pluck('code', 'id');
        $notes = [];
        if ($movements->count() > self::MOVEMENT_LIMIT) {
            $movements = $movements->take(self::MOVEMENT_LIMIT);
            $notes[] = 'Stock movements: the newest '.self::MOVEMENT_LIMIT.' are listed.';
        }
        $notes[] = 'Minimum stock thresholds are not recorded for profiles, so a shortage means current stock at or below zero while a project still uses the profile.';

        $sum = fn (callable $f) => self::sumMoney($profiles, $f);

        return [
            'title' => 'Inventory Report',
            'description' => 'Profile stock, project usage, shortages and stock movements.',
            'filters' => self::filterList($q, ['Show' => $filter]),
            'summary' => [
                self::stat('Profiles', $profiles->count()),
                self::stat('Shortage alerts', $profiles->filter->isShort()->count()),
                self::stat('Zero / negative stock', $profiles->filter(fn (InventoryProfile $p) => $p->currentStock() <= 0)->count()),
                self::stat('Current stock value', $sum(fn (InventoryProfile $p) => $p->stockValue()), 'money'),
                self::stat('Shortage value to buy', $sum(fn (InventoryProfile $p) => $p->shortageValue()), 'money'),
            ],
            'sections' => [
                self::section('Stock by profile', 'Stock', [
                    self::col('code', 'Profile code'), self::col('item', 'Item', 'text', true), self::col('unit', 'Unit'),
                    self::col('base', 'Base stock', 'number'), self::col('used', 'Project usage', 'number'),
                    self::col('current', 'Current stock', 'number'), self::col('shortage_qty', 'Shortage qty', 'number'),
                    self::col('short', 'Low stock'), self::col('unit_cost', 'Unit cost', 'money'),
                    self::col('stock_value', 'Stock value', 'money'), self::col('shortage_value', 'Shortage value', 'money'),
                ], $profiles->map(fn (InventoryProfile $p) => [
                    'code' => $p->code, 'item' => $p->item, 'unit' => $p->unit ?? '',
                    'base' => (float) ($p->base_stock ?? $p->stock ?? 0), 'used' => $p->usageTotal(), 'current' => $p->currentStock(),
                    'shortage_qty' => max(0.0, -$p->currentStock()), 'short' => $p->isShort() ? 'SHORTAGE' : 'No',
                    'unit_cost' => (float) $p->unit_cost, 'stock_value' => $p->stockValue(), 'shortage_value' => $p->shortageValue(),
                ])->all(), [
                    'used' => round($profiles->sum(fn (InventoryProfile $p) => $p->usageTotal()), 3),
                    'stock_value' => $sum(fn (InventoryProfile $p) => $p->stockValue()),
                    'shortage_value' => $sum(fn (InventoryProfile $p) => $p->shortageValue()),
                ], 'No matching profiles.'),
                self::section('Project usage', 'Usage', [
                    self::col('code', 'Profile code'), self::col('project_id', 'Project ID'), self::col('project', 'Project · Customer', 'text', true),
                    self::col('qty', 'Quantity', 'number'), self::col('state', 'Project'),
                ], $usageRows, null, 'No project usage recorded for these profiles.'),
                self::section('Stock movements', 'Movements', [
                    self::col('date', 'Date', 'date'), self::col('code', 'Profile code'), self::col('qty', 'Quantity added', 'number'),
                    self::col('unit', 'Unit'), self::col('reference', 'Reference'), self::col('source', 'Source'),
                    self::col('remarks', 'Remarks', 'text', true), self::col('balance', 'Balance after', 'number'),
                ], $movements->map(fn (StockMovement $m) => [
                    'date' => self::ymd($m->movement_date), 'code' => $codes[$m->profile_id] ?? '', 'qty' => (float) $m->qty, 'unit' => $m->unit ?? '',
                    'reference' => $m->reference ?? '', 'source' => $m->source ?? '', 'remarks' => $m->remarks ?? '', 'balance' => $m->balance_after,
                ])->all(), null, 'No stock movements recorded for these profiles.'),
            ],
            'notes' => $notes,
        ];
    }

    // ── E. Receivables ─────────────────────────────────────────────────

    private static function receivables(array $params): array
    {
        $q = $params['q'] ?? '';
        $filter = $params['filter'] ?? 'all';
        // Same projects as the Receivables page (Order::isReceivable), same search and filter.
        $orders = self::allOrders()->filter->isReceivable()
            ->filter(fn (Order $o) => $filter === 'all' || ($filter === 'completed' ? $o->isCompleted() : ! $o->isCompleted()))
            ->filter(fn (Order $o) => self::matches($q, $o->customer, $o->project, $o->phone, $o->code))
            ->values();
        $rows = $orders->map->receivableSummary();
        $sum = fn (string $k) => round($rows->sum($k), 2);

        return [
            'title' => 'Receivables Report',
            'description' => 'Projects with payment still due: amounts, receipts and outstanding balances.',
            'filters' => self::filterList($q, ['Show' => $filter === 'all' ? 'All with money due' : ($filter === 'completed' ? 'Completed only' : 'Active (not completed)')]),
            'summary' => [
                self::stat('Projects with money due', $rows->count()),
                self::stat('Invoice value', $sum('invoice'), 'money'),
                self::stat('Received', $sum('received'), 'money'),
                self::stat('Total outstanding', $sum('outstanding'), 'money'),
                self::stat('Completed, still due', $rows->where('completed', true)->count()),
            ],
            'sections' => [
                self::section('Receivables', 'Receivables', [
                    self::col('id', 'Project ID'), self::col('customer', 'Customer'), self::col('project', 'Project'), self::col('phone', 'Phone'),
                    self::col('confirmed', 'Confirmed', 'date'), self::col('invoice', 'Project amount', 'money'), self::col('advance', 'Advance', 'money'),
                    self::col('partial', 'Other payments', 'money'), self::col('refunded', 'Refunded', 'money'), self::col('received', 'Received (net)', 'money'),
                    self::col('outstanding', 'Outstanding', 'money'), self::col('payment_status', 'Payment status'), self::col('status', 'Project status'),
                ], $orders->map(fn (Order $o) => array_intersect_key($o->receivableSummary(), array_flip(['id', 'customer', 'project', 'phone', 'confirmed', 'invoice', 'advance', 'partial', 'refunded', 'received', 'outstanding', 'payment_status']))
                    + ['status' => $o->projectStatusLabel()])->all(),
                    ['invoice' => $sum('invoice'), 'advance' => $sum('advance'), 'partial' => $sum('partial'), 'refunded' => $sum('refunded'), 'received' => $sum('received'), 'outstanding' => $sum('outstanding')],
                    'No projects match this search or filter.'),
                self::paymentHistory($orders),
            ],
        ];
    }

    // ── F. Archive ─────────────────────────────────────────────────────

    private static function archive(array $params): array
    {
        $q = $params['q'] ?? '';
        // Same projects and order as the Archive page (Order::isArchived, newest archived first), same search.
        // Oldest first before sorting, as ArchiveController, so ties keep the page's order.
        $orders = Order::with('receipts')->orderBy('id')->get()->filter->isArchived()
            ->sortByDesc(fn (Order $o) => $o->completed_at?->getTimestamp() ?? 0)
            ->filter(fn (Order $o) => self::matches($q, $o->code, $o->customer, $o->project, $o->phone))
            ->values();
        $rows = $orders->map(fn (Order $o) => [
            'id' => $o->code, 'customer' => OrderManagement::upper($o->customer), 'project' => OrderManagement::upper($o->project), 'confirmed' => self::ymd($o->confirmed),
            'archived_on' => OrderManagement::iso($o->completed_at), 'outcome' => $o->isAborted() ? 'Aborted' : 'Completed – fully paid',
            'payment_status' => $o->paymentStatus(), 'invoice' => $o->invoiceAmount(), 'payments' => $o->paymentsAmount(),
            'refunded' => $o->refundedAmount(), 'refund_status' => $o->isAborted() ? $o->refundStatus() : '—',
            'received' => $o->receivedAmount(), 'write_off' => $o->abortWriteOff(), 'outstanding' => $o->outstanding(),
        ]);
        $sum = fn (string $k) => round($rows->sum($k), 2);

        return [
            'title' => 'Archive Report',
            'description' => 'Archived projects: PROJECT ABORT, or PROJECT COMPLETED and fully paid.',
            'filters' => self::filterList($q, []),
            'summary' => [
                self::stat('Archived projects', $rows->count()),
                self::stat('Completed – fully paid', $orders->reject->isAborted()->count()),
                self::stat('Aborted', $orders->filter->isAborted()->count()),
                self::stat('Invoice value', $sum('invoice'), 'money'),
                self::stat('Received (net)', $sum('received'), 'money'),
            ],
            'sections' => [
                self::section('Archived projects', 'Archive', [
                    self::col('id', 'Project ID'), self::col('customer', 'Customer'), self::col('project', 'Project'),
                    self::col('confirmed', 'Confirmed', 'date'), self::col('archived_on', 'Archived on', 'datetime'), self::col('outcome', 'Outcome'),
                    self::col('payment_status', 'Payment status'), self::col('invoice', 'Project amount', 'money'), self::col('payments', 'Payments', 'money'),
                    self::col('refunded', 'Refunded', 'money'), self::col('refund_status', 'Refund status'), self::col('received', 'Received (net)', 'money'),
                    self::col('write_off', 'Abort write-off', 'money'), self::col('outstanding', 'Outstanding', 'money'),
                ], $rows->all(), [
                    'invoice' => $sum('invoice'), 'payments' => $sum('payments'), 'refunded' => $sum('refunded'),
                    'received' => $sum('received'), 'write_off' => $sum('write_off'), 'outstanding' => $sum('outstanding'),
                ], 'No archived projects match this search.'),
            ],
        ];
    }

    // ── G. Analytics ───────────────────────────────────────────────────

    private static function analytics(): array
    {
        $orders = self::allOrders();
        $inventory = self::allInventory();
        $active = $orders->reject->isCompleted();
        $owing = $orders->filter(fn (Order $o) => $o->outstanding() > 0)->sortByDesc(fn (Order $o) => $o->outstanding())->values();
        $byMonth = $orders->filter(fn (Order $o) => $o->confirmed)
            ->groupBy(fn (Order $o) => $o->confirmed->format('Y-m'))->sortKeys()
            ->map(fn (Collection $g, string $ym) => [
                'month' => Carbon::createFromFormat('Y-m-d', $ym.'-01')->format('M Y'), 'orders' => $g->count(),
                'sft' => round($g->sum(fn (Order $o) => (float) $o->sft), 3), 'invoice' => self::sumMoney($g, fn (Order $o) => $o->invoiceAmount()),
                'received' => self::sumMoney($g, fn (Order $o) => $o->receivedAmount()), 'outstanding' => self::sumMoney($g, fn (Order $o) => $o->outstanding()),
            ])->values();
        $byPayment = $orders->groupBy(fn (Order $o) => $o->paymentStatus())->map(fn (Collection $g, string $s) => [
            'status' => $s, 'count' => $g->count(), 'invoice' => self::sumMoney($g, fn (Order $o) => $o->invoiceAmount()),
            'outstanding' => self::sumMoney($g, fn (Order $o) => $o->outstanding()),
        ])->sortByDesc('count')->values();
        $total = fn (Collection $rows, string $k) => round($rows->sum($k), 2);

        return [
            'title' => 'Analytics Report',
            'description' => 'Order, receipt and inventory summaries across all saved records.',
            'filters' => [['label' => 'Period', 'value' => 'All records (the Analytics page has no date or status filters)']],
            'summary' => [
                self::stat('Total orders', $orders->count()),
                self::stat('Active projects', $active->count()),
                self::stat('Invoice value', self::sumMoney($orders, fn (Order $o) => $o->invoiceAmount()), 'money'),
                self::stat('Total received', self::sumMoney($orders, fn (Order $o) => $o->receivedAmount()), 'money'),
                self::stat('Outstanding', self::sumMoney($orders, fn (Order $o) => $o->outstanding()), 'money'),
                self::stat('Existing stock value', self::sumMoney($inventory, fn (InventoryProfile $p) => $p->stockValue()), 'money'),
                self::stat('Shortage purchase value', self::sumMoney($inventory, fn (InventoryProfile $p) => $p->shortageValue()), 'money'),
            ],
            'sections' => [
                self::section('Orders by confirmed month', 'By month', [
                    self::col('month', 'Month'), self::col('orders', 'Orders', 'number'), self::col('sft', 'SFT', 'number'),
                    self::col('invoice', 'Invoice value', 'money'), self::col('received', 'Received', 'money'), self::col('outstanding', 'Outstanding', 'money'),
                ], $byMonth->all(), [
                    'orders' => $byMonth->sum('orders'), 'sft' => round($byMonth->sum('sft'), 3), 'invoice' => $total($byMonth, 'invoice'),
                    'received' => $total($byMonth, 'received'), 'outstanding' => $total($byMonth, 'outstanding'),
                ], 'No orders yet.'),
                self::section('Payment status breakdown', 'Payment status', [
                    self::col('status', 'Payment status'), self::col('count', 'Projects', 'number'),
                    self::col('invoice', 'Invoice value', 'money'), self::col('outstanding', 'Outstanding', 'money'),
                ], $byPayment->all(), ['count' => $byPayment->sum('count'), 'invoice' => $total($byPayment, 'invoice'), 'outstanding' => $total($byPayment, 'outstanding')], 'No orders yet.'),
                self::section('Outstanding by project', 'Outstanding', [
                    self::col('id', 'Project ID'), self::col('customer', 'Customer'), self::col('project', 'Project'), self::col('outstanding', 'Outstanding', 'money'),
                ], $owing->map(fn (Order $o) => ['id' => $o->code, 'customer' => OrderManagement::upper($o->customer), 'project' => OrderManagement::upper($o->project), 'outstanding' => $o->outstanding()])->all(),
                    ['outstanding' => self::sumMoney($owing, fn (Order $o) => $o->outstanding())], 'No outstanding balances.'),
                self::readinessSection($active, 'Readiness (active projects)'),
            ],
        ];
    }

    // ── H. Business Dashboard, Partners, Fenestro Leads ────────────────

    private static function business(): array
    {
        $leads = self::allLeads();
        $partners = User::where('role', 'referral_partner')->get(['id', 'name', 'company', 'is_active']);
        $measurements = Measurement::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $byPartner = $leads->groupBy('referral_partner_id')->map(fn (Collection $g) => [
            'partner' => $g->first()->partner?->name ?? '—', 'company' => $g->first()->partner?->company ?? '',
            'leads' => $g->count(), 'completed' => $g->filter(fn (Lead $l) => self::leadStatus($l) === 'measured')->count(),
        ])->sortByDesc('leads')->values();
        $count = fn (string $s) => $leads->filter(fn (Lead $l) => self::leadStatus($l) === $s)->count();

        return [
            'title' => 'Business Dashboard Report',
            'description' => 'Fenestro pipeline: leads, partners and measurements.',
            'filters' => [],
            'summary' => [
                self::stat('Total leads', $leads->count()),
                self::stat('New leads', $count('new')),
                self::stat('In progress', $count('in_progress')),
                self::stat('Measured (full)', $count('measured')),
                self::stat('Closed (no response)', $count('closed')),
                self::stat('Archived (aborted)', $count('archived')),
                self::stat('B2B leads', $leads->where('type', 'B2B')->count()),
                self::stat('B2C leads', $leads->where('type', 'B2C')->count()),
                self::stat('Leads in the last 7 days', $leads->filter(fn (Lead $l) => $l->created_at && $l->created_at->gte(now()->subDays(7)))->count()),
                self::stat('Approved partners', $partners->filter(fn (User $u) => self::partnerStatus($u) === 'approved')->count()),
                self::stat('Pending partners', $partners->filter(fn (User $u) => self::partnerStatus($u) === 'pending')->count()),
                self::stat('Measurements submitted', (int) ($measurements['submitted'] ?? 0)),
                self::stat('Measurements in draft', (int) ($measurements['draft'] ?? 0)),
            ],
            'sections' => [
                self::section('Leads by partner', 'By partner', [
                    self::col('partner', 'Partner'), self::col('company', 'Company'), self::col('leads', 'Leads', 'number'), self::col('completed', 'Measured', 'number'),
                ], $byPartner->all(), ['leads' => $byPartner->sum('leads'), 'completed' => $byPartner->sum('completed')], 'No leads yet.'),
            ],
        ];
    }

    private static function partners(array $params): array
    {
        $q = $params['q'] ?? '';
        $status = $params['status'] ?? 'all';
        $leadCounts = Lead::query()->selectRaw('referral_partner_id, count(*) as n')->groupBy('referral_partner_id')->pluck('n', 'referral_partner_id');
        // Same search as the Partners page: name, phone or company.
        $partners = User::where('role', 'referral_partner')->orderByDesc('created_at')
            ->get(['id', 'name', 'phone', 'company', 'is_active', 'created_at'])
            ->filter(fn (User $u) => $status === 'all' || self::partnerStatus($u) === $status)
            ->filter(fn (User $u) => self::matches($q, $u->name, $u->phone, $u->company))
            ->values();

        return [
            'title' => 'Partners Report',
            'description' => 'Referral partners, their approval status and leads referred.',
            'filters' => self::filterList($q, ['Status' => $status]),
            'summary' => [
                self::stat('Partners', $partners->count()),
                self::stat('Approved', $partners->filter(fn (User $u) => self::partnerStatus($u) === 'approved')->count()),
                self::stat('Pending', $partners->filter(fn (User $u) => self::partnerStatus($u) === 'pending')->count()),
                self::stat('Rejected', $partners->filter(fn (User $u) => self::partnerStatus($u) === 'rejected')->count()),
            ],
            'sections' => [
                self::section('Partners', 'Partners', [
                    self::col('name', 'Name'), self::col('company', 'Company'), self::col('phone', 'Phone'),
                    self::col('status', 'Status'), self::col('joined', 'Registered', 'date'), self::col('leads', 'Leads referred', 'number'),
                ], $partners->map(fn (User $u) => [
                    'name' => $u->name, 'company' => $u->company ?? '', 'phone' => $u->phone ?? '', 'status' => ucfirst(self::partnerStatus($u)),
                    'joined' => self::ymd($u->created_at), 'leads' => (int) ($leadCounts[$u->id] ?? 0),
                ])->all(), ['leads' => $partners->sum(fn (User $u) => (int) ($leadCounts[$u->id] ?? 0))], 'No partners match this search or status.'),
            ],
        ];
    }

    private static function leads(array $params): array
    {
        $status = $params['status'] ?? 'active';
        $label = ['active' => 'All active', 'new' => 'New', 'in_progress' => 'In progress', 'measured' => 'Measured (full)', 'closed' => 'Closed', 'archived' => 'Archived'];
        $now = now();
        $leads = self::allLeads()->filter(fn (Lead $l) => match ($status) {
            'all' => true,
            'active' => $l->lifecycle === LeadWorkflow::ACTIVE,
            default => self::leadStatus($l) === $status,
        })->values();

        return [
            'title' => 'Fenestro Leads Report',
            'description' => 'Customer leads with status, Red/Amber/Green, assignment and schedule.',
            'filters' => $status === 'all' ? [] : [['label' => 'List', 'value' => $label[$status]]],
            'summary' => [
                self::stat('Leads', $leads->count()),
                ...array_map(fn ($k) => self::stat($label[$k], $leads->filter(fn (Lead $l) => self::leadStatus($l) === $k)->count()), ['new', 'in_progress', 'measured', 'closed', 'archived']),
            ],
            'sections' => [
                self::section('Leads', 'Leads', [
                    self::col('lead_no', 'Lead ID'), self::col('date', 'Received', 'date'), self::col('client', 'Client'), self::col('phone', 'Phone'), self::col('type', 'Type'),
                    self::col('partner', 'Referred by'), self::col('assigned', 'Assigned to'), self::col('status', 'Status'), self::col('rag', 'RAG'),
                    self::col('scheduled', 'Scheduled'), self::col('notes', 'Notes', 'text', true),
                ], $leads->map(function (Lead $l) use ($now) {
                    $rag = LeadWorkflow::rag($l, $now);

                    return [
                        'lead_no' => $l->lead_no ?? '', 'date' => self::ymd($l->created_at), 'client' => $l->client_name, 'phone' => $l->client_phone ?? '', 'type' => $l->type ?? '',
                        'partner' => trim(($l->partner?->name ?? '').($l->partner?->company ? ' ('.$l->partner->company.')' : '')) ?: '—',
                        'assigned' => $l->assigned_to ?? '—', 'status' => $l->status_code.' — '.LeadWorkflow::label($l->status_code),
                        'rag' => ucfirst($rag['rag']).($rag['message'] ? ' — '.$rag['message'] : ''),
                        'scheduled' => LeadWorkflow::local($l->scheduled_at, 'd M Y h:i A') ?? '', 'notes' => $l->notes ?? '',
                    ];
                })->all(), null, 'No leads in this list.'),
            ],
            'notes' => ['Red/Amber/Green is calculated on the server from the stored dates (working days Monday–Friday, company holidays excluded).'],
        ];
    }

    // ── Shared pieces ──────────────────────────────────────────────────

    /** All orders with their receipts (one query each), newest first like the dashboard. */
    private static function allOrders(): Collection
    {
        return Order::with('receipts')->orderByDesc('id')->get();
    }

    private static function allInventory(): Collection
    {
        return InventoryProfile::with('usages')->orderBy('id')->get();
    }

    private static function allLeads(): Collection
    {
        return Lead::with(['partner:id,name,company', 'measurement:id,lead_id,status'])->latest()->get();
    }

    /** Lead list: new / in_progress / measured (active), closed or archived (LeadWorkflow). */
    private static function leadStatus(Lead $lead): string
    {
        return LeadWorkflow::tab($lead);
    }

    /** As on the Partners page: is_active null = pending, 1 = approved, otherwise rejected. */
    private static function partnerStatus(User $user): string
    {
        if ($user->is_active === null) {
            return 'pending';
        }

        return (int) $user->is_active === 1 ? 'approved' : 'rejected';
    }

    /** Payment and refund receipts of the given orders, in date order per project. */
    private static function paymentHistory(Collection $orders): array
    {
        $rows = [];
        foreach ($orders as $o) {
            foreach ($o->receiptsByDate() as $r) {
                /** @var OrderReceipt $r */
                if ((float) $r->amount == 0.0) {
                    continue;
                }
                $rows[] = [
                    'id' => $o->code, 'customer' => OrderManagement::upper($o->customer), 'date' => self::ymd($r->date),
                    'kind' => $r->isRefund() ? 'Refund' : 'Payment', 'amount' => $r->isRefund() ? -(float) $r->amount : (float) $r->amount,
                    'notes' => $r->notes ?? '',
                ];
            }
        }

        return self::section('Payment history', 'Payments', [
            self::col('id', 'Project ID'), self::col('customer', 'Customer'), self::col('date', 'Date', 'date'),
            self::col('kind', 'Type'), self::col('amount', 'Amount', 'money'), self::col('notes', 'Note', 'text', true),
        ], $rows, ['amount' => round(array_sum(array_column($rows, 'amount')), 2)], 'No payments recorded for these projects.');
    }

    /** Pending / done count per CNS status column (PROJ STATUS COMM excluded, as on Analytics). */
    private static function readinessSection(Collection $orders, string $title): array
    {
        $rows = [];
        foreach (OrderManagement::statuses() as $key => $s) {
            if ($key === 'comm') {
                continue;
            }
            $pending = $orders->filter(fn (Order $o) => ! self::isDone($key, OrderManagement::normaliseStatuses($o->statuses ?? [])[$key] ?? $s['default']))->count();
            $rows[] = ['status' => $s['label'], 'pending' => $pending, 'done' => $orders->count() - $pending];
        }

        return self::section($title, 'Readiness', [
            self::col('status', 'Status'), self::col('pending', 'Pending', 'number'), self::col('done', 'Done', 'number'),
        ], $orders->isEmpty() ? [] : $rows, null, 'No projects.');
    }

    private static function isDone(string $key, ?string $value): bool
    {
        return in_array($value, self::DONE_VALUES, true) || in_array($value, config("cns.statuses.$key.done", []), true);
    }

    /** Status value as shown to people (legacy values such as NO read as YET TO SHARE). */
    private static function statusLabel(string $key, ?string $value): string
    {
        return (string) (config("cns.statuses.$key.legacy_labels.$value") ?? $value ?? '');
    }

    /** PROJ STATUS COMM colour rule (green for 4 days after selection, then red) in words. */
    private static function followUp(Order $o): string
    {
        $value = $o->statuses['comm'] ?? null;
        if (! $value || in_array($value, OrderManagement::commUnsetValues(), true)) {
            return 'Not shared yet';
        }
        if ($o->isCompleted()) {
            return 'Closed';
        }
        $until = $o->commFreshUntil();

        // Red after 4 days without a newer status.
        return $until && now()->lt($until) ? 'On track to '.$until->format('d M Y') : 'Follow-up due';
    }

    private static function usageOrder(Collection $orders, string $key): ?Order
    {
        $k = mb_strtolower($key);

        return $orders->first(fn (Order $o) => in_array($k, [mb_strtolower($o->code), mb_strtolower((string) $o->project), mb_strtolower((string) $o->customer)], true));
    }

    /** Case-insensitive "contains" over the given fields, like the pages' search boxes. */
    private static function matches(string $q, ?string ...$fields): bool
    {
        $q = trim(mb_strtolower($q));

        return $q === '' || str_contains(mb_strtolower(implode(' ', array_map(fn ($f) => (string) $f, $fields))), $q);
    }

    private static function filterList(string $q, array $selects): array
    {
        $out = [];
        if (trim($q) !== '') {
            $out[] = ['label' => 'Search', 'value' => trim($q)];
        }
        foreach ($selects as $label => $value) {
            if ($value !== 'all') {
                $out[] = ['label' => $label, 'value' => self::FILTER_LABELS[$value] ?? $value];
            }
        }

        return $out;
    }

    private static function sumMoney(Collection $items, callable $f): float
    {
        return round($items->sum(fn ($i) => (float) $f($i)), 2);
    }

    private static function stat(string $label, int|float|string $value, string $format = 'number'): array
    {
        return ['label' => $label, 'value' => $value, 'format' => $format];
    }

    /**
     * 'proper' marks columns of names (customer, project, partner…): the website
     * and Excel show them as stored/upper case, the PDF in proper case.
     */
    private static function col(string $key, string $label, string $format = 'text', bool $wide = false): array
    {
        return ['key' => $key, 'label' => $label, 'format' => $format]
            + ($wide ? ['wide' => true] : [])
            + (in_array($key, self::NAME_COLUMNS, true) ? ['proper' => true] : []);
    }

    private static function section(string $title, string $sheet, array $columns, array $rows, ?array $totals, string $empty): array
    {
        return ['title' => $title, 'sheet' => $sheet, 'columns' => $columns, 'rows' => array_values($rows), 'totals' => $rows ? $totals : null, 'empty' => $empty];
    }

    private static function ymd($date): string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : '';
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
