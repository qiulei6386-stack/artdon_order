# 宝塔部署检查清单

## 网站

- [ ] 根目录指向 `/www/wwwroot/shop.artdonlighting.com`
- [ ] 默认文档包含 `index.php`
- [ ] PHP 版本为 8.2
- [ ] 已配置 `try_files $uri $uri/ /index.php?$query_string;`
- [ ] 已禁止访问 `config`、`includes`、`templates`、`storage`、`database`、`docs`、`preview`、`tools`
- [ ] `client_max_body_size` 不小于 20m
- [ ] 已开启 HTTPS 和强制跳转

## PHP

- [ ] `mbstring`
- [ ] `fileinfo`
- [ ] `session`
- [ ] `openssl`
- [ ] `upload_max_filesize >= 10M`
- [ ] `post_max_size >= 20M`
- [ ] `max_file_uploads >= 10`

## 权限

```bash
chown -R www:www /www/wwwroot/shop.artdonlighting.com/storage
find /www/wwwroot/shop.artdonlighting.com/storage -type d -exec chmod 750 {} \;
find /www/wwwroot/shop.artdonlighting.com/storage -type f -exec chmod 640 {} \;
```

## 检查

- [ ] `/api/health.php` 返回 `ok`
- [ ] `/products/track-lighting/track-spot` 可打开
- [ ] `/solutions/retail` 可打开
- [ ] `/product/AL1010` 可打开产品详情
- [ ] `/configure/AL1010` 可执行组合规则并加入购物车
- [ ] `/cart` 可提交测试订单
- [ ] `storage/submissions.jsonl` 有新记录
- [ ] 浏览器访问 `/storage/submissions.jsonl` 返回 403 / 404
- [ ] 手机菜单与产品配置页正常
