# AluPanel CRM (NexusCRM)

面向**铝塑板（ACP）业务·印尼市场**的销售 CRM / 轻量 ERP。后端为 **纯 PHP + PDO**，数据库 **SQLite**，无框架、无 Composer 依赖。按既定规划（Nexus CRM）实现。

## 功能模块

| 模块 | 说明 |
| --- | --- |
| **数据看板** | 已收款、客户数、进行中商机、任务完成率；销售漏斗、最近订单、逾期信用预警 |
| **客户管理** | 标签（重点/潜在/成交/流失）、潜在价值、城市、跟进；关联商机与订单 |
| **销售漏斗** | 看板：初步接触→需求确认→方案报价→谈判中→已成交，可左右移动阶段 |
| **任务提醒** | 优先级、截止日、关联客户、完成统计、快速添加 |
| **财务管理** | 发票（PPN 11%、NPWP、账期 Net）、收款状态（已收/部分/待收/逾期）、登记收款、逾期自动判定 |
| **订单审批** | **四级审批流**：销售员 → 主管 → 经理 → 仓库；仓库确认后**自动扣库存 + 生成送货单(DO) + 生成发票** |
| **库存管理** | 产品（SKU/中英颜色/规格/尺寸/库存/安全库存）、低库存预警、手动出入库、出入库流水 |
| **用户管理** | 角色：管理员 / 经理 / 主管 / 销售 / 仓库（仅管理员可管理） |

货币：印尼盾 **IDR（Rp）**。全站 CSRF 保护、密码哈希、会话鉴权、基于角色的审批权限。

## 运行

需 PHP 8.0+（自带 `pdo_sqlite`）。在项目根目录：

```bash
php -S localhost:8000 -t public
```

浏览器打开 <http://localhost:8000>。首次访问自动建库并写入示例数据（含 **269 个产品**及示例客户/订单/发票）。

### 默认账号（密码均为 `admin123`）

| 邮箱 | 角色 |
| --- | --- |
| `admin@alupanel.local` | 管理员 |
| `mutiara@alupanel.local` | 经理 |
| `sari@alupanel.local` | 主管 |
| `ahmad@alupanel.local` | 销售 |
| `joko@alupanel.local` | 仓库 |

> 不同角色登录后，只能在订单流转到自己阶段时进行审批。

## 订单审批 → 履约流程

1. 销售员新建订单 → 进入 `待主管审批`
2. 主管通过 → `待经理审批`；经理通过 → `待仓库出货`
3. 仓库「确认出货」→ 系统自动：
   - 按 SKU+规格匹配产品并**扣减库存**（记一条 `out_auto` 流水）
   - 生成**送货单** `DO-YYYY-NNN`
   - 生成**发票**（小计 = 货品 + 运费，PPN 11%，按账期算到期日）
   - 订单变为 `已批准`
4. 财务在发票上**登记收款**，状态随已收金额/到期日自动更新

## 目录结构

```
public/index.php          前端控制器（路由 index.php?r=controller.action）
app/
  bootstrap.php           启动装配
  Database.php            连接 + 建表 + 种子（schema.sql + seed_products.sql + PHP 种子）
  domain.php              业务逻辑（库存增减、单号生成、发票状态、订单金额）
  Auth.php / Csrf.php / helpers.php
  controllers/            dashboard customers pipeline tasks finance orders inventory users auth
views/                    按模块分目录的模板 + layout.php
database/schema.sql       表结构
database/seed_products.sql 由 tools/gen_products.php 从规划原型生成的产品目录
tools/gen_products.php    产品目录抽取脚本（一次性）
data/                     运行时 SQLite（已 gitignore）
```

## 重置数据库

删除 `data/crm.sqlite`，下次访问自动重新初始化。
