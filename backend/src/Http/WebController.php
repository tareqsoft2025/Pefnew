<?php
declare(strict_types=1);
namespace App\Http;

use App\App;
use App\Core\{ApiException, Authz, Clock, Env, Totp, Validator};
use App\Services\{AuthService, EmergencyService, MissionService, OdometerService, QueryService, ReportService, UserService};

/** لوحة الإدارة (HTML عربي RTL). كل إجراء يمر عبر الخدمات التي تفحص الصلاحيات والنطاق على الخادم. */
final class WebController
{
    private App $app;
    public function __construct(private Kernel $k) { $this->app = $k->app; }

    public function routes(Router $r): void
    {
        $a = ['auth' => 'admin'];
        $r->get('/login', fn($q) => $this->loginForm($q));
        $r->post('/login', fn($q) => $this->login($q));
        $r->post('/logout', function ($q) { $this->k->session->destroy(); return Response::redirect('/login'); }, $a);
        $r->get('/', fn($q) => $this->dashboard($q), $a);
        $r->get('/account', fn($q) => $this->account($q), $a);
        $r->post('/account/password', fn($q) => $this->act($q, '/account', 'تم تغيير كلمة المرور؛ سجّل الدخول مجددًا', function () use ($q) {
            $s = new AuthService($this->app); $u = $q->attrs['user'];
            $s->verifyCredentials($u['username'], (string)$q->body['current'], null, $q->ip);
            $s->changePassword((int)$u['id'], (string)$q->body['new']);
        }), $a);
        $r->post('/account/mfa', fn($q) => $this->mfa($q), $a);
        // المهام
        $r->get('/missions', fn($q) => $this->missions($q), $a + ['perm' => 'missions.view']);
        $r->get('/missions/new', fn($q) => $this->missionForm($q), $a + ['perm' => 'missions.create']);
        $r->post('/missions', fn($q) => $this->missionCreate($q), $a + ['perm' => 'missions.create']);
        $r->get('/missions/{id}', fn($q, $x) => $this->missionShow($q, (int)$x['id']), $a + ['perm' => 'missions.view']);
        $r->post('/missions/{id}/assign', fn($q, $x) => $this->act($q, "/missions/{$x['id']}", 'تم حفظ الإسناد', fn() => (new MissionService($this->app))->assign($q->attrs['user'], (int)$x['id'],
            ($q->body['driver_id'] ?? '') !== '' ? (int)$q->body['driver_id'] : null, ($q->body['vehicle_id'] ?? '') !== '' ? (int)$q->body['vehicle_id'] : null, $q->body['reason'] ?? null, ($q->body['conflict_exception_reason'] ?? '') ?: null)), $a);
        $r->post('/missions/{id}/send', fn($q, $x) => $this->act($q, "/missions/{$x['id']}", 'تم إرسال المهمة للسائق', fn() => (new MissionService($this->app))->send($q->attrs['user'], (int)$x['id'])), $a);
        $r->post('/missions/{id}/cancel', fn($q, $x) => $this->act($q, "/missions/{$x['id']}", 'تم إلغاء المهمة', fn() => (new MissionService($this->app))->cancel($q->attrs['user'], (int)$x['id'], (string)($q->body['reason'] ?? ''))), $a);
        $r->post('/missions/{id}/routes', function ($q, $x) {
            return $this->act($q, "/missions/{$x['id']}", 'تم حفظ المسار المقترح', function () use ($q, $x) {
                $manual = ($q->body['act'] ?? '') === 'manual';
                $geo = $manual ? json_decode((string)($q->body['geometry'] ?? ''), true) : null;
                (new MissionService($this->app))->proposeRoute($q->attrs['user'], (int)$x['id'], $manual ? 'manual' : 'auto', $geo);
            });
        }, $a);
        $r->post('/routes/{id}/approve', function ($q, $x) {
            $mid = $this->app->db->val('SELECT mission_id FROM mission_routes WHERE id=?', [(int)$x['id']]);
            return $this->act($q, "/missions/$mid", 'تم اعتماد المسار', fn() => (new MissionService($this->app))->approveRoute($q->attrs['user'], (int)$x['id']));
        }, $a);
        // الخريطة والرحلات
        $r->get('/map', fn($q) => $this->page($q, 'map', [], 'التتبع المباشر', 'map', ['map' => true, 'scripts' => '<script>var m=FleetMap.init("map",' . json_encode($this->app->settings->get('map.tile_url')) . ');FleetMap.live(m,document.getElementById("vlist"));</script>']), $a + ['perm' => 'tracking.view_live']);
        $r->get('/trips/{id}', fn($q, $x) => $this->trip($q, (int)$x['id']), $a + ['perm' => 'tracking.view_history']);
        // المركبات والسائقون
        $r->get('/vehicles', fn($q) => $this->vehicles($q), $a + ['perm' => 'vehicles.view']);
        $r->post('/vehicles', fn($q) => $this->vehicleSave($q), $a + ['perm' => 'vehicles.manage']);
        $r->get('/drivers', fn($q) => $this->drivers($q), $a + ['perm' => 'drivers.view']);
        $r->post('/drivers', fn($q) => $this->act($q, '/drivers', 'تم إنشاء حساب السائق', fn() => (new UserService($this->app))->createDriver(array_map(fn($v) => $v === '' ? null : $v, $q->body), $q->attrs['user'])), $a + ['perm' => 'drivers.manage']);
        $r->post('/users/{id}/active', fn($q, $x) => $this->act($q, $_SERVER['HTTP_REFERER'] ?? '/users', 'تم تحديث الحالة', fn() => (new UserService($this->app))->setActive($q->attrs['user'], (int)$x['id'], ($q->body['active'] ?? '0') === '1')), $a + ['perm' => 'users.manage']);
        // المراقبة
        $r->get('/stops', fn($q) => $this->stops($q), $a + ['perm' => 'stops.view']);
        $r->post('/deviations/{id}/review', function ($q, $x) {
            return $this->act($q, '/stops', 'تم تسجيل المراجعة', function () use ($q, $x) {
                Authz::require($q->attrs['user'], 'stops.review');
                $d = $this->app->db->one('SELECT dr.team, dr.region, dr.fleet FROM route_deviations v JOIN trips t ON t.id=v.trip_id JOIN drivers dr ON dr.id=t.driver_id WHERE v.id=?', [(int)$x['id']]);
                if (!$d) { throw new ApiException(404, 'not_found', 'غير موجود'); }
                Authz::requireScope($q->attrs['user'], $d);
                $st = ($q->body['status'] ?? '') === 'flagged' ? 'flagged' : 'accepted';
                $this->app->db->update('route_deviations', ['review_status' => $st, 'reviewed_by' => $q->attrs['user']['id']], 'id=?', [(int)$x['id']]);
                $this->app->audit->log($q->attrs['user']['id'], 'deviation.review', 'deviation', (int)$x['id'], ['status' => $st]);
            });
        }, $a);
        $r->get('/emergencies', fn($q) => $this->emergencies($q), $a + ['perm' => 'emergencies.view']);
        $r->get('/emergencies/{id}', fn($q, $x) => $this->emergency($q, (int)$x['id']), $a + ['perm' => 'emergencies.view']);
        foreach (['acknowledge' => ['تم تأكيد الاستلام', fn($u, $id, $b) => (new EmergencyService($this->app))->acknowledge($u, $id)],
            'assign' => ['تم تعيين المسؤول', fn($u, $id, $b) => (new EmergencyService($this->app))->assign($u, $id, (int)($b['assignee_user_id'] ?? 0))],
            'actions' => ['تم تسجيل الإجراء', fn($u, $id, $b) => (new EmergencyService($this->app))->addAction($u, $id, (string)($b['type'] ?? 'note'), (string)($b['details'] ?? ''))],
            'updates' => ['تم إرسال التحديث للسائق', function ($u, $id, $b) { $s = new EmergencyService($this->app); $s->getScoped($u, $id); $s->addUpdate($u, $id, (string)($b['message'] ?? '')); }],
            'status' => ['تم تحديث الحالة', fn($u, $id, $b) => (new EmergencyService($this->app))->setStatus($u, $id, (string)($b['status'] ?? ''))],
            'close' => ['تم إغلاق البلاغ', fn($u, $id, $b) => (new EmergencyService($this->app))->close($u, $id, (string)($b['reason'] ?? ''), !empty($b['false_alarm']))]] as $name => [$msg, $fn]) {
            $r->post("/emergencies/{id}/$name", fn($q, $x) => $this->act($q, "/emergencies/{$x['id']}", $msg, fn() => $fn($q->attrs['user'], (int)$x['id'], $q->body)), $a);
        }
        $r->get('/odometer', fn($q) => $this->odometer($q), $a + ['perm' => 'odometer.view']);
        $r->post('/odometer/{id}', fn($q, $x) => $this->act($q, '/odometer', 'تم تسجيل القراءة', fn() => (new OdometerService($this->app))->adminRecord($q->attrs['user'], (int)$x['id'], (string)$q->body['kind'], (float)$q->body['value'], gmdate('c'), (string)($q->body['reason'] ?? ''))), $a);
        $r->get('/inspections', fn($q) => $this->featureList($q, 'feature.inspection', 'inspections.view', 'فحص المركبات', 'inspections'), $a + ['perm' => 'inspections.view']);
        $r->get('/fuel', fn($q) => $this->featureList($q, 'feature.fuel', 'fuel.view', 'الوقود', 'fuel'), $a + ['perm' => 'fuel.view']);
        $r->get('/reports', fn($q) => $this->reports($q), $a + ['perm' => 'reports.view']);
        $r->get('/reports/export', fn($q) => $this->export($q), $a + ['perm' => 'reports.export']);
        // الإدارة
        $r->get('/users', fn($q) => $this->users($q), $a + ['perm' => 'users.manage']);
        $r->post('/users', fn($q) => $this->userCreate($q), $a + ['perm' => 'users.manage']);
        $r->post('/users/{id}/access', fn($q, $x) => $this->act($q, '/users', 'تم تحديث الأدوار والنطاقات', function () use ($q, $x) {
            $s = new UserService($this->app);
            $s->setRoles((int)$x['id'], (array)($q->body['roles'] ?? []));
            $s->setScopes((int)$x['id'], $this->parseScopes((string)($q->body['scopes'] ?? '')));
            $this->app->audit->log($q->attrs['user']['id'], 'user.access_changed', 'user', (int)$x['id'], ['roles' => $q->body['roles'] ?? [], 'scopes' => $q->body['scopes'] ?? '']);
        }), $a + ['perm' => 'users.manage']);
        $r->get('/roles', fn($q) => $this->roles($q), $a + ['perm' => 'roles.manage']);
        $r->post('/roles/{id}', fn($q, $x) => $this->roleSave($q, (int)$x['id']), $a + ['perm' => 'roles.manage']);
        $r->get('/settings', fn($q) => $this->settings($q), $a + ['perm' => 'settings.manage']);
        $r->post('/settings', fn($q) => $this->act($q, '/settings', 'تم حفظ الإعدادات', function () use ($q) {
            foreach ((array)($q->body['s'] ?? []) as $k => $v) { $this->app->settings->set((string)$k, (string)$v); }
            $this->app->audit->log($q->attrs['user']['id'], 'settings.update', 'settings', null, ['keys' => array_keys((array)($q->body['s'] ?? []))]);
        }), $a + ['perm' => 'settings.manage']);
        $r->get('/audit', fn($q) => $this->audit($q), $a + ['perm' => 'audit.view']);
    }

