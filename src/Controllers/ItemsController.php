<?php
namespace App\Controllers;

use App\Controllers\BaseController;
use App\Services\ItemService;

class ItemsController extends BaseController {

    private ItemService $service;

    public function __construct() {
        $this->service = new ItemService();
    }

    public function index(): void {
        $items = $this->service->listForCurrentBusiness();
        $this->json($items);
    }

    public function store(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = $this->service->create($input);
        $this->json(['id' => $id], 201);
    }

    public function update(int $id): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $this->service->update($id, $input);
        $this->json(['success' => true]);
    }

    public function destroy(int $id): void {
        $this->service->deactivate($id);
        $this->json(['success' => true]);
    }
}
