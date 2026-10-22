# 任务卡 · 财务 AI（FA-AI-00 ~ 03 + FA-TAX-01）v2

> 交给 Claude Code 按顺序开发：FA-AI-00 → FA-AI-01 → FA-TAX-01 → FA-AI-02 → FA-AI-03。04、05 等财务板块主体上线后再出卡。
> 定位：财务板块的"AI 前置部分"。先把最费人工的事（对流水、销项 Faktur 制表、报销录入、进项发票核对）接住，财务板块其余部分（应付、银行现金流、月结）之后按原计划开发，并直接复用这里的数据。
> 依赖：现有销售板块的 Invoice 与 AR、HR-01 员工表、`app/Claude.php`（现有 Anthropic 调用出口）。
> v1 出卡 2026-10-22；**v2 同日**：按财务提供的真实样本补入解析规格、现用口径、新增 FA-TAX-01。
> 真实样本只留在老板手里，**不进仓库**（`.gitignore` 已排除 `/samples/`）。仓库里放的是 `samples_test/`（脱敏后的同格式测试文件），解析器与测试都用它。

---

## 0. 样本分析结论（v2 新增，开发前必读）

### 0.1 账户与分工
| 账户 | 文件 | 性质 |
|---|---|---|
| **BCA Giro**（账号后四位 6865） | `CorpAcctTrxn*.csv`（KlikBCA Bisnis 导出）；另有月度 e-Statement PDF | **经营户**：几乎所有客户回款进这里，日常费用、对工厂付汇也从这里出。10 月 1–22 日：进账 147 笔、出账 87 笔 |
| **ICBC Indonesia**（后四位 7082） | `currentaccounthistory*.csv`（制表符分隔） | **工资税务户**：出账为工资、BPJS 以外的税款（REKENING PENERIMAAN NEGARA TERPUSAT = 国库）、客户 PIC 的 Fee；进账只有从 BCA 的 Pindah Dana 和利息。**没有客户回款** |

→ 回款匹配（AI）只对 **BCA 进账**做；ICBC 导入后按规则打标即可（工资 / 税款 / 内部转账 / 利息），不调 AI。

### 0.2 BCA CSV 解析规格
- 编码 UTF-8，逗号分隔，所有字段带双引号
- 第 1–6 行为抬头（含 `No. rekening`、`Periode`），**第 7 行是表头**：`Tanggal Transaksi, Keterangan, Cabang, Jumlah, Saldo`
- 日期 `DD/MM/YYYY`；**`Tanggal Transaksi = "PEND"` 为未入账的待处理交易，导入时跳过**（下次导出会变成正式记录）
- 金额 `"3,500,000.00 CR"` / `"180,000.00 DB"`：千分位逗号、小数点；后缀 CR = 进账、DB = 出账
- 文件尾 4 行 `Saldo Awal / Mutasi Debet / Mutasi Kredit / Saldo Akhir` 为汇总，跳过；可用于校验：导入后进账合计应 = Mutasi Kredit、笔数一致
- 唯一哈希 = 日期 + Keterangan 原文 + 金额 + Saldo（BCA 同日同金额同备注会重复出现，加 Saldo 才唯一）
- **Keterangan 结构**（付款人名称一律在末尾，被截断到约 18 字符）：
  - `TRSF E-BANKING CR 0110/FTSCY/WS95051 3500000.00  <备注> <付款人>`：网银转账，中间数字为金额重复，备注是客户自己填的
  - `BI-FAST CR TRANSFER   DR 535 <付款人>`：BI-FAST，535/002/008 为对方银行代码
  - `SWITCHING CR TRF <付款人>  002  NEW BRI MOB`：ATM/手机银行跨行
  - `SETORAN VIA CDM` / `SETORAN TUNAI`：现金存款，付款人名可能为空
  - `GoPay Bank Transfe ... DOMPET ANAK BANGSA`、`ESPAY DEBIT INDONE`、`SHOPEE`：电子钱包/平台代收，付款人名在 ID 后或没有
- 备注里**可提取的发票线索**（规则先抓，抓到直接进规则匹配）：
  - 发票号：`1229/AMICO/09/26`、`1245/AMI-CO/10/26`、`PAYMENT INV 1330/AMI-CO/09/26`、`1302,1303,1309-AMI` → 正则抓 3–4 位数字 + `AMI`
  - CO 号：`CO 1222`、`Fee CO 1240`、`Kelebihan Tf Inv 1309`
  - 口语：`lunas`（付清）、`sisa 4.jt`（还剩 400 万）、`bayar acp`、`DP`、`pelunasan`
