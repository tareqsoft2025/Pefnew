<?php
declare(strict_types=1);
namespace App\Http;

final class Labels
{
    public const MISSION = ['draft' => 'مسودة', 'sent' => 'أُرسلت', 'delivered' => 'وصلت للتطبيق', 'accepted' => 'قُبلت', 'rejected' => 'رُفضت', 'active' => 'نشطة', 'arrived' => 'وصلت للوجهة', 'completion_pending' => 'إنهاء معلق', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];
    public const EMERGENCY = ['new' => 'جديد', 'acknowledged' => 'تم الاستلام', 'in_progress' => 'قيد المعالجة', 'awaiting_update' => 'بانتظار تحديث', 'resolved' => 'تمت المعالجة', 'closed' => 'مغلق', 'false_alarm' => 'إنذار خاطئ'];
    public const PRIORITY = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];
    public const TONE = ['completed' => 'ok', 'active' => 'ok', 'accepted' => 'ok', 'arrived' => 'ok', 'resolved' => 'ok', 'closed' => 'mut', 'cancelled' => 'mut', 'draft' => 'mut', 'false_alarm' => 'mut',
        'rejected' => 'bad', 'new' => 'bad', 'urgent' => 'bad', 'high' => 'warn', 'completion_pending' => 'warn', 'awaiting_update' => 'warn', 'sent' => 'warn', 'delivered' => 'warn', 'acknowledged' => 'warn', 'in_progress' => 'warn'];

    public static function badge(string $key, array $map): Raw
    {
        $t = self::TONE[$key] ?? '';
        return new Raw('<span class="badge' . ($t ? " b-$t" : '') . '">' . e($map[$key] ?? $key) . '</span>');
    }
    public static function mission(string $s): Raw { return self::badge($s, self::MISSION); }
    public static function emergency(string $s): Raw { return self::badge($s, self::EMERGENCY); }
    public static function priority(string $s): Raw { return self::badge($s, self::PRIORITY); }
    public static function dt(?string $s): string
    {
        return $s ? gmdate('Y-m-d H:i', strtotime($s . ' UTC') + 3 * 3600) : '—';   // العرض بتوقيت الرياض (UTC+3) افتراضيًا
    }
}