    // ---------------- مساعدات ----------------

    private const NAV = [
        ['الرئيسية', 'dashboard', '/', 'لوحة المؤشرات', '📊', 'dashboard.view'], ['التشغيل', 'map', '/map', 'التتبع المباشر', '🗺️', 'tracking.view_live'],
        ['التشغيل', 'missions', '/missions', 'المهام', '📋', 'missions.view'], ['التشغيل', 'stops', '/stops', 'التوقفات والانحرافات', '⏸️', 'stops.view'],
        ['التشغيل', 'emergencies', '/emergencies', 'الطوارئ SOS', '🚨', 'emergencies.view'], ['الأسطول', 'vehicles', '/vehicles', 'المركبات', '🚗', 'vehicles.view'],
        ['الأسطول', 'drivers', '/drivers', 'السائقون', '👤', 'drivers.view'], ['الأسطول', 'odometer', '/odometer', 'العدادات', '🔢', 'odometer.view'],
        ['الأسطول', 'inspections', '/inspections', 'الفحص', '🛠️', 'inspections.view'], ['الأسطول', 'fuel', '/fuel', 'الوقود', '⛽', 'fuel.view'],
        ['التقارير', 'reports', '/reports', 'التقارير والتصدير', '📈', 'reports.view'],
        ['الإدارة', 'users', '/users', 'المستخدمون', '👥', 'users.manage'], ['الإدارة', 'roles', '/roles', 'الأدوار والصلاحيات', '🔐', 'roles.manage'],
        ['الإدارة', 'settings', '/settings', 'الإعدادات والتكاملات', '⚙️', 'settings.manage'], ['الإدارة', 'audit', '/audit', 'سجل التدقيق', '🧾', 'audit.view'],
    ];

    private function page(Request $q, string $view, array $vars, string $title, string $active, array $opts = []): Response
    {
        $user = $q->attrs['user'];
        $nav = [];
        foreach (self::NAV as [$g, $k, $h, $l, $i, $p]) { if (Authz::can($user, $p)) { $nav[] = ['group' => $g, 'key' => $k, 'href' => $h, 'label' => $l, 'icon' => $i]; } }
        $vars += ['user' => $user, 'csrf' => $this->k->session->csrf(), 'nav' => $nav, 'title' => $title, 'active' => $active,
            'flash' => $this->k->session->flash('ok'), 'flashErr' => $this->k->session->flash('err')] + $opts;
        $vars['content'] = View::render($view, $vars, null);
        return Response::html(View::render('layout', $vars, null));
    }

    private function act(Request $q, string $back, string $okMsg, callable $fn): Response
    {
        try { $fn(); $this->k->session->flash('ok', $okMsg); }
        catch (ApiException $e) {
            if (in_array($e->status, [401, 403, 419], true)) { throw $e; }
            $d = $e->details ? ' — ' . implode('، ', array_map(fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v, $e->details)) : '';
            $this->k->session->flash('err', $e->getMessage() . $d);
        }
        return Response::redirect($back);
    }

