<?php
declare(strict_types=1);

/**
 * SQLite connection + schema bootstrap + seed.
 */
final class Database
{
    public static function connect(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        $exists = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='users'"
        )->fetchColumn();

        if ($exists) {
            self::ensureSchema($pdo);
            return;
        }

        $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));

        $catalog = __DIR__ . '/../database/seed_products.sql';
        if (is_file($catalog)) {
            $pdo->exec(file_get_contents($catalog));
        }

        self::seed($pdo);
    }

    /** Idempotent upgrades for databases created before a column existed. */
    private static function ensureSchema(PDO $pdo): void
    {
        $cols = $pdo->query('PRAGMA table_info(products)')->fetchAll();
        $hasReserved = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'reserved') {
                $hasReserved = true;
                break;
            }
        }
        if (!$hasReserved) {
            $pdo->exec('ALTER TABLE products ADD COLUMN reserved INTEGER NOT NULL DEFAULT 0');
            // Backfill product links + reservations from existing data.
            $pdo->exec(
                "UPDATE order_items SET product_id = (
                     SELECT id FROM products p WHERE p.sku = order_items.sku AND p.spec = order_items.spec LIMIT 1
                 ) WHERE product_id IS NULL"
            );
            $pdo->exec(
                "UPDATE products SET reserved = COALESCE((
                     SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                     WHERE oi.product_id = products.id AND o.status LIKE 'pending_%'
                 ), 0)"
            );
        }

        // customers.owner column (added later) — add + backfill on existing DBs.
        $ccols = $pdo->query('PRAGMA table_info(customers)')->fetchAll();
        $hasOwner = false;
        foreach ($ccols as $c) {
            if ($c['name'] === 'owner') {
                $hasOwner = true;
                break;
            }
        }
        if (!$hasOwner) {
            $pdo->exec('ALTER TABLE customers ADD COLUMN owner TEXT');
            $pdo->exec(
                "UPDATE customers SET owner = (
                     SELECT o.submitter FROM orders o
                     WHERE o.customer_id = customers.id AND COALESCE(o.submitter, '') <> ''
                     ORDER BY o.id DESC LIMIT 1
                 ) WHERE owner IS NULL OR owner = ''"
            );
        }

        // role_permissions table (added later) — create + seed defaults on existing DBs.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS role_permissions (role TEXT NOT NULL, module TEXT NOT NULL, PRIMARY KEY (role, module))'
        );
        if ((int) $pdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn() === 0) {
            self::seedPermissions($pdo);
        }

        // One-time migration: grant 'performance' (全员销售业绩) to manager/finance_manager.
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (k TEXT PRIMARY KEY, v TEXT)');
        if (!$pdo->query("SELECT 1 FROM app_meta WHERE k = 'perm_performance'")->fetchColumn()) {
            $pdo->exec("INSERT OR IGNORE INTO role_permissions (role, module) VALUES ('manager','performance'),('finance_manager','performance')");
            $pdo->exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES ('perm_performance', '1')");
        }

        // One-time migration: seed default permissions for newly added roles (HR / ops / clerk).
        if (!$pdo->query("SELECT 1 FROM app_meta WHERE k = 'perm_roles_v2'")->fetchColumn()) {
            $ins = $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?, ?)');
            $defs = default_permissions();
            foreach (['ops_supervisor', 'hr', 'clerk'] as $role) {
                foreach ($defs[$role] ?? [] as $m) {
                    $ins->execute([$role, $m]);
                }
            }
            $pdo->exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES ('perm_roles_v2', '1')");
        }
    }

    private static function seed(PDO $pdo): void
    {
        $pdo->beginTransaction();

        // ── Staff (password for all demo accounts: admin123) ──
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $users = [
            ['Administrator',     'admin@alupanel.local', 'admin',      '系统管理员'],
            ['张经理',            'manager@alupanel.local', 'manager',  '销售总监'],
            ['Sari Dewi',         'sari@alupanel.local',    'supervisor', '销售主管'],
            ['Mutiara Farianda',  'mutiara@alupanel.local', 'manager',  '经理'],
            ['Ahmad Fauzi',       'ahmad@alupanel.local',   'sales',    '销售员'],
            ['Pak Joko',          'joko@alupanel.local',    'warehouse', '仓库管理员'],
        ];
        $us = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,title) VALUES (?,?,?,?,?)');
        foreach ($users as $u) {
            $us->execute([$u[0], $u[1], $hash, $u[2], $u[3]]);
        }

        // ── Customers ──
        $customers = [
            ['Budi Santoso', 'PT Teknologi Maju', '+62 812-3456-7890', 'budi@tekno.co.id', 'Jakarta', '重点', 28000000, 'Pengambil keputusan utama, perlu dikelola dengan baik', '2026-05-20'],
            ['Siti Rahayu', 'CV Dagang Makmur', '+62 813-9876-5432', 'siti@dagangmakmur.com', 'Surabaya', '成交', 15000000, 'Kontrak tahunan sudah ditandatangani', '2026-05-18'],
            ['Eko Prasetyo', 'PT Belanja Online', '+62 815-2468-1357', 'eko@belanja.id', 'Bandung', '潜在', 8000000, 'Sedang evaluasi proposal, follow up minggu depan', '2026-05-15'],
            ['Dewi Anggraini', 'PT Grup Sejahtera', '+62 856-1111-2222', 'dewi@sejahtera.co.id', 'Jakarta', '重点', 50000000, 'Penanggung jawab pengadaan grup', '2026-05-22'],
            ['Rizky Firmansyah', 'Startup Nusantara', '+62 877-3333-4444', 'rizky@nusantara.io', 'Yogyakarta', '潜在', 6000000, 'Startup, dana terbatas', '2026-05-10'],
            ['Agus Widodo', 'PT Logistik Prima', '+62 833-5555-6666', 'agus@logistik.com', 'Semarang', '成交', 22000000, 'Pelanggan kerja sama jangka panjang', '2026-05-25'],
        ];
        $cs = $pdo->prepare('INSERT INTO customers (name,company,phone,email,city,tag,value,note,last_contact) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($customers as $c) {
            $cs->execute($c);
        }
        // name → id map for the rest of the seed
        $cid = [];
        foreach ($pdo->query('SELECT id,name FROM customers') as $row) {
            $cid[$row['name']] = (int) $row['id'];
        }

        // ── Deals ──
        $deals = [
            ['Lisensi Tahunan PT Teknologi Maju', 'Budi Santoso', 28000000, '谈判中', '2026-06-15'],
            ['Pengadaan Q2 CV Dagang Makmur', 'Siti Rahayu', 8000000, '已成交', '2026-05-20'],
            ['Paket SaaS PT Belanja Online', 'Eko Prasetyo', 4800000, '方案报价', '2026-06-30'],
            ['Kerja Sama Strategis PT Grup Sejahtera', 'Dewi Anggraini', 50000000, '需求确认', '2026-07-01'],
            ['Paket Dasar Startup Nusantara', 'Rizky Firmansyah', 3600000, '初步接触', '2026-06-20'],
            ['Kontrak Tahunan PT Logistik Prima', 'Agus Widodo', 20000000, '已成交', '2026-04-30'],
            ['Order Percobaan Pelanggan Baru', 'Eko Prasetyo', 1200000, '初步接触', '2026-06-10'],
        ];
        $ds = $pdo->prepare('INSERT INTO deals (name,customer_id,value,stage,close_date) VALUES (?,?,?,?,?)');
        foreach ($deals as $d) {
            $ds->execute([$d[0], $cid[$d[1]] ?? null, $d[2], $d[3], $d[4]]);
        }

        // ── Tasks ──
        $tasks = [
            ['Follow up detail kontrak PT Teknologi Maju', '2026-05-27', '高', 'Budi Santoso', 0],
            ['Kirim PPT proposal ke PT Grup Sejahtera', '2026-05-26', '高', 'Dewi Anggraini', 0],
            ['Jadwalkan demo produk PT Belanja Online', '2026-05-28', '中', 'Eko Prasetyo', 0],
            ['Balas email konsultasi Rizky', '2026-05-26', '中', 'Rizky Firmansyah', 1],
            ['Susun laporan penjualan Mei', '2026-05-31', '低', '', 0],
            ['Kunjungi PIC baru CV Dagang Makmur', '2026-06-02', '中', 'Siti Rahayu', 0],
        ];
        $ts = $pdo->prepare('INSERT INTO tasks (title,due_date,priority,customer_id,done) VALUES (?,?,?,?,?)');
        foreach ($tasks as $t) {
            $ts->execute([$t[0], $t[1], $t[2], $cid[$t[3]] ?? null, $t[4]]);
        }

        // ── Stock transactions (productId in prototype maps to seeded product id) ──
        $txns = [
            [1, 'in', 20, 'Restok bulanan', '', '2026-05-10'],
            [2, 'out_auto', 5, 'ORD-2026-003', 'Auto deduct saat disetujui', '2026-05-19'],
            [3, 'out', 1, 'Sample demo', 'Demo ke PT Belanja Online', '2026-05-15'],
            [5, 'in', 10, 'Restok darurat', '', '2026-05-20'],
            [1, 'out_auto', 3, 'ORD-2026-001 (approved soon)', '', '2026-05-22'],
        ];
        $xs = $pdo->prepare('INSERT INTO stock_txn (product_id,type,qty,ref,note,txn_date) VALUES (?,?,?,?,?,?)');
        foreach ($txns as $x) {
            $xs->execute($x);
        }

        // ── Orders + items ──
        $orders = [
            [
                'no' => '0476/AMI-CO/05/26', 'customer' => 'David Afrianto', 'company' => 'David Afrianto',
                'address' => 'JLN. BARU ANCOL SELATAN NO.52.RT.004 RW06, SUNTER AGUNG TANJUNG PRIOK,KOTA ADM. JAKARTA UTARA, DKI JAKARTA,14350',
                'phone' => 'By WeChat', 'client_type' => 'Contractor', 'delivery_service' => 'Lala Move',
                'delivery_address' => 'Sama dengan alamat klien', 'submitter' => 'Ahmad Fauzi', 'shipping_cost' => 600000,
                'delivery_date' => '2026-05-29', 'note' => 'Pickup besok pagi', 'payment_term' => 'CBD', 'custom_days' => 0,
                'status' => 'pending_wh',
                'sup_note' => 'Stok mencukupi', 'sup_approver' => 'Sari Dewi', 'sup_date' => '2026-05-26',
                'mgr_note' => 'Disetujui, mohon segera siapkan barang', 'mgr_approver' => 'Mutiara Farianda', 'mgr_date' => '2026-05-26',
                'wh_note' => '', 'wh_approver' => '', 'wh_date' => '', 'do_number' => '', 'invoice_number' => '', 'created_at' => '2026-05-26',
                'items' => [['SJ8032', 'Dark Gray', '4.0*0.30PVDF', '1.220 x 2.440', 15, 'Unit', 480000]],
            ],
            [
                'no' => '0475/AMI-CO/05/26', 'customer' => 'David Afrianto', 'company' => 'David Afrianto',
                'address' => 'JLN. BARU ANCOL SELATAN NO.52.RT.004 RW06, SUNTER AGUNG TANJUNG PRIOK,KOTA ADM. JAKARTA UTARA, DKI JAKARTA,14350',
                'phone' => 'By WeChat', 'client_type' => 'Contractor', 'delivery_service' => 'Lala Move',
                'delivery_address' => 'JLN. BARU ANCOL SELATAN NO.52...', 'submitter' => 'Deni MS', 'shipping_cost' => 0,
                'delivery_date' => '2026-05-28', 'note' => '', 'payment_term' => 'custom', 'custom_days' => 30,
                'status' => 'pending_mgr',
                'sup_note' => 'Harga sudah sesuai standar, stok mencukupi', 'sup_approver' => 'Sari Dewi', 'sup_date' => '2026-05-26',
                'mgr_note' => '', 'mgr_approver' => '', 'mgr_date' => '',
                'wh_note' => '', 'wh_approver' => '', 'wh_date' => '', 'do_number' => '', 'invoice_number' => '', 'created_at' => '2026-05-26',
                'items' => [['SJ8032', 'Dark Gray', '4.0*0.21', '1.220 x 2.440', 35, 'Unit', 428828.83]],
            ],
            [
                'no' => '0473/AMI-CO/05/26', 'customer' => 'Budi Santoso', 'company' => 'PT Teknologi Maju',
                'address' => 'Jl. Sudirman Kav 52, Jakarta Selatan', 'phone' => '+62 812-3456-7890',
                'client_type' => 'Distributor', 'delivery_service' => 'Self Pickup',
                'delivery_address' => 'Jl. Sudirman Kav 52, Jakarta Selatan', 'submitter' => 'Ahmad Fauzi', 'shipping_cost' => 1500000,
                'delivery_date' => '2026-06-01', 'note' => 'Prioritas tinggi', 'payment_term' => 'custom', 'custom_days' => 30,
                'status' => 'pending_sup',
                'sup_note' => '', 'sup_approver' => '', 'sup_date' => '', 'mgr_note' => '', 'mgr_approver' => '', 'mgr_date' => '',
                'wh_note' => '', 'wh_approver' => '', 'wh_date' => '', 'do_number' => '', 'invoice_number' => '', 'created_at' => '2026-05-25',
                'items' => [
                    ['SJ8001', 'Pure White', '4.0*0.30', '1.220 x 2.440', 50, 'Unit', 520000],
                    ['SJ8019', 'Silver Grey', '4.0*0.30', '1.220 x 2.440', 30, 'Unit', 520000],
                ],
            ],
            [
                'no' => '0470/AMI-CO/05/26', 'customer' => 'Siti Rahayu', 'company' => 'CV Dagang Makmur',
                'address' => 'Jl. Pemuda 234, Surabaya', 'phone' => '+62 813-9876-5432',
                'client_type' => 'Retailer', 'delivery_service' => 'Lala Move',
                'delivery_address' => 'Jl. Pemuda 234, Surabaya', 'submitter' => 'Riko Prabowo', 'shipping_cost' => 800000,
                'delivery_date' => '2026-06-05', 'note' => '', 'payment_term' => 'CBD', 'custom_days' => 0,
                'status' => 'approved',
                'sup_note' => 'OK, stok cukup di gudang', 'sup_approver' => 'Sari Dewi', 'sup_date' => '2026-05-18',
                'mgr_note' => 'Disetujui, mohon segera diproses', 'mgr_approver' => 'Zhang Jing', 'mgr_date' => '2026-05-19',
                'wh_note' => 'Barang sudah dipersiapkan, pickup oleh Lala Move', 'wh_approver' => 'Pak Joko', 'wh_date' => '2026-05-20',
                'do_number' => 'DO-2026-001', 'invoice_number' => '128-AMI-05-26', 'created_at' => '2026-05-17',
                'items' => [['SJ8068', 'C-Red', '4.0*0.21', '1.220 x 2.440', 20, 'Unit', 380000]],
            ],
            [
                'no' => '0468/AMI-CO/05/26', 'customer' => 'Dewi Anggraini', 'company' => 'PT Grup Sejahtera',
                'address' => 'Jl. Gatot Subroto Kav 18, Jakarta', 'phone' => '+62 856-1111-2222',
                'client_type' => 'Contractor', 'delivery_service' => 'Truck',
                'delivery_address' => 'Lokasi Proyek - Karawang', 'submitter' => 'Ahmad Fauzi', 'shipping_cost' => 3500000,
                'delivery_date' => '2026-07-01', 'note' => 'Kontrak strategis grup', 'payment_term' => 'custom', 'custom_days' => 60,
                'status' => 'rejected',
                'sup_note' => 'Stok terbatas, perlu cek ulang', 'sup_approver' => 'Sari Dewi', 'sup_date' => '2026-05-20',
                'mgr_note' => 'Tolak - jumlah melebihi stok tersedia, ajukan ulang setelah restok', 'mgr_approver' => 'Zhang Jing', 'mgr_date' => '2026-05-21',
                'wh_note' => '', 'wh_approver' => '', 'wh_date' => '', 'do_number' => '', 'invoice_number' => '', 'created_at' => '2026-05-19',
                'items' => [['SJ8038', 'Black', '4.0*0.21', '1.220 x 2.440', 100, 'Unit', 380000]],
            ],
            [
                'no' => '0466/AMI-CO/05/26', 'customer' => 'Rizky Firmansyah', 'company' => 'Startup Nusantara',
                'address' => 'Jl. Malioboro 88, Yogyakarta', 'phone' => '+62 877-3333-4444',
                'client_type' => 'End User', 'delivery_service' => 'JNE Trucking',
                'delivery_address' => 'Jl. Malioboro 88, Yogyakarta', 'submitter' => 'Novi Susanti', 'shipping_cost' => 500000,
                'delivery_date' => '2026-06-20', 'note' => 'Paket percobaan', 'payment_term' => 'COD', 'custom_days' => 0,
                'status' => 'draft',
                'sup_note' => '', 'sup_approver' => '', 'sup_date' => '', 'mgr_note' => '', 'mgr_approver' => '', 'mgr_date' => '',
                'wh_note' => '', 'wh_approver' => '', 'wh_date' => '', 'do_number' => '', 'invoice_number' => '', 'created_at' => '2026-05-26',
                'items' => [['SJ8018', 'Silver Metallic', '4.0*0.10', '1.220 x 2.440', 10, 'Unit', 280000]],
            ],
        ];
        $os = $pdo->prepare(
            'INSERT INTO orders (order_no,customer_id,customer_name,company,address,phone,client_type,delivery_service,delivery_address,submitter,shipping_cost,delivery_date,note,payment_term,custom_days,status,sup_note,sup_approver,sup_date,mgr_note,mgr_approver,mgr_date,wh_note,wh_approver,wh_date,do_number,invoice_number,created_at)
             VALUES (:no,:cid,:customer,:company,:address,:phone,:ct,:ds,:da,:sub,:ship,:dd,:note,:pt,:cd,:st,:sn,:sa,:sdt,:mn,:ma,:mdt,:wn,:wa,:wdt,:do,:inv,:ca)'
        );
        $ois = $pdo->prepare('INSERT INTO order_items (order_id,sku,color,spec,size,qty,unit,price) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($orders as $o) {
            $os->execute([
                ':no' => $o['no'], ':cid' => $cid[$o['customer']] ?? null, ':customer' => $o['customer'], ':company' => $o['company'],
                ':address' => $o['address'], ':phone' => $o['phone'], ':ct' => $o['client_type'], ':ds' => $o['delivery_service'],
                ':da' => $o['delivery_address'], ':sub' => $o['submitter'], ':ship' => $o['shipping_cost'], ':dd' => $o['delivery_date'],
                ':note' => $o['note'], ':pt' => $o['payment_term'], ':cd' => $o['custom_days'], ':st' => $o['status'],
                ':sn' => $o['sup_note'], ':sa' => $o['sup_approver'], ':sdt' => $o['sup_date'],
                ':mn' => $o['mgr_note'], ':ma' => $o['mgr_approver'], ':mdt' => $o['mgr_date'],
                ':wn' => $o['wh_note'], ':wa' => $o['wh_approver'], ':wdt' => $o['wh_date'],
                ':do' => $o['do_number'], ':inv' => $o['invoice_number'], ':ca' => $o['created_at'],
            ]);
            $oid = (int) $pdo->lastInsertId();
            foreach ($o['items'] as $it) {
                $ois->execute([$oid, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6]]);
            }
        }

        // ── Delivery order (one issued by warehouse) ──
        $pdo->prepare(
            'INSERT INTO delivery_orders (do_no,order_id,customer,company,address,phone,delivery_address,delivery_service,pickup_date,issued_by,note)
             SELECT ?,o.id,?,?,?,?,?,?,?,?,? FROM orders o WHERE o.order_no = ?'
        )->execute([
            'DO-2026-001', 'Siti Rahayu', 'CV Dagang Makmur', 'Jl. Pemuda 234, Surabaya', '+62 813-9876-5432',
            'Jl. Pemuda 234, Surabaya', 'Lala Move', '2026-05-20', 'Pak Joko', 'Pickup oleh Lala Move', '0470/AMI-CO/05/26',
        ]);

        // ── Invoices + items ──
        $invoices = [
            [
                'no' => '128-AMI-05-26', 'order' => '0470/AMI-CO/05/26', 'do' => 'DO-2026-001', 'customer' => 'CV Dagang Makmur',
                'bill_to' => 'Siti Rahayu', 'address' => 'Jl. Pemuda 234, Surabaya', 'npwp' => '', 'no_po' => '001/CDM-ACP/AMI/V/2026',
                'ship' => 800000, 'subtotal' => 8400000, 'ppn' => 924000, 'total' => 9324000,
                'inv_date' => '2026-05-20', 'due' => '2026-06-19', 'issued_by' => 'Mutiara Farianda', 'note' => 'Net 30天',
                'pt' => 'custom', 'cd' => 30, 'pstatus' => 'paid', 'paid' => 9324000, 'paid_date' => '2026-05-25',
                'receipt' => 'RC-2026-001', 'method' => 'BCA Transfer', 'pnote' => 'Lunas via BCA, sudah dikonfirmasi',
                'tax_no' => '010.000-26.12345678',
                'items' => [['SJ8068', 'C-Red', '4.0*0.21', '1.220 x 2.440', 20, 'Unit', 380000]],
            ],
            [
                'no' => '127-AMI-05-26', 'order' => '0469/AMI-CO/05/26', 'do' => 'DO-2026-000', 'customer' => 'PT Maju Bersama',
                'bill_to' => 'Indra Wijaya', 'address' => 'Jl. Sudirman 100, Jakarta', 'npwp' => '01.234.567.8-091.000', 'no_po' => 'PO-MB-2026-04',
                'ship' => 1500000, 'subtotal' => 17100000, 'ppn' => 1881000, 'total' => 18981000,
                'inv_date' => '2026-05-15', 'due' => '2026-05-28', 'issued_by' => 'Mutiara Farianda', 'note' => 'Net 14天',
                'pt' => 'custom', 'cd' => 14, 'pstatus' => 'pending', 'paid' => 0, 'paid_date' => '',
                'receipt' => '', 'method' => '', 'pnote' => '', 'tax_no' => '',
                'items' => [['SJ8001', 'Pure White', '4.0*0.30', '1.220 x 2.440', 30, 'Unit', 520000]],
            ],
            [
                'no' => '126-AMI-05-26', 'order' => '0468/AMI-CO/05/26', 'do' => 'DO-2026-001', 'customer' => 'CV Anugerah Jaya',
                'bill_to' => 'Hendra Kusuma', 'address' => 'Jl. Pahlawan 56, Bekasi', 'npwp' => '', 'no_po' => '',
                'ship' => 1200000, 'subtotal' => 10700000, 'ppn' => 1177000, 'total' => 11877000,
                'inv_date' => '2026-04-20', 'due' => '2026-05-20', 'issued_by' => 'Mutiara Farianda', 'note' => 'Net 30天',
                'pt' => 'custom', 'cd' => 30, 'pstatus' => 'overdue', 'paid' => 0, 'paid_date' => '',
                'receipt' => '', 'method' => '', 'pnote' => '', 'tax_no' => '010.000-26.87654321',
                'items' => [['SJ8038', 'Black', '4.0*0.21', '1.220 x 2.440', 25, 'Unit', 380000]],
            ],
            [
                'no' => '125-AMI-04-26', 'order' => '0465/AMI-04-26', 'do' => 'DO-2026-002', 'customer' => 'PT Konstruksi Prima',
                'bill_to' => 'Bapak Bambang', 'address' => 'Jl. Gatot Subroto 12, Jakarta', 'npwp' => '02.345.678.9-012.000', 'no_po' => 'PO-KP-2026-03',
                'ship' => 2500000, 'subtotal' => 28500000, 'ppn' => 3135000, 'total' => 31635000,
                'inv_date' => '2026-04-10', 'due' => '2026-05-10', 'issued_by' => 'Mutiara Farianda', 'note' => 'Net 30天',
                'pt' => 'custom', 'cd' => 30, 'pstatus' => 'overdue', 'paid' => 15000000, 'paid_date' => '2026-05-05',
                'receipt' => 'RC-2026-002', 'method' => 'BCA Transfer', 'pnote' => 'Cicilan pertama', 'tax_no' => '010.000-26.11223344',
                'items' => [['SJ8019', 'Silver Grey', '4.0*0.30', '1.220 x 2.440', 50, 'Unit', 520000]],
            ],
        ];
        $invStmt = $pdo->prepare(
            'INSERT INTO invoices (invoice_no,order_id,do_number,customer,bill_to_name,address,npwp,no_po,currency,shipping_cost,subtotal,ppn,total,invoice_date,due_date,issued_by,note,payment_term,custom_days,payment_status,amount_paid,paid_date,receipt_no,payment_method,payment_note,tax_invoice_no)
             VALUES (:no,(SELECT id FROM orders WHERE order_no=:ord),:do,:cust,:bill,:addr,:npwp,:po,:cur,:ship,:sub,:ppn,:tot,:idate,:due,:by,:note,:pt,:cd,:ps,:paid,:pdate,:rcpt,:method,:pnote,:taxno)'
        );
        $iis = $pdo->prepare('INSERT INTO invoice_items (invoice_id,sku,color,spec,size,qty,unit,price) VALUES (?,?,?,?,?,?,?,?)');
        $payStmt = $pdo->prepare('INSERT INTO payments (invoice_id,customer,amount,pay_date,method,receipt_no,note) VALUES (?,?,?,?,?,?,?)');
        foreach ($invoices as $iv) {
            $invStmt->execute([
                ':no' => $iv['no'], ':ord' => $iv['order'], ':do' => $iv['do'], ':cust' => $iv['customer'], ':bill' => $iv['bill_to'],
                ':addr' => $iv['address'], ':npwp' => $iv['npwp'], ':po' => $iv['no_po'], ':cur' => 'IDR', ':ship' => $iv['ship'],
                ':sub' => $iv['subtotal'], ':ppn' => $iv['ppn'], ':tot' => $iv['total'], ':idate' => $iv['inv_date'], ':due' => $iv['due'],
                ':by' => $iv['issued_by'], ':note' => $iv['note'], ':pt' => $iv['pt'], ':cd' => $iv['cd'], ':ps' => $iv['pstatus'],
                ':paid' => $iv['paid'], ':pdate' => $iv['paid_date'], ':rcpt' => $iv['receipt'], ':method' => $iv['method'],
                ':pnote' => $iv['pnote'], ':taxno' => $iv['tax_no'],
            ]);
            $ivId = (int) $pdo->lastInsertId();
            foreach ($iv['items'] as $it) {
                $iis->execute([$ivId, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6]]);
            }
            if ($iv['paid'] > 0) {
                $payStmt->execute([$ivId, $iv['customer'], $iv['paid'], $iv['paid_date'], $iv['method'], $iv['receipt'], $iv['pnote']]);
            }
        }

        // Link order items to products and reserve stock for pending orders.
        $pdo->exec(
            "UPDATE order_items SET product_id = (
                 SELECT id FROM products p WHERE p.sku = order_items.sku AND p.spec = order_items.spec LIMIT 1
             ) WHERE product_id IS NULL"
        );
        $pdo->exec(
            "UPDATE products SET reserved = COALESCE((
                 SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE oi.product_id = products.id AND o.status LIKE 'pending_%'
             ), 0)"
        );

        // Assign each customer an owner (the salesperson who last ordered for them).
        $pdo->exec(
            "UPDATE customers SET owner = (
                 SELECT o.submitter FROM orders o
                 WHERE o.customer_id = customers.id AND COALESCE(o.submitter, '') <> ''
                 ORDER BY o.id DESC LIMIT 1
             ) WHERE owner IS NULL OR owner = ''"
        );

        self::seedPermissions($pdo);
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (k TEXT PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES ('perm_performance', '1'), ('perm_roles_v2', '1')");

        $pdo->commit();
    }

    /** Insert default role → module permissions (idempotent). */
    private static function seedPermissions(PDO $pdo): void
    {
        $rp = $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?, ?)');
        foreach (default_permissions() as $role => $mods) {
            foreach ($mods as $m) {
                $rp->execute([$role, $m]);
            }
        }
    }
}