- 客户大多用**个人名字**付款（店主本人），与 CRM 里的客户名（店名或 "姓名 / 店名"）不同 → 别名表是关键；首次导入用现有 Buku Bank 做种子（见 0.4）

### 0.3 ICBC CSV 解析规格
- **制表符分隔**，字段形如 `"01-08-2026 00:46:39\t"`（引号内带尾随制表符，需 strip）
- 第 1–7 行抬头，**第 8 行表头**：`Seq., Transaction Time, Value Date, Business Type, Summary, Remark, Credit Amount, Debit Amount, Balance, Certificate number, Counterpart Account, Receiver, Transaction Vanue`
- 日期 `DD-MM-YYYY HH:MM:SS`；金额**印尼格式** `1.005.321,88`（点为千分位、逗号为小数）；进出账分两列
- 末行 `Total` 跳过
- `Receiver` = 对方户名，`Counterpart Account` = 对方账号（**不入库**，只用于识别内部转账：对方账号 = 自己 BCA 账号 → 标"内部转账"）
- 规则打标：Summary 含 `Salary` → 工资；Receiver 含 `PENERIMAAN NEGARA` 或 `PAJAK` → 税款；`Pindah Dana` → 内部转账；`Bunga` → 利息；`BPJS` → 社保；其余 → 待分类

### 0.4 财务现用工作簿（`Point_*.xlsx`）= 现有流程，平台要替代的就是它
| 表 | 内容 | 对平台的意义 |
|---|---|---|
| `1. Mutasi Bank BCA / ICBC` | 财务**手工**把每条流水编号（`BCA-IN-2610-0001` / `BCA-OUT-…`）并填上对应 Invoice 号 | 8–10 月已有 **485 条进账 ↔ Invoice 的人工配对**。用途：① 别名种子（付款人名 → 客户）；② AI 匹配的**基准测试集**（跑一遍比准确率） |
| `5. Catatan Penerimaan` | 每张 Invoice 一行：总额、已收、差额、状态、付款日期；**最多 6 次付款明细**（Tanggal/Kode/Ket/No.Invoice/Nominal ×6）；Kelebihan TF（多付退款）的流水号；Insentif/Fee Client；Ongkos Kirim | 答了"AR 是否有收款明细"：**有，且一张票最多 6 笔**。`payment_allocations` 设计够用 |
| `6. Pembukuan` | "Konsultan Luar menggunakan Accurate" | **记账由外包会计用 Accurate 做**。平台不做账，输出做成 Accurate 可导入格式（待拿 Accurate 导入模板） |
| `7. Pajak Keluaran` | 每张 Invoice 标 `FAKTUR / GUNGGUNG / TBC` | 销项分类口径，见 FA-TAX-01 |

**现用回款状态口径**（平台沿用，不另起名）：
`PAID - Balance`（正好）/ `PAID - Lebih Bayar`（多付，需退）/ `PAID - Kurang Bayar`（少付但结案）/ `Sebagian - Kurang Bayar`（部分付）/ `UNPAID - Kurang Bayar`（未付）/ `PAID - Shoppe`（Shopee 平台结算）/ `PAID - Gudang`（仓库现收）/ `PAID - Cash` / `LOST`（坏账）。

### 0.5 税务相关文件
- `DETAIL_DATA_INVOICE.xlsx`：1–10 月**发票行级明细** 5 万行（含 NPWP、Kode Barang 760600、单位 UM0021、`11/12 Coratax`、TAX CODE、Kategori）。这是财务手工维护、用来生成 Faktur 模板的中间表 → 平台直接从 Invoice 表生成，**此表可淘汰**
- `ACP_Faktur_PK_Template_v1.6.1.xlsx`：**DJP 官方 Coretax 导入模板**（Excel → XML），财务已在用。两张数据表 `Faktur`（18 列）+ `DetailFaktur`（14 列）+ 参考表。要点：`Kode Transaksi = 04`、`DPP Nilai Lain = DPP × 11/12`、`Tarif PPN = 12`、`PPN = DPP Nilai Lain × 12%`、买家 `NPWP/NIK` 16 位、`ID TKU = NPWP + 000000`。与 `invoice_amounts_coretax()` 的口径一致
- `Monthly tax confirmation PPh.xlsx`：财务给老板的月度税务确认（中印双语）：PPh 21 清单（员工 + 个人供应商）、PPh Unifikasi（Pasal 23 等）。平台后续月结报表可照此格式
- **8 月实际量**：PPN 发票 171 张 = Faktur 92 + 零售 digunggung 79；Bukti Potong：PPh 21 ×19（员工 17、供应商 2）+ Unifikasi ×6。→ 走 Coretax 模板导入足够，PJAP 直连暂不考虑

