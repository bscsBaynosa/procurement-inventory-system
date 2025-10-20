<?php

class User {
    private $id;
    private $username;
    private $password;
    private $role; // 'custodian' or 'procurement_manager'
    private $branch; // Branch associated with the user

    public function __construct($id, $username, $password, $role, $branch) {
        $this->id = $id;
        $this->username = $username;
        $this->password = $password;
        $this->role = $role;
        $this->branch = $branch;
    }

    public function getId() {
        return $this->id;
    }

    public function getUsername() {
        return $this->username;
    }

    public function getPassword() {
        return $this->password;
    }

    public function getRole() {
        return $this->role;
    }

    public function getBranch() {
        return $this->branch;
    }

    public function setUsername($username) {
        $this->username = $username;
    }

    public function setPassword($password) {
        $this->password = $password;
    }

    public function setRole($role) {
        $this->role = $role;
    }

    public function setBranch($branch) {
        $this->branch = $branch;
    }
}