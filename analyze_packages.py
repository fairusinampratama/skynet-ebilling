
import json

try:
    with open('final_customer_data.json', 'r') as f:
        data = json.load(f)

    packages = {}

    for customer in data:
        pkg_name = customer.get('package')
        if not pkg_name:
            continue
            
        if pkg_name not in packages:
            packages[pkg_name] = {
                'name': pkg_name,
                'price': customer.get('price', 0),
                'bandwidth': customer.get('bandwidth', ''),
                'count': 0
            }
        packages[pkg_name]['count'] += 1

    php_content = """<?php

namespace Database\\Seeders;

use Illuminate\\Database\\Seeder;
use App\\Models\\Package;

class ImportPackagesSeeder extends Seeder
{
    public function run()
    {
        $packages = [
"""

    for pkg in packages.values():
        php_content += f"            ['name' => '{pkg['name']}', 'price' => {pkg['price']}, 'bandwidth_label' => '{pkg['bandwidth'] or 'N/A'}'],\n"

    php_content += """        ];

        foreach ($packages as $pkg) {
            Package::firstOrCreate(
                ['name' => $pkg['name']],
                [
                    'price' => $pkg['price'], 
                    'bandwidth_label' => $pkg['bandwidth_label']
                ]
            );
        }
    }
}
"""

    with open('database/seeders/ImportPackagesSeeder.php', 'w') as f:
        f.write(php_content)

    print("Successfully generated database/seeders/ImportPackagesSeeder.php")

except Exception as e:
    print(f"Error: {e}")
