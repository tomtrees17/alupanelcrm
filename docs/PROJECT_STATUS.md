# AluPanel CRM — 项目上下文摘要 / Handoff

> 本文档用于保存项目全貌，便于跨会话/上下文续接。最新进度以 `git log` 为准（已含：双语、打印模板、含税发票、库存预留防超卖、角色与多级权限、记录级权限、客户归属分配）。

## 1. 项目概述

- **名称**：AluPanel CRM（侧边栏 logo 显示 `AluPanelCRM`）
- **业务**：铝塑板（ACP）销售 CRM / 轻量 ERP，面向**印尼市场**
- **真实公司**：PT ALUPANEL MULIA INDONESIA
- **来源**：按规划原型 `crm-system_25.html`（NexusCRM）实现（该原型文件在早期的 Windows 开发机上，未进仓库）
- **仓库**：https://github.com/tomtrees17/alupanelcrm （main 分支，**private**，2026-08-28 由公开转私有）
- **本地路径**：`~/projects/alupanelcrm`（macOS；早期在 Windows `D:\AluPanelCRM`）

## 2. 技术栈

- 后端：**纯 PHP + PDO**（无框架、无 Composer）
- 数据库：**SQLite**（`data/crm.sqlite`，已 gitignore，首次访问自动建库+示例数据）。连接启用 **WAL + busy_timeout=5s + synchronous=NORMAL**，支持多人并发读写不锁库（`Database::connect`）。会生成 `crm.sqlite-wal/-shm` 临时文件（已 gitignore）
- 前端：服务端渲染 PHP 模板 + 原生 JS/CSS，无构建步骤
- 路由：前端控制器 `public/index.php?r=controller.action`
- 货币：印尼盾 IDR（Rp）
- 语言：中文 / 印尼语（id）可切换

## 3. 运行方式

**本地**（macOS，路径 `~/projects/alupanelcrm`）：
```
php -S localhost:8000 -t public
```
打开 http://localhost:8000 。PHP 不在 PATH 时用 `brew install php` 安装或用绝对路径调用。重置数据：删除 `data/crm.sqlite`（连同 `-wal/-shm`；被沙箱保护时用 PHP `unlink` 删）。

**服务器**（宝塔，已部署）：
- 域名/站点：`www.alupanel.cc`，路径 `/www/wwwroot/www.alupanel.cc`
- SSH：`ssh -p 22022 root@149.129.218.9`（**端口 22022，非默认 22**）
- PHP 8.2（已确认 `pdo_sqlite`/`sqlite3` 启用）
- **网站运行目录必须设为 `/public`**
- `data/` 目录需 `www` 用户可写：`chown -R www:www data && chmod -R u=rwX,go=rX data`
- 更新代码：`cd /www/wwwroot/www.alupanel.cc && git pull`（发布流程见 CLAUDE.md 第 4 节：本地改 → push GitHub → 服务器 git pull）

## 4. 默认账号（初始密码均 `admin123`，**首次登录强制改密**，见 6c）

| 邮箱 | 角色 |
|---|---|
| admin@alupanel.local | 管理员 admin |
| mutiara@alupanel.local | 经理 manager |
| sari@alupanel.local | 主管 supervisor |
| ahmad@alupanel.local | 销售 sales |
| joko@alupanel.local | 仓库 warehouse |
| rina@alupanel.local | 人事 hr |

## 5. 模块（9 + 用户）

数据看板、客户管理、销售漏斗(deals)、任务提醒、财务管理(invoices)、订单审批(orders)、库存管理(products)、**行政审批(approvals)**、**审计日志(audit)** + 用户管理。

## 6. 关键业务逻辑

**订单四级审批流**：销售→主管(supervisor)→经理(manager)→仓库(warehouse)。
- 状态：`draft / pending_sup / pending_mgr / pending_wh / approved`（旧 rejected 仅历史数据）
- 新建订单可**保存草稿**或**提交审批**（表单两按钮 do=draft|submit）。草稿/被驳回订单可由本人或 admin **编辑**(`order_editable()`)；草稿不占库存，提交时才校验可用库存并预留
- 仅对应角色（或 admin）能在该阶段审批（`order_action_role()`）。**驳回 = 退回草稿**：记录 `reject_note/by/date`、清空已有审批、释放预留，销售改后可重新提交
- 仓库「确认出货」(`fulfill_order`) 自动：扣库存(out_auto) + 生成送货单DO + 生成发票
- `save_order()` 统一处理建/改；`submit_order()` 草稿→pending_sup；`orders.reject_note/by/date` 字段由 ensureSchema 自动加

**税务（印尼 2025，价格含税）**：
- 订单输入单价为**含税价**；开票时反算 pre-tax = 含税价 / 1.11
- 发票：Subtotal(税前) + VAT12% = 含税总额（不再额外加税）
- DPP = Subtotal × 11/12，VAT12% = DPP × 12% = Subtotal × 11%，Total = Subtotal + VAT
- 发票号格式 `N - AMI - INV - MM - YY`
- 已与公司真实模板核对一致（235.000×3 含税 → 单价 211.711,71 / Subtotal 714.262 / VAT 78.569 / Total 792.831）
- **发票合计 = 订单含税额，差额记为 Pembulatan（尾差调整）**（2026-10-12）：逐行四舍五入后 Subtotal + VAT 会和订单含税整数差 1–3 卢比。Subtotal、DPP、VAT **一个都不动**（仍严格等于原算法、VAT = Subtotal×11%），只在 `invoices.rounding` 记差额、`total` 等于客户被报的金额。打印件 / Word / 详情页只在非零时显示「Pembulatan」一行；Excel 导出加「尾差调整」列。计算统一在 `invoice_amounts()`（`domain.php`），`fulfill_order()` 调用它。
- **税前单价带 2 位小数**（含税价 ÷ 1.11 的结果）。打印件、Word 导出、发票详情页三处必须都用 2 位小数渲染，否则 `单价 × 数量` 与行小计对不上——2026-08-29 修过一次：详情页用了 `idr()`（0 位）把 `214.414,41` 显示成 `Rp 214.414`，而小计仍按完整值算成 `428.829`，客户拿计算器一按就发现不一致。`idr($v, 2)` 用于发票单价；**订单页不适用**，订单单价是用户输入的含税整价。

**库存预留防超卖**：
- `products.reserved` 列，**可用 = stock − reserved**
- 下单即预留(`recompute_reservations`，按所有 pending 订单求和)；驳回/删除释放；出货实扣并释放
- 新建订单按**可用库存**校验（客户端标红阻止提交 + 服务端拒绝）；每级审批再按物理库存校验
- `order_items.product_id` 已记录；线上旧库由 `Database::ensureSchema()` 自动加列+回填+重算

**打印**：发票(`print/invoice.php`，公司抬头+Bill To+DPP/VAT+双银行ICBC/BCA+签字+terbilang金额大写) 与送货单(`print/do.php`，SURAT JALAN)，A4 样式 `public/assets/css/print.css`。抬头用 **ALUSIGNPANEL logo 图片**：`views/print/invoice.php` 按 `logo-print.png → .svg → .jpg` 顺序查找 `public/assets/img/`，命中即用。真实 PNG（524×112）**原先只存在于服务器上、未进 git 也不在备份范围**，2026-08-28 已提交进仓库。打印时 `no_cjk()` 剔除所有中文（规格如 `4.0*0.15抗刮木纹` 印成 `4.0*0.15`，单位「张」→Unit,库内数据不动）。

**Word 导出（可手改再打）**：`finance.word` / `delivery.word` 生成可编辑 Word 文档（`app/Word.php`，MSO-HTML `.doc`，零依赖，Word/WPS 直接打开编辑）。权限 `can_word_export()`＝admin + 仓库(can_edit_inventory) + 财务(can_access('finance'))；发票 Word 还需过 finance 模块路由（warehouse 只能导送货单）。入口：发票详情/打印页、送货单列表/打印页的「导出 Word」按钮。内容同打印版（含 terbilang、双银行、签名位），同样零中文。

## 6b. 角色与访问控制（重点）

**角色**（`all_roles()`，共 9 个）：admin、manager(经理)、finance_manager(财务经理)、ops_supervisor(运营主管)、supervisor(主管)、sales(销售员)、warehouse(仓库/库存管理员)、hr(人力资源)、clerk(文员)。i18n 含中印双语标签。