### 0.6 发现的旁支问题（不在本卡范围，记下）
- 销售通过 **Google Form 申请 CO 号**（`Request CO Number`，格式 `0001/AMI-CO/02/26`，已 1477 条），银行备注里客户也写 CO 号。CRM 已有订单审批与 Invoice，CO 号是否并入 CRM 订单号，待老板定
- `Insentif / Fee Client`（付给客户 PIC 的费用，从 ICBC 出，备注 `Fee CO 1240`）：平台只记录、不判断；归应付模块
- 历史回填：8–9 月 BCA 只有 PDF 对账单，**不写 PDF 解析器**；历史数据从 Buku Bank Excel 导入即可

---

## 总原则（各卡共用）

1. **AI 只读、只建议，不落账。** AI 的输出一律存为"建议"，由人确认后才生成正式记录。金额、税额、余额全部由规则计算，AI 不算数。
2. **规则优先，AI 兜底。** 每项功能先跑规则，规则处理不了的才调用 AI。人确认过的结果反哺规则（如付款人别名），AI 调用量应逐月下降。
3. **替代现有工作，不增加步骤**（章程第二原则）。确认动作一步完成；AI 失败或额度用完时功能照常可用，退回手工。
4. **可追溯。** 每次 AI 调用、每条建议、谁确认、确认时改了什么，全部留痕。
5. **只发必要数据。** 发给 API 的只有完成该任务所需的字段；公司自己的银行账号、对方账号、员工 NIK、薪资不发。
6. **真实数据不进仓库。** 开发与测试只用 `samples_test/`；代码和测试里不得出现真实户名、账号、金额、客户名。
7. 沿用仓库既有约定：零依赖（Excel 用 `app/Export.php` 的 OOXML 直写，CSV 解析用原生 PHP）、`ensureSchema()` 幂等升级、写操作过 `Csrf::verify()` 并 `audit()`、双语 `t()`、附件存 `data/` 私有目录。

---

## FA-AI-00 · AI 调用基础设施

### 与现有 `app/Claude.php` 的关系
仓库已有 `Claude::send()`（token 上限、瞬时错误重试、每日 token 预算、`ai_queries` 台账）。**在它之上扩展，不另起炉灶**：
- 保留 `Claude::send()` 作为唯一的 HTTP 出口
- 新增一层"任务调用"（可放 `app/Ai.php`）：传入任务类型、关联记录、输入内容 → 调 `Claude::send()` → 按 schema 校验 JSON → 落 `ai_calls`
- 客户分析（`Enrich.php`）的调用迁移到新层，行为不变
- `ai_queries` 若字段够用可直接扩展为 `ai_calls`，否则新建表并让 `Claude::budget_left()` 同时统计两者；二选一，写进度时说明

### 任务调用层
- 模型按任务类型在 `config.php` 的 `ai.tasks[...]` 里指定（便宜档 / 标准档），不在代码里写死
- 输出必须是 JSON，按每个任务的 schema 校验；不合格重试 **1 次**，仍失败则标记"AI 未处理"转人工，**不循环重试**（与 `Claude::send()` 的瞬时错误重试是两回事）
- 提示词放在独立文件 `prompts/{task}_v{n}.txt`，带版本号；改提示词即升版本，旧版本保留
- 图片发送前压缩（长边 ≤ 1568px）；PDF 只发需要的页

### 数据表 `ai_calls`
- `task_type`、`ref_table`、`ref_id`、`model`、`prompt_version`
- `input_tokens`、`output_tokens`、`cost_usd`（按配置的单价算）、`latency_ms`
- `status`（成功 / 校验失败 / 接口错误 / 超预算跳过）、`error`、`created_at`

### 预算保护
- 在现有每日 token 预算之外，加**美元**口径：每日上限、每月上限。达到上限后当天/当月所有 AI 调用跳过，状态记"超预算跳过"，功能退回手工，并给老板生成一条待办
- 同一记录同一任务 24 小时内最多调用 2 次（防 bug 重复调用）

