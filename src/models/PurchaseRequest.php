<?php

class PurchaseRequest {
    private $id;
    private $custodianId;
    private $itemId;
    private $requestType; // 'job_order' or 'purchase_order'
    private $status; // 'pending', 'approved', 'rejected'
    private $createdAt;
    private $updatedAt;

    public function __construct($custodianId, $itemId, $requestType) {
        $this->custodianId = $custodianId;
        $this->itemId = $itemId;
        $this->requestType = $requestType;
        $this->status = 'pending';
        $this->createdAt = date('Y-m-d H:i:s');
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    public function getId() {
        return $this->id;
    }

    public function getCustodianId() {
        return $this->custodianId;
    }

    public function getItemId() {
        return $this->itemId;
    }

    public function getRequestType() {
        return $this->requestType;
    }

    public function getStatus() {
        return $this->status;
    }

    public function getCreatedAt() {
        return $this->createdAt;
    }

    public function getUpdatedAt() {
        return $this->updatedAt;
    }

    public function setStatus($status) {
        $this->status = $status;
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    public function save() {
        // Logic to save the purchase request to the database
    }

    public function update() {
        // Logic to update the purchase request in the database
    }

    public static function find($id) {
        // Logic to find a purchase request by ID
    }

    public static function all() {
        // Logic to retrieve all purchase requests
    }
}