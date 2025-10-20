<?php

namespace App\Controllers;

use App\Models\PurchaseRequest;
use App\Services\RequestService;

class ProcurementController
{
    protected $requestService;

    public function __construct()
    {
        $this->requestService = new RequestService();
    }

    public function index()
    {
        // Fetch all purchase requests
        $requests = $this->requestService->getAllRequests();
        // Load the procurement manager dashboard view with requests
        require_once '../templates/dashboard/manager.php';
    }

    public function viewRequest($id)
    {
        // Fetch a specific purchase request by ID
        $request = $this->requestService->getRequestById($id);
        // Load the request details view
        require_once '../templates/requests/show.php';
    }

    public function updateRequest($id)
    {
        // Update the purchase request based on input
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = $_POST;
            $this->requestService->updateRequest($id, $data);
            header('Location: /procurement-requests'); // Redirect to requests list
        }
    }

    public function followUpRequest($id)
    {
        // Follow up on a purchase request
        $this->requestService->followUpRequest($id);
        header('Location: /procurement-requests'); // Redirect to requests list
    }
}