### 准确率统计
- 每条 AI 建议记录 `ai_suggestion`（JSON）、`ai_confidence`、`final_value`、`confirmed_by`、`confirmed_at`、`ai_accepted`（是否原样采纳）
- 老板/财务可见一页「AI 使用情况」：本月各任务调用次数、费用、采纳率、被改动最多的字段

### 验收
- 人为让接口返回非 JSON，系统重试 1 次后转人工，不死循环
- 把每日上限设为 $0.01，次日调用全部跳过，功能仍可手工操作
- 「AI 使用情况」页数字与 `ai_calls` 汇总一致
- 客户分析的行业推断在迁移后结果不变（跑现有测试）

---

## FA-AI-01 · 银行流水对账（回款匹配）

### 目标
财务导入银行流水后，进账自动对应到 Invoice，确认后 AR 自动更新。**替代 `Point_*.xlsx` 的 `1. Mutasi Bank` 和 `5. Catatan Penerimaan` 两张表的手工维护。**

### 流程
1. **导入**：财务上传 BCA CSV 或 ICBC CSV，系统按文件抬头自动识别银行（BCA：首行 `Informasi Rekening`；ICBC：首行 `Branch Name:`），按 0.2 / 0.3 规格解析。每条流水生成唯一哈希，重复导入自动跳过；导入后显示"本次新增 x 笔、跳过 y 笔、进账合计与文件汇总是否一致"
2. **ICBC 全部、BCA 出账**：只按规则打标存档，不匹配（出账匹配留给应付模块）
3. **BCA 进账 · 规则匹配（不调 AI）**，按顺序：
   - 备注里抓到发票号（0.2 正则）且该 Invoice 未结、金额 ≤ 余额 → 「规则匹配」
   - 付款人名称命中已确认别名，且金额 = 该客户某张未结 Invoice 余额（允许 ±1,000 Rp 的四舍五入差） → 「规则匹配」
   - 金额唯一对应全系统一张未结 Invoice，且付款人名与客户名相似度高 → 「规则匹配」
   - 付款人为 `DOMPET ANAK BANGSA` / `ESPAY` / `SHOPEE` 等平台 → 标「平台代收」，走 AI 时提示"按金额匹配，付款人不可用"
4. **AI 匹配**（规则没配上的 BCA 进账）：
   - 发送：流水日期、金额、Keterangan 原文（去掉 `WS95051` 等银行内部码）；候选 = 近 120 天内未结 Invoice（编号、客户名、别名、PIC、开票日、余额），按金额与名称相似度预筛，最多 30 张
   - AI 返回：匹配的 Invoice 及每张分配金额、置信度（高/中/低）、原因（印尼语 + 中文各一句）、差额类型
   - 要能识别：分次付（`sisa 4.jt`）、合并付（一笔付多张）、店主个人账户代付、多付（→ 建议 Lebih Bayar）、少付（手续费 / 代扣税 / 四舍五入）、口语线索（`lunas`、`DP`）
5. **财务确认**：列表显示建议；财务点「确认」一步完成；可改分配或手选发票。规则匹配的可「全部确认」
6. **确认后**：生成 `payment_allocations`，Invoice 已收额与状态按 0.4 口径更新；付款人名称写入别名表；流水号按现有编号规则自动生成（`BCA-IN-YYMM-NNNN`），财务不用再手编
7. **非货款进账**：财务可标记为 股东注资 / 退款 / 利息 / 内部转账 / 其他，不进 AR
8. **历史导入（一次性，CLI 工具 `tools/import_bank_book.php`，默认预演、`--apply` 写入）**：从 `Point_*.xlsx` 导入 `1. Mutasi Bank BCA` 的 IN 行 → 生成别名种子（付款人名 ↔ Invoice 客户），并作为基准集。真实文件由老板在服务器上跑，测试只用 `samples_test/Finance_Workbook_SAMPLE.xlsx`

