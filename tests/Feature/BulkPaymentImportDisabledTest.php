<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BulkPaymentImportDisabledTest extends TestCase
{
    public function test_bulk_payment_import_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('payments.bulk-import'));

        $this->post('/payments/bulk-import')->assertNotFound();
    }
}
