<?php
	// src/Repository/AuthRepository.php
namespace App\Repository;

use PDO;

class AuthRepository {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }
	
    public function checkPassword($user, $password) {
        return password_verify($password, $user['password_hash']);
    }
	
	public function storeRefreshToken($userId, $deviceid, $tokenHash, $expiresAt): bool {
		$sql = "
			        INSERT INTO refresh_tokens (
						user_id, device_id, token_hash, expires_at, created_at
					) VALUES (
						:user_id, :device_id, :token_hash, :expires_at, NOW()
					)
					ON DUPLICATE KEY UPDATE
						token_hash = VALUES(token_hash),
						expires_at = VALUES(expires_at),
						revoked_at = NULL,
						last_used_at = NULL
		";

		$stmt = $this->db->prepare($sql);
		return $stmt->execute([
					'user_id'    => $userId,
					'device_id'  => $deviceid,
					'token_hash' => $tokenHash,
					'expires_at' => $expiresAt
				]);
	}
	
	public function findValidRefreshToken($tokenHash): ?array  {
		$sql = "
			SELECT id, user_id, device_id, token_hash, expires_at, last_used_at, revoked_at, created_at
			FROM refresh_tokens
			WHERE token_hash = :token_hash
			  AND revoked_at IS NULL
			  AND expires_at > NOW()
		";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':token_hash' => $tokenHash]);

		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}
	
	public function rotateRefreshToken($oldHash, $newHash): bool {
		$this->db->beginTransaction();

		try {
			$sql = "
				UPDATE refresh_tokens
				SET token_hash = :new_hash,
					last_used_at = NOW()
				WHERE token_hash = :old_hash
				  AND revoked_at IS NULL
				  AND expires_at > NOW()
			";

			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				'old_hash' => $oldHash,
				'new_hash' => $newHash,
			]);

			if ($stmt->rowCount() !== 1) {
				$this->db->rollBack();
				return false;
			}

			$this->db->commit(); 
			return true;

		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function revokeRefreshToken($hash): void {
		$sql = "
			UPDATE refresh_tokens
			SET revoked_at = NOW()
			WHERE token_hash = :hash
          	  AND revoked_at IS NULL
		";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':hash' => $hash]);
	}


}
