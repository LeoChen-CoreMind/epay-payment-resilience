# Epay 支付稳定版完整服务端

## 项目简介

本仓库发布可直接部署的 Epay 完整服务端源码，并在原有支付、商户、管理后台和插件体系上，重点修复支付宝免签订单监听长期运行时可能出现的漏单、假死和支付页面状态不及时刷新的问题。

仓库根目录就是服务端根目录，不是补丁包，也不需要 Overlay 或额外安装器。

## 功能范围

- 支付下单、查单、退款、代付、分账及商户 API。
- 管理后台、商户中心、支付页面和安装程序。
- 支付宝、微信、QQ、京东、银联等现有支付插件。
- 支付宝免签订单轮询、完整分页核验、持久化补偿与页面即时刷新。
- 完整第三方依赖、支付 SDK、SQL 安装与升级文件。

## 目录结构

| 路径 | 说明 |
| --- | --- |
| `admin/` | 管理后台 |
| `user/` | 商户中心 |
| `includes/` | 核心库和第三方依赖 |
| `install/` | 安装、升级 SQL 与支付宝索引工具 |
| `plugins/` | 支付插件；支付宝监听位于 `plugins/alipaycode/` |
| `paypage/`、`template/` | 支付页和页面模板 |
| `assets/` | 静态资源和 SDK 压缩包 |
| `tests/` | 现有支付稳定性测试 |

## 环境要求

- PHP 7.4 或更高版本，建议 PHP 8.2；源码同时在 PHP 8.5 CLI 下完成语法检查。
- MySQL 5.7/8.0 或兼容数据库。
- PHP 扩展按所用插件启用，常用扩展包括 `pdo_mysql`、`curl`、`openssl`、`mbstring`、`json` 和 `zip`。
- Nginx/Apache 和 Supervisor。

## 安装部署

1. 将仓库内容上传到站点根目录，并将运行用户设置为站点文件的合理所有者。
2. 创建数据库，导入 `install/install.sql`；已有站点按版本需要执行 `install/update2.sql`、`install/update3.sql`、`install/update4.sql`。
3. 在 `config.php` 填写数据库地址、端口、用户名、密码、数据库名和表前缀。
4. 配置站点伪静态规则，可参考根目录的 `nginx.txt` 或 `IIS.txt`。
5. 对高并发支付宝监听环境执行 `php install/alipaycode_performance.php`。该命令会检查并补齐订单表索引，已存在的索引不会重复创建。
6. 按下一节配置 Supervisor，并确认同一支付宝通道只运行一个监听进程。

升级现有站点前应备份数据库和当前程序目录，再在测试环境验证所用支付插件。

## Supervisor 配置

下面的配置保持支付宝监听单进程串行，进程异常退出后由 Supervisor 自动重建：

```ini
[program:pay]
command=php server.php 1
directory=/www/wwwroot/pay.leochen.cyou/plugins/alipaycode/

autostart=true
autorestart=true
startsecs=3
startretries=10

stopsignal=TERM
stopwaitsecs=10
stopasgroup=true
killasgroup=true

stdout_logfile=/www/server/panel/plugin/supervisor/log/pay.out.log
stderr_logfile=/www/server/panel/plugin/supervisor/log/pay.err.log
stdout_logfile_maxbytes=20MB
stderr_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile_backups=5

user=root
priority=999
numprocs=1
process_name=%(program_name)s_%(process_num)02d
```

`server.php 1` 中的 `1` 是支付宝通道 ID，应按实际通道修改。面板提示“不支持修改进程名”时，删除原守护任务后按完整配置重新添加。

## 支付稳定性修复

### 修复一：监听进程假死和高并发卡住

- 未支付订单只查询最近 **8 分钟**，控制每轮候选范围。
- 支付宝正常流水固定回看 **180 秒（3 分钟）**。
- 每个补偿窗口覆盖 **240 秒**，未匹配订单和窗口写入数据库；进程失败或重启后继续补偿。
- 单轮查询预算固定 **120 秒**，网络请求设置连接超时和总超时，避免连接无限占用进程。
- 支付宝流水按页读取并核对总数、页号、页大小和累计数量；只有完整分页成功后才处理本轮订单。
- 订单和流水使用线性映射匹配，避免订单量增大时出现二次方扫描。
- 数据库读取或持久化失败时进程以非零状态退出，由 Supervisor 重建连接和运行状态。

### 修复二：支付成功后页面不及时刷新

- `getshop.php` 返回禁止缓存响应头，避免浏览器或中间缓存复用旧的未支付状态。
- 支付页继续定时查单，并在窗口重新获得焦点、页面从历史记录恢复或标签页重新可见时立即查单。
- 服务端确认订单成功后，前端下一次即时查询即可进入支付成功状态，无需手动刷新页面或重启监听服务。

持久化补偿用于处理短时网络、数据库或进程重启造成的中断。生产环境仍需保留 Supervisor、数据库备份、日志轮转和进程告警，不能以单个守护配置替代运行监控。

## 测试验证

```bash
# 支付宝订单/流水匹配与恢复队列测试
php tests/alipaycode_reconciler_test.php

# 检查单个 PHP 文件语法
php -l plugins/alipaycode/server.php
```

完整发布前还会检查全部 PHP 文件语法、10,000 条订单线性匹配、源树逐文件 SHA-256，以及 Release 下载包完整性。

## 更新记录

当前稳定性版本为 `v1.0.0`。修复明细见 [CHANGELOG.md](CHANGELOG.md)。原项目历史更新保留在相应源码和上游发布记录中。

## 许可证与第三方依赖

本仓库包含 Epay 原始代码、多个第三方支付 SDK 和 Composer 依赖。各部分继续适用其原作者声明和随附许可证；本次支付稳定性修改的归属说明见 [NOTICE.md](NOTICE.md)。使用或再发布前，请同时检查 `includes/vendor/` 及各插件目录中的许可证和服务条款。

