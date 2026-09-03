<?php
/**
 * Unique Organization ID per POS store.
 * Sync uses this ID: whichever ID is connected, that store's products are pulled.
 */

declare(strict_types=1);

function pos_public_id_offset(): int
{
    return 60077667000;
}

function pos_public_user_id(int $internalUserId): string
{
    if ($internalUserId < 1) {
        return '';
    }

    return (string) (pos_public_id_offset() + $internalUserId);
}

function pos_internal_user_id_from_public(string $publicId): int
{
    $publicId = trim($publicId);
    if ($publicId === '' || ! ctype_digit($publicId) || strlen($publicId) < 10) {
        return 0;
    }

    $offset = pos_public_id_offset();
    $value = (int) $publicId;
    if ($value <= $offset) {
        return 0;
    }

    $internal = $value - $offset;

    return ($internal > 0 && $internal < 1000000) ? $internal : 0;
}

function pos_store_id_from_public_user_id(PDO $db, string $publicId): int
{
    $publicId = trim($publicId);
    if ($publicId === '') {
        return 0;
    }

    try {
        $stmt = $db->prepare('SELECT business_id FROM users WHERE CAST(public_id AS CHAR) = ? LIMIT 1');
        $stmt->execute([$publicId]);
        $bid = (int) ($stmt->fetchColumn() ?: 0);
        if ($bid > 0) {
            return $bid;
        }
    } catch (\Throwable $e) {
        // public_id column may not exist yet
    }

    $userId = pos_internal_user_id_from_public($publicId);
    if ($userId < 1) {
        return 0;
    }

    try {
        $stmt = $db->prepare('SELECT business_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $bid = (int) ($stmt->fetchColumn() ?: 0);

        return $bid > 0 ? $bid : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

function pos_resolve_store_id(PDO $db, string $orgId): int
{
    $orgId = trim($orgId);
    if ($orgId === '') {
        return 0;
    }

    $fromUser = pos_store_id_from_public_user_id($db, $orgId);
    if ($fromUser > 0) {
        return $fromUser;
    }

    try {
        $stmt = $db->prepare('SELECT id FROM businesses WHERE CAST(organization_id AS CHAR) = ? LIMIT 1');
        $stmt->execute([$orgId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    } catch (\Throwable $e) {
        // optional column
    }

    try {
        $stmt = $db->prepare('SELECT business_id FROM business_profile WHERE CAST(organization_id AS CHAR) = ? AND business_id IS NOT NULL AND business_id > 0 LIMIT 1');
        $stmt->execute([$orgId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    } catch (\Throwable $e) {
        // optional table
    }

    if (ctype_digit($orgId) && strlen($orgId) <= 8) {
        try {
            $stmt = $db->prepare('SELECT id FROM businesses WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $orgId]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable $e) {
            return 0;
        }
    }

    return 0;
}

function ensure_pos_organization_ids(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    pos_org_add_column($db, 'businesses', 'organization_id', 'VARCHAR(50) NULL');
    pos_org_add_column($db, 'business_profile', 'business_id', 'INT UNSIGNED NULL');
    pos_org_add_column($db, 'users', 'public_id', 'VARCHAR(50) NULL');

    try {
        $users = $db->query('SELECT id, public_id FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($users as $user) {
            $uid = (int) ($user['id'] ?? 0);
            if ($uid < 1) {
                continue;
            }
            $publicId = trim((string) ($user['public_id'] ?? ''));
            if ($publicId === '') {
                $publicId = pos_public_user_id($uid);
                $db->prepare('UPDATE users SET public_id = ? WHERE id = ?')->execute([$publicId, $uid]);
            }
        }
    } catch (\Throwable $e) {
        // users table may not exist yet
    }

    $used = [];

    try {
        $profiles = $db->query('SELECT * FROM business_profile')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($profiles as $profile) {
            $org = trim((string) ($profile['organization_id'] ?? ''));
            if ($org === '') {
                continue;
            }
            $bid = (int) ($profile['business_id'] ?? 0);
            if ($bid < 1) {
                $name = trim((string) ($profile['business_name'] ?? ''));
                if ($name !== '') {
                    $stmt = $db->prepare('SELECT id FROM businesses WHERE name = ? OR legal_name = ? LIMIT 1');
                    $stmt->execute([$name, $name]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $bid = $row ? (int) $row['id'] : 0;
                }
            }
            if ($bid < 1) {
                $bid = (int) ($profile['id'] ?? 0);
            }
            if ($bid < 1) {
                continue;
            }

            $check = $db->prepare('SELECT id FROM businesses WHERE id = ? LIMIT 1');
            $check->execute([$bid]);
            if (! $check->fetch()) {
                continue;
            }

            $cur = $db->prepare('SELECT organization_id FROM businesses WHERE id = ? LIMIT 1');
            $cur->execute([$bid]);
            $existing = trim((string) ($cur->fetchColumn() ?: ''));
            if ($existing === '') {
                $db->prepare('UPDATE businesses SET organization_id = ? WHERE id = ?')->execute([$org, $bid]);
            }
            $db->prepare('UPDATE business_profile SET business_id = ?, organization_id = ? WHERE id = ?')
                ->execute([$bid, $existing !== '' ? $existing : $org, (int) $profile['id']]);
            $used[$existing !== '' ? $existing : $org] = true;
        }
    } catch (\Throwable $e) {
        // profile table may not exist yet
    }

    try {
        $businesses = $db->query('SELECT id, name, organization_id FROM businesses ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($businesses as $business) {
            $bid = (int) $business['id'];
            $org = trim((string) ($business['organization_id'] ?? ''));
            if ($org === '') {
                $org = generate_pos_organization_id($db, $used);
                $db->prepare('UPDATE businesses SET organization_id = ? WHERE id = ?')->execute([$org, $bid]);
            }
            $used[$org] = true;
            pos_org_ensure_profile_row($db, $bid, $org, (string) ($business['name'] ?? 'Store'));
        }
    } catch (\Throwable $e) {
        // businesses table may not exist yet
    }

    try {
        $db->exec('CREATE UNIQUE INDEX idx_businesses_organization_id ON businesses (organization_id)');
    } catch (\Throwable $e) {
        // index may already exist
    }
}

function generate_pos_organization_id(PDO $db, array $used = []): string
{
    for ($i = 0; $i < 40; $i++) {
        $id = '60' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        if (isset($used[$id])) {
            continue;
        }
        if (! pos_org_id_exists($db, $id)) {
            return $id;
        }
    }

    return '60' . str_pad((string) time(), 9, '0', STR_PAD_LEFT);
}

function assign_organization_id_to_business(PDO $db, int $businessId, string $businessName = ''): string
{
    ensure_pos_organization_ids($db);
    if ($businessId < 1) {
        return '';
    }

    $stmt = $db->prepare('SELECT organization_id FROM businesses WHERE id = ? LIMIT 1');
    $stmt->execute([$businessId]);
    $org = trim((string) ($stmt->fetchColumn() ?: ''));
    if ($org === '') {
        $org = generate_pos_organization_id($db);
        $db->prepare('UPDATE businesses SET organization_id = ? WHERE id = ?')->execute([$org, $businessId]);
    }

    pos_org_ensure_profile_row($db, $businessId, $org, $businessName);

    return $org;
}

function resolve_pos_store_id(PDO $db, string $org): int
{
    $org = trim($org);
    if ($org === '') {
        return 0;
    }

    ensure_pos_organization_ids($db);

    $fromUser = pos_store_id_from_public_user_id($db, $org);
    if ($fromUser > 0) {
        return $fromUser;
    }

    try {
        $stmt = $db->prepare('SELECT id FROM businesses WHERE CAST(organization_id AS CHAR) = ? LIMIT 1');
        $stmt->execute([$org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (int) $row['id'];
        }
    } catch (\Throwable $e) {
        // column may not exist on a brand-new schema
    }

    try {
        $stmt = $db->prepare('SELECT business_id FROM business_profile WHERE CAST(organization_id AS CHAR) = ? AND business_id IS NOT NULL AND business_id > 0 LIMIT 1');
        $stmt->execute([$org]);
        $bid = (int) ($stmt->fetchColumn() ?: 0);
        if ($bid > 0) {
            return $bid;
        }
    } catch (\Throwable $e) {
        // continue
    }

    if (ctype_digit($org) && strlen($org) <= 8) {
        try {
            $stmt = $db->prepare('SELECT id FROM businesses WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $org]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int) $row['id'];
            }
        } catch (\Throwable $e) {
            return 0;
        }
    }

    if (! ctype_digit($org)) {
        try {
            $stmt = $db->prepare('
                SELECT id FROM businesses
                WHERE store_slug = ? OR name = ? OR LOWER(TRIM(store_slug)) = ? OR LOWER(TRIM(name)) = ?
                LIMIT 1
            ');
            $lower = strtolower($org);
            $stmt->execute([$org, $org, $lower, $lower]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int) $row['id'];
            }
        } catch (\Throwable $e) {
            // optional columns
        }
    }

    return 0;
}

function pos_org_id_exists(PDO $db, string $id): bool
{
    try {
        $stmt = $db->prepare('SELECT id FROM businesses WHERE CAST(organization_id AS CHAR) = ? LIMIT 1');
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            return true;
        }
    } catch (\Throwable $e) {
        // continue
    }

    try {
        $stmt = $db->prepare('SELECT id FROM business_profile WHERE CAST(organization_id AS CHAR) = ? LIMIT 1');
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            return true;
        }
    } catch (\Throwable $e) {
        // continue
    }

    return false;
}

function pos_org_ensure_profile_row(PDO $db, int $businessId, string $org, string $name): void
{
    try {
        $stmt = $db->prepare('SELECT id FROM business_profile WHERE business_id = ? LIMIT 1');
        $stmt->execute([$businessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $db->prepare('UPDATE business_profile SET organization_id = ? WHERE id = ? AND (organization_id IS NULL OR organization_id = "")')
                ->execute([$org, (int) $row['id']]);

            return;
        }

        $ins = $db->prepare('
            INSERT INTO business_profile (business_id, organization_id, business_name, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ');
        $ins->execute([$businessId, $org, $name !== '' ? $name : 'Store']);
    } catch (\Throwable $e) {
        // profile insert may fail on older schemas; store id still lives on businesses
    }
}

function pos_org_add_column(PDO $db, string $table, string $column, string $definition): void
{
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
        if ($stmt && $stmt->fetch()) {
            return;
        }
        $db->exec("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
    } catch (\Throwable $e) {
        // table may not exist yet
    }
}
