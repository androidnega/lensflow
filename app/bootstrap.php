<?php
declare(strict_types=1);

final class App
{
    private array $config;
    private PDO $db;
    private ?array $user = null;
    private string $path = '/';

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->startSession();
        $this->db = $this->connectDatabase();
        $this->ensureSchema();
        $this->seedDefaults();
        $this->user = $this->currentUser();
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($basePath && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        $path = '/' . trim($path, '/');
        $this->path = $path === '' ? '/' : $path;

        if ($method === 'POST') {
            $this->verifyCsrf();
        }

        $routes = [
            'GET' => [
                '/' => 'home',
                '/packages' => 'packagesIndex',
                '/packages/wedding' => 'packagesWedding',
                '/packages/baby' => 'packagesBaby',
                '/packages/studio' => 'packagesStudio',
                '/register' => 'registerForm',
                '/login' => 'loginForm',
                '/logout' => 'logout',
                '/auth/otp' => 'otpForm',
                '/sms/dlr' => 'smsDeliveryReport',
                '/client/dashboard' => 'clientDashboard',
                '/client/new-booking' => 'clientNewBooking',
                '/client/bookings' => 'clientBookings',
                '/client/booking' => 'clientBookingDetail',
                '/client/contract-download' => 'downloadContractPdf',
                '/client/payments' => 'clientPayments',
                '/client/files' => 'clientFiles',
                '/client/profile' => 'clientProfile',
                '/download' => 'downloadFile',
                '/admin' => 'adminGate',
                '/dashboard' => 'adminDashboard',
                '/dashboard/bookings' => 'adminBookings',
                '/dashboard/booking' => 'adminBookingDetail',
                '/dashboard/payments' => 'adminPayments',
                '/dashboard/packages' => 'adminPackages',
                '/dashboard/coupons' => 'adminCoupons',
                '/dashboard/clients' => 'adminClients',
                '/dashboard/reports' => 'adminReports',
                '/dashboard/settings' => 'adminSettings',
                '/dashboard/slides' => 'adminSlides',
                // Legacy admin page URLs → handled via redirectLegacyAdmin
                '/admin/bookings' => 'redirectLegacyAdmin',
                '/admin/booking' => 'redirectLegacyAdmin',
                '/admin/payments' => 'redirectLegacyAdmin',
                '/admin/packages' => 'redirectLegacyAdmin',
                '/admin/coupons' => 'redirectLegacyAdmin',
                '/admin/clients' => 'redirectLegacyAdmin',
                '/admin/reports' => 'redirectLegacyAdmin',
                '/admin/settings' => 'redirectLegacyAdmin',
                '/admin/slides' => 'redirectLegacyAdmin',
            ],
            'POST' => [
                '/register' => 'requestOtp',
                '/admin/login' => 'adminLogin',
                '/auth/otp' => 'verifyOtp',
                '/auth/otp-resend' => 'requestOtp',
                '/book' => 'createBooking',
                '/client/payment-submit' => 'submitPayment',
                '/client/contract-accept' => 'acceptContract',
                '/client/profile' => 'updateProfile',
                '/dashboard/payment-verify' => 'verifyPayment',
                '/dashboard/payment-reject' => 'rejectPayment',
                '/dashboard/booking-status' => 'updateBookingStatus',
                '/dashboard/timeline-add' => 'addTimeline',
                '/dashboard/file-upload' => 'uploadDelivery',
                '/dashboard/package-save' => 'savePackage',
                '/dashboard/package-delete' => 'deletePackage',
                '/dashboard/coupon-save' => 'saveCoupon',
                '/dashboard/coupon-toggle' => 'toggleCoupon',
                '/dashboard/settings' => 'saveSettings',
                '/dashboard/slide-upload' => 'uploadHomeSlide',
                '/dashboard/slide-delete' => 'deleteHomeSlide',
                '/dashboard/slide-move' => 'moveHomeSlide',
                // Keep old POST endpoints working
                '/admin/payment-verify' => 'verifyPayment',
                '/admin/payment-reject' => 'rejectPayment',
                '/admin/booking-status' => 'updateBookingStatus',
                '/admin/timeline-add' => 'addTimeline',
                '/admin/file-upload' => 'uploadDelivery',
                '/admin/package-save' => 'savePackage',
                '/admin/package-delete' => 'deletePackage',
                '/admin/coupon-save' => 'saveCoupon',
                '/admin/coupon-toggle' => 'toggleCoupon',
                '/admin/settings' => 'saveSettings',
                '/admin/slide-upload' => 'uploadHomeSlide',
                '/admin/slide-delete' => 'deleteHomeSlide',
                '/admin/slide-move' => 'moveHomeSlide',
            ],
        ];

        $handler = $routes[$method][$path] ?? null;
        if (!$handler && $method === 'GET' && preg_match('#^/package/([a-z0-9\-]+)$#', $path, $m)) {
            $_GET['slug'] = $m[1];
            $handler = 'packageDetail';
        }
        if (!$handler) {
            $this->notFound();
            return;
        }

        try {
            $this->{$handler}();
        } catch (Throwable $e) {
            http_response_code(500);
            $this->render('Error', '<div class="max-w-xl mx-auto py-16"><div class="rounded-3xl bg-red-50 border border-red-100 p-6"><h1 class="text-xl font-bold text-red-800">Something went wrong</h1><p class="mt-2 text-sm text-red-700">'.htmlspecialchars($e->getMessage()).'</p></div></div>');
        }
    }