**① 模块级权限**（`role_permissions` 表 + `can_access($module)`）
- bootstrap 载入 `$GLOBALS['permissions']`；前端控制器(`public/index.php`)拦截越权模块、导航按权限隐藏。
- 可配置模块：customers/pipeline/tasks/finance/orders/inventory/approvals/**audit**；另有视图权限 `performance`(看板全员业绩) 和 `export`(导出 Excel)。
- 默认：sales/supervisor/warehouse/hr/clerk **无 finance**；`export` 默认仅 manager；admin 始终全权。
- 管理员在 **权限设置(roles.index)** 用「角色×权限」勾选矩阵实时配置。
- 看板财务卡片(营收/逾期)按 `can_access('finance')` 显示；全员业绩卡按 `can_access('performance')`，无此权限者改看「我的业绩」(仅本人 submitter 的订单)。

**② 记录级权限**（helpers：`can_edit_inventory()` / `sees_only_own()` / `own_name()`）
- **库存**：增删改产品/调库存仅 admin + warehouse；其他有库存权限者**只读**（控制器拦写操作 + 列表隐藏按钮）。
- **销售(sales)**：只看/改**自己的订单**(`orders.submitter == 本人姓名`，列表/详情/计数/看板最近订单均过滤、`find_order` 校验)与**自己的客户**(`customers.owner` 字段，列表/详情过滤，`find_customer` 校验)；下单时客户下拉也只列自己的客户。
- **客户归属**：`customers.owner` 在新建时记为创建者；管理员/经理可在客户表单的「负责销售」下拉**改派**，客户列表显示归属列；销售本人不可改派。

**④ Excel 导出**（`app/Export.php`，`can_export()`=`can_access('export')`）：库存(inventory.export)、财务报表(finance.export)、客户列表(customers.export) 三个列表页右上「导出 Excel」按钮（仅有 export 权限者可见，动作服务端二次校验）。无依赖生成真 `.xlsx`(ZipArchive 写 OOXML)；服务器无 zip 扩展时自动降级带 BOM 的 CSV。数字列写为数值型。

**③ 线上库自动升级**（`Database::ensureSchema()` + `app_meta` 标记一次性迁移）：自动建/补 role_permissions、加 customers.owner 并按历史订单回填、加 products.reserved、补 performance 与新角色默认权限——均不覆盖管理员后续手动调整。

## 6c. 安全加固（2026-06-17）

**① 会话 Cookie 加固**（`app/bootstrap.php`）：`session_start()` 前设置 HttpOnly + SameSite=Lax + Secure（自动识别 HTTPS / 反代 `X-Forwarded-Proto`）+ 命名 `ALUPANELSESS` + `use_strict_mode`/`use_only_cookies`。

**② 登录防爆破**（`app/domain.php` 的 `login_*` 助手 + `login_attempts` 表）：按客户端 IP 滑动窗口限速——15 分钟内失败 8 次即锁定（`LOGIN_MAX_ATTEMPTS`/`LOGIN_WINDOW_SECONDS`），提示剩余分钟数；登录成功清零、旧记录自动清理。登录页不再泄露默认账号/密码（移除 `admin123` 提示与预填邮箱）。

**③ 强制改默认密码**（`users.must_change_password` 列 + 前端控制器 `account` 模块）：仍用默认密码 `admin123` 的账号首次登录被强制跳转 `account.password` 改密（≥8 位且不同于旧密码），改完才放行其它页面（豁免 `account.*`/`auth.logout`/`lang.set`）。自助改密入口在侧边栏用户卡（全员可用）。线上库 `git pull` 后由 `ensureSchema` 自动加列，并把所有仍用 `admin123` 的账号标记为必须改密（一次性迁移 `pwd_policy_v1`；已本地验证迁移命中 6/6 且幂等、端到端 8 项全过）。

## 6c2. 行政审批（出差 / 报销 / 请假）

- **表** `admin_requests`，**模块键** `approvals`（进权限矩阵，默认所有角色可用；admin 始终全权）。控制器 `app/controllers/approvals.php`，视图 `views/approvals/{index,form,show}.php`。
- **类型** `request_types()`：trip(出差 BT-)、expense(报销 EX-)、leave(请假 LV-)、**payment(付款申请 PY-)**；单号 `next_request_no()` 格式 `BT-YYYY-NNN`。类别/假种/付款用途存 `category` 列（canonical 中文值，`tr_req_cat()` 翻译显示）。付款申请：`destination` 列复用为**收款方**，必填 收款方+金额；`ref_no` 列存**关联单号**（订单/发票/其他申请，表单 datalist 提示最近单号，详情页 `resolve_ref_link()` 自动解析为可点链接）。报销单也可填关联单号。
- **流程**：draft →（提交）→【出差/报销/请假 `request_needs_hr()`】`pending_hr`(**人事审批**,hr_note/approver/date) → `pending_mgr`(经理审批) →【报销&付款 `request_needs_finance()`】`pending_fin`(财务经理确认支付) → approved。付款申请不走人事,直接 pending_mgr(`request_first_stage()`)。**驳回=退回草稿**（记录 reject_note/by/date、清空已有审批含 hr_*），申请人改后重新提交。角色路由 `request_action_role()`：pending_hr→hr、pending_mgr→manager、pending_fin→finance_manager（admin 任意阶段可操作）。线上库 ensureSchema 自动补 hr_* 列;**线上需有 hr 角色用户**处理人事阶段(否则只能 admin 代批),种子已含 rina@alupanel.local。
- **职责分离**：非 admin **不能审批/驳回自己提交的申请**（`request_can_act()` 校验 applicant≠本人；否则经理/财务经理会给自己签批）。admin 作为最高权限例外可自签（小企业老板自己报销的兜底）。
- 已知低危：表单按类型切换字段依赖 JS（禁用隐藏区 input 防重名提交）；禁用 JS 时 `start_date/amount` 重名会串值，导致报销存不上。内部系统 JS 常开,暂未改；如需彻底修则给各类型字段起不同 name。
- **表单**：类型切换显隐字段（JS 同时 disable 隐藏 input 防重名提交）；草稿/提交两按钮 do=draft|submit；服务端按类型校验必填（trip:目的地+开始日；expense:金额>0+费用日期；leave:起止日期）。
- **记录级可见性** `approvals_sees_all()`：admin/manager/finance_manager/hr 看全部；其他角色只看/只能访问**自己的申请**（`find_request` 403+重定向）。草稿仅申请人/admin 可编辑/删除（admin 可删任意）。
- **附件**（`request_files` 表 + `data/uploads/req/<id>/`,在 web 根之外）：表单可多选上传 图片(jpg/png/webp)/PDF,单个 ≤8MB;**魔数检测**文件真实类型(`sniff_upload_mime()`,不依赖 fileinfo 扩展),伪装扩展名拒收;存随机文件名。下载走 `approvals.file&fid=`(经 `find_request` 可见性校验后 readfile 流式输出,外网无法直连文件)。草稿期申请人/admin 可删(`approvals.delfile`)。服务器 PHP `upload_max_filesize` 需 ≥8M(宝塔默认 50M,一般无需动)。
- **线上升级**：`ensureSchema` 自动建表 + 一次性给所有角色授 `approvals` 权限（app_meta `perm_approvals`）。侧边栏「行政审批」带待审批数徽标。
- 注意：pending_fin 需要有 `finance_manager` 角色用户处理（或 admin）；种子里没有该角色用户，线上请在用户管理里指派。

## 6d. 忘记密码 / 重置（运维）

服务器上用 CLI 工具重置任意账号密码（`tools/reset_password.php`，绕过登录直接改库）：
```bash
cd /www/wwwroot/www.alupanel.cc && git pull
/www/server/php/82/bin/php tools/reset_password.php                              # 不带参数 = 列出所有账号
/www/server/php/82/bin/php tools/reset_password.php admin@alupanel.local '新密码'   # ≥8 位
chown -R www:www data && chmod -R u=rwX,go=rX data                                       # 修正属主（必做）
```
- 重置 `password_hash` 并清 `must_change_password`，新密码当场生效。
- 对 `must_change_password` 列做**存在性判断**，故线上库尚未经 web 迁移（刚 git pull、还没人访问网站）时也能用（否则会报 `no such column`）。
- **应急免 git pull 版**（只改密码、不依赖新列）：
  `/www/server/php/82/bin/php -r '$p=new PDO("sqlite:data/crm.sqlite");$p->prepare("UPDATE users SET password_hash=? WHERE email=?")->execute([password_hash("新密码",PASSWORD_DEFAULT),"admin@alupanel.local"]);echo "ok\n";'`
- 坑：宝塔 PHP CLI 路径随版本变（`ls /www/server/php/` 查实际版本号）；密码用单引号包住更安全；两条命令分行别粘成一行。

## 6e. 数据备份（运维）

`tools/backup_db.php`：用 `VACUUM INTO` 生成**一致快照**到 `backups/`（WAL 下也安全,不需要 wal/shm),自动滚动保留最近 **14** 份。`backups/` 已 gitignore（不进仓库,也不在 web 根 `public/` 下,外网访问不到）。
- 宝塔「计划任务 → Shell 脚本」每天执行(PHP 路径按版本调整):
  ```
  /www/server/php/82/bin/php /www/wwwroot/www.alupanel.cc/tools/backup_db.php
  ```
- 恢复:停站 → 用某份快照覆盖 `data/crm.sqlite`(并删掉 `-wal/-shm`)→ `chown -R www:www data`。
- **异地备份（2026-10-13）**：`backup_db.php` 每次备份成功后把 zip 上传到**阿里云 OSS 存储桶 `alupanel-backup-crm`（雅加达 `ap-southeast-5`，和服务器同地域；地域建桶后不可改）**。同地域的取舍：防的是服务器被删/被黑/磁盘损坏，不防整个雅加达地域故障——OSS 本身多副本存储，这个风险可接受。桶名和地域写在 `config.php`（不是机密）。零依赖实现：`app/Oss.php`（PutObject + 签名 V4，规范请求和最终签名步骤用阿里云文档的示例做了测试锁定）。
  - **密钥**：放服务器上的 `config.local.php`（git 忽略），只需 `driver` / `key_id` / `key_secret` 三项，格式见 `config.local.example.php`。用的是 **RAM 子账号、权限仅 `oss:PutObject`**——服务器即使被攻破，攻击者也只能往桶里加文件，列不出、读不到、删不掉已上传的备份。存储桶开**版本控制**（同名覆盖也不丢旧版本）、私有读写、服务端加密，**备份永久保留不过期**（每天几 MB，成本可忽略）；生命周期规则只清理**历史版本 30 天**后删除。
  - **RAM 策略** `alupanel-backup-putonly`，授权给 RAM 用户 `alupanel-backup`：`Action: oss:PutObject`，`Resource: acs:oss:*:*:alupanel-backup-crm/alupanel/*`（只能写 `alupanel/` 前缀）。
  - **已启用（2026-10-13 00:28）**：探针上传成功；首个完整备份 `alupanel/backup_2026-10-13_002817.zip` 已入桶；另验证了往 `alupanel/` 以外的路径写入返回 **403 AccessDenied**——权限确实只限这一个前缀。此后每天 03:30 的备份计划任务自动上传，无需改计划任务。
  - **验证配置**：`/www/server/php/82/bin/php tools/backup_db.php --test-offsite`（只上传一个小探针文件，不做备份）。
  - **坑**：在宝塔编辑器里粘贴密钥时会带进一个换行符（ID 变 25 位、Secret 变 31 位），签名必然失败。`Oss::put_file()` 现在会 trim 凭据；排查时可只看长度不看内容：ID 应为 24 位 `LTAI` 开头、Secret 30 位。
  - **轮换密钥**：RAM → 用户 `alupanel-backup` → 认证管理 → 新建 AccessKey → 改服务器 `config.local.php` → 跑 `--test-offsite` → 禁用旧 key。
  - **失败可见**：上传结果写入 `app_meta`（`offsite_last_ok` / `offsite_last_error`），**管理员打开看板时**，若未配置、从未成功、最近一次失败或超过 48 小时没成功，顶部显示红色提醒（`Dashboard::offsite_health()`）。上传失败时备份脚本退出码 2，但本地备份照常保留。
  - **恢复**：OSS 控制台 → 存储桶 → `alupanel/` 下载对应日期的 zip → 解压出 `crm.sqlite` 和 `uploads/` → 按上面的恢复步骤覆盖。

## 6s. 客户分析 · 四、权限（2026-10-16）

> **Fera（clerk 账号）名下的 88 个客户是她本人的**——她负责 Shopee 渠道的客户（用户 2026-10-16 确认）。不要当作"挂在助理账号下的错位客户"去转给销售。

用户选定方案 A：**销售只开「回访清单」，不开「客户管理」**（线上 sales 角色原本只有「库存管理」，下单由销售助理负责）。

| 角色 | 能看到 | 能做 |
|---|---|---|
| 销售（sales） | 回访清单里**自己名下**的客户：节奏、上次购买、该客户近 12 月金额、详情面板（电话/区域/行业/最近跟进） | 记跟进、无需求、确认/改行业——仅限自己名下客户 |
| 销售助理/HR 等有「回访清单」的角色 | 同上（按自己名字匹配负责人） | 同上 |
| 老板/主管（admin，或勾了「全部客户分析」的角色；默认 manager / supervisor / ops_supervisor） | 全部销售的清单 + 客户分析页（活跃度、分布、地图、数据质量） | 对任何客户记跟进/无需求/改行业 |

实现要点：
- 新视图权限 `insights_all`（权限矩阵「全部客户分析」），`insights_sees_all()` = admin 或有该权限——**可在权限设置里调整，不再写死角色**。一次性迁移 `app_meta.perm_insights_v2`：给 sales 加 `insights`，给 manager/supervisor/ops_supervisor 加 `insights_all`；之后管理员的撤销不会被恢复。
- 清单页的所有写操作改走 `insights.followup / insights.no_need / insights.set_industry`（只需「回访清单」权限），**每次都在服务端用 `insights_can_touch()` 校验该客户的负责人是本人**（大小写/空格不敏感，空名字不匹配任何人）；改造过的请求（别人的客户 id、不存在的 id、`owner=` 参数、直接访问 `insights.admin`）都被拒绝，测试逐条覆盖。客户详情页的 `customers.followup` 仍保留给有客户管理权限的人，两条路径写同一张表（`Insights::record()`）。
- 行业确认抽成 `CustomerData::set_industry()`，客户详情页和清单详情面板共用，都写审计日志。
- 清单只读：除夜间计算 `Insights::compute()` 外，没有任何代码写 `customer_insights`（有测试扫描源码守着）。
- 销售看不到金额汇总：客户分析页（活跃度/分布/地图）只对 `insights_sees_all()` 开放；销售页只有自己单个客户的近 12 月金额（C 清单展示所需）。

**测试**：`tests/insights_perm_test.php`（权限矩阵规则、篡改请求、只读扫描、合法写入），迁移测试覆盖 v2 授权与撤销不被恢复。

## 6r. 客户分析 · 三、页面（2026-10-16）

新模块 `insights`（权限矩阵里叫「回访清单」）：`app/controllers/insights.php`，视图 `views/insights/`。一次性迁移（`app_meta.perm_insights`）把它授予**当时所有能看客户的角色**；之后管理员在权限设置里撤掉的不会被页面加载恢复。左侧导航「回访清单」。

**3.1 销售视角** `insights.index`（所有有该模块权限的人都看到**自己名下**的客户，按 `own_name()`）：三个清单是顶部三个大 tab（数字 + 名称），默认「该回访」；每行一个客户（名字点进客户详情的跟进卡片）：距今天数、通常间隔（只买过一次的标 * = 按同行业估算）、B 的超期天数（超两个间隔整行左侧红条）、C 的近 12 月金额与主要品类、上次购买（SKU 规格 × 数量）；按钮高 44px：打电话（tel:）、WhatsApp（wa.me，号码自动转 62 开头）、记跟进 / 无需求（点开就地填写，提交后回到当前 tab，该客户立即从清单消失）。

**3.2 老板/主管视角** `insights.admin`（`insights_sees_all()`，见 6s）：
1. 销售活跃度：名下客户数、30 天内有跟进的客户数与比例、本月清单处理率（本月出现在清单上的客户中，在**上榜当天或之后**记了跟进/无需求的比例；上榜前的跟进不算）。上榜记录来自新表 `insights_history`（夜间计算时写入当天名单）——**上线第一个月的处理率只从上线日起算**。
2. 成交客户分布：按行业、按区域的客户数与近 12 月金额（表格 + 横条）。
3. 送货点地图：**不用地图库**——服务端按 Web Mercator 算出覆盖 Jabodetabek 的 OpenStreetMap 瓦片（zoom 10，约 4×4 张）和每个点的像素位置，页面上是一个 SVG（瓦片 `<image>` + `<circle>`），随宽度缩放。圆面积 ∝ 订单金额，悬停显示客户/销售/金额/日期；雅加达周边以外的单数单独注明。**按销售着色的做法**：选一个销售 → 他的点橙色、其他人灰色；不选 → 全部蓝色。没有同时给十几个销售各配一种颜色，是因为散点上超过 3 种颜色就分不清（色弱更甚）。瓦片直接从 tile.openstreetmap.org 加载（页面注明 © OpenStreetMap contributors）。
4. 三个清单全量（带负责销售列），可按销售筛选（和地图共用一个下拉）。
5. 数据质量：按销售分列 行业待确认 / 区域未知 / 缺电话——**只统计有出货订单的客户**（6p 里说明了原因）。

**3.3 晨间 WhatsApp 提醒**：未做。任务书写的是"若已有消息渠道"——WhatsApp 目前仍是 `driver=log`、14 个账号里 0 个填了手机号。启用 WhatsApp 后可以用现成的通知队列（6i）加一个每天 8:00 的任务。

**测试**：`tests/insights_view_test.php`（销售只见自己、tab/按钮/回跳、主管与职员的访问控制、活跃度数字与库中一致、分布、数据质量、地图投影与筛选高亮）；迁移测试覆盖授权一次性与"撤销不被恢复"。

## 6q. 客户分析 · 二、三个清单（2026-10-16）

`app/Insights.php`；夜间 `tools/compute_insights.php`（由 `tools/nightly.php` 在区域/行业推断之后调用）整表重建 `customer_insights`（单事务，读者不会看到半张表）。

**口径**（任务书没说清、由我定的地方加 ★）：
- 购买 = 已出货订单（status=approved）；★购买日期取该订单**第一张送货单的提货日期**（实际出货日），没有送货单才用下单日期——线上两者平均差 1 天、最多 19 天。
- ★间隔按**不同的购买日**算：同一天下多单（线上 19 例）算一次购买，否则间隔会是 0。"≥2 笔"也按购买日计。
- 通常间隔：≥2 个购买日 = 相邻购买日间隔的平均值；只有 1 个 = 同行业客户间隔的中位数；同行业无数据或无行业 = 全体中位数。
- 近 12 个月：order_count、total_amount（含税，明细 + 运费）只算 365 天内；但更早的购买仍参与"间隔"计算。
- A 该回访：0.9×间隔 ≤ 距今天数 ≤ 1.5×间隔（含两端）。B 快流失：购买日 ≥2 且距今 > 1.5×间隔；超期 = 距今 − 间隔，超期 > 2×间隔标红。C 值得多跑：近 12 月金额在有消费客户的前 20%（取第 ⌈n×20%⌉ 名的金额为门槛）且距今 > 90 天。各清单按近 12 月金额降序。C 可与 A/B 重叠，A 与 B 互斥。
- 参数都在 `config.php` 的 `insights`（due_low/due_high/red_factor/top_share/idle_days/snooze_*）。

**跟进记录**（任务书说的"现有跟进接口"原本不存在，本次新建）：表 `customer_followups`（kind = followup / no_need）。客户详情页「跟进记录」卡片：显示购买节奏与所在清单、记跟进（必填内容）/ 无需求（必填原因）、历史。记跟进同时把 `customers.last_contact` 设为今天。**屏蔽是读取时实时计算的**（`Insights::snoozed()`）：记跟进后 7 天、无需求后 30 天不出现在清单（第 7 / 30 天重新出现），不用等夜间重算。权限走 `find_customer()`（销售只能给自己的客户记）。★跟进不写 audit_log：高频，且跟进表本身就记录了谁何时做了什么。`back=insights` 参数供第三部分清单页回跳。

**线上快照试算（2026-10-16）**：有出货订单的客户 160 个；A 19、B 26、C 0（C 门槛 = 近 12 月 ≥ Rp19.383.000）；间隔来自自身历史 74 个、行业中位数 86 个。**C 现在必然为 0**：订单历史从 8 月 4 日开始，还没有客户能"超过 90 天未买"；按 12 月 20 日试算会出现 6 个。历史短也让"通常间隔"偏短（很多 6–20 天），数据积累后会自然变长。

**测试**：`tests/insights_test.php`（任务书验收的 3 个客户、A 窗口边界、同日多单、提货日期、12 个月窗口、中位数兜底、7/30 天屏蔽、负责人过滤、控制器权限）。

## 6p. 客户分析 · 一、数据准备（2026-10-16）

任务书：`~/Desktop/印尼crm/印尼CRM任务-客户分析板块.md`（四部分：数据准备 / 三个清单 / 页面 / 权限，逐部分验收）。本节是第一部分。

**现状核对（与任务书的出入）**：订单已按 `customer_id` 关联客户（433/437，4 张无关联且库里没有同名客户）；**没有"跟进记录表"**（只有 `customers.last_contact` 一个字段，`tasks` 表从没关联过客户）——第二部分需要新建；**订单历史只从 2026-08-04 开始**（约 2.5 个月），"近 12 个月""90 天未买"等规则要过一阵才有意义；一半订单是 Self Pickup，送货地址常空或是快递名——所以区域推断按 送货地址 → 订单地址 → 客户地址 依次尝试，取第一个能识别出地点的。

**区域（region）**：`app/Enrich.php`。
- 地址文本规则（免费、离线）：显式「Kota/Kabupaten X」> 裸写的 kota 名（前两个词是 Jl/Jalan/Raya 时视为路名跳过，"Jl. Raya Bekasi" 在雅加达东）> 「Kec X」（雅加达 + 勿加泗/唐格朗/茂物德波的 kecamatan 表，只在 Kec 前缀后才认，避免与同名 kelurahan 混淆；跨两区同名的 Setu/Cipayung/Tamansari 不收）；同级多处提及取**最右边**（印尼地址由小到大）。`Gn`→Gunung、`Tg`→Tanjung。外地关键词（Surabaya、Tasikmalaya…）→ 其他。
- Google Geocoding（配 key 后）：给坐标（地图用）+ kota。**文本显式写了 kota 而 Google 定位到别处 → 以文本为准并丢弃坐标**（错点比没点更糟）。按规范化地址缓存在 `geocode_cache`（含"无结果"），每个地址只付费一次；key/网络错误不缓存，下次重试。
- kota → 区域映射表在 `config.php` 的 `insights.region_map`，外地关键词在 `insights.other_markers`。
- 客户区域 = 最近 3 张**已出货**（status=approved）订单中出现最多的已知区域，平局取更近的；`delivery_regions` 存全部出货订单的区域计数 JSON。无出货订单的客户 region 为 NULL（不算"未知"）。
- 线上实测：仅文本 **373/429 = 87%**；接入 Google 后（2026-10-16）**404/429 = 94%**，400 张有地图坐标，Google 缓存 228 个地址（11 个只到省级）。
- 送货栏里的快递/自提备注（"Expedisi"、"kirim ke expedisi sahabat"、"cust datang ke showroom"、"via …"）**不算地址**——Google 会把它们定位到同名小镇（实际出现过 5 单被定位到东爪哇/廖内），改为跳到订单地址。

**行业（industry）**：规则先行、模型兜底、人工最终。
- 规则（命中即定，source=rule）：公司名/客户名词首匹配 Konstruksi/Kontraktor/Bangun→承包商（"Pembangunan"不算）；Advertising/Reklame/Signage/Printing/Akrilik/Neon→广告招牌；Aluminium/Alumunium/Kaca/Glass→铝材店；Interior/Renovasi/Dekorasi→装修公司；Distributor/Supplier→经销商；**订单表单上客户类型多数为 Contractor/Distributor**→对应行业（"End User"是表单默认值，不作依据）；送货地址含 Proyek/Project/Site→承包商；个人名 + 无公司 + 累计 ≤20 张→终端业主。线上快照：规则直接判定 166/367。
- **线上首次运行（2026-10-16）**：规则 166 个；AI 201 个，耗时 112 秒，约 2.8 万输入 + 0.77 万输出 token（约 0.27 美元）。AI 结果中 **197 个低于 0.7 被标待确认**——不是故障：其中 155 个客户**没有任何订单**，只有一个人名（模型如实给 0.3–0.4，理由多为"个人名无数据"）；有订单的只有 46 个。真正影响清单的是有订单的那部分（约 40 个待确认），第三部分数据质量统计应只计有出货订单的客户。清洗已于同日 `--apply`（快照 `backups/pre_clean_20261016_194323.sqlite`）。
- 未命中的交给 Claude（`claude-opus-5-5`，effort low，结构化输出，每批 20 个客户，`fallbacks: "default"`），返回行业 + 置信度；**置信度 < 0.7 标「待确认」**。只回填问到的客户 id、只收枚举内的行业。
- 客户详情页「客户画像」卡片：显示行业、来源（规则/AI/人工）、置信度与理由、区域、送货分布；下拉一键确认/改选 → `industry_source=manual`，**夜间任务永不覆盖**（含 `--all`）。走 `find_customer()`，销售只能改自己的客户；写审计日志。

**运行**：`tools/enrich_customers.php`（增量：未处理的订单、配了 Google 后之前只靠文本的订单、行业为空的客户；规则每次对所有非人工客户重算）。`--all` 全量重跑、`--dry-run` 只报告不写库也不调付费 API。`tools/nightly.php` 是夜间总入口（第二部分会往里加清单计算）。

**AI 花费护栏（所有 AI 调用，`app/Claude.php`）**：单次输出 token 硬上限 `ai.max_tokens`（4096，调用方要更多也会被压下来）；请求体超过 `ai.max_input_chars` 不发送；只对网络错误和 408/409/429/5xx/529 重试，最多 `ai.retries`（2）次，退避 1s/2s，遵守 Retry-After（最长 30s）；400/401/403/404、拒答、被截断**不重试**；全公司每日 token 预算 `ai.daily_token_budget`（30 万，库存助手和行业判断共用，`ai_queries` 记账）；行业判断连续 3 批失败即停止本次运行。

**密钥**：`.env`（项目根目录，git 忽略，模板 `.env.example`）。`ANTHROPIC_API_KEY` 设了即启用行业 AI（库存助手另需 `ai.driver=claude`）；`GOOGLE_MAPS_API_KEY` 设了即启用地理编码。

**1.2 清洗**（`tools/clean_customer_data.php`，默认预演，`--apply` 先 `VACUUM INTO backups/pre_clean_*.sqlite`）：只做机械安全的事——销售名仅大小写/空格不同的统一为账号写法（线上：fera→Fera，18 客户 + 27 订单）；电话统一 08xx，"wechat"等非号码与多余号码移进备注（线上 33 条，6 条移入备注）；无 customer_id 的订单在恰好一个同名客户时补关联（线上 4 张都没有同名客户，未动）。**需要人判断的放在「客户管理 → 数据整理」（仅管理员）**：疑似同一人（编辑距离 1，7 位以上名字允许 2，去掉 Mr/Bapak 后相同；线上只有 erpandi ↔ erfandi）一键合并（客户负责人 + 订单销售名一起改）或标记"不是同一人"（记在 `app_meta.salesname_ignored`）；按负责人筛选客户（默认"无负责人"，线上 70 个），勾选后批量分配给某账号，或"按建议分配"（建议 = 该客户订单上出现最多的销售）。合并/分配均写审计日志（新动作 merge / assign / enrich / cleanup）。客户表原"城市"长地址保留不动，只作区域推断的最后兜底。

**测试**：`tests/enrich_test.php`、`tests/claude_test.php`、`tests/customer_data_test.php`、`tests/migration_test.php`（新增列的迁移）。

## 6o. 列表分页 + 搜索（2026-10-13）

**为什么**：订单每月约 170 张，订单/发票/送货单/客户都已 360–410 条，原先全量渲染（手机上尤其慢），一年后会到两千条以上。

**范围**：订单、财务（发票）、客户、送货单、行政审批 5 个列表，**每页 50 条**。审计日志原本就有分页，未改。
- 共用 `paginate($pdo, $countSql, $args)` + `pager($route, $filters, $pg)`（`helpers.php`）。`?page=` 超出范围自动夹到最后一页，非数字/负数回到第 1 页；LIMIT/OFFSET 是函数内算出的整数，不拼用户输入。
- 翻页链接**保留当前筛选和搜索词**；切换筛选标签则回到第 1 页。
- **新增搜索框**（分页后找老单据只能一页页翻，所以一起做）：订单搜 单号/客户/公司/销售/发票号；发票搜 发票号/客户/抬头名；送货单搜 送货单号/订单号/客户/公司。客户列表原本就有搜索。**销售的搜索仍只在自己的订单内**（`sees_only_own()` 条件照旧叠加）。
- 发票列表排序由 `invoice_date DESC` 改为 `invoice_date DESC, id DESC`：同一天的发票很多，不加 id 时分页边界不稳定，翻页可能重复或漏掉。
- 状态计数标签、财务三张汇总卡片仍按**全部数据**统计，不受分页影响。

**顺带**：财务列表每次打开都对**每张发票**跑一次 `refresh_invoice_status()`（399 次 SELECT + UPDATE）。改为 `refresh_invoice_statuses()` 一条 UPDATE，规则逐条相同，只写状态真的变了的行（通常 0 行）。用 600 张随机发票与旧函数逐行比对，并在线上快照上验证结果一致。

**测试**：`tests/pagination_test.php`；控制器渲染测试通过 `tests/support/run_controller.php` 子进程执行（控制器文件里声明了函数，同一进程不能 include 两次）。

## 6zf. 财务 AI · FA-TAX-01 销项 Faktur 导入表（2026-10-22）

按 docs/任务卡-财务AI.md v2 开发，纯规则，不用 AI。代码：`app/Faktur.php`、`app/controllers/tax.php`、`views/tax/`、`database/coretax/Faktur_PK_Template_v1.6.1.xlsx`（DJP 官方模板的空白副本）、`tools/import_customer_tax.php`；测试 `tests/faktur_test.php`。入口：财务管理页头「销项 Faktur」。权限：沿用 `finance` 模块。

**用户决定（2026-10-22）**
- **买方税务资料放在客户上，只由财务维护**。客户表新增 `tax_npwp / tax_nik / tax_name / tax_address / tax_nitku / tax_email`，在 Faktur 页「税务资料」里编辑；销售页面不显示、不要求填写。
- 已有资料用 `tools/import_customer_tax.php` 一次性导入。
- **TBC 只给财务看**，不给销售推任何提醒（章程第二原则）。
- **脱敏样本里有真实数据**：`samples_test/Coretax_Faktur_Template_…_SAMPLE.xlsx` 的 Faktur 表第 4–5 行是真实买方（姓名、NIK、住址），文件属性里还有两个人名，已在 `edea8d2` 清除，只留两行虚构数据。**旧提交 `84327a3` 里仍有原文件**，是否改写 git 历史待用户定。测试会检查样本里不再有这些内容。

**分类**（`Faktur::auto_class()`；财务可逐张发票改，改动存 `invoices.faktur_class` 并记审计）
- 客户有 NPWP 或 NIK → FAKTUR。
- 否则名称带法人形式（PT / CV / UD / FA / Koperasi / Yayasan / Tbk / PD / Perum / Persero），或下单客户类型是 Distributor / Contractor → TBC（这类不进导入表，列给财务去补）。
- 其余 → GUNGGUNG。

**生成导入表**（`Faktur::generate()`）
- 复制 DJP 模板，只改写 Faktur（第 4 行起）和 DetailFaktur（第 2 行起）的数据行；表头、列序、参考表、批注、校验规则都原样保留（测试逐表比对样本）。ID 一律写成文本，保留开头的 0。
- **Faktur 行**：
  - Baris 从 1 连号；Tanggal Faktur 写 DD/MM/YYYY；Normal；Kode Transaksi 04；Referensi = 发票号。
  - ID TKU 卖方 = `company_nitku`，留空时用 NPWP + 000000。
  - 买方 ID：NPWP 写 16 位（旧 15 位号自动补成 16 位）。只有 NIK 的个人，按财务现行做法把 NIK 当 TIN 填（与他们手填的模板一致）。
  - 买方 NITKU：留空时用 ID + 000000。
  - 名称：取 NPWP 登记名，其次是公司名 / 开票对象。地址：取 NPWP 登记地址，其次是发票地址。
- **DetailFaktur**：
  - 每个 SKU 一行，同一发票的行用同一个 Baris。
  - 固定值：A / 760600 / UM.0021 / 折扣 0 / 税率 12 / PPnBM 0。
  - 品名与印出来的发票相同：`(规格) SKU (颜色)`。
  - Harga Satuan = 发票上的税前单价。DPP、DPP Nilai Lain、PPN 按行用 `invoice_amounts_coretax()` 算（两位小数截断）。
  - **发票有运费时单独一行**：B / Ongkos Kirim / UM.0033，Kode Barang 留空。这个写法要找财务确认。
- **阻断项**（有一项就不能生成；财务修资料，或把发票改分类）：
  - 没配置本公司 NPWP；
  - FAKTUR 发票的 NPWP 位数不对、NIK 位数不对、缺号、地址为空、没有明细；
  - 各行 DPP / PPN 合计与发票的差额超过每行 Rp1（卡上的容差）。
- 文件存 `data/faktur/`（私有），走鉴权下载。同一期间再生成时，旧版标记为 superseded 但保留。

**Digunggung 汇总**：按期间列出 GUNGGUNG 发票（发票号、日期、买方、DPP、DPP Nilai Lain、PPN、总额），带合计行，同样保存。

**导入已有的买方资料**（一次性）

```bash
cd /www/wwwroot/www.alupanel.cc
/www/server/php/82/bin/php tools/import_customer_tax.php --file=/root/ACP_Faktur_PK_Template_v1.6.1.xlsx            # 预演（默认读 Faktur 表）
/www/server/php/82/bin/php tools/import_customer_tax.php --file=/root/ACP_Faktur_PK_Template_v1.6.1.xlsx --apply
/www/server/php/82/bin/php tools/import_customer_tax.php --file=/root/DETAIL_DATA_INVOICE.xlsx --sheet="表名"          # 其他表：按表头找「发票号」和「NPWP」列
```

- 按发票号找到平台发票，再经订单找到客户。`1301 - AMI - 10 - 26` 和 `1301 - AMI - INV - 10 - 26` 视为同一张。
- 只填资料还空着的客户，不覆盖财务后来的修改。
- 同一客户出现不同 NPWP 时报冲突，保留第一个。

**上线前要做**
- ~~在 `config.php` 填 `company_npwp`~~ —— **已填**（2026-10-22，`1000000000213431`，卖方 NITKU 默认 NPWP + 000000，与财务原先手填的模板一致）；分公司要用别的 NITKU 时再填 `company_nitku`。
- 和财务确认运费行的写法。
- **未做**：卡上第 4 步「Coretax 结果清单回写 Faktur 号」，要等财务提供一份导出样本（卡上「仍需财务提供」第 3 项）。在那之前，Faktur 号仍可在发票页手工补录。

## 6ze. 财务 AI · FA-AI-01 银行流水对账（回款匹配）（2026-10-22）

按 docs/任务卡-财务AI.md v2 开发。代码：`app/Bank.php`、`app/controllers/bank.php`、`views/bank/`、`prompts/bank_match_v1.txt`、`tools/import_bank_book.php`；测试 `tests/bank_test.php`（只用 samples_test/，发票为虚构）。入口：财务管理页头「银行流水」「回款记录」。权限：沿用 `finance` 模块。

**用户决定（2026-10-22）**
- **备注号码怎么对应发票**：两种都试，唯一才算。完整 CO 格式（`1306/AMI-CO/10/26`）按订单号找发票；只有序号的（`1302,1303-AMI`、`CO 1222`、`Inv 1309`）同时按发票序号和订单序号找。只有恰好一张未结发票、且金额不超过余额才算规则匹配，否则交给 AI 或人。
- **少付结案**：记一条非现金结案行（`payments.noncash = 1`，方式注明 PPh (Bukti Potong) / Biaya bank / Pembulatan / Selisih），应收自动归零；看板「回款」只算现金（`Dashboard` 两处查询加了 `noncash = 0`）；冲销时会把 noncash 一并复制。
- **AI 匹配的时机**：财务点按钮才跑（列表页「AI 匹配未配上的（剩 n 笔）」，每次最多 `bank.ai_batch` = 15 笔；单笔页也可点「让 AI 匹配这一笔」）。规则匹配在导入时自动跑，不花钱。

**导入**（`bank.import`）
- 按文件第一行自动识别银行：BCA 的 `Informasi Rekening`、ICBC 的 `Branch Name:`。解析规格见任务卡 0.2 / 0.3。
- 每笔流水按哈希入库，重复导入自动跳过：BCA = 日期 + Keterangan + Jumlah + Saldo；ICBC = 时间 + Summary + 进 / 出 + Balance。PEND 行跳过。
- 导入记录（`bank_imports`）显示：新增 / 跳过 / PEND 笔数、进账笔数与合计、「与文件汇总」（BCA 的 Mutasi Kredit / Debet，ICBC 的 Total 行）、「余额连续」（逐行核对 期初 + 进 − 出 = 余额）。
- **脱敏样本的文件汇总是 0**（BCA 的 Mutasi Kredit 0.00 / 0 笔，ICBC 的 Total 借方 0,00），所以样本显示「不一致」；余额链校验通过。真实文件应显示「一致」。
- 账户只存后四位，第一次导入时自动建档（`bank_accounts`）。ICBC 的对方账号只用来判断内部转账（后四位 = 自己的 BCA 账户），不入库。
- **流水号在导入时就生成**（卡上写「确认后」）：`BCA-IN-YYMM-NNNN` / `BCA-OUT-…` / `ICBC-IN-…`，按银行、方向、交易月份分别连号，接着库里已有的最大号往下排。这样出账、非货款也有号，而且编号按日期顺序，不按确认的先后。

**打标**（规则，不调 AI）
- ICBC：Salary → 工资；收款人含 PENERIMAAN NEGARA / PAJAK，或摘要以 Pajak 开头 → 税款；Pindah Dana 或对方是自己的账户 → 内部转账；Bunga → 利息；BPJS → 社保；其余 → 待分类。
- BCA 出账：BIAYA ADM / BA JASA / BIAYA TRANSFER / Deliv Fee → 费用。
- BCA 进账：GoPay（DOMPET ANAK BANGSA）/ ESPAY / SHOPEE → 平台代收；Bunga → 利息（直接记为非货款）；其余 → 货款。
- ICBC 全部和 BCA 出账的 match_status = `na`，不参与匹配。

**付款人与备注**（`Bank::bca_payer()` / `refs()`）
- 付款人取 Keterangan 末尾：双空格分段时取最后一段；否则从末尾往前取连续的大写词，遇到备注词（DP / LUNAS / PAYMENT / INV / SISA / CO …）停下。
- BI-FAST、SWITCHING、SETORAN CDM 各有固定格式。

**规则匹配**（`rule_proposal()`，容差 `bank.tolerance` = Rp1,000）
1. 备注号码各自指向唯一一张未结发票，且金额 ≤ 余额合计 + 容差；
2. 付款人命中已确认的别名，且该客户恰有一张未结发票的余额 = 金额（± 容差）；
3. 全系统只有一张未结发票的余额 = 金额，且付款人与客户名相似度 ≥ 0.8（去掉 PT / CV / TOKO 后比较）。

平台代收不走 2、3。确认一笔后，同一付款人还在等待的其他行会立刻重跑规则，所以同一文件里第二次出现的付款人也走规则，不调 AI。

**AI 匹配**（task `bank_match`，`prompts/bank_match_v1.txt`，标准档 Opus 5.5，effort medium）
- 发给模型的内容：
  - 日期、金额、去掉银行内部码（`0110/FTSCY/WS95051`、`WSID:`、10 位以上的长号码）后的 Keterangan、付款人；
  - 候选发票最多 30 张：近 120 天开票的未结发票，按金额接近程度、名称相似度、别名、备注号码打分预筛，每张带编号、CO 号、客户、已知付款人名、PIC、开票日、余额。
- 平台代收时告诉模型「付款人不可用，按金额匹配」。
- 模型只返回发票 id 列表、置信度（高 / 中 / 低）、差额类型、印尼语 + 中文原因各一句；**金额由 `allocate()` 按规则分**：按顺序先填满各张余额，多出的放在最后一张（多付），不够时按差额类型处理（部分付 / 手续费 / 代扣税 / 尾差）。
- 返回了候选以外的 id 会校验失败，重问 1 次后记「AI 未处理」。
- 建议写入 `ai_suggestions`，确认时比对，算进「AI 使用情况」的采纳率。

**AI 重试**：「AI 匹配未配上的」除了从没问过的，也会重跑上次失败（AI 未处理）或被预算拦下的；先跑没问过的。不会无限重试：同一笔 24 小时内最多实际调用 2 次（接口直接拒收、没用 token 的不算）。

**确认**（单笔一键「确认入账」，规则匹配可「全部确认」；可改发票和金额，也可手选发票）
- 每张发票写一条现金收款到原有的 `payments` 台账：日期 = 流水日期，收据号 = 流水号，方式 = BCA（平台代收为 `BCA · SHOPEE` 等）。然后 `recompute_invoice_paid()`，所以发票页、应收、看板账龄不用改就能读到新余额。
- 少付且选了结案原因（手续费 / 代扣税 / 尾差 / 其他）的，再记一条非现金结案行；选「部分付款」的不结案。
- 写入 `payment_allocations`（金额、差额、原因、对应的 payment / 结案 payment），付款人名写进 `customer_payer_aliases`（来源 confirmed，平台不记）。
- 「全部确认」逐笔按当前余额重算规则，与原建议不一致的跳过（避免同一张发票被两笔流水重复入账成「多付」）。
- 多付的可以关联退款的出账流水号；代扣税的进「待收 Bukti Potong」页，收到后点「已收到」。
- 非货款进账可标为 股东注资 / 退款 / 利息 / 内部转账 / 其他，也可改回未匹配（只改状态，没有入账）。
- 审计：模块 `bank`，动作 import / confirm / classify / link / handle / export。

**回款记录**（`bank.receipts`，替代工作簿「5. Catatan Penerimaan」）
- 按开票月份每张发票一行：总额、已收现金、结案差额、Balanced、状态、最后付款日、退款流水、付款明细；可导出同列名的 Excel（最多 6 笔付款）。
- 状态沿用财务现用名称（`Bank::collection_status()`）：PAID - Balance / Lebih Bayar / Kurang Bayar（少付已结案）/ Shoppe / Gudang / Cash，Sebagian - Kurang Bayar，UNPAID - Kurang Bayar。**LOST（坏账）平台里还没有来源，不会出现。**

**历史导入与盲测**（`tools/import_bank_book.php`，真实工作簿只在服务器上跑）

```bash
cd /www/wwwroot/www.alupanel.cc
/www/server/php/82/bin/php tools/import_bank_book.php --file=/root/Point_2026.xlsx            # 预演：能找到发票的笔数、要学的付款人、冲突、找不到的发票号
/www/server/php/82/bin/php tools/import_bank_book.php --file=/root/Point_2026.xlsx --apply    # 写入 bank_book + 别名（source history）
/www/server/php/82/bin/php tools/import_bank_book.php --benchmark=2026-09                     # 9 月盲测，只跑规则（免费）
/www/server/php/82/bin/php tools/import_bank_book.php --benchmark=2026-09 --ai --verbose      # 规则 + AI（花钱，计入美元上限）
chown -R www:www data
```

- `--apply` 写完别名后会对平台里**所有「未匹配」的进账流水重跑一遍规则**（`Bank::rematch()`，不调 AI、不花钱），新认识的付款人马上变成「规则匹配」等财务确认；输出最后一行是命中笔数。否则别名只对以后导入的流水生效（同一 CSV 重传会被去重跳过）。
- 读「1. Mutasi Bank BCA」表（没有就找「BCA STATEMENT」，财务导出的流水工作簿用这个名字；也可 `--sheet=` 指定）的 BCA-IN 行，列按表头名找。
- 工作簿的备注是从 PDF 对账单复制的，格式和网银 CSV 不同（没有 CR、单空格、金额无分隔符），`bca_payer()` 两种都认；日期列混着三种写法（Excel 日期、dd/mm/yyyy、dd/mm/yy），其中 Excel 把 day ≤ 12 的手打日期读成了 月/日 颠倒——**以流水号里的年月（BCA-IN-2602-…）为准把日月换回来**。2026-10-22 用真实工作簿核对：1490 行全部读出、日期全部落在流水号月份内，96% 取到付款人（383 个不同付款人）。
- 只有平台里有的发票（2026-08-04 起）能对上客户；1–7 月的旧发票号在历史销售（`sales_history`，单号是旧软件的 XSD…）里没有，这些行学不到付款人。Nomor Invoice `1301 - AMI - 10 - 26` 对应平台的 `1301 - AMI - INV - 10 - 26`。
- 盲测隐藏工作簿的发票号；用当天的未结余额（只算该日之前的收款）；**只用其他月份学到的付款人**。输出规则 / AI 各自的命中数和正确数、AI 高置信错误率、总准确率（目标 ≥ 85%，高置信错误 ≤ 5%）。

## 6zd. 财务 AI · FA-AI-00 AI 调用层（2026-10-22）

按 docs/任务卡-财务AI.md v2 开发（只做了 00，01 起待用户看过再继续）。代码：`app/AiTask.php`、`app/controllers/ai.php`、`views/ai/usage.php`、`prompts/`，测试 `tests/ai_task_test.php`（含迁移测试和真实控制器渲染）。

- **分层**：`Claude::send()` 仍是唯一 HTTP 出口（token 上限、瞬时错误重试）；其上新增任务层 `AiTask::run($pdo, $config, $task, ['content', 'schema', 'ref_table', 'ref_id', 'check'])`。卡上建议的文件名 `app/Ai.php` 已被库存助手占用，所以叫 `AiTask`。**新 AI 功能一律走 `AiTask::run()`**，不要直接调 `Claude::send()`。
- **配置**（`config.php` 的 `ai`）：
  - `tasks[任务] = tier 或 model, effort, max_tokens, prompt_version?, max_input_chars?`；
  - `tiers`：cheap = claude-haiku-5-5，standard = claude-opus-5-5；
  - `prices`：美元 / 百万 token，按 2026-10 的官方价（Opus 5.5 $4 / $20，缓存读 $0.20；Sonnet 5.5 $2 / $10；Haiku 5.5 $0.10 / $0.50）。没列出的模型按 `default`（最贵档）计，宁可多算；
  - `daily_usd_budget = 5`、`monthly_usd_budget = 50`：用户 2026-10-22 定，月上限与 Console workspace 一致；
  - `per_record_daily_calls = 2`。
- **提示词**：放在 `prompts/{task}_v{n}.txt`，默认用最大版本号；`prompt_version` 可钉住旧版，用于回退。改提示词就新建 v(n+1)，旧版保留。`ai_calls.prompt_version` 记录每次用的哪一版。
- **校验与重试**：要求结构化 JSON 输出（`output_config.format`），返回后再做两层检查：一是按 schema 校验（`AiTask::validate()`，零依赖，覆盖我们用到的 JSON Schema 子集）；二是任务自定义规则 `check`。
  - 不合格就重问 1 次，仍不合格记 `invalid`（即"AI 未处理"），由调用方转人工。不会循环。
  - **结构化输出不接受数值 / 长度 / 数组大小约束**（minimum、maximum、multipleOf、minLength、maxLength、minItems、maxItems 等）：带上就是 HTTP 400，模型根本没跑。所以 `AiTask::api_schema()` 发送前把这些键剥掉，`validate()` 仍按完整 schema 校验答案（与官方 SDK 的做法一致）。2026-10-22 线上踩过：银行匹配的 schema 里有 `maxItems: 6`，每一笔 AI 匹配都 400，界面全是「AI 未处理」。
  - 接口错误（HTTP 错误 / 拒答 / 被截断）不重问，因为 `Claude::send()` 已对瞬时错误做过有限重试。
- **预算**：调用前先问 `Claude::budget_left()`。它现在同时看两样，任一用完就返回 0：
  1. 每日 token：ai_queries + ai_calls 两张表合计；
  2. 美元日 / 月上限（`AiTask::budget_guard()`）：ai_calls.cost_usd，加上库存助手 ai_queries 的 token 按 `ai.model` 单价折算。

  超了之后，所有 AI 功能一起停（库存助手、行业推断，以及以后的对账 / 报销 / 进项），记 `over_budget`，并给老板一条待办。过了当天 / 当月自动恢复，旧待办由系统关闭。
- **防重复**：同一任务、同一记录（ref_table + ref_id）24 小时内最多实际调用 2 次，第 3 次记 `repeat_skipped`，不发送。只算模型真正作答过的调用（input + output tokens > 0）：接口直接拒收的请求（如 schema 400）不花钱，不占次数（2026-10-22 用户要求解除，否则修好 400 后当天无法重跑）。批量任务没有 ref_id，不受此限（例如行业推断一次 20 个客户）。
- **台账 `ai_calls`**：
  - 一行 = 一次任务调用。校验失败重问的那次也算在同一行里：tokens 和费用合计，attempts = 2；
  - 状态：ok / invalid / api_error / over_budget / repeat_skipped；
  - `model` 记实际作答的模型（发生 server-side fallback 时可能不同），费用按它的单价算；
  - **新建表，而不是扩展 ai_queries**（卡上二选一）：ai_queries 的 question / matched 是库存助手专用（question 不可为空），而且它兼做库存助手每人每日的限流。库存助手保持原样写 ai_queries，两张表都计入预算。
- **建议与采纳 `ai_suggestions`**：
  - `AiTask::suggest()` 存 AI 建议（JSON + 置信度 + ai_call_id）；
  - `AiTask::confirm()` 存人确认的最终值，按顶层字段逐个比较（数字容差 0.005）。全部一致记 ai_accepted = 1，否则记下 changed_fields。每条建议只能确认一次；
  - 卡上写 01 / 02 / 03 的业务表各带 `ai_*` 字段。打算改为各表存一个 `ai_suggestion_id` 指向这里，到 FA-AI-01 时再确认。
- **图片**：`AiTask::image_block()` 把长边压到 ≤ 1568px（GD 缩放，JPEG 质量 85，按 EXIF 转正，透明底补白），小图原样发送。图片的 base64 远大于默认的 60000 字符请求上限，所以单个任务可以用 `max_input_chars` 放宽。
  - **PDF「只发需要的页」未做**：零依赖下没法可靠拆 PDF 页。留到 FA-AI-03 拿到真实 Faktur 样本后再定：整份发 / 服务器装 qpdf / 先转成图片。
- **行业推断迁移**：`Enrich::ai_classify($pdo, …)` 改走 `AiTask::run('industry')`。
  - 提示词原文搬到 `prompts/industry_v1.txt`，逐字节相同（测试锁了 md5）；请求体与迁移前完全一致（已 diff 过）；
  - 模型改由 `ai.tasks.industry`（standard 档）给出；旧的 `insights.industry_model` 如果在 config.local.php 里设了，仍然优先；
  - 不再往 ai_queries 写 'enrich' 行；
  - 行为上只有一处不同：返回不合 schema 时现在会重问一次，以前是整批直接算失败。
- **超预算待办**（用户 2026-10-22 定：放人力待办中心）：
  - `hr_todos.type = 'ai_budget'`，employee_id 为空；ref 为 `day:YYYY-MM-DD` 或 `month:YYYY-MM`，每天 / 每月最多一条。employee_id 为 NULL 时 UNIQUE 不生效，所以先查重再插入；
  - `Hr::BOSS_TODO_TYPES`：只有老板（admin）在人力首页看得到、能点「已处理」（审计 hr / handle）；HR 看不到，不违反收尾卡「HR 待办只四类」。
- **「AI 使用情况」页** `ai.usage`，只读：
  - 入口：财务管理页头按钮，老板的超预算待办里也有链接；
  - 权限：新视图权限 `ai_usage`（权限矩阵新列），默认只给 finance_manager，老板始终可见（用户 2026-10-22 定）。一次性迁移 `app_meta.perm_ai_usage_v1`，之后管理员的调整不会被覆盖；
  - 内容：今日 / 本月费用与上限；本月按任务列出调用次数（按状态拆开）、tokens、费用、平均耗时、采纳率、改动最多的字段；库存助手单独一栏（数据来自 ai_queries）；月份可切换。
- **上线**：`git pull`，再打开一次网站，`ensureSchema()` 会建好两张表并授权，不需要改 `.env`。服务器 `config.local.php` 里的 `'ai' => ['driver' => 'claude']` 与新配置用 `array_replace_recursive` 合并，互不影响。
## 6zg. CO 号统一：Google 表单发号登记 + 一条流水号（2026-10-22）

**背景**：平台上线前销售用 Google 表单「Request CO Number」领号（后面的 Sheet 是登记本，格式 `NNNN/AMI-CO/MM/YY`，2026-02-09 起从 0001 发到 10/22 的 1378）。平台 8 月上线时自己从 0001 重新起号（取库里最大号 +1），到 10/22 发到 0487，而销售**两边都在用**：表单领的号做 CO 给客户，系统另发一个号跟着 DO 和发票。结果：同一个号两边是不同客户，客户手里的 CO 号在系统里搜不到。

**决定（用户 2026-10-22）**：系统成为唯一发号处，表单停用；流水号不归零、只换年份尾号。

**做法**：
1. **登记导入**：`tools/import_co_requests.php --file=<Responses.xlsx> --apply` 把表单登记（号、领号时间、销售、客户公司/联系人/电话）导进 `co_requests`，按 seq 幂等；表单停用前可反复导最新导出，只加新号。
2. **一条流水号**：`next_order_no()` 改为 `CoNumbers::max_seq()` = max(orders 最大号, co_requests 最大号) + 1。导入后下一个号即 1379（或登记里更大的号 +1）。新建订单同时写 `orders.co_no = order_no`——从此订单号就是客户看到的 CO 号。
3. **已领号的单子**：新建订单页顶部「CO 号」下拉列出最近 60 天**未挂订单**的登记号（号 · 销售 · 客户 · 日期）；选了就以该号作订单号（`CoNumbers::link(... rename=true)`），不另发；不选则自动发号。草稿编辑页同样可选；已有 co_no 的锁定显示。
4. **老订单挂号**：8/4 以来已录系统的订单（0001–0487）不改订单号（DO/发票已用），只填 `orders.co_no`。自动匹配 `tools/import_co_requests.php --link [--alias=erfandi=erpandi,...] [--apply]`：按电话（后 9 位）/ 公司或联系人名（归一化、容错 2 字）在订单日期前 10 天～后 3 天找登记，销售名只用于加分；候选唯一且分差够大才算「确定」（同一客户在窗口内领了多个号的，按日期就近配对、不算存疑——线上首轮 471 单：确定 340 / 存疑 113 / 无候选 18，存疑几乎全是常客多次领号，故加此规则），其余列出来由人工在订单详情页「挂接 CO 号」下拉处理（admin / 看全部订单的角色 / 财务；销售只能在自己的草稿上操作，草稿挂接会改订单号）。删除草稿会把号放回池子。
5. **查询**：订单列表搜索含 co_no；订单详情显示「CO 号」（与订单号不同则注明"表单领的号"）；订单页头新增「CO 号登记」页（`orders.co_index`：统计卡 登记总数/未挂/最后发号/系统下一个号，搜索，未挂/已挂筛选，挂了的链接到订单）。

**CO 打印件**（2026-10-22 晚）：`orders.print_co`（订单详情页「打印 · CO」），`views/print/co.php`，按销售现用的 Confirmation Order 版式（样本：argani 1377）：抬头/公司/地址、No（co_no 优先）+ Jakarta 日期（印尼语月份大写）、客户信息、Type of Client、取货方式/送货地址、英文函、明细表（Material/Type/Code/Color/Size/Volume/Unit/Price/Total，首行 ALUSIGNPANEL PE-LDPE Core）、SUB TOTAL / ONGKIR / GRAND TOTAL；含税报价注明"Harga sudah termasuk PPN"，不含税报价列 DPP + PPN 12%（沿用 `invoice_amounts_coretax()`）；Notes 含付款条件（高亮）、30 天有效、H+2、BCA 账号（取 config banks 里名字含 BCA 的）；三栏签字（销售 = 订单 submitter 对应用户的姓名/职务、客户、config signer）。零中文（no_cjk）。不写审计（打印是读操作）。未做：.docx 导出（需要再做时照 Docx.php 的发票做法）。表单销售名（Mr. Fang / Anton / Tania / Calvin / Deny）与系统账号的对应待用户确认，影响 --link 的 --alias。

## 6zc. 历史销售导入 + 年度销售统计（2026-10-22）

**背景**：平台的真实订单从 2026-08-04 开始；之前（2026-01-02 起，公司 2025 年尚未运营）的销售记在一个简单记账软件里（「销售单」导出 Excel：1,183 单 / 2,208 行，1–8 月共 Rp 69.7 亿）。用户要年度销售额能看整年，所以把旧数据作为**历史销售**导入，**只读、只用于统计**。

**口径（用户 2026-10-22 决定）**：
- 历史销售一律视为已收款（旧软件只记现金结清，没有赊销），**不进应收、不进库存、不进审批、不生成 DO/发票**。
- 8 月两边重叠（旧软件记到 8/31，平台 8/4 起）——**8 月以平台为准**：导入只取 `doc_date ≤ 2026-08-03`（`--until`，默认值 `SalesHistory::DEFAULT_UNTIL`），后面 157 单跳过。实际导入 1,026 单 / 1,919 行 / Rp 6,322,337,921。
- 年度统计 = 历史销售（按单据日期）+ 平台开票（按发票日期、作废除外，与看板「销售额」同口径）；张数 = 历史明细数量 + 出货口径张数。两边按月相加，永不重复。

**数据**：`sales_history`（单据：原单号 doc_no 唯一、日期、原客户名 + 匹配到的 customer_id、原业务员名 + 匹配到的 submitter、折后金额/运费/应收/实收、批次）+ `sales_history_items`（行：sku/颜色/规格 + 匹配到的 product_id、数量、单价、金额、原品名、备注如 样品/理赔）。匹配规则在 `app/SalesHistory.php`：客户按名称/公司归一化（去 PT/CV/Toko、标点、大小写、多余空格，"A / B" 两半各试）；产品按 `sku + spec`，退一步按 `sku + 基础规格`（去掉 抗刮木纹/拉丝 等后缀）；品名 "825高光白…" 丢了 SJ 前缀自动补成 SJ8825；业务员大小写不敏感匹配 `users.name`，`--alias="Erpandi=erfandi"` 可手工映射；匹配不上的照原样保留（customer_id / product_id / submitter 为空），不自动建客户。

**导入工具** `tools/import_sales_history.php`（CLI，默认预演）：
```bash
/www/server/php/82/bin/php tools/import_sales_history.php --file=/path/销售单.xlsx                  # 预演：看匹配报告
/www/server/php/82/bin/php tools/import_sales_history.php --file=/path/销售单.xlsx --alias="Erpandi=erfandi" --apply
/www/server/php/82/bin/php tools/import_sales_history.php --purge-batch=20261022-120000           # 撤销一次导入
```
.xlsx 由自带的零依赖读取器解析（ZipArchive + SimpleXML）。幂等：同 doc_no 已存在则跳过，可反复跑。导入后 `chown -R www:www data`。

**页面**（财务模块权限 `finance`）：`finance.yearly` 年度统计（年份切换；四个数字卡；月表带历史/平台双色条；按业务员——需 `performance`，否则只看自己；按客户前 50，已关联的链接到客户页；导出 Excel 三段表）；`finance.history` 历史单据列表（年份 + 单号/客户/业务员/色号搜索，分页，明细行内展开）。入口：财务管理页头「年度统计」按钮、看板期间栏「年度统计」。

**客户列表更新（2026-10-22 晚，用户要求）**：`tools/sync_history_customers.php`（默认预演，`--apply` 写入并重算回访清单）——① 历史单里系统没有的客户名（去 PT/CV/大小写/空格归一后一名一档，拼法以出现最多的为准、同数时取带 PT/CV 的）建档：tag 成交、note「来自历史销售导入」、owner 只在该销售有平台账号时填（否则留空进"无负责人"待分配）、历史单回填 customer_id；一条汇总审计；② 所有有历史购买的客户：tag 潜在→成交、owner 为空则按历史销售填（同样要求是平台账号）、last_contact 不早于最后一次历史购买；③ `Insights::compute()` 把 `sales_history` 当购买（id 取负数区分；最近购买的明细从 sales_history_items 取；top_specs 仍只看平台订单），夜间任务自动生效。客户详情页新增「历史销售单据」卡（最多 30 条，多的链到历史单据页）。幂等。

**原未做 / 后续**：未匹配客户不自动建档（已由上面的工具解决）（线上要跑一次预演看报告，必要时先在客户管理里补建/改名再导入）；历史单无送货地址，客户区域地图不受益；历史购买暂未接入回访清单的"最近购买"（可作为后续：Insights 读 sales_history 作兜底）。

## 6zb. 人力一期收尾（2026-10-21，章程 v2.2「不给员工加负担」）

- 列表页不做任何颜色提示：去掉完整度 < 80% 的黄标、员工列表 / 合同列表剩余天数不着色；新增/编辑表单不再显示完整度进度条（章程：不做进度条）。完整度数字只在详情页，纯文字。
- 新增员工必填：姓名、NIK、入职日期；部门/职位/工作地/出生日期可后补（填了仍校验）。
- 待办只生成四类：`Hr::TODO_TYPES` = contract_expiring / contract_expired / bpjs_overdue / bpjs_stop。`Hr::add_todo()` 对其他类型直接忽略，首页也只显示这四类。离职只生成「BPJS 停保」；试用期结束日只在合同详情上显示。
- 离职时自动终止生效中的合同（用户 2026-10-22，章程 §七「一键切换」）：`HrContract::on_resign()`，终止日期/原因 = 离职日期/原因，审计 terminate；撤销离职不恢复合同。
- Excel 批量导入（员工 / 合同 / BPJS）：等 HR 提供现用 Excel 后按它定模板。已定规则（用户 2026-10-22）：导入的合同直接按日期算生效 / 已到期，扫描件后补（手工新建的仍需先传扫描件）；员工先按 NIK、再按姓名（忽略大小写和空格）匹配已有档案，都对不上才新建，写入前先出预览由 HR 确认。

## 6za. 人力 / SDM：HR-03 BPJS（2026-10-21）

代码：`app/HrBpjs.php`、`app/controllers/hr.php`（bpjs_* 动作）、`views/hr/bpjs*.php` 与员工详情 BPJS 页签，测试 `tests/hr_bpjs_test.php`。表 `bpjs_kesehatan`、`bpjs_kesehatan_dependents`、`bpjs_ketenagakerjaan`、`bpjs_rates`、`bpjs_monthly`。

- **用户决定（2026-10-21）**：费率**只在公司级** `bpjs_rates`，每行一个生效月份，月度清单取当月有效的那行并记 `rates_id`（员工记录不存费率，只存 JP 是否参保、Kesehatan 等级）；**基数上限两个**：Kesehatan 和 JP，都由 HR 填（不内置数字）；**家属超额自动算**：覆盖本人 + 1 配偶 + 3 子女，超出每人员工多缴基数 1%；**总经理只看参保状态**，基数和金额只有 HR、老板、员工本人可见，月度清单和费率页只有 HR（改）和老板（看）。部门主管看不到别人的 BPJS（HR-00 矩阵）。
- **任务卡默认值更正**：卡上写 JHT 员工 1%，法定是 2%（PP 46/2015：3.7% + 2%）；配置页预填 2%，由 HR 首次填写时核对。
- **记录**：`HrBpjs::scan()`（每次打开人力页面运行）给每个未离职员工补齐两条「待登记」记录；入职满 30 天仍待登记 → 待办「BPJS 逾期未登记」（每套一条，登记后自动关闭）。填了号码和登记日期自动「已参保」。基数默认取员工基本工资，可手工覆盖（必填原因，加密，审计只记"已修改"）。
- **离职**：`Hr::resign()` 调 `HrBpjs::on_resign()` → 两套「待停保」；离职的「BPJS 停保」待办在两套都填了停保日期（→ 已停保）后自动关闭。撤销离职：待停保恢复为已参保（有登记日期）或待登记。
- **月度清单**：当月参保 = 登记日期 ≤ 月底且（无停保日期或停保日期 ≥ 月初，停保当月仍计）。每项四舍五入到整数卢比。生成即锁定；重新计算生成新的一次（run），旧的保留。基数、金额、明细全部加密。没有基数的人不计入并在生成时列出。导出 .xlsx（`send_spreadsheet`），导出记审计（action export）。
- **费率**：同一生效月份可修改，但已被清单用过的不能改（需新建下个月起生效的）；改动审计。JKK 按风险等级五档（0.24/0.54/0.89/1.27/1.74%）。

## 6z. 人力 / SDM：HR-02 合同管理（2026-10-21）

代码：`app/HrContract.php`、`app/controllers/hr.php`（contract_* 动作）、`views/hr/contract*.php`，测试 `tests/hr_contract_test.php`。表 `employment_contracts`。

- **用户决定（2026-10-21）**：合同号 `KK/AMI/{MM}/{YYYY}/{NNN}` 取**起始日**的月/年，流水号**每年**从 001 重排（草稿改起始月份会重新编号，生效后固定）；合同生效时**工资/职位/工作地自动同步**到员工档案并按合同起始日记变更历史（备注 = 合同号；未来起始的合同到起始日才同步）；**允许录历史合同**（到期日已过的生效后直接记「已到期」，不同步档案）；**不加服务器定时任务**——`HrContract::scan()` 在每次打开「人力」任何页面时运行（幂等），代替 07:00 cron。
- **状态**：草稿 → 生效（必须先上传扫描件）→ 已到期（到期日次日自动）/ 已终止 / 已转 PKWTT。草稿可「作废」（记已终止，不影响员工）。生效中的合同只能更正到期日和签署日。
- **同一时间一份生效合同** = 生效合同的期间不能重叠（续签合同可提前生效，因为从旧合同到期次日开始）。
- **到期处理页**（PKWT 生效/已到期且未处理）：续签（新 PKWT 草稿，起始 = 旧到期 +1，`previous_contract_id` 链接，`renewal_count` +1）/ 转 PKWTT（新 PKWTT 草稿，旧合同立即「已转 PKWTT」）/ 终止。已续签 2 次或链上 PKWT 累计将超 5 年 → 红字提示，不阻止。
- **终止**：合同「已终止」+ 联动 HR-01 离职（`Hr::resign()`，状态、三条待办、CRM 停用），其中「合同终止」待办直接标已处理；员工已先离职的只关闭合同和该待办。
- **待办**（ref = `contract:{id}`）：PKWT 到期前 30 天「合同即将到期」；到期日当天起未处理「合同已到期未处理」（列表标红）；试用期结束前 7 天「试用期到期」。做出续签/转/终止决定后自动关闭该合同的提醒。已离职员工不再提醒；有更晚的生效/草稿合同的历史合同不提醒。
- **谁看什么**：同 HR-01（部门主管看本部门、员工看自己、总经理看全部不含工资、HR 增改）。合同工资加密，规则同基本工资。
- 重构：离职逻辑移到 `Hr::resign()`，上传校验 `Hr::accept_upload()`，私有文件下载 `Hr::send_file()`（员工附件和合同扫描件共用）。

## 6y. 人力 / SDM：HR-00 基础约定 + HR-01 员工档案（2026-10-21）

按 docs/任务卡-人力一期.md 开发，规则见 CLAUDE.md §1.1。代码：`app/Hr.php`（权限/加密/校验/迁移）、`app/controllers/hr.php`、`views/hr/`，测试 `tests/hr_test.php`。

- **菜单**「人力 / SDM」（`can_access('hr')`），首页 = 待办列表（按到期日，逾期标红）；子页 待办 / 员工，合同、BPJS 灰显待 HR-02/03。
- **表**：`employees`（工号 AMI-0001 起）、`employee_attachments`、`employee_changes`、`hr_todos`（UNIQUE(type, employee_id, ref)）；`users` 加 `employee_id`、`active`。
- **谁看什么**（后端过滤，`Hr::viewer()`）：hr 角色增改全部、看薪资；admin（老板）只读全部含薪资；有 `hr_all` 权限（默认只给 manager = 总经理 tania）只读全部**不含薪资**；勾了「部门主管」的员工只读本部门不含薪资（目前无人勾选）；其他人只看自己（含自己的薪资）；没员工档案的账号进不了。用户确认：平级管理、总经理管全部（1B）。
- **薪资加密**：AES-256-GCM，存 `v1:base64(iv|tag|ct)`；密钥 `.env` 的 `HR_SALARY_KEY`（未配置时不能录薪资，不会明文落库）。变更历史里的薪资新旧值也加密；**audit_log 永不记金额**（用户 2A），只记"已修改"。**密钥丢失 = 薪资无法读出，必须另外备份。**
- **审计**：字段级，一个字段一行 `table.field: 旧 → 新`，module = hr；新动作 resign / upload / handle。
- **附件**：`data/hr/{employee_id}/` 随机文件名，目录 0750；只经 `hr.file` 鉴权下载；≤10MB，扩展名与文件头都须是 pdf/jpg/png。
- **离职**：状态改离职 → 三条待办（合同终止 / BPJS 停保 / CRM 账号停用）→ 对应 `users.active = 0`（不删）。`Auth` 拒绝停用账号登录，已登录的下次请求即退出。
- **迁移**（一次性，app_meta `hr_employees_v1`）：除 admin 外每个 CRM 账号建档，部门按角色：sales→销售、supervisor→财务（antonni）、finance_manager→财务、warehouse→仓库、clerk→行政（Fera）、manager→管理（tania），工作地 Jakarta、状态在职，其余待 HR 补。`perm_hr_v1`：hr 模块给所有非 admin 角色，hr_all 给 manager。
- **关联 CRM 账号**（任务卡外，用户 2026-10-21 要求）：详情页「岗位与薪资」下拉框，只列未关联的非 admin 账号，一账号一员工，可取消关联；`hr.link`，审计 users.employee_id。离职员工不可改关联。
- **撤销离职**（任务卡外，用户 2026-10-21 要求）：`hr.unresign`，必填原因；状态恢复为离职前（取最近一条离职变更的旧值）、清空离职日期/原因、未处理的离职待办改 `cancelled`、CRM 账号 active 恢复 1；已处理的待办不回滚（页面提示人工处理）。每次离职的待办 ref = `resign:{变更记录 id}`，撤销后再离职会重新生成三条。审计动作 `unresign`。
- **待后续**：每日 07:00 WIB 待办扫描随 HR-02 上线（用户同意；服务器时区 UTC+8，cron 写 08:00）。
- **薪资密钥**：已配置在服务器 .env（2026-10-21 换过一次，旧的在聊天中出现过已作废）；备份在站长 Mac 钥匙串「AluPanel HR_SALARY_KEY」。

## 6x. 订单录入：含税 / 不含税单价（2026-10-21）

**为什么**：价目表是含税价，但部分客户按**不含税价**谈价格、要求发票单价就是那个数。以前销售/财务手工 ×1,11 换算。

**做法**：订单明细上方加开关「单价按：含税 / 不含税（客户按税前价报价）」（`orders.price_basis`，默认 `incl`）。明细同时显示 **含税单价** 与 **不含税单价** 两列：所选口径那一列可填，另一列自动换算且只读——不含税 = 含税 ÷ 1,11 截到 2 位；含税 = 不含税 × 1,11 四舍五入到分（与 Coretax 口径一致，JS 与服务端都按"分"整数算）。从价目表选产品时填入的是含税价并自动换算。
- **含税单**（默认）：与之前完全一样；发票 Harga Satuan = 含税 ÷ 1,11 截断；客户应付 = 报价含税总额，几分差额体现在 Total Invoice 与 Total Amount 之间。
- **不含税单**：`order_items.price_excl` 存销售填的不含税价（**权威**），`price` 存由它算出的含税价（供订单金额、列表等展示）；服务端**忽略表单提交的含税价**，只从不含税价推算。开票时 Harga Satuan = 原样的不含税价，**客户应付 = Subtotal + PPN**（等于税票，rounding = 0）。订单详情页标「本单按不含税价报价」并显示两列单价。
- 运费仍按含税填写（发票上的 Ongkir = 运费 ÷ 1,11）。
- 已知小差异：不含税单的订单列表金额 = Σ 数量 × 推算的含税价，可能和发票 Total 差几分钱（发票是准的）。

**测试**：`tests/price_basis_test.php`（不含税单价原样上发票、2000 单随机验证、无尾差、服务端忽略表单含税价、编辑保持口径、详情页、含税单不变、开票端到端）；迁移测试覆盖新列。

## 6w. 发票按 Coretax 口径计算（2026-10-21）

**为什么**：财务在 Coretax 开税票（Faktur Pajak）时手算税前单价、DPP、PPN；系统发票按整数卢比逐行四舍五入，和 Coretax 对不上（例：系统 VAT 148.649，Coretax 148.648,64）。用户要求与 Coretax 统一：**保留两位小数、不四舍五入（截断）**。

**新发票的算法**（`invoice_amounts_coretax()`，全程整数"分"运算，避免浮点把 ,69 算成 ,6899…）：
- Harga Satuan = 含税价 ÷ 1,11，截到 2 位小数（150.000 → 135.135,13）
- 每行金额 = 数量 × Harga Satuan（精确）；Subtotal = Σ 行 + 运费 ÷ 1,11
- DPP Nilai Lain = Subtotal × 11/12，截到 2 位；PPN = DPP × 12%，截到 2 位
- **Total Invoice = Subtotal + PPN**（= 税票合计），**Total Amount = 客户报价含税总额**（精确到分，客户实付）。二者之差（几分钱，截断只会让 Total Invoice 略小；大约每张 ≤1,1 分）存 `rounding`，**不并进 PPN、不单列 Pembulatan 行**，发票上就是这两行之差（用户选定）。
- 发票 11752：Subtotal 1.351.351,30 / DPP 1.238.738,69 / PPN 148.648,64 / Total Invoice 1.499.999,94 / Total Amount 1.500.000,00。

**数据**：`invoices.calc`（`legacy` | `coretax`）+ `invoices.dpp`（ensureSchema 加列，存量全部标 legacy、不重算——已开给客户）。新发票由 `fulfill_order()` 按 `config.php` 的 `invoice_calc`（默认 `coretax`）和 `coretax_decimals`（默认 `truncate`，可改 `round`）生成。`invoice_display()` 对 coretax 发票：PPN 原样、两位小数、`invoice_total` = Subtotal + PPN；对 legacy 发票保持原样（`invoice_rounding` 仍只作用于它们）。打印、Word、系统详情页（显示 "DPP Nilai Lain" 和 "Total Invoice (= Coretax)"）三处一致。`tools/fix_invoice_rounding.php` 的计划函数跳过 coretax 发票。
- **待核实**：截断是用户的观察，尚未用一张实际税票逐项比对 Harga Satuan / DPP Nilai Lain / PPN。若发现 Coretax 是四舍五入，把 `coretax_decimals` 改成 `round`（只影响之后新开的发票）。
- 后续可做：从系统直接生成 Coretax 可导入的税票文件，彻底免去手工录入。

**测试**：`tests/coretax_test.php`（例子逐项、3000 单随机校验每一步都是正确的截断/四舍五入、展示三处、legacy 不变、仓库确认出货端到端开出 coretax 发票）；迁移测试覆盖新列。测试夹具 `run_controller.php` 支持 `{"real": true}` 用真正的 `Auth` 登录（订单审批函数要求 `Auth` 类型）。

## 6v. 表单过期不再停在 "CSRF token mismatch"（2026-10-21）

**现象**：登录时出现白页 "CSRF token mismatch. 请刷新页面重试。"。**原因**：登录页（或任何表单）打开后停留超过会话寿命（PHP-FPM `session.gc_maxlifetime = 1440` 秒 = 24 分钟空闲），会话被回收，表单里的令牌对不上；在别的标签页登录/退出过也会这样。正常流程经外部实测无问题（地址栏显示 `alupanel.cc` 只是 Chrome 隐藏了 www，裸域名本身没有 DNS 解析）。
**改动**：`Csrf::verify()` 仍然拒绝这次提交（防护不变），但不再输出死页，而是带一条「页面已过期…请重新提交」提示 303 跳回来源页（只接受同站 Referer 的路径部分；登录提交端点不回跳，改回首页→登录页）。登录页本身不套 layout，所以 `auth.login` 会把待显示的错误 flash 放进页面的 `$error`。
**测试**：`tests/csrf_test.php`；测试夹具 `tests/support/run_controller.php` 新增可选的会话 JSON 参数与 `TEST_HTTP_REFERER/TEST_HTTP_HOST` 环境变量。

## 6u. 财务反馈修复：订单小数 + 发票 Word（2026-10-21）

**1. 订单输入小数点**：订单表单的单价、运费是 `type="number"` 却没写 `step`，浏览器默认步长 1，输入 12.5 / 12,5 提交时被拦下（服务端本来就按小数解析）。改为单价、运费、收款金额、客户/商机金额 `step="any"` + `inputmode="decimal"`（手机弹数字小数键盘）；**数量仍只能整数**——库存按整张记账（`adjust_stock()` 收整数），小数张数会被截断。收款表单的默认金额不再 `(int)` 截断。

**2. 发票导出 Word**：原来是 HTML 改名 `.doc`（`app/Word.php`），Word/WPS 对 HTML 表格排版松散（百分比宽度、嵌套表格错位），且 HTML 里无法嵌入图片——模板本身也没放 logo。改为**真正的 .docx**：`app/Docx.php`（零依赖，ZipArchive 直写 WordprocessingML，与 Excel 导出同思路），模板 `views/word/invoice_docx.php` 照打印版（已与公司模板核对的版式）排：logo（`public/assets/img/logo-print.png` 嵌入文件内，限 50×14 mm 等比）、地址框、Invoice 标题、开票信息表（含 Job NO）、明细、合计（含 Total Invoice / Pembulatan / Total Amount）、Say 大写金额、银行信息、签字。表格固定列宽（twips）、每个文字 run 显式 Arial、元素按 OOXML schema 顺序（Word 对顺序严格）、零中文。旧 `views/word/invoice.php` 已删除。
- **送货单 Word 仍是旧的 HTML 方式**（`views/word/do.php` + `Word.php`），如财务/仓库也反馈排版问题，可用同一个 `Docx` 改造。
- 本机验证：macOS 快速预览能渲染新文件（logo、字体正确），但它的导入器会丢弃所有表格宽度（转出的 HTML 里没有任何宽度），所以那里看到的表格偏窄不代表 Word 里的效果；文件里宽度声明完整（tblW / gridCol / tcW / fixed layout），以 Word/WPS 实际打开为准。

**测试**：`tests/docx_test.php`（包结构、XML 合法、logo 嵌入与比例、全部字段、转义、零中文、固定布局不超页宽、schema 顺序、作废章、运费行、表单小数）。

**3. Pembulatan 并入 VAT 显示**（用户与财务 2026-10-21 商定，财务自己的 Excel 一直这样算）：打印版、Word、系统发票详情页统一由 `invoice_display()`（`app/domain.php`）决定合计怎么呈现，开关 `config.php` 的 `invoice_rounding`：`'vat'`（默认，尾差并进 VAT 行，纸面上 Subtotal + VAT = Total）/ `'line'`（单列 Pembulatan 行，即 6n 最初方案）/ `'hide'`（不显示）。**库里的数据不变**：`invoices.ppn` 仍是 Subtotal × 11% = DPP × 12%，尾差仍在 `rounding`，Excel 导出也照旧分列——所以将来做 Coretax 税票导出时精确税额还在。发票详情页 VAT 旁边小字注明「含尾差 −Rp1」，财务能看到。
- 代价：约 31% 的发票（线上 143/455）纸面 VAT 与 DPP × 12% 差 Rp1–3；若 Coretax 税票按 DPP × 12% 自动算 PPN，两边差这一两块钱。用户知情后决定如此。
- 当天曾先改为"隐藏 Pembulatan 行"（Subtotal + VAT 与 Total 对不上），随即按财务意见改为并入 VAT。
- 背景澄清：发票上的单价**一直是税前价**（开票时 = 含税价 ÷ 1.11，线上 461 张全部如此），财务"手算税前单价"并非系统缺这项功能。

## 6t. 看板 · 业绩排名加「张数」列（2026-10-17）

业绩排名表在「新订单」和「销售额」之间加了**张数**。所有订单明细的单位都是 Unit（ACP 板材），数量全为整数，直接相加就是张数。合计行同步显示总张数。只在有「销售业绩」权限时可见（和排名表本身一致）。

**口径 = 出货口径（用户 2026-10-17 确定）**：只算仓库已确认出货的订单（status=approved），**按仓库确认日期 `orders.wh_date` 归期**——这一天系统同时生成送货单和发票，发票日期就是它（线上 437/437 吻合）；发票已作废的订单不算（与销售额一致，线上 5 单）。所以同一期的张数和销售额是同一批货。「新订单」仍按下单日期、含审批中的订单（它回答"这期新接了多少单"）。最初上线时张数按下单日期统计，当天即改为出货口径。
- 两种口径只在月末/周末附近有差：线上 98% 的订单 0–2 天内出货。10 月 1–17 日按出货口径 3,539 张，按下单口径 2,971 张，差额 568 张来自 7 张 9 月下单、10 月出货的订单（6 张 9-30 下单，1 张 9-18 下单等了 19 天）。

顶部数字卡片加了**销售张数**（无「销售业绩」权限的人看到的是**我的销售张数**，只算自己的订单），口径同上（出货口径），并和其他卡片一样与上一期同口径时段对比涨跌。卡片行改为自适应列数：桌面一行 5 张；手机 2 列，奇数张时最后一张占满一行。

## 6n. 发票尾差订正（2026-10-12）

**问题**：394 张关联订单的发票里 **123 张（31%）合计和订单含税额差 ±1–3 卢比**。真正的危害是：客户按订单整数付清后，发票仍挂着 1 卢比欠款被判"逾期"——**看板上 117 张逾期里约一半是这种幽灵欠款**（订正后快照试算降到 60 张）。财务一直在手动绕：有人给 `1240` 专门补登了一笔 1 卢比收款，另有 4 张照着错误合计（如 1.450.001）登记了收款。

**决策**（用户确认）：差额单列为 **Pembulatan** 行，而不是让 VAT 吸收——VAT 必须保持 DPP×12%，否则和税务发票（Faktur Pajak）对不上。
> **2026-10-21 更新**：发票**呈现**改为尾差并入 VAT（财务要求，见 6u-3）；库存储方式不变（ppn 仍为 DPP×12%，尾差在 rounding 列）。

**新发票**：`invoice_amounts()` 计算，3000 单随机订单对照测试证实 Subtotal/VAT 与旧算法逐位一致，合计恒等于订单含税额。

**历史发票**：`tools/fix_invoice_rounding.php`（默认预演，`--apply` 执行，单事务，幂等，逐张写审计日志）。**只订正"用现行算法重算 Subtotal 和 VAT 与库里完全一致"的发票**——这证明除了尾差以外什么都没变；孤儿发票、被改过的、差额 >5 卢比的一律跳过并列出。`ensureSchema()` 只加 `rounding` 列（默认 0），**不会在页面加载时静默改写财务数据**。订正后自动刷新收款状态。

```bash
cd /www/wwwroot/www.alupanel.cc && /www/server/php/82/bin/php tools/backup_db.php
/www/server/php/82/bin/php tools/fix_invoice_rounding.php            # 预演
/www/server/php/82/bin/php tools/fix_invoice_rounding.php --apply
chown -R www:www data && chmod -R u=rwX,go=rX data
```

**订正后遗留 5 张，需财务手动处理**（收款流水是只追加的现金记录，工具不改）：`174`（Rose Aluminium，收款 939.999 → 订正后欠 1 卢比，需补登或冲销重登）；`255` / `1163` / `1218` / `1240` 各多收 1 卢比，显示已收清，可用冲销功能清掉多出的 1 卢比（`1240` 那笔单独的 Rp 1 收款直接冲销即可）。

## 6m. 数据看板改版：日 / 周 / 月（2026-10-12）

**改了什么**：原看板是"累计至今"口径，混着销售漏斗、客户数、任务完成率。现在以**销售业绩 + 回款应收**为主，顶部一个「日 / 周 / 月」切换 + 前后翻页，下面所有图表都跟着这个时段走。漏斗、任务完成率从看板移除（各自模块页仍在）。逻辑在 `app/Dashboard.php`（可测试的静态类），控制器只决定谁看什么。

**口径**（与财务模块一致）：
- **销售额** = 期间内**开出的发票**合计（作废除外）。发票只在仓库确认出货后才生成，所以是真实营收，不含还在审批中的订单。
- **新订单** = 期间内创建的订单数（草稿除外）。
- **回款** = 期间内收款流水合计，**冲销按负数计入**——是实际净收到的钱。
- **应收余额** = 当前所有有效发票的未收合计，是**时点余额**，不随所选时段变化（界面标注「截至今日」）。按发票逐张截断负数，避免多收 1 卢比的发票抵消真实欠款。

**同期对比**：进行中的时段与上一时段**相同天数**比较（10 月 1–12 日 vs 9 月 1–12 日，标注「较上月同期」），否则每个月前三周都会显示暴跌。已结束的时段比较完整上一期。月末自动截断（3/31 对比 2 月只到 2/28）。周从周一开始。

**内容**：四个指标卡（销售额、新订单、回款、应收余额+逾期金额）→ 趋势图（日 14 天 / 周 12 周 / 月 12 个月，**开头连续为空的时段会被裁掉、至少保留 6 列**——系统 2026 年 7 月才启用，之前的空月份看起来像图坏了；开票 vs 回款，柱子可点击下钻到该时段，附可展开数据表）→ **宽屏下销售排行（3/5）与应收账龄（2/5）左右并排**，1100px 以下堆叠 → 销售排行（新订单 / 销售额 / 本期回款 / 当前未收 + 合计行）→ 应收账龄（未到期 / 1–30 / 31–60 / 61–90 / 90+ 天，堆叠条 + 金额表 + 逾期金额最多的 5 个客户）→ 本期热销产品（**按 SKU+规格分组**，SKU 只是颜色码）与最近订单并排。周期切换和时段标题并排靠左（原先两端对齐，宽屏上中间空出近 900px）。指标卡标签改用正文无衬线字体（全局 `.stat-label` 是等宽字体，中文/印尼文显得像打字机）。

**权限**（不变）：无 `performance` 只看本人数据（标签变「我的销售额」）、无排行；无 `finance` 不显示任何回款/应收/账龄数字，两个财务卡换成「待我审批」（主管/经理/仓库，按 `order_action_role()` 映射）或「我的订单审批中」+「被驳回待修改」，趋势图标题也去掉「回款」字样。

**配色**：蓝 `#2a78d6` = 开票、品牌绿 `#00a884` = 回款，经 dataviz 校验器验证色弱可区分（protan ΔE 20.3，原绿+琥珀只有 11.6）；绿色对白底对比度不足 3:1，按规范以数据表兜底。账龄用单色相递进色阶（浅橙→深红），同样校验通过。定义为 `app.css` 的 `--c-sales / --c-paid / --age-*` 变量。

**上线后看到的业务问题（2026-10-12）**：10 月开票 4.02 亿、回款只登记 4,690 万，**最后一笔回款是 10/3**，日视图上绿柱从 10/4 起完全消失；应收 4.39 亿中 93% 是逾期 1–30 天。大部分发票付款条件是 CBD/COD（到期日=开票日），所以未登记回款的发票第二天就算逾期。更可能是**回款没及时录入系统**而不是客户真没付——需要财务确认。另外排行里 `erpandi` / `erfandi` 很可能是同一个人（销售名是自由输入）。

## 6l. 发票作废（2026-08-29）

**为什么是作废不是删除**：发票是对外财务凭证。开错了不能凭空消失——单据留着、标记作废、写明原因和经手人，这才是会计上正确的做法，也和收款冲销（6h）是同一套思路。删除只保留给**测试数据清理**（6k，CLI 工具）。

**字段**：`invoices.voided_at / voided_by / void_reason`（ensureSchema 幂等加列）。用独立标记而不是 `payment_status='void'`，因为 `refresh_invoice_status()` 会重算 payment_status，标记会被冲掉。

**规则**：
- **有收款记录的发票不能直接作废** —— 必须先在收款流水里逐笔冲销（6h），把钱退干净了再作废。两个功能天然衔接：`invoice_void_block()` 检查 `SUM(payments.amount)` 是否为 0。
- 作废后：**移出应收统计**（已收/待收/逾期三个卡片和列表筛选都加了 `voided_at IS NULL`）；**不能再登记收款**；`refresh_invoice_status()` **跳过已作废发票**，否则时间一过它会自己变成"逾期"，账面上就多出一笔不存在的欠款。
- **库存和送货单不动** —— 作废的是账单，不是发货。货已经出去了就不会退回来。
- **撤销作废仅限 admin**（`finance.unvoid`）：作废本意是终局的，留一个管理员纠错的口子但不下放。

**界面**：发票详情页顶部红色横幅（谁、何时、什么原因）+ 作废按钮。**有收款的发票，作废按钮显示为不可点并带上原因**（tooltip + 页面提示），而不是让人点了再报错——按钮点了没反应会让员工不信任整个系统；旁边的「冲销」按钮就是下一步该做的事。列表里整行删除线 + 红色「已作废」标签 + 一个「已作废(n)」筛选页签（有作废发票时才出现）。**打印件和 Word 导出都加醒目的 `VOID / BATAL` 边框标记**——纸质单据绝不能被误当成有效发票（英文，遵守打印零中文规则）。

**审计**：`finance/void` 和 `finance/unvoid`，记金额、客户、原因。

## 6k. 测试数据清理（2026-08-29）

**背景**：线上 169 张发票里混着三类数据——首次建库写入的**种子示例**（`PT Maju Bersama` 等 9 个客户名，见 `Database::seed()`）、**测试时乱敲的**（`8888` / `fffff` / `kahsjkfhkasfh`）、以及**真实业务数据**。另有 **19 张孤儿发票**和 16 张孤儿送货单。

**孤儿是怎么来的（已修）**：`orders.delete` 允许 admin 删任意订单，但 `invoices.order_id` 和 `delivery_orders.order_id` 是 `ON DELETE SET NULL`——删掉订单只是把关联置空，发票和送货单永远留在财务列表上。现在 `orders.delete` **会拒绝删除已开票的订单**并提示用清理工具，不再产生新孤儿。

**工具** `tools/cleanup_test_data.php`（CLI，不做成界面按钮——发票是财务凭证，界面上放一个永久的删除键，风险远大于一次性清理的便利）：
- **默认只预演不删除**，`--apply` 才真正执行；全程一个事务，失败整体回滚。
- **自动跳过有收款记录的发票**（有钱进出的多半是真单），要删必须显式 `--force`。
- 选择方式：`--customer="名字"` / `--invoice="单号"` / `--order="单号"` / `--seed`（种子客户）/ `--orphans`（孤儿发票）。
- 不带参数运行 = 打印数据概览和按客户的发票分布，用来决定删什么。
- `--restore-stock` 回补已出货订单扣掉的库存（只回补该订单的 `out_auto` 流水，手动出入库不动）。
- 删除后写审计日志（module=finance / action=delete）。

**注意**：客户名是否"像测试数据"**无法自动判断**——`Sasmita`、`fadoli`、`Rudi`、`Aluminium99` 这些看着可疑的其实很可能是真实的印尼个人客户或小店。工具刻意不做自动识别，必须人工逐个确认。

**`--orphan-stock`（另一半烂摊子）**：删订单**从来不会回补库存**。测试单走到过"确认出货"的，库存被真扣了但货没出去——线上有 15 条这样的孤立流水，其中一条一次扣了 200 张板。因为订单已删、`stock_txn.ref` 对不上任何订单，只能直接按流水处理。**这个模式强制要求 `--before=DATE`**：孤立流水里既有测试单（5-6 月）也有真实发货（8 月，货确实出库了，回补会把账做错），必须用日期切开。

**线上实际情况（2026-08-29 排查）**：19 张孤儿发票里，**5 张是真实业务**（`139/1039/1048/215/1057 - AMI - INV - 08 - 26`，客户 HANIF、ADIL、Rose Aluminium、Zalati Alumindo、Ardi Mandiri，其中 2 张有收款），它们的订单同样被误删过。**所以 `--orphans` 不能单独用**，必须配 `--before` 或直接列发票号。

**执行前务必** `php tools/backup_db.php`。

## 6j. AI 智能查库存（2026-08-28）

**场景**：销售在客户现场用手机，要查库存得进列表页翻 269 个产品。改成一句话问答：「4.0 银色拉丝还有多少张？」「客户要 50 张 3mm 白色，够吗？」中文/印尼语/英文混着说都行。入口 `inventory.ask`，侧边栏「智能查库存」，权限沿用 `can_access('inventory')`。

**核心安全属性：模型只挑产品，数字一律来自数据库。**
- 喂给模型的目录（`Ai::catalogue()`）**故意不含库存数**——既因为库存每小时在变，也为了让模型根本没有可引用的数字。
- 结构化输出（`output_config.format` + JSON Schema）只接受 `product_ids` 整数数组，**schema 里没有任何能装下数量的字段**。
- 拿到 id 后由 `ai_resolve_products()` 查实时 `stock/reserved`，算出可用量。模型幻觉出不存在的 id 会被直接丢弃，不会变成错误答案。
- 界面显示完整产品名 + SKU + 规格，**让人一眼看出 AI 有没有认错货**。

**成本控制**：
- 产品目录放在**带 `cache_control` 的系统前缀**里，问题在 `messages` 中（必须在缓存断点之后，否则每次都是 cache miss）。目录渲染保持字节稳定（按 id 排序、无时间戳），测试里有断言守着。
- `output_config.effort = 'low'`——这是查表不是推理。
- `ai_queries` 表记录每次调用的提问、匹配结果、**token 用量**（含 `cache_read_input_tokens`），可据此实测真实成本。
- 每人每天上限 `ai.daily_limit`（默认 60 次），失败的调用也计数，防止出错时反复重试烧钱。

**驱动可切换**（`config.php` 的 `ai.driver`）：
- `stub`（**默认**）= 纯 PHP 关键词匹配，不发任何请求、零成本。整条链路（界面、限流、日志、实时库存渲染）都能跑通，**没有 API key 也能用**，同时也是 API 故障时的兜底。
- `claude` = 调 Anthropic Messages API，模型 `claude-opus-5`。

**开通**：在 console.anthropic.com 拿 API key → 填 `config.php` 的 `ai.key` → `driver` 改 `claude`。key 在项目根目录的 `config.php`，不在 `public/` 下，外网访问不到。

**未验证**：`claude` 驱动**从未发过真实请求**（无 key）。请求体的线上格式（模型名、缓存断点位置、structured output schema、Chinese JSON 编码）有 13 项断言守着，但真实往返要等填了 key 才知道。首次开通建议先自己问几句，核对答案与库存页一致。

**后续**：文字下单（解析 → 预填订单表单 → **人工确认后**才走 `save_order()`，绝不自动提交）；WhatsApp 入站（销售发消息直接生成草稿）。

## 6i. WhatsApp 通知（2026-08-28）

**目标**：印尼员工不会主动打开系统，审批卡住的最常见原因就是"没人知道轮到自己了"。通知是让系统进入日常动线的触发器。**销售下单统一由销售助理负责**，所以通知的主要服务对象是**审批链流转**，不是销售的日常。

**架构（发送通道可插拔）**：
- `notifications` 表 = **队列**。业务代码只调 `Notify::queue()`，Web 请求里**从不**调用第三方 API——服务商慢或挂掉都不会让审批卡住或丢失。
- `tools/send_notifications.php` 由 cron 每分钟消费队列。失败重试，`Notify::MAX_ATTEMPTS`(3) 次后标 `failed` 不再循环。
- 通道在 `config.php` 的 `wa.driver` 切换：`log`(只入库不发送，默认) / `fonnte`(印尼服务商) / `cloud`(Meta 官方)。**换服务商是改配置，业务代码零改动**。零依赖，用 PHP 自带 curl。
- `wa.test_to` 设了之后**所有消息都改发这个号**，第一次开通时用来验证不会误扰员工。

**收件人与语言**：
- `users.phone`(WhatsApp 号，`Notify::normalise_phone()` 把 0812/+62/62-812 各种写法统一成 `628xxx`) + `users.lang`(通知语言)。
- **消息用收件人自己的语言渲染**（`t_in()`），不是发起人的 session 语言——否则中国老板操作一下，印尼员工收到中文。
- 没填号码的人**入库标 `skipped` 并记原因**，不静默丢弃；否则"某个审批人从来收不到通知"这件事没人会发现。

**通知点**：
| 事件 | 通知谁 |
|---|---|
| 订单提交 | 主管（`notify_order_stage()` 复用 `order_action_role()`，保证"通知谁"和"谁有权审批"永远一致） |
| 主管通过 / 经理通过 | 经理 / 仓库 |
| 订单驳回 | **助理 + 对应销售**（`order_stakeholders()`） |
| 仓库确认出货 | 助理 + 销售（含送货单号、发票号） |
| 行政审批提交 / 各级通过 | 对应阶段角色（人事 / 经理 / 财务经理） |
| 行政审批通过 / 驳回 | 申请人 |

新增 `orders.created_by` 记录**实际录单的人**（助理），与 `submitter`（订单归属的销售）区分——驳回和出货要同时通知这两个人。

**查看**：`audit.notifications`（审计日志页右上角入口，同样 admin-only）列出每条通知的接收人、内容、状态、失败原因，可按状态筛选。没有这个页面通知就是黑盒。

**运维**：宝塔计划任务每分钟执行
```
/www/server/php/82/bin/php /www/wwwroot/www.alupanel.cc/tools/send_notifications.php
```
队列为空时静默退出。有 `failed` 记录时在 cron 日志里告警。

**开通步骤**：① 用户管理里给每人填 WhatsApp 号和语言 → ② `config.php` 填 `wa.token`、把 `driver` 改成 `fonnte`、`test_to` 先填自己的号 → ③ 触发一次审批，确认自己收到 → ④ 清空 `test_to` 正式启用。

**选型说明**：先用 Fonnte 这类印尼本地服务商起步（当天可用、消息内容随便写、便于快速迭代措辞），**务必用专门的便宜 SIM 卡，不要绑公司主号**（非官方通道理论上有封号风险）。跑顺一个月、措辞稳定后再迁 Meta 官方 Cloud API（要商业验证 + 模板预审，约 2-3 周）。

## 6h. 收款冲销（2026-08-28）

**背景**：财务反馈"已登记收款的发票能不能改"。查下来除发票号外**什么都改不了**——收款记录既不能编辑也不能删除。金额录错(如 500.000 打成 5.000.000)会让发票直接变"已付清"，界面上无法补救，只能改库。

**做法：冲销，不是编辑或删除。** 追加一笔等额负数记录抵销原记录，两条都留在流水里：
- `payments` 新增 `reversal_of`(指向被冲销记录的 id，只在冲销行上有值) 和 `created_by`(登记人/冲销人)。
- **`invoices.amount_paid` 改为按流水合计重算**(`recompute_invoice_paid()`)，不再累加。这是冲销能生效的前提，也永久杜绝了缓存值与流水漂移。
- 入口：发票详情页每条收款右侧「冲销」→ 确认页(显示原记录 + 冲销后已收金额变化) → **必填原因** → 提交。分两步是刻意的：冲销会改动账面金额。
- 护栏 `payment_reversal_block()`(在 `domain.php`，确认页与 POST 共用同一判断)：冲销记录本身不能再冲销；同一笔不能冲销两次(否则会重复贷记客户)。
- 已冲销的原记录和冲销行在流水里加删除线 + 标签(`已冲销` / `冲销记录`)，并显示冲销原因和操作人。
- 审计日志记 `finance/reverse`，含原金额、收据号、冲销后累计已收、原因。

**注意**：权限与「登记收款」相同(有 finance 模块访问权即可)，没有额外限制——小公司里犯错的人通常就是要改的人。控制手段是"不可删除 + 必填原因 + 审计留痕"，而非限制人。

**线上升级**：`ensureSchema()` 幂等加两列 + `idx_payments_invoice` 索引；历史收款记录 `reversal_of` 为 NULL，照常可冲销。已验证既有 40 条流水不受影响。

## 6g. 审计日志（2026-08-28）

**表** `audit_log`（**只追加**，全站没有任何 UPDATE/DELETE 它的代码路径）。**模块键** `audit`，进权限矩阵但**默认不授予任何角色**——只有 admin 能看，除非在权限设置里显式勾选。控制器 `app/controllers/audit.php` **只有 index 一个动作**（没有编辑/删除入口，被攻破的账号无法从界面抹除自己的痕迹）。

**核心助手**（`app/domain.php`）：
- `audit($pdo, $module, $action, $entity, $entityId, $label, $detail)` —— 写一条记录。操作人/角色/IP 从 `$GLOBALS['auth']` 与 `login_client_ip()` 自动取；`label` 截断 200 字、`detail` 截断 2000 字。**整个函数包在 try/catch 里**：日志写失败绝不能让已成功的业务操作变成 500（比如库还没迁移、或写锁冲突）。
- `audit_diff($before, $after, $fields)` —— 生成"单价: 100 → 120; 库存: 5 → 8"，跳过未变字段，空值显示 `(空)`。
- `audit_snapshot($pdo, $table, $id)` —— 删除前取快照，好把被删对象的名字记进日志。

**已挂钩的写操作**（module/action）：
| 模块 | 记录的动作 |
|---|---|
| orders | create / update / submit / approve（含主管、经理两级）/ reject（记阶段+理由）/ **fulfill**（扣库存+DO+发票，含单号与金额）/ delete |
| finance | **pay**（收款金额、方式、累计已收/总额、收据号）/ update（改发票号，记 旧→新）/ create（出货自动开票） |
| inventory | create / update（字段级 diff）/ **adjust**（出入库方向、数量、库存前后值、事由）/ delete |
| customers | create / update（含**改派负责销售**）/ delete |
| approvals | create / update / submit / approve（人事、经理、财务三级）/ reject / delete / **删除附件**（报销单据被删是重点监控项） |
| users | create / update（**角色变更**明文写出、重置密码标记）/ delete |
| roles | **permission**（逐角色列出"授予 X / 收回 Y"） |
| auth | login / logout / **login_failed**（无操作人，记尝试的邮箱+IP） |
| account | password（本人改密） |
| pipeline | create / update / move（阶段 旧→新）/ delete |
| tasks | create / delete |

**故意不记的**：任务勾选完成（`tasks.toggle`）——高频低价值，会把日志刷爆；纯读取动作（列表、详情、打印、导出）也不记。

**查看界面**：`audit.index` 支持 模块 / 动作 / 操作人 / 起止日期 / 关键词（对象+变更内容+操作人）筛选，**分页 50 条/页**（现已抽成通用的 `paginate()`/`pager()`，见 6o）。动作用颜色标签区分（删除/驳回/登录失败=红，通过/新建=绿，调库存/权限=橙）。

**线上升级**：`ensureSchema()` 自动建表+3 个索引，**不给任何角色授权**。已验证：模拟线上现状（有 app_meta 标记、无 audit_log）跑迁移后只新增 audit_log，既有权限一条没动，重复跑幂等。

## 6f. 服务器访问与安全审计（2026-08-28）

**仓库已转为 private**。服务器 git remote 相应从 HTTPS 改为 **SSH**（`git@github.com:tomtrees17/alupanelcrm.git`）——private 仓库走 HTTPS 会卡在认证上，**不要改回去**。认证用 `/root/.ssh/id_ed25519`，该公钥已注册在 GitHub 账号 `tomtrees17` 的 SSH keys 下（不是 deploy key，所以服务器实际拥有该账号**所有仓库**的访问权；想收紧就从账号 SSH keys 移除、改加为本仓库的 read-only deploy key）。

**SSH 访问**：`ssh -p 22022 root@149.129.218.9`，**仅限密钥登录**（2026-10-12 已关闭密码登录，见下）。授权的公钥：`yuan-imac`（Mac，`SHA256:ndFF…QnU`）、`windows-pc`（Windows，`SHA256:Moraa…kfo`）、服务器自身的 `id_ed25519`（`SHA256:9GE/…AMU`，用于拉 GitHub）。**新增电脑必须先由已授权的电脑把它的公钥追加进 `/root/.ssh/authorized_keys`**——密码登录已关，新电脑无法用 `ssh-copy-id`。主机指纹 ED25519 `SHA256:Ub6Xr8wg7Bg1y6ilzPzVfQsVxU8kEOHL0GDkOxK3hkw`。**退路**：阿里云控制台 VNC 远程连接可用（已验证），SSH 配置改坏时靠它救场。

**2026-10-12 加固完成**：`PermitRootLogin prohibit-password` + `PasswordAuthentication no`（`sshd_config` 第 132/133 行），`kbdinteractiveauthentication no` 保持不变（否则 PAM 会绕过）。外部验证：服务器只接受 `publickey`，强制密码登录被拒。改前备份在 `/root/sshd_config.bak.pre-hardening`；回滚：`cp -a /root/sshd_config.bak.pre-hardening /etc/ssh/sshd_config && systemctl reload sshd`。**应急退路**：阿里云控制台 VNC，同日用 `passwd` 重设了 root 密码（原密码已无人记得——Windows 上三次输错），并退出了一个从 7 月起一直挂着的 tty1 root 会话（任何能进阿里云控制台的人都可以免密拿到 root）。

**审计结论：无入侵迹象。** authorized_keys 里两把钥匙——`SHA256:9GE/...`(服务器自己的 id_ed25519.pub，setup 时自我授权，无害) 和 `SHA256:ndFF...`(yuan-imac)，没有第三方公钥；可登录 shell 的账号只有 root；所有成功登录都来自本人 IP（`101.0.4.x` / `100.104.x.x` CGNAT 移动网络）。

**当前风险（截至 2026-08-28 未处理）**：
| 项 | 现状 | 风险 |
|---|---|---|
| ~~SSH 密码认证~~ | **已关闭（2026-10-12）**，只剩密钥登录 | 已解除——累计 67 万次爆破全部失效。fail2ban 仍未安装（需 EPEL，阿里云 Linux 4 默认无），现在只起减少日志噪音的作用，可选 |
| 宝塔面板 8888 / phpMyAdmin 888 | firewalld 放行，对公网开放 | 中高——宝塔面板历史有未授权访问漏洞 |
| MySQL 3306 | 监听 `0.0.0.0`（无 bind-address），但 **firewalld 未放行** | 低——外网连不到；且本项目用 SQLite，该 MySQL 疑似闲置 |
| FTP 20/21 | firewalld 放行 | 低——明文协议，未在用可关 |

firewalld 放行清单：`20, 21, 22, 80, 443, 888, 8888, 22022, 37775, 39000-40000`（`22/tcp` 是残留，sshd 只听 22022）。阿里云**安全组**规则是 firewalld 之外的第二道防火墙，服务器内部看不到，需在控制台确认。

**加固待办（关闭密码认证的操作步骤）**——改前务必先确认 VNC 能进：
```bash
cp -a /etc/ssh/sshd_config /root/sshd_config.bak.$(date +%Y%m%d)
sed -i 's/^PermitRootLogin yes$/PermitRootLogin prohibit-password/; s/^PasswordAuthentication yes$/PasswordAuthentication no/' /etc/ssh/sshd_config
sshd -t && systemctl reload sshd          # reload 不断开现有连接
sshd -T | grep -E 'permitrootlogin|passwordauthentication|kbdinteractive'
```
生效值应为 `permitrootlogin prohibit-password` / `passwordauthentication no` / `kbdinteractiveauthentication no`（**最后一项必须是 no**，否则 PAM 会绕过 `prohibit-password` 让密码登录复活）。**改完先别关当前会话**，另开终端验证 `ssh -p 22022 root@149.129.218.9 hostname` 能通再关。回滚：`cp -a /root/sshd_config.bak.<日期> /etc/ssh/sshd_config && systemctl reload sshd`。

配置生效点在 `/etc/ssh/sshd_config` 第 132/133 行（宝塔追加）；`Include /etc/ssh/sshd_config.d/*.conf` 在第 15 行，那两个 redhat 默认文件没覆盖这两项，也没有 `Match` 块。直接改主配置是为了和宝塔面板的 SSH 管理界面保持一致，避免「面板显示开着、实际关着」。

## 7. 目录结构

```
CLAUDE.md                   开发约定（技术栈/目录/部署流程/代码约定，给 Claude Code 和新人看）
public/index.php            前端控制器（路由）
public/assets/css/{app,print}.css
public/assets/img/           app-icon.svg(PWA) / logo-print.png + .svg(发票抬头，png 优先)
app/
  bootstrap.php             启动装配（session, config, i18n, helpers, domain, Database, Auth, Csrf）
  Database.php              连接+建表+种子+ensureSchema(线上升级)
  domain.php                业务逻辑：库存增减、预留重算、可用/库存校验、单号生成、发票状态、terbilang
  helpers.php               视图辅助：e/url/redirect/idr/num/各label与tr_*翻译
  i18n.php                  中印双语字典 + t() + current_lang()
  Export.php                无依赖 Excel 导出（.xlsx via ZipArchive，CSV 兜底）
  Claude.php / AiTask.php   AI：唯一 HTTP 出口 / 任务调用层（模型按任务配置、prompt 版本、schema 校验、美元预算、ai_calls 台账，见 6zd）
  Bank.php                  银行流水：BCA/ICBC 解析、打标、规则匹配、AI 匹配、确认入账、回款状态、历史导入与盲测（见 6ze）
  Faktur.php                销项 Faktur：FAKTUR/GUNGGUNG/TBC 分类、填 DJP Coretax 导入模板、Digunggung 汇总、买方税务资料（见 6zf）
  Auth.php / Csrf.php
  controllers/              dashboard customers pipeline tasks finance orders inventory approvals audit delivery users roles account auth lang hr ai bank tax
prompts/                    AI 提示词 {task}_v{n}.txt（改提示词即升版本，旧版保留，见 6zd）
views/                      按模块分目录 + layout.php + print/ + errors/（account/password.php 改密页）
database/schema.sql         表结构
database/seed_products.sql  269 个产品（由 tools/gen_products.php 从原型抽取）
tools/reset_password.php    CLI 重置账号密码（运维，见 6d）
tools/backup_db.php         DB 快照 + 附件打包备份（运维，见 6e）
tools/import_bank_book.php  财务工作簿 Buku Bank 导入（别名种子）+ 回款匹配盲测（见 6ze）
tools/import_customer_tax.php 买方 NPWP 等税务资料一次性导入（Coretax 模板或任意含发票号+NPWP 的表，见 6zf）
database/coretax/           DJP Coretax 销项导入模板 v1.6.1 空白副本（生成 Faktur 用）
tools/run_tests.php         零依赖测试运行器（`php tools/run_tests.php`）
tests/*_test.php            测试用例（内存 SQLite，不碰 data/crm.sqlite）
data/                       运行时 SQLite + uploads/（gitignore，在 web 根之外）
config.php                  应用与公司配置
```

**服务器上的未跟踪文件**（宝塔生成，别提交也别删）：`.htaccess`、`404.html`、`index.html`、`public/.user.ini`、`public/.well-known/`。除此之外 `git status` 应该是干净的——**服务器上永远不要直接改代码**。

## 8. config.php 可定制项

`company_full / company_addr / banks[ICBC,BCA] / signer_name / signer_title`（发票抬头）、`company_npwp / company_nitku`（Coretax 导入表的卖方，FA-TAX-01；NPWP 已填）、`coretax`（Kode Transaksi / Kode Barang / 单位 / 运费行，见 6zf）、`bank`（回款匹配容差等，见 6ze）、`ai`（任务、单价、美元上限，见 6zd）、`ppn_rate=11`、`currency=Rp`、`brand=AluPanel`。

## 9. 数据模型（表）

**faktur_exports（生成的 Coretax / Digunggung 文件，见 6zf）**, customers(+tax_npwp/tax_nik/tax_name/tax_address/tax_nitku/tax_email 买方税务资料), invoices(+faktur_class 分类覆盖), **bank_accounts / bank_imports / bank_transactions / payment_allocations / customer_payer_aliases / bank_book（银行流水与回款对账，见 6ze）**, payments(+noncash 结案差额行), **ai_calls(AI 调用台账：任务/模型/prompt 版本/tokens/费用/耗时/状态，见 6zd)**, **ai_suggestions(AI 建议与人工确认、采纳率)**, hr_todos(+type ai_budget：老板专属、无员工), **sales_history / sales_history_items(历史销售，只读统计用，见 6zc)**, **co_requests(Google 表单 CO 号登记，见 6zg)**, orders(+co_no 客户看到的 CO 号), users(+must_change_password), customer_followups(跟进记录), customer_insights(回访清单, 夜间重建), insights_history(每日上榜记录), customers(+industry/industry_source/industry_confidence/industry_pending/industry_note/region/delivery_regions/enriched_at 客户画像), orders(+delivery_lat/delivery_lng/delivery_region/geo_source 送货点), invoices(+calc/dpp Coretax 口径), orders(+price_basis 含税/不含税), order_items(+price_excl), **geocode_cache(地理编码缓存)**, deals, tasks, products(+reserved), stock_txn, orders, order_items(+product_id), delivery_orders, invoices, invoice_items, payments, admin_requests(行政审批), role_permissions, app_meta, login_attempts, invoices(+rounding 尾差), **audit_log(审计日志)**, **notifications(WhatsApp 队列)**, **ai_queries(AI 用量与限流)**, payments(+created_by/reversal_of 冲销), users(+phone/lang), orders(+created_by)。

## 10. 提交历史（main）

最近 30 条（共 134 条，含本条文档提交；完整历史用 `git log --oneline`）：

```
xxxxxxx Docs: refresh commit history
0cb0f5b Bank: read the finance bank-statement workbook (sheet name, PDF-style notes, swapped dates)
0c0a401 Docs: refresh commit history
20fb934 Bank: after the history import, run the rules again over waiting lines
9ed92a6 Docs: refresh commit history
d3f3425 AI: requests the API refused without using tokens no longer count toward the per-record limit
19ff910 Docs: refresh commit history
63a97aa AI: keep schema limits the API rejects out of requests; retry failed bank matches
e8c3f3d Faktur: company NPWP for the Coretax import file
60162b2 Docs: refresh commit history
90cdbc8 Finance AI: FA-TAX-01 Coretax output-tax import file and buyer tax data
edea8d2 samples_test: remove two real buyer rows and author names from the Coretax fixture
746e07d Docs: refresh commit history
ec365de Finance AI: FA-AI-01 bank statement import and collection matching
ff50f0e Docs: refresh commit history
15ff01c Finance AI: FA-AI-00 task layer with USD budgets, call ledger and usage page
84327a3 Docs: finance AI task card v2, charter v2.4, de-identified bank/tax fixtures
3b3e287 Finance: import pre-platform sales as read-only history, yearly sales report
9238c20 HR: a resignation also terminates the active contract
248a9c8 HR wrap-up 1–3 (charter v2.2: no extra burden on staff)
98d2828 HR-03: BPJS Kesehatan and Ketenagakerjaan
5a7575f HR-02: employment contracts (PKWT / PKWTT)
9f2bc60 HR-01 additions: link an employee to a CRM account; undo a resignation
f41dbdb HR-00 + HR-01: 人力 / SDM menu, todo home and employee records
dd12a19 Docs: platform charter and HR task cards; development rules in CLAUDE.md
44b1c76 Orders: enter unit prices tax-inclusive or pre-tax; pre-tax quotes print as typed on the invoice
94c237c Invoice: new invoices calculated like Coretax (2 decimals, truncated; Total Invoice vs Total Amount)
b30193b Expired form token sends the user back with a message instead of a dead-end page
e366971 Invoice: fold the rounding difference into the VAT line, as finance's spreadsheet does
13a19a6 Invoice: hide the Pembulatan line on printed and Word invoices (configurable)
```

## 11. 用户偏好

中文交流；尽量少打断/少让用户授权，按合理默认自主推进。

## 12. 可能的后续

发票明细规格显示格式微调、库存"有预留"筛选、订单占用库存视图、预留超时自动释放、双语未覆盖的零散文案补全。（~~真实 logo.png 上传~~ 已完成，见 6 打印一节）

**安全/运维**：已完成——cookie 加固 / 强制改密 / 登录限速 / 审批职责分离 / **数据备份**(`tools/backup_db.php` 见 6e) / 修复财务逾期判定写死日期(`finance.php` 现用 `date('Y-m-d')`) / **响应式移动端布局 + PWA** / **仓库转 private + 服务器改走 SSH remote**(见 6f) / **服务器安全审计**(无入侵迹象，见 6f) / **审计日志**(见 6g) / **收款冲销**(见 6h) / **WhatsApp 通知**(见 6i) / **AI 智能查库存**(见 6j) / **控制器提示语全部 i18n 化**(55 条，原先印尼员工每天看到中文) / **备份异地同步到 OSS**(见 6e) / **列表分页 + 搜索**(见 6o) / **客户分析·数据准备**(见 6p) / **AI 花费护栏**(见 6p) / **回访清单计算 + 跟进记录**(见 6q) / **回访清单页 + 客户分析看板 + 送货地图**(见 6r) / **客户分析权限**(见 6s) / **看板张数列 + 张数卡片**(见 6t) / **订单小数 + 发票 .docx + 尾差并入 VAT**(见 6u) / **表单过期友好提示**(见 6v) / **发票按 Coretax 口径**(见 6w) / **订单含税/不含税单价**(见 6x) / **自动化测试**(`tools/run_tests.php`，870 项)。

**待办（按优先级）**：
-2. **财务 AI**（docs/任务卡-财务AI.md v2，顺序 00 → 01 → TAX-01 → 02 → 03）：**FA-AI-00 ✅**（见 6zd）、**FA-AI-01 ✅ 待用户确认**（见 6ze）。**FA-TAX-01 ✅ 已合并上线**（见 6zf；本公司 NPWP 已填；运费行写法待财务确认，Coretax 结果清单回写等样本）。**FA-AI-02 报销：用户 2026-10-22 决定先跳过**（等小票样本和审批人）。FA-AI-03 进项核对等样本（一个月供应商 Faktur + 同月 Coretax 进项导出）。**已知问题（与本卡无关，未改）**：`tools/backup_db.php` 只打包 `data/uploads/`，**人力附件 `data/hr/`（KTP / NPWP / 合同扫描件）从未进过备份**，`data/faktur/` 也不在内（可重新生成，影响小）——建议尽快把 `data/hr` 加进备份。FA-AI-01 上线后要在服务器做的：① `tools/import_bank_book.php` 预演 → `--apply` → `--benchmark=2026-09 --ai`，把准确率记进 docs/进度.md；② 财务用一次真实的 BCA / ICBC CSV，确认「与文件汇总」显示一致（脱敏样本的汇总被清零了）。FA-AI-01 未做 / 已知：撤销确认没有入口（错了只能在发票页冲销收款，冲销不会把流水退回未匹配）；LOST 坏账状态没有来源；BCA 付款人截取靠格式推断，异常格式会落到 AI 或人工。遗留给后续卡的：PDF 只发需要的页（FA-AI-03 时定方案）；01 的业务表用 `ai_suggestion_id` 关联 ai_suggestions（01 时确认）。已知低危：① 超预算待办的日期 ref 用 PHP `date()`，花费窗口用 SQLite `localtime`；若 PHP 时区与服务器系统时区不一致，在午夜前后待办日期可能差一天（只影响待办标题，不影响拦截）。② `ai.prices` 是 2026-10 的官方价，Anthropic 调价时要手工改；Haiku 5.5 的缓存读价 $0.01 是按输入价的 10% 推定的。③ 库存助手仍记在 ai_queries（没进 ai_calls），使用情况页单列；以后可以迁到任务层。
-2. **CO 号统一**（见 6zg）：线上 `tools/import_co_requests.php --file=… --apply`（用财务最新导出）→ `--link` 预演看报告 → 定 `--alias` 后 `--apply` → 剩余在订单页手工挂 → 通知销售改在系统领号、停表单。CO 打印件待模板。
-1. **历史销售**（见 6zc）：线上先预演 `tools/import_sales_history.php --file=…` 看未匹配客户/业务员报告，定 `--alias` 后 `--apply`；再考虑把 sales_history 接进回访清单的最近购买。
0. **客户分析板块**（任务书四部分，见 6p）：一、数据准备 ✅（6p）；二、三个清单 ✅（6q）；三、页面 ✅（6r）；四、权限 ✅ 待验收（6s）。宝塔计划任务 `tools/nightly.php` 每天 02:30 已加，`.env` 已配。四部分均已上线（2026-10-16）。**智能查库存已启用 Claude（2026-10-16；服务器 `config.local.php` 加了 `'ai' => ['driver' => 'claude']`），模型 `claude-opus-5-5`**——曾短暂用 `claude-opus-5`，比较后改回 5.5（更便宜且更新；用户不需要 Sonnet/Haiku 对比测试）。请求带 `fallbacks: "default"`；行业判断同为 `claude-opus-5-5`。剩余：70 个无负责人客户待在「数据整理」分配。**`.env` 里的 Anthropic 密钥曾在聊天中明文出现，用户决定继续使用——建议择机在 console.anthropic.com 轮换。** erpandi 已于 2026-10-16 合并到 erfandi（21 客户 / 31 订单，快照 `backups/pre_merge_erpandi_*`）。晨间 WhatsApp 提醒待 WhatsApp 启用后再做（6r）。
1. ~~**关闭 SSH 密码认证**~~ —— **已完成**（2026-10-12，见 6f）。~~**备份异地同步**~~ —— **已完成并启用**（2026-10-13，阿里云 OSS，见 6e）；**系统安全补丁**（登录提示 72 个安全更新，26 个 Important，挑空闲时段 `dnf upgrade-minimal --security`）
1b. **订单/客户按姓名而非账号关联**（erpandi/erfandi 现可在「数据整理」一键合并，见 6p；下单改账号下拉仍未做）—— 85/407 个订单的 `submitter` 对不上任何账号（如账号 `erfandi` 有 29 张发票录成 `erpandi`，他登录后看不到）；改用户姓名会让该销售的订单和客户从视图中消失。修法：下单改为账号下拉选择 + 名字变体合并工具
2. ~~**审计日志**~~ —— **已完成**（见 6g）
3. ~~**修发票合计舍入**~~ —— **已完成**（见 6n，Pembulatan）。**金额改整数分**仍未做（REAL 存储本身目前未造成误差，优先级降低）。原记录：现用 REAL 存。**线上已实际发生**：3 张发票(1027/1037/1043 - AMI - INV - 08 - 26)的合计比订单含税额少 1 卢比，客户按整数付款后被判定"超额收款"。根因是 `fulfill_order()` 里逐行 `round()` 后 `total = subtotal + ppn` 与原始含税额不完全相等(代码注释写的就是 `≈ original`)。修法：税额改为 `total - subtotal` 反推，让合计严格等于订单含税金额；历史 3 张可一并订正。涉及数据迁移，越晚成本越高
4. ~~**列表分页**~~ —— **已完成**（2026-10-13，见 6o，含搜索框和财务列表状态刷新改为单条 SQL）
5. ~~**WhatsApp 审批通知**~~ —— **已完成**（见 6i）。**待补**：发票逾期每日提醒、客户 N 天未跟进提醒（都靠 cron，队列已就绪）
6. 宝塔面板 8888 收口、停用闲置 MySQL/FTP（见 6f）
7. ~~备份异地同步~~（已完成，见 6e）、扩充测试覆盖（~~看板日期范围~~ 已完成，见 6m）（目前只覆盖审计日志与迁移路径）
8. GitHub 密钥收紧：服务器用的是账号级 SSH key（可访问所有仓库），可改为本仓库 read-only deploy key（见 6f）

**响应式 + PWA**：`app.css` 末尾 `@media (max-width:768px)` 把侧边栏变滑出抽屉(`layout.php` 顶栏加 `☰` `#navToggle` + `#navBackdrop` + 底部切换脚本;`.nav-toggle`/`.sidebar-backdrop` 默认隐藏),栅格(stats/grid-2/detail/form-row)、搜索栏、页头在窄屏自适应堆叠。PWA:`public/manifest.json`(display=standalone,theme #00a884)+ `public/assets/img/app-icon.svg`(青底白 A)+ `layout.php`/`login.php` head 里的 manifest/theme-color/apple-touch-icon,可「添加到主屏幕」。**未做 service worker**(避免缓存旧页;CRM 本就依赖服务器)。iOS 主屏图标想更精细可加 180×180 png 覆盖。
