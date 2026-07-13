<?php

namespace Database\Seeders;

use App\Models\Router;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RouterSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('routers')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $routers = [
            ['name' => 'Demo Core Router', 'ip_address' => '192.0.2.10', 'port' => 8728],
            ['name' => 'Demo Distribution Router', 'ip_address' => '192.0.2.11', 'port' => 8728],
            ['name' => 'Demo Access Router', 'ip_address' => '192.0.2.12', 'port' => 8728],
        ];

        foreach ($routers as $router) {
            $this->command->info("Creating router: {$router['name']} ({$router['ip_address']})");
            Router::create([
                'name' => $router['name'],
                'ip_address' => $router['ip_address'],
                'username' => 'demo-router-user',
                'password' => 'replace-with-private-secret',
                'port' => $router['port'],
                'is_active' => true,
            ]);
        }
    }
}
