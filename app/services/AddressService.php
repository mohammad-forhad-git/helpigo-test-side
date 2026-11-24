<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use DomainException;

/**
 * Customer's saved drop-off addresses (requirement CUS-01). An address can belong to someone
 * else (e.g. parents): contact name and phone are then used as the task's recipient.
 */
final class AddressService
{
    public const MAX_PER_USER = 10;

    /** @return list<array<string, mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::all(
            'SELECT a.*, z.name AS zone_name FROM addresses a JOIN zones z ON z.id = a.zone_id
             WHERE a.user_id = ? ORDER BY a.id',
            [$userId]
        );
    }

    /** The address if it belongs to this user, else null. */
    public static function find(int $userId, int $id): ?array
    {
        return Database::one(
            'SELECT a.*, z.name AS zone_name, z.is_active AS zone_active FROM addresses a JOIN zones z ON z.id = a.zone_id
             WHERE a.id = ? AND a.user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * Validates input. Returns [clean data, errors].
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $v = (new Validator($input))
            ->string('label', 'Label', 40)
            ->int('zone_id', 'Area', 1, 1_000_000)
            ->string('address_line', 'Address', 255, true, 5)
            ->string('landmark', 'Landmark', 120, false)
            ->string('contact_name', 'Contact name', 100, false)
            ->bdPhone('contact_phone', 'Contact phone', false);
        $clean = $v->clean();
        if (!$v->fails() && !CatalogService::zoneIsActive((int) $clean['zone_id'])) {
            $v->addError('zone_id', 'We do not serve this area yet.');
        }
        if (($clean['contact_name'] ?? null) !== null && ($clean['contact_phone'] ?? null) === null) {
            $v->addError('contact_phone', 'Please add a phone number for this contact.');
        }
        return [$v->clean(), $v->errors()];
    }

    public static function create(int $userId, array $clean): int
    {
        if ((int) Database::value('SELECT COUNT(*) FROM addresses WHERE user_id = ?', [$userId]) >= self::MAX_PER_USER) {
            throw new DomainException('You can save up to ' . self::MAX_PER_USER . ' addresses. Please delete one first.');
        }
        return Database::insert('addresses', ['user_id' => $userId] + self::columns($clean));
    }

    public static function update(int $userId, int $id, array $clean): void
    {
        if (self::find($userId, $id) === null) {
            throw new DomainException('Address not found.');
        }
        Database::update('addresses', self::columns($clean), 'id = :w_id AND user_id = :w_user', ['w_id' => $id, 'w_user' => $userId]);
    }

    /** Tasks keep a copy of the address text, so deleting an address never changes past tasks. */
    public static function delete(int $userId, int $id): void
    {
        Database::run('DELETE FROM addresses WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    private static function columns(array $clean): array
    {
        return [
            'label'         => $clean['label'],
            'zone_id'       => (int) $clean['zone_id'],
            'address_line'  => $clean['address_line'],
            'landmark'      => $clean['landmark'],
            'contact_name'  => $clean['contact_name'],
            'contact_phone' => $clean['contact_phone'],
        ];
    }
}
