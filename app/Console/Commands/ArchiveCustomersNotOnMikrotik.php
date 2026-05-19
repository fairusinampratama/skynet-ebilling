<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArchiveCustomersNotOnMikrotik extends Command
{
    protected $signature = 'customers:archive-not-on-mikrotik
                            {--apply : Soft-delete archive candidates}
                            {--backup-confirmed : Confirm a database backup exists before applying}
                            {--timeout=10 : MikroTik connection timeout in seconds}';

    protected $description = 'Archive eBilling customers whose PPPoE username is absent from live MikroTik routers';

    public function handle(MikrotikService $mikrotik): int
    {
        $apply = (bool) $this->option('apply');
        $backupConfirmed = (bool) $this->option('backup-confirmed');
        $timeout = max(1, (int) $this->option('timeout'));

        if ($apply && ! $backupConfirmed) {
            $this->error('Refusing to apply: pass --backup-confirmed after taking a production database backup.');
            return self::FAILURE;
        }

        $routers = Router::where('is_active', true)->orderBy('name')->get();
        if ($routers->isEmpty()) {
            $this->warn('No active routers found. Archive aborted.');
            return self::FAILURE;
        }

        $audit = $this->readMikrotikSecrets($routers, $mikrotik, $timeout);

        if ($audit['failed_routers']->isNotEmpty()) {
            $this->error('Archive aborted because one or more routers could not be audited.');
            $this->table(['Router', 'IP', 'Error'], $audit['failed_routers']->all());
            return self::FAILURE;
        }

        $duplicateUsernames = $audit['all_secrets']
            ->groupBy('pppoe_user')
            ->filter(fn (Collection $secrets) => $secrets->pluck('router_id')->unique()->count() > 1)
            ->keys()
            ->values();

        if ($duplicateUsernames->isNotEmpty()) {
            $this->error('Archive aborted because duplicate MikroTik PPPoE usernames exist across routers.');
            $this->line($duplicateUsernames->implode(', '));
            return self::FAILURE;
        }

        $mikrotikUsernames = $audit['all_secrets']->pluck('pppoe_user')->unique()->values();
        $disabledUsernames = $audit['all_secrets']
            ->where('disabled', true)
            ->pluck('pppoe_user')
            ->unique()
            ->values();

        $customers = Customer::ebilling()
            ->with('router:id,name')
            ->select('id', 'code', 'name', 'pppoe_user', 'router_id', 'status')
            ->get();

        $candidates = $customers
            ->filter(fn (Customer $customer) => ! $this->validUsername($customer->pppoe_user)
                || ! $mikrotikUsernames->contains($customer->pppoe_user))
            ->values();
        $matchedCustomers = $customers
            ->filter(fn (Customer $customer) => $this->validUsername($customer->pppoe_user)
                && $mikrotikUsernames->contains($customer->pppoe_user))
            ->values();
        $disabledMatches = $matchedCustomers
            ->filter(fn (Customer $customer) => $disabledUsernames->contains($customer->pppoe_user))
            ->count();

        $this->printSummary($routers, $audit['router_rows'], $audit['all_secrets'], $customers, $matchedCustomers, $disabledMatches, $candidates);

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply --backup-confirmed to soft-delete archive candidates.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $candidateIds = $candidates->pluck('id')->all();

        Customer::ebilling()
            ->whereKey($candidateIds)
            ->with('router:id,name')
            ->orderBy('id')
            ->chunkById(100, function ($customers) use (&$deleted) {
                DB::transaction(function () use ($customers, &$deleted) {
                    foreach ($customers as $customer) {
                        activity()
                            ->performedOn($customer)
                            ->withProperties([
                                'reason' => 'not_on_live_mikrotik',
                                'pppoe_user' => $customer->pppoe_user,
                                'router' => $customer->router?->name,
                                'status' => $customer->status,
                            ])
                            ->log('archived_not_on_mikrotik');

                        $customer->delete();
                        $deleted++;
                    }
                });
            });

        $this->info("Soft-deleted archive candidates: {$deleted}");

        return self::SUCCESS;
    }

    private function readMikrotikSecrets(Collection $routers, MikrotikService $mikrotik, int $timeout): array
    {
        $allSecrets = collect();
        $routerRows = [];
        $failedRouters = collect();

        foreach ($routers as $router) {
            $this->line("Auditing {$router->name} ({$router->ip_address})...");

            try {
                $mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
                $secrets = collect($mikrotik->getPPPSecrets())
                    ->map(fn (array $secret) => $this->secretRow($router, $secret))
                    ->filter(fn (array $secret) => $this->validUsername($secret['pppoe_user']))
                    ->values();
            } catch (\Throwable $e) {
                $failedRouters->push([$router->name, $router->ip_address, $e->getMessage()]);
                continue;
            } finally {
                $mikrotik->disconnect();
            }

            $routerRows[] = [
                $router->name,
                $router->ip_address,
                $secrets->count(),
                $secrets->where('disabled', false)->count(),
                $secrets->where('disabled', true)->count(),
            ];

            $allSecrets = $allSecrets->merge($secrets);
        }

        return [
            'all_secrets' => $allSecrets,
            'router_rows' => $routerRows,
            'failed_routers' => $failedRouters,
        ];
    }

    private function printSummary(
        Collection $routers,
        array $routerRows,
        Collection $allSecrets,
        Collection $customers,
        Collection $matchedCustomers,
        int $disabledMatches,
        Collection $candidates
    ): void {
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Routers audited', $routers->count()],
                ['eBilling customers total', $customers->count()],
                ['MikroTik PPPoE total', $allSecrets->count()],
                ['MikroTik enabled total', $allSecrets->where('disabled', false)->count()],
                ['MikroTik disabled total', $allSecrets->where('disabled', true)->count()],
                ['Matched kept', $matchedCustomers->count()],
                ['Disabled matches kept', $disabledMatches],
                ['Archive candidates', $candidates->count()],
            ]
        );

        $this->newLine();
        $this->table(['Router', 'IP', 'PPP Total', 'Enabled', 'Disabled'], $routerRows);

        $this->newLine();
        $this->table(
            ['Status', 'Archive Candidates'],
            $candidates
                ->groupBy('status')
                ->map(fn (Collection $customers, string $status) => [$status, $customers->count()])
                ->values()
                ->all()
        );

        $this->newLine();
        $this->table(
            ['Code', 'Name', 'PPPoE', 'Router', 'Status'],
            $candidates
                ->take(50)
                ->map(fn (Customer $customer) => [
                    $customer->code,
                    $customer->name,
                    $customer->pppoe_user ?? '',
                    $customer->router?->name ?? '',
                    $customer->status,
                ])
                ->all()
        );
    }

    private function secretRow(Router $router, array $secret): array
    {
        return [
            'router_id' => $router->id,
            'pppoe_user' => (string) ($secret['name'] ?? ''),
            'disabled' => filter_var($secret['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function validUsername(mixed $username): bool
    {
        return is_string($username) && trim($username) !== '';
    }
}