### 数据表
- `bank_accounts`：银行名、账号后四位、币种、解析器类型（bca_csv / icbc_csv）
- `bank_transactions`：`account_id`、`trx_date`、`amount`、`direction`、`description`、`counterparty_name`（规则从备注提取）、`hash`、`trx_code`（`BCA-IN-2610-0001`）、`tag`（货款 / 平台代收 / 工资 / 税款 / 内部转账 / 利息 / 费用 / 待分类）、`match_status`（未匹配 / 规则匹配 / AI 建议 / 已确认 / 非货款）、`ai_*` 字段（同 FA-AI-00）
- `payment_allocations`：`transaction_id`、`invoice_id`、`amount`、`diff_amount`、`diff_reason`（多付 / 手续费 / 代扣税 / 四舍五入 / 其他）、`refund_transaction_id`（多付退款对应的出账流水，可空）
- `customer_payer_aliases`：`customer_id`、`payer_name`（流水里出现的名称原文）、`source`（历史导入 / 财务确认）、`confirmed_by`、`created_at`
- 现有 `invoices` 的收款字段若只有状态没有明细，以 `payment_allocations` 为准回算已收额；不改现有发票页面的展示逻辑

### 口径
- **回款日期 = 银行流水日期**，不是财务确认日期（提成按此计）
- 差额属于客户代扣税的，Invoice 视为已收清，差额另记"待收 Bukti Potong"
- 回款状态沿用 0.4 的现有名称

### 验收
- 用 `samples_test/` 两个 CSV 导入：行数、进账合计与文件尾汇总一致；PEND 行未入库；同一文件导两次第二次全部跳过
- 历史导入后，拿 Buku Bank 中 9 月的 IN 行当盲测：隐藏其 Invoice 号，让规则 + AI 重新匹配，**准确率记录在案**（目标：规则 + AI 合计 ≥ 85%，AI 高置信建议错误率 ≤ 5%）——此项在服务器上用真实数据跑，由老板执行
- 构造分次付、合并付、个人账户代付、多付各一例，AI 建议正确且原因可读
- 第二次出现同一付款人名称时走规则匹配，不调 AI
- 确认后客户分析和 AR 账期预警读到新余额

### 不做
网银 API 直连、PDF 对账单解析、出账匹配、自动确认（无论置信度多高都要人点）

---

## FA-TAX-01 · 销项 Faktur 导入表生成（规则，不用 AI）

### 目标
每月（或每周）从平台 Invoice 直接生成 DJP Coretax 导入模板（`Faktur` + `DetailFaktur` 两张表），财务上传 Coretax 后把发票号导回。**替代 `DETAIL_DATA_INVOICE.xlsx` 的手工维护和模板手工填写**，也替代现在 Invoice 上"补录 Faktur 号"的步骤。

### 流程
1. 平台上每张 Invoice 自动预分类 `FAKTUR / GUNGGUNG / TBC`：
   - 客户有 NPWP（16 位，或 15 位旧号可转）或 NIK → FAKTUR
   - 无 → GUNGGUNG（零售合并申报）
   - 客户资料里 NPWP/NIK 为空但客户类型为公司/经销商 → TBC，提醒销售补
   - 财务可手动改
2. 财务选期间 → 生成模板 Excel（结构严格按 v1.6.1：表头行、列顺序、参考表都保留；`Baris` 连号；`Tanggal Faktur` 为 DD/MM/YYYY；`Kode Transaksi` 默认 04；`DPP Nilai Lain = DPP × 11/12`；`Tarif PPN = 12`；`PPN = DPP Nilai Lain × 12%`；`ID TKU = NPWP + 000000`；`Kode Barang Jasa = 760600`、`Nama Satuan Ukur = UM.0021`，这些常量放 `config.php`）。金额口径直接复用 `invoice_amounts_coretax()`，不要另算
3. 生成前校验并列出阻断项：NPWP 位数不对、地址为空、金额与 Invoice 合计不符
4. 财务上传 Coretax，拿到结果后把 Coretax 导出的发票号清单（格式待财务提供一份）上传平台，按 `Referensi`（= Invoice 号）回写 `faktur_no`
5. GUNGGUNG 清单单独导出一张汇总表，供申报 digunggung 使用
6. 按期间保存生成的文件（私有目录），同期间重复生成覆盖前标记旧版

### 验收
- 用 `samples_test/Coretax_Faktur_Template_v1.6.1_SAMPLE.xlsx` 作为目标，生成的文件列名、列序、参考表与之完全一致
- 模板里 PPN 金额与平台 Invoice 的 PPN 合计一致（容差 1 Rp/行）
- 一张 Invoice 多个 SKU 行 → DetailFaktur 多行、同一 Baris

### 不做
XML 直接生成（模板已够）、Coretax 直连、进项、SPT、Bukti Potong

---

## FA-AI-02 · 报销票据识别