    /** جدول HTML مهرّب؛ Raw للمحتوى الموثوق. $cols = [عنوان => callable|مفتاح] */
    private function tbl(array $cols, array $rows, string $empty = 'لا توجد بيانات'): string
    {
        if (!$rows) { return '<div class="empty">' . e($empty) . '</div>'; }
        $h = '<table><thead><tr>';
        foreach (array_keys($cols) as $c) { $h .= '<th scope="col">' . e($c) . '</th>'; }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($cols as $c) {
                $v = is_callable($c) ? $c($r) : ($r[$c] ?? '');
                $h .= '<td>' . ($v instanceof Raw ? $v->html : e($v)) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    }

    private function csrfField(): string { return '<input type="hidden" name="_csrf" value="' . e($this->k->session->csrf()) . '">'; }
    private function select(string $name, array $opts, ?string $sel, string $all = 'الكل'): string
    {
        $h = '<select name="' . e($name) . '"><option value="">' . e($all) . '</option>';
        foreach ($opts as $k => $v) { $h .= '<option value="' . e($k) . '"' . ((string)$sel === (string)$k && $sel !== null && $sel !== '' ? ' selected' : '') . '>' . e($v) . '</option>'; }
        return $h . '</select>';
    }
    private function drivers4(array $user): array
    {
        [$sc, $sp] = Authz::scopeSql($user, 'd');
        return $this->app->db->all("SELECT d.id, u.full_name FROM drivers d JOIN users u ON u.id=d.user_id AND u.is_active=1 WHERE $sc ORDER BY u.full_name", $sp);
    }
    private function vehicles4(array $user): array
    {
        [$sc, $sp] = Authz::scopeSql($user, 'd');
        return $this->app->db->all("SELECT d.id, d.internal_no, d.plate_no FROM vehicles d WHERE d.status='operational' AND $sc ORDER BY d.internal_no", $sp);
    }

    // ---------------- الدخول والحساب ----------------

    private function loginForm(Request $q, ?string $err = null, bool $mfa = false, string $username = ''): Response
    {
        return Response::html(View::render('login', ['csrf' => $this->k->session->csrf(), 'error' => $err, 'mfa' => $mfa, 'username' => $username], null));
    }

    private function login(Request $q): Response
    {
        $sent = $q->body['_csrf'] ?? '';
        if (!is_string($sent) || !hash_equals($this->k->session->csrf(), $sent)) { return $this->loginForm($q, 'انتهت صلاحية النموذج، حاول مجددًا'); }
        try {
            $uid = (new AuthService($this->app))->verifyCredentials((string)($q->body['username'] ?? ''), (string)($q->body['password'] ?? ''), ($q->body['mfa_code'] ?? '') ?: null, $q->ip);
            $user = $this->app->authz->loadUser($uid);
            if (!$user || !Authz::can($user, 'dashboard.view')) { return $this->loginForm($q, 'هذا الحساب لا يملك صلاحية دخول اللوحة'); }
            $this->k->session->login($uid);
            return Response::redirect('/');
        } catch (ApiException $e) {
            return $this->loginForm($q, $e->getMessage(), $e->errorCode === 'mfa_required' || $e->errorCode === 'mfa_invalid', (string)($q->body['username'] ?? ''));
        }
    }

    private function account(Request $q): Response
    {
        $u = $q->attrs['user'];
        $secret = $this->app->db->val('SELECT pending_mfa_secret FROM users WHERE id=?', [$u['id']]);
        $html = '<div class="grid g2"><form method="post" action="/account/password" class="card">' . $this->csrfField() . '<h2>تغيير كلمة المرور</h2><div class="f"><div><label>الحالية</label><input type="password" name="current" required autocomplete="current-password"></div><div><label>الجديدة (10 أحرف فأكثر)</label><input type="password" name="new" required minlength="10" autocomplete="new-password"></div><div class="w"><button class="btn">تغيير</button></div></div></form>';
        $html .= '<div class="card"><h2>التحقق الثنائي (TOTP)</h2>';
        if ($u['mfa_enabled']) { $html .= '<p>مفعّل لحسابك.</p><form method="post" action="/account/mfa" data-confirm="إيقاف التحقق الثنائي؟">' . $this->csrfField() . '<input type="hidden" name="act" value="disable"><div class="f"><div><label>كلمة المرور</label><input type="password" name="password" required></div><div><button class="btn bad">إيقاف</button></div></div></form>'; }
        elseif ($secret) { $html .= '<p>أضف المفتاح في تطبيق المصادقة (Google/Microsoft Authenticator) ثم أدخل الرمز:</p><p><code dir="ltr">' . e($secret) . '</code></p><p class="mut" dir="ltr">' . e(Totp::uri($secret, $u['username'])) . '</p><form method="post" action="/account/mfa">' . $this->csrfField() . '<input type="hidden" name="act" value="confirm"><div class="f"><div><label>الرمز</label><input name="code" inputmode="numeric" required></div><div><button class="btn">تفعيل</button></div></div></form>'; }
        else { $html .= '<p>طبقة حماية إضافية لحسابات الإدارة، لا تحتاج مزودًا خارجيًا.</p><form method="post" action="/account/mfa">' . $this->csrfField() . '<input type="hidden" name="act" value="begin"><button class="btn">بدء الإعداد</button></form>'; }
        return $this->page($q, 'raw', ['raw' => $html . '</div></div>'], 'حسابي', 'account');
    }

    private function mfa(Request $q): Response
    {
        return $this->act($q, '/account', 'تم تحديث إعدادات التحقق الثنائي', function () use ($q) {
            $u = $q->attrs['user']; $db = $this->app->db; $act = $q->body['act'] ?? '';
            if ($act === 'begin') { $db->update('users', ['pending_mfa_secret' => Totp::generateSecret()], 'id=?', [$u['id']]); }
            elseif ($act === 'confirm') {
                $s = (string)$db->val('SELECT pending_mfa_secret FROM users WHERE id=?', [$u['id']]);
                if (!$s || !Totp::verify($s, (string)($q->body['code'] ?? ''))) { throw new ApiException(422, 'mfa_invalid', 'الرمز غير صحيح'); }
                $db->update('users', ['mfa_secret' => $s, 'mfa_enabled' => 1, 'pending_mfa_secret' => null], 'id=?', [$u['id']]);
                $this->app->audit->log($u['id'], 'auth.mfa_enabled', 'user', $u['id']);
            } elseif ($act === 'disable') {
                (new AuthService($this->app))->verifyCredentials($u['username'], (string)($q->body['password'] ?? ''), ($q->body['code'] ?? '') ?: ($u['mfa_enabled'] ? '000000' : null), $q->ip);
                $db->update('users', ['mfa_enabled' => 0, 'mfa_secret' => null], 'id=?', [$u['id']]);
                $this->app->audit->log($u['id'], 'auth.mfa_disabled', 'user', $u['id']);
            }
        });
    }

    // ---------------- اللوحة ----------------

    private function dashboard(Request $q): Response
    {
        $u = $q->attrs['user'];
        if (!Authz::can($u, 'dashboard.view')) { throw new ApiException(403, 'forbidden', 'لا تملك الصلاحية المطلوبة'); }
        $qs = new QueryService($this->app);
        $sos = Authz::can($u, 'emergencies.view') ? $qs->emergencies($u, ['status' => 'open'], 1, 8)['data'] : [];
        $ms = Authz::can($u, 'missions.view') ? $qs->missions($u, ['status' => 'active'], 1, 8)['data'] : [];
        return $this->page($q, 'dashboard', ['k' => $qs->dashboard($u),
            'sos' => $this->tbl(['الرقم' => fn($r) => new Raw('<a href="/emergencies/' . (int)$r['id'] . '">' . e($r['incident_no']) . '</a>'), 'السائق' => 'driver_name', 'الحالة' => fn($r) => Labels::emergency($r['status']), 'وصل' => fn($r) => Labels::dt($r['received_at'])], $sos, 'لا توجد بلاغات مفتوحة'),
            'missions' => $this->tbl(['المهمة' => fn($r) => new Raw('<a href="/missions/' . (int)$r['id'] . '">' . e($r['mission_no']) . '</a>'), 'العنوان' => 'title', 'السائق' => 'driver_name', 'الحالة' => fn($r) => Labels::mission($r['status'])], $ms, 'لا توجد مهام نشطة')],
            'لوحة المؤشرات', 'dashboard');
    }

    // ---------------- المهام ----------------

    private function missions(Request $q): Response
    {
        $u = $q->attrs['user']; $f = $q->query;
        $res = (new QueryService($this->app))->missions($u, $f, (int)($f['page'] ?? 1), 25);
        $filters = '<div><label>بحث</label><input name="q" value="' . e($f['q'] ?? '') . '"></div><div><label>الحالة</label>' . $this->select('status', Labels::MISSION, $f['status'] ?? null) . '</div>'
            . '<div><label>الأولوية</label>' . $this->select('priority', Labels::PRIORITY, $f['priority'] ?? null) . '</div><div><label>السائق</label>' . $this->select('driver_id', array_column($this->drivers4($u), 'full_name', 'id'), $f['driver_id'] ?? null) . '</div>'
            . '<div><label>من</label><input type="date" name="from" value="' . e($f['from'] ?? '') . '"></div><div><label>إلى</label><input type="date" name="to" value="' . e($f['to'] ?? '') . '"></div>';
        $table = $this->tbl(['الرقم' => fn($r) => new Raw('<a href="/missions/' . (int)$r['id'] . '">' . e($r['mission_no']) . '</a>'), 'العنوان' => 'title', 'الأولوية' => fn($r) => Labels::priority($r['priority']),
            'الحالة' => fn($r) => Labels::mission($r['status']), 'السائق' => 'driver_name', 'المركبة' => 'plate_no', 'الموعد' => fn($r) => Labels::dt($r['scheduled_start'])], $res['data'], 'لا توجد مهام مطابقة');
        $actions = Authz::can($u, 'missions.create') ? '<a class="btn" href="/missions/new">+ مهمة جديدة</a>' : '';
        return $this->page($q, 'list', ['filters' => $filters, 'table' => $table, 'meta' => $res['meta'], 'actions' => $actions], 'إدارة المهام', 'missions');
    }

    private function units(): array
    {
        $o = [];
        foreach (['team', 'region', 'fleet'] as $c) { $o[$c] = array_column($this->app->db->all("SELECT DISTINCT $c AS v FROM drivers WHERE $c IS NOT NULL UNION SELECT DISTINCT $c FROM vehicles WHERE $c IS NOT NULL"), 'v'); }
        return $o;
    }

    private function missionForm(Request $q): Response
    {
        $u = $q->attrs['user'];
        $script = '<script>var m=FleetMap.init("map",' . json_encode($this->app->settings->get('map.tile_url')) . ');var mode=null,mk={};
function setMode(x){mode=x}document.getElementById("pick-o").onclick=function(){setMode("o")};document.getElementById("pick-d").onclick=function(){setMode("d")};document.getElementById("pick-s").onclick=function(){setMode("s")};
function addStop(n,la,lo){var d=document.createElement("div");d.className="f";d.innerHTML=\'<div><label>اسم النقطة</label><input name="stops[][name]"></div><div><label>خط العرض</label><input name="stops[][lat]"></div><div><label>خط الطول</label><input name="stops[][lon]"></div>\';var i=d.querySelectorAll("input");i[0].value=n||"";i[1].value=la||"";i[2].value=lo||"";document.getElementById("stops").appendChild(d)}
m.on("click",function(e){var la=e.latlng.lat.toFixed(6),lo=e.latlng.lng.toFixed(6);if(mode==="o"){document.getElementById("ola").value=la;document.getElementById("olo").value=lo;if(mk.o)m.removeLayer(mk.o);mk.o=L.marker(e.latlng).addTo(m).bindPopup("البداية")}
else if(mode==="d"){document.getElementById("dla").value=la;document.getElementById("dlo").value=lo;if(mk.d)m.removeLayer(mk.d);mk.d=L.marker(e.latlng).addTo(m).bindPopup("الوجهة")}else if(mode==="s"){addStop("نقطة توقف",la,lo);L.marker(e.latlng).addTo(m)}});
window.fillReview=function(){var f=document.getElementById("mform"),g=function(n){var x=f.elements[n];return x&&x.selectedOptions?x.selectedOptions[0].text:(x?x.value:"")};
document.getElementById("review").textContent="العنوان: "+g("title")+" | الموعد: "+g("scheduled_start")+" | السائق: "+g("driver_id")+" | المركبة: "+g("vehicle_id")+" | من: "+g("origin_name")+" إلى: "+g("dest_name")};
setTimeout(function(){m.invalidateSize()},300);document.querySelectorAll("[data-steps] .steps button").forEach(function(b){b.addEventListener("click",function(){setTimeout(function(){m.invalidateSize()},50)})});
document.querySelectorAll("[data-next]").forEach(function(b){b.addEventListener("click",function(){setTimeout(function(){m.invalidateSize()},50)})});</script>';
        return $this->page($q, 'mission_form', ['drivers' => $this->drivers4($u), 'vehicles' => $this->vehicles4($u), 'units' => $this->units()], 'مهمة جديدة', 'missions', ['map' => true, 'scripts' => $script]);
    }

    private function missionCreate(Request $q): Response
    {
        $u = $q->attrs['user']; $b = $q->body; $ms = new MissionService($this->app);
        try {
            $clean = array_map(fn($v) => $v === '' ? null : $v, $b);
            $clean['stops'] = array_values(array_filter(array_map(fn($s) => $s, $this->groupStops((array)($b['stops'] ?? []))), fn($s) => ($s['lat'] ?? '') !== '' && ($s['lon'] ?? '') !== ''));
            foreach ($clean['stops'] as &$s) { $s['name'] = $s['name'] !== '' ? $s['name'] : 'نقطة توقف'; }
            $m = $ms->create($u, $clean);
            $notes = [];
            if (!empty($b['driver_id']) || !empty($b['vehicle_id'])) {
                try { $ms->assign($u, (int)$m['id'], !empty($b['driver_id']) ? (int)$b['driver_id'] : null, !empty($b['vehicle_id']) ? (int)$b['vehicle_id'] : null, null, ($b['conflict_exception_reason'] ?? '') ?: null); }
                catch (ApiException $e) { $notes[] = 'لم يُحفظ الإسناد: ' . $e->getMessage(); }
            }
            if (!empty($b['auto_route'])) { try { $ms->proposeRoute($u, (int)$m['id'], 'auto', null); } catch (ApiException $e) { $notes[] = 'تعذر اقتراح المسار: ' . $e->getMessage(); } }
            if (!empty($b['send_now'])) { try { $ms->send($u, (int)$m['id']); } catch (ApiException $e) { $notes[] = 'لم تُرسل: ' . $e->getMessage(); } }
            $this->k->session->flash($notes ? 'err' : 'ok', $notes ? 'حُفظت المهمة مع ملاحظات: ' . implode(' | ', $notes) : 'تم حفظ المهمة ' . $m['mission_no']);
            return Response::redirect('/missions/' . $m['id']);
        } catch (ApiException $e) {
            $this->k->session->flash('err', $e->getMessage() . ($e->details ? ' — ' . implode('، ', array_map('strval', $e->details)) : ''));
            return Response::redirect('/missions/new');
        }
    }

    /** يحول stops[][name|lat|lon] المسطحة (من النموذج) إلى مجموعات من ثلاثة. */
    private function groupStops(array $flat): array
    {
        $out = []; $cur = [];
        foreach ($flat as $item) { foreach ((array)$item as $k => $v) { $cur[$k] = $v; if (count($cur) === 3) { $out[] = $cur; $cur = []; } } }
        return $out;
    }

    private function missionShow(Request $q, int $id): Response
    {
        $u = $q->attrs['user']; $db = $this->app->db;
        $ms = new MissionService($this->app);
        $m = $ms->getScoped($u, $id);
        $m += $db->one('SELECT us.full_name AS driver_name, v.plate_no FROM (SELECT 1) x LEFT JOIN drivers d ON d.id=? LEFT JOIN users us ON us.id=d.user_id LEFT JOIN vehicles v ON v.id=?', [$m['driver_id'], $m['vehicle_id']]) ?: [];
        $routes = $db->all('SELECT * FROM mission_routes WHERE mission_id=? ORDER BY version DESC', [$id]);
        $canRoute = Authz::can($u, 'missions.update_route');
        $draw = $routes ? $routes[0] : null;
        foreach ($routes as $r) { if ($r['status'] === 'approved') { $draw = $r; break; } }
        $pts = $draw ? json_decode($draw['geometry'], true) : [];
        $script = '<script>var m=FleetMap.init("map",' . json_encode($this->app->settings->get('map.tile_url')) . ');var pts=' . json_encode($pts) . ';var ep=' . json_encode([$m['origin_lat'] !== null ? [(float)$m['origin_lat'], (float)$m['origin_lon']] : null, $m['dest_lat'] !== null ? [(float)$m['dest_lat'], (float)$m['dest_lon']] : null]) . ';
var line=L.polyline(pts,{color:"#157347",weight:4}).addTo(m);ep.forEach(function(p,i){if(p)L.marker(p).addTo(m).bindPopup(i?"الوجهة":"البداية")});
var all=pts.concat(ep.filter(Boolean));if(all.length)m.fitBounds(all,{padding:[30,30],maxZoom:15});else m.setView([24.7136,46.6753],6);
var manual=[];m.on("click",function(e){' . ($canRoute ? 'manual.push([e.latlng.lat,e.latlng.lng]);line.setLatLngs(manual);line.setStyle({color:"#b26a00",dashArray:"6 6"});document.getElementById("geom").value=JSON.stringify(manual)' : '') . '});
var c=document.getElementById("clearpts");if(c)c.onclick=function(){manual=[];line.setLatLngs([]);document.getElementById("geom").value=""};</script>';
        $routesTable = $this->tbl(['الإصدار' => 'version', 'المصدر' => fn($r) => $r['source'] === 'auto' ? 'آلي (' . ($r['provider'] ?? '') . ')' : 'يدوي', 'المسافة (كم)' => fn($r) => $r['distance_m'] !== null ? round($r['distance_m'] / 1000, 1) : '—',
            'الحالة' => fn($r) => ['proposed' => 'مقترح', 'approved' => 'معتمد', 'superseded' => 'مستبدل'][$r['status']], 'أُنشئ' => fn($r) => Labels::dt($r['created_at']),
            'إجراء' => fn($r) => new Raw($canRoute && $r['status'] === 'proposed' ? '<form method="post" action="/routes/' . (int)$r['id'] . '/approve">' . $this->csrfField() . '<button class="btn sm">اعتماد</button></form>' : '')], $routes, 'لا توجد إصدارات مسار');
        $vh = $this->tbl(['المركبة' => 'plate_no', 'أسندها' => 'by_name', 'السبب' => 'reason', 'استثناء تعارض' => 'conflict_exception_reason', 'من' => fn($r) => Labels::dt($r['assigned_at']), 'تأكيد السائق' => fn($r) => Labels::dt($r['driver_confirmed_at'])],
            $db->all('SELECT h.*, v.plate_no, u.full_name AS by_name FROM vehicle_assignment_history h JOIN vehicles v ON v.id=h.vehicle_id LEFT JOIN users u ON u.id=h.assigned_by WHERE h.mission_id=? ORDER BY h.id', [$id]), 'لا يوجد إسناد مركبة');
        $sms = $this->tbl(['طُلبت' => fn($r) => Labels::dt($r['requested_at']), 'قبلها المزود' => fn($r) => $r['provider_accepted_at'] ? Labels::dt($r['provider_accepted_at']) : ($r['error'] ?? '—'), 'سُلمت للهاتف' => fn($r) => Labels::dt($r['delivered_at']), 'استلمها التطبيق' => fn($r) => Labels::dt($r['app_received_at'])],
            $db->all('SELECT * FROM sms_messages WHERE mission_id=?', [$id]), 'لم تُرسل رسائل');
        return $this->page($q, 'mission_show', ['m' => $m, 'events' => $db->all('SELECT * FROM mission_events WHERE mission_id=? ORDER BY occurred_at,id', [$id]), 'routesTable' => $routesTable, 'vhTable' => $vh, 'smsTable' => $sms,
            'drivers' => $this->drivers4($u), 'vehicles' => $this->vehicles4($u), 'canAssign' => Authz::can($u, 'missions.assign'), 'canCancel' => Authz::can($u, 'missions.cancel'), 'canRoute' => $canRoute],
            'تفاصيل المهمة', 'missions', ['map' => true, 'scripts' => $script]);
    }

    private function trip(Request $q, int $id): Response
    {
        $t = (new QueryService($this->app))->track($q->attrs['user'], $id);
        $canRev = Authz::can($q->attrs['user'], 'stops.review');
        $stops = $this->tbl(['البداية' => fn($r) => Labels::dt($r['started_at']), 'المدة (د)' => fn($r) => round($r['duration_s'] / 60, 1), 'السبب' => fn($r) => $r['reason'] ?? 'دون تفسير', 'ملاحظات' => 'notes'], $t['stops']);
        $dev = $this->tbl(['البداية' => fn($r) => Labels::dt($r['started_at']), 'أقصى انحراف' => fn($r) => $r['max_distance_m'] . ' م', 'السبب' => 'reason', 'المراجعة' => 'review_status'], $t['deviations']);
        return $this->page($q, 'trip', ['t' => $t, 'stopsTable' => $stops, 'devTable' => $dev], 'سجل الرحلة #' . $id, 'map', ['map' => true,
            'scripts' => '<script>var m=FleetMap.init("map",' . json_encode($this->app->settings->get('map.tile_url')) . ');FleetMap.track(m,' . json_encode($t, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ');</script>']);
    }

    // ---------------- أسطول ----------------

    private function vehicles(Request $q): Response
    {
        $u = $q->attrs['user'];
        [$sc, $sp] = Authz::scopeSql($u, 'v');
        $rows = $this->app->db->all("SELECT v.* FROM vehicles v WHERE $sc ORDER BY v.internal_no", $sp);
        $can = Authz::can($u, 'vehicles.manage');
        $table = $this->tbl(['الرقم الداخلي' => 'internal_no', 'اللوحة' => 'plate_no', 'النوع' => 'vehicle_type', 'الموديل' => 'model', 'الحالة' => fn($r) => ['operational' => 'تعمل', 'maintenance' => 'صيانة', 'out_of_service' => 'خارج الخدمة'][$r['status']],
            'آخر عداد' => 'last_odometer', 'انتهاء الترخيص' => 'license_expiry', 'الصيانة القادمة' => 'next_maintenance_date', 'الفريق/المنطقة/الأسطول' => fn($r) => implode(' / ', array_filter([$r['team'], $r['region'], $r['fleet']])) ?: '—'], $rows);
        $form = $can ? '<form method="post" action="/vehicles" class="card" style="margin-bottom:14px">' . $this->csrfField() . '<h2>إضافة مركبة</h2><div class="f"><div><label>الرقم الداخلي *</label><input name="internal_no" required></div><div><label>اللوحة *</label><input name="plate_no" required></div><div><label>النوع *</label><input name="vehicle_type" required></div><div><label>الموديل</label><input name="model"></div><div><label>السنة</label><input name="year" type="number"></div><div><label>انتهاء الترخيص</label><input name="license_expiry" type="date"></div><div><label>الصيانة القادمة</label><input name="next_maintenance_date" type="date"></div><div><label>الفريق</label><input name="team"></div><div><label>المنطقة</label><input name="region"></div><div><label>الأسطول</label><input name="fleet"></div><div><label>صورة العداد</label><select name="require_odometer_photo"><option value="0">اختيارية</option><option value="1">إلزامية لهذه المركبة</option></select></div><div><button class="btn">حفظ</button></div></div></form>' : '';
        return $this->page($q, 'list', ['table' => $table, 'extra' => $form], 'المركبات', 'vehicles');
    }

    private function vehicleSave(Request $q): Response
    {
        return $this->act($q, '/vehicles', 'تمت إضافة المركبة', function () use ($q) {
            $u = $q->attrs['user'];
            $d = Validator::validate($q->body, ['internal_no' => 'required|string|max:32', 'plate_no' => 'required|string|max:32', 'vehicle_type' => 'required|string|max:64', 'model' => 'string|max:96', 'year' => 'int', 'license_expiry' => 'string', 'next_maintenance_date' => 'string', 'team' => 'string|max:64', 'region' => 'string|max:64', 'fleet' => 'string|max:64', 'require_odometer_photo' => 'bool']);
            Authz::requireScope($u, $d + ['team' => null, 'region' => null, 'fleet' => null]);
            try { $id = $this->app->db->insert('vehicles', array_map(fn($v) => $v === '' ? null : $v, $d) + ['created_at' => Clock::nowStr()]); }
            catch (\PDOException $e) { if (\App\Core\Db::isDuplicate($e)) { throw new ApiException(409, 'duplicate', 'الرقم الداخلي أو اللوحة مسجلان مسبقًا'); } throw $e; }
            $this->app->audit->log($u['id'], 'vehicle.create', 'vehicle', $id);
        });
    }

    private function drivers(Request $q): Response
    {
        $u = $q->attrs['user'];
        [$sc, $sp] = Authz::scopeSql($u, 'd');
        $rows = $this->app->db->all("SELECT d.id, d.license_no, d.license_expiry, d.team, d.region, d.fleet, u.id AS uid, u.full_name, u.username, u.phone, u.is_active, u.last_login_at FROM drivers d JOIN users u ON u.id=d.user_id WHERE $sc ORDER BY u.full_name", $sp);
        $can = Authz::can($u, 'drivers.manage') && Authz::can($u, 'users.manage');
        $table = $this->tbl(['الاسم' => 'full_name', 'اسم الدخول' => 'username', 'الهاتف' => 'phone', 'الرخصة' => 'license_no', 'انتهاء الرخصة' => 'license_expiry', 'الفريق' => 'team', 'الحالة' => fn($r) => new Raw($r['is_active'] ? '<span class="badge b-ok">مفعّل</span>' : '<span class="badge b-mut">معطّل</span>'),
            'آخر دخول' => fn($r) => Labels::dt($r['last_login_at']), 'إجراء' => fn($r) => new Raw($can ? '<form method="post" action="/users/' . (int)$r['uid'] . '/active">' . $this->csrfField() . '<input type="hidden" name="active" value="' . ($r['is_active'] ? 0 : 1) . '"><button class="btn sec sm">' . ($r['is_active'] ? 'تعطيل' : 'تفعيل') . '</button></form>' : '')], $rows);
        $form = Authz::can($u, 'drivers.manage') && Authz::can($u, 'users.manage') ? '<form method="post" action="/drivers" class="card" style="margin-bottom:14px">' . $this->csrfField() . '<h2>إضافة سائق</h2><div class="f"><div><label>الاسم الكامل *</label><input name="full_name" required></div><div><label>اسم الدخول *</label><input name="username" required pattern="[A-Za-z0-9_.@-]{3,64}"></div><div><label>كلمة مرور مؤقتة (10+) *</label><input name="password" type="password" required minlength="10" autocomplete="new-password"></div><div><label>الهاتف</label><input name="phone"></div><div><label>رقم الرخصة</label><input name="license_no"></div><div><label>انتهاء الرخصة</label><input name="license_expiry" type="date"></div><div><label>الفريق</label><input name="team"></div><div><label>المنطقة</label><input name="region"></div><div><label>الأسطول</label><input name="fleet"></div><input type="hidden" name="must_change_password" value="1"><div><button class="btn">إنشاء الحساب</button></div></div></form>' : '';
        return $this->page($q, 'list', ['table' => $table, 'extra' => $form], 'السائقون والحسابات', 'drivers');
    }

    // ---------------- المراقبة ----------------

    private function stops(Request $q): Response
    {
        $u = $q->attrs['user']; $db = $this->app->db; $can = Authz::can($u, 'stops.review');
        [$sc, $sp] = Authz::scopeSql($u, 'd');
        $stops = $db->all("SELECT s.id, s.trip_id, s.started_at, s.duration_s, s.status, uu.full_name, r.name_ar AS reason, x.notes FROM trip_stops s JOIN trips t ON t.id=s.trip_id JOIN drivers d ON d.id=t.driver_id JOIN users uu ON uu.id=d.user_id LEFT JOIN stop_explanations x ON x.stop_id=s.id LEFT JOIN stop_reasons r ON r.id=x.reason_id WHERE $sc ORDER BY s.started_at DESC LIMIT 100", $sp);
        $devs = $db->all("SELECT v.*, uu.full_name FROM route_deviations v JOIN trips t ON t.id=v.trip_id JOIN drivers d ON d.id=t.driver_id JOIN users uu ON uu.id=d.user_id WHERE $sc ORDER BY v.started_at DESC LIMIT 100", $sp);
        $t1 = $this->tbl(['السائق' => 'full_name', 'البداية' => fn($r) => Labels::dt($r['started_at']), 'المدة (د)' => fn($r) => round($r['duration_s'] / 60, 1), 'الحالة' => fn($r) => $r['status'] === 'ongoing' ? 'جارٍ' : 'انتهى', 'السبب' => fn($r) => $r['reason'] ?? 'دون تفسير', 'ملاحظات' => 'notes', 'الرحلة' => fn($r) => new Raw(Authz::can($u, 'tracking.view_history') ? '<a href="/trips/' . (int)$r['trip_id'] . '">عرض</a>' : '')], $stops, 'لا توجد توقفات مسجلة');
        $t2 = $this->tbl(['السائق' => 'full_name', 'البداية' => fn($r) => Labels::dt($r['started_at']), 'النهاية' => fn($r) => $r['ended_at'] ? Labels::dt($r['ended_at']) : 'مستمر', 'أقصى انحراف' => fn($r) => $r['max_distance_m'] . ' م', 'سبب السائق' => fn($r) => $r['reason'] ?? 'لم يُسجل', 'المراجعة' => fn($r) => ['pending' => 'بانتظار', 'accepted' => 'مقبول', 'flagged' => 'مُعلَّم'][$r['review_status']],
            'إجراء' => fn($r) => new Raw($can ? '<form method="post" action="/deviations/' . (int)$r['id'] . '/review" class="row">' . $this->csrfField() . '<button class="btn sm sec" name="status" value="accepted">قبول</button><button class="btn sm sec" name="status" value="flagged">تعليم</button></form>' : '')], $devs, 'لا توجد انحرافات');
        return $this->page($q, 'raw', ['raw' => '<div class="card" style="margin-bottom:14px"><h2>التوقفات</h2><div class="tw">' . $t1 . '</div></div><div class="card"><h2>الانحرافات عن المسار</h2><div class="tw">' . $t2 . '</div><p class="mut">الانحراف والتوقف نتيجة تحليل GPS وقد يتأثر بدقة الجهاز؛ هما أدوات مراجعة وليسا مخالفة قطعية.</p></div>'], 'التوقفات والانحرافات', 'stops');
    }

    private function emergencies(Request $q): Response
    {
        $f = $q->query; $res = (new QueryService($this->app))->emergencies($q->attrs['user'], $f, (int)($f['page'] ?? 1), 25);
        $filters = '<div><label>الحالة</label>' . $this->select('status', ['open' => 'المفتوحة'] + Labels::EMERGENCY, $f['status'] ?? null) . '</div>';
        $table = $this->tbl(['الرقم' => fn($r) => new Raw('<a href="/emergencies/' . (int)$r['id'] . '">' . e($r['incident_no']) . '</a>'), 'السائق' => 'driver_name', 'النوع' => fn($r) => $r['type_name'] ?? 'غير محدد', 'الحالة' => fn($r) => Labels::emergency($r['status']), 'وصل الخادم' => fn($r) => Labels::dt($r['received_at']), 'القناة' => fn($r) => $r['channel'] === 'sms_fallback' ? 'SMS احتياطي' : 'إنترنت', 'التصعيد' => 'escalation_level'], $res['data'], 'لا توجد بلاغات');
        return $this->page($q, 'list', ['filters' => $filters, 'table' => $table, 'meta' => $res['meta']], 'مركز الطوارئ SOS', 'emergencies');
    }

    private function emergency(Request $q, int $id): Response
    {
        $u = $q->attrs['user']; $db = $this->app->db;
        $i = (new EmergencyService($this->app))->getScoped($u, $id);
        $i += $db->one('SELECT t.name_ar AS type_name, us.full_name AS driver_name, us.phone, v.plate_no, a.full_name AS ack_name, f.full_name AS followup_name FROM (SELECT 1) x LEFT JOIN emergency_types t ON t.id=? LEFT JOIN drivers d ON d.id=? LEFT JOIN users us ON us.id=d.user_id LEFT JOIN vehicles v ON v.id=? LEFT JOIN users a ON a.id=? LEFT JOIN users f ON f.id=?', [$i['type_id'], $i['driver_id'], $i['vehicle_id'], $i['acknowledged_by'], $i['followup_user_id']]) ?: [];
        $this->app->audit->log($u['id'], 'emergency.view', 'emergency', $id);
        $assignees = [];
        foreach ($db->all('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name') as $x) { $au = $this->app->authz->loadUser((int)$x['id']); if ($au && Authz::can($au, 'emergencies.view') && Authz::inScope($au, $i)) { $assignees[] = $x; } }
        $script = $i['lat'] !== null ? '<script>var m=FleetMap.init("map",' . json_encode($this->app->settings->get('map.tile_url')) . ');m.setView([' . (float)$i['lat'] . ',' . (float)$i['lon'] . '],15);L.marker([' . (float)$i['lat'] . ',' . (float)$i['lon'] . ']).addTo(m);</script>' : '';
        return $this->page($q, 'emergency_show', ['i' => $i, 'actions' => $db->all('SELECT a.*, u.full_name AS actor_name FROM emergency_actions a LEFT JOIN users u ON u.id=a.actor_user_id WHERE incident_id=? ORDER BY a.id', [$id]),
            'escalations' => $db->all('SELECT * FROM emergency_escalations WHERE incident_id=? ORDER BY level', [$id]), 'attachments' => $db->all('SELECT attachment_id AS id FROM emergency_attachments WHERE incident_id=?', [$id]), 'assignees' => $assignees,
            'can' => ['ack' => Authz::can($u, 'emergencies.acknowledge'), 'assign' => Authz::can($u, 'emergencies.assign'), 'close' => Authz::can($u, 'emergencies.close')]], 'بلاغ طوارئ', 'emergencies', ['map' => $i['lat'] !== null, 'scripts' => $script]);
    }

    private function odometer(Request $q): Response
    {
        $u = $q->attrs['user'];
        [$sc, $sp] = Authz::scopeSql($u, 'v');
        $rows = $this->app->db->all("SELECT s.*, v.plate_no, v.internal_no, uu.full_name,
            (SELECT r.photo_status FROM vehicle_odometer_readings r WHERE r.segment_id=s.id AND r.kind='start') AS sp, (SELECT r.photo_status FROM vehicle_odometer_readings r WHERE r.segment_id=s.id AND r.kind='end') AS ep,
            (SELECT r.attachment_id FROM vehicle_odometer_readings r WHERE r.segment_id=s.id AND r.kind='start') AS sa, (SELECT r.attachment_id FROM vehicle_odometer_readings r WHERE r.segment_id=s.id AND r.kind='end') AS ea
            FROM vehicle_segments s JOIN vehicles v ON v.id=s.vehicle_id JOIN trips t ON t.id=s.trip_id JOIN drivers d ON d.id=t.driver_id JOIN users uu ON uu.id=d.user_id WHERE $sc ORDER BY s.started_at DESC LIMIT 100", $sp);
        $ph = fn($st, $att) => $st === null ? '—' : ($st === 'received' ? new Raw('<a target="_blank" rel="noopener" href="/api/v1/admin/attachments/' . (int)$att . '/file">عرض</a>') : ($st === 'pending' ? 'لم تصل بعد' : 'غير مطلوبة'));
        $can = Authz::can($u, 'odometer.override');
        $table = $this->tbl(['السائق' => 'full_name', 'المركبة' => 'plate_no', 'البداية' => fn($r) => Labels::dt($r['started_at']), 'عداد البداية' => fn($r) => $r['odometer_pending'] ? new Raw('<span class="badge b-warn">قراءة معلقة</span>') : $r['start_odometer'], 'صورة البداية' => fn($r) => $ph($r['sp'], $r['sa']),
            'عداد النهاية' => fn($r) => $r['end_odometer'] ?? '—', 'صورة النهاية' => fn($r) => $ph($r['ep'], $r['ea']), 'مسافة العداد (كم)' => fn($r) => $r['odometer_distance_m'] !== null ? round($r['odometer_distance_m'] / 1000, 1) : '—', 'مسافة GPS (كم)' => fn($r) => round($r['gps_distance_m'] / 1000, 1),
            'مراجعة' => fn($r) => new Raw($r['review_flag'] ? '<span class="badge b-warn">فرق يحتاج مراجعة</span>' : ''),
            'استثناء' => fn($r) => new Raw($can ? '<form method="post" action="/odometer/' . (int)$r['id'] . '" class="row">' . $this->csrfField() . '<select name="kind" style="width:80px"><option value="start">بداية</option><option value="end">نهاية</option></select><input name="value" placeholder="القيمة" style="width:90px" required><input name="reason" placeholder="السبب" style="width:120px" required><button class="btn sm sec">إدخال</button></form>' : '')], $rows, 'لا توجد مراحل');
        return $this->page($q, 'list', ['table' => $table], 'قراءات العدادات', 'odometer');
    }

    private function featureList(Request $q, string $flag, string $perm, string $title, string $key): Response
    {
        if (!$this->app->settings->bool($flag)) {
            return $this->page($q, 'raw', ['raw' => '<div class="card empty"><h2>وحدة «' . e($title) . '» غير مفعّلة</h2><p>تُفعَّل من الإعدادات (' . e($flag) . '). مستوى إلزام الفحص لم يُعتمد بعد، وهو اختياري افتراضيًا.</p></div>'], $title, $key);
        }
        $u = $q->attrs['user'];
        [$sc, $sp] = Authz::scopeSql($u, 'v');
        if ($key === 'inspections') {
            $rows = $this->app->db->all("SELECT i.*, v.plate_no, uu.full_name FROM vehicle_inspections i JOIN vehicles v ON v.id=i.vehicle_id JOIN drivers d ON d.id=i.driver_id JOIN users uu ON uu.id=d.user_id WHERE $sc ORDER BY i.inspected_at DESC LIMIT 100", $sp);
            $yn = fn($v) => $v === null ? '—' : ($v ? 'سليم' : 'مشكلة');
            $t = $this->tbl(['المركبة' => 'plate_no', 'السائق' => 'full_name', 'المرحلة' => fn($r) => $r['phase'] === 'pre' ? 'قبل الحركة' : 'بعد العودة', 'الإطارات' => fn($r) => $yn($r['tires_ok']), 'الأنوار' => fn($r) => $yn($r['lights_ok']), 'الوقود' => 'fuel_level', 'أضرار' => fn($r) => $r['damage_noted'] ? 'نعم' : 'لا', 'ملاحظات' => 'notes', 'الوقت' => fn($r) => Labels::dt($r['inspected_at'])], $rows);
        } else {
            $rows = $this->app->db->all("SELECT f.*, v.plate_no FROM fuel_logs f JOIN vehicles v ON v.id=f.vehicle_id WHERE $sc ORDER BY f.filled_at DESC LIMIT 100", $sp);
            $t = $this->tbl(['المركبة' => 'plate_no', 'اللترات' => 'liters', 'التكلفة' => 'cost', 'العداد' => 'odometer', 'الوقت' => fn($r) => Labels::dt($r['filled_at'])], $rows, 'لا سجلات وقود. لا يُحتسب استهلاك فعلي دون مصدر موثوق.');
        }
        return $this->page($q, 'list', ['table' => $t], $title, $key);
    }

    // ---------------- التقارير ----------------

    private function reports(Request $q): Response
    {
        $f = $q->query; $type = $f['type'] ?? 'missions';
        $rep = (new ReportService($this->app))->build($q->attrs['user'], $type, $f);
        $filters = '<div><label>التقرير</label>' . $this->select('type', ReportService::TYPES, $type, 'اختر') . '</div><div><label>من</label><input type="date" name="from" value="' . e($f['from'] ?? gmdate('Y-m-d', Clock::now() - 30 * 86400)) . '"></div><div><label>إلى</label><input type="date" name="to" value="' . e($f['to'] ?? gmdate('Y-m-d')) . '"></div>';
        $qs = http_build_query(['type' => $type, 'from' => $f['from'] ?? '', 'to' => $f['to'] ?? '']);
        $actions = Authz::can($q->attrs['user'], 'reports.export') ? '<a class="btn sec" href="/reports/export?' . e($qs) . '&format=xlsx">Excel</a><a class="btn sec" href="/reports/export?' . e($qs) . '&format=csv">CSV</a><a class="btn sec" href="/reports/export?' . e($qs) . '&format=print" target="_blank">PDF / طباعة</a>' : '';
        $cols = array_combine($rep['columns'], array_map(fn($i) => fn($r) => $r[$i], array_keys($rep['columns'])));
        return $this->page($q, 'list', ['filters' => $filters, 'actions' => $actions, 'table' => $this->tbl($cols, $rep['rows'], 'لا بيانات في هذه الفترة')], $rep['title'], 'reports');
    }

    private function export(Request $q): Response
    {
        $u = $q->attrs['user']; $svc = new ReportService($this->app);
        $type = (string)($q->query['type'] ?? 'missions'); $fmt = (string)($q->query['format'] ?? 'csv');
        $rep = $svc->build($u, $type, $q->query);
        $this->app->audit->log($u['id'], 'report.export', 'report', $type, ['format' => $fmt, 'from' => $q->query['from'] ?? null, 'to' => $q->query['to'] ?? null, 'rows' => count($rep['rows'])]);
        $name = 'report-' . $type . '-' . gmdate('Ymd');
        if ($fmt === 'xlsx' && ($x = $svc->xlsx($rep)) !== null) { return Response::bytes($x, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "$name.xlsx"); }
        if ($fmt === 'print') {
            $cols = array_combine($rep['columns'], array_map(fn($i) => fn($r) => $r[$i], array_keys($rep['columns'])));
            $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>' . e($rep['title']) . '</title><link rel="stylesheet" href="/assets/css/app.css"></head><body style="background:#fff;color:#000;padding:20px"><h1>' . e($rep['title']) . '</h1><p class="mut">' . e(gmdate('Y-m-d H:i')) . ' UTC · ' . e($u['full_name']) . '</p>' . $this->tbl($cols, $rep['rows']) . '<p class="noprint"><button class="btn" onclick="print()">طباعة / حفظ PDF</button></p></body></html>';
            return Response::html($html);
        }
        return Response::bytes($svc->csv($rep), 'text/csv; charset=utf-8', "$name.csv");
    }

    // ---------------- الإدارة ----------------

    private function parseScopes(string $s): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if ($tok === 'all') { $out[] = ['all']; continue; }
            [$t, $v] = array_pad(explode(':', $tok, 2), 2, '');
            if ($t === '' || $v === '') { throw new ApiException(422, 'validation_failed', "نطاق غير صالح: $tok (الصيغة team:اسم أو all)"); }
            $out[] = [$t, $v];
        }
        return $out;
    }

    private function users(Request $q): Response
    {
        $db = $this->app->db;
        $rows = $db->all('SELECT u.id, u.username, u.full_name, u.is_active, u.mfa_enabled, u.last_login_at, (SELECT GROUP_CONCAT(r.code) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id) AS roles, (SELECT GROUP_CONCAT(CONCAT(scope_type,":",scope_value)) FROM access_scopes WHERE user_id=u.id) AS scopes FROM users u ORDER BY u.full_name');
        $roles = $db->all('SELECT code, name_ar FROM roles ORDER BY id');
        $table = $this->tbl(['الاسم' => 'full_name', 'الدخول' => 'username', 'MFA' => fn($r) => $r['mfa_enabled'] ? 'مفعّل' : '—', 'الحالة' => fn($r) => $r['is_active'] ? 'مفعّل' : 'معطّل', 'آخر دخول' => fn($r) => Labels::dt($r['last_login_at']),
            'الأدوار والنطاقات' => function ($r) use ($roles) {
                $have = explode(',', (string)$r['roles']); $h = '<form method="post" action="/users/' . (int)$r['id'] . '/access">' . $this->csrfField() . '<div class="row">';
                foreach ($roles as $ro) { $h .= '<label class="row" style="margin:0;font-weight:400"><input type="checkbox" style="width:auto;min-height:0" name="roles[]" value="' . e($ro['code']) . '"' . (in_array($ro['code'], $have, true) ? ' checked' : '') . '>' . e($ro['name_ar']) . '</label>'; }
                return new Raw($h . '</div><input name="scopes" value="' . e(str_replace(',', ' ', (string)$r['scopes'])) . '" placeholder="all أو team:A region:الرياض fleet:1" style="margin-top:6px"><button class="btn sm sec" style="margin-top:6px">حفظ</button></form>');
            }], $rows);
        $form = '<form method="post" action="/users" class="card" style="margin-bottom:14px">' . $this->csrfField() . '<h2>إضافة مستخدم إداري</h2><div class="f"><div><label>الاسم الكامل *</label><input name="full_name" required></div><div><label>اسم الدخول *</label><input name="username" required></div><div><label>البريد</label><input name="email" type="email"></div><div><label>الهاتف</label><input name="phone"></div><div><label>كلمة مرور مؤقتة *</label><input type="password" name="password" required minlength="10" autocomplete="new-password"></div><div><label>الدور *</label><select name="role">';
        foreach ($roles as $ro) { if ($ro['code'] !== 'driver') { $form .= '<option value="' . e($ro['code']) . '">' . e($ro['name_ar']) . '</option>'; } }
        $form .= '</select></div><div><label>نطاق الوصول</label><input name="scopes" placeholder="all أو team:A region:X"></div><div><button class="btn">إنشاء</button></div></div></form>';
        return $this->page($q, 'list', ['table' => $table, 'extra' => $form], 'المستخدمون والنطاقات', 'users');
    }

    private function userCreate(Request $q): Response
    {
        return $this->act($q, '/users', 'تم إنشاء المستخدم', function () use ($q) {
            $b = $q->body;
            (new UserService($this->app))->create(['username' => $b['username'] ?? '', 'full_name' => $b['full_name'] ?? '', 'password' => $b['password'] ?? '', 'email' => ($b['email'] ?? '') ?: null, 'phone' => ($b['phone'] ?? '') ?: null,
                'roles' => [(string)($b['role'] ?? '')], 'scopes' => $this->parseScopes((string)($b['scopes'] ?? '')), 'must_change_password' => 1], $q->attrs['user']);
        });
    }

    private function roles(Request $q): Response
    {
        $db = $this->app->db;
        $roles = $db->all('SELECT * FROM roles ORDER BY id'); $perms = $db->all('SELECT * FROM permissions ORDER BY code');
        $has = []; foreach ($db->all('SELECT role_id, permission_id FROM role_permissions') as $r) { $has[$r['role_id']][$r['permission_id']] = true; }
        $h = '';
        foreach ($roles as $ro) {
            $locked = $ro['code'] === 'system_admin';
            $h .= '<form method="post" action="/roles/' . (int)$ro['id'] . '" class="card" style="margin-bottom:14px">' . $this->csrfField() . '<h2>' . e($ro['name_ar']) . ' <span class="mut">(' . e($ro['code']) . ')</span></h2><div class="f">';
            foreach ($perms as $p) { $h .= '<label class="row" style="font-weight:400;margin:0"><input type="checkbox" style="width:auto;min-height:0" name="perms[]" value="' . (int)$p['id'] . '"' . (!empty($has[$ro['id']][$p['id']]) ? ' checked' : '') . ($locked ? ' disabled' : '') . '>' . e($p['description_ar']) . ' <span class="mut" dir="ltr">' . e($p['code']) . '</span></label>'; }
            $h .= '</div>' . ($locked ? '<p class="mut">دور مدير النظام يملك كل الصلاحيات ولا يُعدَّل.</p>' : '<button class="btn" style="margin-top:10px">حفظ الصلاحيات</button>') . '</form>';
        }
        return $this->page($q, 'raw', ['raw' => $h], 'الأدوار والصلاحيات', 'roles');
    }

    private function roleSave(Request $q, int $id): Response
    {
        return $this->act($q, '/roles', 'تم حفظ صلاحيات الدور', function () use ($q, $id) {
            $db = $this->app->db; $role = $db->one('SELECT * FROM roles WHERE id=?', [$id]);
            if (!$role || $role['code'] === 'system_admin') { throw new ApiException(403, 'forbidden', 'لا يمكن تعديل هذا الدور'); }
            $db->tx(function () use ($db, $id, $q) {
                $db->exec('DELETE FROM role_permissions WHERE role_id=?', [$id]);
                foreach (array_unique((array)($q->body['perms'] ?? [])) as $pid) { $db->q('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE id=?', [$id, (int)$pid]); }
            });
            $this->app->audit->log($q->attrs['user']['id'], 'role.permissions_changed', 'role', $id, ['perms' => array_map('intval', (array)($q->body['perms'] ?? []))]);
        });
    }

    private function settings(Request $q): Response
    {
        $defs = [];
        $labels = ['stop.record_after_s' => ['ثواني لتسجيل التوقف', 's'], 'stop.prompt_after_s' => ['ثواني لطلب السبب', 's'], 'stop.alert_after_s' => ['ثواني لتنبيه التوقف', 's'], 'stop.radius_m' => ['نصف قطر الثبات (م)', 's'], 'gps.max_accuracy_m' => ['أقصى خطأ دقة مقبول (م)', 's'], 'gps.poll_interval_s' => ['فاصل إرسال GPS (ث)', 's'],
            'deviation.distance_m' => ['مسافة الانحراف (م)', 's'], 'deviation.duration_s' => ['مدة الانحراف (ث)', 's'], 'arrival.min_readings' => ['قراءات مستقرة لكشف الوصول', 's'], 'odometer.review_threshold_pct' => ['عتبة مراجعة العداد/GPS %', 's'], 'odometer.photo_required' => ['صورة العداد إلزامية للجميع', 'bool'],
            'emergency.ack_timeout_s' => ['مهلة تأكيد الاستلام قبل التصعيد (ث)', 's'], 'emergency.escalation_interval_s' => ['فاصل التصعيد (ث)', 's'], 'emergency.max_escalation_level' => ['أقصى مستوى تصعيد', 's'], 'emergency.alternate_user_id' => ['معرّف مسؤول الطوارئ البديل', 's'],
            'sms.undelivered_timeout_s' => ['مهلة قبل SMS لمهمة لم تصل (ث)', 's'], 'mission.unconfirmed_sms_enabled' => ['تفعيل SMS للمهام غير المستلمة', 'bool'], 'retention.gps_days' => ['أيام الاحتفاظ بنقاط GPS (0=دائم)', 's'], 'retention.audit_days' => ['أيام الاحتفاظ بالتدقيق', 's'],
            'feature.inspection' => ['وحدة فحص المركبات', 'bool'], 'feature.inspection_mandatory' => ['إلزام الفحص (قرار معلق)', 'bool'], 'feature.fuel' => ['وحدة الوقود', 'bool'], 'feature.driving_behavior' => ['مراقبة سلوك القيادة (مستقبلية)', 'bool'],
            'map.tile_url' => ['رابط بلاطات الخريطة', 's'], 'map.live_fresh_s' => ['ثواني اعتبار الموقع مباشرًا', 's'], 'security.require_admin_mfa' => ['إلزام التحقق الثنائي للإداريين', 'bool']];
        foreach ($labels as $k => $l) { $defs[$k] = $l; }
        $yes = fn($b) => $b ? '<span class="badge b-ok">مهيأ</span>' : '<span class="badge b-mut">غير مهيأ</span>';
        $int = [['Firebase Push (FCM)', $yes((bool)Env::get('FCM_SERVICE_ACCOUNT_JSON'))], ['مزود SMS', $yes((bool)Env::get('SMS_API_URL')) . ' ' . e($this->app->sms->name())], ['مزود المسارات', e($this->app->routing->name())], ['بلاطات الخريطة', e($this->app->settings->get('map.tile_url'))]];
        $it = $this->tbl(['الخدمة' => fn($r) => $r[0], 'الحالة' => fn($r) => new Raw($r[1])], $int);
        return $this->page($q, 'settings', ['defs' => $defs, 'vals' => $this->app->settings->all(), 'integrations' => $it], 'الإعدادات العامة والتكاملات', 'settings');
    }

    private function audit(Request $q): Response
    {
        $f = $q->query; $page = max(1, (int)($f['page'] ?? 1));
        $w = ['1=1']; $p = [];
        if (!empty($f['action'])) { $w[] = 'a.action LIKE ?'; $p[] = addcslashes((string)$f['action'], '%_') . '%'; }
        $where = implode(' AND ', $w);
        $res = (new QueryService($this->app))->paged("SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE $where ORDER BY a.id DESC", "SELECT COUNT(*) FROM audit_logs a WHERE $where", $p, $page, 50);
        $bad = $this->app->audit->verify();
        $extra = '<div class="flash' . ($bad ? ' e' : '') . '">' . ($bad ? 'تحذير: السلسلة مكسورة عند السجل #' . (int)$bad : 'سلسلة التجزئة سليمة (لم يُكتشف أي تلاعب).') . '</div>';
        $table = $this->tbl(['#' => 'id', 'الوقت (UTC)' => 'created_at', 'المستخدم' => fn($r) => $r['username'] ?? 'النظام', 'الإجراء' => 'action', 'الكيان' => fn($r) => trim($r['entity_type'] . ' ' . $r['entity_id']), 'تفاصيل' => 'details', 'IP' => 'ip'], $res['data']);
        return $this->page($q, 'list', ['filters' => '<div><label>الإجراء يبدأ بـ</label><input name="action" value="' . e($f['action'] ?? '') . '"></div>', 'table' => $table, 'meta' => $res['meta'], 'extra' => $extra], 'سجل التدقيق', 'audit');
    }
}
