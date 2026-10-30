<?php

/**
 * Admin-configured OTP delivery channels. Email is always the default; WhatsApp (Meta Cloud API)
 * and SMS (MSG91) become selectable by a shopper only once an operator stores their credentials.
 * Config is authoritative in store_settings; secret keys are write-only and never rendered back.
 */
final class OtpChannels
{
    public const CHANNELS = ['email', 'whatsapp', 'sms'];
    private const FIELDS = [
        'whatsapp' => ['enabled' => 'otp_whatsapp_enabled', 'phone_id' => 'otp_whatsapp_phone_id', 'template' => 'otp_whatsapp_template', 'token' => 'otp_whatsapp_token'],
        'sms'      => ['enabled' => 'otp_sms_enabled', 'template' => 'otp_sms_template', 'sender' => 'otp_sms_sender', 'authkey' => 'otp_sms_authkey'],
    ];
    private const SECRETS = ['otp_whatsapp_token', 'otp_sms_authkey'];

    private static function rows(PDO $pdo): array
    {
        $keys = [];
        foreach (self::FIELDS as $field) $keys = array_merge($keys, array_values($field));
        $in = implode(',', array_fill(0, count($keys), '?'));
        return CommerceDatabase::query($pdo, "SELECT skey, svalue FROM store_settings WHERE skey IN ($in)", $keys)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** True for each channel a shopper may pick now: email always, others only when enabled AND fully configured. */
    public static function available(PDO $pdo): array
    {
        $rows = self::rows($pdo);
        $status = ['email' => true];
        foreach (self::FIELDS as $channel => $field) {
            $on = filter_var($rows[$field['enabled']] ?? '0', FILTER_VALIDATE_BOOLEAN);
            $complete = true;
            foreach ($field as $role => $key) if ($role !== 'enabled' && ($rows[$key] ?? '') === '') $complete = false;
            $status[$channel] = $on && $complete;
        }
        return $status;
    }

    /** Sender credentials for a usable channel, or null when the channel is off or incomplete. */
    public static function credentials(PDO $pdo, string $channel): ?array
    {
        if (!isset(self::FIELDS[$channel]) || !self::available($pdo)[$channel]) return null;
        $rows = self::rows($pdo);
        $creds = [];
        foreach (self::FIELDS[$channel] as $role => $key) if ($role !== 'enabled') $creds[$role] = (string) $rows[$key];
        return $creds;
    }

    /** Admin view model: enablement + non-secret values + whether each secret is already stored. */
    public static function adminView(PDO $pdo): array
    {
        $rows = self::rows($pdo);
        $view = [];
        foreach (self::FIELDS as $channel => $field) {
            $view[$channel] = ['enabled' => filter_var($rows[$field['enabled']] ?? '0', FILTER_VALIDATE_BOOLEAN)];
            foreach ($field as $role => $key) {
                if ($role === 'enabled') continue;
                $view[$channel][$role] = in_array($key, self::SECRETS, true) ? (($rows[$key] ?? '') !== '') : ($rows[$key] ?? '');
            }
        }
        return $view;
    }

    public static function save(PDO $pdo, int $actorId, array $input, string $operationKey): void
    {
        CommerceAccess::requirePermission('admin.settings.edit', $actorId);
        $key = CommerceValues::key($operationKey);
        $existing = self::rows($pdo);
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field['enabled']] = CommerceValues::flag($input[$field['enabled']] ?? 0, $field['enabled']) ? '1' : '0';
            foreach ($field as $role => $skey) {
                if ($role === 'enabled') continue;
                $submitted = is_string($input[$skey] ?? null) ? trim($input[$skey]) : '';
                // A blank secret keeps its stored value, so the token is never echoed into the form.
                if ($submitted === '' && in_array($skey, self::SECRETS, true)) $submitted = (string) ($existing[$skey] ?? '');
                if (strlen($submitted) > 500) CommerceValues::invalid($skey);
                $values[$skey] = $submitted;
            }
        }
        ksort($values);
        CommerceDatabase::transaction($pdo, function () use ($pdo, $actorId, $key, $values): void {
            $prior = CommerceDatabase::query($pdo, 'SELECT id FROM admin_activity_log WHERE operation_key=?' . CommerceDatabase::lock($pdo), [$key])->fetchColumn();
            // Audit the enablement and non-secret values only; never log the stored secrets.
            StaffAudit::record($pdo, $actorId, 'settings.otp', 'settings', null, $key, array_diff_key($values, array_flip(self::SECRETS)));
            if ($prior) return; // Replaying an old operation must not overwrite newer settings.
            $sql = 'INSERT INTO store_settings (skey,svalue,updated_at) VALUES (?,?,?)';
            $sql .= $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE svalue=VALUES(svalue),updated_at=VALUES(updated_at)'
                : ' ON CONFLICT(skey) DO UPDATE SET svalue=excluded.svalue,updated_at=excluded.updated_at';
            foreach ($values as $skey => $value) CommerceDatabase::query($pdo, $sql, [$skey, $value, CommerceDatabase::clock($pdo)]);
        });
    }
}
