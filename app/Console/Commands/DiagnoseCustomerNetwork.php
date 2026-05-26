<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\MikrotikService;
use App\Support\NetworkProfiles;
use Illuminate\Console\Command;

class DiagnoseCustomerNetwork extends Command
{
    protected $signature = 'network:diagnose-customer {customer : Customer ID, code, or PPPoE username}';

    protected $description = 'Read-only diagnosis of customer DB network state against the assigned MikroTik router';

    public function handle(MikrotikService $mikrotik): int
    {
        $customer = $this->resolveCustomer((string) $this->argument('customer'));

        if (! $customer) {
            $this->error('Customer not found.');

            return self::FAILURE;
        }

        $customer->load(['router', 'package']);

        $this->info("Customer: {$customer->name}");
        $this->line("Code: {$customer->code}");
        $this->line("Status: {$customer->status}");
        $this->line("DB PPPoE: ".($customer->pppoe_user ?: 'NO_PPPOE'));
        $this->line("DB MikroTik profile: ".($customer->mikrotik_profile ?: 'NULL'));
        $this->line("DB previous profile: ".($customer->previous_profile ?: 'NULL'));
        $this->line("Package profile: ".($customer->package?->mikrotik_profile ?: 'NULL'));
        $this->line("Router: ".($customer->router?->name ?? 'NO_ROUTER'));

        if (! $customer->router || ! $customer->pppoe_user) {
            $this->warn('Cannot query router because customer is missing router or PPPoE username.');

            return self::SUCCESS;
        }

        $targetIsolationProfile = trim((string) ($customer->router->isolation_profile ?? '')) ?: 'isolirebilling';
        $this->line("Isolation target: {$targetIsolationProfile}");

        try {
            $mikrotik->connect($customer->router, ['timeout' => 10, 'attempts' => 1]);
            $secret = $mikrotik->getPPPSecret($customer->pppoe_user);
            $activeSession = collect($mikrotik->getActiveConnections())
                ->first(fn (array $session) => ($session['name'] ?? null) === $customer->pppoe_user);
        } catch (\Throwable $e) {
            $this->error("Router query failed: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $mikrotik->disconnect();
        }

        if (! $secret) {
            $this->error('Router PPP secret: NOT FOUND');

            return self::FAILURE;
        }

        $secretProfile = $secret['profile'] ?? null;
        $activeProfile = $activeSession['profile'] ?? null;

        $this->newLine();
        $this->info('Router state');
        $this->line('Secret profile: '.($secretProfile ?: 'NULL'));
        $this->line('Secret ID: '.($secret['.id'] ?? 'NULL'));
        $this->line('Active session: '.($activeSession ? 'YES' : 'NO'));
        $this->line('Active profile: '.($activeProfile ?: 'NULL'));
        $this->line('Active address: '.($activeSession['address'] ?? 'NULL'));

        $warnings = [];

        if ($customer->status === 'isolated' && ! NetworkProfiles::isIsolationLike($secretProfile)) {
            $warnings[] = 'DB says isolated, but router secret profile is not isolation-like.';
        }

        if ($customer->status === 'active' && NetworkProfiles::isIsolationLike($secretProfile)) {
            $warnings[] = 'DB says active, but router secret profile is isolation-like.';
        }

        if ($activeSession && $secretProfile && $activeProfile && strcasecmp($secretProfile, $activeProfile) !== 0) {
            $warnings[] = 'Active PPP session profile differs from PPP secret profile; kick/reconnect may still be pending.';
        }

        if ($warnings === []) {
            $this->info('Diagnosis: DB and router state look consistent.');

            return self::SUCCESS;
        }

        $this->warn('Diagnosis warnings:');
        foreach ($warnings as $warning) {
            $this->warn(" - {$warning}");
        }

        return self::SUCCESS;
    }

    private function resolveCustomer(string $identifier): ?Customer
    {
        return Customer::withTrashed()
            ->where('id', ctype_digit($identifier) ? (int) $identifier : -1)
            ->orWhere('code', $identifier)
            ->orWhere('pppoe_user', $identifier)
            ->first();
    }
}
