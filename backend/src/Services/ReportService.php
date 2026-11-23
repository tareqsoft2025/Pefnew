<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Authz, Clock};

/** تقارير مقيّدة بالنطاق + تصدير CSV/XLSX (PDF عبر طباعة المتصفح لدعم العربية بلا مكتبات ثقيلة). */
final class ReportService
{
    public const TYPES = [
        'missions' => 'تقرير المهام', 'trips' => 'تقرير الرحلات والمسافات', 'stops' => 'تقرير التوقفات', 'deviations' => 'تقرير الانحرافات',
        'odometer' => 'مقارنة العداد وGPS', 'emergencies' => 'تقرير الطوارئ', 'drivers' => 'ملخص السائقين',
    ];
    public function __construct(private App $app) {}

    /** @return array{title:string,columns:string[],rows:array[]} */
    public function build(array $user, string $type, array $f): array
    {
        Authz::require($user, 'reports.view');
        if (!isset(self::TYPES[$type])) { throw new ApiException(404, 'not_found', 'نوع تقرير غير معروف'); }
        $from = Clock::fmt(Clock::parse($f['from'] ?? gmdate('Y-m-d', Clock::now() - 30 * 86400)));
        $to = Clock::fmt(Clock::parse(($f['to'] ?? gmdate('Y-m-d')) . ' 23:59:59'));
        $db = $this->app->db;
        $m = fn(string $sql, string $alias, array $p = []) => $this->scoped($user, $sql, $alias, $p);
        switch ($type) {
            case 'missions':
                [$sc, $sp] = Authz::scopeSql($user, 'm');
                $rows = $db->all("SELECT m.mission_no, m.title, m.status, m.priority, m.scheduled_start, u.full_name, v.plate_no, m.completed_at FROM missions m LEFT JOIN drivers d ON d.id=m.driver_id LEFT JOIN users u ON u.id=d.user_id LEFT JOIN vehicles v ON v.id=m.vehicle_id WHERE m.scheduled_start BETWEEN ? AND ? AND $sc ORDER BY m.scheduled_start", [$from, $to, ...$sp]);
                return $this->out($type, ['رقم المهمة', 'العنوان', 'الحالة', 'الأولوية', 'موعد البداية', 'السائق', 'اللوحة', 'وقت الإنجاز'], $rows);
            case 'trips':
                [$sc, $sp] = Authz::scopeSql($user, 'd');
                $rows = $db->all("SELECT t.id, u.full_name, t.started_at, t.ended_at, ROUND(t.distance_gps_m/1000,2) AS km, t.point_count, (SELECT COUNT(*) FROM trip_missions x WHERE x.trip_id=t.id) AS missions, (SELECT COUNT(*) FROM trip_stops s WHERE s.trip_id=t.id) AS stops FROM trips t JOIN drivers d ON d.id=t.driver_id JOIN users u ON u.id=d.user_id WHERE t.started_at BETWEEN ? AND ? AND $sc ORDER BY t.started_at", [$from, $to, ...$sp]);
                return $this->out($type, ['رقم الرحلة', 'السائق', 'البداية', 'النهاية', 'المسافة (كم GPS)', 'عدد النقاط', 'المهام', 'التوقفات'], $rows);
            case 'stops':
                [$sc, $sp] = Authz::scopeSql($user, 'd');
                $rows = $db->all("SELECT s.id, u.full_name, s.started_at, ROUND(s.duration_s/60,1) AS mins, r.name_ar, x.notes FROM trip_stops s JOIN trips t ON t.id=s.trip_id JOIN drivers d ON d.id=t.driver_id JOIN users u ON u.id=d.user_id LEFT JOIN stop_explanations x ON x.stop_id=s.id LEFT JOIN stop_reasons r ON r.id=x.reason_id WHERE s.started_at BETWEEN ? AND ? AND $sc ORDER BY s.started_at", [$from, $to, ...$sp]);
                foreach ($rows as &$r) { $r['name_ar'] ??= 'دون تفسير'; }
                return $this->out($type, ['رقم', 'السائق', 'بداية التوقف', 'المدة (دقيقة)', 'السبب', 'ملاحظات'], $rows);
            case 'deviations':
                [$sc, $sp] = Authz::scopeSql($user, 'd');
                $rows = $db->all("SELECT v.id, u.full_name, v.started_at, v.ended_at, v.max_distance_m, v.reason, v.review_status FROM route_deviations v JOIN trips t ON t.id=v.trip_id JOIN drivers d ON d.id=t.driver_id JOIN users u ON u.id=d.user_id WHERE v.started_at BETWEEN ? AND ? AND $sc ORDER BY v.started_at", [$from, $to, ...$sp]);
                return $this->out($type, ['رقم', 'السائق', 'البداية', 'النهاية', 'أقصى انحراف (م)', 'السبب', 'المراجعة'], $rows);
            case 'odometer':
                [$sc, $sp] = Authz::scopeSql($user, 'v');
                $rows = $db->all("SELECT s.id, v.plate_no, s.started_at, s.start_odometer, s.end_odometer, ROUND(s.odometer_distance_m/1000,2) AS odo_km, ROUND(s.gps_distance_m/1000,2) AS gps_km, IF(s.review_flag,'يحتاج مراجعة','—') AS flag FROM vehicle_segments s JOIN vehicles v ON v.id=s.vehicle_id WHERE s.started_at BETWEEN ? AND ? AND $sc ORDER BY s.started_at", [$from, $to, ...$sp]);
                return $this->out($type, ['مرحلة', 'اللوحة', 'البداية', 'عداد البداية', 'عداد النهاية', 'مسافة العداد (كم)', 'مسافة GPS (كم)', 'المراجعة'], $rows);
            case 'emergencies':
                Authz::require($user, 'emergencies.view');
                [$sc, $sp] = Authz::scopeSql($user, 'e');
                $rows = $db->all("SELECT e.incident_no, t.name_ar, e.status, e.received_at, e.acknowledged_at, TIMESTAMPDIFF(SECOND,e.received_at,e.acknowledged_at) AS ack_s, e.escalation_level, e.close_reason FROM emergency_incidents e LEFT JOIN emergency_types t ON t.id=e.type_id WHERE e.received_at BETWEEN ? AND ? AND $sc ORDER BY e.received_at", [$from, $to, ...$sp]);
                return $this->out($type, ['رقم البلاغ', 'النوع', 'الحالة', 'وقت الوصول', 'وقت الاستلام', 'ثواني حتى الاستلام', 'مستوى التصعيد', 'سبب الإغلاق'], $rows);
            default:
                [$sc, $sp] = Authz::scopeSql($user, 'd');
                $rows = $db->all("SELECT u.full_name, COUNT(DISTINCT t.id) AS trips, ROUND(COALESCE(SUM(t.distance_gps_m),0)/1000,1) AS km, (SELECT COUNT(*) FROM missions m WHERE m.driver_id=d.id AND m.status='completed' AND m.completed_at BETWEEN ? AND ?) AS done FROM drivers d JOIN users u ON u.id=d.user_id LEFT JOIN trips t ON t.driver_id=d.id AND t.started_at BETWEEN ? AND ? WHERE $sc GROUP BY d.id, u.full_name ORDER BY u.full_name", [$from, $to, $from, $to, ...$sp]);
                return $this->out($type, ['السائق', 'الرحلات', 'المسافة (كم)', 'مهام منجزة'], $rows);
        }
    }

