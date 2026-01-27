<?php
namespace App\Services;

use App\Repository\ItemRepository;
use App\Support\AuthContext;

class ItemService {
    private ItemRepository $ItemRepo;

    public function __construct() {
        $this->ItemRepo = new ItemRepository();
    }
	
	public function listForCurrentBusiness(): array {
        $ctx = AuthContext::get();

        return $this->ItemRepo->getAllForBusiness(
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    public function create(array $input): int {
        $ctx = AuthContext::get();

        $isShared = empty($input['business_id']);

        // Rule: shared item requires elevated permission
        if ($isShared && !in_array('items.create.shared', $ctx->permissions)) {
            throw new ForbiddenException('Cannot create shared item');
        }

        // Rule: business-specific must match context
        if (!$isShared && (int)$input['business_id'] !== $ctx->business_id) {
            throw new ForbiddenException('Invalid business scope');
        }
		
		if (empty(trim($input['name'] ?? ''))) {
			throw new ValidationException('Item name is required');
		}

        return $this->ItemRepo->insert([
            'tenant'      => $ctx->tenant_id,
            'business'    => $isShared ? null : $ctx->business_id,
            'type'        => $input['item_type_id'],
            'name'        => trim($input['name']),
            'sku'         => $input['sku'] ?? null,
            'unit'        => $input['unit'] ?? null,
            'cost'        => $input['cost_price'] ?? null,
            'sale'        => $input['sale_price'] ?? null,
            'notes'       => $input['notes'] ?? null,
            'created_by'  => $ctx->user_id
        ]);
    }

    public function update(int $itemId, array $input): void {
        $ctx = AuthContext::get();
        $item = $this->ItemRepo->getById($ctx->tenant_id, $itemId);

        if (!$item) {
            throw new NotFoundException('Item not found');
        }

        // Rule: cannot edit shared item without permission
        if ($item['business_id'] === null &&
            !in_array('items.edit.shared', $ctx->permissions)) {
            throw new ForbiddenException('Cannot edit shared item');
        }

        $this->ItemRepo->update($itemId, $ctx->tenant_id, [
            'name'  => $input['name'],
            'unit'  => $input['unit'],
            'cost'  => $input['cost_price'],
            'sale'  => $input['sale_price'],
            'notes' => $input['notes']
        ]);
    }

    public function deactivate(int $itemId): void {
        $ctx = AuthContext::get();
        $this->ItemRepo->deactivate($itemId, $ctx->tenant_id);
    }
	
}