<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\AccountTransferService;
use App\Support\AuthContext;

class AccountTransfersController extends BaseController {

    private AccountTransferService $transferService;

    public function __construct() {
        // BaseController expects no arguments in child constructors usually, or we just call parent
        $this->transferService = new AccountTransferService(Database::getConnection());
    }

    /**
     * GET /api/account-transfers
     * List all transfers
     */
    public function index() {
        try {
            $this->auth();

            $transfers = $this->transferService->getAllForBusiness();
            return $this->json(true, ['transfers' => $transfers]);
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * POST /api/account-transfers
     * Create a new transfer
     */
    public function store() {
        try {
            $this->auth();

            $data = $this->input(['from_account_id', 'to_account_id', 'amount_from', 'amount_to', 'amount_bc', 'exchange_rate', 'transfer_date']);
            
            $required = ['from_account_id', 'to_account_id', 'amount_from', 'amount_to', 'amount_bc', 'exchange_rate', 'transfer_date'];
            foreach ($required as $req) {
                if (!isset($data[$req]) || $data[$req] === '') {
                    throw new \InvalidArgumentException("Missing required field: $req");
                }
            }

            $data['reference'] = $_POST['reference'] ?? json_decode(file_get_contents('php://input'), true)['reference'] ?? null;
            $data['notes'] = $_POST['notes'] ?? json_decode(file_get_contents('php://input'), true)['notes'] ?? null;

            $transferId = $this->transferService->transferFunds($data);
            return $this->json(true, ['id' => $transferId], 'Transfer completed successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->json(false, [], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }
}