### 目标
员工拍小票即可报销，不手填；财务看到的是结构化数据。流水里现在的做法：员工先垫付，财务汇总后从 BCA 转账（备注 `Reimburse …`）。按现有审批人配置（仓库里已有行政申请的报销审批流，先看 `approvals` 能否直接复用），不新增审批层级。

### 员工端（手机网页，与拜访记录同一入口风格）
1. 拍照或选图（可一次多张）
2. AI 识别后表单预填：日期、商户、金额、PPN（如有）、商户 NPWP（如有）、建议类别
3. 员工核对，可改，补一句用途（可选）→ 提交
- AI 失败或超预算：表单空白，员工手填，照常提交

### 后台
- 待处理报销列表：员工、日期、商户、金额、类别、原图；审批人一步「通过」，财务一步「已付」（已付时可选关联 BCA 出账流水）
- 标记（仅提示，不拦截）：疑似重复（同一图片哈希，或同商户 + 同日期 + 同金额）；员工改过 AI 读出的金额；图片模糊 / AI 低置信
- 每月导出报销明细 Excel

### 数据表 `expense_claims`
- `claim_no`、`employee_id`、`receipt_date`、`merchant`、`amount`、`ppn_amount`、`merchant_npwp`、`category`、`purpose`
- `photo_path`（私有目录，规则同 HR-00）、`image_hash`
- `status`（已提交 / 已通过 / 已付 / 退回）、`paid_transaction_id`、`flags`、`ai_*` 字段
- 类别下拉由财务在配置页维护（先用：交通 / 油费 / 停车过路 / 餐饮招待 / 办公用品 / 快递 / 送货费 / 其他；流水里高频的是 Deliv Fee、Transport、Entertain Client）

### 验收
- 用 10 张真实小票测试（含热敏纸褪色、手写收据各至少 1 张），记录识别准确率
- 同一张图提交两次，第二次被标记疑似重复
- 关闭 AI 后员工仍可手填提交

### 不做
预支借款、差旅标准控制、与薪资合并发放、自动付款

---

## FA-AI-03 · 进项发票（Faktur Pajak Masukan）核对

### 目标
每月进项发票有一张清单，并与 Coretax 中的进项数据核对，找出"供应商没上传、不能抵扣"和"金额不一致"的票。

### 流程
1. 财务上传当月收到的供应商 Faktur PDF/扫描件（可批量）
2. AI 提取：发票号（NSFP）、日期、供应商名称与 NPWP、DPP、PPN、商品/服务摘要
3. 财务上传当月 Coretax 进项数据导出文件（格式按财务提供的样本解析，不调 AI）
4. 规则核对（不调 AI），按发票号 + NPWP 配对，输出：一致 / 有票据缺 Coretax → 提醒联系供应商 / Coretax 有缺票据 / 金额不一致
5. 导出当月进项清单 Excel，供财务申报使用

### 数据表 `input_tax_invoices`
- `period`、`faktur_no`、`faktur_date`、`supplier_name`、`supplier_npwp`、`dpp`、`ppn`、`description`、`file_path`
- `coretax_match`（一致 / 缺 Coretax / 缺票据 / 金额不符）、`ai_*` 字段
- 同一发票号不可重复登记

### 验收
- 用一个月真实发票测试，提取的 DPP/PPN 与票面一致率记录在案
- 人为删掉 Coretax 文件中一行，系统正确标出"缺 Coretax"

### 不做
进口 PIB 的 PPN（来自工厂进货，财务板块应付部分处理）、SPT 填报、Coretax API 对接

---

## 之后再出卡（等财务板块主体）
- **FA-AI-04 费用归类与代扣税提示**：会计由外包用 Accurate，需先拿 Accurate 的科目表与导入模板
- **FA-AI-05 月结说明与异常监测**：依赖月结报表与 Agent 推送渠道；月度税务确认可沿用现有双语格式

## 仍需财务提供
1. 报销小票照片 10–20 张（FA-AI-02 开发前）
2. 一个月供应商 Faktur 文件 + 同月 Coretax 进项导出（FA-AI-03 开发前）
3. Coretax 上传后导出的"发票号结果清单"样本（FA-TAX-01 第 4 步）
4. 外包会计的 Accurate 导入模板 / 科目表（FA-AI-04 前）
5. 现行报销审批人是谁

## 成本预估
BCA 每月进账约 200 笔，规则匹配后预计 60–100 笔走 AI；加小票、进项发票，约 $5–10/月；Console workspace 月上限建议 $50。
