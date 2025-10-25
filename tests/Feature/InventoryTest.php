<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;


    public function it_can_create_an_inventory_item()
    {
        $response = $this->post('/inventory', [
            'name' => 'Office Chair',
            'category' => 'Officeware',
            'status' => 'Good',
            'branch' => 'Quezon City',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('inventory_items', [
            'name' => 'Office Chair',
            'category' => 'Officeware',
            'status' => 'Good',
        ]);
    }


    public function it_can_update_an_inventory_item()
    {
        $item = InventoryItem::create([
            'name' => 'Laptop',
            'category' => 'Electronics',
            'status' => 'Good',
            'branch' => 'Manila',
        ]);

        $response = $this->put("/inventory/{$item->id}", [
            'status' => 'For Repair',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'status' => 'For Repair',
        ]);
    }


    public function it_can_toggle_inventory_item_status()
    {
        $item = InventoryItem::create([
            'name' => 'Projector',
            'category' => 'Electronics',
            'status' => 'Good',
            'branch' => 'Dasmariñas City Cavite',
        ]);

        $response = $this->patch("/inventory/{$item->id}/toggle-status");

        $response->assertStatus(200);
        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'status' => 'For Repair',
        ]);
    }


    public function it_can_generate_inventory_report()
    {

        InventoryItem::factory()->count(5)->create(['status' => 'Good']);
        InventoryItem::factory()->count(3)->create(['status' => 'For Repair']);

        $response = $this->get('/inventory/report?start_date=2023-01-01&end_date=2023-12-31');

        $response->assertStatus(200);
        $this->assertSee('Inventory Report');
        $this->assertSee('Total Items: 8');
    }


    public function it_can_view_inventory_items()
    {
        InventoryItem::factory()->create([
            'name' => 'Desk',
            'category' => 'Officeware',
            'status' => 'Good',
            'branch' => 'San Fernando City La Union',
        ]);

        $response = $this->get('/inventory');

        $response->assertStatus(200);
        $response->assertSee('Desk');
    }
}