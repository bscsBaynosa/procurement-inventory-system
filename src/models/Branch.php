<?php

class Branch {
    private $id;
    private $name;
    private $location;

    public function __construct($id, $name, $location) {
        $this->id = $id;
        $this->name = $name;
        $this->location = $location;
    }

    public function getId() {
        return $this->id;
    }

    public function getName() {
        return $this->name;
    }

    public function getLocation() {
        return $this->location;
    }

    public static function getAllBranches() {
        // This method should return all branches from the database
    }

    public static function findBranchById($id) {
        // This method should find a branch by its ID from the database
    }
}