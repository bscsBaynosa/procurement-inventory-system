<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\PurchaseRequest;

class InventoryService
{
    public function getAllItems()
    {
        return InventoryItem::all();
    }

    public function getItemById($id)
    {
        return InventoryItem::find($id);
    }

    public function addItem($data)
    {
        $item = new InventoryItem();
        $item->name = $data['name'];
        $item->category = $data['category'];
        $item->status = $data['status'];
        $item->save();

        return $item;
    }

    public function updateItem($id, $data)
    {
        $item = InventoryItem::find($id);
        if ($item) {
            $item->name = $data['name'];
            $item->category = $data['category'];
            $item->status = $data['status'];
            $item->save();
        }

        return $item;
    }

    public function deleteItem($id)
    {
        $item = InventoryItem::find($id);
        if ($item) {
            $item->delete();
            return true;
        }

        return false;
    }

    public function toggleItemStatus($id, $status)
    {
        $item = InventoryItem::find($id);
        if ($item) {
            $item->status = $status;
            $item->save();
        }

        return $item;
    }

    public function createPurchaseRequest($data)
    {
        $request = new PurchaseRequest();
        $request->item_id = $data['item_id'];
        $request->type = $data['type']; // 'repair' or 'replacement'
        $request->status = 'pending';
        $request->save();

        return $request;
    }

    public function getPendingRequests()
    {
        return PurchaseRequest::where('status', 'pending')->get();
    }

    public function followUpRequest($id)
    {
        $request = PurchaseRequest::find($id);
        if ($request) {
            // Logic to notify the procurement manager
            return true;
        }

        return false;
    }
}