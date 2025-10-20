<?php

namespace App\Controllers;

use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Services\InventoryService;
use App\Services\RequestService;

class CustodianController
{
    protected $inventoryService;
    protected $requestService;

    public function __construct()
    {
        $this->inventoryService = new InventoryService();
        $this->requestService = new RequestService();
    }

    public function dashboard()
    {
        $inventoryStats = $this->inventoryService->getInventoryStats();
        $pendingRequests = $this->requestService->getPendingRequests();

        // Load the custodian dashboard view with inventory stats and pending requests
        require_once '../templates/dashboard/custodian.php';
    }

    public function addInventoryItem($data)
    {
        $item = new InventoryItem($data);
        $this->inventoryService->addItem($item);
        // Redirect or return response
    }

    public function updateInventoryItem($id, $data)
    {
        $item = $this->inventoryService->getItemById($id);
        $item->update($data);
        // Redirect or return response
    }

    public function toggleItemStatus($id, $status)
    {
        $this->inventoryService->updateItemStatus($id, $status);
        // Redirect or return response
    }

    public function createPurchaseRequest($data)
    {
        $request = new PurchaseRequest($data);
        $this->requestService->createRequest($request);
        // Redirect or return response
    }

    public function generateInventoryReport($startDate, $endDate)
    {
        $reportData = $this->inventoryService->getReportData($startDate, $endDate);
        // Generate PDF or return response
    }

    public function editProfile($data)
    {
        // Logic to update custodian profile
    }
}