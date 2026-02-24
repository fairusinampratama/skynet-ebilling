<?php
// parse_xls.php
$html = file_get_contents('Data Pelanggan Dikara backup.xls');
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);

$rows = $xpath->query('//table[@id="example1"]/tbody/tr');

$customers = [];
foreach ($rows as $row) {
    $cols = $xpath->query('td', $row);
    if ($cols->length < 36) continue;

    $priceStr = trim($cols->item(18)->textContent);
    $price = (int) preg_replace('/[^0-9]/', '', $priceStr);

    $coords = trim($cols->item(35)->textContent);
    $lat = null;
    $lng = null;
    if ($coords && str_contains($coords, ',')) {
        list($l1, $l2) = explode(',', $coords);
        $lat = (float) trim($l1);
        $lng = (float) trim($l2);
    }

    $status = trim($cols->item(34)->textContent);
    $statusText = 'active';
    if (strtolower($status) === 'off') {
        $statusText = 'suspended';
    }

    $customers[] = [
        'internal_id' => trim($cols->item(1)->textContent),
        'code' => trim($cols->item(3)->textContent),
        'join_date' => trim($cols->item(4)->textContent),
        'name' => trim($cols->item(5)->textContent),
        'address' => trim($cols->item(9)->textContent),
        'phone' => trim($cols->item(10)->textContent),
        'nik' => trim($cols->item(12)->textContent),
        'package' => trim($cols->item(15)->textContent),
        'bandwidth' => trim($cols->item(16)->textContent),
        'price' => $price,
        'pppoe_username' => trim($cols->item(25)->textContent),
        'pppoe_password' => trim($cols->item(26)->textContent),
        'status' => $statusText,
        'latitude' => $lat,
        'longitude' => $lng,
    ];
}

file_put_contents('final_customer_data.json', json_encode($customers, JSON_PRETTY_PRINT));
echo "Successfully exported " . count($customers) . " records to final_customer_data.json\n";
