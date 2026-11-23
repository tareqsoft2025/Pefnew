<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Authz, Clock, Db};

/** رفع/تقديم الملفات: تخزين خارج الويب، فحص MIME وsha256، وتقديم عبر نقطة مصرّح بها فقط. */
final class AttachmentService
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private Db $db;
    public function __construct(private App $app) { $this->db = $app->db; }

    /** @param array $file ['tmp_name','name','size'] @return array السجل مع received=true بعد التحقق */
    public function storeForDriver(int $userId, int $driverId, string $ownerType, string $ownerRef, string $clientFileId, array $file, ?string $sha256, ?string $capturedAt): array
    {
        if (!in_array($ownerType, ['mission_proof', 'odometer', 'emergency', 'inspection'], true)) { throw new ApiException(422, 'validation_failed', 'نوع المرفق غير صالح'); }
        if ($clientFileId === '' || strlen($clientFileId) > 64) { throw new ApiException(422, 'validation_failed', 'client_file_id مطلوب'); }
        $dup = $this->db->one('SELECT * FROM mission_attachments WHERE uploader_user_id=? AND client_file_id=?', [$userId, $clientFileId]);
        if ($dup) { return ['id' => (int)$dup['id'], 'duplicate' => true, 'sha256' => $dup['sha256']]; }
        $ownerId = $this->resolveOwner($driverId, $ownerType, $ownerRef);
        if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) { throw new ApiException(422, 'file_missing', 'الملف مفقود'); }
        $size = filesize($file['tmp_name']);
        if ($size === 0 || $size > self::MAX_BYTES) { throw new ApiException(413, 'file_too_large', 'حجم الملف غير مقبول (الحد 8MB)'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::MIMES[$mime])) { throw new ApiException(415, 'unsupported_type', 'يسمح فقط بصور JPEG/PNG/WebP'); }
        $hash = hash_file('sha256', $file['tmp_name']);
        if ($sha256 !== null && !hash_equals(strtolower($sha256), $hash)) { throw new ApiException(422, 'checksum_mismatch', 'فشل التحقق من سلامة الملف؛ أعد الرفع'); }
        $rel = substr($hash, 0, 2) . '/' . bin2hex(random_bytes(12)) . '.' . self::MIMES[$mime];
        $dir = App::storageDir() . '/uploads/' . dirname($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) { throw new ApiException(500, 'storage_error', 'تعذر حفظ الملف'); }
        $dest = App::storageDir() . '/uploads/' . $rel;
        if (!@copy($file['tmp_name'], $dest)) { throw new ApiException(500, 'storage_error', 'تعذر حفظ الملف'); }
        @chmod($dest, 0640);
        $id = $this->db->insert('mission_attachments', ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'uploader_user_id' => $userId, 'client_file_id' => $clientFileId,
            'original_name' => isset($file['name']) ? mb_substr(basename((string)$file['name']), 0, 190) : null, 'mime' => $mime, 'size_bytes' => $size, 'sha256' => $hash,
            'storage_path' => $rel, 'captured_at' => $capturedAt ? Clock::fmt(Clock::parse($capturedAt)) : null, 'created_at' => Clock::nowStr()]);
        $this->link($ownerType, $ownerId, $id);
        return ['id' => $id, 'duplicate' => false, 'sha256' => $hash, 'size' => $size];
    }

    private function resolveOwner(int $driverId, string $type, string $ref): int
    {
        $row = match ($type) {
            'mission_proof' => $this->db->one('SELECT id FROM missions WHERE id=? AND driver_id=?', [(int)$ref, $driverId]),
            'odometer' => $this->db->one('SELECT r.id FROM vehicle_odometer_readings r JOIN vehicle_segments s ON s.id=r.segment_id JOIN trips t ON t.id=s.trip_id WHERE r.client_event_id=? AND t.driver_id=?', [$ref, $driverId]),
            'emergency' => $this->db->one('SELECT id FROM emergency_incidents WHERE client_event_id=? AND driver_id=?', [$ref, $driverId]),
            'inspection' => $this->db->one('SELECT id FROM vehicle_inspections WHERE client_event_id=? AND driver_id=?', [$ref, $driverId]),
        };
        if (!$row) { throw new ApiException(404, 'owner_not_found', 'العنصر المرتبط غير موجود بعد؛ زامن الحدث أولًا'); }
        return (int)$row['id'];
    }

    private function link(string $type, int $ownerId, int $attId): void
    {
        if ($type === 'mission_proof') { (new MissionService($this->app))->onProofUploaded($ownerId); }
        elseif ($type === 'odometer') { $this->db->update('vehicle_odometer_readings', ['attachment_id' => $attId, 'photo_status' => 'received'], 'id=?', [$ownerId]); }
        elseif ($type === 'emergency') { $this->db->q('INSERT IGNORE INTO emergency_attachments (incident_id, attachment_id) VALUES (?,?)', [$ownerId, $attId]); }
    }

    /** صلاحية الاطلاع: السائق صاحب الملف، أو إداري بالصلاحية والنطاق. يُسجل كل اطلاع إداري. */
    public function authorizedRecord(array $user, int $id, bool $isDriverApi): array
    {
        $a = $this->db->one('SELECT * FROM mission_attachments WHERE id=?', [$id]);
        if (!$a) { throw new ApiException(404, 'not_found', 'الملف غير موجود'); }
        if ($isDriverApi) {
            if ((int)$a['uploader_user_id'] !== (int)$user['id']) { throw new ApiException(404, 'not_found', 'الملف غير موجود'); }
            return $a;
        }
        $perm = ['mission_proof' => 'missions.view', 'odometer' => 'odometer.view', 'emergency' => 'emergencies.view', 'inspection' => 'inspections.view'][$a['owner_type']];
        Authz::require($user, $perm);
        $entity = match ($a['owner_type']) {
            'mission_proof' => $this->db->one('SELECT team,region,fleet FROM missions WHERE id=?', [$a['owner_id']]),
            'odometer' => $this->db->one('SELECT v.team,v.region,v.fleet FROM vehicle_odometer_readings r JOIN vehicles v ON v.id=r.vehicle_id WHERE r.id=?', [$a['owner_id']]),
            'emergency' => $this->db->one('SELECT team,region,fleet FROM emergency_incidents WHERE id=?', [$a['owner_id']]),
            'inspection' => $this->db->one('SELECT v.team,v.region,v.fleet FROM vehicle_inspections i JOIN vehicles v ON v.id=i.vehicle_id WHERE i.id=?', [$a['owner_id']]),
        };
        if (!$entity) { throw new ApiException(404, 'not_found', 'الملف غير موجود'); }
        Authz::requireScope($user, $entity);
        $this->app->audit->log((int)$user['id'], 'attachment.view', 'attachment', $id, ['owner' => $a['owner_type']]);
        return $a;
    }

    public function path(array $a): string
    {
        $p = App::storageDir() . '/uploads/' . $a['storage_path'];
        if (!is_file($p) || str_contains($a['storage_path'], '..')) { throw new ApiException(404, 'not_found', 'الملف غير موجود'); }
        return $p;
    }
}
