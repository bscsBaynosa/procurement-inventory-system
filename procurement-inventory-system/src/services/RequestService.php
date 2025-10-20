<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\InventoryItem;

class RequestService
{
    public function createPurchaseRequest($data)
    {
        // Validate and create a new purchase request
        $purchaseRequest = new PurchaseRequest();
        $purchaseRequest->item_id = $data['item_id'];
        $purchaseRequest->quantity = $data['quantity'];
        $purchaseRequest->request_type = $data['request_type']; // 'job_order' or 'purchase_order'
        $purchaseRequest->status = 'pending';
        $purchaseRequest->created_at = date('Y-m-d H:i:s');
        $purchaseRequest->save();

        return $purchaseRequest;
    }

    public function getPendingRequests()
    {
        // Retrieve all pending purchase requests
        return PurchaseRequest::where('status', 'pending')->get();
    }

    public function followUpRequest($requestId)
    {
        // Logic to follow up on a request
        $purchaseRequest = PurchaseRequest::find($requestId);
        if ($purchaseRequest) {
            // Notify the procurement manager (implementation depends on notification system)
            // For example, send an email or log a message
            return true;
        }
        return false;
    }

    public function updateRequestStatus($requestId, $status)
    {
        // Update the status of a purchase request
        $purchaseRequest = PurchaseRequest::find($requestId);
        if ($purchaseRequest) {
            $purchaseRequest->status = $status;
            $purchaseRequest->save();
            return $purchaseRequest;
        }
        return null;
    }

    public function getRequestDetails($requestId)
    {
        // Retrieve details of a specific purchase request
        return PurchaseRequest::find($requestId);
    }
}