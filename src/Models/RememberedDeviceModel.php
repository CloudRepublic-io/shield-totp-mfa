<?php

declare(strict_types=1);

namespace TotpMfa\Models;

use CodeIgniter\Model;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string       $selector
 * @property string       $hashed_validator
 * @property string|null  $device_name
 * @property string|null  $ip_address
 * @property string|null  $user_agent
 * @property string|null  $last_used_at
 * @property string       $expires_at
 */
class RememberedDeviceModel extends Model
{
    protected $table          = 'auth_remembered_devices';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $allowedFields  = [
        'user_id',
        'selector',
        'hashed_validator',
        'device_name',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
    ];

    /**
     * Find a non-expired device by its selector (the public half of the
     * remember-device cookie). Does NOT check the validator - callers
     * must hash_equals() the validator themselves after fetching, to
     * keep the comparison constant-time and out of the query.
     */
    public function findActiveBySelector(string $selector): ?array
    {
        $device = $this->where('selector', $selector)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->first();

        return $device ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->where('user_id', $userId)
            ->orderBy('last_used_at', 'DESC')
            ->findAll();
    }

    public function touch(int $id, ?string $newHashedValidator = null): void
    {
        $data = ['last_used_at' => date('Y-m-d H:i:s')];

        if ($newHashedValidator !== null) {
            $data['hashed_validator'] = $newHashedValidator;
        }

        $this->update($id, $data);
    }

    public function purgeExpired(): void
    {
        $this->where('expires_at <', date('Y-m-d H:i:s'))->delete();
    }
}