    private function scoped(array $u, string $s, string $a, array $p): array { return [$s, $p]; }
    private function out(string $type, array $cols, array $rows): array { return ['title' => self::TYPES[$type], 'columns' => $cols, 'rows' => array_map('array_values', $rows)]; }

    public function csv(array $rep): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");   // BOM ليفتح Excel العربية صحيحة
        fputcsv($h, $rep['columns']);
        foreach ($rep['rows'] as $r) { fputcsv($h, array_map(fn($v) => $this->csvSafe($v), $r)); }
        rewind($h);
        return (string)stream_get_contents($h);
    }

    /** يمنع حقن الصيغ في Excel: قيم تبدأ بـ = + - @ تُسبق بفاصلة عليا. */
    public function csvSafe(mixed $v): string
    {
        $s = (string)($v ?? '');
        return $s !== '' && strpbrk($s[0], '=+-@') !== false && !is_numeric($s) ? "'" . $s : $s;
    }

    public function xlsx(array $rep): ?string
    {
        if (!class_exists(\ZipArchive::class)) { return null; }
        $col = fn(int $i) => ($i >= 26 ? chr(64 + intdiv($i, 26)) : '') . chr(65 + $i % 26);
        $rowsXml = '';
        foreach (array_merge([$rep['columns']], $rep['rows']) as $ri => $r) {
            $cells = '';
            foreach (array_values($r) as $ci => $v) {
                $ref = $col($ci) . ($ri + 1);
                $cells .= is_numeric($v) && $ri > 0 && !str_starts_with((string)$v, '0') || ($ri > 0 && is_numeric($v) && $v === '0')
                    ? "<c r=\"$ref\"><v>" . (float)$v . '</v></c>'
                    : "<c r=\"$ref\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . htmlspecialchars($this->csvSafe($v), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
            $rowsXml .= "<row r=\"" . ($ri + 1) . "\">$cells</row>";
        }
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        $z = new \ZipArchive();
        $z->open($tmp, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetData>' . $rowsXml . '</sheetData></worksheet>');
        $z->close();
        $data = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }
}
