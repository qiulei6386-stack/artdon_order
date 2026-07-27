# 宝塔 / 腾讯云发布检查

## 网站

- [ ] 根目录：`/www/wwwroot/artdon_order`
- [ ] 域名：`shop.artdonlighting.com`
- [ ] 默认文档包含 `index.php`
- [ ] PHP 8.2；已启用 PDO SQLite、JSON、Session、OpenSSL、GD、Zip、cURL；
  Fileinfo 可选（缺失时使用严格文件签名与规范 MIME 回退）
- [ ] HTTPS 强制跳转
- [ ] `client_max_body_size 20m`
- [ ] 上传存储默认保留至少 1 GB 可用空间，并限制为 2 GB；如通过环境变量调整
  `ARTDON_UPLOAD_QUOTA_BYTES` / `ARTDON_UPLOAD_FREE_RESERVE_BYTES`，必须同步配置磁盘告警
- [ ] HTTPS PHP 参数正确，HSTS 响应头已启用
- [ ] Pretty URL 回退：`try_files $uri $uri/ /index.php?$query_string;`
- [ ] 禁止公开访问隐藏文件及 `config`、`includes`、`templates`、`storage`、
  `database`、`docs`、`preview`、`tools`、`tests`

## 数据与权限

先备份现有数据库和上传文件，再执行：

```bash
cd /www/wwwroot/artdon_order
php tools/migrate.php
chown -R www:www storage
find storage -type d -exec chmod 750 {} \;
find storage -type f -exec chmod 640 {} \;
```

迁移必须可重复运行，第二次应显示所有 migration 已存在。

## 自动检查

- [ ] 全部 PHP 文件通过语法检查
- [ ] `assets/js/app.js` 与 `assets/js/lighting-simulation.js` 通过语法检查
- [ ] Configurator、Cart、Procurement、IES、Simulation、AI 测试全部通过
- [ ] `/api/health.php` 返回 HTTP 200
- [ ] `/api/lighting-products.php` 返回 simulation-ready profiles
- [ ] `/storage/artdon.sqlite`、`/tools/migrate.php`、`/tests/cart/run.php`、`/.git/config` 返回 404

## 浏览器验收

- [ ] `/configure/AL1010` 的服务端组合规则、MOQ、型号结果正确
- [ ] Project Cart 刷新后仍保留，能修改、复制、删除、导出
- [ ] Order Request 只提交服务端购物车内容
- [ ] `/lighting-simulation` 可完成单灯和 Auto Layout
- [ ] 热力图、数量、间距、平均/最大/最小照度、U0 正常显示
- [ ] 模拟项目可保存，PDF 可下载，并能加入 Project Cart
- [ ] 上传附件保存为 quarantined；安全扫描/人工放行流程已由运营确认
- [ ] 手机端导航、配置、购物车和模拟页面可用

## 外部连接

ERP/CRM、SMTP 和客户登录未配置时，不应伪造成功：

- 请求仍在本地事务中安全保存
- ERP job 保持 pending，不丢失、不重复建单
- Account 页面不显示任何示例客户数据