    private function startSession(): void
    {
        session_name($this->config['session_name'] ?? 'lensflow_session');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    private function connectDatabase(): PDO
    {
        $db = $this->config['database'] ?? [];
        $driver = $db['driver'] ?? 'sqlite';
        if ($driver !== 'sqlite') {
            throw new RuntimeException('This packaged edition uses SQLite for zero-setup deployment.');
        }
        $path = $db['path'] ?? (__DIR__ . '/../storage/database.sqlite');
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    private function ensureSchema(): void
    {
        $schema = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role TEXT NOT NULL DEFAULT 'client',
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    phone TEXT NOT NULL UNIQUE,
    email TEXT,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    category TEXT NOT NULL DEFAULT 'wedding',
    price REAL NOT NULL,
    deposit_percent REAL NOT NULL DEFAULT 50,
    turnaround_days INTEGER NOT NULL DEFAULT 14,
    deliverables TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    package_id INTEGER,
    type TEXT NOT NULL DEFAULT 'percent',
    value REAL NOT NULL,
    max_uses INTEGER NOT NULL DEFAULT 0,
    uses INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    FOREIGN KEY(package_id) REFERENCES packages(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_code TEXT NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    package_id INTEGER NOT NULL,
    event_type TEXT,
    event_date TEXT,
    event_location TEXT,
    notes TEXT,
    subtotal REAL NOT NULL,
    discount REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL,
    deposit_required REAL NOT NULL,
    coupon_code TEXT,
    payment_status TEXT NOT NULL DEFAULT 'unpaid',
    status TEXT NOT NULL DEFAULT 'awaiting_payment',
    contract_accepted INTEGER NOT NULL DEFAULT 0,
    contract_accepted_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(package_id) REFERENCES packages(id)
);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    payment_type TEXT NOT NULL DEFAULT 'deposit',
    network TEXT NOT NULL DEFAULT 'MTN',
    sender_number TEXT,
    momo_reference TEXT NOT NULL,
    system_reference TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    admin_note TEXT,
    submitted_at TEXT NOT NULL,
    verified_at TEXT,
    verified_by INTEGER,
    FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS timeline (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    due_date TEXT,
    completed_at TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT,
    file_size INTEGER NOT NULL DEFAULT 0,
    category TEXT NOT NULL DEFAULT 'final',
    uploaded_at TEXT NOT NULL,
    FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS timeline_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    due_rule TEXT NOT NULL DEFAULT 'booking',
    due_offset INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS home_slides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stored_name TEXT NOT NULL,
    original_name TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS sms_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL DEFAULT 'log',
    phone TEXT NOT NULL,
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'logged',
    segments INTEGER NOT NULL DEFAULT 1,
    cost REAL NOT NULL DEFAULT 0,
    response TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phone TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    intent TEXT NOT NULL DEFAULT 'register',
    package_id INTEGER NOT NULL DEFAULT 0,
    tries INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    consumed_at TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_otp_phone ON otp_codes(phone);
SQL;
        $this->db->exec($schema);
        $this->ensureColumn('packages', 'category', "category TEXT NOT NULL DEFAULT 'wedding'");
        $this->ensureColumn('coupons', 'package_id', 'package_id INTEGER');
        $this->ensureColumn('bookings', 'contract_text_snapshot', 'contract_text_snapshot TEXT');
        $this->ensureColumn('bookings', 'contract_signer_name', 'contract_signer_name TEXT');
        $this->ensureColumn('bookings', 'contract_signature', 'contract_signature TEXT');
        $this->ensureColumn('bookings', 'contract_ip', 'contract_ip TEXT');
        $this->ensureColumn('bookings', 'contract_file', 'contract_file TEXT');
        $this->ensureColumn('bookings', 'wedding_date', 'wedding_date TEXT');
        $this->ensureColumn('bookings', 'engagement_date', 'engagement_date TEXT');
        $this->ensureColumn('bookings', 'prior_payment_amount', 'prior_payment_amount REAL NOT NULL DEFAULT 0');
        $this->ensureColumn('bookings', 'prior_payment_note', 'prior_payment_note TEXT');
        $this->ensureColumn('bookings', 'addon_summary', 'addon_summary TEXT');
        $this->ensureColumn('bookings', 'addon_total', 'addon_total REAL NOT NULL DEFAULT 0');
        $this->ensureColumn('packages', 'cover_image', 'cover_image TEXT');
        $this->seedPackageCovers();
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $cols = array_column($this->db->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
        if (!in_array($column, $cols, true)) {
            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
        }
    }

    private function seedDefaults(): void
    {
        $this->seedCataloguePackages();
        $this->syncWeddingPackages();

        $this->syncAdminAccount();
        $defaultWeddingContract = $this->defaultWeddingContractText();
        $defaultGeneralContract = $this->defaultGeneralContractText();

        $defaults = [
            'app_name' => 'iBuk.online',
            'photographer_name' => (string)($this->config['photographer_name'] ?? 'iBuk.online'),
            'momo_number' => (string)($this->config['momo_number'] ?? ''),
            'momo_account_name' => (string)($this->config['momo_account_name'] ?? ''),
            'momo_network' => (string)($this->config['momo_network'] ?? 'MTN'),
            'whatsapp_number' => (string)($this->config['whatsapp_number'] ?? '0541069241'),
            'sms_provider' => (string)(($this->config['sms']['driver'] ?? 'log') === 'webhook' ? 'log' : ($this->config['sms']['driver'] ?? 'log')),
            'sms_sender' => 'iBuk',
            'sms_arkesel_api_key' => (string)(($this->config['sms']['arkesel_api_key'] ?? null) ?: ($this->config['sms']['api_key'] ?? '')),
            'sms_moolre_vas_key' => (string)($this->config['sms']['moolre_vas_key'] ?? ''),
            'sms_unit_cost' => '0.04',
            'home_headline' => 'Beauty, held still.',
            'home_title' => 'Weddings, portraits, and days worth keeping.',
            'home_lead' => 'Book a session in minutes. Pay with MoMo. Follow every step in one quiet place.',
            'home_cta' => 'Explore packages',
            'cat_wedding_label' => 'Wedding & Engagement',
            'cat_wedding_short' => 'Wedding',
            'cat_wedding_blurb' => 'Coverage for weddings, engagements and celebrations.',
            'cat_baby_label' => 'Baby Dedication & Christening',
            'cat_baby_short' => 'Baby',
            'cat_baby_blurb' => 'Packages for baby dedication and christening days.',
            'cat_studio_label' => 'Studio Shoot',
            'cat_studio_short' => 'Studio',
            'cat_studio_blurb' => 'Professional studio portrait sessions.',
            'wedding_booking_percent' => '80',
            'wedding_balance_percent' => '20',
            'contract_text' => $defaultWeddingContract,
            'general_contract_text' => $defaultGeneralContract,
            'cheat_sheet_text' => "SESSION CHEAT SHEET\n\n• Confirm your booking details and preferred time with the studio.\n• Pay via MTN MoMo using your booking reference — you can pay in parts.\n• Arrive on time with outfits/props ready for the shoot.\n• Soft copies and package items are released after payment is cleared.\n• Message the studio on WhatsApp if anything changes.",
            'studio_note' => "After payment verification, your booking becomes active. Complete the service agreement in the portal, then follow every stage on your timeline.",
            'studio_signer_name' => 'iBuk.online Studio',
            'otp_sms_template' => 'Your {app} code is {otp}. It expires in 10 minutes.',
        ];
        $stmt = $this->db->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)");
        foreach ($defaults as $k => $v) $stmt->execute([$k,$v]);
        $copyRefresh = [
            'home_headline' => ['Moments that last.', 'Beauty, held still.'],
            'home_title' => ['Book your next shoot in minutes.', 'Weddings, portraits, and days worth keeping.'],
            'home_lead' => [
                'Wedding, baby & studio packages — pay with MoMo and follow everything in one place.',
                'Book a session in minutes. Pay with MoMo. Follow every step in one quiet place.',
            ],
            'home_cta' => ['View packages', 'Explore packages'],
            'studio_note' => [
                'After payment verification, your booking becomes active. Complete the contract or cheat sheet in the portal, then follow every stage on your timeline.',
                'After payment verification, your booking becomes active. Complete the service agreement in the portal, then follow every stage on your timeline.',
            ],
        ];
        $refreshStmt = $this->db->prepare("INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
        foreach ($copyRefresh as $key => [$old, $new]) {
            if ($this->setting($key) === $old) $refreshStmt->execute([$key, $new]);
        }
        $legacyContract = "PHOTOGRAPHY SERVICE AGREEMENT (Wedding & Engagement)\n\nThis agreement is between the Studio and the Client named in this booking.\n\n1. Booking confirmation\nThe Client confirms the selected package, event details and pricing shown in the portal.\n\n2. Payments\nWedding & engagement bookings follow the payment schedule shown in the portal. Partial payments are allowed. Work is scheduled after the booking payment is verified. Final deliverables remain subject to clearing the remaining balance unless otherwise agreed in writing.\n\n3. Schedule & delivery\nEstimated turnaround follows the package terms and the project timeline in the portal. Dates may shift by mutual agreement.\n\n4. Usage & ownership\nThe Studio retains copyright in the images. The Client receives personal-use soft copies as listed in the package. Commercial use requires prior written consent.\n\n5. Cancellation\nVerified booking payments are non-refundable, except where the Studio cancels the booking.\n\n6. Acceptance\nBy accepting this agreement in the portal, the Client agrees to these terms.";
        $currentContract = $this->setting('contract_text');
        if ($currentContract === '' || $currentContract === $legacyContract || $currentContract === $defaultGeneralContract) {
            $refreshStmt->execute(['contract_text', $defaults['contract_text']]);
        }
        $currentGeneralContract = $this->setting('general_contract_text');
        if ($currentGeneralContract === '' || $currentGeneralContract === $legacyContract) {
            $refreshStmt->execute(['general_contract_text', $defaults['general_contract_text']]);
        }
        $this->db->prepare("UPDATE timeline_templates SET description=? WHERE title='2 · Confirm terms' AND (description='Wedding/engagement: sign contract. Other shoots: acknowledge cheat sheet.' OR description='Accept the contract or cheat sheet, then get ready for the shoot.')")
            ->execute(['Review and accept the service agreement, then get ready for the shoot.']);
        // Rename legacy brands to iBuk.online once
        $legacy = ['Mhannuellens', 'LensFlow', 'Mhannuellens Studio'];
        $app = $this->setting('app_name');
        if ($app === '' || in_array($app, $legacy, true)) {
            $this->db->prepare("INSERT INTO settings (key,value) VALUES ('app_name',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute(['iBuk.online']);
        }
        $photo = $this->setting('photographer_name');
        if ($photo === '' || in_array($photo, $legacy, true)) {
            $this->db->prepare("INSERT INTO settings (key,value) VALUES ('photographer_name',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute(['iBuk.online']);
        }
        $signer = $this->setting('studio_signer_name');
        if ($signer === '' || in_array($signer, $legacy, true) || str_contains($signer, 'Mhannuellens') || str_contains($signer, 'LensFlow')) {
            $this->db->prepare("INSERT INTO settings (key,value) VALUES ('studio_signer_name',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute(['iBuk.online Studio']);
        }

        $this->seedTimelineTemplates();
        $this->seedHomeSlides();
    }

    private function seedCataloguePackages(): void
    {
        $count = (int)$this->db->query("SELECT COUNT(*) FROM packages")->fetchColumn();
        if ($count > 0) return;

        $now = $this->now();
        $packages = [
            ['ProBasic','probasic','wedding','Engagement coverage with framed prints and soft-copy delivery. No video.',3659.99,80,14,"1 A3 frame\n8 Retouched pictures\n8GB Pen drive for soft copy pictures\nOver 165 soft copy pictures\nEngagement only\nNo Video"],
            ['Ultra','ultra','wedding','Wedding coverage with video, framed prints and Google Drive delivery.',4499,80,21,"2 A3 frames\n12 Retouched Pictures\nPictures on Google Drive\n8GB Pen drive for softcopy pictures\nOver 200 soft copy pictures\nWedding Video only"],
            ['Premium','premium','wedding','Full wedding & engagement coverage with photobook, pre-wedding and drone.',6600,80,28,"A4 Photobook\nOver 300 soft copy pictures\nWedding & Engagement Video\n32GB Pen drive for the soft copy pictures / Videos\nPre Wedding Pictures / Video\nDrone"],
            ['Gold','gold','wedding','Top-tier wedding & engagement package with photobook, pre-wedding and drone.',7000,80,30,"2 A3 frames\nPhotobook\nOver 370 soft copy pictures\nWedding & Engagement Video\n64GB Pen drive for the soft copy pictures and Videos\nPre Wedding Video\nPre Wedding Pictures\nDrone"],
            ['Baby Package 1','baby-1','baby','Baby dedication & christening package with WhatsApp delivery and an A4 wooden frame.',1250,0,7,"40+ soft copy pictures\nSent via WhatsApp\nA4 wooden frame"],
            ['Baby Package 2','baby-2','baby','Baby dedication & christening package with pendrive delivery and an A3 wooden frame.',1650,0,10,"65+ soft copy pictures\nPendrive\nA3 wooden frame"],
            ['Baby Package 3','baby-3','baby','Baby dedication & christening package with frames, pendrive and Google Drive backup.',2300,0,14,"100+ soft copy pictures\n2 A3 wooden frames\nPendrive\nBackup on Google Drive"],
            ['Glow','studio-glow','studio','Studio portrait session with one look and fully retouched pictures.',200,0,3,"3 Pictures\n1 Dress\nAll Retouched"],
            ['Signature','studio-signature','studio','Studio portrait session with two looks and fully retouched pictures.',350,0,5,"5 Pictures\n2 Dresses\nAll Retouched"],
            ['Prestige','studio-prestige','studio','Studio portrait session with three looks and fully retouched pictures.',500,0,5,"8 Pictures\n3 Dresses\nAll Retouched"],
        ];
        $stmt = $this->db->prepare("INSERT INTO packages (name,slug,category,description,price,deposit_percent,turnaround_days,deliverables,active,created_at) VALUES (?,?,?,?,?,?,?,?,1,?)");
        foreach ($packages as $p) {
            $stmt->execute([$p[0],$p[1],$p[2],$p[3],$p[4],$p[5],$p[6],$p[7],$now]);
        }
    }

    private function seedTimelineTemplates(): void
    {
        $count = (int)$this->db->query("SELECT COUNT(*) FROM timeline_templates")->fetchColumn();
        if ($count > 0) return;
        $now = $this->now();
        $steps = [
            ['1 · Book & pay','Client picks a package, books, then pays via MoMo (partial OK).','booking',0,1],
            ['2 · Confirm terms','Review and accept the service agreement, then get ready for the shoot.','booking',1,2],
            ['3 · Shoot day','Studio covers the event or session on the booked date.','event',0,3],
            ['4 · Edit & polish','Photos are selected, edited and retouched to package specs.','turnaround',-3,4],
            ['5 · Images ready','Soft copies appear in the client portal for download.','turnaround',0,5],
        ];
        $stmt = $this->db->prepare("INSERT INTO timeline_templates (title,description,due_rule,due_offset,sort_order,active,created_at) VALUES (?,?,?,?,?,1,?)");
        foreach ($steps as $s) {
            $stmt->execute([$s[0],$s[1],$s[2],$s[3],$s[4],$now]);
        }
    }

    private function syncWeddingPackages(): void
    {
        $updates = [
            'probasic' => [
                'name' => 'ProBasic',
                'description' => 'Engagement-only photo coverage with framed prints and soft-copy delivery.',
                'price' => 3659.99,
                'turnaround_days' => 14,
                'deliverables' => "1 A3 frame\n8 Retouched pictures\n8GB Pen drive for soft copy pictures\nOver 165 soft copy pictures\nEngagement only\nNo Video",
            ],
            'ultra' => [
                'name' => 'Ultra',
                'description' => 'Wedding-day coverage with video, framed prints and Google Drive delivery.',
                'price' => 4499.0,
                'turnaround_days' => 21,
                'deliverables' => "2 A3 frames\n12 Retouched Pictures\nPictures on Google Drive\n8GB Pen drive for softcopy pictures\nOver 200 soft copy pictures\nWedding Video only",
            ],
            'premium' => [
                'name' => 'Premium',
                'description' => 'Wedding and engagement coverage with photobook, pre-wedding shoot and drone.',
                'price' => 6600.0,
                'turnaround_days' => 28,
                'deliverables' => "A4 Photobook\nOver 300 soft copy pictures\nWedding & Engagement Video\n32GB Pen drive for the soft copy pictures / Videos\nPre Wedding Pictures / Video\nDrone",
            ],
            'gold' => [
                'name' => 'Gold',
                'description' => 'Top-tier wedding and engagement coverage with photobook, pre-wedding and drone.',
                'price' => 7000.0,
                'turnaround_days' => 30,
                'deliverables' => "2 A3 frames\nPhotobook\nOver 370 soft copy pictures\nWedding & Engagement Video\n64GB Pen drive for the soft copy pictures and Videos\nPre Wedding Video\nPre Wedding Pictures\nDrone",
            ],
        ];
        $stmt = $this->db->prepare("UPDATE packages SET name=?, description=?, price=?, turnaround_days=?, deliverables=?, active=1 WHERE slug=?");
        foreach ($updates as $slug => $item) {
            $stmt->execute([
                $item['name'],
                $item['description'],
                $item['price'],
                $item['turnaround_days'],
                $item['deliverables'],
                $slug,
            ]);
        }
    }

    private function syncAdminAccount(): void
    {
        $login = trim((string)($this->config['admin_login'] ?? 'admin'));
        $phone = $this->normalizePhone((string)($this->config['admin_phone'] ?? '0200000000'));
        $password = (string)($this->config['admin_password'] ?? 'ChangeMe123!');
        $email = (string)($this->config['admin_email'] ?? 'admin@example.com');
        $firstName = $login !== '' ? ucfirst($login) : 'Admin';

        $admin = $this->db->query("SELECT * FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetch();
        if (!$admin) {
            $existingPhone = null;
            if ($phone !== '') {
                $check = $this->db->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
                $check->execute([$phone]);
                $existingPhone = $check->fetchColumn() ?: null;
            }
            if ($existingPhone) {
                $phone = '0200000000';
            }
            $stmt = $this->db->prepare("INSERT INTO users (role,first_name,last_name,phone,email,password_hash,created_at) VALUES ('admin',?,?,?,?,?,?)");
            $stmt->execute([$firstName, 'Admin', $phone, $email, password_hash($password, PASSWORD_DEFAULT), $this->now()]);
            return;
        }

        $nextPhone = $phone;
        if ($phone !== '') {
            $check = $this->db->prepare("SELECT id FROM users WHERE phone=? AND id<>? LIMIT 1");
            $check->execute([$phone, $admin['id']]);
            if ($check->fetchColumn()) {
                $nextPhone = (string)$admin['phone'];
            }
        }
        $this->db->prepare("UPDATE users SET first_name=?, last_name=?, phone=?, email=?, password_hash=? WHERE id=?")
            ->execute([$firstName, 'Admin', $nextPhone, $email, password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }

    private function seedHomeSlides(): void
    {
        $dir = __DIR__.'/../assets/slides';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $now = $this->now();
        $seeds = [
            ['couple-embrace.jpg', 'Garden embrace'],
            ['bridal-lilies.jpg', 'Bridal lilies'],
            ['couple-celebration.jpg', 'Wedding portrait'],
            ['bridal-couture.jpg', 'Couture portrait'],
            ['studio-blue.jpg', 'Studio portrait'],
            ['bridal-garden.jpg', 'Garden portrait'],
            ['couple-venue.jpg', 'Venue portrait'],
        ];
        $legacy = ['look-01.jpg', 'look-02.jpg', 'look-03.jpg', 'couple.jpg', 'bridal-party.jpg'];
        $existing = $this->db->query("SELECT stored_name FROM home_slides")->fetchAll(PDO::FETCH_COLUMN);
        $onlyLegacy = $existing !== [] && array_diff($existing, $legacy) === [];
        if ($existing !== [] && !$onlyLegacy) return;
        if ($onlyLegacy) {
            $this->db->exec("DELETE FROM home_slides");
        }
        $stmt = $this->db->prepare("INSERT INTO home_slides (stored_name,original_name,sort_order,active,created_at) VALUES (?,?,?,1,?)");
        foreach ($seeds as $i => [$file, $label]) {
            if (!is_file($dir.'/'.$file)) continue;
            $stmt->execute([$file, $label, $i + 1, $now]);
        }
    }

    private function seedPackageCovers(): void
    {
        $defaults = [
            'wedding' => 'cover-wedding.jpg',
            'baby' => 'cover-baby.jpg',
            'studio' => 'cover-studio.jpg',
        ];
        $dir = __DIR__.'/../assets/packages';
        $stmt = $this->db->prepare("UPDATE packages SET cover_image=? WHERE (cover_image IS NULL OR cover_image='') AND category=?");
        foreach ($defaults as $cat => $file) {
            if (!is_file($dir.'/'.$file)) continue;
            $stmt->execute([$file, $cat]);
        }
    }

    private function homeSlides(): array
    {
        return $this->db->query("SELECT * FROM home_slides WHERE active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    }

    private function homeLookbook(): array
    {
        $dir = __DIR__.'/../assets/home';
        $items = [
            ['couple-embrace.jpg', 'Bride and groom in a quiet garden'],
            ['bridal-lilies.jpg', 'Bride with calla lilies'],
            ['couple-celebration.jpg', 'Wedding portrait of the couple'],
            ['bridal-couture.jpg', 'Couture bridal portrait'],
            ['studio-blue.jpg', 'Studio portrait in powder blue'],
            ['bridal-garden.jpg', 'Outdoor bridal portrait'],
            ['couple-venue.jpg', 'Bride and groom at the venue'],
        ];
        $out = [];
        foreach ($items as [$file, $alt]) {
            if (!is_file($dir.'/'.$file)) continue;
            $out[] = ['file' => $file, 'alt' => $alt, 'src' => $this->url('/assets/home/'.$file)];
        }
        return $out;
    }

    private function packageCategoryMeta(): array
    {
        return [
            'wedding' => [
                'label' => $this->cfg('cat_wedding_label', 'Wedding & Engagement'),
                'short' => $this->cfg('cat_wedding_short', 'Wedding'),
                'blurb' => $this->cfg('cat_wedding_blurb', 'Coverage for weddings, engagements and celebrations.'),
            ],
            'baby' => [
                'label' => $this->cfg('cat_baby_label', 'Baby Dedication & Christening'),
                'short' => $this->cfg('cat_baby_short', 'Baby'),
                'blurb' => $this->cfg('cat_baby_blurb', 'Packages for baby dedication and christening days.'),
            ],
            'studio' => [
                'label' => $this->cfg('cat_studio_label', 'Studio Shoot'),
                'short' => $this->cfg('cat_studio_short', 'Studio'),
                'blurb' => $this->cfg('cat_studio_blurb', 'Professional studio portrait sessions.'),
            ],
        ];
    }

    private function packageSections(bool $showBook = false, ?string $onlyCategory = null, bool $withHeading = true): string
    {
        $packages = $this->db->query("SELECT * FROM packages WHERE active=1 ORDER BY CASE category WHEN 'wedding' THEN 1 WHEN 'baby' THEN 2 WHEN 'studio' THEN 3 ELSE 4 END, price ASC")->fetchAll();
        $groups = [];
        foreach ($packages as $p) {
            $cat = (string)($p['category'] ?? 'wedding');
            if ($onlyCategory !== null && $cat !== $onlyCategory) continue;
            $groups[$cat][] = $p;
        }
        $meta = $this->packageCategoryMeta();
        $html = '';
        foreach ($groups as $cat => $items) {
            $label = $meta[$cat]['label'] ?? ucfirst($cat);
            $blurb = $meta[$cat]['blurb'] ?? '';
            $cols = count($items) >= 4 ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-3';
            $cards = '';
            foreach ($items as $p) $cards .= $this->packageCard($p, $showBook);
            $heading = '';
            if ($withHeading) {
                $heading = '<div class="mb-6 flex items-start gap-4"><span class="mt-1 grid h-12 w-12 place-items-center rounded-2xl bg-stone-950 text-stone-100">'.$this->categoryIcon($cat,'h-6 w-6').'</span><div><p class="text-sm font-semibold text-stone-500">'.htmlspecialchars($this->cfg('app_name','iBuk.online')).'</p><h2 class="text-2xl sm:text-3xl font-black text-stone-950">'.htmlspecialchars($label).'</h2><p class="mt-2 text-stone-600">'.htmlspecialchars($blurb).'</p></div></div>';
            }
            $html .= '<section id="packages-'.htmlspecialchars($cat).'" class="'.($withHeading ? 'mt-12 first:mt-0 ' : '').'scroll-mt-28">'.$heading.'<div class="grid '.$cols.' gap-4">'.$cards.'</div></section>';
        }
        return $html;
    }

    private function home(): void
    {
        $slides = $this->homeSlides();
        $lookbook = $this->homeLookbook();
        $app = htmlspecialchars($this->cfg('app_name', 'iBuk.online'));
        $headline = htmlspecialchars($this->cfg('home_headline', 'Beauty, held still.'));
        $support = htmlspecialchars($this->cfg('home_title', 'Weddings, portraits, and days worth keeping.'));
        $lead = htmlspecialchars($this->cfg('home_lead', 'Book a session in minutes. Pay with MoMo. Follow every step in one quiet place.'));
        $cta = htmlspecialchars($this->cfg('home_cta', 'Explore packages'));

        $bookNowHref = $this->user && ($this->user['role'] ?? '') === 'client'
            ? $this->url('/packages')
            : $this->url('/register');

        if ($slides === [] && $lookbook !== []) {
            foreach ($lookbook as $item) {
                $slides[] = ['stored_name' => $item['file'], 'original_name' => $item['alt']];
            }
        }

        $slideHtml = '';
        foreach ($slides as $idx => $slide) {
            $name = basename((string)($slide['stored_name'] ?? ''));
            if ($name === '') continue;
            $srcPath = is_file(__DIR__.'/../assets/slides/'.$name) ? '/assets/slides/'.$name : '/assets/home/'.$name;
            $src = htmlspecialchars($this->url($srcPath));
            $alt = htmlspecialchars((string)($slide['original_name'] ?? 'Studio photograph'));
            $prio = $idx === 0 ? ' fetchpriority="high"' : ' loading="lazy"';
            $active = $idx === 0 ? ' is-active' : '';
            $slideHtml .= '<figure class="home-slide'.$active.'" data-slide><img src="'.$src.'" alt="'.$alt.'" width="820" height="1024" decoding="async"'.$prio.'></figure>';
        }
        if ($slideHtml === '') {
            $slideHtml = '<figure class="home-slide is-active" data-slide><div class="home-slide-empty"></div></figure>';
        }

        $sideA = $lookbook[1] ?? $lookbook[0] ?? null;
        $sideB = $lookbook[2] ?? $lookbook[0] ?? null;
        $mosaicSide = '';
        foreach ([$sideA, $sideB] as $side) {
            if (!$side) continue;
            $mosaicSide .= '<figure class="home-mosaic-side"><img src="'.htmlspecialchars($side['src']).'" alt="'.htmlspecialchars($side['alt']).'" width="820" height="1024" loading="lazy" decoding="async"></figure>';
        }

        $waBtn = '<a class="home-cta home-cta-accent" href="'.$bookNowHref.'">Book noww</a>';

        $body = '
        <div class="home-page-inner">
          <section class="home-hero">
            <div class="home-hero-copy">
              <p class="home-kicker">'.$app.' · Accra</p>
              <h1 class="home-headline">'.$headline.'</h1>
              <p class="home-support">'.$support.'</p>
              <p class="home-lead">'.$lead.'</p>
              <div class="home-cta-row">
                <a class="home-cta" href="'.$this->url('/packages').'">'.$cta.'</a>
                '.$waBtn.'
              </div>
            </div>
            <div class="home-hero-stage">
              <div class="home-mosaic">
                <div class="home-mosaic-main">'.$slideHtml.'</div>
                '.$mosaicSide.'
              </div>
            </div>
          </section>
        </div>
        <script>
        (() => {
          const slides = Array.from(document.querySelectorAll("[data-slide]"));
          if (slides.length < 2) return;
          if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
          let i = 0;
          setInterval(() => {
            slides[i].classList.remove("is-active");
            i = (i + 1) % slides.length;
            slides[i].classList.add("is-active");
          }, 5600);
        })();
        </script>';
        $this->render('Home', $body, ['home' => true]);
    }

    private function packageDetail(): void
    {
        $slug = trim((string)($_GET['slug'] ?? ''));
        $stmt = $this->db->prepare("SELECT * FROM packages WHERE slug=? AND active=1 LIMIT 1");
        $stmt->execute([$slug]);
        $p = $stmt->fetch();
        if (!$p) { $this->notFound(); return; }
        $cat = (string)($p['category'] ?? 'wedding');
        $meta = $this->packageCategoryMeta();
        $label = $meta[$cat]['label'] ?? ucfirst($cat);
        $lines = array_filter(array_map('trim', preg_split('/\r?\n/', (string)$p['deliverables'])));
        $list = '';
        foreach ($lines as $line) {
            $list .= '<li><i class="fa-solid fa-check" aria-hidden="true"></i><span>'.htmlspecialchars($line).'</span></li>';
        }
        if ($this->user && ($this->user['role'] ?? '') === 'client') {
            $bookHref = $this->url('/packages/'.$cat.'?book='.$p['id']).'#book';
        } else {
            $bookHref = $this->url('/register?package='.$p['id']);
        }
        $img = htmlspecialchars($this->packageCoverUrl($p));
        $tags = $this->packageTagList($p);
        $body = '<div class="max-w-5xl mx-auto px-4 py-10 sm:py-14">
          <a class="inline-flex items-center gap-2 text-sm font-semibold text-stone-500 hover:text-stone-800" href="'.$this->url('/packages/'.$cat).'"><i class="fa-solid fa-arrow-left"></i> Back to '.htmlspecialchars($meta[$cat]['short'] ?? $label).'</a>
          <div class="mt-6 grid lg:grid-cols-[1.05fr_.95fr] gap-6 lg:gap-10 items-start">
            <div class="offer-detail-media"><img src="'.$img.'" alt="'.htmlspecialchars($p['name']).'" width="1200" height="800" decoding="async"></div>
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-400">'.htmlspecialchars($label).'</p>
              <h1 class="mt-2 font-display text-4xl sm:text-5xl font-semibold tracking-wide text-stone-950">'.htmlspecialchars($p['name']).'</h1>
              <p class="mt-4 text-3xl font-black text-stone-950">'.$this->money((float)$p['price']).'</p>
              <ul class="offer-chips mt-4">'.$tags.'</ul>
              <p class="mt-4 text-stone-600 leading-7">'.nl2br(htmlspecialchars((string)$p['description'])).'</p>
              <div class="mt-6 rounded-2xl bg-stone-100/80 px-4 py-3 text-sm text-stone-600"><i class="fa-regular fa-clock mr-2"></i>Estimated turnaround <strong>'.(int)$p['turnaround_days'].' days</strong></div>
              '.($list ? '<h2 class="mt-8 text-sm font-bold uppercase tracking-[0.14em] text-stone-500">What\'s included</h2><ul class="offer-detail-list mt-4">'.$list.'</ul>' : '').'
              <div class="mt-8 flex flex-wrap gap-3">
                <a class="inline-flex items-center justify-center rounded-full bg-stone-950 px-6 py-3 text-sm font-bold text-white hover:bg-stone-800" href="'.$bookHref.'">Book this package</a>
                <a class="inline-flex items-center justify-center rounded-full border border-stone-300 bg-white px-6 py-3 text-sm font-bold text-stone-800" href="'.$this->url('/packages/'.$cat).'">View category</a>
              </div>
            </div>
          </div>
        </div>';
        $this->render($p['name'], $body);
    }

    private function packagesIndex(): void
    {
        $meta = $this->packageCategoryMeta();
        $cards = '';
        foreach ($meta as $slug => $info) {
            $count = (int)$this->db->query("SELECT COUNT(*) FROM packages WHERE active=1 AND category=".$this->db->quote($slug))->fetchColumn();
            $cards .= '<a href="'.$this->url('/packages/'.$slug).'" class="group block rounded-[1.75rem] border border-stone-200 bg-white p-7 sm:p-8 hover:border-stone-400 hover:shadow-lg hover:shadow-stone-200/60 transition">
              <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-stone-950 text-stone-100">'.$this->categoryIcon($slug,'h-6 w-6').'</span>
              <h2 class="mt-6 font-display text-3xl font-semibold tracking-wide text-stone-950">'.htmlspecialchars($info['label']).'</h2>
              <p class="mt-3 text-sm leading-6 text-stone-500">'.htmlspecialchars($info['blurb']).'</p>
              <p class="mt-6 text-sm font-semibold text-stone-800 group-hover:text-stone-950">'.$count.' packages <span class="inline-block transition group-hover:translate-x-0.5">→</span></p>
            </a>';
        }
        $body = '<div class="max-w-6xl mx-auto px-4 py-14 sm:py-20">
          <div class="max-w-2xl">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Packages</p>
            <h1 class="mt-3 font-display text-4xl sm:text-5xl font-semibold tracking-wide text-stone-950">Choose your session.</h1>
            <p class="mt-4 text-stone-500 leading-7">Pick a category to view pricing, inclusions and book online.</p>
          </div>
          <div class="mt-12 grid md:grid-cols-3 gap-5">'.$cards.'</div>
        </div>';
        $this->render('Packages', $body);
    }

    private function packagesWedding(): void { $this->packagesCategory('wedding'); }
    private function packagesBaby(): void { $this->packagesCategory('baby'); }
    private function packagesStudio(): void { $this->packagesCategory('studio'); }

    private function packagesCategory(string $category): void
    {
        $meta = $this->packageCategoryMeta();
        if (!isset($meta[$category])) {
            $this->notFound();
            return;
        }

        $bookPanel = $this->resolveBookingPanel($category);
        $tabs = '';
        foreach ($meta as $slug => $info) {
            $active = $slug === $category;
            $cls = $active
                ? 'rounded-full bg-stone-950 px-4 py-2 text-sm font-semibold text-white'
                : 'rounded-full border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-600 hover:border-stone-400';
            $tabs .= '<a href="'.$this->url('/packages/'.$slug).'" class="'.$cls.'">'.htmlspecialchars($info['short'] ?? $info['label']).'</a>';
        }

        $body = '<div class="max-w-6xl mx-auto px-4 py-12 sm:py-16">
          '.$bookPanel.'
          <div class="flex flex-wrap gap-2 mb-8">'.$tabs.'</div>
          <div class="max-w-2xl mb-10">
            <div class="flex items-start gap-4">
              <span class="mt-1 grid h-12 w-12 place-items-center rounded-2xl bg-stone-950 text-stone-100">'.$this->categoryIcon($category,'h-6 w-6').'</span>
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">iBuk.online</p>
                <h1 class="mt-2 font-display text-4xl sm:text-5xl font-semibold tracking-wide text-stone-950">'.htmlspecialchars($meta[$category]['label']).'</h1>
                <p class="mt-3 text-stone-500 leading-7">'.htmlspecialchars($meta[$category]['blurb']).'</p>
              </div>
            </div>
          </div>
          '.$this->packageSections(true, $category, false).'
        </div>';
        $this->render($meta[$category]['label'], $body);
    }

    private function resolveBookingPanel(?string $expectedCategory = null): string
    {
        $bookId = (int)($_GET['book'] ?? 0);
        if ($bookId <= 0) return '';
        if (!$this->user) {
            $this->redirect('/register?package='.$bookId);
        }
        if (($this->user['role'] ?? '') !== 'client') {
            $this->flash('error', 'Switch to a client account to book a package.');
            $this->redirect($expectedCategory ? '/packages/'.$expectedCategory : '/packages');
        }
        $stmt = $this->db->prepare("SELECT * FROM packages WHERE id=? AND active=1");
        $stmt->execute([$bookId]);
        $package = $stmt->fetch();
        if (!$package) {
            $this->flash('error', 'That package is not available.');
            $this->redirect($expectedCategory ? '/packages/'.$expectedCategory : '/packages');
        }
        $cat = (string)($package['category'] ?? 'wedding');
        if ($expectedCategory && $cat !== $expectedCategory) {
            $this->redirect('/packages/'.$cat.'?book='.$bookId.'#book');
        }
        return $this->bookingForm($package);
    }

    private function bookingForm(array $package, array $options = []): string
    {
        $icon = $this->categoryIcon((string)($package['category'] ?? 'wedding'), 'h-6 w-6');
        $contactFields = '';
        if ($this->profileNeedsName()) {
            $contactFields = $this->input('contact_name','Your full name','text','','e.g. Ama Mensah').$this->input('contact_email','Email (optional)','email','','name@email.com');
        }
        $category = (string)($package['category'] ?? 'wedding');
        $isPortalFlow = !empty($options['portal']);
        $fixedEventType = trim((string)($options['fixed_event_type'] ?? ''));
        $cancelHref = (string)($options['cancel_href'] ?? ('/packages/'.$category));
        $eventField = $this->eventTypeSelect($category);
        if ($fixedEventType !== '') {
            $eventField = '<input type="hidden" name="event_type" value="'.htmlspecialchars($fixedEventType).'"><div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Booking type</p><p class="mt-1 text-sm font-black text-emerald-950">'.htmlspecialchars($fixedEventType).'</p></div>';
        } elseif ($isPortalFlow && $category === 'wedding') {
            $eventField = '<div class="md:col-span-2"><label class="text-sm font-bold">What are you booking?</label><div class="mt-2 grid gap-3 sm:grid-cols-3">
              <label class="rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm font-semibold text-stone-800"><input type="radio" name="event_type" value="Wedding" class="mr-2" checked>Wedding</label>
              <label class="rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm font-semibold text-stone-800"><input type="radio" name="event_type" value="Engagement" class="mr-2">Engagement</label>
              <label class="rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm font-semibold text-stone-800"><input type="radio" name="event_type" value="Wedding &amp; Engagement" class="mr-2">Wedding &amp; engagement</label>
            </div></div>';
        }
        $addOns = $this->bookingAddonCatalog($category);
        $addOnHtml = '';
        if ($addOns !== []) {
            $addOnRows = '';
            foreach ($addOns as $key => $item) {
                $addOnRows .= '<label class="flex items-start gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3"><input type="checkbox" name="addons[]" value="'.htmlspecialchars($key).'" class="mt-1 h-4 w-4 rounded border-stone-300 text-stone-900"><span class="min-w-0"><span class="block text-sm font-bold text-stone-900">'.htmlspecialchars($item['label']).'</span><span class="mt-0.5 block text-xs text-stone-500">'.htmlspecialchars($item['hint']).'</span></span><span class="ml-auto whitespace-nowrap text-sm font-black text-stone-900">'.$this->money((float)$item['price']).'</span></label>';
            }
            $addOnHtml = '<div class="md:col-span-2"><label class="text-sm font-bold">Add-ons (optional)</label><div class="mt-2 grid gap-3">'.$addOnRows.'</div></div>';
        }
        $combinedDates = '';
        if ($category === 'wedding') {
            $combinedDates = '<div id="combined-dates-panel" class="md:col-span-2 hidden rounded-2xl border border-stone-200 bg-stone-50 p-4"><p class="text-sm font-bold text-stone-900">Wedding &amp; engagement dates</p><p class="mt-1 text-xs leading-5 text-stone-500">Choose both dates when this booking includes the engagement shoot and the wedding day.</p><div class="mt-3 grid md:grid-cols-2 gap-4">'.$this->input('engagement_date','Engagement date','date','','Select engagement date').$this->input('wedding_date','Wedding date','date','','Select wedding date').'</div></div>';
        }
        return '<div id="book" class="mb-10 scroll-mt-28 rounded-[2rem] border border-stone-200 bg-white p-5 sm:p-8 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-4">
              <span class="grid h-12 w-12 place-items-center rounded-2xl bg-stone-950 text-amber-400">'.$icon.'</span>
              <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-400">'.($isPortalFlow ? 'Book from your portal' : 'Confirm booking').'</p>
                <h2 class="mt-1 text-2xl font-black text-stone-950">'.htmlspecialchars($package['name']).'</h2>
                <p class="mt-1 text-sm text-stone-500">'.$this->money((float)$package['price']).'</p>
              </div>
            </div>
            <a href="'.$this->url($cancelHref).'" class="text-sm font-semibold text-stone-500 hover:text-stone-800">Cancel</a>
          </div>
          <form method="post" action="'.$this->url('/book').'" class="mt-6 grid md:grid-cols-2 gap-4">
            '.$this->csrfField().'
            <input type="hidden" name="package_id" value="'.(int)$package['id'].'">
            '.$contactFields.'
            '.$eventField.'
            <div id="single-date-field">'.$this->input('event_date','Event date','date','','Select date').'</div>
            '.$combinedDates.'
            '.$this->input('event_location','Event location','text','','Venue or location').'
            '.$this->input('prior_payment_amount','Already paid / part-paid amount (optional)','number','','0.00').'
            '.$this->input('coupon_code','Coupon code (optional)','text','','Enter coupon if any').'
            '.$this->textarea('prior_payment_note','Prior payment note (optional)','Tell us when you paid, the reference used, or anything we should verify first.','',3,'md:col-span-2').'
            '.$addOnHtml.'
            '.$this->textarea('notes','Notes','Venue, preferred time, extra requests…').'
            <div class="md:col-span-2 flex flex-wrap items-center gap-3 pt-1">
              <button class="rounded-full bg-stone-950 px-6 py-3 text-sm font-bold text-white hover:bg-stone-800 transition">Confirm booking</button>
              <p class="text-xs text-stone-500">You will get payment instructions after confirmation.</p>
            </div>
          </form>
          <script>
          (() => {
            const form = document.currentScript?.previousElementSibling;
            if (!form) return;
            const single = form.querySelector("#single-date-field");
            const combo = form.querySelector("#combined-dates-panel");
            const singleInput = form.querySelector("input[name=\"event_date\"]");
            const engagementInput = form.querySelector("input[name=\"engagement_date\"]");
            const weddingInput = form.querySelector("input[name=\"wedding_date\"]");
            if (!single || !combo || !singleInput || !engagementInput || !weddingInput) return;
            const readType = () => {
              const select = form.querySelector("select[name=\"event_type\"]");
              if (select) return select.value;
              const checked = form.querySelector("input[name=\"event_type\"]:checked");
              return checked ? checked.value : "";
            };
            const sync = () => {
              const combined = readType() === "Wedding & Engagement";
              combo.classList.toggle("hidden", !combined);
              single.classList.toggle("hidden", combined);
              singleInput.required = !combined;
              engagementInput.required = combined;
              weddingInput.required = combined;
            };
            form.querySelectorAll("[name=\"event_type\"]").forEach((el) => el.addEventListener("change", sync));
            sync();
          })();
          </script>
        </div>';
    }

    private function eventTypeSelect(string $category = 'wedding'): string
    {
        $groups = [
            'wedding' => ['Wedding', 'Engagement', 'Wedding & Engagement'],
            'baby' => ['Baby Dedication', 'Baby Christening', 'Birthday'],
            'studio' => ['Studio Portrait', 'Birthday', 'Engagement'],
        ];
        $all = ['Wedding', 'Engagement', 'Wedding & Engagement', 'Baby Dedication', 'Baby Christening', 'Birthday', 'Studio Portrait', 'Other'];
        $preferred = $groups[$category] ?? [];
        $ordered = array_values(array_unique(array_merge($preferred, $all)));
        $opts = '<option value="" disabled selected>Select event type</option>';
        foreach ($ordered as $label) {
            $opts .= '<option value="'.htmlspecialchars($label).'">'.htmlspecialchars($label).'</option>';
        }
        return '<div><label class="text-sm font-bold">What are you booking?</label><div class="relative mt-1"><select required name="event_type" class="w-full appearance-none rounded-2xl border border-stone-200 bg-white px-4 py-3 pr-11 outline-none focus:border-stone-400">'.$opts.'</select><span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-stone-400"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span></div></div>';
    }

    private function packageCoverUrl(array $p): string
    {
        $file = trim((string)($p['cover_image'] ?? ''));
        $cat = (string)($p['category'] ?? 'wedding');
        $fallback = [
            'wedding' => 'cover-wedding.jpg',
            'baby' => 'cover-baby.jpg',
            'studio' => 'cover-studio.jpg',
        ][$cat] ?? 'cover-wedding.jpg';
        if ($file === '' || !is_file(__DIR__.'/../assets/packages/'.$file)) {
            $file = $fallback;
        }
        return $this->url('/assets/packages/'.$file);
    }

    private function packageCard(array $p, bool $showBook = false): string
    {
        $cat = (string)($p['category'] ?? 'wedding');
        $detail = $this->url('/package/'.rawurlencode((string)$p['slug']));
        $desc = trim((string)($p['description'] ?? ''));
        if (mb_strlen($desc) > 88) $desc = rtrim(mb_substr($desc, 0, 85)).'…';
        $chips = $this->packageTagList($p);
        $img = htmlspecialchars($this->packageCoverUrl($p));
        $book = '';
        if ($showBook) {
            if ($this->user && ($this->user['role'] ?? '') === 'client') {
                $bookHref = $this->url('/packages/'.$cat.'?book='.$p['id']).'#book';
            } elseif (!$this->user) {
                $bookHref = $this->url('/register?package='.$p['id']);
            } else {
                $bookHref = $detail;
            }
            $book = '<a class="offer-book" href="'.$bookHref.'">Book</a>';
        }
        return '<article class="offer-card">
          <a class="offer-media" href="'.$detail.'" aria-label="'.htmlspecialchars($p['name']).'">
            <img src="'.$img.'" alt="" loading="lazy" decoding="async" width="600" height="400">
          </a>
          <div class="offer-body">
            <div class="offer-top">
              <h3><a href="'.$detail.'">'.htmlspecialchars($p['name']).'</a></h3>
              <p class="offer-price">'.$this->money((float)$p['price']).'</p>
            </div>
            <p class="offer-desc">'.htmlspecialchars($desc).'</p>
            <ul class="offer-chips">'.$chips.'</ul>
            <div class="offer-actions">
              <a class="offer-more" href="'.$detail.'">Read more <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
              '.$book.'
            </div>
          </div>
        </article>';
    }

    private function packageTagList(array $p): string
    {
        $tags = [];
        $category = (string)($p['category'] ?? '');
        $coverage = $this->packageCoverageLabel($p);
        if ($coverage !== '') {
            $tags[] = $coverage;
        } elseif ($category !== 'wedding') {
            $days = (int)($p['turnaround_days'] ?? 0);
            if ($days > 0) $tags[] = $days.' days';
        }

        $blob = strtolower(trim((string)($p['deliverables'] ?? '').' '.(string)($p['description'] ?? '')));
        if (str_contains($blob, 'no video')) {
            $tags[] = 'Photos only';
        } elseif (str_contains($blob, 'video')) {
            $tags[] = 'Video included';
        } else {
            $tags[] = 'Photos only';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($p['deliverables'] ?? '')))));
        foreach (array_slice($lines, 0, 2) as $line) {
            $tags[] = $line;
        }

        $html = '';
        foreach (array_slice(array_values(array_unique($tags)), 0, 5) as $tag) {
            $html .= '<li>'.htmlspecialchars($tag).'</li>';
        }
        return $html;
    }

    private function packageCoverageLabel(array $p): string
    {
        $category = (string)($p['category'] ?? '');
        $blob = strtolower(trim((string)($p['deliverables'] ?? '').' '.(string)($p['description'] ?? '')));
        if ($category === 'wedding') {
            if (str_contains($blob, 'engagement only')) return 'Engagement only';
            if (str_contains($blob, 'wedding & engagement') || (str_contains($blob, 'wedding and engagement'))) {
                return 'Same day or 2 separate days';
            }
            if (str_contains($blob, 'wedding')) return 'Wedding only';
            return 'Wedding booking';
        }
        $days = (int)($p['turnaround_days'] ?? 0);
        return $days > 0 ? $days.' days' : '';
    }

    private function bookingAddonCatalog(string $category): array
    {
        return match ($category) {
            'wedding' => [
                'extra-retouch' => ['label' => 'Extra retouched pictures', 'price' => 350, 'hint' => 'Add more polished hero images to the delivery.'],
                'traditional' => ['label' => 'Traditional ceremony coverage', 'price' => 900, 'hint' => 'Extra coverage for the traditional event day.'],
                'prewedding' => ['label' => 'Pre-wedding shoot', 'price' => 1200, 'hint' => 'A styled pre-wedding portrait session.'],
                'drone' => ['label' => 'Drone coverage', 'price' => 1500, 'hint' => 'Aerial clips and scene coverage where venue rules allow it.'],
                'express' => ['label' => 'Express delivery', 'price' => 650, 'hint' => 'Faster gallery turnaround and priority edit queue.'],
            ],
            default => [],
        };
    }

    private function selectedBookingAddons(array $selected, string $category): array
    {
        $catalog = $this->bookingAddonCatalog($category);
        $items = [];
        $total = 0.0;
        foreach ($selected as $key) {
            $key = (string)$key;
            if (!isset($catalog[$key])) continue;
            $items[] = $catalog[$key]['label'].' ('.$this->money((float)$catalog[$key]['price']).')';
            $total += (float)$catalog[$key]['price'];
        }
        return [
            'summary' => implode(', ', $items),
            'total' => round($total, 2),
        ];
    }

    private function bookingEventSummary(array $booking): string
    {
        $eventType = (string)($booking['event_type'] ?? '');
        $eventDate = trim((string)($booking['event_date'] ?? ''));
        $weddingDate = trim((string)($booking['wedding_date'] ?? ''));
        $engagementDate = trim((string)($booking['engagement_date'] ?? ''));

        if ($eventType === 'Wedding & Engagement' && ($weddingDate !== '' || $engagementDate !== '')) {
            $bits = [];
            if ($engagementDate !== '') $bits[] = 'Engagement: '.$engagementDate;
            if ($weddingDate !== '') $bits[] = 'Wedding: '.$weddingDate;
            return implode(' · ', $bits);
        }
        return $eventDate !== '' ? $eventDate : 'Date to be confirmed';
    }

    private function bookingSummaryTags(array $booking): string
    {
        $tags = [];
        if ((float)($booking['discount'] ?? 0) > 0 && trim((string)($booking['coupon_code'] ?? '')) !== '') {
            $tags[] = 'Coupon '.trim((string)$booking['coupon_code']).' applied';
        }
        if ((float)($booking['addon_total'] ?? 0) > 0) {
            $tags[] = 'Add-ons included';
        }
        if ((float)($booking['prior_payment_amount'] ?? 0) > 0) {
            $tags[] = 'Prior payment noted';
        }
        if (trim((string)($booking['event_type'] ?? '')) === 'Wedding & Engagement') {
            $tags[] = 'Two dates scheduled';
        }
        $html = '';
        foreach ($tags as $tag) {
            $html .= '<span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700">'.htmlspecialchars($tag).'</span>';
        }
        return $html;
    }

    private function clientBookingProgress(int $step): string
    {
        $steps = [
            1 => ['label' => 'Type'],
            2 => ['label' => 'Package'],
            3 => ['label' => 'Details'],
        ];
        $html = '<div class="rounded-3xl border border-stone-200 bg-white p-4"><div class="flex items-center justify-between gap-3">';
        foreach ($steps as $num => $item) {
            $active = $num <= $step;
            $html .= '<div class="flex items-center gap-3 '.($num < 3 ? 'flex-1' : '').'">'
                .'<span class="grid h-9 w-9 place-items-center rounded-full '.($active ? 'bg-stone-950 text-white' : 'bg-stone-100 text-stone-400').' text-xs font-black">'.$num.'</span>'
                .'<div class="min-w-0 '.($num < 3 ? 'flex-1' : '').'"><p class="text-[11px] font-black uppercase tracking-[0.14em] '.($active ? 'text-stone-900' : 'text-stone-400').'">'.htmlspecialchars($item['label']).'</p>'
                .($num < 3 ? '<div class="mt-1 h-1.5 rounded-full '.($step > $num ? 'bg-stone-950' : 'bg-stone-100').'"></div>' : '')
                .'</div></div>';
        }
        return $html.'</div></div>';
    }

    private function categoryIcon(string $category, string $class = 'h-5 w-5'): string
    {
        if ($category === 'baby') {
            return '<svg class="'.$class.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3c2.2 2.4 3.5 4.6 3.5 7a3.5 3.5 0 1 1-7 0c0-2.4 1.3-4.6 3.5-7Z"/><path d="M7 20c1.2-2.2 2.9-3.3 5-3.3S15.8 17.8 17 20"/><path d="M8.5 11.5c-.8.2-1.7.8-2.2 1.6M15.5 11.5c.8.2 1.7.8 2.2 1.6"/></svg>';
        }
        if ($category === 'studio') {
            return '<svg class="'.$class.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4.5 8.5h2.2l1.2-2h8.2l1.2 2H19.5A1.5 1.5 0 0 1 21 10v7.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5V10a1.5 1.5 0 0 1 1.5-1.5Z"/><circle cx="12" cy="13.5" r="3.2"/></svg>';
        }
        return '<svg class="'.$class.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M8.2 9.2c-1.6-1.7-1.5-4.3.3-5.8 1.7-1.4 4.1-1 5.3.8L14 4.5l.2-.3c1.2-1.8 3.6-2.2 5.3-.8 1.8 1.5 1.9 4.1.3 5.8L14 15.3 8.2 9.2Z"/><path d="M7 20h10"/><path d="M9.5 17.5h5"/></svg>';
    }

    private function checkIcon(): string
    {
        return '<svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>';
    }

    private function registerForm(): void
    {
        if ($this->user) $this->redirect($this->user['role']==='admin'?'/dashboard':'/client/dashboard');
        $packageId = (int)($_GET['package'] ?? ($_SESSION['otp_package_id'] ?? 0));
        $body = $this->authLayout('Continue with your phone','Enter the phone number you use for MoMo. We will send a one-time code — no password needed.',
            '<form method="post" action="'.$this->url('/register').'" class="space-y-4">'.$this->csrfField().'<input type="hidden" name="package_id" value="'.$packageId.'"><input type="hidden" name="intent" value="register">'.$this->input('phone','Phone number','tel','','Active phone number').'<button class="w-full rounded-2xl bg-slate-950 px-4 py-3 font-bold text-white">Send OTP</button><p class="text-center text-sm text-slate-500">Already verified? <a class="font-bold text-slate-900" href="'.$this->url('/login').'">Log in with OTP</a></p></form>');
        $this->render('Register', $body);
    }

    private function loginForm(): void
    {
        if ($this->user) $this->redirect($this->user['role']==='admin'?'/dashboard':'/client/dashboard');
        $body = $this->authLayout('Client login','Enter your active phone number. We will send a one-time code to continue.',
            '<form method="post" action="'.$this->url('/register').'" class="space-y-4">'.$this->csrfField().'<input type="hidden" name="intent" value="login">'.$this->input('phone','Phone number','tel','','Active phone number').'<button class="w-full rounded-2xl bg-slate-950 px-4 py-3 font-bold text-white">Send OTP</button></form>
            <p class="mt-4 text-center text-sm text-slate-500">New here? <a class="font-bold text-slate-900" href="'.$this->url('/register').'">Create account with OTP</a></p>');
        $this->render('Login', $body);
    }

    private function adminGate(): void
    {
        if ($this->user && ($this->user['role'] ?? '') === 'admin') {
            $this->redirect('/dashboard');
        }
        if ($this->user) {
            http_response_code(403);
            exit('Forbidden');
        }
        $hint = trim((string)($this->config['admin_login'] ?? 'admin'));
        $placeholder = $hint !== '' ? $hint : 'admin';
        $body = $this->authLayout('Studio admin','Sign in with your admin username or phone and password.',
            '<form method="post" action="'.$this->url('/admin/login').'" class="space-y-4">'.$this->csrfField().
            $this->input('login','Admin username or phone','text','',$placeholder).
            $this->input('password','Password','password','','Enter admin password').
            '<button class="w-full rounded-2xl bg-slate-950 px-4 py-3 font-bold text-white">Sign in to admin</button></form>');
        $this->render('Admin login', $body);
    }

    private function adminLogin(): void
    {
        if ($this->user && ($this->user['role'] ?? '') === 'admin') {
            $this->redirect('/dashboard');
        }
        $login = trim((string)($_POST['login'] ?? ($_POST['phone'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $configLogin = trim((string)($this->config['admin_login'] ?? 'admin'));
        $configPhone = $this->normalizePhone((string)($this->config['admin_phone'] ?? '0200000000'));
        $configPassword = (string)($this->config['admin_password'] ?? 'ChangeMe123!');
        $submittedPhone = $this->normalizePhone($login);
        $matchConfig = (($configLogin !== '' && strcasecmp($login, $configLogin) === 0) || ($submittedPhone !== '' && $submittedPhone === $configPhone))
            && hash_equals($configPassword, $password);

        $this->syncAdminAccount();
        $stmt = $this->db->prepare("SELECT * FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $user = $stmt->fetch();
        $matchDb = false;
        if ($user) {
            $matchIdentity = ($submittedPhone !== '' && $submittedPhone === (string)$user['phone'])
                || strcasecmp($login, (string)($user['first_name'] ?? '')) === 0
                || strcasecmp($login, 'admin') === 0;
            $matchDb = $matchIdentity && password_verify($password, (string)$user['password_hash']);
        }
        if (!$matchConfig && !$matchDb) {
            $this->flash('error','Admin login failed. Check username/phone and password.');
            $this->redirect('/admin');
        }
        if (!$user) {
            $this->flash('error','Admin account is not ready yet.');
            $this->redirect('/admin');
        }
        $_SESSION['user_id'] = (int)$user['id'];
        $this->user = $user;
        $this->redirect('/dashboard');
    }

    private function redirectLegacyAdmin(): void
    {
        $from = $this->path ?: '/admin';
        $to = preg_replace('#^/admin#', '/dashboard', $from) ?: '/dashboard';
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        $this->redirect($to.($qs !== '' ? '?'.$qs : ''));
    }

    private function requestOtp(): void
    {
        if ($this->user) $this->redirect($this->user['role']==='admin'?'/dashboard':'/client/dashboard');
        $phone = $this->normalizePhone((string)($_POST['phone'] ?? ''));
        $packageId = (int)($_POST['package_id'] ?? 0);
        $intent = ($_POST['intent'] ?? 'register') === 'login' ? 'login' : 'register';
        if (strlen($phone) < 9) {
            $this->flash('error','Enter a valid active phone number.');
            $this->redirect($intent === 'login' ? '/login' : '/register'.($packageId?'?package='.$packageId:''));
        }
        $otp = (string)random_int(100000, 999999);
        $_SESSION['otp'] = [
            'phone' => $phone,
            'hash' => password_hash($otp, PASSWORD_DEFAULT),
            'expires' => time() + 600,
            'package_id' => $packageId,
            'intent' => $intent,
            'tries' => 0,
        ];
        $otpMsg = str_replace(
            ['{app}', '{otp}'],
            [$this->cfg('app_name', 'iBuk.online'), $otp],
            $this->cfg('otp_sms_template', 'Your {app} code is {otp}. It expires in 10 minutes.')
        );
        $smsResult = $this->sendSms($phone, $otpMsg);
        if ($smsResult['provider'] === 'log') {
            $this->flash('success','OTP sent. For local testing, use code '.$otp.'.');
        } elseif ($smsResult['status'] !== 'sent') {
            $this->flash('error', 'SMS could not be sent right now. Check your SMS provider, API key, sender ID, and balance in Settings.');
        } else {
            $this->flash('success','OTP sent to your phone. Enter it to continue.');
        }
        $this->redirect('/auth/otp');
    }

    private function otpForm(): void
    {
        if ($this->user) $this->redirect($this->user['role']==='admin'?'/dashboard':'/client/dashboard');
        $otp = $_SESSION['otp'] ?? null;
        if (!$otp || empty($otp['phone'])) {
            $this->flash('error','Request a new OTP first.');
            $this->redirect('/register');
        }
        $masked = $this->maskPhone((string)$otp['phone']);
        $body = $this->authLayout('Enter your code','We sent a 6-digit OTP to '.$masked.'.',
            '<form method="post" action="'.$this->url('/auth/otp').'" class="space-y-4" id="otp-form">'.$this->csrfField().'
              <input type="hidden" name="otp" id="otp-hidden" value="">
              <div><label class="text-sm font-bold">OTP code</label>
                <div class="mt-2 grid grid-cols-6 gap-2" id="otp-boxes">
                  <input inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" class="otp-box h-14 rounded-2xl border border-slate-200 bg-white text-center text-xl font-black outline-none focus:border-slate-400" aria-label="OTP digit 1">
                  <input inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box h-14 rounded-2xl border border-slate-200 bg-white text-center text-xl font-black outline-none focus:border-slate-400" aria-label="OTP digit 2">
                  <input inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box h-14 rounded-2xl border border-slate-200 bg-white text-center text-xl font-black outline-none focus:border-slate-400" aria-label="OTP digit 3">
                  <input inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box h-14 rounded-2xl border border-slate-200 bg-white text-center text-xl font-black outline-none focus:border-slate-400" aria-label="OTP digit 4">
                  <input inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box h-14 rounded-2xl border border-slate-200 bg-white text-center text-xl font-black outline-none focus:border-slate-400" aria-label="OTP digit 5">
                  <input inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box h-14 rounded-2xl border border-slate-200 bg-white text-center text-xl font-black outline-none focus:border-slate-400" aria-label="OTP digit 6">
                </div>
              </div>
              <button class="w-full rounded-2xl bg-slate-950 px-4 py-3 font-bold text-white">Verify & continue</button>
            </form>
            <form method="post" action="'.$this->url('/auth/otp-resend').'" class="mt-4 text-center">'.$this->csrfField().'<input type="hidden" name="phone" value="'.htmlspecialchars((string)$otp['phone']).'"><input type="hidden" name="package_id" value="'.(int)($otp['package_id']??0).'"><input type="hidden" name="intent" value="'.htmlspecialchars((string)($otp['intent']??'register')).'"><button class="text-sm font-bold text-slate-700">Resend OTP</button></form>');
        $body .= '<script>
        (() => {
          const form = document.getElementById("otp-form");
          const hidden = document.getElementById("otp-hidden");
          const boxes = Array.from(document.querySelectorAll(".otp-box"));
          if (!form || !hidden || boxes.length !== 6) return;
          const sync = () => {
            hidden.value = boxes.map((box) => box.value).join("");
            if (hidden.value.length === 6) form.requestSubmit();
          };
          boxes.forEach((box, idx) => {
            box.addEventListener("input", () => {
              box.value = (box.value || "").replace(/\\D/g, "").slice(0, 1);
              if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
              sync();
            });
            box.addEventListener("keydown", (e) => {
              if (e.key === "Backspace" && !box.value && idx > 0) {
                boxes[idx - 1].focus();
              }
            });
            box.addEventListener("paste", (e) => {
              const pasted = (e.clipboardData?.getData("text") || "").replace(/\\D/g, "").slice(0, 6);
              if (!pasted) return;
              e.preventDefault();
              boxes.forEach((item, i) => item.value = pasted[i] || "");
              const next = Math.min(pasted.length, boxes.length - 1);
              boxes[next].focus();
              sync();
            });
          });
          boxes[0].focus();
        })();
        </script>';
        $this->render('Verify OTP', $body);
    }

    private function verifyOtp(): void
    {
        $pending = $_SESSION['otp'] ?? null;
        $code = preg_replace('/\D+/', '', (string)($_POST['otp'] ?? ''));
        if (!$pending || empty($pending['phone']) || empty($pending['hash'])) {
            $this->flash('error','OTP session expired. Request a new code.');
            $this->redirect('/register');
        }
        if (time() > (int)$pending['expires']) {
            unset($_SESSION['otp']);
            $this->flash('error','That OTP has expired. Request a new one.');
            $this->redirect('/register'.(!empty($pending['package_id'])?'?package='.(int)$pending['package_id']:''));
        }
        $pending['tries'] = (int)($pending['tries'] ?? 0) + 1;
        $_SESSION['otp'] = $pending;
        if ($pending['tries'] > 6) {
            unset($_SESSION['otp']);
            $this->flash('error','Too many attempts. Request a new OTP.');
            $this->redirect('/register');
        }
        if (!password_verify($code, (string)$pending['hash'])) {
            $this->flash('error','Incorrect OTP. Try again.');
            $this->redirect('/auth/otp');
        }

        $phone = (string)$pending['phone'];
        $packageId = (int)($pending['package_id'] ?? 0);
        unset($_SESSION['otp']);

        $stmt = $this->db->prepare("SELECT * FROM users WHERE phone=? LIMIT 1");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if ($user && ($user['role'] ?? '') === 'admin') {
            $this->flash('error','Admin accounts use password login.');
            $this->redirect('/login');
        }
        if (!$user) {
            $stmt = $this->db->prepare("INSERT INTO users (role,first_name,last_name,phone,email,password_hash,created_at) VALUES ('client',?,?,?,?,?,?)");
            $stmt->execute(['Client','', $phone, null, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $this->now()]);
            $userId = (int)$this->db->lastInsertId();
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $this->flash('success','You are in. Add your name when you confirm the booking.');
        } else {
            $this->flash('success','Welcome back.');
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $this->user = $user;
        if ($packageId) {
            $stmt = $this->db->prepare("SELECT category FROM packages WHERE id=? AND active=1");
            $stmt->execute([$packageId]);
            $cat = (string)($stmt->fetchColumn() ?: 'wedding');
            $this->redirect('/packages/'.$cat.'?book='.$packageId.'#book');
        }
        $this->redirect('/client/dashboard');
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', trim($phone)) ?? '';
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if (str_starts_with($phone, '233') && strlen($phone) >= 12) {
            $phone = '0'.substr($phone, 3);
        }
        if (str_starts_with($phone, '+233') && strlen($phone) >= 13) {
            $phone = '0'.substr($phone, 4);
        }
        return $phone;
    }

    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 6) return $phone;
        return substr($phone, 0, 3).str_repeat('•', max(2, $len - 5)).substr($phone, -2);
    }

    private function profileNeedsName(?array $user = null): bool
    {
        $user = $user ?? $this->user;
        if (!$user || ($user['role'] ?? '') !== 'client') return false;
        $first = trim((string)($user['first_name'] ?? ''));
        $last = trim((string)($user['last_name'] ?? ''));
        return $first === '' || strcasecmp($first, 'Client') === 0 || $last === '';
    }

    private function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->redirect('/');
    }

    private function createBooking(): void
    {
        $this->requireRole('client');
        $this->verifyCsrf();
        $packageId = (int)($_POST['package_id'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM packages WHERE id=? AND active=1");
        $stmt->execute([$packageId]);
        $package = $stmt->fetch();
        if (!$package) throw new RuntimeException('Package not found.');

        $allowedTypes = ['Wedding','Engagement','Wedding & Engagement','Baby Dedication','Baby Christening','Birthday','Studio Portrait','Other'];
        $eventType = trim($_POST['event_type'] ?? '');
        if (!in_array($eventType, $allowedTypes, true)) {
            $this->flash('error','Please choose a valid event type.');
            $cat = (string)($package['category'] ?? 'wedding');
            $this->redirect('/packages/'.$cat.'?book='.$packageId.'#book');
        }
        $eventDate = trim((string)($_POST['event_date'] ?? ''));
        $engagementDate = trim((string)($_POST['engagement_date'] ?? ''));
        $weddingDate = trim((string)($_POST['wedding_date'] ?? ''));
        if ($eventType === 'Wedding & Engagement') {
            if ($engagementDate === '' || $weddingDate === '') {
                $this->flash('error','Choose both the engagement date and wedding date for this package.');
                $cat = (string)($package['category'] ?? 'wedding');
                $this->redirect('/packages/'.$cat.'?book='.$packageId.'#book');
            }
            $eventDate = $weddingDate;
        } elseif ($eventDate === '') {
            $this->flash('error','Please choose the event date.');
            $cat = (string)($package['category'] ?? 'wedding');
            $this->redirect('/packages/'.$cat.'?book='.$packageId.'#book');
        }

        if ($this->profileNeedsName()) {
            $full = trim(preg_replace('/\s+/', ' ', (string)($_POST['contact_name'] ?? '')) ?? '');
            if ($full === '' || !str_contains($full, ' ')) {
                $this->flash('error','Please enter your full name (first and last).');
                $cat = (string)($package['category'] ?? 'wedding');
                $this->redirect('/packages/'.$cat.'?book='.$packageId.'#book');
            }
            $parts = explode(' ', $full, 2);
            $email = trim((string)($_POST['contact_email'] ?? ''));
            $this->db->prepare("UPDATE users SET first_name=?, last_name=?, email=COALESCE(NULLIF(?, ''), email) WHERE id=?")
                ->execute([$parts[0], $parts[1], $email, $this->user['id']]);
            $this->user = $this->currentUser();
        }

        $subtotal = (float)$package['price'];
        $selectedAddOns = $this->selectedBookingAddons($_POST['addons'] ?? [], (string)($package['category'] ?? 'wedding'));
        $addonTotal = $selectedAddOns['total'];
        $subtotal += $addonTotal;
        $discount = 0.0;
        $couponCode = strtoupper(trim($_POST['coupon_code'] ?? ''));
        $coupon = null;
        if ($couponCode) {
            $coupon = $this->validCoupon($couponCode, $packageId);
            if (!$coupon) {
                $this->flash('error','Coupon is invalid, expired, unavailable, or not for this package.');
                $cat = (string)($package['category'] ?? 'wedding');
                $this->redirect('/packages/'.$cat.'?book='.$packageId.'#book');
            }
            $discount = $coupon['type']==='fixed' ? min($subtotal,(float)$coupon['value']) : $subtotal*((float)$coupon['value']/100);
            $this->db->prepare("UPDATE coupons SET uses=uses+1 WHERE id=?")->execute([$coupon['id']]);
        }
        $total = max(0,$subtotal-$discount);
        $isWedding = ($package['category'] ?? '') === 'wedding';
        $bookingPct = $this->weddingBookingPercent();
        $deposit = $isWedding ? round($total * ($bookingPct / 100), 2) : 0.0;
        $priorPaymentAmount = max(0, round((float)($_POST['prior_payment_amount'] ?? 0), 2));
        $priorPaymentNote = trim((string)($_POST['prior_payment_note'] ?? ''));
        $bookingCode = 'BK-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $now = $this->now();

        $stmt = $this->db->prepare("INSERT INTO bookings (booking_code,user_id,package_id,event_type,event_date,event_location,notes,subtotal,discount,total,deposit_required,coupon_code,payment_status,status,created_at,updated_at,wedding_date,engagement_date,prior_payment_amount,prior_payment_note,addon_summary,addon_total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $bookingCode,$this->user['id'],$packageId,
            $eventType,$eventDate,trim($_POST['event_location'] ?? ''),trim($_POST['notes'] ?? ''),
            $subtotal,$discount,$total,$deposit,$couponCode?:null,'unpaid','awaiting_payment',$now,$now,
            $weddingDate ?: null,$engagementDate ?: null,$priorPaymentAmount,$priorPaymentNote ?: null,$selectedAddOns['summary'] ?: null,$addonTotal
        ]);
        $bookingId = (int)$this->db->lastInsertId();
        $this->seedTimeline($bookingId, (int)$package['turnaround_days'], $eventDate);
        $bookingSms = "Hi {$this->user['first_name']}, your booking {$bookingCode} for {$package['name']} has been created.";
        if ($coupon) {
            $bookingSms .= " Coupon {$couponCode} was applied.";
        }
        $bookingSms .= " Log in to your portal for payment instructions.";
        $this->sendSms((string)$this->user['phone'], $bookingSms);
        $success = 'Booking created. Use the payment reference shown below.';
        if ($coupon) {
            $success .= ' Coupon '.$couponCode.' was applied.';
        }
        $this->flash('success',$success);
        $this->redirect('/client/booking?id='.$bookingId);
    }

    private function seedTimeline(int $bookingId, int $turnaroundDays, string $eventDate): void
    {
        $base = $eventDate && strtotime($eventDate) ? strtotime($eventDate) : time();
        $steps = [
            ['1 · Book & pay','Waiting for your MoMo payment to be verified.','pending',date('Y-m-d')],
            ['2 · Confirm terms','Review and accept the service agreement, then get ready for the shoot.','pending',date('Y-m-d', strtotime('+1 day'))],
            ['3 · Shoot day','Photography session or event coverage.','pending',$eventDate ?: null],
            ['4 · Edit & polish','Your photos are selected, edited and retouched.','pending',date('Y-m-d', $base + max(1,$turnaroundDays-3)*86400)],
            ['5 · Images ready','Soft copies unlock in your portal for download.','pending',date('Y-m-d', $base + $turnaroundDays*86400)],
        ];
        $stmt = $this->db->prepare("INSERT INTO timeline (booking_id,title,description,status,due_date,sort_order,created_at) VALUES (?,?,?,?,?,?,?)");
        foreach ($steps as $i=>$s) $stmt->execute([$bookingId,$s[0],$s[1],$s[2],$s[3],$i+1,$this->now()]);
    }

    private function clientDashboard(): void
    {
        $this->requireRole('client');
        $stmt = $this->db->prepare("SELECT b.*,p.name package_name FROM bookings b JOIN packages p ON p.id=b.package_id WHERE b.user_id=? ORDER BY b.id DESC");
        $stmt->execute([$this->user['id']]);
        $bookings = $stmt->fetchAll();
        $active = count($bookings);
        $paid = 0;
        foreach ($bookings as $b) $paid += $this->bookingPaid((int)$b['id']);
        $latest = '';
        foreach (array_slice($bookings,0,4) as $b) $latest .= $this->bookingRow($b, false);
        $note = trim($this->cfg('studio_note', ''));
        $noteBlock = $note !== '' ? '<div class="mt-5 rounded-3xl border border-stone-200 bg-white p-5 text-sm leading-6 text-stone-600">'.nl2br(htmlspecialchars($note)).'</div>' : '';
        if ($active <= 0) {
            $body = $this->clientShell('Overview',
                '<div class="rounded-[2rem] border border-stone-200 bg-white p-6"><p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-400">Start here</p><h2 class="mt-2 text-3xl font-black text-stone-950">Book your first shoot</h2><p class="mt-3 text-sm leading-6 text-stone-500">Once you place your first booking, your portal cards, payments, and booking history will appear here.</p><a href="'.$this->url('/client/new-booking').'" class="mt-5 inline-flex rounded-full bg-stone-950 px-5 py-3 text-sm font-bold text-white">Book now</a></div>'.$noteBlock
            );
        } else {
            $body = $this->clientShell('Overview',
                '<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">'.$this->stat('Bookings',(string)$active).$this->stat('Paid',$this->money($paid)).$this->stat('Files',(string)$this->clientFileCount()).$this->stat('Account','Active').'</div>'.
                $noteBlock.
                '<div class="mt-7"><h2 class="text-lg font-black">Recent bookings</h2><div class="mt-3 space-y-3">'.$latest.'</div></div>'
            );
        }
        $this->render('Client dashboard',$body,['portal'=>'client']);
    }

    private function clientNewBooking(): void
    {
        $this->requireRole('client');
        $kinds = [
            'wedding' => ['label' => 'Wedding / engagement', 'category' => 'wedding', 'event_type' => '', 'hint' => 'Choose a wedding package, then pick wedding, engagement, or both.'],
            'birthday' => ['label' => 'Birthday', 'category' => 'studio', 'event_type' => 'Birthday', 'hint' => 'Fast birthday booking inside your portal.'],
            'studio' => ['label' => 'Studio shoot', 'category' => 'studio', 'event_type' => 'Studio Portrait', 'hint' => 'Simple studio portrait booking.'],
            'baby' => ['label' => 'Baby christening', 'category' => 'baby', 'event_type' => 'Baby Christening', 'hint' => 'Baby christening packages only.'],
        ];
        $kind = (string)($_GET['kind'] ?? '');
        $bookId = (int)($_GET['book'] ?? 0);
        if ($kind !== '' && !isset($kinds[$kind])) {
            $kind = '';
        }
        $step = $bookId > 0 ? 3 : ($kind !== '' ? 2 : 1);

        $picker = '';
        foreach ($kinds as $slug => $item) {
            $active = $slug === $kind
                ? ' border-stone-950 bg-stone-950 text-white'
                : ' border-stone-200 bg-white text-stone-900';
            $picker .= '<a href="'.$this->url('/client/new-booking?kind='.$slug).'" class="block rounded-3xl border px-4 py-4'.$active.'"><p class="text-sm font-black">'.htmlspecialchars($item['label']).'</p><p class="mt-1 text-xs leading-5 '.($slug === $kind ? 'text-stone-200' : 'text-stone-500').'">'.htmlspecialchars($item['hint']).'</p></a>';
        }

        if ($kind === '') {
            $body = '<div class="space-y-5">'.$this->clientBookingProgress(1).'<div class="rounded-3xl border border-stone-200 bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-400">Step 1</p><h2 class="mt-2 text-2xl font-black text-stone-950">What are you booking?</h2><p class="mt-2 text-sm leading-6 text-stone-500">Start here inside your portal. Pick the shoot type first, then continue step by step.</p></div><div class="grid gap-3 sm:grid-cols-2">'.$picker.'</div></div>';
            $this->render('New booking', $this->clientShell('New booking', $body), ['portal' => 'client']);
            return;
        }

        $selected = $kinds[$kind];
        $stmt = $this->db->prepare("SELECT * FROM packages WHERE category=? AND active=1 ORDER BY price ASC, id ASC");
        $stmt->execute([$selected['category']]);
        $packages = $stmt->fetchAll();

        $cards = '';
        foreach ($packages as $package) {
            $cards .= '<div class="rounded-3xl border border-stone-200 bg-white p-4"><div class="flex items-start gap-4"><img src="'.htmlspecialchars($this->packageCoverUrl($package)).'" alt="" class="h-20 w-20 rounded-2xl object-cover shrink-0" style="object-position:center 10%;"><div class="min-w-0 flex-1"><p class="text-lg font-black text-stone-950">'.htmlspecialchars((string)$package['name']).'</p><p class="mt-1 text-sm text-stone-500">'.htmlspecialchars((string)$package['description']).'</p><div class="mt-3 flex flex-wrap gap-2"><span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-700">'.$this->money((float)$package['price']).'</span><span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-700">'.htmlspecialchars($this->packageCoverageLabel($package)).'</span></div><a href="'.$this->url('/client/new-booking?kind='.$kind.'&book='.(int)$package['id'].'#book').'" class="mt-4 inline-flex rounded-full bg-stone-950 px-4 py-2 text-sm font-bold text-white">Continue</a></div></div></div>';
        }
        if ($cards === '') {
            $cards = $this->emptyState('No packages yet', 'No active packages are available for this booking type right now.');
        }

        $form = '';
        $selectedCard = '';
        if ($bookId > 0) {
            $pick = $this->db->prepare("SELECT * FROM packages WHERE id=? AND active=1 AND category=? LIMIT 1");
            $pick->execute([$bookId, $selected['category']]);
            $package = $pick->fetch();
            if ($package) {
                $selectedCard = '<div class="rounded-3xl border border-stone-200 bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-400">Step 3</p><h2 class="mt-2 text-2xl font-black text-stone-950">Finish your booking</h2><div class="mt-4 flex items-start gap-4"><img src="'.htmlspecialchars($this->packageCoverUrl($package)).'" alt="" class="h-24 w-24 rounded-2xl object-cover shrink-0" style="object-position:center 10%;"><div><p class="text-lg font-black text-stone-950">'.htmlspecialchars((string)$package['name']).'</p><p class="mt-1 text-sm text-stone-500">'.htmlspecialchars((string)$package['description']).'</p><div class="mt-3 flex flex-wrap gap-2"><span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-700">'.$this->money((float)$package['price']).'</span><span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-700">'.htmlspecialchars($this->packageCoverageLabel($package)).'</span></div></div></div></div>';
                $form = '<div class="mt-5">'.$this->bookingForm($package, [
                    'portal' => true,
                    'fixed_event_type' => $selected['event_type'],
                    'cancel_href' => '/client/new-booking?kind='.$kind,
                ]).'</div>';
            } else {
                $step = 2;
            }
        }

        if ($step === 2) {
            $body = '<div class="space-y-5">'.$this->clientBookingProgress(2).'<div class="rounded-3xl border border-stone-200 bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-400">Step 2</p><h2 class="mt-2 text-2xl font-black text-stone-950">Choose a package</h2><p class="mt-2 text-sm leading-6 text-stone-500">'.htmlspecialchars($selected['label']).' booking. Pick one package and continue.</p><a href="'.$this->url('/client/new-booking').'" class="mt-4 inline-flex text-sm font-bold text-stone-700">← Change booking type</a></div><div class="space-y-3">'.$cards.'</div></div>';
        } else {
            $body = '<div class="space-y-5">'.$this->clientBookingProgress(3).$selectedCard.$form.'<div class="rounded-3xl border border-stone-200 bg-white p-4"><a href="'.$this->url('/client/new-booking?kind='.$kind).'" class="text-sm font-bold text-stone-700">← Back to package list</a></div></div>';
        }
        $this->render('New booking', $this->clientShell('New booking', $body), ['portal' => 'client']);
    }

    private function clientBookings(): void
    {
        $this->requireRole('client');
        $stmt = $this->db->prepare("SELECT b.*,p.name package_name FROM bookings b JOIN packages p ON p.id=b.package_id WHERE b.user_id=? ORDER BY b.id DESC");
        $stmt->execute([$this->user['id']]);
        $rows = '';
        foreach ($stmt->fetchAll() as $b) $rows .= $this->bookingRow($b,false);
        if (!$rows) $rows = $this->emptyState('No bookings yet','Your bookings will appear here.','/packages','Choose a package');
        $this->render('Bookings',$this->clientShell('Bookings','<div class="space-y-3">'.$rows.'</div>'),['portal'=>'client']);
    }

    private function clientBookingDetail(): void
    {
        $this->requireRole('client');
        $booking = $this->bookingById((int)($_GET['id'] ?? 0), (int)$this->user['id']);
        if (!$booking) { $this->notFound(); return; }

        $paid = $this->bookingPaid((int)$booking['id']);
        $balance = max(0,(float)$booking['total']-$paid);
        $timeline = $this->timelineHtml((int)$booking['id']);
        $paymentBlock = '';
        if ($booking['payment_status'] === 'unpaid' || $booking['payment_status'] === 'partial' || $balance > 0.01) {
            $paymentBlock = $this->paymentInstructions($booking, $balance, $paid <= 0 ? 'deposit' : 'balance');
        }
        $bookingTags = $this->bookingSummaryTags($booking);
        $addonNote = trim((string)($booking['addon_summary'] ?? ''));
        $priorAmount = (float)($booking['prior_payment_amount'] ?? 0);
        $priorNote = trim((string)($booking['prior_payment_note'] ?? ''));
        $extras = '';
        if ($bookingTags !== '') {
            $extras .= '<div class="mt-4 flex flex-wrap gap-2">'.$bookingTags.'</div>';
        }
        if ($addonNote !== '' || (float)($booking['addon_total'] ?? 0) > 0) {
            $extras .= '<div class="rounded-3xl border border-stone-200 bg-white p-5"><h3 class="font-black text-stone-950">Add-ons</h3><p class="mt-2 text-sm text-stone-600">'.htmlspecialchars($addonNote ?: 'No add-ons selected.').'</p><p class="mt-3 text-xs font-bold uppercase tracking-wider text-stone-400">Addon total</p><p class="mt-1 text-lg font-black text-stone-950">'.$this->money((float)($booking['addon_total'] ?? 0)).'</p></div>';
        }
        if ($priorAmount > 0 || $priorNote !== '') {
            $extras .= '<div class="rounded-3xl border border-sky-200 bg-sky-50 p-5"><h3 class="font-black text-sky-950">Client prior-payment note</h3><p class="mt-2 text-sm text-sky-900/80">Already paid: <strong>'.$this->money($priorAmount).'</strong></p>'.($priorNote !== '' ? '<p class="mt-2 text-sm leading-6 text-sky-900/80">'.nl2br(htmlspecialchars($priorNote)).'</p>' : '').'</div>';
        }

        $terms = '';
        if ($booking['payment_status'] !== 'unpaid') {
            if (!(int)$booking['contract_accepted']) {
                $terms = $this->needsFullContract($booking)
                    ? $this->contractFormHtml($booking)
                    : $this->cheatSheetFormHtml($booking);
            } else {
                $label = $this->needsFullContract($booking) ? 'Contract accepted' : 'Cheat sheet acknowledged';
                $download = '<a href="'.$this->url('/client/contract-download?id='.(int)$booking['id']).'" class="inline-flex rounded-full bg-emerald-700 px-4 py-2 text-xs font-bold text-white">Download contract PDF</a>';
                $terms = '<div class="rounded-3xl border border-emerald-100 bg-emerald-50 p-5 text-sm text-emerald-800"><strong>'.$label.'.</strong> On '.htmlspecialchars((string)$booking['contract_accepted_at']).'.<div class="mt-3">'.$download.'</div></div>';
            }
        }

        $cover = htmlspecialchars($this->packageCoverUrl([
            'cover_image' => (string)($booking['cover_image'] ?? ''),
            'category' => (string)($booking['package_category'] ?? 'wedding'),
        ]));
        $body = $this->clientShell('Booking '.$booking['booking_code'],
            '<div class="grid lg:grid-cols-[1.3fr_.7fr] gap-5"><div class="space-y-5"><div class="rounded-3xl border border-slate-200 bg-white overflow-hidden"><div class="offer-detail-media"><img src="'.$cover.'" alt="'.htmlspecialchars($booking['package_name']).'" width="1200" height="800" decoding="async"></div><div class="p-5"><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-wider text-slate-400">'.$booking['booking_code'].'</p><h2 class="mt-1 text-xl font-black">'.htmlspecialchars($booking['package_name']).'</h2><p class="mt-2 text-sm text-slate-600">'.htmlspecialchars($booking['event_type'] ?: 'Photography booking').' · '.htmlspecialchars($this->bookingEventSummary($booking)).'</p></div>'.$this->badge($booking['status']).'</div><div class="mt-5 grid grid-cols-3 gap-3">'.$this->mini('Total',$this->money((float)$booking['total'])).$this->mini('Paid',$this->money($paid)).$this->mini('Balance',$this->money($balance)).'</div>'.$extras.'</div></div>'.$paymentBlock.$terms.'</div><aside><div class="rounded-3xl border border-slate-200 bg-white p-5 sticky top-24"><h3 class="font-black">Journey to your images</h3><p class="mt-1 text-xs text-stone-500">Follow each step until soft copies unlock.</p><div class="mt-5">'.$timeline.'</div></div></aside></div>'
        );
        $this->render('Booking',$body,['portal'=>'client']);
    }

    private function submitPayment(): void
    {
        $this->requireRole('client');
        $booking = $this->bookingById((int)($_POST['booking_id'] ?? 0),(int)$this->user['id']);
        if (!$booking) { $this->notFound(); return; }
        $amount = (float)($_POST['amount'] ?? 0);
        $momoRef = trim($_POST['momo_reference'] ?? '');
        $sender = trim($_POST['sender_number'] ?? '');
        $systemRef = 'PAY-'.$booking['booking_code'].'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,4));
        if ($amount <= 0 || !$momoRef || strlen($sender)<9) {
            $this->flash('error','Enter the amount, sender number and MoMo transaction/reference ID.');
            $this->redirect('/client/booking?id='.$booking['id']);
        }
        $stmt = $this->db->prepare("INSERT INTO payments (booking_id,amount,payment_type,network,sender_number,momo_reference,system_reference,status,submitted_at) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$booking['id'],$amount,trim($_POST['payment_type'] ?? 'deposit'),'MTN',$sender,$momoRef,$systemRef,'pending',$this->now()]);
        $this->sendSms((string)$this->user['phone'], "Hi {$this->user['first_name']}, we received your payment submission of ".$this->money($amount)." for {$booking['booking_code']}. We will review it and update you.");
        $this->flash('success','Payment submitted for verification. You will be notified after approval.');
        $this->redirect('/client/booking?id='.$booking['id']);
    }

    private function acceptContract(): void
    {
        $this->requireRole('client');
        $booking = $this->bookingById((int)($_POST['booking_id'] ?? 0),(int)$this->user['id']);
        if (!$booking) { $this->notFound(); return; }
        if (($_POST['agree'] ?? '') !== '1') {
            $this->flash('error','You must accept to continue.');
            $this->redirect('/client/booking?id='.$booking['id']);
        }
        $acceptedAt = $this->now();
        $snapshot = $this->contractSnapshotText($booking);
        $signer = trim(($this->user['first_name'] ?? '').' '.($this->user['last_name'] ?? ''));
        if ($signer === '') $signer = 'Client';
        $contractFile = $this->writeContractPdf($booking, $snapshot, $signer, $acceptedAt);
        $this->db->prepare("UPDATE bookings SET contract_accepted=1,contract_accepted_at=?,contract_text_snapshot=?,contract_signer_name=?,contract_ip=?,contract_file=?,updated_at=? WHERE id=?")
            ->execute([
                $acceptedAt,
                $snapshot,
                $signer,
                (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                $contractFile,
                $acceptedAt,
                $booking['id'],
            ]);
        $this->sendSms((string)$this->user['phone'], "Hi {$this->user['first_name']}, your service agreement for {$booking['booking_code']} has been accepted. We will keep you updated in your portal.");
        $msg = $this->needsFullContract($booking) ? 'Contract accepted. Your booking record has been updated.' : 'Cheat sheet acknowledged. You are ready for the session.';
        $this->flash('success',$msg);
        $this->redirect('/client/booking?id='.$booking['id']);
    }

    private function downloadContractPdf(): void
    {
        $this->requireRole('client');
        $booking = $this->bookingById((int)($_GET['id'] ?? 0),(int)$this->user['id']);
        if (!$booking) { $this->notFound(); return; }
        if (!(int)($booking['contract_accepted'] ?? 0)) {
            $this->flash('error','Accept the contract first before downloading it.');
            $this->redirect('/client/booking?id='.$booking['id']);
        }
        $snapshot = trim((string)($booking['contract_text_snapshot'] ?? ''));
        $signer = trim((string)($booking['contract_signer_name'] ?? (($this->user['first_name'] ?? '').' '.($this->user['last_name'] ?? ''))));
        if ($snapshot === '' || trim((string)($booking['contract_file'] ?? '')) === '') {
            $acceptedAt = trim((string)($booking['contract_accepted_at'] ?? '')) ?: $this->now();
            $snapshot = $snapshot !== '' ? $snapshot : $this->contractSnapshotText($booking);
            $file = $this->writeContractPdf($booking, $snapshot, $signer !== '' ? $signer : 'Client', $acceptedAt);
            $this->db->prepare("UPDATE bookings SET contract_text_snapshot=COALESCE(NULLIF(contract_text_snapshot,''),?), contract_signer_name=COALESCE(NULLIF(contract_signer_name,''),?), contract_file=COALESCE(NULLIF(contract_file,''),?), updated_at=? WHERE id=?")
                ->execute([$snapshot, $signer, $file, $this->now(), $booking['id']]);
            $booking['contract_file'] = $file;
        }
        $path = __DIR__.'/../storage/contracts/'.basename((string)$booking['contract_file']);
        if (!is_file($path)) {
            $file = $this->writeContractPdf($booking, $snapshot !== '' ? $snapshot : $this->contractSnapshotText($booking), $signer !== '' ? $signer : 'Client', trim((string)($booking['contract_accepted_at'] ?? '')) ?: $this->now());
            $this->db->prepare("UPDATE bookings SET contract_file=?, updated_at=? WHERE id=?")->execute([$file, $this->now(), $booking['id']]);
            $path = __DIR__.'/../storage/contracts/'.basename($file);
        }
        header('Content-Type: application/pdf');
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: attachment; filename="'.basename($path).'"');
        readfile($path);
        exit;
    }

    private function clientPayments(): void
    {
        $this->requireRole('client');
        $stmt = $this->db->prepare("SELECT pay.*,b.booking_code,p.name package_name FROM payments pay JOIN bookings b ON b.id=pay.booking_id JOIN packages p ON p.id=b.package_id WHERE b.user_id=? ORDER BY pay.id DESC");
        $stmt->execute([$this->user['id']]);
        $rows = '';
        foreach ($stmt->fetchAll() as $p) {
            $rows .= '<div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex items-center justify-between gap-3"><div><p class="text-sm font-black">'.htmlspecialchars($p['package_name']).' · '.$p['booking_code'].'</p><p class="mt-1 text-xs text-slate-500">'.$p['system_reference'].' · '.$p['submitted_at'].'</p></div>'.$this->badge($p['status']).'</div><div class="mt-4 text-2xl font-black">'.$this->money((float)$p['amount']).'</div></div>';
        }
        if (!$rows) $rows = $this->emptyState('No payments yet','Submitted payments and their verification status will appear here.');
        $this->render('Payments',$this->clientShell('Payments','<div class="space-y-3">'.$rows.'</div>'),['portal'=>'client']);
    }

    private function clientFiles(): void
    {
        $this->requireRole('client');
        $stmt = $this->db->prepare("SELECT f.*,b.booking_code,p.name package_name FROM files f JOIN bookings b ON b.id=f.booking_id JOIN packages p ON p.id=b.package_id WHERE b.user_id=? ORDER BY f.id DESC");
        $stmt->execute([$this->user['id']]);
        $rows = '';
        foreach ($stmt->fetchAll() as $f) {
            $rows .= '<div class="rounded-3xl border border-slate-200 bg-white p-4 flex items-center justify-between gap-4"><div class="min-w-0"><p class="font-bold truncate">'.htmlspecialchars($f['original_name']).'</p><p class="mt-1 text-xs text-slate-500">'.htmlspecialchars($f['package_name']).' · '.$this->formatBytes((int)$f['file_size']).'</p></div><a href="'.$this->url('/download?id='.$f['id']).'" class="shrink-0 rounded-xl bg-slate-950 px-3 py-2 text-xs font-bold text-white">Download</a></div>';
        }
        if (!$rows) $rows = $this->emptyState('No files delivered yet','Your final soft copies will appear here when the studio releases them.');
        $this->render('Files',$this->clientShell('Delivered files','<div class="space-y-3">'.$rows.'</div>'),['portal'=>'client']);
    }

    private function downloadFile(): void
    {
        $this->requireRole('client');
        $stmt = $this->db->prepare("SELECT f.* FROM files f JOIN bookings b ON b.id=f.booking_id WHERE f.id=? AND b.user_id=?");
        $stmt->execute([(int)($_GET['id'] ?? 0),$this->user['id']]);
        $file = $stmt->fetch();
        if (!$file) { $this->notFound(); return; }
        $path = __DIR__ . '/../storage/uploads/deliveries/' . basename($file['stored_name']);
        if (!is_file($path)) { $this->notFound(); return; }
        header('Content-Type: '.($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: attachment; filename="'.str_replace('"','', $file['original_name']).'"');
        readfile($path);
        exit;
    }

    private function clientProfile(): void
    {
        $this->requireRole('client');
        $form = '<form method="post" action="'.$this->url('/client/profile').'" class="space-y-4 max-w-xl">'.$this->csrfField().
            $this->input('first_name','First name','text',$this->user['first_name'],'Your first name').
            $this->input('last_name','Last name','text',$this->user['last_name'],'Your last name').
            $this->input('email','Email','email',$this->user['email'] ?? '','name@email.com').
            '<div><label class="text-sm font-bold">Phone number</label><input disabled value="'.htmlspecialchars($this->user['phone']).'" class="mt-1 w-full rounded-2xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-500"></div><button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Save profile</button></form>';
        $this->render('Profile',$this->clientShell('Profile',$form),['portal'=>'client']);
    }

    private function updateProfile(): void
    {
        $this->requireRole('client');
        $this->db->prepare("UPDATE users SET first_name=?,last_name=?,email=? WHERE id=?")->execute([trim($_POST['first_name'] ?? ''),trim($_POST['last_name'] ?? ''),trim($_POST['email'] ?? ''),$this->user['id']]);
        $this->flash('success','Profile updated.');
        $this->redirect('/client/profile');
    }

    private function adminDashboard(): void
    {
        $this->requireRole('admin');
        $bookings = (int)$this->db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
        $clients = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn();
        $pending = (int)$this->db->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
        $revenue = (float)$this->db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='verified'")->fetchColumn();
        $weddings = (int)$this->db->query("SELECT COUNT(*) FROM bookings b JOIN packages p ON p.id=b.package_id WHERE p.category='wedding'")->fetchColumn();
        $baby = (int)$this->db->query("SELECT COUNT(*) FROM bookings b JOIN packages p ON p.id=b.package_id WHERE p.category='baby'")->fetchColumn();
        $studio = (int)$this->db->query("SELECT COUNT(*) FROM bookings b JOIN packages p ON p.id=b.package_id WHERE p.category='studio'")->fetchColumn();
        $smsSpent = (float)$this->db->query("SELECT COALESCE(SUM(cost),0) FROM sms_log")->fetchColumn();
        $smsAccepted = (int)$this->db->query("SELECT COUNT(*) FROM sms_log WHERE status IN ('sent','delivered')")->fetchColumn();
        $smsDelivered = (int)$this->db->query("SELECT COUNT(*) FROM sms_log WHERE status='delivered'")->fetchColumn();
        $smsFailed = (int)$this->db->query("SELECT COUNT(*) FROM sms_log WHERE status IN ('error','logged')")->fetchColumn();
        $smsBalance = $this->fetchSmsBalance();
        $balanceLabel = $smsBalance['ok']
            ? htmlspecialchars($smsBalance['label'])
            : '—';
        $balanceHint = $smsBalance['ok']
            ? htmlspecialchars($smsBalance['hint'] ?? '')
            : htmlspecialchars($smsBalance['error'] ?? 'Configure SMS in Settings');

        $byStatus = $this->db->query("SELECT status, COUNT(*) c FROM bookings GROUP BY status ORDER BY c DESC")->fetchAll();
        $statusLabels = [];
        $statusCounts = [];
        foreach ($byStatus as $row) {
            $statusLabels[] = ucwords(str_replace('_', ' ', (string)$row['status']));
            $statusCounts[] = (int)$row['c'];
        }

        $months = [];
        $revSeries = [];
        $bookSeries = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = date('Y-m', strtotime("-{$i} months"));
            $label = date('M', strtotime("-{$i} months"));
            $months[] = $label;
            $r = $this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='verified' AND substr(COALESCE(verified_at,submitted_at),1,7)=?");
            $r->execute([$key]);
            $revSeries[] = round((float)$r->fetchColumn(), 2);
            $b = $this->db->prepare("SELECT COUNT(*) FROM bookings WHERE substr(created_at,1,7)=?");
            $b->execute([$key]);
            $bookSeries[] = (int)$b->fetchColumn();
        }

        $chartPayload = json_encode([
            'categories' => ['labels' => ['Wedding', 'Baby', 'Studio'], 'data' => [$weddings, $baby, $studio]],
            'status' => ['labels' => $statusLabels, 'data' => $statusCounts],
            'months' => $months,
            'revenue' => $revSeries,
            'bookings' => $bookSeries,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->query("SELECT b.*,p.name package_name,u.first_name,u.last_name FROM bookings b JOIN packages p ON p.id=b.package_id JOIN users u ON u.id=b.user_id ORDER BY b.id DESC LIMIT 6");
        $rows = '';
        foreach ($stmt->fetchAll() as $b) $rows .= $this->bookingRow($b,true);
        if (!$rows) $rows = '<p class="text-sm text-stone-500">No bookings yet.</p>';

        $body = $this->adminShell('Dashboard',
            '<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
              '.$this->stat('Revenue',$this->money($revenue),'fa-solid fa-coins').'
              '.$this->stat('Bookings',(string)$bookings,'fa-solid fa-calendar-check').'
              '.$this->stat('Weddings',(string)$weddings,'fa-solid fa-ring').'
              '.$this->stat('Pending pay',(string)$pending,'fa-solid fa-clock').'
            </div>
            <div class="mt-3 grid grid-cols-2 lg:grid-cols-4 gap-3">
              '.$this->stat('Clients',(string)$clients,'fa-solid fa-users').'
              '.$this->stat('SMS left',$balanceLabel,'fa-solid fa-comment-sms').'
              '.$this->stat('SMS spent',$this->money($smsSpent),'fa-solid fa-money-bill-wave').'
              '.$this->stat('SMS delivered',(string)$smsDelivered,'fa-solid fa-paper-plane').'
            </div>
            <p class="mt-2 text-xs text-stone-500">'.$balanceHint.' · Provider: '.htmlspecialchars($this->smsProvider()).' · Accepted: '.$smsAccepted.' · Failed/logged: '.$smsFailed.'</p>

            <div class="mt-6 grid lg:grid-cols-3 gap-4">
              <div class="rounded-3xl border border-stone-200 bg-white p-5 lg:col-span-2">
                <div class="flex items-center justify-between gap-3"><h2 class="font-black"><i class="fa-solid fa-chart-line mr-2 text-stone-400"></i>Revenue &amp; bookings</h2><p class="text-xs text-stone-400">Last 6 months</p></div>
                <div class="mt-4 h-56"><canvas id="chartTrend" aria-label="Revenue and bookings trend"></canvas></div>
              </div>
              <div class="rounded-3xl border border-stone-200 bg-white p-5">
                <h2 class="font-black"><i class="fa-solid fa-layer-group mr-2 text-stone-400"></i>By category</h2>
                <div class="mt-4 h-56"><canvas id="chartCats" aria-label="Bookings by category"></canvas></div>
              </div>
            </div>
            <div class="mt-4 grid lg:grid-cols-2 gap-4">
              <div class="rounded-3xl border border-stone-200 bg-white p-5">
                <h2 class="font-black"><i class="fa-solid fa-bars-progress mr-2 text-stone-400"></i>Booking status</h2>
                <div class="mt-4 h-52"><canvas id="chartStatus" aria-label="Bookings by status"></canvas></div>
              </div>
              <div class="rounded-3xl border border-stone-200 bg-white p-5">
                <h2 class="font-black"><i class="fa-solid fa-wallet mr-2 text-stone-400"></i>Money snapshot</h2>
                <div class="mt-4 space-y-3">
                  '.$this->mini('Verified revenue',$this->money($revenue)).'
                  '.$this->mini('Pending payments',(string)$pending).'
                  '.$this->mini('SMS spend (logged)',$this->money($smsSpent)).'
                  '.$this->mini('SMS balance',$balanceLabel).'
                </div>
                <p class="mt-4 text-xs text-stone-500">Set unit cost under Settings → SMS to estimate spend when providers do not return cost.</p>
              </div>
            </div>

            <div class="mt-5 grid sm:grid-cols-3 gap-3">
              <a href="'.$this->url('/dashboard/settings').'" class="dash-link"><span class="dash-link-icon"><i class="fa-solid fa-gear"></i></span><span><p class="text-xs font-bold uppercase tracking-wider text-stone-400">Studio</p><p class="mt-1 font-black">Settings</p><p class="mt-1 text-xs text-stone-500">Brand, MoMo, Arkesel &amp; Moolre SMS</p></span></a>
              <a href="'.$this->url('/dashboard/packages').'" class="dash-link"><span class="dash-link-icon"><i class="fa-solid fa-box-open"></i></span><span><p class="text-xs font-bold uppercase tracking-wider text-stone-400">Offers</p><p class="mt-1 font-black">Packages</p><p class="mt-1 text-xs text-stone-500">Create, edit, activate packages</p></span></a>
              <a href="'.$this->url('/dashboard/slides').'" class="dash-link"><span class="dash-link-icon"><i class="fa-solid fa-images"></i></span><span><p class="text-xs font-bold uppercase tracking-wider text-stone-400">Homepage</p><p class="mt-1 font-black">Slides</p><p class="mt-1 text-xs text-stone-500">Upload and reorder hero images</p></span></a>
            </div>
            <div class="mt-7 flex items-center justify-between"><h2 class="text-lg font-black"><i class="fa-solid fa-clock-rotate-left mr-2 text-stone-400"></i>Recent bookings</h2><a href="'.$this->url('/dashboard/bookings').'" class="text-sm font-bold">View all →</a></div><div class="mt-3 space-y-3">'.$rows.'</div>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
            <script>
            (function(){
              const D = '.$chartPayload.';
              const ink = "#1c1917";
              const mute = "#a8a29e";
              const grid = "#e7e5e4";
              Chart.defaults.font.family = "Outfit, ui-sans-serif, system-ui, sans-serif";
              Chart.defaults.color = mute;
              new Chart(document.getElementById("chartTrend"), {
                type: "line",
                data: {
                  labels: D.months,
                  datasets: [
                    { label: "Revenue (GHS)", data: D.revenue, borderColor: ink, backgroundColor: "rgba(28,25,23,.08)", tension: .35, fill: true, yAxisID: "y" },
                    { label: "Bookings", data: D.bookings, borderColor: "#b45309", backgroundColor: "transparent", tension: .35, yAxisID: "y1" }
                  ]
                },
                options: {
                  responsive: true, maintainAspectRatio: false,
                  plugins: { legend: { position: "bottom", labels: { boxWidth: 10, usePointStyle: true } } },
                  scales: {
                    x: { grid: { display: false } },
                    y: { position: "left", grid: { color: grid }, ticks: { callback: v => "GH₵" + v } },
                    y1: { position: "right", grid: { drawOnChartArea: false }, ticks: { precision: 0 } }
                  }
                }
              });
              new Chart(document.getElementById("chartCats"), {
                type: "doughnut",
                data: {
                  labels: D.categories.labels,
                  datasets: [{ data: D.categories.data, backgroundColor: ["#1c1917","#b45309","#d6d3d1"], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom", labels: { boxWidth: 10, usePointStyle: true } } } }
              });
              new Chart(document.getElementById("chartStatus"), {
                type: "bar",
                data: {
                  labels: D.status.labels.length ? D.status.labels : ["None"],
                  datasets: [{ data: D.status.data.length ? D.status.data : [0], backgroundColor: "#1c1917", borderRadius: 8, maxBarThickness: 36 }]
                },
                options: {
                  responsive: true, maintainAspectRatio: false,
                  plugins: { legend: { display: false } },
                  scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: grid } } }
                }
              });
            })();
            </script>'
        );
        $this->render('Admin dashboard',$body,['portal'=>'admin']);
    }

    private function adminBookings(): void
    {
        $this->requireRole('admin');
        $stmt = $this->db->query("SELECT b.*,p.name package_name,u.first_name,u.last_name FROM bookings b JOIN packages p ON p.id=b.package_id JOIN users u ON u.id=b.user_id ORDER BY b.id DESC");
        $rows = '';
        foreach ($stmt->fetchAll() as $b) $rows .= $this->bookingRow($b,true);
        if (!$rows) $rows = $this->emptyState('No bookings','Client bookings will appear here.');
        $this->render('Admin bookings',$this->adminShell('Bookings','<div class="space-y-3">'.$rows.'</div>'),['portal'=>'admin']);
    }

    private function adminBookingDetail(): void
    {
        $this->requireRole('admin');
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $this->db->prepare("SELECT b.*,p.name package_name,u.first_name,u.last_name,u.phone,u.email FROM bookings b JOIN packages p ON p.id=b.package_id JOIN users u ON u.id=b.user_id WHERE b.id=?");
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) { $this->notFound(); return; }

        $payments = $this->db->prepare("SELECT * FROM payments WHERE booking_id=? ORDER BY id DESC");
        $payments->execute([$id]);
        $paymentRows = '';
        foreach ($payments->fetchAll() as $p) {
            $actions = '';
            if ($p['status']==='pending') {
                $actions = '<div class="mt-3 flex gap-2"><form method="post" action="'.$this->url('/dashboard/payment-verify').'">'.$this->csrfField().'<input type="hidden" name="payment_id" value="'.$p['id'].'"><button class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Verify</button></form><form method="post" action="'.$this->url('/dashboard/payment-reject').'">'.$this->csrfField().'<input type="hidden" name="payment_id" value="'.$p['id'].'"><button class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">Reject</button></form></div>';
            }
            $paymentRows .= '<div class="rounded-2xl border border-slate-200 p-4"><div class="flex justify-between gap-3"><div><p class="font-bold">'.$this->money((float)$p['amount']).'</p><p class="text-xs text-slate-500">MTN '.$p['sender_number'].' · '.$p['momo_reference'].'</p></div>'.$this->badge($p['status']).'</div>'.$actions.'</div>';
        }

        $timeline = $this->timelineHtml($id,true);
        $body = $this->adminShell('Booking '.$b['booking_code'],
            '<div class="grid xl:grid-cols-[1.1fr_.9fr] gap-5"><div class="space-y-5"><div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-bold text-slate-400">'.$b['booking_code'].'</p><h2 class="mt-1 text-xl font-black">'.htmlspecialchars($b['first_name'].' '.$b['last_name']).'</h2><p class="mt-1 text-sm text-slate-600">'.htmlspecialchars($b['package_name']).' · '.htmlspecialchars($b['phone']).'</p></div>'.$this->badge($b['status']).'</div><div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3">'.$this->mini('Total',$this->money((float)$b['total'])).$this->mini('Paid',$this->money($this->bookingPaid($id))).$this->mini('Event',$b['event_date'] ?: 'TBC').$this->mini('Contract',(int)$b['contract_accepted']?'Accepted':'Pending').'</div></div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Update booking</h3><form method="post" action="'.$this->url('/dashboard/booking-status').'" class="mt-4 flex flex-col sm:flex-row gap-3">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$id.'"><select name="status" class="rounded-xl border border-slate-200 px-4 py-3 text-sm"><option value="awaiting_payment">Awaiting payment</option><option value="confirmed">Confirmed</option><option value="scheduled">Scheduled</option><option value="shoot_completed">Shoot completed</option><option value="editing">Editing</option><option value="ready">Ready</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select><button class="rounded-xl bg-slate-950 px-4 py-3 text-sm font-bold text-white">Update status</button></form></div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Payments</h3><div class="mt-4 space-y-3">'.($paymentRows ?: '<p class="text-sm text-slate-500">No payment submitted.</p>').'</div></div></div><aside class="space-y-5"><div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Timeline</h3><div class="mt-4">'.$timeline.'</div><form method="post" action="'.$this->url('/dashboard/timeline-add').'" class="mt-5 space-y-3">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$id.'">'.$this->input('title','New timeline step','text','','e.g. Editing started').$this->input('due_date','Due date','date','','Target date').'<button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Add step</button></form></div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Deliver soft copies</h3><p class="mt-1 text-sm text-slate-500">Files uploaded here are protected and only this client can download them.</p><form method="post" enctype="multipart/form-data" action="'.$this->url('/dashboard/file-upload').'" class="mt-4 space-y-3">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$id.'"><input required type="file" name="delivery_file" class="block w-full text-sm"><button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Upload file</button></form></div></aside></div>'
        );
        $this->render('Booking admin',$body,['portal'=>'admin']);
    }

    private function adminPayments(): void
    {
        $this->requireRole('admin');
        $stmt = $this->db->query("SELECT pay.*,b.booking_code,u.first_name,u.last_name FROM payments pay JOIN bookings b ON b.id=pay.booking_id JOIN users u ON u.id=b.user_id ORDER BY CASE pay.status WHEN 'pending' THEN 0 ELSE 1 END,pay.id DESC");
        $rows='';
        foreach($stmt->fetchAll() as $p){
            $actions = $p['status']==='pending' ? '<div class="mt-3 flex gap-2"><form method="post" action="'.$this->url('/dashboard/payment-verify').'">'.$this->csrfField().'<input type="hidden" name="payment_id" value="'.$p['id'].'"><button class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Verify</button></form><form method="post" action="'.$this->url('/dashboard/payment-reject').'">'.$this->csrfField().'<input type="hidden" name="payment_id" value="'.$p['id'].'"><button class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">Reject</button></form></div>' : '';
            $rows.='<div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="font-black">'.htmlspecialchars($p['first_name'].' '.$p['last_name']).' · '.$p['booking_code'].'</p><p class="mt-1 text-xs text-slate-500">MTN '.$p['sender_number'].' · Txn '.$p['momo_reference'].'</p></div><div class="text-right"><p class="text-lg font-black">'.$this->money((float)$p['amount']).'</p>'.$this->badge($p['status']).'</div></div>'.$actions.'</div>';
        }
        if(!$rows)$rows=$this->emptyState('No payments','Client payment submissions will appear here.');
        $this->render('Payments',$this->adminShell('Payments','<div class="space-y-3">'.$rows.'</div>'),['portal'=>'admin']);
    }

    private function verifyPayment(): void
    {
        $this->requireRole('admin');
        $id=(int)($_POST['payment_id']??0);
        $stmt=$this->db->prepare("SELECT pay.*,b.user_id,b.id booking_id,b.booking_code,u.phone,u.first_name FROM payments pay JOIN bookings b ON b.id=pay.booking_id JOIN users u ON u.id=b.user_id WHERE pay.id=?");
        $stmt->execute([$id]); $p=$stmt->fetch();
        if (!$p) { $this->notFound(); return; }
        $this->db->prepare("UPDATE payments SET status='verified',verified_at=?,verified_by=? WHERE id=?")->execute([$this->now(),$this->user['id'],$id]);
        $paid=$this->bookingPaid((int)$p['booking_id']);
        $b=$this->bookingById((int)$p['booking_id']);
        $status = $paid + 0.001 >= (float)$b['total'] ? 'paid' : 'partial';
        $bookingStatus = $paid + 0.001 >= (float)$b['deposit_required'] ? 'confirmed' : 'awaiting_payment';
        $this->db->prepare("UPDATE bookings SET payment_status=?,status=?,updated_at=? WHERE id=?")->execute([$status,$bookingStatus,$this->now(),$p['booking_id']]);
        if($bookingStatus==='confirmed'){
            $this->db->prepare("UPDATE timeline SET status='completed',completed_at=? WHERE booking_id=? AND sort_order=1")->execute([$this->now(),$p['booking_id']]);
        }
        $balance = max(0, (float)$b['total'] - $paid);
        $this->sendSms($p['phone'],"Hi {$p['first_name']}, receipt confirmed for {$p['booking_code']}. We received ".$this->money((float)$p['amount']).". Total paid: ".$this->money($paid).". Balance left: ".$this->money($balance).". Log in to your portal for full details.");
        $this->flash('success','Payment verified and client notification processed.');
        $this->redirect('/dashboard/booking?id='.$p['booking_id']);
    }

    private function rejectPayment(): void
    {
        $this->requireRole('admin');
        $id=(int)($_POST['payment_id']??0);
        $stmt=$this->db->prepare("SELECT pay.*,b.id booking_id,u.phone,u.first_name FROM payments pay JOIN bookings b ON b.id=pay.booking_id JOIN users u ON u.id=b.user_id WHERE pay.id=?");
        $stmt->execute([$id]); $p=$stmt->fetch();
        if (!$p) { $this->notFound(); return; }
        $this->db->prepare("UPDATE payments SET status='rejected',verified_at=?,verified_by=? WHERE id=?")->execute([$this->now(),$this->user['id'],$id]);
        $this->sendSms($p['phone'],"Hi {$p['first_name']}, we could not verify your submitted MoMo payment. Please check the transaction reference and resubmit from your portal.");
        $this->flash('success','Payment rejected.');
        $this->redirect('/dashboard/booking?id='.$p['booking_id']);
    }

    private function updateBookingStatus(): void
    {
        $this->requireRole('admin');
        $id=(int)($_POST['booking_id']??0);
        $status=trim($_POST['status']??'');
        $allowed=['awaiting_payment','confirmed','scheduled','shoot_completed','editing','ready','completed','cancelled'];
        if(!in_array($status,$allowed,true)) throw new RuntimeException('Invalid status.');
        $this->db->prepare("UPDATE bookings SET status=?,updated_at=? WHERE id=?")->execute([$status,$this->now(),$id]);
        $map=['confirmed'=>2,'scheduled'=>2,'shoot_completed'=>3,'editing'=>4,'ready'=>5,'completed'=>5];
        if(isset($map[$status])){
            $this->db->prepare("UPDATE timeline SET status='completed',completed_at=COALESCE(completed_at,?) WHERE booking_id=? AND sort_order<=?")->execute([$this->now(),$id,$map[$status]]);
        }
        $stmt=$this->db->prepare("SELECT u.phone,u.first_name,b.booking_code FROM bookings b JOIN users u ON u.id=b.user_id WHERE b.id=?");
        $stmt->execute([$id]);$c=$stmt->fetch();
        if($c)$this->sendSms($c['phone'],"Hi {$c['first_name']}, your booking {$c['booking_code']} is now ".str_replace('_',' ',$status).". Check your portal for details.");
        $this->flash('success','Booking status updated.');
        $this->redirect('/dashboard/booking?id='.$id);
    }

    private function addTimeline(): void
    {
        $this->requireRole('admin');
        $id=(int)($_POST['booking_id']??0);
        $title = trim($_POST['title']??'Milestone');
        $order=(int)$this->db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM timeline WHERE booking_id=".$id)->fetchColumn();
        $this->db->prepare("INSERT INTO timeline (booking_id,title,description,status,due_date,sort_order,created_at) VALUES (?,?,?,?,?,?,?)")->execute([$id,$title,'','pending',trim($_POST['due_date']??''),$order,$this->now()]);
        $stmt=$this->db->prepare("SELECT u.phone,u.first_name,b.booking_code FROM bookings b JOIN users u ON u.id=b.user_id WHERE b.id=?");
        $stmt->execute([$id]);$c=$stmt->fetch();
        if($c)$this->sendSms($c['phone'],"Hi {$c['first_name']}, there is a new update on your booking {$c['booking_code']}: {$title}. Check your portal for details.");
        $this->flash('success','Timeline step added.');
        $this->redirect('/dashboard/booking?id='.$id);
    }

    private function uploadDelivery(): void
    {
        $this->requireRole('admin');
        $id=(int)($_POST['booking_id']??0);
        if(!isset($_FILES['delivery_file']) || $_FILES['delivery_file']['error']!==UPLOAD_ERR_OK) {
            $this->flash('error','File upload failed.');
            $this->redirect('/dashboard/booking?id='.$id);
        }
        $f=$_FILES['delivery_file'];
        if($f['size']>200*1024*1024) throw new RuntimeException('File exceeds the 200 MB application limit.');
        $ext=pathinfo($f['name'],PATHINFO_EXTENSION);
        $stored=bin2hex(random_bytes(16)).($ext?'.'.preg_replace('/[^a-zA-Z0-9]/','',$ext):'');
        $dir=__DIR__.'/../storage/uploads/deliveries';
        if(!is_dir($dir))mkdir($dir,0775,true);
        if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$stored)) throw new RuntimeException('Could not store uploaded file.');
        $mime=function_exists('mime_content_type') ? mime_content_type($dir.'/'.$stored) : 'application/octet-stream';
        $this->db->prepare("INSERT INTO files (booking_id,original_name,stored_name,mime_type,file_size,category,uploaded_at) VALUES (?,?,?,?,?,'final',?)")->execute([$id,$f['name'],$stored,$mime,$f['size'],$this->now()]);
        $stmt=$this->db->prepare("SELECT u.phone,u.first_name,b.booking_code FROM bookings b JOIN users u ON u.id=b.user_id WHERE b.id=?");$stmt->execute([$id]);$c=$stmt->fetch();
        if($c)$this->sendSms($c['phone'],"Hi {$c['first_name']}, a new file has been delivered for {$c['booking_code']}. Log in to your client portal to download it.");
        $this->flash('success','File uploaded and made available to the client.');
        $this->redirect('/dashboard/booking?id='.$id);
    }

    private function adminPackages(): void
    {
        $this->requireRole('admin');
        $editId = (int)($_GET['edit'] ?? 0);
        $editing = null;
        if ($editId > 0) {
            $stmt = $this->db->prepare("SELECT * FROM packages WHERE id=?");
            $stmt->execute([$editId]);
            $editing = $stmt->fetch() ?: null;
        }
        $meta = $this->packageCategoryMeta();
        $all = $this->db->query("SELECT * FROM packages ORDER BY CASE category WHEN 'wedding' THEN 1 WHEN 'baby' THEN 2 WHEN 'studio' THEN 3 ELSE 4 END, price ASC")->fetchAll();
        $byCat = ['wedding' => [], 'baby' => [], 'studio' => []];
        foreach ($all as $p) {
            $c = $p['category'] ?? 'wedding';
            if (!isset($byCat[$c])) $byCat[$c] = [];
            $byCat[$c][] = $p;
        }

        $sections = '';
        foreach ($byCat as $slug => $pkgs) {
            if (!$pkgs) continue;
            $label = htmlspecialchars($meta[$slug]['label'] ?? $slug);
            $rows = '';
            foreach ($pkgs as $p) {
                $active = (int)$p['active'] === 1;
                $editHref = $this->url('/dashboard/packages?edit='.$p['id']);
                $isEdit = $editing && (int)$editing['id'] === (int)$p['id'];
                $rowCls = $isEdit ? 'pkg-row is-editing' : 'pkg-row';
                $status = $active
                    ? '<span class="pkg-dot on"></span>Live'
                    : '<span class="pkg-dot"></span>Off';
                $toggleLabel = $active ? 'Deactivate' : 'Reactivate';
                $thumb = htmlspecialchars($this->packageCoverUrl($p));
                $rows .= '<div class="'.$rowCls.'">'
                    .'<div class="pkg-row-main">'
                    .'<img class="pkg-thumb" src="'.$thumb.'" alt="">'
                    .'<div><a class="pkg-name" href="'.$editHref.'">'.htmlspecialchars($p['name']).'</a>'
                    .'<p class="pkg-meta">'.$this->money((float)$p['price']).' · '.(int)$p['turnaround_days'].' day turnaround</p></div>'
                    .'</div>'
                    .'<div class="pkg-row-side">'
                    .'<span class="pkg-status">'.$status.'</span>'
                    .'<a class="pkg-link" href="'.$editHref.'"><i class="fa-solid fa-pen mr-1"></i>Edit</a>'
                    .'<form method="post" action="'.$this->url('/dashboard/package-delete').'" class="inline">'
                    .$this->csrfField().'<input type="hidden" name="id" value="'.$p['id'].'">'
                    .'<button class="pkg-link muted" type="submit"><i class="fa-solid fa-'.($active?'ban':'rotate-right').' mr-1"></i>'.$toggleLabel.'</button></form>'
                    .'</div></div>';
            }
            $catIcons = [
                'wedding' => 'fa-solid fa-ring',
                'baby' => 'fa-solid fa-baby',
                'studio' => 'fa-solid fa-camera',
            ];
            $sections .= '<section class="pkg-group"><header class="pkg-group-head"><h2><i class="'.($catIcons[$slug] ?? 'fa-solid fa-box').' mr-2 text-stone-400"></i>'.$label.'</h2><span>'.count($pkgs).'</span></header><div class="pkg-list">'.$rows.'</div></section>';
        }
        if ($sections === '') {
            $sections = '<p class="text-sm text-stone-500">No packages yet. Create one on the right.</p>';
        }

        $form = $this->packageFormHtml($editing);
        $heading = $editing ? '<i class="fa-solid fa-pen-to-square mr-2 text-stone-400"></i>Edit package' : '<i class="fa-solid fa-plus mr-2 text-stone-400"></i>New package';
        $cancel = $editing ? '<a class="pkg-link muted" href="'.$this->url('/dashboard/packages').'">Cancel</a>' : '';

        $body = '
        <style>
        .pkg-page{display:grid;gap:1.25rem}
        @media(min-width:1100px){.pkg-page{grid-template-columns:minmax(0,1.15fr) minmax(18rem,.85fr);align-items:start}}
        .pkg-tunnel{border:1px solid #e7e5e4;background:#fff;border-radius:1.25rem;padding:1.15rem 1.2rem 1.25rem}
        .pkg-tunnel h2{font-size:.95rem;font-weight:800;letter-spacing:.01em}
        .pkg-tunnel p.lead{margin:.35rem 0 1rem;font-size:.8rem;line-height:1.45;color:#78716c;max-width:42rem}
        .tunnel{display:grid;gap:.55rem}
        @media(min-width:720px){.tunnel{grid-template-columns:repeat(5,minmax(0,1fr));gap:.45rem}}
        .tunnel-step{position:relative;border-radius:1rem;background:#fafaf9;border:1px solid #f5f5f4;padding:.85rem .8rem .9rem;min-height:100%}
        .tunnel-num{display:inline-grid;place-items:center;width:1.7rem;height:1.7rem;border-radius:999px;background:#1c1917;color:#fff;font-size:.72rem}
        .tunnel-step strong{display:block;margin-top:.55rem;font-size:.78rem;font-weight:800;color:#1c1917;line-height:1.25}
        .tunnel-step span{display:block;margin-top:.3rem;font-size:.68rem;line-height:1.4;color:#78716c}
        .pkg-board{display:grid;gap:1rem}
        .pkg-group{border:1px solid #e7e5e4;background:#fff;border-radius:1.25rem;overflow:hidden}
        .pkg-group-head{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1rem;border-bottom:1px solid #f5f5f4}
        .pkg-group-head h2{font-size:.82rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#57534e}
        .pkg-group-head span{font-size:.72rem;font-weight:700;color:#a8a29e}
        .pkg-list{display:flex;flex-direction:column}
        .pkg-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;padding:.9rem 1rem;border-bottom:1px solid #f5f5f4}
        .pkg-row-main{display:flex;align-items:center;gap:.75rem;min-width:0}
        .pkg-thumb{width:3.1rem;height:2.2rem;object-fit:cover;border-radius:.55rem;background:#e7e5e4;flex-shrink:0}
        .pkg-cover-preview{border-radius:.85rem;overflow:hidden;border:1px solid #e7e5e4;aspect-ratio:3/2;max-width:14rem}
        .pkg-cover-preview img{width:100%;height:100%;object-fit:cover;display:block}
        .pkg-row:last-child{border-bottom:0}
        .pkg-row.is-editing{background:#fafaf9}
        .pkg-name{font-size:.95rem;font-weight:800;color:#1c1917;text-decoration:none}
        .pkg-name:hover{color:#44403c}
        .pkg-meta{margin-top:.2rem;font-size:.75rem;color:#a8a29e}
        .pkg-row-side{display:flex;flex-wrap:wrap;align-items:center;gap:.65rem .85rem}
        .pkg-status{display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;color:#57534e}
        .pkg-dot{width:.45rem;height:.45rem;border-radius:999px;background:#d6d3d1;display:inline-block}
        .pkg-dot.on{background:#16a34a}
        .pkg-link{font-size:.72rem;font-weight:800;color:#1c1917;text-decoration:none;background:none;border:0;padding:0;cursor:pointer}
        .pkg-link.muted{color:#a8a29e}
        .pkg-link:hover{color:#57534e}
        .pkg-editor{border:1px solid #e7e5e4;background:#fff;border-radius:1.25rem;padding:1.15rem 1.2rem 1.25rem;position:sticky;top:4.5rem}
        .pkg-editor-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
        .pkg-editor-head h2{font-size:1rem;font-weight:900}
        .pkg-editor form{display:grid;gap:.85rem}
        @media(min-width:640px){.pkg-editor form.two{grid-template-columns:1fr 1fr}}
        .pkg-editor .span-2{grid-column:1/-1}
        .pkg-editor label{display:block;font-size:.72rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#78716c;margin-bottom:.35rem}
        .pkg-editor input,.pkg-editor select,.pkg-editor textarea{width:100%;border:1px solid #e7e5e4;border-radius:.85rem;padding:.7rem .85rem;font-size:.9rem;background:#fff;outline:none}
        .pkg-editor input:focus,.pkg-editor select:focus,.pkg-editor textarea:focus{border-color:#a8a29e}
        .pkg-editor textarea{min-height:5.5rem;resize:vertical}
        .pkg-save{display:inline-flex;align-items:center;justify-content:center;min-height:2.55rem;padding:0 1.1rem;border-radius:.85rem;background:#1c1917;color:#fff;font-size:.82rem;font-weight:800;border:0;cursor:pointer}
        </style>

        <div class="pkg-tunnel">
          <h2><i class="fa-solid fa-route mr-2 text-stone-400"></i>Client journey tunnel</h2>
          <p class="lead">Every booking follows this path in the client portal — from first payment to images in Downloads.</p>
          <div class="tunnel">
            <div class="tunnel-step"><span class="tunnel-num"><i class="fa-solid fa-credit-card"></i></span><strong>Book &amp; pay</strong><span>Pick package → MoMo pay → admin verifies.</span></div>
            <div class="tunnel-step"><span class="tunnel-num"><i class="fa-solid fa-file-signature"></i></span><strong>Confirm terms</strong><span>Review and accept the service agreement.</span></div>
            <div class="tunnel-step"><span class="tunnel-num"><i class="fa-solid fa-camera"></i></span><strong>Shoot day</strong><span>Coverage on the booked date.</span></div>
            <div class="tunnel-step"><span class="tunnel-num"><i class="fa-solid fa-wand-magic-sparkles"></i></span><strong>Edit &amp; polish</strong><span>Select, edit, retouch to package.</span></div>
            <div class="tunnel-step"><span class="tunnel-num"><i class="fa-solid fa-images"></i></span><strong>Images ready</strong><span>Soft copies unlock in the portal.</span></div>
          </div>
        </div>

        <div class="pkg-page mt-5">
          <div class="pkg-board">'.$sections.'</div>
          <aside class="pkg-editor">
            <div class="pkg-editor-head"><h2>'.$heading.'</h2>'.$cancel.'</div>
            '.$form.'
          </aside>
        </div>';

        $this->render('Packages', $this->adminShell('Packages', $body), ['portal' => 'admin']);
    }

    private function packageFormHtml(?array $p = null): string
    {
        $meta = $this->packageCategoryMeta();
        $id = (int)($p['id'] ?? 0);
        $cat = (string)($p['category'] ?? 'wedding');
        $opts = '';
        foreach ($meta as $slug => $info) {
            $sel = $slug === $cat ? ' selected' : '';
            $opts .= '<option value="'.htmlspecialchars($slug).'"'.$sel.'>'.htmlspecialchars($info['label']).'</option>';
        }
        $btn = $id ? 'Save changes' : 'Add package';
        $name = htmlspecialchars((string)($p['name'] ?? ''));
        $price = htmlspecialchars(isset($p['price']) ? number_format((float)$p['price'], 2, '.', '') : '');
        $days = htmlspecialchars((string)($p['turnaround_days'] ?? '14'));
        $desc = htmlspecialchars((string)($p['description'] ?? ''));
        $deliv = htmlspecialchars((string)($p['deliverables'] ?? ''));
        $cover = $p ? '<div class="span-2"><div class="pkg-cover-preview"><img src="'.htmlspecialchars($this->packageCoverUrl($p)).'" alt=""></div></div>' : '';
        return '<form method="post" enctype="multipart/form-data" action="'.$this->url('/dashboard/package-save').'" class="two">'.$this->csrfField().
            ($id ? '<input type="hidden" name="id" value="'.$id.'">' : '').
            '<div class="span-2"><label>Package name</label><input name="name" required value="'.$name.'" placeholder="e.g. Glow"></div>'.
            '<div><label>Category</label><select name="category">'.$opts.'</select></div>'.
            '<div><label>Price (GHS)</label><input type="number" step="0.01" name="price" value="'.$price.'" placeholder="e.g. 200.00"></div>'.
            '<div><label>Turnaround days</label><input type="number" name="turnaround_days" value="'.$days.'" placeholder="e.g. 14"></div>'.
            '<div class="span-2"><label>Cover picture</label><input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp"></div>'.
            $cover.
            '<div class="span-2"><label>Short description</label><textarea name="description" placeholder="What this package covers" rows="3">'.$desc.'</textarea></div>'.
            '<div class="span-2"><label>Deliverables (one per line)</label><textarea name="deliverables" placeholder="One deliverable per line" rows="5">'.$deliv.'</textarea></div>'.
            '<div class="span-2"><button class="pkg-save" type="submit"><i class="fa-solid fa-floppy-disk mr-2"></i>'.$btn.'</button></div></form>';
    }

    private function savePackage(): void
    {
        $this->requireRole('admin');
        $name = trim($_POST['name'] ?? '');
        if (!$name) throw new RuntimeException('Package name is required.');
        $id = (int)($_POST['id'] ?? 0);
        $category = in_array($_POST['category'] ?? '', ['wedding','baby','studio'], true) ? (string)$_POST['category'] : 'wedding';
        $deposit = $category === 'wedding' ? $this->weddingBookingPercent() : 0.0;
        $price = (float)($_POST['price'] ?? 0);
        $days = (int)($_POST['turnaround_days'] ?? 14);
        $desc = trim($_POST['description'] ?? '');
        $deliv = trim($_POST['deliverables'] ?? '');
        $cover = $this->storePackageCover($_FILES['cover_image'] ?? null);

        if ($id > 0) {
            if ($cover) {
                $this->db->prepare("UPDATE packages SET name=?, category=?, description=?, price=?, deposit_percent=?, turnaround_days=?, deliverables=?, cover_image=? WHERE id=?")
                    ->execute([$name, $category, $desc, $price, $deposit, $days, $deliv, $cover, $id]);
            } else {
                $this->db->prepare("UPDATE packages SET name=?, category=?, description=?, price=?, deposit_percent=?, turnaround_days=?, deliverables=? WHERE id=?")
                    ->execute([$name, $category, $desc, $price, $deposit, $days, $deliv, $id]);
            }
            $this->flash('success', 'Package updated.');
            $this->redirect('/dashboard/packages?edit='.$id);
        }
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')).'-'.substr(bin2hex(random_bytes(2)), 0, 3);
        if (!$cover) {
            $cover = ['wedding'=>'cover-wedding.jpg','baby'=>'cover-baby.jpg','studio'=>'cover-studio.jpg'][$category] ?? 'cover-wedding.jpg';
        }
        $this->db->prepare("INSERT INTO packages (name,slug,category,description,price,deposit_percent,turnaround_days,deliverables,cover_image,active,created_at) VALUES (?,?,?,?,?,?,?,?,?,1,?)")
            ->execute([$name, $slug, $category, $desc, $price, $deposit, $days, $deliv, $cover, $this->now()]);
        $this->flash('success', 'Package added.');
        $this->redirect('/dashboard/packages');
    }

    private function storePackageCover(?array $file): ?string
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Cover upload failed.');
            return null;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($map[$mime])) {
            $this->flash('error', 'Use JPG, PNG or WebP for package covers.');
            return null;
        }
        $dir = __DIR__.'/../assets/packages';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = 'pkg-'.bin2hex(random_bytes(6)).'.'.$map[$mime];
        $dest = $dir.'/'.$name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->flash('error', 'Could not save cover image.');
            return null;
        }
        try {
            $img = @imagecreatefromstring((string)file_get_contents($dest));
            if ($img) {
                $w = imagesx($img); $h = imagesy($img);
                $targetW = 1200; $targetH = 800;
                $srcRatio = $w / max(1,$h); $dstRatio = $targetW / $targetH;
                if ($srcRatio > $dstRatio) {
                    $nh = $h; $nw = (int)($h * $dstRatio); $sx = (int)(($w - $nw) / 2); $sy = 0;
                } else {
                    $nw = $w; $nh = (int)($w / $dstRatio); $sx = 0; $sy = (int)(($h - $nh) / 2);
                }
                $canvas = imagecreatetruecolor($targetW, $targetH);
                imagecopyresampled($canvas, $img, 0, 0, $sx, $sy, $targetW, $targetH, $nw, $nh);
                if ($map[$mime] === 'png') imagepng($canvas, $dest, 6);
                elseif ($map[$mime] === 'webp' && function_exists('imagewebp')) imagewebp($canvas, $dest, 86);
                else imagejpeg($canvas, $dest, 88);
                imagedestroy($canvas); imagedestroy($img);
            }
        } catch (Throwable $e) {
            // keep original upload
        }
        return $name;
    }

    private function deletePackage(): void
    {
        $this->requireRole('admin');
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("SELECT active FROM packages WHERE id=?");
        $stmt->execute([$id]);
        $active = (int)($stmt->fetchColumn() ?: 0);
        $this->db->prepare("UPDATE packages SET active=? WHERE id=?")->execute([$active ? 0 : 1, $id]);
        $this->flash('success', $active ? 'Package deactivated.' : 'Package reactivated.');
        $this->redirect('/dashboard/packages');
    }

    private function adminSettings(): void
    {
        $this->requireRole('admin');
        $bookingPct = (string)(int)$this->weddingBookingPercent();
        $balancePct = (string)max(0, 100 - (int)$bookingPct);
        $provider = $this->smsProvider();
        $arkConfigured = trim($this->cfg('sms_arkesel_api_key', '')) !== '';
        $moolreConfigured = trim($this->cfg('sms_moolre_vas_key', '')) !== '';
        $activeConfigured = $provider === 'log' ? true : ($provider === 'arkesel' ? $arkConfigured : $moolreConfigured);
        $activeLabel = $provider === 'log' ? 'Dev log mode' : ucfirst($provider);
        $activeState = $provider === 'log'
            ? '<span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-700">No key needed</span>'
            : ($activeConfigured
                ? '<span class="rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700">Configured</span>'
                : '<span class="rounded-full bg-amber-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-amber-700">Key needed</span>');
        $body = '<form method="post" action="'.$this->url('/dashboard/settings').'" class="max-w-5xl space-y-5">'.$this->csrfField().'
        <div class="rounded-[2rem] border border-stone-200 bg-white p-5 sm:p-6">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-400">Settings</p>
              <h1 class="mt-2 text-2xl font-black text-stone-950 sm:text-3xl">Keep everything simple</h1>
              <p class="mt-2 max-w-2xl text-sm leading-6 text-stone-500">Use the tabs below to update your brand, payments, homepage, contract and SMS settings without a long crowded page.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:min-w-[26rem]">
              <div class="rounded-2xl bg-stone-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.16em] text-stone-400">Active SMS provider</p><p class="mt-2 text-sm font-black text-stone-950">'.htmlspecialchars($activeLabel).'</p><div class="mt-2">'.$activeState.'</div></div>
              <div class="rounded-2xl bg-stone-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.16em] text-stone-400">Arkesel key</p><p class="mt-2">'.($arkConfigured ? '<span class="rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700">Configured</span>' : '<span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-600">Not set</span>').'</p></div>
              <div class="rounded-2xl bg-stone-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.16em] text-stone-400">Moolre key</p><p class="mt-2">'.($moolreConfigured ? '<span class="rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700">Configured</span>' : '<span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-600">Not set</span>').'</p></div>
            </div>
          </div>
        </div>

        <div class="sticky top-[4.6rem] z-20 -mx-1 overflow-x-auto px-1">
          <div class="inline-flex min-w-full gap-2 rounded-full border border-stone-200 bg-white/95 p-1 backdrop-blur settings-tabs">
            <button type="button" data-settings-tab="general" class="settings-tab-btn rounded-full bg-stone-950 px-4 py-2 text-sm font-bold text-white">General</button>
            <button type="button" data-settings-tab="homepage" class="settings-tab-btn rounded-full px-4 py-2 text-sm font-bold text-stone-600">Homepage</button>
            <button type="button" data-settings-tab="packages" class="settings-tab-btn rounded-full px-4 py-2 text-sm font-bold text-stone-600">Packages</button>
            <button type="button" data-settings-tab="contract" class="settings-tab-btn rounded-full px-4 py-2 text-sm font-bold text-stone-600">Contract</button>
            <button type="button" data-settings-tab="sms" class="settings-tab-btn rounded-full px-4 py-2 text-sm font-bold text-stone-600">SMS &amp; OTP</button>
          </div>
        </div>

        <div data-settings-panel="general" class="settings-panel space-y-5">
          <section class="rounded-3xl border border-stone-200 bg-white p-5 space-y-4">
            <div><h2 class="font-black">Studio identity</h2><p class="mt-1 text-sm text-stone-500">Brand name shown in the header, portals and messages.</p></div>
            <div class="grid sm:grid-cols-2 gap-4">'.$this->input('app_name','App / brand name','text',$this->cfg('app_name','iBuk.online'),'Studio brand name').$this->input('photographer_name','Photographer / studio name','text',$this->cfg('photographer_name',''),'Photographer name').'</div>
          </section>

          <section class="rounded-3xl border border-stone-200 bg-white p-5 space-y-4">
            <div><h2 class="font-black">Payments &amp; WhatsApp</h2><p class="mt-1 text-sm text-stone-500">Shown on MoMo instructions and the chat button. The WhatsApp number itself stays hidden on the public site.</p></div>
            <div class="grid sm:grid-cols-2 gap-4">'.$this->input('momo_network','MoMo network','text',$this->cfg('momo_network','MTN'),'MTN').$this->input('momo_number','MoMo number','tel',$this->cfg('momo_number',''),'e.g. 0541069241').$this->input('momo_account_name','MoMo account name','text',$this->cfg('momo_account_name',''),'Account name on MoMo').$this->input('whatsapp_number','WhatsApp number','tel',$this->cfg('whatsapp_number','0541069241'),'e.g. 0541069241').'</div>
          </section>
        </div>

        <div data-settings-panel="homepage" class="settings-panel hidden space-y-5">
          <section class="rounded-3xl border border-stone-200 bg-white p-5 space-y-4">
            <div><h2 class="font-black">Homepage copy</h2><p class="mt-1 text-sm text-stone-500">Text on the main landing page. Slides are managed separately.</p></div>
            '.$this->input('home_headline','Headline','text',$this->cfg('home_headline','Beauty, held still.'),'Main homepage headline').'
            '.$this->input('home_title','Supporting title','text',$this->cfg('home_title','Weddings, portraits, and days worth keeping.'),'Short supporting line').'
            '.$this->textarea('home_lead','Lead paragraph','Short paragraph under the title',$this->cfg('home_lead','Book a session in minutes. Pay with MoMo. Follow every step in one quiet place.'),3,'').'
            '.$this->input('home_cta','Button label','text',$this->cfg('home_cta','Explore packages'),'Homepage button text').'
            <p class="text-xs text-stone-500"><a class="font-bold text-stone-800" href="'.$this->url('/dashboard/slides').'">Manage homepage slides →</a></p>
          </section>
        </div>

        <div data-settings-panel="packages" class="settings-panel hidden space-y-5">
          <section class="rounded-3xl border border-stone-200 bg-white p-5 space-y-4">
            <div><h2 class="font-black">Package categories</h2><p class="mt-1 text-sm text-stone-500">Labels and blurbs used on the package pages.</p></div>
            <div class="grid sm:grid-cols-3 gap-4">
              '.$this->input('cat_wedding_label','Wedding label','text',$this->cfg('cat_wedding_label','Wedding & Engagement'),'Category title').'
              '.$this->input('cat_wedding_short','Wedding short','text',$this->cfg('cat_wedding_short','Wedding'),'Short label').'
              '.$this->textarea('cat_wedding_blurb','Wedding blurb','Short category description',$this->cfg('cat_wedding_blurb',''),2,'').'
              '.$this->input('cat_baby_label','Baby label','text',$this->cfg('cat_baby_label','Baby Dedication & Christening'),'Category title').'
              '.$this->input('cat_baby_short','Baby short','text',$this->cfg('cat_baby_short','Baby'),'Short label').'
              '.$this->textarea('cat_baby_blurb','Baby blurb','Short category description',$this->cfg('cat_baby_blurb',''),2,'').'
              '.$this->input('cat_studio_label','Studio label','text',$this->cfg('cat_studio_label','Studio Shoot'),'Category title').'
              '.$this->input('cat_studio_short','Studio short','text',$this->cfg('cat_studio_short','Studio'),'Short label').'
              '.$this->textarea('cat_studio_blurb','Studio blurb','Short category description',$this->cfg('cat_studio_blurb',''),2,'').'
            </div>
          </section>

          <section class="rounded-3xl border border-stone-200 bg-white p-5 space-y-4">
            <div><h2 class="font-black">Wedding payment schedule</h2><p class="mt-1 text-sm text-stone-500">Used as guidance inside the contract for wedding and engagement bookings.</p></div>
            <div class="grid sm:grid-cols-2 gap-4">'.$this->input('wedding_booking_percent','Booking payment %','number',$bookingPct,'e.g. 80').$this->input('wedding_balance_percent','Balance % (auto-filled hint)','number',$balancePct,'e.g. 20').'</div>
            <p class="text-xs text-stone-500">Balance % is informational. The site uses the booking % and treats the rest as balance.</p>
          </section>
        </div>

        <div data-settings-panel="contract" class="settings-panel hidden space-y-5">
          <section class="rounded-3xl border border-stone-200 bg-white p-5 space-y-4">
            <div><h2 class="font-black">Contracts &amp; client terms</h2><p class="mt-1 text-sm text-stone-500">Wedding bookings use the Ghana wedding and traditional engagement agreement. Other shoots use the general service agreement.</p></div>
            '.$this->textarea('contract_text','Wedding & traditional engagement agreement','Main contract for weddings and engagements',$this->cfg('contract_text',''),18,'').'
            '.$this->textarea('general_contract_text','General photo / video agreement','Contract for studio, baby, birthday and other non-wedding shoots',$this->cfg('general_contract_text',$this->defaultGeneralContractText()),12,'').'
            '.$this->textarea('cheat_sheet_text','Optional session notes','Optional extra prep notes for your own internal use or future expansion',$this->cfg('cheat_sheet_text',''),6,'').'
            '.$this->textarea('studio_note','Client portal note','Note shown on the client dashboard',$this->cfg('studio_note',''),3,'').'
          </section>
        </div>

        <div data-settings-panel="sms" class="settings-panel hidden space-y-5">
          <section class="rounded-3xl border border-stone-200 bg-white p-5 space-y-4">
            <div><h2 class="font-black">SMS &amp; OTP</h2><p class="mt-1 text-sm text-stone-500">Choose the live provider, keep keys hidden, and only replace them when you type a new value.</p></div>
            <div>
              <label class="mb-1.5 block text-sm font-semibold text-stone-700">SMS provider</label>
              <select name="sms_provider" class="w-full rounded-xl border border-stone-200 bg-white px-4 py-3 text-sm">
                <option value="log"'.($provider==='log'?' selected':'').'>Log only (dev)</option>
                <option value="arkesel"'.($provider==='arkesel'?' selected':'').'>Arkesel</option>
                <option value="moolre"'.($provider==='moolre'?' selected':'').'>Moolre</option>
              </select>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
              <div class="rounded-2xl border border-stone-200 bg-stone-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.16em] text-stone-400">Arkesel status</p><div class="mt-2">'.($arkConfigured ? '<span class="rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700">Configured</span>' : '<span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-600">Not set</span>').'</div></div>
              <div class="rounded-2xl border border-stone-200 bg-stone-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.16em] text-stone-400">Moolre status</p><div class="mt-2">'.($moolreConfigured ? '<span class="rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700">Configured</span>' : '<span class="rounded-full bg-stone-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-stone-600">Not set</span>').'</div></div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
              '.$this->input('sms_sender','Sender ID','text',$this->cfg('sms_sender','iBuk'),'Approved sender ID').'
              '.$this->input('sms_unit_cost','Unit cost (GHS per SMS)','number',$this->cfg('sms_unit_cost','0.04'),'e.g. 0.04').'
            </div>
            '.$this->secretInput('sms_arkesel_api_key','Arkesel API key','From Arkesel dashboard', $arkConfigured, $provider === 'arkesel').'
            '.$this->secretInput('sms_moolre_vas_key','Moolre VAS / API key','X-API-VASKEY from Moolre', $moolreConfigured, $provider === 'moolre').'
            '.$this->input('otp_sms_template','OTP message template','text',$this->cfg('otp_sms_template','Your {app} code is {otp}. It expires in 10 minutes.'),'Your {app} code is {otp}…').'
            <p class="text-xs text-stone-500">If a key is already configured, it stays saved when you leave the field blank. Enter a new value only when you want to replace it.</p>
          </section>
        </div>

        <div class="sticky bottom-3 z-20 pt-2">
          <div class="rounded-2xl border border-stone-200 bg-white/95 p-3 shadow-sm backdrop-blur">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <p class="text-sm text-stone-500">Save once to apply changes across the dashboard, booking pages, and OTP flow.</p>
              <button class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-bold text-white">Save all settings</button>
            </div>
          </div>
        </div>
        </form>
        <script>
        (() => {
          const buttons = Array.from(document.querySelectorAll("[data-settings-tab]"));
          const panels = Array.from(document.querySelectorAll("[data-settings-panel]"));
          if (!buttons.length || !panels.length) return;
          const activate = (name) => {
            buttons.forEach((btn) => {
              const active = btn.dataset.settingsTab === name;
              btn.classList.toggle("bg-stone-950", active);
              btn.classList.toggle("text-white", active);
              btn.classList.toggle("text-stone-600", !active);
            });
            panels.forEach((panel) => {
              panel.classList.toggle("hidden", panel.dataset.settingsPanel !== name);
            });
            try {
              localStorage.setItem("ibuk-settings-tab", name);
            } catch (e) {}
          };
          buttons.forEach((btn) => btn.addEventListener("click", () => activate(btn.dataset.settingsTab || "general")));
          let current = "general";
          try {
            current = localStorage.getItem("ibuk-settings-tab") || "general";
          } catch (e) {}
          if (!panels.some((panel) => panel.dataset.settingsPanel === current)) current = "general";
          activate(current);
        })();
        </script>';
        $this->render('Settings', $this->adminShell('Settings', $body), ['portal' => 'admin']);
    }

    private function saveSettings(): void
    {
        $this->requireRole('admin');
        $keys = [
            'app_name','photographer_name','momo_network','momo_number','momo_account_name','whatsapp_number',
            'home_headline','home_title','home_lead','home_cta',
            'cat_wedding_label','cat_wedding_short','cat_wedding_blurb',
            'cat_baby_label','cat_baby_short','cat_baby_blurb',
            'cat_studio_label','cat_studio_short','cat_studio_blurb',
            'wedding_booking_percent','wedding_balance_percent',
            'contract_text','general_contract_text','cheat_sheet_text','studio_note',
            'sms_provider','sms_sender','sms_arkesel_api_key','sms_moolre_vas_key','sms_unit_cost','otp_sms_template',
        ];
        $stmt = $this->db->prepare("INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
        foreach ($keys as $key) {
            $val = trim((string)($_POST[$key] ?? ''));
            if ($key === 'wedding_booking_percent') {
                $val = (string)max(1, min(100, (int)$val ?: 80));
            }
            if ($key === 'wedding_balance_percent') {
                $booking = max(1, min(100, (int)($_POST['wedding_booking_percent'] ?? 80) ?: 80));
                $val = (string)max(0, 100 - $booking);
            }
            if ($key === 'sms_provider') {
                $val = in_array($val, ['log', 'arkesel', 'moolre'], true) ? $val : 'log';
            }
            if ($key === 'sms_unit_cost') {
                $val = (string)max(0, (float)$val);
            }
            if (in_array($key, ['sms_arkesel_api_key', 'sms_moolre_vas_key'], true) && $val === '') {
                $val = $this->cfg($key, '');
            }
            $stmt->execute([$key, $val]);
        }
        // Keep wedding package deposit_percent aligned with schedule setting.
        $pct = $this->weddingBookingPercent();
        $this->db->prepare("UPDATE packages SET deposit_percent=? WHERE category='wedding'")->execute([$pct]);
        $this->db->exec("UPDATE packages SET deposit_percent=0 WHERE category IN ('baby','studio')");
        $this->flash('success', 'Settings saved. Changes apply across the site.');
        $this->redirect('/dashboard/settings');
    }

    private function adminCoupons(): void
    {
        $this->requireRole('admin');
        $packages = $this->db->query("SELECT id,name,category,price FROM packages ORDER BY CASE category WHEN 'wedding' THEN 1 WHEN 'baby' THEN 2 WHEN 'studio' THEN 3 ELSE 4 END, price ASC, id ASC")->fetchAll();
        $packageOptions = '<option value="0">All packages</option>';
        foreach ($packages as $pkg) {
            $packageOptions .= '<option value="'.(int)$pkg['id'].'">'.htmlspecialchars($pkg['name'].' · '.ucfirst((string)$pkg['category']).' · '.$this->money((float)$pkg['price'])).'</option>';
        }
        $rows='';
        foreach($this->db->query("SELECT c.*,p.name package_name,p.category package_category FROM coupons c LEFT JOIN packages p ON p.id=c.package_id ORDER BY c.id DESC")->fetchAll() as $c){
            $value=$c['type']==='percent' ? rtrim(rtrim(number_format((float)$c['value'],2), '0'),'.').'%' : $this->money((float)$c['value']);
            $scope = !empty($c['package_id']) && !empty($c['package_name'])
                ? 'For '.htmlspecialchars($c['package_name']).' · '.htmlspecialchars(ucfirst((string)($c['package_category'] ?? 'package')))
                : 'All packages';
            $rows.='<div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex flex-wrap justify-between gap-3"><div><p class="font-black">'.$c['code'].'</p><p class="mt-1 text-sm text-slate-500">'.$value.' off · '.$scope.'</p><p class="mt-1 text-xs text-slate-400">'.$c['uses'].' uses'.($c['max_uses']?' / '.$c['max_uses']:'').' · '.($c['expires_at'] ? 'Expires '.$c['expires_at'] : 'No expiry').'</p></div>'.$this->badge((int)$c['active']?'active':'inactive').'</div><form method="post" action="'.$this->url('/dashboard/coupon-toggle').'" class="mt-3">'.$this->csrfField().'<input type="hidden" name="id" value="'.$c['id'].'"><button class="text-xs font-bold text-slate-700">Toggle status</button></form></div>';
        }
        if(!$rows)$rows='<p class="text-sm text-slate-500">No coupons created.</p>';
        $form='<div class="rounded-3xl border border-stone-200 bg-stone-50 p-4"><p class="text-sm font-semibold text-stone-700">Create one code, choose a discount, then decide whether it works for all packages or only one package.</p></div><form method="post" action="'.$this->url('/dashboard/coupon-save').'" class="grid sm:grid-cols-2 gap-4">'.$this->csrfField().
            $this->input('code','Coupon code','text','','e.g. LOVE10').
            '<div><label class="text-sm font-bold">Discount type</label><select name="type" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"><option value="percent">Percentage</option><option value="fixed">Fixed amount</option></select></div>'.
            $this->input('value','Discount value','number','','e.g. 10').
            '<div><label class="text-sm font-bold">Apply to package</label><select name="package_id" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm">'.$packageOptions.'</select></div>'.
            $this->input('max_uses','Maximum uses (0 = unlimited)','number','0','0 for unlimited').
            $this->input('expires_at','Expiry date','date','','Optional end date').
            '<div class="sm:col-span-2"><button type="submit" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Create coupon</button></div></form>';
        $this->render('Coupons',$this->adminShell('Coupons','<div class="grid xl:grid-cols-[.95fr_1.05fr] gap-5"><div class="space-y-3">'.$rows.'</div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h2 class="font-black">New coupon</h2><p class="mt-1 text-sm text-slate-500">Keep coupons simple: code, discount, package scope, and optional expiry.</p><div class="mt-4">'.$form.'</div></div></div>'),['portal'=>'admin']);
    }

    private function saveCoupon(): void
    {
        $this->requireRole('admin');
        $codeInput = (string)($_POST['code'] ?? ($_POST['coupon_code'] ?? ''));
        $type = ($_POST['type']??'percent')==='fixed'?'fixed':'percent';
        $value = max(0, (float)($_POST['value']??0));
        $packageId = (int)($_POST['package_id'] ?? 0);
        $packageScope = null;
        if ($packageId > 0) {
            $check = $this->db->prepare("SELECT id FROM packages WHERE id=? LIMIT 1");
            $check->execute([$packageId]);
            $packageScope = $check->fetchColumn() ? $packageId : null;
        }
        $code = strtoupper(trim($codeInput));
        $autoGenerated = false;
        if(!$code) {
            $code = $this->generateCouponCode($packageScope);
            $autoGenerated = true;
        }
        if ($value <= 0) {
            $this->flash('error','Enter a discount value greater than zero.');
            $this->redirect('/dashboard/coupons');
        }
        try{
            $this->db->prepare("INSERT INTO coupons (code,package_id,type,value,max_uses,uses,expires_at,active,created_at) VALUES (?,?,?,?,?,0,?,1,?)")->execute([$code,$packageScope,$type,$value,(int)($_POST['max_uses']??0),trim($_POST['expires_at']??'')?:null,$this->now()]);
        }catch(PDOException $e){$this->flash('error','Coupon code already exists.');$this->redirect('/dashboard/coupons');}
        $this->flash('success',$autoGenerated ? 'Coupon created with generated code '.$code.'.' : 'Coupon created.');
        $this->redirect('/dashboard/coupons');
    }

    private function generateCouponCode(?int $packageId = null): string
    {
        $prefix = 'SAVE';
        if ($packageId) {
            $stmt = $this->db->prepare("SELECT name FROM packages WHERE id=? LIMIT 1");
            $stmt->execute([$packageId]);
            $name = strtoupper((string)($stmt->fetchColumn() ?: ''));
            $letters = preg_replace('/[^A-Z0-9]+/', '', $name) ?: '';
            if ($letters !== '') {
                $prefix = substr($letters, 0, min(6, strlen($letters)));
            }
        }
        for ($i = 0; $i < 10; $i++) {
            $code = substr($prefix, 0, 6).substr(strtoupper(bin2hex(random_bytes(3))), 0, 4);
            $check = $this->db->prepare("SELECT 1 FROM coupons WHERE code=? LIMIT 1");
            $check->execute([$code]);
            if (!$check->fetchColumn()) {
                return $code;
            }
        }
        return 'SAVE'.substr(strtoupper(bin2hex(random_bytes(4))), 0, 6);
    }

    private function toggleCoupon(): void
    {
        $this->requireRole('admin');
        $this->db->prepare("UPDATE coupons SET active=CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?")->execute([(int)($_POST['id']??0)]);
        $this->redirect('/dashboard/coupons');
    }

    private function adminClients(): void
    {
        $this->requireRole('admin');
        $stmt=$this->db->query("SELECT u.*,COUNT(b.id) bookings,COALESCE(SUM(b.total),0) booked_value FROM users u LEFT JOIN bookings b ON b.user_id=u.id WHERE u.role='client' GROUP BY u.id ORDER BY u.id DESC");
        $rows='';
        foreach($stmt->fetchAll() as $u)$rows.='<div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex justify-between gap-3"><div><p class="font-black">'.htmlspecialchars($u['first_name'].' '.$u['last_name']).'</p><p class="mt-1 text-xs text-slate-500">'.htmlspecialchars($u['phone']).' · '.htmlspecialchars($u['email']??'No email').'</p></div><div class="text-right"><p class="font-black">'.$u['bookings'].' bookings</p><p class="text-xs text-slate-500">'.$this->money((float)$u['booked_value']).' booked</p></div></div></div>';
        if(!$rows)$rows=$this->emptyState('No clients','Registered customers will appear here.');
        $this->render('Clients',$this->adminShell('Clients','<div class="space-y-3">'.$rows.'</div>'),['portal'=>'admin']);
    }

    private function adminReports(): void
    {
        $this->requireRole('admin');
        $verified=(float)$this->db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='verified'")->fetchColumn();
        $pending=(float)$this->db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'")->fetchColumn();
        $outstanding=(float)$this->db->query("SELECT COALESCE(SUM(total),0) FROM bookings")->fetchColumn()-$verified;
        $month=$this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='verified' AND substr(verified_at,1,7)=?");
        $month->execute([date('Y-m')]);$monthTotal=(float)$month->fetchColumn();
        $stmt=$this->db->query("SELECT substr(verified_at,1,7) month,SUM(amount) total FROM payments WHERE status='verified' GROUP BY substr(verified_at,1,7) ORDER BY month DESC LIMIT 12");
        $rows='';
        foreach($stmt->fetchAll() as $r)$rows.='<div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4"><span class="text-sm font-bold">'.$r['month'].'</span><span class="font-black">'.$this->money((float)$r['total']).'</span></div>';
        if(!$rows)$rows='<p class="text-sm text-slate-500">No verified revenue yet.</p>';
        $body='<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">'.$this->stat('All verified',$this->money($verified)).$this->stat('This month',$this->money($monthTotal)).$this->stat('Pending review',$this->money($pending)).$this->stat('Outstanding',$this->money(max(0,$outstanding))).'</div><div class="mt-6 rounded-3xl border border-slate-200 bg-white p-5"><h2 class="font-black">Monthly money received</h2><div class="mt-4 space-y-2">'.$rows.'</div></div>';
        $this->render('Reports',$this->adminShell('Reports & money',$body),['portal'=>'admin']);
    }

    private function adminSlides(): void
    {
        $this->requireRole('admin');
        $rows = '';
        foreach ($this->homeSlidesAll() as $s) {
            $src = htmlspecialchars($this->url('/assets/slides/'.$s['stored_name']));
            $rows .= '<div class="rounded-2xl border border-stone-200 bg-white p-3 flex gap-3 items-center">
              <img src="'.$src.'" alt="" class="h-20 w-16 rounded-xl object-cover object-top bg-stone-100">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-bold truncate">'.htmlspecialchars($s['original_name'] ?: $s['stored_name']).'</p>
                <p class="text-xs text-stone-500 mt-0.5">Order '.$s['sort_order'].((int)$s['active'] ? '' : ' · hidden').'</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <form method="post" action="'.$this->url('/dashboard/slide-move').'">'.$this->csrfField().'<input type="hidden" name="id" value="'.$s['id'].'"><input type="hidden" name="dir" value="up"><button class="rounded-lg border border-stone-200 px-2.5 py-1 text-[11px] font-bold">Up</button></form>
                  <form method="post" action="'.$this->url('/dashboard/slide-move').'">'.$this->csrfField().'<input type="hidden" name="id" value="'.$s['id'].'"><input type="hidden" name="dir" value="down"><button class="rounded-lg border border-stone-200 px-2.5 py-1 text-[11px] font-bold">Down</button></form>
                  <form method="post" action="'.$this->url('/dashboard/slide-delete').'" onsubmit="return confirm(\'Remove this slide?\')">'.$this->csrfField().'<input type="hidden" name="id" value="'.$s['id'].'"><button class="rounded-lg bg-red-50 px-2.5 py-1 text-[11px] font-bold text-red-700">Delete</button></form>
                </div>
              </div>
            </div>';
        }
        if ($rows === '') $rows = '<p class="text-sm text-stone-500">No homepage slides yet. Upload a photo to start the slideshow.</p>';
        $body = '<div class="grid lg:grid-cols-[1.1fr_.9fr] gap-4">
          <div class="space-y-3">'.$rows.'</div>
          <div class="rounded-2xl border border-stone-200 bg-white p-4">
            <h2 class="font-bold text-stone-950">Add slide</h2>
            <p class="mt-1 text-xs text-stone-500 leading-5">Upload JPG or PNG portraits. These rotate on the homepage for mobile and desktop.</p>
            <form method="post" enctype="multipart/form-data" action="'.$this->url('/dashboard/slide-upload').'" class="mt-4 space-y-3">
              '.$this->csrfField().'
              <input required type="file" name="slide" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm">
              <button class="rounded-xl bg-stone-950 px-4 py-2.5 text-sm font-bold text-white">Upload slide</button>
            </form>
            <a href="'.$this->url('/').'" class="mt-4 inline-flex text-xs font-bold text-stone-600">Preview homepage →</a>
          </div>
        </div>';
        $this->render('Homepage slides', $this->adminShell('Homepage slides', $body), ['portal' => 'admin']);
    }

    private function homeSlidesAll(): array
    {
        return $this->db->query("SELECT * FROM home_slides ORDER BY sort_order ASC, id ASC")->fetchAll();
    }

    private function uploadHomeSlide(): void
    {
        $this->requireRole('admin');
        if (!isset($_FILES['slide']) || $_FILES['slide']['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Upload failed. Try another image.');
            $this->redirect('/dashboard/slides');
        }
        $f = $_FILES['slide'];
        if ($f['size'] > 12 * 1024 * 1024) {
            $this->flash('error', 'Image must be under 12 MB.');
            $this->redirect('/dashboard/slides');
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            $this->flash('error', 'Use JPG, PNG or WebP only.');
            $this->redirect('/dashboard/slides');
        }
        $dir = __DIR__.'/../assets/slides';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $stored = 'slide-'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dir.'/'.$stored)) {
            $this->flash('error', 'Could not save the image.');
            $this->redirect('/dashboard/slides');
        }
        $order = (int)$this->db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM home_slides")->fetchColumn();
        $this->db->prepare("INSERT INTO home_slides (stored_name,original_name,sort_order,active,created_at) VALUES (?,?,?,1,?)")
            ->execute([$stored, $f['name'], $order, $this->now()]);
        $this->flash('success', 'Slide added to the homepage.');
        $this->redirect('/dashboard/slides');
    }

    private function deleteHomeSlide(): void
    {
        $this->requireRole('admin');
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM home_slides WHERE id=?");
        $stmt->execute([$id]);
        $slide = $stmt->fetch();
        if ($slide) {
            $this->db->prepare("DELETE FROM home_slides WHERE id=?")->execute([$id]);
            $file = __DIR__.'/../assets/slides/'.basename($slide['stored_name']);
            if (is_file($file) && !in_array(basename($slide['stored_name']), ['couple.jpg','bridal-party.jpg','look-01.jpg','look-02.jpg','look-03.jpg','bridal-lilies.jpg','bridal-couture.jpg','studio-blue.jpg','bridal-garden.jpg','couple-embrace.jpg','couple-celebration.jpg','couple-venue.jpg'], true)) {
                @unlink($file);
            }
        }
        $this->flash('success', 'Slide removed.');
        $this->redirect('/dashboard/slides');
    }

    private function moveHomeSlide(): void
    {
        $this->requireRole('admin');
        $id = (int)($_POST['id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        $slides = $this->homeSlidesAll();
        $idx = null;
        foreach ($slides as $i => $s) {
            if ((int)$s['id'] === $id) { $idx = $i; break; }
        }
        if ($idx === null) {
            $this->redirect('/dashboard/slides');
        }
        $swap = $idx + $dir;
        if ($swap < 0 || $swap >= count($slides)) {
            $this->redirect('/dashboard/slides');
        }
        $a = $slides[$idx];
        $b = $slides[$swap];
        $this->db->prepare("UPDATE home_slides SET sort_order=? WHERE id=?")->execute([(int)$b['sort_order'], $a['id']]);
        $this->db->prepare("UPDATE home_slides SET sort_order=? WHERE id=?")->execute([(int)$a['sort_order'], $b['id']]);
        // normalize if orders collided
        if ((int)$a['sort_order'] === (int)$b['sort_order']) {
            foreach ($slides as $i => $s) {
                $this->db->prepare("UPDATE home_slides SET sort_order=? WHERE id=?")->execute([$i + 1, $s['id']]);
            }
            $slides = $this->homeSlidesAll();
            foreach ($slides as $i => $s) {
                if ((int)$s['id'] === $id) { $idx = $i; break; }
            }
            $swap = $idx + $dir;
            if ($swap >= 0 && $swap < count($slides)) {
                $a = $slides[$idx]; $b = $slides[$swap];
                $this->db->prepare("UPDATE home_slides SET sort_order=? WHERE id=?")->execute([$swap + 1, $a['id']]);
                $this->db->prepare("UPDATE home_slides SET sort_order=? WHERE id=?")->execute([$idx + 1, $b['id']]);
            }
        }
        $this->redirect('/dashboard/slides');
    }

    private function needsFullContract(array $booking): bool
    {
        unset($booking);
        return true;
    }

    private function defaultGeneralContractText(): string
    {
        return <<<'TEXT'
PHOTO / VIDEO SERVICE AGREEMENT

This agreement is between the Studio and the Client named in this booking.

1. Scope of coverage
The Studio will provide the photography services, videography services, or combined coverage selected in the booked package, subject to the event details confirmed in the portal.

2. Booking confirmation
A booking is treated as confirmed once the Client submits the booking, accepts this agreement, and the Studio verifies the required payment or agreed first installment.

3. Payments and balance
Package pricing, discounts, add-ons, and any prior-payment notes shown in the portal form part of this agreement. Partial payments may be accepted, but any outstanding balance must be cleared before final edited images, videos, albums, frames, or other deliverables are released, unless the Studio agrees otherwise in writing.

4. Rescheduling and delays
The Client should notify the Studio as early as possible about date, venue, or schedule changes. The Studio will make reasonable efforts to accommodate changes, but availability for a new date is not guaranteed.

5. Creative coverage and editing
The Client hires the Studio for its professional judgment, shooting style, and editing approach. The Studio will make reasonable efforts to capture key moments, but exact poses, people, or scenes cannot be guaranteed in every live event situation.

6. Delivery timeline
Estimated turnaround depends on the package selected, the scope of coverage, and production workload. The portal timeline and package turnaround guide the expected delivery window, but the Studio may reasonably extend delivery where necessary for editing quality, technical recovery, or circumstances beyond its control.

7. Copyright and usage
Copyright in all photographs, videos, raw files, and edited deliverables remains with the Studio unless otherwise agreed in writing. The Client receives personal-use rights for delivered files. Commercial, resale, or third-party promotional use requires the Studio's prior written consent.

8. Client cooperation
The Client is responsible for providing accurate event details, securing venue permissions where required, keeping to agreed timelines, and ensuring a safe working environment for the Studio team and equipment.

9. Cancellation and non-refund policy
Payments already verified by the Studio are non-refundable once the date has been reserved and work has been scheduled, except where the Studio cancels and cannot provide the agreed service or an acceptable alternative.

10. Limitation of liability
If equipment failure, illness, accident, force majeure, restricted venue access, or other events beyond the Studio's reasonable control prevent full performance, the Studio's liability is limited to amounts paid for the affected service portion, unless another remedy is agreed in writing.

11. Acceptance
By accepting this agreement in the portal, the Client confirms that the booking summary, package details, payment terms, and portal record are correct and agrees to be bound by this service agreement.
TEXT;
    }

    private function defaultWeddingContractText(): string
    {
        return <<<'TEXT'
WEDDING & TRADITIONAL ENGAGEMENT SERVICES AGREEMENT
Republic of Ghana

This Wedding and Traditional Engagement Services Agreement is made between the Client named in this booking and the Service Provider identified by the Studio settings and booking record.

1. Parties
The Client may include one or two principal clients for the wedding or engagement. Where both clients proceed under one booking, payment obligations and approved charges are treated as joint unless otherwise agreed in writing.

2. Purpose
The Client appoints the Service Provider to provide wedding, traditional engagement, photography, videography, coordination, planning, decoration, logistics, production, and related event services described in the booked package, approved add-ons, and written updates.

3. Event details
Traditional engagement, wedding, reception, venue, date, programme, and related details are those shown in the booking summary, package notes, portal record, and any later written update approved by the Parties.

4. Term
This Agreement begins once accepted and continues until the contracted services are completed, all outstanding amounts are paid, and agreed post-event deliverables are finished, unless terminated earlier under this Agreement.

5. Scope of services
Only the services included in the booked package, accepted add-ons, and later approved written changes form part of this Agreement. Services not expressly included are treated as additional services.

6. Traditional engagement and customary items
Where the Service Provider assists with a Ghanaian traditional engagement or customary ceremony, the families remain responsible for confirming the applicable customs, family lists, and required customary items. The Service Provider is not responsible for disputes concerning bridewealth, dowry, gifts, customary validity, or traditional obligations.

7. Budget and price
The package total, discounts, coupons, add-ons, prior-payment notes, and payment schedule displayed in the portal form part of the agreed financial record. Additional approved work, schedule extensions, material guest-count changes, venue changes, or third-party cost increases may result in extra charges.

8. Payment schedule
The booking payment, later instalments, and final balance are governed by the portal summary and any written update approved by the Parties. Unless otherwise agreed, the outstanding balance should be cleared before final delivery and, where required by the package, before the event date.

9. Method of payment
Payments may be made through approved channels such as mobile money, bank transfer, card, or another method accepted by the Service Provider. The Client should use the booking reference or payment reference supplied by the portal.

10. Booking fee / retainer
Any booking fee or reservation payment secures the date and allows planning or preparation to begin. Where identified as non-refundable, it may be retained to the extent permitted by law and to cover date reservation, administrative time, and non-recoverable commitments already made.

11. Additional services and change orders
Requests outside the agreed scope must be approved in writing. WhatsApp, email, or another written electronic channel may be used for minor approved changes. Major changes affecting date, venue, total price, or principal scope should be clearly recorded.

12. Guest count and programme changes
The Client must provide timely updates on guest count, programme flow, venues, and timing. Additional guests, venue restrictions, or programme delays may affect pricing, staffing, logistics, and delivery.

13. Vendor management
Where the Service Provider coordinates third-party vendors as part of the package, the Service Provider will manage them within the agreed scope. Vendors contracted directly by the Client remain responsible for their own services.

14. Venue, permits, and compliance
The Client remains responsible for disclosing venue rules, access conditions, and any known restrictions. The Parties must comply with applicable Ghanaian law, venue requirements, and local authority rules relating to noise, access, structures, sanitation, and public safety.

15. Weather, power, and outdoor risk
Outdoor events carry risks including rain, wind, flooding, dust, heat, transport disruption, and power interruption. Where the Service Provider recommends a reasonable backup or contingency plan and the Client declines it, the Service Provider is not responsible for losses that reasonably result from that decision.

16. Timeline and overtime
Delays caused by the Client, family members, officiants, guests, venues, traffic, or client-appointed vendors may affect programme timing and may result in overtime charges or reduced coverage opportunities.

17. Photography and videography
Where media coverage is included, the relevant hours, deliverables, turnaround expectations, drone use, and whether raw files are included are determined by the booked package and approved add-ons. The Service Provider will use reasonable skill and care but cannot guarantee capture of every moment, person, pose, or scene in a live event environment.

18. Copyright, usage, and marketing consent
Copyright in photographs, videos, raw files, edits, and creative materials remains with the Service Provider unless otherwise agreed in writing. On full payment, the Client receives normal personal-use rights for delivered work. Commercial use requires prior written consent. Marketing or portfolio use should follow the Client's selected privacy preference and any applicable law.

19. Privacy and confidentiality
Client names, phone numbers, addresses, payment records, event details, and related personal information should be handled with reasonable confidentiality and in line with applicable Ghanaian data-protection requirements.

20. Client responsibilities
The Client must provide accurate information, pay on time, disclose venue restrictions, communicate family and ceremony requirements, identify authorised decision-makers, inform the Service Provider of separate vendors, and avoid unlawful or unsafe instructions.

21. Service Provider responsibilities
The Service Provider shall perform the agreed services with reasonable skill, care, professionalism, and timely communication, while taking reasonable steps to coordinate included vendors and manage the event within the approved scope.

22. Cancellation by Client
Cancellation must be communicated in writing. The Service Provider may retain the booking fee and non-refundable third-party commitments already incurred, with any additional refund position depending on timing, work completed, supplier policies, and applicable law.

23. Rescheduling
Rescheduling requests must be made in writing and are subject to availability, venue constraints, vendor availability, revised pricing, and any additional costs reasonably caused by the change.

24. Cancellation by Service Provider
The Service Provider may terminate for material non-payment, serious abuse, safety threats, illegal instructions, denied access, or another material breach that makes performance unreasonable or unsafe. If the Service Provider cancels without Client fault and outside force majeure, payments for unperformed services should be refunded subject to committed third-party costs and applicable law.

25. Force majeure
Neither Party is treated as in breach to the extent performance is prevented by extraordinary events beyond reasonable control, including severe weather, public-health restrictions, civil unrest, venue closure, major utility failure, or similar events. The Parties should first try to reduce loss through rescheduling, substitutions, or a revised plan.

26. Liability
Each Party remains responsible for loss caused by its own breach, negligence, wilful misconduct, or unlawful act. To the extent permitted by law, the Service Provider is not liable for indirect or unforeseeable loss, and liability may be limited to amounts actually paid for the affected services except where law does not allow exclusion.

27. Electronic communication and signatures
The Parties may use email, WhatsApp, the client portal, or another written electronic channel for notices, approvals, and record-keeping where the communication can reasonably be identified and retained. Electronic acceptance in the portal forms part of the contractual record.

28. Governing law
This Agreement is governed by the laws of the Republic of Ghana.

29. Priority of records
If there is any conflict, priority is given to approved special conditions or written change orders first, then this Agreement, then the booking summary, package description, payment record, and other approved written communications.

30. Acceptance
By accepting this agreement in the portal, the Client confirms that the booking summary, package details, event information, payment terms, and related records are materially correct and agrees to be bound by this Agreement.
TEXT;
    }

    private function contractFormHtml(array $booking): string
    {
        $total = (float)$booking['total'];
        $bookingPct = $this->weddingBookingPercent();
        $balancePct = max(0, 100 - $bookingPct);
        $paid = $this->bookingPaid((int)$booking['id']);
        $balance = max(0, round($total - $paid, 2));
        $category = (string)($booking['package_category'] ?? $booking['category'] ?? '');
        $eventType = trim((string)($booking['event_type'] ?? '')) ?: 'Photo / video service';
        $coverage = $this->packageCoverageLabel($booking);
        $isWeddingContract = $category === 'wedding' || str_contains(strtolower($eventType), 'wedding') || str_contains(strtolower($eventType), 'engagement');
        $contractText = $this->setting($isWeddingContract ? 'contract_text' : 'general_contract_text');
        if ($contractText === '') {
            $contractText = $isWeddingContract ? $this->defaultWeddingContractText() : $this->defaultGeneralContractText();
        }
        $paymentNote = $isWeddingContract
            ? 'The remaining '.$balancePct.'% balance is due on the wedding day, immediately after the program, unless you agree otherwise with the studio in writing.'
            : 'Any remaining balance must be cleared before final delivery unless the studio agrees otherwise in writing.';
        return '<div class="rounded-3xl border border-slate-200 bg-white p-5">
          <h3 class="font-black">'.($isWeddingContract ? 'Wedding &amp; traditional engagement agreement' : 'Service agreement').'</h3>
          <p class="mt-1 text-sm text-stone-500">'.($isWeddingContract ? 'Standard Ghana wedding and traditional engagement terms adapted for your booked package.' : 'Standard contract for photography, videography, portraits, baby events, and other booked sessions.').'</p>
          <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded-2xl bg-stone-50 p-4"><p class="text-[10px] font-bold uppercase tracking-wider text-stone-400">Service type</p><p class="mt-1 text-sm font-black text-stone-950">'.htmlspecialchars($eventType).'</p></div>
            <div class="rounded-2xl bg-stone-50 p-4"><p class="text-[10px] font-bold uppercase tracking-wider text-stone-400">Coverage</p><p class="mt-1 text-sm font-black text-stone-950">'.htmlspecialchars($coverage).'</p></div>
            <div class="rounded-2xl bg-stone-50 p-4"><p class="text-[10px] font-bold uppercase tracking-wider text-stone-400">Package total</p><p class="mt-1 text-lg font-black">'.$this->money($total).'</p></div>
            <div class="rounded-2xl bg-stone-50 p-4"><p class="text-[10px] font-bold uppercase tracking-wider text-stone-400">Balance remaining</p><p class="mt-1 text-lg font-black">'.$this->money($balance).'</p></div>
          </div>
          <p class="mt-3 text-xs text-stone-500">'.htmlspecialchars($paymentNote).'</p>
          <div class="mt-3 rounded-2xl border border-sky-100 bg-sky-50 p-4 text-xs leading-6 text-sky-900">Editing, delivery updates, and the full post-event timeline will be communicated to you and can be tracked in your client portal.</div>
          <div class="mt-4 max-h-48 overflow-auto rounded-2xl bg-slate-50 p-4 text-sm leading-6 text-slate-700">'.nl2br(htmlspecialchars($contractText)).'</div>
          <form method="post" action="'.$this->url('/client/contract-accept').'" class="mt-4">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$booking['id'].'">
            <label class="flex items-start gap-3 text-sm"><input required type="checkbox" class="mt-1" name="agree" value="1"><span>I have read and accept this service agreement, including the booking, payment, delivery, and usage terms shown above.</span></label>
            <button class="mt-4 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Accept contract</button>
          </form>
        </div>';
    }

    private function contractSnapshotText(array $booking): string
    {
        $category = (string)($booking['package_category'] ?? $booking['category'] ?? '');
        $eventType = trim((string)($booking['event_type'] ?? ''));
        $isWeddingContract = $category === 'wedding' || str_contains(strtolower($eventType), 'wedding') || str_contains(strtolower($eventType), 'engagement');
        $base = trim((string)($booking['contract_text_snapshot'] ?? ''));
        if ($base === '') {
            $key = $isWeddingContract ? 'contract_text' : 'general_contract_text';
            $base = trim($this->setting($key));
        }
        if ($base === '') {
            $base = $isWeddingContract ? $this->defaultWeddingContractText() : $this->defaultGeneralContractText();
        }
        $bookingPct = $this->weddingBookingPercent();
        $balancePct = max(0, 100 - $bookingPct);
        $paymentNote = $isWeddingContract
            ? "PAYMENT NOTE\nThe remaining {$balancePct}% balance is due on the wedding day, immediately after the program, unless otherwise agreed in writing.\n"
            : "PAYMENT NOTE\nAny remaining balance must be cleared before final delivery unless otherwise agreed in writing.\n";
        $trackingNote = "TRACKING NOTE\nEditing, delivery updates, and the post-event timeline will be communicated to the client and can be tracked in the client portal.\n";
        $summary = [
            'BOOKING SUMMARY',
            'Booking code: '.(string)($booking['booking_code'] ?? ''),
            'Package: '.(string)($booking['package_name'] ?? ''),
            'Event type: '.($eventType !== '' ? $eventType : 'Photo / video service'),
            'Event timing: '.$this->bookingEventSummary($booking),
            'Total: '.$this->money((float)($booking['total'] ?? 0)),
            'Deposit / amount due now: '.$this->money((float)($booking['deposit_required'] ?? 0)),
            '',
        ];
        return trim(implode("\n", $summary).$paymentNote."\n".$trackingNote."\n".$base)."\n";
    }

    private function writeContractPdf(array $booking, string $snapshot, string $signer, string $acceptedAt): string
    {
        $dir = __DIR__.'/../storage/contracts';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $filename = 'contract-'.preg_replace('/[^A-Za-z0-9\-]+/', '-', (string)($booking['booking_code'] ?? 'booking')).'.pdf';
        $path = $dir.'/'.$filename;
        $body = $this->contractPdfText($booking, $snapshot, $signer, $acceptedAt);
        file_put_contents($path, $this->simpleTextPdf($body));
        return $filename;
    }

    private function contractPdfText(array $booking, string $snapshot, string $signer, string $acceptedAt): string
    {
        $lines = [
            strtoupper((string)($booking['package_name'] ?? 'SERVICE CONTRACT')),
            'Contract PDF copy',
            '',
            'Booking code: '.(string)($booking['booking_code'] ?? ''),
            'Client signer: '.$signer,
            'Accepted at: '.$acceptedAt,
            'Accepted IP: '.trim((string)($booking['contract_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))),
            'Event summary: '.$this->bookingEventSummary($booking),
            'Total: '.$this->money((float)($booking['total'] ?? 0)),
            'Deposit required: '.$this->money((float)($booking['deposit_required'] ?? 0)),
            '',
            trim($snapshot),
        ];
        return implode("\n", $lines);
    }

    private function simpleTextPdf(string $text): string
    {
        $rawLines = preg_split("/\\r\\n|\\n|\\r/", $text) ?: [];
        $wrapped = [];
        foreach ($rawLines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                $wrapped[] = '';
                continue;
            }
            foreach (explode("\n", wordwrap($line, 92, "\n", true)) as $part) {
                $wrapped[] = $part;
            }
        }
        $pages = [];
        $chunk = [];
        foreach ($wrapped as $line) {
            $chunk[] = $line;
            if (count($chunk) >= 42) {
                $pages[] = $chunk;
                $chunk = [];
            }
        }
        if ($chunk) $pages[] = $chunk;
        if (!$pages) $pages = [['Contract copy']];

        $objects = [];
        $pagesKids = [];
        $fontObject = 3;
        foreach ($pages as $i => $lines) {
            $content = "BT\n/F1 11 Tf\n14 TL\n50 790 Td\n";
            $first = true;
            foreach ($lines as $line) {
                $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                if ($first) {
                    $content .= "({$escaped}) Tj\n";
                    $first = false;
                } else {
                    $content .= "T*\n({$escaped}) Tj\n";
                }
            }
            $content .= "ET\n";
            $contentObject = 4 + ($i * 2);
            $pageObject = 5 + ($i * 2);
            $objects[$contentObject] = "<< /Length ".strlen($content)." >>\nstream\n".$content."endstream";
            $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontObject} 0 R >> >> /Contents {$contentObject} 0 R >>";
            $pagesKids[] = "{$pageObject} 0 R";
        }
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [".implode(' ', $pagesKids)."] /Count ".count($pages)." >>";
        $objects[$fontObject] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num." 0 obj\n".$obj."\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $off = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer << /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    private function cheatSheetFormHtml(array $booking): string
    {
        $sheet = $this->setting('cheat_sheet_text');
        if ($sheet === '') {
            $sheet = "SESSION CHEAT SHEET\n\n• Confirm your booking details and preferred time with the studio.\n• Pay via MTN MoMo using your booking reference — you can pay in parts.\n• Arrive on time with outfits/props ready for the shoot.\n• Soft copies and package items are released after payment is cleared.\n• Message the studio on WhatsApp if anything changes.";
        }
        return '<div class="rounded-3xl border border-slate-200 bg-white p-5">
          <h3 class="font-black">Session cheat sheet</h3>
          <p class="mt-1 text-sm text-stone-500">Quick guide for your shoot — no full contract needed.</p>
          <div class="mt-4 rounded-2xl bg-slate-50 p-4 text-sm leading-6 text-slate-700 whitespace-pre-line">'.htmlspecialchars($sheet).'</div>
          <form method="post" action="'.$this->url('/client/contract-accept').'" class="mt-4">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$booking['id'].'">
            <label class="flex items-start gap-3 text-sm"><input required type="checkbox" class="mt-1" name="agree" value="1"><span>I have read this cheat sheet and understand the session steps.</span></label>
            <button class="mt-4 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Acknowledge cheat sheet</button>
          </form>
        </div>';
    }

    private function paymentInstructions(array $b,float $amount,string $type): string
    {
        $ref=$b['booking_code'];
        $suggest = max(0, round($amount, 2));
        return '<div class="rounded-3xl border border-amber-200 bg-amber-50 p-5"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-wider text-amber-700">'.htmlspecialchars($this->cfg('momo_network','MTN')).' Mobile Money</p><h3 class="mt-1 font-black text-amber-950">Pay toward '.$this->money((float)$b['total']).'</h3><p class="mt-1 text-sm text-amber-900/80">Balance left: '.$this->money($suggest).' — you can send a partial amount.</p></div><span class="rounded-xl bg-white px-3 py-2 text-xs font-bold text-amber-900">Manual verification</span></div><div class="mt-4 grid sm:grid-cols-3 gap-3 text-sm">'.$this->mini('Number',$this->cfg('momo_number','')).$this->mini('Account',$this->cfg('momo_account_name','')).$this->mini('Reference',$ref).'</div><p class="mt-4 text-xs leading-5 text-amber-800">Use <strong>'.$ref.'</strong> as your payment reference. After sending, submit the MoMo transaction ID below. Partial payments are fine.</p><form method="post" action="'.$this->url('/client/payment-submit').'" class="mt-4 grid sm:grid-cols-2 gap-3">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$b['id'].'"><input type="hidden" name="payment_type" value="'.$type.'">'.$this->input('amount','Amount sent','number',number_format($suggest > 0 ? $suggest : (float)$b['total'],2,'.',''),'Amount you sent').$this->input('sender_number','MTN number used','tel',$this->user['phone'],'Active MoMo number').$this->input('momo_reference','MoMo transaction/reference ID','text','','Paste MoMo reference').'<div class="sm:col-span-2"><button class="rounded-xl bg-amber-900 px-4 py-2.5 text-sm font-bold text-white">Submit payment for verification</button></div></form></div>';
    }

    private function timelineHtml(int $bookingId, bool $admin = false): string
    {
        unset($admin);
        $stmt = $this->db->prepare("SELECT * FROM timeline WHERE booking_id=? ORDER BY sort_order,id");
        $stmt->execute([$bookingId]);
        $items = $stmt->fetchAll();
        if (!$items) return '<p class="text-sm text-slate-500">No timeline yet.</p>';

        $html = '<div class="journey">';
        $n = count($items);
        foreach ($items as $i => $t) {
            $done = $t['status'] === 'completed';
            $cls = $done ? 'journey-step is-done' : 'journey-step';
            $num = $i + 1;
            $html .= '<div class="'.$cls.'">'
                .'<div class="journey-rail"><span class="journey-node">'.($done ? '✓' : $num).'</span>'
                .($i < $n - 1 ? '<span class="journey-line"></span>' : '')
                .'</div>'
                .'<div class="journey-body">'
                .'<p class="journey-title">'.htmlspecialchars($t['title']).'</p>'
                .'<p class="journey-desc">'.htmlspecialchars((string)($t['description'] ?? '')).'</p>'
                .($t['due_date'] ? '<p class="journey-due">Target '.$t['due_date'].'</p>' : '')
                .'</div></div>';
        }
        $html .= '</div><style>
        .journey{display:flex;flex-direction:column;gap:0}
        .journey-step{display:grid;grid-template-columns:1.6rem 1fr;gap:.75rem;align-items:stretch}
        .journey-rail{display:flex;flex-direction:column;align-items:center}
        .journey-node{display:grid;place-items:center;width:1.45rem;height:1.45rem;border-radius:999px;background:#e7e5e4;color:#57534e;font-size:.65rem;font-weight:800;flex-shrink:0}
        .journey-step.is-done .journey-node{background:#166534;color:#fff}
        .journey-line{width:2px;flex:1;min-height:1.1rem;background:#e7e5e4;margin:.2rem 0}
        .journey-step.is-done .journey-line{background:#bbf7d0}
        .journey-body{padding-bottom:1.05rem}
        .journey-step:last-child .journey-body{padding-bottom:0}
        .journey-title{font-size:.86rem;font-weight:800;color:#1c1917;line-height:1.3}
        .journey-desc{margin-top:.25rem;font-size:.74rem;line-height:1.45;color:#78716c}
        .journey-due{margin-top:.3rem;font-size:.65rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#a8a29e}
        </style>';
        return $html;
    }

    private function bookingRow(array $b,bool $admin): string
    {
        $name=$admin && isset($b['first_name']) ? '<p class="text-xs text-slate-500">'.htmlspecialchars($b['first_name'].' '.$b['last_name']).'</p>' : '';
        $href=$admin?'/dashboard/booking?id='.$b['id']:'/client/booking?id='.$b['id'];
        return '<a href="'.$this->url($href).'" class="block rounded-3xl border border-slate-200 bg-white p-5 hover:border-slate-300"><div class="flex items-start justify-between gap-4"><div class="min-w-0"><p class="font-black truncate">'.htmlspecialchars($b['package_name']).'</p>'.$name.'<p class="mt-1 text-xs text-slate-500">'.$b['booking_code'].' · '.htmlspecialchars($this->bookingEventSummary($b)).'</p></div><div class="text-right shrink-0">'.$this->badge($b['status']).'<p class="mt-2 text-sm font-black">'.$this->money((float)$b['total']).'</p></div></div></a>';
    }

    private function validCoupon(string $code, int $packageId = 0): ?array
    {
        $stmt=$this->db->prepare("SELECT * FROM coupons WHERE code=? AND active=1 LIMIT 1");$stmt->execute([$code]);$c=$stmt->fetch();
        if(!$c)return null;
        $scope = (int)($c['package_id'] ?? 0);
        if ($scope > 0 && $scope !== $packageId) return null;
        if($c['expires_at'] && strtotime($c['expires_at'].' 23:59:59')<time())return null;
        if((int)$c['max_uses']>0 && (int)$c['uses']>=(int)$c['max_uses'])return null;
        return $c;
    }

    private function bookingById(int $id,?int $userId=null): ?array
    {
        $sql="SELECT b.*,p.name package_name,p.deposit_percent,p.turnaround_days,p.category package_category,p.cover_image FROM bookings b JOIN packages p ON p.id=b.package_id WHERE b.id=?";
        $params=[$id];
        if($userId!==null){$sql.=" AND b.user_id=?";$params[]=$userId;}
        $stmt=$this->db->prepare($sql);$stmt->execute($params);$b=$stmt->fetch();
        return $b?:null;
    }

    private function bookingPaid(int $id): float
    {
        $stmt=$this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id=? AND status='verified'");$stmt->execute([$id]);return (float)$stmt->fetchColumn();
    }

    private function clientFileCount(): int
    {
        $stmt=$this->db->prepare("SELECT COUNT(*) FROM files f JOIN bookings b ON b.id=f.booking_id WHERE b.user_id=?");$stmt->execute([$this->user['id']]);return (int)$stmt->fetchColumn();
    }

    private function clientBookingCount(): int
    {
        if (!$this->user || ($this->user['role'] ?? '') !== 'client') return 0;
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM bookings WHERE user_id=?");
        $stmt->execute([$this->user['id']]);
        return (int)$stmt->fetchColumn();
    }

    private function smsDeliveryReport(): void
    {
        $smsId = trim((string)($_GET['sms_id'] ?? ''));
        $status = strtoupper(trim((string)($_GET['status'] ?? '')));
        if ($smsId !== '') {
            $stmt = $this->db->prepare("SELECT id, response FROM sms_log WHERE response LIKE ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(['%'.$smsId.'%']);
            $row = $stmt->fetch();
            if ($row) {
                $existing = (string)($row['response'] ?? '');
                $suffix = "\nDLR status: ".($status !== '' ? $status : 'UNKNOWN')." @ ".$this->now();
                $nextStatus = match ($status) {
                    'DELIVERED' => 'delivered',
                    'SUBMITTED', 'QUEUED' => 'sent',
                    'PROHIBITED', 'NOT_DELIVERED', 'EXPIRED' => 'error',
                    default => strtolower($status ?: 'sent'),
                };
                $this->db->prepare("UPDATE sms_log SET status=?, response=? WHERE id=?")
                    ->execute([$nextStatus, trim($existing.$suffix), (int)$row['id']]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    /** @return array{provider:string,status:string,response:string} */
    private function sendSms(string $phone, string $message): array
    {
        $sender = $this->smsSenderId();
        $to = $this->smsPhone($phone);
        $segments = max(1, (int)ceil(mb_strlen($message) / 160));
        $unit = (float)$this->cfg('sms_unit_cost', '0.04');
        $cost = round($segments * $unit, 4);
        $status = 'logged';
        $response = '';
        $provider = 'log';
        $attempts = [];

        foreach ($this->smsProviderChain() as $candidate) {
            if ($candidate === 'log') {
                $provider = 'log';
                $status = 'logged';
                $response = 'Log provider active';
                break;
            }
            $attempt = $candidate === 'moolre'
                ? $this->sendViaMoolre($to, $message, $sender)
                : $this->sendViaArkesel($to, $message, $sender);
            $attempts[] = strtoupper($candidate).': '.$attempt['response'];
            if ($attempt['status'] === 'sent') {
                $provider = $candidate;
                $status = 'sent';
                $response = $attempt['response'];
                break;
            }
            $provider = $candidate;
            $status = 'error';
            $response = $attempt['response'];
        }

        if ($provider === 'log') {
            // Optional legacy webhook from config.php
            $sms = $this->config['sms'] ?? [];
            if (($sms['driver'] ?? '') === 'webhook' && !empty($sms['webhook_url'])) {
                $payload = json_encode(['to' => $to, 'message' => $message, 'sender' => $sender]);
                $ch = curl_init($sms['webhook_url']);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.($sms['api_key'] ?? '')],
                    CURLOPT_TIMEOUT => 10,
                ]);
                $response = (string)@curl_exec($ch);
                @curl_close($ch);
                $status = 'sent';
            }
            $line = '['.$this->now().'] '.$to.' | '.$message.PHP_EOL;
            @file_put_contents(__DIR__.'/../storage/logs/sms.log', $line, FILE_APPEND);
        }
        if ($status === 'error' && count($attempts) > 1) {
            $response = implode("\n\nFallback chain:\n", [array_shift($attempts), implode("\n", $attempts)]);
        }

        try {
            $this->db->prepare("INSERT INTO sms_log (provider,phone,message,status,segments,cost,response,created_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$provider, $to, $message, $status, $segments, $status === 'error' ? 0 : $cost, mb_substr($response, 0, 2000), $this->now()]);
        } catch (Throwable $e) {
            // ignore log failures
        }
        return ['provider' => $provider, 'status' => $status, 'response' => $response];
    }

    private function smsProvider(): string
    {
        $p = strtolower($this->cfg('sms_provider', (string)($this->config['sms']['driver'] ?? 'log')));
        if ($p === 'webhook') $p = 'log';
        $preferred = in_array($p, ['log', 'arkesel', 'moolre'], true) ? $p : 'log';
        $chain = $this->smsProviderChain();
        return $chain[0] ?? $preferred;
    }

    /** @return string[] */
    private function smsProviderChain(): array
    {
        $preferred = strtolower($this->cfg('sms_provider', (string)($this->config['sms']['driver'] ?? 'log')));
        if ($preferred === 'webhook') $preferred = 'log';
        if ($preferred === 'log') return ['log'];

        $chain = [];
        if ($this->smsProviderReady('moolre')) $chain[] = 'moolre';
        if ($this->smsProviderReady('arkesel')) $chain[] = 'arkesel';
        if ($chain) return $chain;

        return [in_array($preferred, ['arkesel', 'moolre'], true) ? $preferred : 'log'];
    }

    private function smsProviderReady(string $provider): bool
    {
        return match ($provider) {
            'moolre' => trim($this->cfg('sms_moolre_vas_key', '')) !== '',
            'arkesel' => trim($this->cfg('sms_arkesel_api_key', '')) !== '',
            default => false,
        };
    }

    private function smsSenderId(): string
    {
        $sender = trim($this->cfg('sms_sender', 'iBuk'));
        $clean = preg_replace('/[^A-Za-z0-9 ]+/', '', $sender) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        if ($clean === '') $clean = 'iBuk';
        return substr($clean, 0, 11);
    }

    private function smsPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '233') && strlen($digits) >= 12) return $digits;
        if (str_starts_with($digits, '0') && strlen($digits) === 10) return '233'.substr($digits, 1);
        if (strlen($digits) === 9) return '233'.$digits;
        return $digits !== '' ? $digits : $phone;
    }

    /** @return array{status:string,response:string} */
    private function sendViaArkesel(string $to, string $message, string $sender): array
    {
        $key = $this->cfg('sms_arkesel_api_key', '');
        if ($key === '') {
            return ['status' => 'error', 'response' => 'Missing Arkesel API key'];
        }
        $res = $this->httpJson(
            'POST',
            'https://sms.arkesel.com/api/v2/sms/send',
            [
                'sender' => $sender,
                'message' => $message,
                'recipients' => [$to],
                'callback_url' => $this->url('/sms/dlr'),
            ],
            ['api-key: '.$key, 'Content-Type: application/json']
        );
        $response = $res['raw'];
        $ok = ($res['code'] >= 200 && $res['code'] < 300)
            && (empty($res['json']['status']) || in_array(strtolower((string)$res['json']['status']), ['success', 'ok'], true) || (string)($res['json']['status'] ?? '') === 'success');
        if (isset($res['json']['status']) && strtolower((string)$res['json']['status']) === 'success') $ok = true;
        if (isset($res['json']['code']) && (string)$res['json']['code'] === '1000') $ok = true;
        return ['status' => $ok ? 'sent' : 'error', 'response' => $response];
    }

    /** @return array{status:string,response:string} */
    private function sendViaMoolre(string $to, string $message, string $sender): array
    {
        $key = $this->cfg('sms_moolre_vas_key', '');
        if ($key === '') {
            return ['status' => 'error', 'response' => 'Missing Moolre VAS key'];
        }
        $res = $this->httpJson(
            'POST',
            'https://api.moolre.com/open/sms/send',
            [
                'type' => 1,
                'senderid' => $sender,
                'messages' => [['recipient' => $to, 'message' => $message]],
            ],
            ['X-API-VASKEY: '.$key, 'Content-Type: application/json']
        );
        $response = $res['raw'];
        $code = (string)($res['json']['code'] ?? $res['json']['status'] ?? '');
        $ok = $res['code'] >= 200 && $res['code'] < 300 && ($code === '' || $code === '1' || strtolower($code) === 'success' || $code === '200');
        if (isset($res['json']['status']) && (int)$res['json']['status'] === 1) $ok = true;
        return ['status' => $ok ? 'sent' : 'error', 'response' => $response];
    }

    /** @return array{code:int,json:array,raw:string} */
    private function httpJson(string $method, string $url, ?array $body, array $headers): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)@curl_exec($ch);
        $curlError = (string)curl_error($ch);
        $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        if ($raw === '' && $curlError !== '') {
            $raw = $curlError;
        }
        $json = json_decode($raw, true);
        return ['code' => $code, 'json' => is_array($json) ? $json : [], 'raw' => $raw];
    }

    /** @return array{ok:bool,label:string,hint?:string,error?:string} */
    private function fetchSmsBalance(): array
    {
        $provider = $this->smsProvider();
        if ($provider === 'arkesel') {
            $key = $this->cfg('sms_arkesel_api_key', '');
            if ($key === '') return ['ok' => false, 'label' => '—', 'error' => 'Add Arkesel API key in Settings'];
            $res = $this->httpJson('GET', 'https://sms.arkesel.com/api/v2/clients/balance-details', null, ['api-key: '.$key, 'Accept: application/json']);
            $data = $res['json']['data'] ?? $res['json'];
            $smsBal = $data['sms_balance'] ?? $data['SMS Balance'] ?? $data['balance'] ?? null;
            $wallet = $data['main_balance'] ?? $data['wallet_balance'] ?? $data['Wallet Balance'] ?? null;
            if ($smsBal !== null || $wallet !== null) {
                $label = $smsBal !== null ? (string)$smsBal.' SMS' : 'GH₵'.(string)$wallet;
                $hint = 'Arkesel live balance';
                if ($smsBal !== null && $wallet !== null) $hint .= ' · wallet GH₵'.$wallet;
                return ['ok' => true, 'label' => $label, 'hint' => $hint];
            }
            // Legacy fallback
            $legacy = $this->httpJson('GET', 'https://sms.arkesel.com/sms/api?action=check-balance&api_key='.urlencode($key).'&response=json', null, ['Accept: application/json']);
            $bal = $legacy['json']['balance'] ?? $legacy['json']['sms_balance'] ?? null;
            if ($bal !== null) return ['ok' => true, 'label' => (string)$bal.' SMS', 'hint' => 'Arkesel balance'];
            return ['ok' => false, 'label' => '—', 'error' => 'Could not read Arkesel balance'];
        }
        if ($provider === 'moolre') {
            $key = $this->cfg('sms_moolre_vas_key', '');
            if ($key === '') return ['ok' => false, 'label' => '—', 'error' => 'Add Moolre VAS key in Settings'];
            $res = $this->httpJson(
                'POST',
                'https://api.moolre.com/open/sms/status',
                ['type' => 2],
                ['X-API-VASKEY: '.$key, 'Content-Type: application/json']
            );
            $data = $res['json']['data'] ?? $res['json'];
            $bal = $data['balance'] ?? $data['smsbalance'] ?? $data['sms_balance'] ?? $data['credit'] ?? null;
            if (is_array($data) && isset($data['wallet'])) $bal = $data['wallet'];
            if ($bal === null && isset($res['json']['balance'])) $bal = $res['json']['balance'];
            if ($bal !== null) {
                $label = is_numeric($bal) ? ((float)$bal >= 100 ? (string)$bal.' SMS' : 'GH₵'.rtrim(rtrim(number_format((float)$bal, 2, '.', ''), '0'), '.')) : (string)$bal;
                // Prefer showing as SMS credits when clearly an integer count
                if (is_numeric($bal) && (float)$bal == (int)$bal && (int)$bal > 0) {
                    $label = (string)(int)$bal.' SMS';
                }
                return ['ok' => true, 'label' => $label, 'hint' => 'Moolre live balance'];
            }
            return ['ok' => false, 'label' => '—', 'error' => 'Could not read Moolre balance'];
        }
        return ['ok' => true, 'label' => 'Dev log', 'hint' => 'Log provider — no live balance'];
    }

    private function setting(string $key): string
    {
        $stmt=$this->db->prepare("SELECT value FROM settings WHERE key=?");$stmt->execute([$key]);return (string)($stmt->fetchColumn()?:'');
    }

    private function env(string $key): string
    {
        $value = getenv($key);
        if ($value !== false && trim((string)$value) !== '') return trim((string)$value);
        $server = $_SERVER[$key] ?? null;
        if (is_string($server) && trim($server) !== '') return trim($server);
        $env = $_ENV[$key] ?? null;
        if (is_string($env) && trim($env) !== '') return trim($env);
        return '';
    }

    private function cfg(string $key, string $default = ''): string
    {
        $envMap = [
            'sms_sender' => $this->env('SMS_SENDER') ?: $this->env('MOOLRE_SENDER') ?: $this->env('ARKESEL_SENDER'),
            'sms_provider' => $this->env('SMS_PROVIDER') ?: $this->env('SMS_DRIVER'),
            'sms_arkesel_api_key' => $this->env('SMS_ARKESEL_API_KEY') ?: $this->env('ARKESEL_API_KEY') ?: $this->env('ARKESEL_KEY'),
            'sms_moolre_vas_key' => $this->env('SMS_MOOLRE_VAS_KEY') ?: $this->env('MOOLRE_VAS_KEY') ?: $this->env('MOOLRE_API_KEY'),
        ];
        if (array_key_exists($key, $envMap) && $envMap[$key] !== '') {
            return (string)$envMap[$key];
        }
        $fromDb = $this->setting($key);
        if ($fromDb !== '') return $fromDb;
        $configMap = [
            'app_name' => $this->config['app_name'] ?? null,
            'photographer_name' => $this->config['photographer_name'] ?? null,
            'momo_number' => $this->config['momo_number'] ?? null,
            'momo_account_name' => $this->config['momo_account_name'] ?? null,
            'momo_network' => $this->config['momo_network'] ?? null,
            'whatsapp_number' => $this->config['whatsapp_number'] ?? null,
            'sms_sender' => $this->config['sms']['sender'] ?? null,
            'sms_provider' => $this->config['sms']['driver'] ?? null,
            'sms_arkesel_api_key' => ($this->config['sms']['arkesel_api_key'] ?? null) ?: ($this->config['sms']['api_key'] ?? null),
            'sms_moolre_vas_key' => $this->config['sms']['moolre_vas_key'] ?? null,
        ];
        if (array_key_exists($key, $configMap) && $configMap[$key] !== null && $configMap[$key] !== '') {
            return (string)$configMap[$key];
        }
        return $default;
    }

    private function weddingBookingPercent(): float
    {
        $pct = (float)$this->cfg('wedding_booking_percent', '80');
        if ($pct < 1) $pct = 80;
        if ($pct > 100) $pct = 100;
        return $pct;
    }

    private function currentUser(): ?array
    {
        if(empty($_SESSION['user_id'])) return null;
        $stmt=$this->db->prepare("SELECT * FROM users WHERE id=?");$stmt->execute([(int)$_SESSION['user_id']]);$u=$stmt->fetch();return $u?:null;
    }

    private function requireRole(string $role): void
    {
        if (!$this->user) {
            $this->flash('error', 'Please log in first.');
            $this->redirect($role === 'admin' ? '/admin' : '/login');
        }
        if ($this->user['role'] !== $role) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    private function csrfField(): string
    {
        if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
        return '<input type="hidden" name="_csrf" value="'.htmlspecialchars($_SESSION['csrf']).'">';
    }

    private function verifyCsrf(): void
    {
        if(empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['_csrf']??''))){http_response_code(419);exit('Invalid session token. Refresh the page and try again.');}
    }

    private function flash(string $type,string $message): void
    {
        $_SESSION['flash'][]=['type'=>$type,'message'=>$message];
    }

    private function render(string $title, string $content, array $options = []): void
    {
        $isHome = !empty($options['home']);
        $portal = (string)($options['portal'] ?? '');
        $isPortal = $portal === 'admin' || $portal === 'client';
        $flashes=$_SESSION['flash']??[];unset($_SESSION['flash']);
        $flashHtml='';
        foreach($flashes as $f){
            $cls=$f['type']==='error'?'bg-red-50 border-red-200 text-red-800':'bg-emerald-50 border-emerald-200 text-emerald-800';
            $flashHtml.='<div class="mb-2 rounded-xl border '.$cls.' px-3 py-2 text-sm font-semibold">'.htmlspecialchars($f['message']).'</div>';
        }
        $nav = $isPortal ? '' : $this->topNav($isHome);
        $flashBlock = $flashHtml === '' ? '' : ($isPortal
            ? '<div class="px-3 pt-2">'.$flashHtml.'</div>'
            : '<div class="max-w-6xl mx-auto px-4 pt-4">'.$flashHtml.'</div>');
        $appName=htmlspecialchars($this->cfg('app_name','iBuk.online'));
        $themeColor = '#f7f6f3';
        if ($isHome) {
            $bodyClass = 'home-page text-stone-900 antialiased';
            $themeColor = '#f6f2ec';
        } elseif ($isPortal) {
            $bodyClass = 'portal-app text-stone-900 antialiased';
        } else {
            $bodyClass = 'bg-[#f7f5f2] text-stone-900 antialiased';
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="'.$themeColor.'"><title>'.htmlspecialchars($title).' · '.$appName.'</title><link rel="icon" href="'.$this->url('/assets/favicon.svg').'" type="image/svg+xml"><link rel="apple-touch-icon" href="'.$this->url('/assets/favicon.svg').'"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"><script src="https://cdn.tailwindcss.com"></script><style>
html{scroll-behavior:smooth}
body{font-family:"Outfit",ui-sans-serif,system-ui,sans-serif}
body.home-page{background:#f6f2ec;min-height:100svh}
.font-display{font-family:"Fraunces",ui-serif,Georgia,serif}
.safe-bottom{padding-bottom:env(safe-area-inset-bottom)}
#site-menu:checked~.site-header-inner .icon-open{display:none}
#site-menu:checked~.site-header-inner .icon-close{display:block}
#site-menu:checked~.mobile-panel{display:block}
#site-menu:checked~.mobile-scrim{display:block}
body:has(#site-menu:checked){overflow:hidden}
body:has(#site-menu:checked) main{pointer-events:none}
.icon-close{display:none}
.mobile-scrim{display:none;position:fixed;inset:0;z-index:120;background:rgba(28,25,23,.45);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px)}
.mobile-panel{display:none;position:fixed;left:.75rem;right:.75rem;top:calc(3.4rem + .4rem);z-index:130;max-height:calc(100svh - 4.2rem);overflow:auto;padding:.55rem;border-radius:1.15rem;border:1px solid #e7e5e4;background:#fff;box-shadow:0 22px 48px rgba(28,25,23,.22);-webkit-overflow-scrolling:touch}
.mobile-panel nav{display:flex;flex-direction:column;gap:.15rem}
.mobile-nav-link{display:flex;flex-direction:column;align-items:flex-start;gap:.12rem;width:100%;box-sizing:border-box;border-radius:.85rem;padding:.8rem .9rem;font-size:.95rem;font-weight:600;line-height:1.2;color:#1c1917;text-decoration:none;background:#fff}
.mobile-nav-link:hover,.mobile-nav-link:active{background:#f5f5f4}
.mobile-nav-meta{display:block;font-size:.72rem;font-weight:500;color:#a8a29e;line-height:1.25}
.mobile-panel-auth{display:grid;gap:.45rem;margin-top:.45rem;padding-top:.55rem;border-top:1px solid #f5f5f4;background:#fff}
@media (min-width:1024px){
  .mobile-panel,.mobile-scrim,.site-menu-btn{display:none!important}
}

/* —— Homepage —— */
.home-page-inner{max-width:76rem;margin:0 auto;padding:0 0 1.1rem}
.home-hero{display:flex;flex-direction:column;gap:1.45rem;padding:1.2rem 1.15rem 1.1rem}
.home-hero-copy{animation:home-rise .7s ease .05s both}
.home-kicker{margin:0;font-size:.68rem;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:#a8a29e}
.home-headline{margin:.55rem 0 0;font-family:"Fraunces",ui-serif,Georgia,serif;font-size:clamp(2.45rem,8.6vw,5rem);font-weight:600;letter-spacing:-.03em;line-height:1.05;color:#14110f;max-width:8.2ch}
.home-support{margin:.85rem 0 0;font-size:1.08rem;font-weight:500;line-height:1.45;color:#44403c;max-width:28rem}
.home-lead{margin:.5rem 0 0;font-size:.95rem;line-height:1.65;color:#78716c;max-width:28rem}
.home-cta-row{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.3rem}
.home-cta{display:inline-flex;align-items:center;justify-content:center;min-height:2.75rem;padding:0 1.3rem;border-radius:999px;background:#14110f;color:#fafaf9;font-size:.84rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.home-cta:hover{background:#292524}
.home-cta:active{transform:scale(.98)}
.home-cta-ghost{display:inline-flex;align-items:center;justify-content:center;min-height:2.75rem;padding:0 1.15rem;border-radius:999px;border:1px solid rgba(28,25,23,.14);color:#44403c;font-size:.84rem;font-weight:650;text-decoration:none;background:transparent}
.home-cta-ghost:hover{border-color:#14110f;color:#14110f}
.home-cta-accent{background:#16a34a!important;color:#fff!important}
.home-cta-accent:hover{background:#15803d!important}
.home-hero-stage{min-height:0}
.home-mosaic{display:block}
.home-mosaic-main{position:relative;overflow:hidden;border-radius:1.35rem;height:min(58svh,28rem);background:#1c1917;box-shadow:0 18px 40px rgba(28,25,23,.1)}
.home-mosaic-side{display:none;margin:0}
.home-slide{position:absolute;inset:0;margin:0;opacity:0;transition:opacity 1.1s ease;z-index:0}
.home-slide.is-active{opacity:1;z-index:1}
.home-slide img,.home-slide-empty,.home-mosaic-side img{width:100%;height:100%;object-fit:cover;object-position:center 18%;display:block}
.home-slide img{transform:scale(1);filter:contrast(1.04) saturate(1.03);backface-visibility:hidden;-webkit-backface-visibility:hidden}
.home-slide.is-active img{animation:home-drift 16s ease-out forwards}
.home-slide-empty{background:#1c1917}
@keyframes home-rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
@keyframes home-drift{from{transform:scale(1)}to{transform:scale(1.035)}}
@media (prefers-reduced-motion:reduce){
  .home-hero-copy,.home-slide img{animation:none!important;opacity:1;transform:none}
  .home-slide{transition:none}
}
@media (min-width:900px){
  html:has(body.home-page),body.home-page{height:100svh;overflow:hidden}
  .home-page-inner{height:calc(100svh - 3.4rem);max-width:76rem;padding:0}
  .home-hero{display:grid;grid-template-columns:minmax(18rem,.9fr) minmax(0,1.28fr);align-items:center;gap:2.15rem;height:100%;min-height:0;padding:1.25rem 1.75rem 1.4rem}
  .home-headline{font-size:clamp(3.3rem,5.2vw,5rem)}
  .home-hero-stage{height:100%}
  .home-mosaic{display:grid;grid-template-columns:1.38fr .72fr;grid-template-rows:1fr 1fr;gap:.7rem;height:100%;min-height:0}
  .home-mosaic-main{grid-row:1 / span 2;height:100%;border-radius:1.5rem}
  .home-mosaic-side{display:block;overflow:hidden;border-radius:1.15rem;min-height:0;background:#e7e5e4}
}
@media (min-width:1024px){
  .home-page-inner{height:calc(100svh - 4rem)}
}

.site-header{position:sticky;top:0;z-index:140;isolation:isolate;border-bottom:1px solid rgba(231,229,228,.9);background:#fff}
.site-header-inner{position:relative;z-index:150;max-width:72rem;margin:0 auto;padding:0 1rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;height:3.4rem;background:#fff}
.site-header-home{background:#f6f2ec;border-bottom-color:rgba(28,25,23,.06)}
.site-header-home .site-header-inner{background:#f6f2ec}
.site-brand{display:flex;align-items:center;gap:.65rem;min-width:0;text-decoration:none;color:#1c1917}
.site-brand-mark{display:none}
.site-brand-name{font-family:"Fraunces",ui-serif,Georgia,serif;font-size:1.35rem;font-weight:600;letter-spacing:-.01em;line-height:1}
.site-brand-tag{display:none;margin-top:.15rem;font-size:.62rem;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:#a8a29e}
.site-header-actions{display:flex;align-items:center;gap:.45rem}
.site-pill{display:inline-flex;align-items:center;justify-content:center;height:2.15rem;padding:0 .9rem;border-radius:999px;font-size:.78rem;font-weight:700;text-decoration:none}
.site-pill-ghost{color:#44403c}
.site-pill-solid{background:#1c1917;color:#fff}
.site-menu-btn{display:grid;place-items:center;width:2.35rem;height:2.35rem;border-radius:999px;border:1px solid #e7e5e4;background:#fff;color:#1c1917;cursor:pointer}
.site-nav-desk{display:none;align-items:center;gap:1.35rem}
.site-nav-desk a{position:relative;font-size:.84rem;font-weight:600;color:#78716c;text-decoration:none;padding:.25rem 0;letter-spacing:.01em;transition:color .2s ease}
.site-nav-desk a:hover{color:#1c1917}
.site-nav-desk a::after{content:"";position:absolute;left:0;right:0;bottom:0;height:1.5px;background:#1c1917;transform:scaleX(0);transform-origin:left;transition:transform .25s ease}
.site-nav-desk a:hover::after{transform:scaleX(1)}
@media (min-width:1024px){
  .site-header-inner{height:4rem}
  .site-brand-mark{display:grid;place-items:center;width:2.35rem;height:2.35rem;border-radius:999px;background:#1c1917;color:#fafaf9}
  .site-brand-name{font-size:1.55rem}
  .site-brand-tag{display:block}
  .site-nav-desk{display:flex}
  .site-menu-btn{display:none}
  .site-pill{height:2.4rem;padding:0 1.05rem;font-size:.82rem}
}
body.portal-app{min-height:100svh;background:#f3f1ee}
.portal-shell{min-height:100svh;display:flex;flex-direction:column}
.portal-top{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:.75rem;height:3.25rem;padding:0 .85rem;border-bottom:1px solid rgba(231,229,228,.95);background:rgba(255,252,249,.92);backdrop-filter:blur(12px)}
.portal-brand{display:flex;align-items:center;gap:.55rem;min-width:0;text-decoration:none;color:#1c1917}
.portal-brand-mark{display:grid;place-items:center;width:1.85rem;height:1.85rem;border-radius:.55rem;background:#1c1917;color:#fafaf9}
.portal-brand-name{font-family:"Fraunces",ui-serif,Georgia,serif;font-size:1.2rem;font-weight:600;letter-spacing:.02em;line-height:1}
.portal-brand-sub{display:block;margin-top:.1rem;font-size:.62rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#a8a29e}
.portal-top-actions{display:flex;align-items:center;gap:.4rem}
.portal-chip{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;border:1px solid #e7e5e4;background:#fff;padding:.35rem .7rem;font-size:.72rem;font-weight:700;color:#44403c;text-decoration:none}
.portal-chip i{font-size:.7rem;opacity:.8}
.portal-body{display:flex;flex:1;min-height:0}
.portal-side{display:none;width:13.75rem;flex-shrink:0;border-right:1px solid #e7e5e4;background:#fffcf9;padding:.85rem .65rem;position:sticky;top:3.25rem;height:calc(100svh - 3.25rem);overflow:auto}
.portal-side a{display:flex;align-items:center;gap:.65rem;border-radius:.75rem;padding:.58rem .72rem;font-size:.82rem;font-weight:600;color:#57534e;text-decoration:none}
.portal-side a i{width:1.05rem;text-align:center;opacity:.85;font-size:.9rem}
.portal-side a:hover{background:#f5f1ec;color:#1c1917}
.portal-side a.is-active{background:#1c1917;color:#fff}
.portal-side a.is-active i{opacity:1}
.portal-main{flex:1;min-width:0;padding:.85rem .85rem 4.75rem}
.portal-head{margin-bottom:.85rem}
.portal-kicker{margin:0;font-size:.65rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#a8a29e}
.portal-title{margin:.2rem 0 0;font-size:1.35rem;font-weight:800;letter-spacing:-.02em;color:#1c1917}
.portal-tabs{display:flex;gap:.4rem;overflow-x:auto;padding-bottom:.15rem;margin-bottom:.85rem;-webkit-overflow-scrolling:touch}
.portal-tabs a{flex:0 0 auto;display:inline-flex;align-items:center;gap:.4rem;border-radius:999px;border:1px solid #e7e5e4;background:#fff;padding:.4rem .75rem;font-size:.72rem;font-weight:700;color:#57534e;text-decoration:none}
.portal-tabs a i{font-size:.7rem;opacity:.8}
.portal-tabs a.is-active{background:#1c1917;border-color:#1c1917;color:#fff}
.portal-bottom{position:fixed;left:0;right:0;bottom:0;z-index:40;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.15rem;padding:.35rem .45rem calc(.35rem + env(safe-area-inset-bottom));border-top:1px solid #e7e5e4;background:rgba(255,252,249,.96);backdrop-filter:blur(10px)}
.portal-bottom a{display:flex;flex-direction:column;align-items:center;gap:.15rem;padding:.35rem .2rem;border-radius:.65rem;font-size:.62rem;font-weight:700;color:#78716c;text-decoration:none}
.portal-bottom a.is-active{color:#1c1917;background:#f5f1ec}
.portal-bottom a i{font-size:.95rem;line-height:1}
.portal-bottom svg{width:1.15rem;height:1.15rem}
.stat-card{display:flex;align-items:flex-start;gap:.75rem;border-radius:1.15rem;border:1px solid #e7e5e4;background:#fff;padding:.95rem 1rem;box-shadow:0 1px 0 rgba(28,25,23,.02)}
.stat-icon{display:grid;place-items:center;width:2.25rem;height:2.25rem;border-radius:.8rem;background:#1c1917;color:#fafaf9;flex-shrink:0}
.stat-icon i{font-size:.85rem}
.stat-label{font-size:.7rem;font-weight:700;color:#a8a29e;letter-spacing:.02em}
.stat-value{margin-top:.15rem;font-size:1.15rem;font-weight:900;color:#1c1917;line-height:1.15}
.dash-link{display:flex;align-items:flex-start;gap:.75rem;border-radius:1.15rem;border:1px solid #e7e5e4;background:#fff;padding:.95rem 1rem;text-decoration:none;color:inherit;transition:border-color .15s ease,transform .15s ease}
.dash-link:hover{border-color:#d6d3d1;transform:translateY(-1px)}
.dash-link-icon{display:grid;place-items:center;width:2.25rem;height:2.25rem;border-radius:.8rem;background:#f5f1ec;color:#1c1917;flex-shrink:0}
.dash-link-icon i{font-size:.9rem}

/* Compact public package cards */
.offer-card{display:flex;flex-direction:column;border:1px solid #e7e5e4;background:#fff;border-radius:1.25rem;overflow:hidden;box-shadow:0 8px 24px rgba(28,25,23,.04)}
.offer-media{display:block;aspect-ratio:3/2;overflow:hidden;background:#e7e5e4}
.offer-media img{width:100%;height:100%;object-fit:cover;object-position:center 10%;display:block;transition:transform .45s ease}
.offer-card:hover .offer-media img{transform:scale(1.03)}
.offer-body{padding:.95rem 1rem 1.05rem;display:flex;flex-direction:column;gap:.45rem;flex:1}
.offer-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem}
.offer-top h3{margin:0;font-size:1.02rem;font-weight:800;line-height:1.25}
.offer-top h3 a{color:#1c1917;text-decoration:none}
.offer-price{margin:0;font-size:.95rem;font-weight:900;white-space:nowrap;color:#1c1917}
.offer-desc{margin:0;font-size:.8rem;line-height:1.45;color:#78716c;min-height:2.3rem}
.offer-chips{margin:0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:.3rem}
.offer-chips li{font-size:.65rem;font-weight:700;color:#57534e;background:#f5f1ec;border-radius:999px;padding:.22rem .55rem}
.offer-chips li.more{background:transparent;color:#a8a29e}
.offer-actions{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-top:auto;padding-top:.35rem}
.offer-more{font-size:.78rem;font-weight:800;color:#1c1917;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem}
.offer-more i{font-size:.65rem}
.offer-book{display:inline-flex;align-items:center;justify-content:center;min-height:2.1rem;padding:0 .9rem;border-radius:999px;background:#1c1917;color:#fff;font-size:.75rem;font-weight:800;text-decoration:none}
.offer-detail-media{border-radius:1.35rem;overflow:hidden;border:1px solid #e7e5e4;background:#e7e5e4;aspect-ratio:3/2}
.offer-detail-media img{width:100%;height:100%;object-fit:cover;object-position:center 10%;display:block}
.offer-detail-list{list-style:none;margin:0;padding:0;display:grid;gap:.65rem}
.offer-detail-list li{display:flex;gap:.65rem;align-items:flex-start;font-size:.92rem;line-height:1.45;color:#44403c}
.offer-detail-list i{margin-top:.2rem;color:#1c1917;font-size:.75rem}
@media (min-width:1024px){
  .portal-side{display:block}
  .portal-tabs,.portal-bottom{display:none}
  .portal-main{padding:1rem 1.15rem 1.25rem}
  .portal-title{font-size:1.55rem}
}
</style></head><body class="'.$bodyClass.'">'.$nav.'<main>'.$flashBlock;
        $footer = '';
        $fab = '';
        echo $content.'</main>'.$footer.$fab.'</body></html>';
    }

    private function topNav(bool $home = false): string
    {
        $name=htmlspecialchars($this->cfg('app_name','iBuk.online'));
        $homeUrl=$this->url('/');
        $wedding=$this->url('/packages/wedding');
        $baby=$this->url('/packages/baby');
        $studio=$this->url('/packages/studio');
        $packages=$this->url('/packages');

        if(!$this->user){
            $authDesk='<a href="'.$this->url('/login').'" class="site-pill site-pill-ghost hidden sm:inline-flex">Log in</a><a href="'.$this->url('/register').'" class="site-pill site-pill-solid">Book</a>';
            $authMob='<a href="'.$this->url('/login').'" class="block rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm font-semibold text-stone-800">Log in</a><a href="'.$this->url('/register').'" class="block rounded-2xl bg-stone-950 px-4 py-3 text-center text-sm font-semibold text-white">Book a shoot</a>';
        }else{
            $portal=$this->user['role']==='admin'?'/dashboard':'/client/dashboard';
            $portalLabel=$this->user['role']==='admin'?'Dashboard':'Portal';
            $authDesk='<a href="'.$this->url($portal).'" class="site-pill site-pill-ghost hidden sm:inline-flex">'.$portalLabel.'</a><a href="'.$this->url('/logout').'" class="site-pill site-pill-solid">Log out</a>';
            $authMob='<a href="'.$this->url($portal).'" class="block rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm font-semibold text-stone-800">'.$portalLabel.'</a><a href="'.$this->url('/logout').'" class="block rounded-2xl bg-stone-950 px-4 py-3 text-center text-sm font-semibold text-white">Log out</a>';
        }

        $mobileLink=function(string $href,string $label,string $meta){
            return '<a href="'.$href.'" class="mobile-nav-link"><span>'.$label.'</span><span class="mobile-nav-meta">'.$meta.'</span></a>';
        };

        return '<header class="site-header'.($home ? ' site-header-home' : '').'">
          <input type="checkbox" id="site-menu" class="sr-only" aria-hidden="true">
          <div class="site-header-inner">
            <a href="'.$homeUrl.'" class="site-brand">
              <span class="site-brand-mark"><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4.5 8.5h2.2l1.2-2h8.2l1.2 2H19.5A1.5 1.5 0 0 1 21 10v7.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5V10a1.5 1.5 0 0 1 1.5-1.5Z"/><circle cx="12" cy="13.5" r="3.2"/></svg></span>
              <span><span class="site-brand-name">'.$name.'</span><span class="site-brand-tag">Photography studio</span></span>
            </a>
            <nav class="site-nav-desk" aria-label="Primary">
              <a href="'.$wedding.'">Weddings</a>
              <a href="'.$baby.'">Baby</a>
              <a href="'.$studio.'">Studio</a>
              <a href="'.$packages.'">Packages</a>
            </nav>
            <div class="site-header-actions">
              '.$authDesk.'
              <label for="site-menu" class="site-menu-btn" aria-label="Open menu">
                <svg class="icon-open h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                <svg class="icon-close h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>
              </label>
            </div>
          </div>
          <label for="site-menu" class="mobile-scrim" aria-label="Close menu"></label>
          <div class="mobile-panel">
            <nav aria-label="Mobile">
              '.$mobileLink($homeUrl,'Home','Studio overview').'
              '.$mobileLink($wedding,'Weddings','Engagements & celebrations').'
              '.$mobileLink($baby,'Baby days','Dedication & christening').'
              '.$mobileLink($studio,'Studio','Portrait sessions').'
              '.$mobileLink($packages,'Packages','Browse every offer').'
            </nav>
            <div class="mobile-panel-auth">'.$authMob.'</div>
          </div>
        </header>';
    }

    private function clientShell(string $title, string $content): string
    {
        $name = htmlspecialchars($this->cfg('app_name', 'iBuk.online'));
        $canBookFirst = $this->clientBookingCount() === 0;
        $items = [
            ['/client/dashboard', 'Home'],
            ['/client/bookings', 'Bookings'],
            ['/client/payments', 'Payments'],
            ['/client/files', 'Files'],
            ['/client/profile', 'Profile'],
        ];
        $side = '';
        $tabs = '';
        foreach ($items as [$href, $label]) {
            $active = $this->portalActive($href) ? ' is-active' : '';
            $side .= '<a href="'.$this->url($href).'" class="'.$active.'">'.htmlspecialchars($label).'</a>';
            $tabs .= '<a href="'.$this->url($href).'" class="'.$active.'">'.htmlspecialchars($label).'</a>';
        }
        $bottomIcons = [
            '/client/dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z"/></svg>',
            '/client/bookings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 3v2M16 3v2M5 8h14M6.5 5.5h11A1.5 1.5 0 0 1 19 7v12.5A1.5 1.5 0 0 1 17.5 21h-11A1.5 1.5 0 0 1 5 19.5V7a1.5 1.5 0 0 1 1.5-1.5Z"/></svg>',
            '/client/payments' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8.5h16v9H4v-9Z"/><path d="M4 11h16M8 14.5h3"/></svg>',
            '/client/files' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 4h6l4 4v12H8V4Z"/><path d="M14 4v4h4"/></svg>',
            '/client/profile' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="9" r="3.2"/><path d="M5.5 19c1.5-3 3.7-4.5 6.5-4.5s5 1.5 6.5 4.5"/></svg>',
        ];
        $bottom = '';
        foreach ($items as [$href, $label]) {
            $active = $this->portalActive($href) ? ' is-active' : '';
            $short = ['/client/dashboard'=>'Home','/client/bookings'=>'Jobs','/client/payments'=>'Pay','/client/files'=>'Files','/client/profile'=>'You'][$href];
            $bottom .= '<a href="'.$this->url($href).'" class="'.$active.'">'.$bottomIcons[$href].'<span>'.$short.'</span></a>';
        }
        return '<div class="portal-shell">
          <header class="portal-top">
            <a class="portal-brand" href="'.$this->url('/client/dashboard').'">
              <span class="portal-brand-mark"><svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4.5 8.5h2.2l1.2-2h8.2l1.2 2H19.5A1.5 1.5 0 0 1 21 10v7.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5V10a1.5 1.5 0 0 1 1.5-1.5Z"/><circle cx="12" cy="13.5" r="3.2"/></svg></span>
              <span><span class="portal-brand-name">'.$name.'</span><span class="portal-brand-sub">Client portal</span></span>
            </a>
            <div class="portal-top-actions">
              '.($canBookFirst ? '<a class="portal-chip" href="'.$this->url('/client/new-booking').'">Book</a>' : '').'
              <a class="portal-chip" href="'.$this->url('/logout').'">Log out</a>
            </div>
          </header>
          <div class="portal-body">
            <aside class="portal-side">'.$side.'</aside>
            <section class="portal-main">
              <div class="portal-head"><p class="portal-kicker">Your studio</p><h1 class="portal-title">'.htmlspecialchars($title).'</h1></div>
              <div class="portal-tabs">'.$tabs.'</div>
              '.$content.'
            </section>
          </div>
          <nav class="portal-bottom" aria-label="Client">'.$bottom.'</nav>
        </div>';
    }

    private function adminShell(string $title, string $content): string
    {
        $name = htmlspecialchars($this->cfg('app_name', 'iBuk.online'));
        $items = [
            ['/dashboard', 'Home', 'fa-solid fa-gauge-high'],
            ['/dashboard/bookings', 'Bookings', 'fa-solid fa-calendar-check'],
            ['/dashboard/payments', 'Payments', 'fa-solid fa-mobile-screen'],
            ['/dashboard/packages', 'Packages', 'fa-solid fa-box-open'],
            ['/dashboard/clients', 'Clients', 'fa-solid fa-users'],
            ['/dashboard/coupons', 'Coupons', 'fa-solid fa-ticket'],
            ['/dashboard/slides', 'Homepage', 'fa-solid fa-images'],
            ['/dashboard/reports', 'Reports', 'fa-solid fa-chart-line'],
            ['/dashboard/settings', 'Settings', 'fa-solid fa-gear'],
        ];
        $side = '';
        $tabs = '';
        foreach ($items as [$href, $label, $icon]) {
            $active = $this->portalActive($href) ? ' is-active' : '';
            $side .= '<a href="'.$this->url($href).'" class="'.$active.'"><i class="'.$icon.'" aria-hidden="true"></i><span>'.htmlspecialchars($label).'</span></a>';
            $tabs .= '<a href="'.$this->url($href).'" class="'.$active.'"><i class="'.$icon.'" aria-hidden="true"></i><span>'.htmlspecialchars($label).'</span></a>';
        }
        $bottomKeys = [
            ['/dashboard', 'Home', 'fa-solid fa-gauge-high'],
            ['/dashboard/bookings', 'Jobs', 'fa-solid fa-calendar-check'],
            ['/dashboard/payments', 'Pay', 'fa-solid fa-mobile-screen'],
            ['/dashboard/packages', 'Packs', 'fa-solid fa-box-open'],
            ['/dashboard/settings', 'More', 'fa-solid fa-ellipsis'],
        ];
        $bottom = '';
        foreach ($bottomKeys as [$href, $label, $icon]) {
            $active = $this->portalActive($href) ? ' is-active' : '';
            $bottom .= '<a href="'.$this->url($href).'" class="'.$active.'"><i class="'.$icon.'" aria-hidden="true"></i><span>'.$label.'</span></a>';
        }
        return '<div class="portal-shell">
          <header class="portal-top">
            <a class="portal-brand" href="'.$this->url('/dashboard').'">
              <span class="portal-brand-mark"><i class="fa-solid fa-camera" aria-hidden="true"></i></span>
              <span><span class="portal-brand-name">'.$name.'</span><span class="portal-brand-sub">Studio dashboard</span></span>
            </a>
            <div class="portal-top-actions">
              <a class="portal-chip" href="'.$this->url('/').'"><i class="fa-solid fa-globe" aria-hidden="true"></i> Site</a>
              <a class="portal-chip" href="'.$this->url('/logout').'"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Log out</a>
            </div>
          </header>
          <div class="portal-body">
            <aside class="portal-side">'.$side.'</aside>
            <section class="portal-main">
              <div class="portal-head"><p class="portal-kicker">Studio dashboard</p><h1 class="portal-title">'.htmlspecialchars($title).'</h1></div>
              <div class="portal-tabs">'.$tabs.'</div>
              '.$content.'
            </section>
          </div>
          <nav class="portal-bottom" aria-label="Dashboard">'.$bottom.'</nav>
        </div>';
    }


    private function portalActive(string $href): bool
    {
        $current = $this->path ?: '/';
        if ($href === '/dashboard' || $href === '/client/dashboard') {
            return $current === $href;
        }
        if ($href === '/dashboard/bookings' && (str_starts_with($current, '/dashboard/booking') || $current === '/dashboard/bookings')) {
            return true;
        }
        if ($href === '/client/bookings' && (str_starts_with($current, '/client/booking') || $current === '/client/bookings')) {
            return true;
        }
        return $current === $href || str_starts_with($current, rtrim($href, '/').'/');
    }

    private function sideLink(string $href,string $label): string
    {
        return '<a class="block rounded-xl px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-white hover:text-slate-950" href="'.$this->url($href).'">'.htmlspecialchars($label).'</a>';
    }

    private function mobileLink(string $href,string $label): string
    {
        return '<a class="whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold" href="'.$this->url($href).'">'.htmlspecialchars($label).'</a>';
    }

    private function authLayout(string $title,string $subtitle,string $form): string
    {
        return '<div class="max-w-md mx-auto px-4 py-12"><div class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8 shadow-sm"><h1 class="text-3xl font-black">'.$title.'</h1><p class="mt-2 text-sm leading-6 text-slate-500">'.$subtitle.'</p><div class="mt-6">'.$form.'</div></div></div>';
    }

    private function input(string $name,string $label,string $type='text',$value='',string $placeholder=''): string
    {
        $step=$type==='number'?' step="0.01"':'';
        if ($placeholder === '') {
            $placeholder = match ($type) {
                'email' => 'name@email.com',
                'tel' => 'Active phone number',
                'password' => 'Enter password',
                'date' => 'Select date',
                'number' => 'Enter amount',
                default => $label,
            };
        }
        $ph=' placeholder="'.htmlspecialchars($placeholder).'"';
        $auto=$type==='password'?' autocomplete="current-password"':($type==='tel'?' autocomplete="tel"':'');
        return '<div><label class="text-sm font-bold">'.htmlspecialchars($label).'</label><input'.$step.' type="'.$type.'" name="'.$name.'" value="'.htmlspecialchars((string)$value).'"'.$ph.$auto.' class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 outline-none focus:border-slate-400 placeholder:text-stone-400"></div>';
    }

    private function secretInput(string $name, string $label, string $placeholder, bool $configured, bool $activeProvider = false): string
    {
        $status = $configured
            ? '<span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700">Configured</span>'
            : '<span class="rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-black uppercase tracking-wide text-stone-600">Not set</span>';
        $active = $activeProvider
            ? '<span class="rounded-full bg-sky-100 px-2.5 py-1 text-[11px] font-black uppercase tracking-wide text-sky-700">Active</span>'
            : '';
        $help = $configured
            ? 'Current key is hidden. Leave blank to keep it, or enter a new value to replace it.'
            : $placeholder;
        return '<div><div class="flex flex-wrap items-center justify-between gap-2"><label class="text-sm font-bold">'.htmlspecialchars($label).'</label><div class="flex flex-wrap gap-2">'.$status.$active.'</div></div><input type="password" name="'.$name.'" value="" placeholder="'.htmlspecialchars($configured ? 'Leave blank to keep current key' : $placeholder).'" autocomplete="new-password" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 outline-none focus:border-slate-400 placeholder:text-stone-400"><p class="mt-1 text-xs text-stone-500">'.htmlspecialchars($help).'</p></div>';
    }

    private function textarea(string $name, string $label, string $placeholder = '', string $value = '', int $rows = 3, string $wrapClass = 'md:col-span-2'): string
    {
        if ($placeholder === '') $placeholder = $label;
        $cls = $wrapClass !== '' ? ' class="'.$wrapClass.'"' : '';
        return '<div'.$cls.'><label class="text-sm font-bold">'.htmlspecialchars($label).'</label><textarea name="'.$name.'" rows="'.$rows.'" placeholder="'.htmlspecialchars($placeholder).'" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 outline-none focus:border-slate-400 placeholder:text-stone-400">'.htmlspecialchars($value).'</textarea></div>';
    }

    private function stat(string $label, string $value, string $icon = 'fa-solid fa-circle'): string
    {
        return '<div class="stat-card"><span class="stat-icon"><i class="'.$icon.'" aria-hidden="true"></i></span><div><p class="stat-label">'.$label.'</p><p class="stat-value">'.$value.'</p></div></div>';
    }

    private function mini(string $label,string $value): string
    {
        return '<div class="rounded-2xl bg-slate-50 p-3 min-w-0"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">'.$label.'</p><p class="mt-1 text-sm font-black truncate">'.htmlspecialchars((string)$value).'</p></div>';
    }

    private function step(string $n,string $title,string $text): string
    {
        return '<div class="flex gap-4"><div class="h-9 w-9 rounded-xl bg-white/10 grid place-items-center text-xs font-black">'.$n.'</div><div><p class="font-black">'.$title.'</p><p class="mt-1 text-sm text-slate-400">'.$text.'</p></div></div>';
    }

    private function badge(string $status): string
    {
        $label=ucwords(str_replace('_',' ',$status));
        $ok=['verified','paid','confirmed','completed','ready','active','shoot completed'];
        $bad=['rejected','cancelled','inactive'];
        $normalized=strtolower(str_replace('_',' ',$status));
        $cls=in_array($normalized,$ok,true)?'bg-emerald-50 text-emerald-700 border-emerald-100':(in_array($normalized,$bad,true)?'bg-red-50 text-red-700 border-red-100':'bg-amber-50 text-amber-700 border-amber-100');
        return '<span class="inline-flex rounded-full border '.$cls.' px-2.5 py-1 text-[10px] font-black uppercase tracking-wide">'.$label.'</span>';
    }

    private function emptyState(string $title,string $text,string $href='',string $button=''): string
    {
        return '<div class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center"><h3 class="font-black">'.$title.'</h3><p class="mt-2 text-sm text-slate-500">'.$text.'</p>'.($href?'<a href="'.$this->url($href).'" class="mt-4 inline-block rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">'.$button.'</a>':'').'</div>';
    }

    private function money(float $amount): string
    {
        return ($this->config['currency']??'GHS').' '.number_format($amount,2);
    }

    private function formatBytes(int $bytes): string
    {
        if($bytes>=1073741824)return round($bytes/1073741824,1).' GB';
        if($bytes>=1048576)return round($bytes/1048576,1).' MB';
        if($bytes>=1024)return round($bytes/1024,1).' KB';
        return $bytes.' B';
    }

    private function url(string $path): string
    {
        $base=rtrim((string)($this->config['base_url']??''),'/');
        if(!$base){
            $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
            $host=$_SERVER['HTTP_HOST']??'localhost';
            $dir=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'')),'/');
            $base=$scheme.'://'.$host.($dir==='/'?'':$dir);
        }
        return $base.'/'.ltrim($path,'/');
    }

    private function now(): string { return date('Y-m-d H:i:s'); }

    private function redirect(string $path): never
    {
        header('Location: '.$this->url($path));exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('Not found','<div class="max-w-xl mx-auto px-4 py-20 text-center"><h1 class="text-4xl font-black">404</h1><p class="mt-3 text-slate-500">The page or record could not be found.</p><a href="'.$this->url('/').'" class="mt-5 inline-block rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Go home</a></div>');
    }
}