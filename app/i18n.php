<?php
declare(strict_types=1);

/**
 * Lightweight bilingual (zh / id) i18n.
 * Language is stored in the session; switch via ?r=lang.set&lang=id.
 */

const I18N = [
    'zh' => [
        'lang_name' => '中文', 'app_tagline' => '铝塑板业务管理系统',
        // nav
        'nav_main' => '主菜单', 'nav_extra' => '扩展功能',
        'nav_dashboard' => '数据看板', 'nav_customers' => '客户管理', 'nav_pipeline' => '销售漏斗',
        'nav_tasks' => '任务提醒', 'nav_finance' => '财务管理', 'nav_orders' => '订单审批',
        'nav_inventory' => '库存管理', 'nav_users' => '用户管理', 'logout' => '退出登录',
        // page titles / subs
        'page_dashboard' => '数据看板', 'sub_dashboard' => '2026年5月概览',
        'page_customers' => '客户管理', 'sub_customers' => '管理所有客户信息',
        'page_pipeline' => '销售漏斗', 'sub_pipeline' => '跟踪商机进展',
        'page_tasks' => '任务提醒', 'sub_tasks' => '待办事项与提醒',
        'page_finance' => '财务管理', 'sub_finance' => '收款与应收账款',
        'page_orders' => '订单审批', 'sub_orders' => '销售员 → 主管 → 经理 → 仓管 四级审批',
        'page_inventory' => '库存管理', 'sub_inventory' => '产品库存与自动扣减',
        'page_users' => '用户管理', 'sub_users' => '员工与角色',
        // buttons
        'btn_new' => '新建', 'btn_save' => '保存', 'btn_cancel' => '取消', 'btn_edit' => '编辑',
        'btn_delete' => '删除', 'btn_back' => '返回列表', 'btn_search' => '搜索', 'btn_clear' => '清除',
        'btn_print' => '打印', 'btn_approve' => '通过', 'btn_reject' => '驳回', 'btn_confirm_ship' => '确认出货',
        'btn_add_customer' => '＋ 新建客户', 'btn_add_deal' => '＋ 新增商机', 'btn_add_order' => '＋ 新建订单',
        'btn_add_product' => '＋ 新建产品', 'btn_add_user' => '＋ 新建用户', 'btn_add_row' => '＋ 添加行',
        'btn_save_customer' => '保存客户', 'btn_save_deal' => '保存商机', 'btn_save_task' => '保存任务',
        'btn_save_product' => '保存产品', 'btn_save_user' => '保存用户', 'btn_save_order' => '提交审批', 'btn_save_draft' => '保存草稿',
        'btn_record_payment' => '登记收款', 'btn_stock_txns' => '出入库流水', 'btn_back_inventory' => '返回库存',
        'btn_submit' => '提交', 'btn_export' => '导出 Excel',
        // table headers
        'th_name' => '客户', 'th_company' => '公司', 'th_phone' => '联系方式', 'th_city' => '城市',
        'th_tag' => '标签', 'th_value' => '潜在价值', 'th_action' => '操作', 'th_contact_way' => '联系方式',
        'th_sku' => 'SKU', 'th_color' => '颜色', 'th_spec' => '规格', 'th_stock' => '库存',
        'th_min_stock' => '安全库存', 'th_price' => '单价', 'th_date' => '日期', 'th_status' => '状态',
        'th_amount' => '金额', 'th_customer' => '客户', 'th_due_date' => '到期日', 'th_invoice_no' => '发票号',
        'th_invoice_date' => '开票日', 'th_paid' => '已收', 'th_unpaid' => '未收', 'th_total' => '总额',
        'th_order_no' => '单号', 'th_submitter' => '提交人', 'th_payment' => '付款', 'th_qty' => '数量',
        'th_unit_price' => '单价', 'th_subtotal' => '小计', 'th_deal' => '商机', 'th_stage' => '阶段',
        'th_close_date' => '预计成交', 'th_product' => '产品', 'th_color_spec' => '颜色/规格', 'th_type' => '类型',
        'th_ref' => '单据', 'th_note' => '备注', 'th_role' => '角色', 'th_title' => '职位', 'th_email' => '邮箱',
        'th_method' => '方式', 'th_receipt' => '收据号',
        // filters / empties
        'filter_all' => '全部', 'no_customer' => '暂无客户数据', 'no_data' => '暂无数据',
        'no_tasks' => '没有符合条件的任务', 'no_orders' => '暂无订单', 'no_products' => '暂无产品',
        'no_invoice' => '暂无发票', 'no_deal' => '暂无商机', 'no_txn' => '暂无流水', 'no_payment' => '暂无收款',
        // statuses / labels
        'st_draft' => '草稿', 'st_pending_sup' => '待主管审批', 'st_pending_mgr' => '待经理审批',
        'st_pending_wh' => '待仓库出货', 'st_approved' => '已批准', 'st_rejected' => '已驳回',
        'inv_paid' => '已收款', 'inv_partial' => '部分收款', 'inv_pending' => '待收款', 'inv_overdue' => '逾期',
        'role_admin' => '管理员', 'role_manager' => '经理', 'role_finance_manager' => '财务经理', 'role_ops_supervisor' => '运营主管', 'role_supervisor' => '主管', 'role_sales' => '销售员', 'role_warehouse' => '仓库', 'role_hr' => '人力资源', 'role_clerk' => '文员',
        'nav_roles' => '权限设置', 'page_roles' => '权限设置', 'sub_roles' => '按角色配置模块访问', 'btn_save_perms' => '保存权限', 'col_module' => '模块', 'perms_hint' => '勾选 = 该角色可访问对应模块/数据；管理员始终拥有全部权限。「销售业绩」控制看板全员业绩；「导出」控制能否导出 Excel。', 'nav_performance' => '销售业绩', 'nav_export' => '导出',
        'stage_1' => '初步接触', 'stage_2' => '需求确认', 'stage_3' => '方案报价', 'stage_4' => '谈判中', 'stage_5' => '已成交',
        'tag_vip' => '重点', 'tag_potential' => '潜在', 'tag_closed' => '成交', 'tag_lost' => '流失',
        'pri_high' => '高', 'pri_med' => '中', 'pri_low' => '低',
        'txn_in' => '入库', 'txn_out' => '出库', 'txn_out_auto' => '自动扣减',
        // dashboard
        'stat_received' => '已收款总额', 'stat_customers' => '客户总数', 'stat_active_deals' => '进行中商机',
        'stat_task_rate' => '任务完成率', 'funnel' => '销售漏斗', 'recent_orders' => '最近订单',
        'view_more' => '查看 →', 'credit_alert_1' => '有', 'credit_alert_2' => '张发票已逾期，应收',
        'sales_perf' => '销售业绩', 'my_perf' => '我的业绩', 'hot_products' => '热销产品', 'col_orders' => '单数', 'col_won' => '成交', 'col_won_amt' => '成交额',
        // tasks
        'filter_today' => '今天', 'filter_high' => '高优先级', 'filter_pending' => '待办', 'filter_done' => '已完成',
        'week_stats' => '本周统计', 'completion_rate' => '完成率', 'done_count' => '已完成', 'high_pending' => '高优先级待办',
        'add_task' => '添加任务',
        // finance
        'fin_received' => '已收款', 'fin_pending' => '待收款', 'fin_overdue' => '逾期',
        'related_deals' => '关联商机', 'order_records' => '订单记录', 'product_items' => '产品明细', 'approval_opinions' => '审批意见',
        // inventory
        'inv_skus' => 'SKU 数', 'inv_total_stock' => '总库存（张）', 'inv_low' => '低库存预警', 'inv_out' => '缺货',
        'only_low' => '仅看低库存', 'stock_adjust' => '±库存', 'all_specs' => '全部规格', 'all_tags' => '全部标签',
        'stock_insufficient' => '库存不足', 'need' => '需', 'have' => '库存', 'available' => '可用', 'stock_block_submit' => '有产品库存不足，无法提交订单', 'owner' => '负责销售',
        // orders / approval
        'approval_flow' => '审批流程', 'order_info' => '订单信息', 'do_invoice' => '送货单 / 发票',
        'delivery_addr' => '送货地址', 'note' => '备注', 'shipping' => '运费', 'total' => '合计',
        'sales' => '销售', 'supervisor' => '主管', 'manager' => '经理', 'warehouse' => '仓库',
        'approval_by' => '审批', 'wait_for' => '当前等待', 'no_permission_stage' => '审批，你没有该阶段的操作权限。',
        // form fields
        'f_name' => '客户姓名 *', 'f_company' => '公司名称', 'f_phone' => '手机号', 'f_email' => '邮箱',
        'f_city' => '城市', 'f_tag' => '客户标签', 'f_value' => '潜在价值 (Rp)', 'f_last_contact' => '最后跟进日期',
        'f_note' => '备注', 'f_deal_name' => '商机名称 *', 'f_customer' => '关联客户', 'f_deal_value' => '商机金额 (Rp) *',
        'f_stage' => '当前阶段', 'f_close_date' => '预计成交日期', 'f_task_title' => '任务标题 *', 'f_due' => '截止日期',
        'f_priority' => '优先级', 'f_role' => '角色', 'f_title' => '职位', 'f_password' => '密码',
        'f_client_type' => '客户类型', 'f_delivery_service' => '配送方式', 'f_delivery_date' => '送货日期',
        'f_shipping' => '运费 (Rp)', 'f_payment_term' => '付款条件', 'f_custom_days' => '账期天数', 'f_address' => '客户地址',
        // login
        'login_title' => '登录', 'login_email' => '邮箱', 'login_password' => '密码', 'login_btn' => '登录',
        // print
        'print_invoice' => '发票 / INVOICE', 'print_do' => '送货单 / SURAT JALAN',
    ],
    'id' => [
        'lang_name' => 'Indonesia', 'app_tagline' => 'Sistem Manajemen Bisnis ACP',
        'nav_main' => 'Menu Utama', 'nav_extra' => 'Fitur Lanjutan',
        'nav_dashboard' => 'Dasbor', 'nav_customers' => 'Pelanggan', 'nav_pipeline' => 'Pipeline',
        'nav_tasks' => 'Tugas', 'nav_finance' => 'Keuangan', 'nav_orders' => 'Persetujuan Pesanan',
        'nav_inventory' => 'Stok Gudang', 'nav_users' => 'Pengguna', 'logout' => 'Keluar',
        'page_dashboard' => 'Dasbor', 'sub_dashboard' => 'Ikhtisar Mei 2026',
        'page_customers' => 'Manajemen Pelanggan', 'sub_customers' => 'Kelola semua data pelanggan',
        'page_pipeline' => 'Pipeline Penjualan', 'sub_pipeline' => 'Lacak kemajuan peluang',
        'page_tasks' => 'Tugas & Pengingat', 'sub_tasks' => 'Daftar tugas & pengingat',
        'page_finance' => 'Keuangan', 'sub_finance' => 'Pembayaran & piutang',
        'page_orders' => 'Persetujuan Pesanan', 'sub_orders' => 'Sales → Supervisor → Manager → Gudang',
        'page_inventory' => 'Stok Gudang', 'sub_inventory' => 'Manajemen stok & potongan otomatis',
        'page_users' => 'Manajemen Pengguna', 'sub_users' => 'Karyawan & peran',
        'btn_new' => 'Tambah', 'btn_save' => 'Simpan', 'btn_cancel' => 'Batal', 'btn_edit' => 'Ubah',
        'btn_delete' => 'Hapus', 'btn_back' => 'Kembali', 'btn_search' => 'Cari', 'btn_clear' => 'Hapus',
        'btn_print' => 'Cetak', 'btn_approve' => 'Setujui', 'btn_reject' => 'Tolak', 'btn_confirm_ship' => 'Konfirmasi Kirim',
        'btn_add_customer' => '＋ Tambah Pelanggan', 'btn_add_deal' => '＋ Tambah Peluang', 'btn_add_order' => '＋ Buat Pesanan',
        'btn_add_product' => '＋ Tambah Produk', 'btn_add_user' => '＋ Tambah Pengguna', 'btn_add_row' => '＋ Tambah Baris',
        'btn_save_customer' => 'Simpan Pelanggan', 'btn_save_deal' => 'Simpan Peluang', 'btn_save_task' => 'Simpan Tugas',
        'btn_save_product' => 'Simpan Produk', 'btn_save_user' => 'Simpan Pengguna', 'btn_save_order' => 'Kirim ke Persetujuan', 'btn_save_draft' => 'Simpan Draft',
        'btn_record_payment' => 'Catat Pembayaran', 'btn_stock_txns' => 'Riwayat Stok', 'btn_back_inventory' => 'Kembali ke Stok',
        'btn_submit' => 'Kirim', 'btn_export' => 'Ekspor Excel',
        'th_name' => 'Nama', 'th_company' => 'Perusahaan', 'th_phone' => 'Kontak', 'th_city' => 'Kota',
        'th_tag' => 'Label', 'th_value' => 'Nilai Potensi', 'th_action' => 'Aksi', 'th_contact_way' => 'Kontak',
        'th_sku' => 'SKU', 'th_color' => 'Warna', 'th_spec' => 'Spesifikasi', 'th_stock' => 'Stok',
        'th_min_stock' => 'Stok Min', 'th_price' => 'Harga', 'th_date' => 'Tanggal', 'th_status' => 'Status',
        'th_amount' => 'Jumlah', 'th_customer' => 'Pelanggan', 'th_due_date' => 'Jatuh Tempo', 'th_invoice_no' => 'No. Invoice',
        'th_invoice_date' => 'Tgl Invoice', 'th_paid' => 'Dibayar', 'th_unpaid' => 'Sisa', 'th_total' => 'Total',
        'th_order_no' => 'No. Pesanan', 'th_submitter' => 'Submitter', 'th_payment' => 'Pembayaran', 'th_qty' => 'Qty',
        'th_unit_price' => 'Harga', 'th_subtotal' => 'Subtotal', 'th_deal' => 'Peluang', 'th_stage' => 'Tahap',
        'th_close_date' => 'Perkiraan Closing', 'th_product' => 'Produk', 'th_color_spec' => 'Warna/Spek', 'th_type' => 'Tipe',
        'th_ref' => 'Referensi', 'th_note' => 'Catatan', 'th_role' => 'Peran', 'th_title' => 'Jabatan', 'th_email' => 'Email',
        'th_method' => 'Metode', 'th_receipt' => 'No. Kwitansi',
        'filter_all' => 'Semua', 'no_customer' => 'Belum ada pelanggan', 'no_data' => 'Belum ada data',
        'no_tasks' => 'Tidak ada tugas', 'no_orders' => 'Belum ada pesanan', 'no_products' => 'Belum ada produk',
        'no_invoice' => 'Belum ada invoice', 'no_deal' => 'Belum ada peluang', 'no_txn' => 'Belum ada riwayat', 'no_payment' => 'Belum ada pembayaran',
        'st_draft' => 'Draft', 'st_pending_sup' => 'Menunggu Supervisor', 'st_pending_mgr' => 'Menunggu Manager',
        'st_pending_wh' => 'Menunggu Gudang', 'st_approved' => 'Disetujui', 'st_rejected' => 'Ditolak',
        'inv_paid' => 'Lunas', 'inv_partial' => 'Sebagian', 'inv_pending' => 'Belum Dibayar', 'inv_overdue' => 'Jatuh Tempo',
        'role_admin' => 'Admin', 'role_manager' => 'Manager', 'role_finance_manager' => 'Manajer Keuangan', 'role_ops_supervisor' => 'Supervisor Operasional', 'role_supervisor' => 'Supervisor', 'role_sales' => 'Sales', 'role_warehouse' => 'Gudang', 'role_hr' => 'HRD', 'role_clerk' => 'Staf Administrasi',
        'nav_roles' => 'Hak Akses', 'page_roles' => 'Pengaturan Hak Akses', 'sub_roles' => 'Atur akses modul per peran', 'btn_save_perms' => 'Simpan', 'col_module' => 'Modul', 'perms_hint' => 'Centang = peran dapat mengakses modul/data; Admin selalu penuh. "Kinerja Sales" untuk dasbor; "Ekspor" untuk ekspor Excel.', 'nav_performance' => 'Kinerja Sales', 'nav_export' => 'Ekspor',
        'stage_1' => 'Kontak Awal', 'stage_2' => 'Konfirmasi Kebutuhan', 'stage_3' => 'Penawaran Harga', 'stage_4' => 'Negosiasi', 'stage_5' => 'Closing',
        'tag_vip' => 'VIP', 'tag_potential' => 'Prospek', 'tag_closed' => 'Closing', 'tag_lost' => 'Lost',
        'pri_high' => 'Tinggi', 'pri_med' => 'Sedang', 'pri_low' => 'Rendah',
        'txn_in' => 'Masuk', 'txn_out' => 'Keluar', 'txn_out_auto' => 'Potong Otomatis',
        'stat_received' => 'Total Diterima', 'stat_customers' => 'Total Pelanggan', 'stat_active_deals' => 'Peluang Aktif',
        'stat_task_rate' => 'Penyelesaian Tugas', 'funnel' => 'Pipeline Penjualan', 'recent_orders' => 'Pesanan Terbaru',
        'view_more' => 'Lihat →', 'credit_alert_1' => 'Ada', 'credit_alert_2' => 'invoice jatuh tempo, piutang',
        'sales_perf' => 'Kinerja Sales', 'my_perf' => 'Kinerja Saya', 'hot_products' => 'Produk Terlaris', 'col_orders' => 'Pesanan', 'col_won' => 'Closing', 'col_won_amt' => 'Nilai Closing',
        'filter_today' => 'Hari Ini', 'filter_high' => 'Prioritas Tinggi', 'filter_pending' => 'Tertunda', 'filter_done' => 'Selesai',
        'week_stats' => 'Statistik Minggu Ini', 'completion_rate' => 'Tingkat Selesai', 'done_count' => 'Selesai', 'high_pending' => 'Prioritas Tinggi Tertunda',
        'add_task' => 'Tambah Tugas',
        'fin_received' => 'Lunas', 'fin_pending' => 'Belum Dibayar', 'fin_overdue' => 'Jatuh Tempo',
        'related_deals' => 'Peluang Terkait', 'order_records' => 'Riwayat Pesanan', 'product_items' => 'Rincian Produk', 'approval_opinions' => 'Catatan Persetujuan',
        'inv_skus' => 'Jumlah SKU', 'inv_total_stock' => 'Total Stok', 'inv_low' => 'Stok Rendah', 'inv_out' => 'Habis',
        'only_low' => 'Hanya stok rendah', 'stock_adjust' => '±Stok', 'all_specs' => 'Semua Spesifikasi', 'all_tags' => 'Semua Label',
        'stock_insufficient' => 'Stok tidak cukup', 'need' => 'butuh', 'have' => 'stok', 'available' => 'Tersedia', 'stock_block_submit' => 'Ada produk stok tidak cukup, pesanan tidak dapat dikirim', 'owner' => 'Sales PIC',
        'approval_flow' => 'Alur Persetujuan', 'order_info' => 'Info Pesanan', 'do_invoice' => 'Surat Jalan / Invoice',
        'delivery_addr' => 'Alamat Kirim', 'note' => 'Catatan', 'shipping' => 'Ongkir', 'total' => 'Total',
        'sales' => 'Sales', 'supervisor' => 'Supervisor', 'manager' => 'Manager', 'warehouse' => 'Gudang',
        'approval_by' => 'Persetujuan', 'wait_for' => 'Menunggu', 'no_permission_stage' => ', Anda tidak punya akses pada tahap ini.',
        'f_name' => 'Nama Pelanggan *', 'f_company' => 'Nama Perusahaan', 'f_phone' => 'Nomor HP', 'f_email' => 'Email',
        'f_city' => 'Kota', 'f_tag' => 'Label Pelanggan', 'f_value' => 'Nilai Potensi (Rp)', 'f_last_contact' => 'Kontak Terakhir',
        'f_note' => 'Catatan', 'f_deal_name' => 'Nama Peluang *', 'f_customer' => 'Pelanggan Terkait', 'f_deal_value' => 'Nilai Peluang (Rp) *',
        'f_stage' => 'Tahap Saat Ini', 'f_close_date' => 'Perkiraan Closing', 'f_task_title' => 'Judul Tugas *', 'f_due' => 'Tenggat',
        'f_priority' => 'Prioritas', 'f_role' => 'Peran', 'f_title' => 'Jabatan', 'f_password' => 'Kata Sandi',
        'f_client_type' => 'Tipe Klien', 'f_delivery_service' => 'Jasa Kirim', 'f_delivery_date' => 'Tgl Kirim',
        'f_shipping' => 'Ongkir (Rp)', 'f_payment_term' => 'Termin Bayar', 'f_custom_days' => 'Hari Termin', 'f_address' => 'Alamat Klien',
        'login_title' => 'Masuk', 'login_email' => 'Email', 'login_password' => 'Kata Sandi', 'login_btn' => 'Masuk',
        'print_invoice' => 'INVOICE', 'print_do' => 'SURAT JALAN',
    ],
];

function current_lang(): string
{
    $lang = $_SESSION['lang'] ?? 'zh';
    return in_array($lang, ['zh', 'id'], true) ? $lang : 'zh';
}

function t(string $key): string
{
    $lang = current_lang();
    return I18N[$lang][$key] ?? I18N['zh'][$key] ?? $key;
}
