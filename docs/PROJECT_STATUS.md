# AluPanel CRM — 项目上下文摘要 / Handoff

> 本文档用于保存项目全貌，便于跨会话/上下文续接。最新进度以 `git log` 为准（已含：双语、打印模板、含税发票、库存预留防超卖、角色与多级权限、记录级权限、客户归属分配）。

## 1. 项目概述

- **名称**：AluPanel CRM（侧边栏 logo 显示 `AluPanelCRM`）
- **业务**：铝塑板（ACP）销售 CRM / 轻量 ERP，面向**印尼市场**
- **真实公司**：PT ALUPANEL MULIA INDONESIA
- **来源**：按规划原型 `C:\Users\yuans\Downloads\crm-system_25.html`（NexusCRM）实现
- **仓库**：https://github.com/tomtrees17/alupanelcrm （main 分支，公开）
- **本地路径**：`D:\AluPanelCRM`

## 2. 技术栈

- 后端：**纯 PHP + PDO**（无框架、无 Composer）
- 数据库：**SQLite**（`data/crm.sqlite`，已 gitignore，首次访问自动建库+示例数据）。连接启用 **WAL + busy_timeout=5s + synchronous=NORMAL**，支持多人并发读写不锁库（`Database::connect`）。会生成 `crm.sqlite-wal/-shm` 临时文件（已 gitignore）
- 前端：服务端渲染 PHP 模板 + 原生 JS/CSS，无构建步骤
- 路由：前端控制器 `public/index.php?r=controller.action`
- 货币：印尼盾 IDR（Rp）
- 语言：中文 / 印尼语（id）可切换

## 3. 运行方式

**本地**（Windows，PHP 未加入 PATH）：
```
& "C:\Users\yuans\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" -S localhost:8000 -t public
```
打开 http://localhost:8000 。重置数据：删除 `data/crm.sqlite`（被沙箱保护时用 PHP `unlink` 删）。

**服务器**（宝塔，已部署）：
- 域名/站点：`www.alupanel.cc`，路径 `/www/wwwroot/www.alupanel.cc`
- PHP 8.2（已确认 `pdo_sqlite`/`sqlite3` 启用）
- **网站运行目录必须设为 `/public`**
- `data/` 目录需 `www` 用户可写：`chown -R www:www data && chmod -R 755 data`
- 更新代码：`cd /www/wwwroot/www.alupanel.cc && git pull`

## 4. 默认账号（初始密码均 `admin123`，**首次登录强制改密**，见 6c）

| 邮箱 | 角色 |
|---|---|
| admin@alupanel.local | 管理员 admin |
| mutiara@alupanel.local | 经理 manager |
| sari@alupanel.local | 主管 supervisor |
| ahmad@alupanel.local | 销售 sales |
| joko@alupanel.local | 仓库 warehouse |

## 5. 模块（7 + 用户）

数据看板、客户管理、销售漏斗(deals)、任务提醒、财务管理(invoices)、订单审批(orders)、库存管理(products) + 用户管理。

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

**库存预留防超卖**：
- `products.reserved` 列，**可用 = stock − reserved**
- 下单即预留(`recompute_reservations`，按所有 pending 订单求和)；驳回/删除释放；出货实扣并释放
- 新建订单按**可用库存**校验（客户端标红阻止提交 + 服务端拒绝）；每级审批再按物理库存校验
- `order_items.product_id` 已记录；线上旧库由 `Database::ensureSchema()` 自动加列+回填+重算

**打印**：发票(`print/invoice.php`，公司抬头+Bill To+DPP/VAT+双银行ICBC/BCA+签字+terbilang金额大写) 与送货单(`print/do.php`，SURAT JALAN)，A4 样式 `public/assets/css/print.css`。logo：`public/assets/img/logo.{png,svg}`（已提交 SVG 还原版，放 png 可覆盖）。

## 6b. 角色与访问控制（重点）

**角色**（`all_roles()`，共 9 个）：admin、manager(经理)、finance_manager(财务经理)、ops_supervisor(运营主管)、supervisor(主管)、sales(销售员)、warehouse(仓库/库存管理员)、hr(人力资源)、clerk(文员)。i18n 含中印双语标签。

**① 模块级权限**（`role_permissions` 表 + `can_access($module)`）
- bootstrap 载入 `$GLOBALS['permissions']`；前端控制器(`public/index.php`)拦截越权模块、导航按权限隐藏。
- 可配置模块：customers/pipeline/tasks/finance/orders/inventory；另有视图权限 `performance`(看板全员业绩) 和 `export`(导出 Excel)。
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

## 6d. 忘记密码 / 重置（运维）

