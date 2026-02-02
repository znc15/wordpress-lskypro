<h1 align="center">LskyPro For WordPress</h1>

<p align="center">
  <img src="png/first.png" width="80%"/>
</p>

## 插件介绍

LskyPro For WordPress 是一个专为 WordPress 设计的图床插件，可以自动将 WordPress 上传的图片同步到 LskyPro 图床。通过使用此插件，您可以有效减轻服务器存储压力，提高图片加载速度，并且更好地管理您的媒体资源。 ✨

## 主要功能

- **自动同步** 上传到 WordPress 的图片会自动同步到 LskyPro 图床
- **远程图片处理** 可以自动将文章中的远程图片上传到图床
- **批量处理** 支持批量处理媒体库和文章中的图片
- **自定义存储策略** 支持选择 LskyPro 的不同存储策略
- **后台队列** 保存文章/批处理支持后台任务执行（优先 Action Scheduler，fallback WP-Cron），减少阻塞与超时风险
- **状态显示** 在媒体库中显示图片的图床状态
- **安全可靠** Nonce/权限校验、远程下载 SSRF 防护、后台输出转义；HTTPS 默认启用证书校验（可通过 filter 配置）

## 安装方法

1. 下载插件压缩包 
2. 在 WordPress 后台进入"插件"页面，点击"上传插件" 
3. 选择下载的压缩包并安装 
4. 激活插件 
5. 按照设置向导完成配置 

## 配置说明

### 基础配置 

1. 激活插件后，会自动跳转到设置向导
2. 输入 LskyPro 图床的 API 地址 
3. 输入 Token 
4. 点击"保存配置"完成基础设置 

## 使用说明

### 媒体上传 

插件激活后，所有上传到 WordPress 的图片都会自动同步到 LskyPro 图床。您可以在媒体库中查看图片的图床状态。

### 远程图片处理 

1. 在插件设置页面启用"自动处理文章中的远程图片"选项 
2. 保存文章时，插件会自动将文章中的远程图片上传到图床 

### 批量处理 

1. 在插件设置页面的"批量处理"选项卡中 
2. 选择"处理媒体库图片"或"处理文章图片" 
3. 点击"开始处理"按钮 
4. 等待处理完成 

## 常见问题

### 图片上传失败怎么办？

- 检查 LskyPro 图床 API 地址是否正确 
- 确认 Token 是否有效 
- 查看 WordPress 错误日志获取详细信息 

### 后台队列不工作/处理一直没反应怎么办？

- 确认站点未禁用 WP-Cron（`DISABLE_WP_CRON`）
- 若站点禁用了 WP-Cron：建议安装/启用 Action Scheduler（很多电商/缓存插件会自带），或配置系统计划任务定时访问 `wp-cron.php`
- 打开 `WP_DEBUG` + `WP_DEBUG_LOG` 后查看日志（默认写入 `wp-content/debug.log`；插件可选额外写入 `wp-content/uploads/lskypro-logs/`）

### 如何更改存储策略？

在插件设置页面的"设置"选项卡中，可以选择不同的存储策略。

## 安全与行为说明（必读）

- **HTTPS 证书校验**：默认对 HTTPS 请求启用 `sslverify=true`。若你的 LskyPro 使用自签证书，可通过 `lsky_pro_sslverify` / `lsky_pro_http_sslverify` filter 覆盖。
- **远程图片下载**：使用安全下载（`wp_safe_remote_get` + stream 落盘），并限制端口/大小/Content-Type，降低 SSRF 与资源消耗风险。
- **删除联动**：删除文章时联动删除图床图片/媒体库附件属于高风险操作，新安装默认关闭；请在确认“图片不复用”后再开启。
- **缩略图/中间尺寸**：插件不再在激活/停用时改写全站“媒体设置”。可在设置中选择是否禁用默认缩略图/中间尺寸生成。

## 版权信息

- 作者：LittleSheep 
- 作者网站：[https://www.littlesheep.cc](https://www.littlesheep.cc) 
- 插件版本：1.1.0 