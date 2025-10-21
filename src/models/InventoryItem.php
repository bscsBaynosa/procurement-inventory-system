<?php

class InventoryItem {
    private $id;
    private $name;
    private $category;
    private $status;
    private $quantity;
    private $branch;

    public function __construct($id, $name, $category, $status, $quantity, $branch) {
        $this->id = $id;
        $this->name = $name;
        $this->category = $category;
        $this->status = $status;
        $this->quantity = $quantity;
        $this->branch = $branch;
    }

    public function getId() {
        return $this->id;
    }

    public function getName() {
        return $this->name;
    }

    public function getCategory() {
        return $this->category;
    }

    public function getStatus() {
        return $this->status;
    }

    public function getQuantity() {
        return $this->quantity;
    }

    public function getBranch() {
        return $this->branch;
    }

    public function setStatus($status) {
        $this->status = $status;
    }

    public function setQuantity($quantity) {
        $this->quantity = $quantity;
    }
}