服务器上用 CLI 工具重置任意账号密码（`tools/reset_password.php`，绕过登录直接改库）：
```bash
cd /www/wwwroot/www.alupanel.cc && git pull
/www/server/php/82/bin/php tools/reset_password.php                              # 不带参数 = 列出所有账号
/www/server/php/82/bin/php tools/reset_password.php admin@alupanel.local '新密码'   # ≥8 位
chown -R www:www data && chmod -R 755 data                                       # 修正属主（必做）
```
- 重置 `password_hash` 并清 `must_change_password`，新密码当场生效。
- 对 `must_change_password` 列做**存在性判断**，故线上库尚未经 web 迁移（刚 git pull、还没人访问网站）时也能用（否则会报 `no such column`）。
- **应急免 git pull 版**（只改密码、不依赖新列）：
  `/www/server/php/82/bin/php -r '$p=new PDO("sqlite:data/crm.sqlite");$p->prepare("UPDATE users SET password_hash=? WHERE email=?")->execute([password_hash("新密码",PASSWORD_DEFAULT),"admin@alupanel.local"]);echo "ok\n";'`
- 坑：宝塔 PHP CLI 路径随版本变（`ls /www/server/php/` 查实际版本号）；密码用单引号包住更安全；两条命令分行别粘成一行。

## 7. 目录结构

```
public/index.php            前端控制器（路由）
public/assets/css/{app,print}.css
public/assets/img/logo.svg
app/
  bootstrap.php             启动装配（session, config, i18n, helpers, domain, Database, Auth, Csrf）
  Database.php              连接+建表+种子+ensureSchema(线上升级)
  domain.php                业务逻辑：库存增减、预留重算、可用/库存校验、单号生成、发票状态、terbilang
  helpers.php               视图辅助：e/url/redirect/idr/num/各label与tr_*翻译
  i18n.php                  中印双语字典 + t() + current_lang()
  Export.php                无依赖 Excel 导出（.xlsx via ZipArchive，CSV 兜底）
  Auth.php / Csrf.php
  controllers/              dashboard customers pipeline tasks finance orders inventory delivery users roles account auth lang
views/                      按模块分目录 + layout.php + print/ + errors/（account/password.php 改密页）
database/schema.sql         表结构
database/seed_products.sql  269 个产品（由 tools/gen_products.php 从原型抽取）
tools/reset_password.php    CLI 重置账号密码（运维，见 6d）
data/                       运行时 SQLite（gitignore）
config.php                  应用与公司配置
```

## 8. config.php 可定制项

`company_full / company_addr / company_npwp / banks[ICBC,BCA] / signer_name / signer_title`（发票抬头）、`ppn_rate=11`、`currency=Rp`、`brand=AluPanel`。

## 9. 数据模型（表）

users(+must_change_password), customers, deals, tasks, products(+reserved), stock_txn, orders, order_items(+product_id), delivery_orders, invoices, invoice_items, payments, role_permissions, app_meta, login_attempts。

## 10. 提交历史（main）

```
169b0da Make reset tool work before web migration adds must_change_password
b6cbc57 Add CLI password-reset tool for locked-out accounts
afa4e76 Harden auth: session cookies, login throttle, forced password change
957817d Let orders enter a new customer and auto-save to customer module
08eee58 Allow free-text salesperson name with suggestions in order form
ae9f33b Add assign-salesperson (submitter) option in order form
a0c4fe0 Localize remaining UI strings (finance detail, topbar titles, 404, banners)
af8ea56 Fully localize finance invoice detail page (zh/id)
b0f012a Order draft + edit-before-approval + reject-back-to-draft workflow
c83a4f9 Enable SQLite WAL + busy_timeout for safe concurrent access
fa7a4ea Document Excel export in PROJECT_STATUS
a729e69 Make Excel export a configurable 'export' permission
a7ad0ab Add Excel export for inventory, finance report and customers (managers only)
683d883 Document roles & access-control rules in PROJECT_STATUS
f4eb663 Let admins/managers assign customer owner (sales PIC)
bd2863a Inventory edit limited to admin/warehouse; sales see only own orders & customers
43dba6e Add HR, operations-supervisor and clerk roles
88f1244 Make all-staff sales performance its own configurable permission
73bf91c Show personal performance to sales (own orders only)
763b01d Add sales performance & hot products to dashboard
ebb8c2d Add finance_manager role and per-role module permissions
f64c117 Reserve stock on order placement to prevent overselling
4681501 Block order flow when product stock is insufficient
4212162 Add keyword search to product picker in new-order form
a76cd2b Add ALUSIGNPANEL logo (SVG) to printed invoice and delivery order
4ba25e8 Treat order prices as tax-inclusive when generating invoice
99d1a41 Match printed invoice to company template (PT Alupanel Mulia Indonesia)
c94400e Add bilingual zh/id, print templates, delivery orders; rename to AluPanelCRM
feccc03 Rebuild as ACP sales CRM per NexusCRM plan (7 modules)
56afafa Stop tracking local .claude settings
c3ad488 Initial commit: AluPanel CRM (PHP + SQLite)
```

## 11. 用户偏好

中文交流；尽量少打断/少让用户授权，按合理默认自主推进。

## 12. 可能的后续

真实 logo.png 上传、发票明细规格显示格式微调、库存"有预留"筛选、订单占用库存视图、预留超时自动释放、双语未覆盖的零散文案补全。

**安全/运维后续**（Op2 的 cookie 加固 / 强制改密 / 登录限速已完成）：审计日志（谁改了什么）、数据备份（宝塔定时 copy `crm.sqlite`）、金额用 REAL 存在舍入风险（可改整数分）、列表分页、看板日期范围、移动端布局、自动化测试。
