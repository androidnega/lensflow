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
                '/client/dashboard' => 'clientDashboard',
                '/client/bookings' => 'clientBookings',
                '/client/booking' => 'clientBookingDetail',
                '/client/payments' => 'clientPayments',
                '/client/files' => 'clientFiles',
                '/client/profile' => 'clientProfile',
                '/download' => 'downloadFile',
                '/admin' => 'adminDashboard',
                '/admin/bookings' => 'adminBookings',
                '/admin/booking' => 'adminBookingDetail',
                '/admin/payments' => 'adminPayments',
                '/admin/packages' => 'adminPackages',
                '/admin/coupons' => 'adminCoupons',
                '/admin/clients' => 'adminClients',
                '/admin/reports' => 'adminReports',
                '/admin/settings' => 'adminSettings',
            ],
            'POST' => [
                '/register' => 'register',
                '/login' => 'login',
                '/book' => 'createBooking',
                '/client/payment-submit' => 'submitPayment',
                '/client/contract-accept' => 'acceptContract',
                '/client/profile' => 'updateProfile',
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
            ],
        ];

        $handler = $routes[$method][$path] ?? null;
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
    type TEXT NOT NULL DEFAULT 'percent',
    value REAL NOT NULL,
    max_uses INTEGER NOT NULL DEFAULT 0,
    uses INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
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
SQL;
        $this->db->exec($schema);
        $this->ensureColumn('packages', 'category', "category TEXT NOT NULL DEFAULT 'wedding'");
        $this->ensureColumn('bookings', 'contract_text_snapshot', 'contract_text_snapshot TEXT');
        $this->ensureColumn('bookings', 'contract_signer_name', 'contract_signer_name TEXT');
        $this->ensureColumn('bookings', 'contract_signature', 'contract_signature TEXT');
        $this->ensureColumn('bookings', 'contract_ip', 'contract_ip TEXT');
        $this->ensureColumn('bookings', 'contract_file', 'contract_file TEXT');
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

        $admin = $this->db->prepare("SELECT id FROM users WHERE role='admin' LIMIT 1");
        $admin->execute();
        if (!$admin->fetch()) {
            $stmt = $this->db->prepare("INSERT INTO users (role,first_name,last_name,phone,email,password_hash,created_at) VALUES ('admin',?,?,?,?,?,?)");
            $stmt->execute([
                'Studio','Admin','0200000000',
                $this->config['admin_email'] ?? 'admin@example.com',
                password_hash('ChangeMe123!', PASSWORD_DEFAULT),
                $this->now()
            ]);
        }

        $defaults = [
            'contract_text' => "PHOTOGRAPHY SERVICE AGREEMENT\n\nThis agreement is between the Studio (\"Mhannuellens\") and the Client named in this booking.\n\n1. Booking confirmation\nThe Client confirms the selected package, event details, pricing and deposit shown in the portal.\n\n2. Payments\nWork begins after the required initial payment has been verified. Final deliverables remain subject to full payment unless otherwise agreed in writing.\n\n3. Schedule & delivery\nEstimated turnaround follows the package terms and the project timeline in the portal. Dates may shift by mutual agreement.\n\n4. Usage & ownership\nThe Studio retains copyright in the images. The Client receives personal-use soft copies as listed in the package. Commercial use requires prior written consent.\n\n5. Cancellation\nDeposits are non-refundable once verified, except where the Studio cancels the booking.\n\n6. Acceptance\nBy signing digitally in this portal, the Client agrees to these terms.",
            'studio_note' => "After payment verification, your booking becomes active. Sign your agreement in the portal, then follow every stage on your timeline.",
            'studio_signer_name' => 'Mhannuellens Studio',
        ];
        $stmt = $this->db->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)");
        foreach ($defaults as $k => $v) $stmt->execute([$k,$v]);

        $this->seedTimelineTemplates();
    }

    private function seedCataloguePackages(): void
    {
        $count = (int)$this->db->query("SELECT COUNT(*) FROM packages")->fetchColumn();
        if ($count > 0) return;

        $now = $this->now();
        $packages = [
            ['ProBasic','probasic','wedding','Engagement coverage with framed prints and soft-copy delivery. No video.',3659.99,50,14,"1 A3 frame\n8 Retouched pictures\n8GB Pen drive for soft copy pictures\nOver 165 soft copy pictures\nEngagement only\nNo Video"],
            ['Ultra','ultra','wedding','Wedding coverage with video, framed prints and Google Drive delivery.',4499,50,21,"2 A3 frames\n12 Retouched Pictures\nPictures on Google Drive\n8GB Pen drive for softcopy pictures\nOver 200 soft copy pictures\nWedding Video only"],
            ['Premium','premium','wedding','Full wedding & engagement coverage with photobook, pre-wedding and drone.',6600,50,28,"A4 Photobook\nOver 300 soft copy pictures\nWedding & Engagement Video\n32GB Pen drive for the soft copy pictures / Videos\nPre Wedding Pictures / Video\nDrone"],
            ['Gold','gold','wedding','Top-tier wedding & engagement package with photobook, pre-wedding and drone.',7000,50,30,"2 A3 frames\nPhotobook\nOver 370 soft copy pictures\nWedding & Engagement Video\n64GB Pen drive for the soft copy pictures and Videos\nPre Wedding Video\nPre Wedding Pictures\nDrone"],
            ['Baby Package 1','baby-1','baby','Baby dedication & christening package with WhatsApp delivery and an A4 wooden frame.',1250,50,7,"40+ soft copy pictures\nSent via WhatsApp\nA4 wooden frame"],
            ['Baby Package 2','baby-2','baby','Baby dedication & christening package with pendrive delivery and an A3 wooden frame.',1650,50,10,"65+ soft copy pictures\nPendrive\nA3 wooden frame"],
            ['Baby Package 3','baby-3','baby','Baby dedication & christening package with frames, pendrive and Google Drive backup.',2300,50,14,"100+ soft copy pictures\n2 A3 wooden frames\nPendrive\nBackup on Google Drive"],
            ['Glow','studio-glow','studio','Studio portrait session with one look and fully retouched pictures.',200,50,3,"3 Pictures\n1 Dress\nAll Retouched"],
            ['Signature','studio-signature','studio','Studio portrait session with two looks and fully retouched pictures.',350,50,5,"5 Pictures\n2 Dresses\nAll Retouched"],
            ['Prestige','studio-prestige','studio','Studio portrait session with three looks and fully retouched pictures.',500,50,5,"8 Pictures\n3 Dresses\nAll Retouched"],
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
            ['Booking & deposit','Waiting for your initial payment to be verified.','booking',0,1],
            ['Contract & preparation','Review and sign the agreement, then prepare for the shoot.','booking',1,2],
            ['Shoot / event','Photography session or event coverage.','event',0,3],
            ['Selection & editing','Culling, editing and retouching according to your package.','turnaround',-3,4],
            ['Final delivery','Your completed soft copies become available in your portal.','turnaround',0,5],
        ];
        $stmt = $this->db->prepare("INSERT INTO timeline_templates (title,description,due_rule,due_offset,sort_order,active,created_at) VALUES (?,?,?,?,?,1,?)");
        foreach ($steps as $s) {
            $stmt->execute([$s[0],$s[1],$s[2],$s[3],$s[4],$now]);
        }
    }

    private function packageCategoryMeta(): array
    {
        return [
            'wedding' => ['label' => 'Wedding & Engagement', 'short' => 'Wedding', 'blurb' => 'Coverage for weddings, engagements and celebrations.'],
            'baby' => ['label' => 'Baby Dedication & Christening', 'short' => 'Baby', 'blurb' => 'Packages for baby dedication and christening days.'],
            'studio' => ['label' => 'Studio Shoot 2026', 'short' => 'Studio', 'blurb' => 'Professional studio portrait sessions.'],
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
            $cols = count($items) >= 4 ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-2 lg:grid-cols-3';
            $cards = '';
            foreach ($items as $p) $cards .= $this->packageCard($p, $showBook);
            $heading = '';
            if ($withHeading) {
                $heading = '<div class="mb-6 flex items-start gap-4"><span class="mt-1 grid h-12 w-12 place-items-center rounded-2xl bg-stone-950 text-amber-400">'.$this->categoryIcon($cat,'h-6 w-6').'</span><div><p class="text-sm font-semibold text-stone-500">Mhannuellens</p><h2 class="text-2xl sm:text-3xl font-black text-stone-950">'.htmlspecialchars($label).'</h2><p class="mt-2 text-stone-600">'.htmlspecialchars($blurb).'</p></div></div>';
            }
            $html .= '<section id="packages-'.htmlspecialchars($cat).'" class="'.($withHeading ? 'mt-12 first:mt-0 ' : '').'scroll-mt-28">'.$heading.'<div class="grid '.$cols.' gap-5">'.$cards.'</div></section>';
        }
        return $html;
    }

    private function home(): void
    {
        $phone = htmlspecialchars($this->config['momo_number'] ?? '0257940791');
        $phoneHref = preg_replace('/\s+/', '', $this->config['momo_number'] ?? '0257940791');
        $meta = $this->packageCategoryMeta();
        $hero = htmlspecialchars($this->url('/assets/hero-home.jpg'));

        $cats = '';
        $i = 0;
        foreach ($meta as $slug => $info) {
            $i++;
            $cats .= '<a href="'.$this->url('/packages/'.$slug).'" class="clean-cat" style="--cat-i:'.$i.'">
              <span class="clean-cat-icon">'.$this->categoryIcon($slug, 'h-5 w-5').'</span>
              <span class="clean-cat-label">'.htmlspecialchars($info['short']).'</span>
            </a>';
        }
        $i++;
        $cats .= '<a href="'.$this->url('/packages').'" class="clean-cat" style="--cat-i:'.$i.'">
          <span class="clean-cat-icon">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 7.5h16M7 4.5h10M6 7.5v10.5A1.5 1.5 0 0 0 7.5 19.5h9a1.5 1.5 0 0 0 1.5-1.5V7.5"/><path d="M10 11.5h4M10 15h4"/></svg>
          </span>
          <span class="clean-cat-label">All</span>
        </a>';

        $body = '
        <section class="clean-home">
          <div class="clean-visual">
            <div class="clean-visual-frame">
              <img src="'.$hero.'" alt="" class="clean-visual-img" width="819" height="1024" decoding="async" fetchpriority="high">
            </div>
            <div class="clean-visual-fade" aria-hidden="true"></div>
          </div>

          <div class="clean-wrap">
            <h1 class="clean-display">Moments that last.</h1>
            <p class="clean-title">Book your next shoot in minutes.</p>
            <p class="clean-lead">Wedding, baby &amp; studio packages — pay with MoMo and follow everything in one place.</p>

            <div class="clean-actions">
              <a href="'.$this->url('/packages').'" class="clean-btn clean-btn-primary">View packages</a>
            </div>

            <div class="clean-cats">'.$cats.'</div>

            <p class="clean-foot">
              <a href="tel:'.$phoneHref.'">'.$phone.'</a>
              <span aria-hidden="true">·</span>
              <a href="'.$this->url('/login').'">Client login</a>
            </p>
          </div>
        </section>';
        $this->render('Home', $body, ['home' => true]);
    }

    private function packagesIndex(): void
    {
        $meta = $this->packageCategoryMeta();
        $cards = '';
        foreach ($meta as $slug => $info) {
            $count = (int)$this->db->query("SELECT COUNT(*) FROM packages WHERE active=1 AND category=".$this->db->quote($slug))->fetchColumn();
            $cards .= '<a href="'.$this->url('/packages/'.$slug).'" class="group block rounded-[1.75rem] border border-stone-200 bg-white p-7 sm:p-8 hover:border-stone-400 hover:shadow-lg hover:shadow-stone-200/60 transition">
              <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-stone-950 text-amber-400">'.$this->categoryIcon($slug,'h-6 w-6').'</span>
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
              <span class="mt-1 grid h-12 w-12 place-items-center rounded-2xl bg-stone-950 text-amber-400">'.$this->categoryIcon($category,'h-6 w-6').'</span>
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Mhannuellens</p>
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

    private function bookingForm(array $package): string
    {
        $deposit = (float)$package['price'] * ((float)$package['deposit_percent'] / 100);
        $icon = $this->categoryIcon((string)($package['category'] ?? 'wedding'), 'h-6 w-6');
        return '<div id="book" class="mb-10 scroll-mt-28 rounded-[2rem] border border-stone-200 bg-white p-5 sm:p-8 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-4">
              <span class="grid h-12 w-12 place-items-center rounded-2xl bg-stone-950 text-amber-400">'.$icon.'</span>
              <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-400">Confirm booking</p>
                <h2 class="mt-1 text-2xl font-black text-stone-950">'.htmlspecialchars($package['name']).'</h2>
                <p class="mt-1 text-sm text-stone-500">'.$this->money((float)$package['price']).' · Initial payment '.$this->money($deposit).'</p>
              </div>
            </div>
            <a href="'.$this->url('/packages/'.htmlspecialchars((string)($package['category'] ?? 'wedding'))).'" class="text-sm font-semibold text-stone-500 hover:text-stone-800">Cancel</a>
          </div>
          <form method="post" action="'.$this->url('/book').'" class="mt-6 grid md:grid-cols-2 gap-4">
            '.$this->csrfField().'
            <input type="hidden" name="package_id" value="'.(int)$package['id'].'">
            '.$this->eventTypeSelect((string)($package['category'] ?? 'wedding')).'
            '.$this->input('event_date','Event date','date').'
            '.$this->input('event_location','Event location').'
            '.$this->input('coupon_code','Coupon code (optional)').'
            <div class="md:col-span-2"><label class="text-sm font-bold">Notes</label><textarea name="notes" rows="3" placeholder="Venue, preferred time, extra requests…" class="mt-1 w-full rounded-2xl border border-stone-200 bg-white px-4 py-3 outline-none focus:border-stone-400"></textarea></div>
            <div class="md:col-span-2 flex flex-wrap items-center gap-3 pt-1">
              <button class="rounded-full bg-stone-950 px-6 py-3 text-sm font-bold text-white hover:bg-stone-800 transition">Confirm booking</button>
              <p class="text-xs text-stone-500">You will get payment instructions after confirmation.</p>
            </div>
          </form>
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

    private function packageCard(array $p, bool $showBook = false): string
    {
        $lines = array_filter(array_map('trim', preg_split('/\r?\n/', (string)$p['deliverables'])));
        $list = '';
        foreach ($lines as $line) {
            $list .= '<li class="flex gap-2.5"><span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-700">'.$this->checkIcon().'</span><span>'.htmlspecialchars($line).'</span></li>';
        }
        $deposit = $p['price'] * ($p['deposit_percent'] / 100);
        $cat = (string)($p['category'] ?? 'wedding');
        $button = '';
        if ($showBook) {
            $bookHref = $this->url('/packages/'.$cat.'?book='.$p['id']).'#book';
            if ($this->user && ($this->user['role'] ?? '') === 'client') {
                $button = '<a href="'.$bookHref.'" class="mt-6 block w-full rounded-2xl bg-stone-950 px-4 py-3 text-center text-sm font-bold text-white hover:bg-stone-800 transition">Book this package</a>';
            } elseif (!$this->user) {
                $button = '<a href="'.$this->url('/register?package='.$p['id']).'" class="mt-6 block w-full rounded-2xl bg-stone-950 px-4 py-3 text-center text-sm font-bold text-white hover:bg-stone-800 transition">Book this package</a>';
            }
        }
        return '<article class="rounded-[1.7rem] border border-stone-200 bg-white p-6 shadow-sm hover:border-stone-300 transition">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-stone-950 text-amber-400">'.$this->categoryIcon($cat).'</span>
              <h3 class="mt-4 text-xl font-black text-stone-950">'.htmlspecialchars($p['name']).'</h3>
              <p class="mt-2 text-sm leading-6 text-stone-600">'.htmlspecialchars($p['description']).'</p>
            </div>
          </div>
          <div class="mt-6"><span class="text-3xl font-black">'.$this->money((float)$p['price']).'</span><p class="mt-1 text-xs text-stone-500">Initial payment: '.$this->money((float)$deposit).' ('.(int)$p['deposit_percent'].'%)</p></div>
          <ul class="mt-5 space-y-2.5 text-sm text-stone-700">'.$list.'</ul>
          <div class="mt-5 rounded-2xl bg-stone-50 p-3 text-xs text-stone-600">Estimated turnaround: <strong>'.(int)$p['turnaround_days'].' days</strong></div>
          '.$button.'
        </article>';
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
        if ($this->user) $this->redirect($this->user['role']==='admin'?'/admin':'/client/dashboard');
        $packageId = (int)($_GET['package'] ?? 0);
        $body = $this->authLayout('Create your client account','Use your phone number to keep your booking and project updates in one place.',
            '<form method="post" action="'.$this->url('/register').'" class="space-y-4">'.$this->csrfField().'<input type="hidden" name="package_id" value="'.$packageId.'">'.$this->input('first_name','First name').$this->input('last_name','Last name').$this->input('phone','Phone number','tel').$this->input('email','Email (optional)','email').$this->input('password','Password','password').'<button class="w-full rounded-2xl bg-slate-950 px-4 py-3 font-bold text-white">Create account</button><p class="text-center text-sm text-slate-500">Already a client? <a class="font-bold text-slate-900" href="'.$this->url('/login').'">Log in</a></p></form>');
        $this->render('Register', $body);
    }

    private function register(): void
    {
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $phone = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $packageId = (int)($_POST['package_id'] ?? 0);
        if (!$first || !$last || strlen($phone) < 9 || strlen($password) < 6) {
            $this->flash('error','Please complete the required fields. Password must be at least 6 characters.');
            $this->redirect('/register'.($packageId?'?package='.$packageId:''));
        }
        try {
            $stmt = $this->db->prepare("INSERT INTO users (role,first_name,last_name,phone,email,password_hash,created_at) VALUES ('client',?,?,?,?,?,?)");
            $stmt->execute([$first,$last,$phone,$email ?: null,password_hash($password,PASSWORD_DEFAULT),$this->now()]);
        } catch (PDOException $e) {
            $this->flash('error','That phone number already has an account.');
            $this->redirect('/register');
        }
        $_SESSION['user_id'] = (int)$this->db->lastInsertId();
        $this->user = $this->currentUser();
        $this->flash('success','Account created successfully.');
        if ($packageId) {
            $stmt = $this->db->prepare("SELECT category FROM packages WHERE id=? AND active=1");
            $stmt->execute([$packageId]);
            $cat = (string)($stmt->fetchColumn() ?: 'wedding');
            $this->redirect('/packages/'.$cat.'?book='.$packageId.'#book');
        }
        $this->redirect('/client/dashboard');
    }

    private function loginForm(): void
    {
        if ($this->user) $this->redirect($this->user['role']==='admin'?'/admin':'/client/dashboard');
        $body = $this->authLayout('Welcome back','Access bookings, payments, contracts, progress and delivered files.',
            '<form method="post" action="'.$this->url('/login').'" class="space-y-4">'.$this->csrfField().$this->input('phone','Phone number','tel').$this->input('password','Password','password').'<button class="w-full rounded-2xl bg-slate-950 px-4 py-3 font-bold text-white">Log in</button><p class="text-center text-sm text-slate-500">New here? <a class="font-bold text-slate-900" href="'.$this->url('/register').'">Create account</a></p></form><div class="mt-6 rounded-2xl bg-amber-50 p-4 text-xs text-amber-800"><strong>First login:</strong> the packaged admin account is <code>0200000000</code> / <code>ChangeMe123!</code>. Change it before production.</div>');
        $this->render('Login', $body);
    }

    private function login(): void
    {
        $phone = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
        $stmt = $this->db->prepare("SELECT * FROM users WHERE phone=? LIMIT 1");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
            $this->flash('error','Invalid phone number or password.');
            $this->redirect('/login');
        }
        $_SESSION['user_id'] = (int)$user['id'];
        $this->user = $user;
        $this->redirect($user['role']==='admin'?'/admin':'/client/dashboard');
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

        $subtotal = (float)$package['price'];
        $discount = 0.0;
        $couponCode = strtoupper(trim($_POST['coupon_code'] ?? ''));
        if ($couponCode) {
            $coupon = $this->validCoupon($couponCode);
            if (!$coupon) {
                $this->flash('error','Coupon is invalid, expired or unavailable.');
                $this->redirect('/packages');
            }
            $discount = $coupon['type']==='fixed' ? min($subtotal,(float)$coupon['value']) : $subtotal*((float)$coupon['value']/100);
            $this->db->prepare("UPDATE coupons SET uses=uses+1 WHERE id=?")->execute([$coupon['id']]);
        }
        $total = max(0,$subtotal-$discount);
        $deposit = round($total*((float)$package['deposit_percent']/100),2);
        $bookingCode = 'BK-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $now = $this->now();

        $stmt = $this->db->prepare("INSERT INTO bookings (booking_code,user_id,package_id,event_type,event_date,event_location,notes,subtotal,discount,total,deposit_required,coupon_code,payment_status,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $bookingCode,$this->user['id'],$packageId,
            $eventType,trim($_POST['event_date'] ?? ''),trim($_POST['event_location'] ?? ''),trim($_POST['notes'] ?? ''),
            $subtotal,$discount,$total,$deposit,$couponCode?:null,'unpaid','awaiting_payment',$now,$now
        ]);
        $bookingId = (int)$this->db->lastInsertId();
        $this->seedTimeline($bookingId, (int)$package['turnaround_days'], trim($_POST['event_date'] ?? ''));
        $this->flash('success','Booking created. Use the payment reference shown below.');
        $this->redirect('/client/booking?id='.$bookingId);
    }

    private function seedTimeline(int $bookingId, int $turnaroundDays, string $eventDate): void
    {
        $base = $eventDate && strtotime($eventDate) ? strtotime($eventDate) : time();
        $steps = [
            ['Booking & deposit','Waiting for your initial payment to be verified.','pending',date('Y-m-d')],
            ['Contract & preparation','Booking terms, expectations and shoot preparation.','pending',date('Y-m-d', strtotime('+1 day'))],
            ['Shoot / event','Photography session or event coverage.','pending',$eventDate ?: null],
            ['Selection & editing','Culling, editing and retouching according to your package.','pending',date('Y-m-d', $base + max(1,$turnaroundDays-3)*86400)],
            ['Final delivery','Your completed soft copies become available in your portal.','pending',date('Y-m-d', $base + $turnaroundDays*86400)],
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
        if (!$latest) $latest = $this->emptyState('No bookings yet','Choose a package to start your first photography booking.','/packages','View packages');
        $body = $this->clientShell('Overview',
            '<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">'.$this->stat('Bookings',(string)$active).$this->stat('Paid',$this->money($paid)).$this->stat('Files',(string)$this->clientFileCount()).$this->stat('Account','Active').'</div>'.
            '<div class="mt-7 flex items-center justify-between"><h2 class="text-lg font-black">Recent bookings</h2><a href="'.$this->url('/packages').'" class="rounded-xl bg-slate-950 px-3 py-2 text-xs font-bold text-white">New booking</a></div><div class="mt-3 space-y-3">'.$latest.'</div>'
        );
        $this->render('Client dashboard',$body,['portal'=>'client']);
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
        if (!$booking) $this->notFound(); return;

        $paid = $this->bookingPaid((int)$booking['id']);
        $balance = max(0,(float)$booking['total']-$paid);
        $timeline = $this->timelineHtml((int)$booking['id']);
        $paymentBlock = '';
        if ($booking['payment_status'] === 'unpaid' || $booking['payment_status'] === 'partial' || $balance > 0.01) {
            $nextAmount = $paid < (float)$booking['deposit_required'] ? max(0,(float)$booking['deposit_required']-$paid) : $balance;
            $paymentBlock = $this->paymentInstructions($booking,$nextAmount,$paid<=0?'deposit':'balance');
        }

        $contract = '';
        if ($booking['payment_status'] !== 'unpaid' && !(int)$booking['contract_accepted']) {
            $contractText = $this->setting('contract_text');
            $contract = '<div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Contract & expectations</h3><div class="mt-3 max-h-48 overflow-auto rounded-2xl bg-slate-50 p-4 text-sm leading-6 text-slate-700">'.nl2br(htmlspecialchars($contractText)).'</div><form method="post" action="'.$this->url('/client/contract-accept').'" class="mt-4">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$booking['id'].'"><label class="flex items-start gap-3 text-sm"><input required type="checkbox" class="mt-1" name="agree" value="1"><span>I have read and accept the booking terms and expectations.</span></label><button class="mt-4 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Accept contract</button></form></div>';
        } elseif ((int)$booking['contract_accepted']) {
            $contract = '<div class="rounded-3xl border border-emerald-100 bg-emerald-50 p-5 text-sm text-emerald-800"><strong>Contract accepted.</strong> Accepted on '.htmlspecialchars((string)$booking['contract_accepted_at']).'.</div>';
        }

        $body = $this->clientShell('Booking '.$booking['booking_code'],
            '<div class="grid lg:grid-cols-[1.3fr_.7fr] gap-5"><div class="space-y-5"><div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-wider text-slate-400">'.$booking['booking_code'].'</p><h2 class="mt-1 text-xl font-black">'.htmlspecialchars($booking['package_name']).'</h2><p class="mt-2 text-sm text-slate-600">'.htmlspecialchars($booking['event_type'] ?: 'Photography booking').' · '.htmlspecialchars($booking['event_date'] ?: 'Date to be confirmed').'</p></div>'.$this->badge($booking['status']).'</div><div class="mt-5 grid grid-cols-3 gap-3">'.$this->mini('Total',$this->money((float)$booking['total'])).$this->mini('Paid',$this->money($paid)).$this->mini('Balance',$this->money($balance)).'</div></div>'.$paymentBlock.$contract.'</div><aside><div class="rounded-3xl border border-slate-200 bg-white p-5 sticky top-24"><h3 class="font-black">Project timeline</h3><div class="mt-5">'.$timeline.'</div></div></aside></div>'
        );
        $this->render('Booking',$body,['portal'=>'client']);
    }

    private function submitPayment(): void
    {
        $this->requireRole('client');
        $booking = $this->bookingById((int)($_POST['booking_id'] ?? 0),(int)$this->user['id']);
        if (!$booking) $this->notFound(); return;
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
        $this->flash('success','Payment submitted for verification. You will be notified after approval.');
        $this->redirect('/client/booking?id='.$booking['id']);
    }

    private function acceptContract(): void
    {
        $this->requireRole('client');
        $booking = $this->bookingById((int)($_POST['booking_id'] ?? 0),(int)$this->user['id']);
        if (!$booking) $this->notFound(); return;
        if (($_POST['agree'] ?? '') !== '1') {
            $this->flash('error','You must accept the agreement to continue.');
            $this->redirect('/client/booking?id='.$booking['id']);
        }
        $this->db->prepare("UPDATE bookings SET contract_accepted=1,contract_accepted_at=?,updated_at=? WHERE id=?")->execute([$this->now(),$this->now(),$booking['id']]);
        $this->flash('success','Contract accepted. Your booking record has been updated.');
        $this->redirect('/client/booking?id='.$booking['id']);
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
        if (!$file) $this->notFound(); return;
        $path = __DIR__ . '/../storage/uploads/deliveries/' . basename($file['stored_name']);
        if (!is_file($path)) $this->notFound(); return;
        header('Content-Type: '.($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: attachment; filename="'.str_replace('"','', $file['original_name']).'"');
        readfile($path);
        exit;
    }

    private function clientProfile(): void
    {
        $this->requireRole('client');
        $form = '<form method="post" action="'.$this->url('/client/profile').'" class="space-y-4 max-w-xl">'.$this->csrfField().$this->input('first_name','First name','text',$this->user['first_name']).$this->input('last_name','Last name','text',$this->user['last_name']).$this->input('email','Email','email',$this->user['email'] ?? '').'<div><label class="text-sm font-bold">Phone number</label><input disabled value="'.htmlspecialchars($this->user['phone']).'" class="mt-1 w-full rounded-2xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-500"></div><button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Save profile</button></form>';
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
        $stmt = $this->db->query("SELECT b.*,p.name package_name,u.first_name,u.last_name FROM bookings b JOIN packages p ON p.id=b.package_id JOIN users u ON u.id=b.user_id ORDER BY b.id DESC LIMIT 6");
        $rows = '';
        foreach ($stmt->fetchAll() as $b) $rows .= $this->bookingRow($b,true);
        $body = $this->adminShell('Dashboard',
            '<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">'.$this->stat('Revenue',$this->money($revenue)).$this->stat('Bookings',(string)$bookings).$this->stat('Clients',(string)$clients).$this->stat('Pending payments',(string)$pending).'</div><div class="mt-7 flex items-center justify-between"><h2 class="text-lg font-black">Recent bookings</h2><a href="'.$this->url('/admin/bookings').'" class="text-sm font-bold">View all →</a></div><div class="mt-3 space-y-3">'.$rows.'</div>'
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
        if (!$b) $this->notFound(); return;

        $payments = $this->db->prepare("SELECT * FROM payments WHERE booking_id=? ORDER BY id DESC");
        $payments->execute([$id]);
        $paymentRows = '';
        foreach ($payments->fetchAll() as $p) {
            $actions = '';
            if ($p['status']==='pending') {
                $actions = '<div class="mt-3 flex gap-2"><form method="post" action="'.$this->url('/admin/payment-verify').'">'.$this->csrfField().'<input type="hidden" name="payment_id" value="'.$p['id'].'"><button class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Verify</button></form><form method="post" action="'.$this->url('/admin/payment-reject').'">'.$this->csrfField().'<input type="hidden" name="payment_id" value="'.$p['id'].'"><button class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">Reject</button></form></div>';
            }
            $paymentRows .= '<div class="rounded-2xl border border-slate-200 p-4"><div class="flex justify-between gap-3"><div><p class="font-bold">'.$this->money((float)$p['amount']).'</p><p class="text-xs text-slate-500">MTN '.$p['sender_number'].' · '.$p['momo_reference'].'</p></div>'.$this->badge($p['status']).'</div>'.$actions.'</div>';
        }

        $timeline = $this->timelineHtml($id,true);
        $body = $this->adminShell('Booking '.$b['booking_code'],
            '<div class="grid xl:grid-cols-[1.1fr_.9fr] gap-5"><div class="space-y-5"><div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-bold text-slate-400">'.$b['booking_code'].'</p><h2 class="mt-1 text-xl font-black">'.htmlspecialchars($b['first_name'].' '.$b['last_name']).'</h2><p class="mt-1 text-sm text-slate-600">'.htmlspecialchars($b['package_name']).' · '.htmlspecialchars($b['phone']).'</p></div>'.$this->badge($b['status']).'</div><div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3">'.$this->mini('Total',$this->money((float)$b['total'])).$this->mini('Paid',$this->money($this->bookingPaid($id))).$this->mini('Event',$b['event_date'] ?: 'TBC').$this->mini('Contract',(int)$b['contract_accepted']?'Accepted':'Pending').'</div></div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Update booking</h3><form method="post" action="'.$this->url('/admin/booking-status').'" class="mt-4 flex flex-col sm:flex-row gap-3">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$id.'"><select name="status" class="rounded-xl border border-slate-200 px-4 py-3 text-sm"><option value="awaiting_payment">Awaiting payment</option><option value="confirmed">Confirmed</option><option value="scheduled">Scheduled</option><option value="shoot_completed">Shoot completed</option><option value="editing">Editing</option><option value="ready">Ready</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select><button class="rounded-xl bg-slate-950 px-4 py-3 text-sm font-bold text-white">Update status</button></form></div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Payments</h3><div class="mt-4 space-y-3">'.($paymentRows ?: '<p class="text-sm text-slate-500">No payment submitted.</p>').'</div></div></div><aside class="space-y-5"><div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Timeline</h3><div class="mt-4">'.$timeline.'</div><form method="post" action="'.$this->url('/admin/timeline-add').'" class="mt-5 space-y-3">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$id.'">'.$this->input('title','New timeline step').$this->input('due_date','Due date','date').'<button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Add step</button></form></div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h3 class="font-black">Deliver soft copies</h3><p class="mt-1 text-sm text-slate-500">Files uploaded here are protected and only this client can download them.</p><form method="post" enctype="multipart/form-data" action="'.$this->url('/admin/file-upload').'" class="mt-4 space-y-3">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$id.'"><input required type="file" name="delivery_file" class="block w-full text-sm"><button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Upload file</button></form></div></aside></div>'
        );
        $this->render('Booking admin',$body,['portal'=>'admin']);
    }

    private function adminPayments(): void
    {
        $this->requireRole('admin');
        $stmt = $this->db->query("SELECT pay.*,b.booking_code,u.first_name,u.last_name FROM payments pay JOIN bookings b ON b.id=pay.booking_id JOIN users u ON u.id=b.user_id ORDER BY CASE pay.status WHEN 'pending' THEN 0 ELSE 1 END,pay.id DESC");
        $rows='';
        foreach($stmt->fetchAll() as $p){
            $actions = $p['status']==='pending' ? '<div class="mt-3 flex gap-2"><form method="post" action="'.$this->url('/admin/payment-verify').'">'.$this->csrfField().'<input type="hidden" name="payment_id" value="'.$p['id'].'"><button class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Verify</button></form><form method="post" action="'.$this->url('/admin/payment-reject').'">'.$this->csrfField().'<input type="hidden" name="payment_id" value="'.$p['id'].'"><button class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">Reject</button></form></div>' : '';
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
        if(!$p) $this->notFound(); return;
        $this->db->prepare("UPDATE payments SET status='verified',verified_at=?,verified_by=? WHERE id=?")->execute([$this->now(),$this->user['id'],$id]);
        $paid=$this->bookingPaid((int)$p['booking_id']);
        $b=$this->bookingById((int)$p['booking_id']);
        $status = $paid + 0.001 >= (float)$b['total'] ? 'paid' : 'partial';
        $bookingStatus = $paid + 0.001 >= (float)$b['deposit_required'] ? 'confirmed' : 'awaiting_payment';
        $this->db->prepare("UPDATE bookings SET payment_status=?,status=?,updated_at=? WHERE id=?")->execute([$status,$bookingStatus,$this->now(),$p['booking_id']]);
        if($bookingStatus==='confirmed'){
            $this->db->prepare("UPDATE timeline SET status='completed',completed_at=? WHERE booking_id=? AND sort_order=1")->execute([$this->now(),$p['booking_id']]);
        }
        $this->sendSms($p['phone'],"Hi {$p['first_name']}, your payment of ".$this->money((float)$p['amount'])." for {$p['booking_code']} has been verified. Log in to continue your booking.");
        $this->flash('success','Payment verified and client notification processed.');
        $this->redirect('/admin/booking?id='.$p['booking_id']);
    }

    private function rejectPayment(): void
    {
        $this->requireRole('admin');
        $id=(int)($_POST['payment_id']??0);
        $stmt=$this->db->prepare("SELECT pay.*,b.id booking_id,u.phone,u.first_name FROM payments pay JOIN bookings b ON b.id=pay.booking_id JOIN users u ON u.id=b.user_id WHERE pay.id=?");
        $stmt->execute([$id]); $p=$stmt->fetch();
        if(!$p)$this->notFound(); return;
        $this->db->prepare("UPDATE payments SET status='rejected',verified_at=?,verified_by=? WHERE id=?")->execute([$this->now(),$this->user['id'],$id]);
        $this->sendSms($p['phone'],"Hi {$p['first_name']}, we could not verify your submitted MoMo payment. Please check the transaction reference and resubmit from your portal.");
        $this->flash('success','Payment rejected.');
        $this->redirect('/admin/booking?id='.$p['booking_id']);
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
        $this->redirect('/admin/booking?id='.$id);
    }

    private function addTimeline(): void
    {
        $this->requireRole('admin');
        $id=(int)($_POST['booking_id']??0);
        $order=(int)$this->db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM timeline WHERE booking_id=".$id)->fetchColumn();
        $this->db->prepare("INSERT INTO timeline (booking_id,title,description,status,due_date,sort_order,created_at) VALUES (?,?,?,?,?,?,?)")->execute([$id,trim($_POST['title']??'Milestone'),'','pending',trim($_POST['due_date']??''),$order,$this->now()]);
        $this->flash('success','Timeline step added.');
        $this->redirect('/admin/booking?id='.$id);
    }

    private function uploadDelivery(): void
    {
        $this->requireRole('admin');
        $id=(int)($_POST['booking_id']??0);
        if(!isset($_FILES['delivery_file']) || $_FILES['delivery_file']['error']!==UPLOAD_ERR_OK) {
            $this->flash('error','File upload failed.');
            $this->redirect('/admin/booking?id='.$id);
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
        $this->redirect('/admin/booking?id='.$id);
    }

    private function adminPackages(): void
    {
        $this->requireRole('admin');
        $rows='';
        foreach($this->db->query("SELECT * FROM packages ORDER BY active DESC, CASE category WHEN 'wedding' THEN 1 WHEN 'baby' THEN 2 WHEN 'studio' THEN 3 ELSE 4 END, price ASC")->fetchAll() as $p){
            $cat=ucwords(str_replace('_',' ',(string)($p['category']??'wedding')));
            $rows.='<div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">'.$cat.'</p><p class="font-black">'.htmlspecialchars($p['name']).'</p><p class="text-sm text-slate-500">'.$this->money((float)$p['price']).' · '.(int)$p['deposit_percent'].'% deposit · '.(int)$p['turnaround_days'].' days</p></div>'.$this->badge((int)$p['active']?'active':'inactive').'</div><form method="post" action="'.$this->url('/admin/package-delete').'" class="mt-3">'.$this->csrfField().'<input type="hidden" name="id" value="'.$p['id'].'"><button class="text-xs font-bold text-red-600">Deactivate</button></form></div>';
        }
        $form='<form method="post" action="'.$this->url('/admin/package-save').'" class="grid md:grid-cols-2 gap-4">'.$this->csrfField().$this->input('name','Package name').'<div><label class="text-sm font-bold">Category</label><select name="category" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3"><option value="wedding">Wedding & Engagement</option><option value="baby">Baby Dedication & Christening</option><option value="studio">Studio Shoot</option></select></div>'.$this->input('price','Price','number').$this->input('deposit_percent','Initial payment %','number','50').$this->input('turnaround_days','Turnaround days','number','14').'<div class="md:col-span-2"><label class="text-sm font-bold">Description</label><textarea name="description" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></textarea></div><div class="md:col-span-2"><label class="text-sm font-bold">Deliverables, one per line</label><textarea name="deliverables" rows="5" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></textarea></div><div class="md:col-span-2"><button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Add package</button></div></form>';
        $this->render('Packages',$this->adminShell('Packages','<div class="grid xl:grid-cols-[.9fr_1.1fr] gap-5"><div class="space-y-3">'.$rows.'</div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h2 class="font-black">Create package</h2><div class="mt-4">'.$form.'</div></div></div>'),['portal'=>'admin']);
    }

    private function savePackage(): void
    {
        $this->requireRole('admin');
        $name=trim($_POST['name']??'');
        if(!$name) throw new RuntimeException('Package name is required.');
        $slug=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$name),'-')).'-'.substr(bin2hex(random_bytes(2)),0,3);
        $category=in_array($_POST['category']??'',['wedding','baby','studio'],true)?(string)$_POST['category']:'wedding';
        $this->db->prepare("INSERT INTO packages (name,slug,category,description,price,deposit_percent,turnaround_days,deliverables,active,created_at) VALUES (?,?,?,?,?,?,?,?,1,?)")->execute([$name,$slug,$category,trim($_POST['description']??''),(float)($_POST['price']??0),(float)($_POST['deposit_percent']??50),(int)($_POST['turnaround_days']??14),trim($_POST['deliverables']??''),$this->now()]);
        $this->flash('success','Package added.');
        $this->redirect('/admin/packages');
    }

    private function deletePackage(): void
    {
        $this->requireRole('admin');
        $this->db->prepare("UPDATE packages SET active=0 WHERE id=?")->execute([(int)($_POST['id']??0)]);
        $this->flash('success','Package deactivated.');
        $this->redirect('/admin/packages');
    }

    private function adminCoupons(): void
    {
        $this->requireRole('admin');
        $rows='';
        foreach($this->db->query("SELECT * FROM coupons ORDER BY id DESC")->fetchAll() as $c){
            $value=$c['type']==='percent' ? rtrim(rtrim(number_format((float)$c['value'],2), '0'),'.').'%' : $this->money((float)$c['value']);
            $rows.='<div class="rounded-3xl border border-slate-200 bg-white p-5"><div class="flex justify-between gap-3"><div><p class="font-black">'.$c['code'].'</p><p class="text-sm text-slate-500">'.$value.' off · '.$c['uses'].' uses'.($c['max_uses']?' / '.$c['max_uses']:'').'</p></div>'.$this->badge((int)$c['active']?'active':'inactive').'</div><form method="post" action="'.$this->url('/admin/coupon-toggle').'" class="mt-3">'.$this->csrfField().'<input type="hidden" name="id" value="'.$c['id'].'"><button class="text-xs font-bold text-slate-700">Toggle status</button></form></div>';
        }
        if(!$rows)$rows='<p class="text-sm text-slate-500">No coupons created.</p>';
        $form='<form method="post" action="'.$this->url('/admin/coupon-save').'" class="grid sm:grid-cols-2 gap-4">'.$this->csrfField().$this->input('code','Coupon code').'<div><label class="text-sm font-bold">Discount type</label><select name="type" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"><option value="percent">Percentage</option><option value="fixed">Fixed amount</option></select></div>'.$this->input('value','Discount value','number').$this->input('max_uses','Maximum uses (0 = unlimited)','number','0').$this->input('expires_at','Expiry date','date').'<div class="sm:col-span-2"><button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Create coupon</button></div></form>';
        $this->render('Coupons',$this->adminShell('Coupons','<div class="grid xl:grid-cols-[.9fr_1.1fr] gap-5"><div class="space-y-3">'.$rows.'</div><div class="rounded-3xl border border-slate-200 bg-white p-5"><h2 class="font-black">New coupon</h2><div class="mt-4">'.$form.'</div></div></div>'),['portal'=>'admin']);
    }

    private function saveCoupon(): void
    {
        $this->requireRole('admin');
        $code=strtoupper(trim($_POST['code']??''));
        if(!$code) throw new RuntimeException('Coupon code is required.');
        try{
            $this->db->prepare("INSERT INTO coupons (code,type,value,max_uses,uses,expires_at,active,created_at) VALUES (?,?,?,?,0,?,1,?)")->execute([$code,($_POST['type']??'percent')==='fixed'?'fixed':'percent',(float)($_POST['value']??0),(int)($_POST['max_uses']??0),trim($_POST['expires_at']??'')?:null,$this->now()]);
        }catch(PDOException $e){$this->flash('error','Coupon code already exists.');$this->redirect('/admin/coupons');}
        $this->flash('success','Coupon created.');
        $this->redirect('/admin/coupons');
    }

    private function toggleCoupon(): void
    {
        $this->requireRole('admin');
        $this->db->prepare("UPDATE coupons SET active=CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?")->execute([(int)($_POST['id']??0)]);
        $this->redirect('/admin/coupons');
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

    private function adminSettings(): void
    {
        $this->requireRole('admin');
        $form='<form method="post" action="'.$this->url('/admin/settings').'" class="space-y-4 max-w-2xl">'.$this->csrfField().'<div><label class="text-sm font-bold">Contract text</label><textarea name="contract_text" rows="9" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3">'.htmlspecialchars($this->setting('contract_text')).'</textarea></div><div><label class="text-sm font-bold">Client note</label><textarea name="studio_note" rows="4" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3">'.htmlspecialchars($this->setting('studio_note')).'</textarea></div><button class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Save settings</button></form>';
        $this->render('Settings',$this->adminShell('Settings',$form),['portal'=>'admin']);
    }

    private function saveSettings(): void
    {
        $this->requireRole('admin');
        $stmt=$this->db->prepare("INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
        foreach(['contract_text','studio_note'] as $key)$stmt->execute([$key,trim($_POST[$key]??'')]);
        $this->flash('success','Settings saved.');
        $this->redirect('/admin/settings');
    }

    private function paymentInstructions(array $b,float $amount,string $type): string
    {
        $ref=$b['booking_code'];
        return '<div class="rounded-3xl border border-amber-200 bg-amber-50 p-5"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-wider text-amber-700">MTN Mobile Money</p><h3 class="mt-1 font-black text-amber-950">Send '.$this->money($amount).'</h3></div><span class="rounded-xl bg-white px-3 py-2 text-xs font-bold text-amber-900">Manual verification</span></div><div class="mt-4 grid sm:grid-cols-3 gap-3 text-sm">'.$this->mini('Number',$this->config['momo_number']??'').$this->mini('Account',$this->config['momo_account_name']??'').$this->mini('Reference',$ref).'</div><p class="mt-4 text-xs leading-5 text-amber-800">Use <strong>'.$ref.'</strong> as your payment reference. After sending the money, submit the MoMo transaction ID below. The studio will verify it before your booking is activated.</p><form method="post" action="'.$this->url('/client/payment-submit').'" class="mt-4 grid sm:grid-cols-2 gap-3">'.$this->csrfField().'<input type="hidden" name="booking_id" value="'.$b['id'].'"><input type="hidden" name="payment_type" value="'.$type.'">'.$this->input('amount','Amount sent','number',number_format($amount,2,'.','')).$this->input('sender_number','MTN number used','tel',$this->user['phone']).$this->input('momo_reference','MoMo transaction/reference ID').'<div class="sm:col-span-2"><button class="rounded-xl bg-amber-900 px-4 py-2.5 text-sm font-bold text-white">Submit payment for verification</button></div></form></div>';
    }

    private function timelineHtml(int $bookingId,bool $admin=false): string
    {
        $stmt=$this->db->prepare("SELECT * FROM timeline WHERE booking_id=? ORDER BY sort_order,id");
        $stmt->execute([$bookingId]);$html='';
        foreach($stmt->fetchAll() as $t){
            $done=$t['status']==='completed';
            $html.='<div class="relative pl-8 pb-6 last:pb-0"><div class="absolute left-[7px] top-4 bottom-0 w-px bg-slate-200"></div><div class="absolute left-0 top-1 h-4 w-4 rounded-full '.($done?'bg-emerald-500':'bg-slate-200').' border-4 border-white ring-1 ring-slate-200"></div><p class="text-sm font-black">'.htmlspecialchars($t['title']).'</p><p class="mt-1 text-xs leading-5 text-slate-500">'.htmlspecialchars($t['description']??'').'</p>'.($t['due_date']?'<p class="mt-1 text-[11px] font-bold text-slate-400">Target: '.$t['due_date'].'</p>':'').'</div>';
        }
        return $html ?: '<p class="text-sm text-slate-500">No timeline yet.</p>';
    }

    private function bookingRow(array $b,bool $admin): string
    {
        $name=$admin && isset($b['first_name']) ? '<p class="text-xs text-slate-500">'.htmlspecialchars($b['first_name'].' '.$b['last_name']).'</p>' : '';
        $href=$admin?'/admin/booking?id='.$b['id']:'/client/booking?id='.$b['id'];
        return '<a href="'.$this->url($href).'" class="block rounded-3xl border border-slate-200 bg-white p-5 hover:border-slate-300"><div class="flex items-start justify-between gap-4"><div class="min-w-0"><p class="font-black truncate">'.htmlspecialchars($b['package_name']).'</p>'.$name.'<p class="mt-1 text-xs text-slate-500">'.$b['booking_code'].' · '.htmlspecialchars($b['event_date'] ?: 'Date to be confirmed').'</p></div><div class="text-right shrink-0">'.$this->badge($b['status']).'<p class="mt-2 text-sm font-black">'.$this->money((float)$b['total']).'</p></div></div></a>';
    }

    private function validCoupon(string $code): ?array
    {
        $stmt=$this->db->prepare("SELECT * FROM coupons WHERE code=? AND active=1 LIMIT 1");$stmt->execute([$code]);$c=$stmt->fetch();
        if(!$c)return null;
        if($c['expires_at'] && strtotime($c['expires_at'].' 23:59:59')<time())return null;
        if((int)$c['max_uses']>0 && (int)$c['uses']>=(int)$c['max_uses'])return null;
        return $c;
    }

    private function bookingById(int $id,?int $userId=null): ?array
    {
        $sql="SELECT b.*,p.name package_name,p.deposit_percent,p.turnaround_days FROM bookings b JOIN packages p ON p.id=b.package_id WHERE b.id=?";
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

    private function sendSms(string $phone,string $message): void
    {
        $sms=$this->config['sms']??['driver'=>'log'];
        if(($sms['driver']??'log')==='webhook' && !empty($sms['webhook_url'])){
            $payload=json_encode(['to'=>$phone,'message'=>$message,'sender'=>$sms['sender']??'LensFlow']);
            $ch=curl_init($sms['webhook_url']);
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.($sms['api_key']??'')],CURLOPT_TIMEOUT=>10]);
            @curl_exec($ch);@curl_close($ch);
            return;
        }
        $line='['.$this->now().'] '.$phone.' | '.$message.PHP_EOL;
        @file_put_contents(__DIR__.'/../storage/logs/sms.log',$line,FILE_APPEND);
    }

    private function setting(string $key): string
    {
        $stmt=$this->db->prepare("SELECT value FROM settings WHERE key=?");$stmt->execute([$key]);return (string)($stmt->fetchColumn()?:'');
    }

    private function currentUser(): ?array
    {
        if(empty($_SESSION['user_id'])) return null;
        $stmt=$this->db->prepare("SELECT * FROM users WHERE id=?");$stmt->execute([(int)$_SESSION['user_id']]);$u=$stmt->fetch();return $u?:null;
    }

    private function requireRole(string $role): void
    {
        if(!$this->user){$this->flash('error','Please log in first.');$this->redirect('/login');}
        if($this->user['role']!==$role){http_response_code(403);exit('Forbidden');}
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
        $nav = $isPortal ? '' : $this->topNav(false);
        $flashBlock = $flashHtml === '' ? '' : ($isPortal
            ? '<div class="px-3 pt-2">'.$flashHtml.'</div>'
            : '<div class="max-w-6xl mx-auto px-4 pt-4">'.$flashHtml.'</div>');
        $appName=htmlspecialchars($this->config['app_name']??'LensFlow');
        if ($isHome) {
            $bodyClass = 'home-lock bg-[#f7f6f3] text-stone-900 antialiased';
        } elseif ($isPortal) {
            $bodyClass = 'portal-app bg-[#f4f4f2] text-stone-900 antialiased';
        } else {
            $bodyClass = 'bg-stone-50 text-stone-900 antialiased';
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f7f6f3"><title>'.htmlspecialchars($title).' · '.$appName.'</title><link rel="icon" href="'.$this->url('/assets/favicon.svg').'" type="image/svg+xml"><link rel="apple-touch-icon" href="'.$this->url('/assets/favicon.svg').'"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet"><script src="https://cdn.tailwindcss.com"></script><style>
html{scroll-behavior:smooth}
body{font-family:"Outfit",ui-sans-serif,system-ui,sans-serif}
body.home-lock{height:100svh;overflow:hidden}
.font-display{font-family:"Cormorant Garamond",ui-serif,Georgia,serif}
.safe-bottom{padding-bottom:env(safe-area-inset-bottom)}
#site-menu:checked~label[for=site-menu] .icon-open{display:none}
#site-menu:checked~label[for=site-menu] .icon-close{display:block}
#site-menu:checked~.mobile-panel{display:block}
.icon-close{display:none}
.mobile-panel{display:none}
.site-nav-link{position:relative;display:inline-flex;align-items:center;gap:.4rem;padding:.35rem 0;font-size:.84rem;font-weight:600;letter-spacing:.01em;color:#78716c;text-decoration:none;transition:color .22s ease}
.site-nav-link:hover,.site-nav-link:focus-visible{color:#1c1917}
.site-nav-link::after{content:"";position:absolute;left:0;right:0;bottom:0;height:1.5px;border-radius:999px;background:#1c1917;transform:scaleX(0);transform-origin:left center;transition:transform .28s cubic-bezier(.22,1,.36,1)}
.site-nav-link:hover::after,.site-nav-link:focus-visible::after{transform:scaleX(1)}
.site-nav-ico{width:1rem;height:1rem;opacity:.7;transition:opacity .22s ease,transform .28s cubic-bezier(.22,1,.36,1)}
.site-nav-link:hover .site-nav-ico{opacity:1;transform:translateY(-1px)}
.mobile-nav-link{display:flex;align-items:center;gap:.85rem;border-radius:1rem;padding:.9rem 1rem;font-size:.98rem;font-weight:600;color:#1c1917;text-decoration:none;transition:background .2s ease,transform .2s ease}
.mobile-nav-link:hover{background:#f5f5f4}
.mobile-nav-link:active{transform:scale(.99)}
.mobile-nav-ico{display:grid;place-items:center;width:2.25rem;height:2.25rem;border-radius:.85rem;background:#1c1917;color:#fafaf9;flex-shrink:0}
.mobile-nav-meta{display:block;margin-top:.15rem;font-size:.72rem;font-weight:600;color:#a8a29e}
@media (prefers-reduced-motion:reduce){
  .site-nav-link::after,.site-nav-ico,.mobile-nav-link{transition:none}
}
.clean-home{height:calc(100svh - 4.25rem);display:grid;grid-template-rows:minmax(0,1.08fr) minmax(0,1fr);grid-template-areas:"visual" "copy";background:#f7f6f3;overflow:hidden}
.clean-visual{grid-area:visual;position:relative;min-height:0;overflow:hidden;background:#ebe8e2}
.clean-visual-frame{position:absolute;inset:0;overflow:hidden;clip-path:inset(2% 0 3% 0 round 0);opacity:0;animation:hero-reveal 1s cubic-bezier(.22,1,.36,1) forwards;box-shadow:none}
.clean-visual-img{width:100%;height:100%;object-fit:cover;object-position:center 28%;display:block;will-change:transform;transform:scale(1.08);animation:hero-zoom 36s ease-in-out infinite;filter:saturate(1.05) contrast(1.03);backface-visibility:hidden}
.clean-visual-fade{position:absolute;inset:auto 0 0 0;height:42%;background:linear-gradient(to top,#f7f6f3 12%,rgba(247,246,243,.7) 48%,transparent);pointer-events:none;z-index:1}
.clean-wrap{grid-area:copy;min-height:0;display:flex;flex-direction:column;justify-content:center;padding:.15rem 1.25rem calc(.85rem + env(safe-area-inset-bottom));max-width:28rem;margin:0 auto;width:100%;animation:clean-up .65s ease .1s both}
.clean-display{margin:0;font-family:"Cormorant Garamond",ui-serif,Georgia,serif;font-size:clamp(3.6rem,14vw,5.5rem);font-weight:600;letter-spacing:-.01em;line-height:.88;color:#1c1917}
.clean-title{margin:.55rem 0 0;font-size:clamp(1.05rem,4.2vw,1.25rem);font-weight:600;letter-spacing:-.02em;line-height:1.3;color:#44403c}
.clean-lead{margin:.5rem 0 0;font-size:.86rem;line-height:1.5;color:#78716c;max-width:22rem}
.clean-actions{margin-top:.9rem}
.clean-btn{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:.7rem 1.15rem;font-size:.84rem;font-weight:700;transition:transform .15s ease}
.clean-btn:active{transform:scale(.98)}
.clean-btn-primary{background:#1c1917;color:#fff}
.clean-cats{margin-top:1.05rem;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.45rem}
.clean-cat{display:flex;flex-direction:column;align-items:center;gap:.35rem;padding:.65rem .3rem .6rem;border-radius:1rem;border:1px solid #e7e5e4;background:#fff;color:#1c1917;text-decoration:none;opacity:0;transform:translateY(14px) scale(.96);animation:cat-in .55s cubic-bezier(.22,1,.36,1) calc(.28s + (var(--cat-i,1) * .08s)) forwards;transition:border-color .25s ease,box-shadow .25s ease,translate .25s cubic-bezier(.22,1,.36,1),background .25s ease}
.clean-cat:hover{border-color:#d6d3d1;background:#fafaf9;box-shadow:0 10px 24px rgba(28,25,23,.08);translate:0 -3px}
.clean-cat:active{translate:0 -1px;transition-duration:.1s}
.clean-cat-icon{display:grid;place-items:center;width:2.25rem;height:2.25rem;border-radius:999px;background:#1c1917;color:#fafaf9;transition:transform .35s cubic-bezier(.22,1,.36,1),background .25s ease}
.clean-cat:hover .clean-cat-icon{transform:scale(1.08) rotate(-4deg);background:#292524}
.clean-cat-label{font-size:.7rem;font-weight:700;letter-spacing:.01em;transition:color .25s ease}
.clean-cat:hover .clean-cat-label{color:#0c0a09}
@keyframes cat-in{from{opacity:0;transform:translateY(14px) scale(.96)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){
  .clean-cat{opacity:1;transform:none;animation:none;translate:none}
  .clean-cat:hover .clean-cat-icon{transform:none}
}
.clean-foot{margin:.85rem 0 0;display:flex;align-items:center;gap:.45rem;font-size:.78rem;font-weight:600;color:#a8a29e}
.clean-foot a{color:#57534e;text-decoration:none}
@keyframes clean-up{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes hero-reveal{from{opacity:0;transform:translateY(18px) scale(1.03);filter:blur(6px)}to{opacity:1;transform:none;filter:blur(0)}}
@keyframes hero-zoom{0%{transform:scale(1.08) translate3d(0,0,0)}50%{transform:scale(1.2) translate3d(0,-1.8%,0)}100%{transform:scale(1.08) translate3d(0,0,0)}}
@media (prefers-reduced-motion:reduce){
  .clean-visual-frame,.clean-visual-img,.clean-wrap{animation:none!important;opacity:1;transform:none;filter:none;will-change:auto}
}
@media (min-width:768px){
  .clean-home{grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);grid-template-rows:1fr;grid-template-areas:"copy visual";max-width:72rem;margin:0 auto;padding:1rem 1rem 1rem 0;gap:0 1.25rem;align-items:stretch}
  .clean-visual{border-radius:0;overflow:visible;background:transparent}
  .clean-visual-frame{inset:.5rem .75rem .5rem .15rem;clip-path:inset(0 round 1.6rem);box-shadow:0 28px 60px rgba(28,25,23,.12)}
  .clean-visual-img{object-position:center 28%}
  .clean-visual-fade{inset:0 auto 0 0;width:26%;height:auto;background:linear-gradient(to right,#f7f6f3,rgba(247,246,243,.2) 55%,transparent);border-radius:1.5rem 0 0 1.5rem}
  .clean-wrap{padding:1.5rem 1rem 1.5rem 1.75rem;max-width:none;justify-content:center}
  .clean-display{font-size:5.75rem}
  .clean-title{font-size:1.2rem;margin-top:.7rem}
  .clean-lead{font-size:.95rem;margin-top:.55rem}
  .clean-actions{margin-top:1.1rem}
  .clean-cats{margin-top:1.35rem;max-width:22rem}
}
@media (max-height:700px){
  .clean-display{font-size:3.1rem}
  .clean-title{font-size:1rem;margin-top:.4rem}
  .clean-lead{font-size:.78rem;margin-top:.3rem}
  .clean-actions{margin-top:.7rem}
  .clean-cats{margin-top:.75rem}
  .clean-cat{padding:.5rem .2rem}
  .clean-cat-icon{width:2rem;height:2rem}
  .clean-foot{margin:.6rem 0 0}
}

body.portal-app{min-height:100svh}
.portal-shell{min-height:100svh;display:flex;flex-direction:column}
.portal-top{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:.75rem;height:3.25rem;padding:0 .85rem;border-bottom:1px solid #e7e5e4;background:rgba(255,255,255,.92);backdrop-filter:blur(10px)}
.portal-brand{display:flex;align-items:center;gap:.55rem;min-width:0;text-decoration:none;color:#1c1917}
.portal-brand-mark{display:grid;place-items:center;width:1.85rem;height:1.85rem;border-radius:.55rem;background:#1c1917;color:#fafaf9}
.portal-brand-name{font-family:"Cormorant Garamond",ui-serif,Georgia,serif;font-size:1.2rem;font-weight:600;letter-spacing:.02em;line-height:1}
.portal-brand-sub{display:block;margin-top:.1rem;font-size:.62rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#a8a29e}
.portal-top-actions{display:flex;align-items:center;gap:.4rem}
.portal-chip{display:inline-flex;align-items:center;border-radius:999px;border:1px solid #e7e5e4;background:#fff;padding:.35rem .7rem;font-size:.72rem;font-weight:700;color:#44403c;text-decoration:none}
.portal-body{display:flex;flex:1;min-height:0}
.portal-side{display:none;width:13.5rem;flex-shrink:0;border-right:1px solid #e7e5e4;background:#fff;padding:.85rem .65rem;position:sticky;top:3.25rem;height:calc(100svh - 3.25rem);overflow:auto}
.portal-side a{display:flex;align-items:center;gap:.55rem;border-radius:.7rem;padding:.55rem .7rem;font-size:.82rem;font-weight:600;color:#57534e;text-decoration:none}
.portal-side a:hover{background:#f5f5f4;color:#1c1917}
.portal-side a.is-active{background:#1c1917;color:#fff}
.portal-main{flex:1;min-width:0;padding:.85rem .85rem 4.75rem}
.portal-head{margin-bottom:.85rem}
.portal-kicker{margin:0;font-size:.65rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#a8a29e}
.portal-title{margin:.2rem 0 0;font-size:1.35rem;font-weight:800;letter-spacing:-.02em;color:#1c1917}
.portal-tabs{display:flex;gap:.4rem;overflow-x:auto;padding-bottom:.15rem;margin-bottom:.85rem;-webkit-overflow-scrolling:touch}
.portal-tabs a{flex:0 0 auto;border-radius:999px;border:1px solid #e7e5e4;background:#fff;padding:.4rem .75rem;font-size:.72rem;font-weight:700;color:#57534e;text-decoration:none}
.portal-tabs a.is-active{background:#1c1917;border-color:#1c1917;color:#fff}
.portal-bottom{position:fixed;left:0;right:0;bottom:0;z-index:40;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.15rem;padding:.35rem .45rem calc(.35rem + env(safe-area-inset-bottom));border-top:1px solid #e7e5e4;background:rgba(255,255,255,.96);backdrop-filter:blur(10px)}
.portal-bottom a{display:flex;flex-direction:column;align-items:center;gap:.15rem;padding:.35rem .2rem;border-radius:.65rem;font-size:.62rem;font-weight:700;color:#78716c;text-decoration:none}
.portal-bottom a.is-active{color:#1c1917;background:#f5f5f4}
.portal-bottom svg{width:1.15rem;height:1.15rem}
@media (min-width:1024px){
  .portal-side{display:block}
  .portal-tabs,.portal-bottom{display:none}
  .portal-main{padding:1rem 1.15rem 1.25rem}
  .portal-title{font-size:1.55rem}
}
</style></head><body class="'.$bodyClass.'">'.$nav.'<main>'.$flashBlock;
        $footer = ($isHome || $isPortal) ? '' : '<footer class="border-t border-stone-200 mt-16 bg-white"><div class="max-w-6xl mx-auto px-4 py-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"><div><p class="font-display text-2xl font-semibold tracking-wide text-stone-950">'.$appName.'</p><p class="mt-1 text-sm text-stone-500">Photography · Videography · Client portal</p></div><div class="text-sm text-stone-500 space-y-1 sm:text-right"><p>'.htmlspecialchars($this->config['momo_number']??'').'</p><p>© '.date('Y').' '.htmlspecialchars($this->config['photographer_name']??'Photography Studio').'</p></div></div></footer>';
        echo $content.'</main>'.$footer.'</body></html>';
    }

    private function topNav(bool $home = false): string
    {
        unset($home);
        $name=htmlspecialchars($this->config['app_name']??'LensFlow');
        $phone=htmlspecialchars($this->config['momo_number']??'');
        $phoneHref=preg_replace('/\s+/','',$this->config['momo_number']??'');
        $homeUrl=$this->url('/');
        $wedding=$this->url('/packages/wedding');
        $baby=$this->url('/packages/baby');
        $studio=$this->url('/packages/studio');
        $packages=$this->url('/packages');

        if(!$this->user){
            $authDesktop='<a href="'.$this->url('/login').'" class="hidden sm:inline text-sm font-semibold text-stone-600 hover:text-stone-950">Log in</a><a href="'.$this->url('/register').'" class="inline-flex items-center rounded-full bg-stone-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-800 transition">Book now</a>';
            $authMobile='<a href="'.$this->url('/login').'" class="block rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm font-semibold text-stone-800">Log in</a><a href="'.$this->url('/register').'" class="block rounded-2xl bg-stone-950 px-4 py-3 text-center text-sm font-semibold text-white">Book now</a>';
        }else{
            $portal=$this->user['role']==='admin'?'/admin':'/client/dashboard';
            $portalLabel=$this->user['role']==='admin'?'Admin':'My portal';
            $authDesktop='<a href="'.$this->url($portal).'" class="hidden sm:inline text-sm font-semibold text-stone-600 hover:text-stone-950">'.$portalLabel.'</a><a href="'.$this->url('/logout').'" class="inline-flex items-center rounded-full border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-stone-800 hover:border-stone-400 transition">Log out</a>';
            $authMobile='<a href="'.$this->url($portal).'" class="block rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm font-semibold text-stone-800">'.$portalLabel.'</a><a href="'.$this->url('/logout').'" class="block rounded-2xl bg-stone-950 px-4 py-3 text-center text-sm font-semibold text-white">Log out</a>';
        }

        $navIcon = function(string $kind): string {
            if ($kind === 'home') {
                return '<svg class="site-nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z"/></svg>';
            }
            if ($kind === 'packages') {
                return '<svg class="site-nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7.5h16M7 4.5h10M6 7.5v10.5A1.5 1.5 0 0 0 7.5 19.5h9a1.5 1.5 0 0 0 1.5-1.5V7.5"/><path d="M10 11.5h4M10 15h4"/></svg>';
            }
            return str_replace('class="h-5 w-5"', 'class="site-nav-ico"', $this->categoryIcon($kind, 'h-5 w-5'));
        };

        $navLink = function(string $href, string $label, string $kind = '') use ($navIcon) {
            $ico = $kind !== '' ? $navIcon($kind) : '';
            return '<a href="'.$href.'" class="site-nav-link">'.$ico.'<span>'.$label.'</span></a>';
        };

        $mobileNav = function(string $href, string $label, string $meta, string $kind) {
            if ($kind === 'packages') {
                $ico = '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 7.5h16M7 4.5h10M6 7.5v10.5A1.5 1.5 0 0 0 7.5 19.5h9a1.5 1.5 0 0 0 1.5-1.5V7.5"/><path d="M10 11.5h4M10 15h4"/></svg>';
            } elseif ($kind === 'home') {
                $ico = '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z"/></svg>';
            } else {
                $ico = $this->categoryIcon($kind, 'h-5 w-5');
            }
            return '<a href="'.$href.'" class="mobile-nav-link"><span class="mobile-nav-ico">'.$ico.'</span><span><span>'.$label.'</span><span class="mobile-nav-meta">'.$meta.'</span></span></a>';
        };

        $logo='<a href="'.$homeUrl.'" class="group flex items-center gap-3 min-w-0">
          <span class="grid h-10 w-10 place-items-center rounded-full border border-stone-200 bg-stone-950 text-stone-100 shadow-sm">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4.5 8.5h2.2l1.2-2h8.2l1.2 2H19.5A1.5 1.5 0 0 1 21 10v7.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5V10a1.5 1.5 0 0 1 1.5-1.5Z"/><circle cx="12" cy="13.5" r="3.2"/></svg>
          </span>
          <span class="min-w-0 leading-tight">
            <span class="font-display block text-[1.55rem] sm:text-[1.7rem] font-semibold tracking-[0.02em] text-stone-950 group-hover:text-stone-800 transition">'.$name.'</span>
            <span class="hidden sm:block text-[10px] font-semibold uppercase tracking-[0.22em] text-stone-500">Photography studio</span>
          </span>
        </a>';

        return '<header class="sticky top-0 z-50 border-b border-stone-200/90 bg-white/90 backdrop-blur-md">
          <div class="max-w-6xl mx-auto px-4">
            <div class="relative flex h-[4.25rem] items-center justify-between gap-4">
              '.$logo.'
              <nav class="hidden lg:flex items-center gap-7" aria-label="Primary">
                '.$navLink($wedding,'Weddings','wedding').'
                '.$navLink($baby,'Baby days','baby').'
                '.$navLink($studio,'Studio','studio').'
                '.$navLink($packages,'Browse all','packages').'
              </nav>
              <div class="flex items-center gap-3">
                <a href="tel:'.$phoneHref.'" class="lg:hidden grid h-10 w-10 place-items-center rounded-full border border-stone-200 bg-white text-stone-700" aria-label="Call studio">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6.6 10.8c1.7 3.3 3.9 5.5 7.2 7.2l2.4-2.4c.3-.3.8-.4 1.2-.2 1.3.4 2.7.7 4.1.7.7 0 1.2.5 1.2 1.2V21c0 .7-.5 1.2-1.2 1.2C10.8 22.2 1.8 13.2 1.8 2.2 1.8 1.5 2.3 1 3 1h3.7c.7 0 1.2.5 1.2 1.2 0 1.4.2 2.8.7 4.1.1.4 0 .9-.3 1.2L6.6 10.8Z"/></svg>
                </a>
                <div class="hidden sm:flex items-center gap-3">'.$authDesktop.'</div>
                <input type="checkbox" id="site-menu" class="peer sr-only" aria-hidden="true">
                <label for="site-menu" class="lg:hidden grid h-10 w-10 place-items-center rounded-full border border-stone-200 bg-white text-stone-800 cursor-pointer" aria-label="Open menu">
                  <svg class="icon-open h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                  <svg class="icon-close h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </label>
                <div class="mobile-panel absolute left-0 right-0 top-[calc(100%+0.75rem)] z-50 rounded-[1.5rem] border border-stone-200 bg-white p-3 shadow-xl shadow-stone-900/10 lg:hidden">
                  <nav class="space-y-1" aria-label="Mobile">
                    '.$mobileNav($homeUrl,'Home','Studio overview','home').'
                    '.$mobileNav($wedding,'Weddings','Engagements & celebrations','wedding').'
                    '.$mobileNav($baby,'Baby days','Dedication & christening','baby').'
                    '.$mobileNav($studio,'Studio','Portrait sessions','studio').'
                    '.$mobileNav($packages,'Browse all','Every package in one place','packages').'
                  </nav>
                  <div class="mt-3 grid gap-2 border-t border-stone-100 pt-3">'.$authMobile.'</div>
                  <a href="tel:'.$phoneHref.'" class="mt-3 flex items-center justify-center gap-2 rounded-2xl bg-stone-100 px-4 py-3 text-sm font-semibold text-stone-800">Call '.$phone.'</a>
                </div>
              </div>
            </div>
          </div>
        </header>';
    }

    private function clientShell(string $title, string $content): string
    {
        $name = htmlspecialchars($this->config['app_name'] ?? 'Mhannuellens');
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
              <a class="portal-chip" href="'.$this->url('/packages').'">Book</a>
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
        $name = htmlspecialchars($this->config['app_name'] ?? 'Mhannuellens');
        $items = [
            ['/admin', 'Home', 'Overview'],
            ['/admin/bookings', 'Bookings', 'Jobs'],
            ['/admin/payments', 'Payments', 'MoMo'],
            ['/admin/packages', 'Packages', 'Offers'],
            ['/admin/clients', 'Clients', 'People'],
            ['/admin/coupons', 'Coupons', 'Codes'],
            ['/admin/reports', 'Reports', 'Money'],
            ['/admin/settings', 'Settings', 'Studio'],
        ];
        $side = '';
        $tabs = '';
        foreach ($items as [$href, $label, $meta]) {
            $active = $this->portalActive($href) ? ' is-active' : '';
            $side .= '<a href="'.$this->url($href).'" class="'.$active.'">'.htmlspecialchars($label).'</a>';
            $tabs .= '<a href="'.$this->url($href).'" class="'.$active.'">'.htmlspecialchars($label).'</a>';
        }
        $bottomKeys = ['/admin','/admin/bookings','/admin/payments','/admin/packages','/admin/settings'];
        $bottomIcons = [
            '/admin' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z"/></svg>',
            '/admin/bookings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 3v2M16 3v2M5 8h14M6.5 5.5h11A1.5 1.5 0 0 1 19 7v12.5A1.5 1.5 0 0 1 17.5 21h-11A1.5 1.5 0 0 1 5 19.5V7a1.5 1.5 0 0 1 1.5-1.5Z"/></svg>',
            '/admin/payments' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8.5h16v9H4v-9Z"/><path d="M4 11h16M8 14.5h3"/></svg>',
            '/admin/packages' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7.5h16M7 4.5h10M6 7.5v10.5A1.5 1.5 0 0 0 7.5 19.5h9a1.5 1.5 0 0 0 1.5-1.5V7.5"/></svg>',
            '/admin/settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.2M12 18.3v2.2M3.5 12h2.2M18.3 12h2.2M5.8 5.8l1.6 1.6M16.6 16.6l1.6 1.6M18.2 5.8l-1.6 1.6M7.4 16.6l-1.6 1.6"/></svg>',
        ];
        $bottom = '';
        foreach ($bottomKeys as $href) {
            $label = [ '/admin'=>'Home','/admin/bookings'=>'Jobs','/admin/payments'=>'Pay','/admin/packages'=>'Packs','/admin/settings'=>'More'][$href];
            $active = $this->portalActive($href) ? ' is-active' : '';
            $bottom .= '<a href="'.$this->url($href).'" class="'.$active.'">'.$bottomIcons[$href].'<span>'.$label.'</span></a>';
        }
        return '<div class="portal-shell">
          <header class="portal-top">
            <a class="portal-brand" href="'.$this->url('/admin').'">
              <span class="portal-brand-mark"><svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4.5 8.5h2.2l1.2-2h8.2l1.2 2H19.5A1.5 1.5 0 0 1 21 10v7.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5V10a1.5 1.5 0 0 1 1.5-1.5Z"/><circle cx="12" cy="13.5" r="3.2"/></svg></span>
              <span><span class="portal-brand-name">'.$name.'</span><span class="portal-brand-sub">Admin portal</span></span>
            </a>
            <div class="portal-top-actions">
              <a class="portal-chip" href="'.$this->url('/').'">Site</a>
              <a class="portal-chip" href="'.$this->url('/logout').'">Log out</a>
            </div>
          </header>
          <div class="portal-body">
            <aside class="portal-side">'.$side.'</aside>
            <section class="portal-main">
              <div class="portal-head"><p class="portal-kicker">Studio admin</p><h1 class="portal-title">'.htmlspecialchars($title).'</h1></div>
              <div class="portal-tabs">'.$tabs.'</div>
              '.$content.'
            </section>
          </div>
          <nav class="portal-bottom" aria-label="Admin">'.$bottom.'</nav>
        </div>';
    }


    private function portalActive(string $href): bool
    {
        $current = $this->path ?: '/';
        if ($href === '/admin' || $href === '/client/dashboard') {
            return $current === $href;
        }
        if ($href === '/admin/bookings' && (str_starts_with($current, '/admin/booking') || $current === '/admin/bookings')) {
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

    private function input(string $name,string $label,string $type='text',$value=''): string
    {
        $step=$type==='number'?'step="0.01"':'';
        return '<div><label class="text-sm font-bold">'.htmlspecialchars($label).'</label><input '.$step.' type="'.$type.'" name="'.$name.'" value="'.htmlspecialchars((string)$value).'" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 outline-none focus:border-slate-400" '.($type==='password'?'autocomplete="current-password"':'').'></div>';
    }

    private function stat(string $label,string $value): string
    {
        return '<div class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5"><p class="text-xs font-bold text-slate-400">'.$label.'</p><p class="mt-2 text-xl sm:text-2xl font-black">'.$value.'</p></div>';
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