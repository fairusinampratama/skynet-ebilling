<?php

namespace Database\Seeders;

use App\Models\Router;
use Illuminate\Database\Seeder;

class RouterSeeder extends Seeder
{
    public function run(): void
    {
        $routers = [
            ['name' => 'Skynet-Tutur', 'ip_address' => '103.156.128.231', 'port' => 8728],
            ['name' => 'Skynet-Tasikmadu-ITN', 'ip_address' => '103.156.128.226', 'port' => 8728],
            ['name' => 'Skynet-Srigading', 'ip_address' => '10.77.77.2', 'port' => 8728],
            ['name' => 'Skynet-Sentul', 'ip_address' => '10.183.10.12', 'port' => 8728],
            ['name' => 'Skynet-Ngadipuro', 'ip_address' => '103.156.128.228', 'port' => 8728],
            ['name' => 'Skynet-Metro', 'ip_address' => '172.22.254.1', 'port' => 8728],
            ['name' => 'Skynet-Martopuro', 'ip_address' => '10.182.53.2', 'port' => 8728],
            ['name' => 'Skynet-Lawang', 'ip_address' => '10.182.47.2', 'port' => 8728],
            ['name' => 'Skynet-Krian', 'ip_address' => '103.156.128.34', 'port' => 8777],
            ['name' => 'Skynet-Kendit', 'ip_address' => '10.183.10.11', 'port' => 8728],
            ['name' => 'Skynet-Kasin', 'ip_address' => '103.156.128.50', 'port' => 8728],
            ['name' => 'Skynet-KarangKunci', 'ip_address' => '10.181.34.2', 'port' => 8728],
            ['name' => 'Skynet-Bumiayu-Malang', 'ip_address' => '10.150.6.5', 'port' => 8728],
            ['name' => 'Skynet-Blitar', 'ip_address' => '10.183.10.9', 'port' => 8728],
            ['name' => 'Skynet-PPPoE Randuagung', 'ip_address' => '10.181.40.2', 'port' => 8728],
            ['name' => 'Skynet-Arjosari', 'ip_address' => '192.168.3.1', 'port' => 8728],
        ];

        foreach ($routers as $router) {
            Router::updateOrCreate(
                ['name' => $router['name']],
                [
                    'ip_address' => $router['ip_address'],
                    'username' => 'skynet',
                    'password' => 'Sky@2026??',
                    'port' => $router['port'],
                    'is_active' => true,
                ]
            );
        }
    }
}
